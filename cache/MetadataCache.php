<?php
/**
 * Metadata Cache - Handles Archive.org video metadata caching
 */

require_once __DIR__ . '/CacheManager.php';

class MetadataCache {
    private $cacheManager;

    public function __construct() {
        $this->cacheManager = new CacheManager();
    }

    /**
     * Get cached metadata for a video
     */
    public function get(string $archiveId): ?array {
        return $this->cacheManager->getMetadataCache($archiveId);
    }

    /**
     * Store metadata in cache
     * @param string $archiveId The Archive.org identifier
     * @param array $metadata Normalized metadata
     * @param array|null $rawMetadata Optional raw Archive.org response for permanent storage
     */
    public function set(string $archiveId, array $metadata, ?array $rawMetadata = null): void {
        $this->cacheManager->setMetadataCache($archiveId, $metadata, $rawMetadata);
    }

    /**
     * Check if an item is cached but stale (needs refresh)
     */
    public function isStale(string $archiveId): bool {
        $cached = $this->get($archiveId);
        return $cached !== null && isset($cached['_is_stale']) && $cached['_is_stale'];
    }

    /**
     * Mark an item as stale (for background refresh)
     */
    public function markStale(string $archiveId): void {
        $this->cacheManager->markMetadataStale($archiveId);
    }

    /**
     * Check if metadata is cached
     */
    public function has(string $archiveId): bool {
        return $this->get($archiveId) !== null;
    }

    /**
     * Update specific fields in cached metadata
     */
    public function update(string $archiveId, array $fields): void {
        $existing = $this->get($archiveId);
        if ($existing) {
            $merged = array_merge($existing, $fields);
            $this->set($archiveId, $merged);
        }
    }
}
