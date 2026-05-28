<?php
/**
 * MaintenanceService — database backup / refresh / content-reset engine
 * for the admin "System" panel.
 *
 * Design constraints (cPanel / shared hosting):
 *   - NO shell-out. `mysqldump` is frequently absent and exec()/shell_exec()
 *     are commonly disabled, so backup/restore are pure-PHP over PDO.
 *   - Bounded memory. Table dumps page through rows with an interpolated
 *     (int) LIMIT/OFFSET — never a bound `?` in LIMIT, which throws under the
 *     app's native-prepares PDO (see audit C1) — so a huge cache table can't
 *     blow the memory limit.
 *   - Best-effort time budget. Long actions call set_time_limit(0); callers
 *     should still expect shared-host ceilings on very large databases.
 *
 * Every destructive method is the *service* half only. Auth (strict admin),
 * CSRF, and type-to-confirm live in api/admin/maintenance.php.
 */
class MaintenanceService
{
    /** @var Database */
    private $db;
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $dbName;

    /**
     * Cache tables hold only regenerable data (re-fetched from Archive.org).
     * They dominate backup size and are the bulk of a content reset.
     */
    private const CACHE_TABLES = [
        'search_cache',
        'video_metadata_cache',
        'collection_metadata_cache',
        'thumbnail_cache',
        'cache_queue',
        'cached_items_registry',
        'cache_statistics',
        'api_usage_log',
    ];

    /**
     * User-generated / community data removed by a "content reset". Schema,
     * user accounts, settings, branding, staff picks and featured sections
     * are all left intact.
     */
    private const CONTENT_TABLES = [
        'video_comments',
        'comment_likes',
        'comment_reports',
        'user_bookmarks',
        'user_watch_history',
        'user_collections',
        'user_collection_items',
        'search_history',
        'popular_searches',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getPdo();
        $cfg = $this->db->getConfig();
        $this->dbName = $cfg['database'] ?? '';
    }

    // =====================================================
    // STATUS
    // =====================================================

    /**
     * A snapshot for the panel: per-table row counts + sizes, totals,
     * migration files on disk, and the host's relevant PHP limits +
     * capabilities (so the UI can hide features the host can't do).
     */
    public function databaseStatus(): array
    {
        $tables = [];
        $totalRows = 0;
        $totalBytes = 0;

        // Sizes come from information_schema (cheap, one query). Row counts
        // are taken exactly via COUNT(*) — information_schema.table_rows is
        // only an estimate for InnoDB and admins expect a real number.
        $sizes = [];
        $rows = $this->db->fetchAll(
            "SELECT table_name AS name, (data_length + index_length) AS size_bytes
               FROM information_schema.tables
              WHERE table_schema = ?",
            [$this->dbName]
        );
        foreach ($rows as $r) {
            $sizes[$r['name']] = (int)$r['size_bytes'];
        }

        foreach ($this->listTables() as $table) {
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $bytes = $sizes[$table] ?? 0;
            $totalRows += $count;
            $totalBytes += $bytes;
            $tables[] = [
                'name' => $table,
                'rows' => $count,
                'size_bytes' => $bytes,
                'is_cache' => in_array($table, self::CACHE_TABLES, true),
            ];
        }

        $migrationFiles = [];
        foreach ((array)glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') as $f) {
            $migrationFiles[] = basename($f);
        }
        sort($migrationFiles);

        return [
            'database' => $this->dbName,
            'table_count' => count($tables),
            'total_rows' => $totalRows,
            'total_size_bytes' => $totalBytes,
            'tables' => $tables,
            'migrations' => $migrationFiles,
            'limits' => [
                'max_execution_time' => (int)ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ],
            'capabilities' => [
                'zip' => class_exists('ZipArchive'),
                'gzip' => function_exists('gzencode'),
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    // =====================================================
    // BACKUP (streamed SQL)
    // =====================================================

    /**
     * Stream a self-contained SQL dump to php://output. The caller is
     * responsible for sending the Content-Type / Content-Disposition
     * headers BEFORE calling this.
     *
     * @param bool $includeCaches keep the regenerable *_cache tables in the dump
     */
    public function streamBackup(bool $includeCaches = true): void
    {
        @set_time_limit(0);

        $tables = $this->listTables();
        if (!$includeCaches) {
            $tables = array_values(array_filter(
                $tables,
                function ($t) { return !in_array($t, self::CACHE_TABLES, true); }
            ));
        }

        // Header marker — restore validates the first line before executing,
        // so a stray .sql can't be fed in by accident.
        $this->out("-- Archive Film Club SQL backup\n");
        $this->out('-- generated: ' . gmdate('c') . "\n");
        $this->out('-- database: ' . $this->dbName . "\n");
        $this->out('-- tables: ' . count($tables) . ($includeCaches ? '' : ' (caches excluded)') . "\n");
        $this->out("-- NOTE: restoring this file overwrites the listed tables.\n\n");
        $this->out("SET NAMES utf8mb4;\n");
        $this->out("SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $this->dumpTable($table);
        }

        $this->out("\nSET FOREIGN_KEY_CHECKS=1;\n");
        $this->flush();
    }

    /**
     * Dump one table: DROP + CREATE, then paged INSERTs.
     */
    private function dumpTable(string $table): void
    {
        $createRow = $this->pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? null);
        if ($createSql === null) {
            return; // not a base table we can recreate
        }

        $this->out("-- ----------------------------\n");
        $this->out("-- Table: {$table}\n");
        $this->out("-- ----------------------------\n");
        $this->out("DROP TABLE IF EXISTS `{$table}`;\n");
        $this->out($createSql . ";\n");

        $batch = 200;
        $offset = 0;
        $columns = null;

        do {
            // Interpolated (int) LIMIT/OFFSET — bound `?` here throws under
            // native prepares (audit C1). The casts make this injection-safe.
            $rows = $this->pdo
                ->query("SELECT * FROM `{$table}` LIMIT {$batch} OFFSET {$offset}")
                ->fetchAll(PDO::FETCH_ASSOC);

            if ($rows) {
                if ($columns === null) {
                    $columns = array_map(
                        function ($c) { return '`' . str_replace('`', '``', $c) . '`'; },
                        array_keys($rows[0])
                    );
                }
                $valueGroups = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $v === null ? 'NULL' : $this->pdo->quote((string)$v);
                    }
                    $valueGroups[] = '(' . implode(',', $vals) . ')';
                }
                $this->out(
                    'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ") VALUES\n"
                    . implode(",\n", $valueGroups) . ";\n"
                );
                $this->flush();
            }

            $offset += $batch;
        } while (count($rows) === $batch);

        $this->out("\n");
    }

    /**
     * Build a zip of the cached-thumbnail directory into a temp file and
     * return its path (caller streams it then unlinks), or null if zip is
     * unavailable or there is nothing to archive. Building to a temp file
     * (rather than streaming) lets the API return a clean JSON error before
     * any file headers are sent.
     */
    public function buildThumbnailsZip(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $cfg = $this->db->getConfig();
        $dir = $cfg['paths']['thumbnails'] ?? (dirname(__DIR__, 2) . '/thumbnails');
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return null;
        }

        @set_time_limit(0);
        $tmp = tempnam(sys_get_temp_dir(), 'afc_thumbs_');
        if ($tmp === false) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return null;
        }

        $added = 0;
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            // Only ship actual cached images — skip index.html / .htaccess
            // and anything that isn't a plain readable file.
            if (!is_file($path)) continue;
            if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $name)) continue;
            $zip->addFile($path, $name);
            $added++;
        }
        $zip->close();

        if ($added === 0) {
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    // =====================================================
    // REFRESH
    // =====================================================

    /**
     * Re-run every migration in db/migrations idempotently — the same logic
     * install.php uses, so a code upgrade that ships a new NNN_*.sql can be
     * applied from the panel. "Already exists" style errors are swallowed.
     */
    public function runMigrations(): array
    {
        @set_time_limit(0);
        $dir = dirname(__DIR__, 2) . '/db/migrations';
        $files = glob($dir . '/*.sql');
        if (!$files) {
            throw new RuntimeException('No migration files found');
        }
        sort($files);

        $applied = 0;
        $skipped = 0;
        $perFile = [];

        foreach ($files as $file) {
            $sql = (string)file_get_contents($file);
            // Strip line comments before splitting on ';' (a comment can
            // contain a semicolon). Mirrors install.php's runner exactly.
            $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function ($s) { return $s !== ''; }
            );

            $fileApplied = 0;
            $fileSkipped = 0;
            foreach ($statements as $statement) {
                try {
                    $this->pdo->exec($statement);
                    $applied++;
                    $fileApplied++;
                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, 'already exists') === false
                        && stripos($msg, 'Duplicate column') === false
                        && stripos($msg, 'Duplicate key name') === false
                        && stripos($msg, 'Multiple primary key') === false) {
                        throw new RuntimeException(
                            'Migration ' . basename($file) . ' failed: ' . $msg
                        );
                    }
                    $skipped++;
                    $fileSkipped++;
                }
            }
            $perFile[] = [
                'file' => basename($file),
                'applied' => $fileApplied,
                'skipped' => $fileSkipped,
            ];
        }

        return [
            'files' => count($files),
            'statements_applied' => $applied,
            'statements_skipped' => $skipped,
            'detail' => $perFile,
        ];
    }

    /**
     * Flush regenerable caches and re-warm the stale ones. Search cache is
     * truncated outright (always rebuildable on the next query); expired
     * metadata/thumbnail rows are pruned; stuck queue items are reaped; then
     * a bounded slice of stale metadata is re-fetched.
     */
    public function refreshCaches(int $rewarmLimit = 10): array
    {
        @set_time_limit(0);
        require_once dirname(__DIR__, 2) . '/cache/CacheManager.php';
        $cacheManager = new CacheManager();

        $before = $cacheManager->getStats();

        // Flush the search cache (FK-free, fully regenerable).
        $searchCleared = 0;
        if ($this->tableExists('search_cache')) {
            $searchCleared = (int)$this->pdo->exec('TRUNCATE TABLE `search_cache`');
        }

        $expired = $cacheManager->cleanExpiredCache();
        $reaped = $cacheManager->reapStuckQueueItems();

        $rewarmed = [];
        if ($rewarmLimit > 0) {
            $localStorage = new LocalStorageService();
            $rewarmed = $localStorage->refreshStaleData($rewarmLimit);
        }

        $after = $cacheManager->getStats();

        return [
            'search_cache_cleared' => true,
            'expired_removed' => $expired,
            'stuck_queue_reaped' => $reaped,
            'rewarmed' => $rewarmed,
            'stats_before' => $before,
            'stats_after' => $after,
        ];
    }

    /**
     * Re-pull stale item metadata (and thumbnails) from Archive.org, bounded
     * by $limit so the request stays within the host's time budget.
     */
    public function refetchMetadata(int $limit = 25): array
    {
        @set_time_limit(0);
        $localStorage = new LocalStorageService();
        $results = $localStorage->refreshStaleData(max(1, $limit));
        return ['limit' => $limit, 'results' => $results];
    }

    // =====================================================
    // CONTENT RESET (wipe — safest mode)
    // =====================================================

    /**
     * Truncate user-generated + cache tables and delete cached thumbnail
     * files. Schema, user accounts, site settings, branding, staff picks and
     * featured sections are preserved. Returns per-table row counts removed.
     */
    public function contentReset(): array
    {
        @set_time_limit(0);

        $targets = array_merge(self::CONTENT_TABLES, self::CACHE_TABLES);
        $cleared = [];
        $totalRows = 0;

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($targets as $table) {
                if (!$this->tableExists($table)) {
                    continue;
                }
                $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $this->pdo->exec("TRUNCATE TABLE `{$table}`");
                $cleared[$table] = $count;
                $totalRows += $count;
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $thumbsDeleted = $this->clearThumbnailFiles();

        return [
            'tables_cleared' => count($cleared),
            'rows_removed' => $totalRows,
            'detail' => $cleared,
            'thumbnail_files_deleted' => $thumbsDeleted,
        ];
    }

    // =====================================================
    // INTERNALS
    // =====================================================

    /** Base tables in the app's schema, alphabetical. */
    private function listTables(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT table_name AS name
               FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'
              ORDER BY table_name",
            [$this->dbName]
        );
        $out = [];
        foreach ($rows as $r) {
            // Defence-in-depth: every table name is interpolated into
            // backticked SQL below, so reject anything but the identifier
            // charset even though the source is our own information_schema.
            if (preg_match('/^[A-Za-z0-9_]+$/', $r['name'])) {
                $out[] = $r['name'];
            }
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    /**
     * Delete cached image files, keeping index.html / .htaccess and any
     * non-image guard files. Returns the count deleted.
     */
    private function clearThumbnailFiles(): int
    {
        $cfg = $this->db->getConfig();
        $dir = $cfg['paths']['thumbnails'] ?? (dirname(__DIR__, 2) . '/thumbnails');
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return 0;
        }
        $deleted = 0;
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            if (!is_file($path)) continue;
            if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $name)) continue;
            if (@unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Echo a chunk to the response. */
    private function out(string $chunk): void
    {
        echo $chunk;
    }

    /** Best-effort flush so large dumps stream instead of buffering. */
    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
