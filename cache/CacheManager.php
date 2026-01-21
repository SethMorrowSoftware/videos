<?php
/**
 * Unified Cache Manager
 *
 * Coordinates all caching operations for the application
 */

require_once __DIR__ . '/../db/Database.php';

class CacheManager {
    private $db;
    private $config;

    // Cache TTL settings (in seconds)
    private $searchTTL = 1800;      // 30 minutes
    private $metadataTTL = 86400;   // 24 hours
    private $thumbnailTTL = 604800; // 7 days
    private $settingsTTL = 3600;    // 1 hour

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = $this->db->getConfig();

        // Load TTL settings from config
        if (isset($this->config['cache'])) {
            $this->searchTTL = $this->config['cache']['search_ttl'] ?? $this->searchTTL;
            $this->metadataTTL = $this->config['cache']['metadata_ttl'] ?? $this->metadataTTL;
            $this->thumbnailTTL = $this->config['cache']['thumbnail_ttl'] ?? $this->thumbnailTTL;
            $this->settingsTTL = $this->config['cache']['settings_ttl'] ?? $this->settingsTTL;
        }
    }

    /**
     * Generate a cache key from parameters
     */
    public function generateKey(array $params): string {
        ksort($params); // Ensure consistent key generation
        return hash('sha256', json_encode($params));
    }

    // =====================================================
    // SEARCH CACHE
    // =====================================================

    /**
     * Get cached search results
     */
    public function getSearchCache(string $cacheKey): ?array {
        $result = $this->db->fetchOne(
            "SELECT response_data, hit_count FROM search_cache
             WHERE cache_key = ? AND expires_at > NOW()",
            [$cacheKey]
        );

        if ($result) {
            // Update hit count and last accessed
            $this->db->query(
                "UPDATE search_cache SET hit_count = hit_count + 1, last_accessed = NOW()
                 WHERE cache_key = ?",
                [$cacheKey]
            );

            return json_decode($result['response_data'], true);
        }

        return null;
    }

    /**
     * Set search cache
     */
    public function setSearchCache(string $cacheKey, array $params, array $data, int $resultCount = 0): void {
        $expiresAt = date('Y-m-d H:i:s', time() + $this->searchTTL);

        $this->db->query(
            "INSERT INTO search_cache (cache_key, query_params, response_data, result_count, expires_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                response_data = VALUES(response_data),
                result_count = VALUES(result_count),
                expires_at = VALUES(expires_at),
                hit_count = 0,
                last_accessed = NOW()",
            [
                $cacheKey,
                json_encode($params),
                json_encode($data),
                $resultCount,
                $expiresAt
            ]
        );
    }

    // =====================================================
    // METADATA CACHE
    // =====================================================

    /**
     * Get cached video metadata
     */
    public function getMetadataCache(string $archiveId): ?array {
        $result = $this->db->fetchOne(
            "SELECT * FROM video_metadata_cache
             WHERE archive_id = ? AND expires_at > NOW()",
            [$archiveId]
        );

        if ($result) {
            // Parse files JSON if present
            if (!empty($result['files_json'])) {
                $result['files'] = json_decode($result['files_json'], true);
            }
            unset($result['files_json']);

            return $result;
        }

        return null;
    }

    /**
     * Set metadata cache
     */
    public function setMetadataCache(string $archiveId, array $metadata): void {
        $expiresAt = date('Y-m-d H:i:s', time() + $this->metadataTTL);

        $filesJson = null;
        if (isset($metadata['files'])) {
            $filesJson = json_encode($metadata['files']);
        }

        $subject = null;
        if (isset($metadata['subject'])) {
            $subject = is_array($metadata['subject'])
                ? implode(', ', $metadata['subject'])
                : $metadata['subject'];
        }

        $this->db->query(
            "INSERT INTO video_metadata_cache
             (archive_id, title, description, creator, date, runtime, mediatype, downloads, license_url, subject, files_json, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                creator = VALUES(creator),
                date = VALUES(date),
                runtime = VALUES(runtime),
                mediatype = VALUES(mediatype),
                downloads = VALUES(downloads),
                license_url = VALUES(license_url),
                subject = VALUES(subject),
                files_json = VALUES(files_json),
                expires_at = VALUES(expires_at),
                updated_at = NOW()",
            [
                $archiveId,
                $this->extractValue($metadata, 'title'),
                $this->extractValue($metadata, 'description'),
                $this->extractValue($metadata, 'creator'),
                $this->extractValue($metadata, 'date'),
                $this->extractValue($metadata, 'runtime'),
                $this->extractValue($metadata, 'mediatype'),
                (int)($metadata['downloads'] ?? 0),
                $this->extractValue($metadata, 'licenseurl'),
                $subject,
                $filesJson,
                $expiresAt
            ]
        );
    }

    /**
     * Extract value from metadata (handles arrays)
     */
    private function extractValue(array $data, string $key): ?string {
        if (!isset($data[$key])) {
            return null;
        }
        return is_array($data[$key]) ? $data[$key][0] : $data[$key];
    }

    // =====================================================
    // THUMBNAIL CACHE
    // =====================================================

    /**
     * Get cached thumbnail path
     */
    public function getThumbnailPath(string $archiveId): ?string {
        $result = $this->db->fetchOne(
            "SELECT local_path FROM thumbnail_cache WHERE archive_id = ?",
            [$archiveId]
        );

        if ($result && file_exists($result['local_path'])) {
            // Update access count and time
            $this->db->query(
                "UPDATE thumbnail_cache SET access_count = access_count + 1, last_accessed = NOW()
                 WHERE archive_id = ?",
                [$archiveId]
            );

            return $result['local_path'];
        }

        return null;
    }

    /**
     * Cache a thumbnail
     */
    public function cacheThumbnail(string $archiveId, string $localPath, string $originalUrl, array $imageInfo = []): void {
        $this->db->query(
            "INSERT INTO thumbnail_cache (archive_id, original_url, local_path, file_size, width, height, mime_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                local_path = VALUES(local_path),
                file_size = VALUES(file_size),
                width = VALUES(width),
                height = VALUES(height),
                access_count = access_count + 1,
                last_accessed = NOW()",
            [
                $archiveId,
                $originalUrl,
                $localPath,
                $imageInfo['size'] ?? 0,
                $imageInfo['width'] ?? 0,
                $imageInfo['height'] ?? 0,
                $imageInfo['mime'] ?? 'image/jpeg'
            ]
        );
    }

    // =====================================================
    // CACHE CLEANUP
    // =====================================================

    /**
     * Clean up expired cache entries
     */
    public function cleanExpiredCache(): array {
        $deleted = [
            'search' => 0,
            'metadata' => 0,
            'thumbnails' => 0,
        ];

        // Clean search cache
        $stmt = $this->db->query("DELETE FROM search_cache WHERE expires_at < NOW()");
        $deleted['search'] = $stmt->rowCount();

        // Clean metadata cache
        $stmt = $this->db->query("DELETE FROM video_metadata_cache WHERE expires_at < NOW()");
        $deleted['metadata'] = $stmt->rowCount();

        // Clean old thumbnails (not accessed in 30 days)
        $oldThumbnails = $this->db->fetchAll(
            "SELECT local_path FROM thumbnail_cache WHERE last_accessed < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        foreach ($oldThumbnails as $thumb) {
            if (file_exists($thumb['local_path'])) {
                unlink($thumb['local_path']);
            }
        }

        $stmt = $this->db->query(
            "DELETE FROM thumbnail_cache WHERE last_accessed < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $deleted['thumbnails'] = $stmt->rowCount();

        return $deleted;
    }

    // =====================================================
    // CACHE STATISTICS
    // =====================================================

    /**
     * Get cache statistics
     */
    public function getStats(): array {
        $stats = [];

        // Search cache stats
        $searchStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total, SUM(hit_count) as total_hits
             FROM search_cache WHERE expires_at > NOW()"
        );
        $stats['search'] = [
            'entries' => (int)($searchStats['total'] ?? 0),
            'total_hits' => (int)($searchStats['total_hits'] ?? 0),
        ];

        // Metadata cache stats
        $metadataStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM video_metadata_cache WHERE expires_at > NOW()"
        );
        $stats['metadata'] = [
            'entries' => (int)($metadataStats['total'] ?? 0),
        ];

        // Thumbnail cache stats
        $thumbStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total, SUM(file_size) as total_size, SUM(access_count) as total_access
             FROM thumbnail_cache"
        );
        $stats['thumbnails'] = [
            'entries' => (int)($thumbStats['total'] ?? 0),
            'total_size_bytes' => (int)($thumbStats['total_size'] ?? 0),
            'total_accesses' => (int)($thumbStats['total_access'] ?? 0),
        ];

        return $stats;
    }

    /**
     * Get cache hit rate from API usage log
     */
    public function getHitRate(string $period = '24 hours'): array {
        return $this->db->fetchAll(
            "SELECT
                endpoint,
                COUNT(*) as total_requests,
                SUM(cache_hit) as cache_hits,
                ROUND(AVG(response_time_ms), 2) as avg_response_time,
                ROUND(SUM(cache_hit) / COUNT(*) * 100, 2) as hit_rate
             FROM api_usage_log
             WHERE created_at > DATE_SUB(NOW(), INTERVAL $period)
             GROUP BY endpoint"
        );
    }
}
