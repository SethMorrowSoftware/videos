<?php
/**
 * Cache System Upgrade Script
 *
 * Simply visit this page in your browser to upgrade the database
 * for permanent local caching support.
 *
 * DELETE THIS FILE AFTER RUNNING!
 */

// Basic security - only allow from browser with visual confirmation
if (php_sapi_name() === 'cli') {
    die("Please run this script from your web browser.\n");
}

require_once __DIR__ . '/db/Database.php';

$messages = [];
$errors = [];

function runQuery($db, $sql, $description) {
    global $messages, $errors;
    try {
        $db->query($sql);
        $messages[] = "✓ $description";
        return true;
    } catch (Exception $e) {
        // Check if it's just "column already exists" or similar
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false ||
            strpos($msg, 'already exists') !== false ||
            strpos($msg, 'Duplicate key') !== false) {
            $messages[] = "○ $description (already done)";
            return true;
        }
        $errors[] = "✗ $description: " . $msg;
        return false;
    }
}

// Check if upgrade requested
$doUpgrade = isset($_POST['upgrade']) && $_POST['upgrade'] === 'yes';

if ($doUpgrade) {
    $db = Database::getInstance();

    // =====================================================
    // UPGRADE video_metadata_cache TABLE
    // =====================================================

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN is_permanent TINYINT(1) DEFAULT 1",
        "Add is_permanent column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN is_stale TINYINT(1) DEFAULT 0",
        "Add is_stale column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN raw_metadata_json LONGTEXT",
        "Add raw_metadata_json column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN last_refreshed TIMESTAMP NULL",
        "Add last_refreshed column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN refresh_count INT DEFAULT 0",
        "Add refresh_count column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN collection_json TEXT",
        "Add collection_json column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache ADD COLUMN thumbnail_cached TINYINT(1) DEFAULT 0",
        "Add thumbnail_cached column to video_metadata_cache");

    runQuery($db, "ALTER TABLE video_metadata_cache MODIFY COLUMN expires_at TIMESTAMP NULL",
        "Make expires_at nullable for permanent storage");

    runQuery($db, "CREATE INDEX idx_stale ON video_metadata_cache (is_stale, last_refreshed)",
        "Add index for stale items");

    // =====================================================
    // CREATE cache_queue TABLE
    // =====================================================

    runQuery($db, "CREATE TABLE IF NOT EXISTS cache_queue (
        id INT PRIMARY KEY AUTO_INCREMENT,
        archive_id VARCHAR(255) NOT NULL,
        cache_type ENUM('metadata', 'thumbnail', 'collection') NOT NULL,
        priority INT DEFAULT 5,
        status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
        attempts INT DEFAULT 0,
        max_attempts INT DEFAULT 3,
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        UNIQUE KEY uk_item_type (archive_id, cache_type),
        INDEX idx_pending (status, priority, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create cache_queue table");

    // =====================================================
    // CREATE cached_items_registry TABLE
    // =====================================================

    runQuery($db, "CREATE TABLE IF NOT EXISTS cached_items_registry (
        id INT PRIMARY KEY AUTO_INCREMENT,
        archive_id VARCHAR(255) NOT NULL,
        has_metadata TINYINT(1) DEFAULT 0,
        has_thumbnail TINYINT(1) DEFAULT 0,
        has_files_list TINYINT(1) DEFAULT 0,
        total_size_bytes BIGINT DEFAULT 0,
        first_cached TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        access_count INT DEFAULT 0,
        source VARCHAR(50) DEFAULT 'user_browse',
        UNIQUE KEY uk_archive_id (archive_id),
        INDEX idx_last_accessed (last_accessed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create cached_items_registry table");

    // =====================================================
    // CREATE cache_statistics TABLE
    // =====================================================

    runQuery($db, "CREATE TABLE IF NOT EXISTS cache_statistics (
        id INT PRIMARY KEY AUTO_INCREMENT,
        stat_date DATE NOT NULL,
        metadata_cached INT DEFAULT 0,
        thumbnails_cached INT DEFAULT 0,
        collections_cached INT DEFAULT 0,
        total_storage_bytes BIGINT DEFAULT 0,
        api_calls_saved INT DEFAULT 0,
        cache_hit_count INT DEFAULT 0,
        cache_miss_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_stat_date (stat_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create cache_statistics table");

    // =====================================================
    // CREATE collection_metadata_cache TABLE
    // =====================================================

    runQuery($db, "CREATE TABLE IF NOT EXISTS collection_metadata_cache (
        id INT PRIMARY KEY AUTO_INCREMENT,
        collection_id VARCHAR(255) NOT NULL,
        title VARCHAR(500),
        description TEXT,
        creator VARCHAR(255),
        item_count INT DEFAULT 0,
        thumbnail_url VARCHAR(500),
        thumbnail_cached TINYINT(1) DEFAULT 0,
        raw_metadata_json LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_refreshed TIMESTAMP NULL,
        is_stale TINYINT(1) DEFAULT 0,
        UNIQUE KEY uk_collection_id (collection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create collection_metadata_cache table");

    // =====================================================
    // ADD SETTINGS
    // =====================================================

    runQuery($db, "INSERT INTO site_settings (setting_key, setting_value, setting_type) VALUES
        ('enableLocalCaching', '1', 'boolean'),
        ('cacheMetadataPermanently', '1', 'boolean'),
        ('cacheThumbnailsOnView', '1', 'boolean'),
        ('backgroundCacheEnabled', '1', 'boolean'),
        ('maxThumbnailCacheMB', '500', 'number'),
        ('refreshStaleAfterDays', '30', 'number')
        ON DUPLICATE KEY UPDATE updated_at = NOW()",
        "Add caching settings");

    // =====================================================
    // MARK EXISTING METADATA AS PERMANENT
    // =====================================================

    runQuery($db, "UPDATE video_metadata_cache SET is_permanent = 1, expires_at = NULL WHERE is_permanent IS NULL OR is_permanent = 0",
        "Mark existing cached metadata as permanent");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cache System Upgrade</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            min-height: 100vh;
            margin: 0;
            padding: 2rem;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: rgba(255,255,255,0.05);
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        h1 { margin-top: 0; color: #4fc3f7; }
        h2 { color: #81c784; margin-top: 2rem; }
        .success { color: #81c784; }
        .error { color: #ef5350; }
        .skip { color: #9e9e9e; }
        ul { list-style: none; padding: 0; }
        li { padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .btn {
            background: linear-gradient(135deg, #4fc3f7 0%, #29b6f6 100%);
            color: #000;
            border: none;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: bold;
            border-radius: 0.5rem;
            cursor: pointer;
            margin-top: 1rem;
        }
        .btn:hover { opacity: 0.9; }
        .btn-danger {
            background: linear-gradient(135deg, #ef5350 0%, #e53935 100%);
            color: #fff;
        }
        .warning {
            background: rgba(255, 152, 0, 0.2);
            border: 1px solid #ff9800;
            padding: 1rem;
            border-radius: 0.5rem;
            margin: 1rem 0;
        }
        .info {
            background: rgba(33, 150, 243, 0.2);
            border: 1px solid #2196f3;
            padding: 1rem;
            border-radius: 0.5rem;
            margin: 1rem 0;
        }
        code {
            background: rgba(0,0,0,0.3);
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Cache System Upgrade</h1>

        <?php if ($doUpgrade): ?>
            <h2>Upgrade Results</h2>

            <?php if (!empty($messages)): ?>
                <ul>
                    <?php foreach ($messages as $msg): ?>
                        <li class="<?php echo strpos($msg, '✓') === 0 ? 'success' : 'skip'; ?>">
                            <?php echo htmlspecialchars($msg); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <h2 style="color: #ef5350;">Errors</h2>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li class="error"><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (empty($errors)): ?>
                <div class="info">
                    <strong>Upgrade Complete!</strong><br>
                    Your database has been upgraded for permanent local caching.
                </div>

                <div class="warning">
                    <strong>Important:</strong> Delete this file now for security!<br>
                    <code>rm upgrade_cache.php</code>
                </div>

                <h2>Optional: Add Cron Job</h2>
                <p>To enable background cache processing, add this cron job (every 5 minutes):</p>
                <code style="display: block; padding: 1rem; margin: 1rem 0; word-break: break-all;">
                    */5 * * * * php <?php echo __DIR__; ?>/cron/process_cache_queue.php >> <?php echo __DIR__; ?>/logs/cache.log 2>&1
                </code>
            <?php endif; ?>

        <?php else: ?>
            <p>This will upgrade your database to support permanent local caching of metadata and thumbnails from Archive.org.</p>

            <h2>What This Upgrade Does:</h2>
            <ul>
                <li>✓ Enables permanent metadata storage (no more expiration)</li>
                <li>✓ Adds background caching queue for thumbnails</li>
                <li>✓ Creates cache statistics tracking</li>
                <li>✓ Marks all existing cached data as permanent</li>
            </ul>

            <div class="info">
                <strong>Safe to Run:</strong> This upgrade is non-destructive and can be run multiple times safely.
            </div>

            <form method="POST">
                <input type="hidden" name="upgrade" value="yes">
                <button type="submit" class="btn">Run Upgrade Now</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
