<?php
/**
 * Search Cache - Handles Archive.org search result caching
 */

require_once __DIR__ . '/CacheManager.php';

class SearchCache {
    private $cacheManager;

    public function __construct() {
        $this->cacheManager = new CacheManager();
    }

    /**
     * Get cached search results or null if not cached
     */
    public function get(array $params): ?array {
        $cacheKey = $this->cacheManager->generateKey($params);
        return $this->cacheManager->getSearchCache($cacheKey);
    }

    /**
     * Store search results in cache
     */
    public function set(array $params, array $results, int $totalResults = 0): void {
        $cacheKey = $this->cacheManager->generateKey($params);
        $this->cacheManager->setSearchCache($cacheKey, $params, $results, $totalResults);
    }

    /**
     * Generate a normalized cache key from search parameters
     */
    public function generateKey(array $params): string {
        return $this->cacheManager->generateKey($params);
    }

    /**
     * Check if results are cached
     */
    public function has(array $params): bool {
        return $this->get($params) !== null;
    }
}
