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
     */
    public function set(string $archiveId, array $metadata): void {
        $this->cacheManager->setMetadataCache($archiveId, $metadata);
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
