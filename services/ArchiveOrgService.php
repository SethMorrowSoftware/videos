<?php
/**
 * Archive.org API Service
 *
 * Handles all communication with Archive.org API
 * with caching support for improved performance
 */

require_once __DIR__ . '/../cache/SearchCache.php';
require_once __DIR__ . '/../cache/MetadataCache.php';
require_once __DIR__ . '/../db/Database.php';

class ArchiveOrgService {
    private $searchCache;
    private $metadataCache;
    private $db;
    private $config;

    // API settings
    const API_BASE_URL = 'https://archive.org';
    const API_TIMEOUT = 15;
    const USER_AGENT = 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)';

    public function __construct() {
        $this->searchCache = new SearchCache();
        $this->metadataCache = new MetadataCache();
        $this->db = Database::getInstance();
        $this->config = $this->db->getConfig();
    }

    /**
     * Search the Archive.org collection
     */
    public function search(array $params): array {
        $startTime = microtime(true);
        $cacheHit = false;

        // Normalize parameters
        $searchParams = $this->normalizeSearchParams($params);

        // Check cache first
        $cached = $this->searchCache->get($searchParams);

        if ($cached !== null) {
            $cacheHit = true;
            $result = [
                'success' => true,
                'cached' => true,
                'cache_age' => 0, // Could calculate from timestamp
                'data' => $cached,
            ];
        } else {
            // Fetch from Archive.org
            $apiResult = $this->fetchFromArchive($searchParams);

            if ($apiResult['success']) {
                // Cache the results
                $this->searchCache->set(
                    $searchParams,
                    $apiResult['data'],
                    $apiResult['data']['response']['numFound'] ?? 0
                );

                $result = [
                    'success' => true,
                    'cached' => false,
                    'data' => $apiResult['data'],
                ];
            } else {
                $result = [
                    'success' => false,
                    'error' => $apiResult['error'],
                ];
            }
        }

        // Log API usage if enabled
        if ($this->config['features']['api_logging'] ?? false) {
            $this->logApiUsage('search', $cacheHit, microtime(true) - $startTime);
        }

        // Track popular searches
        if (isset($params['q']) && !empty($params['q'])) {
            $this->trackPopularSearch($params['q']);
        }

        return $result;
    }

    /**
     * Get video metadata
     */
    public function getMetadata(string $archiveId): array {
        $startTime = microtime(true);
        $cacheHit = false;

        // Check cache first
        $cached = $this->metadataCache->get($archiveId);

        if ($cached !== null) {
            $cacheHit = true;
            $result = [
                'success' => true,
                'cached' => true,
                'data' => $cached,
            ];
        } else {
            // Fetch from Archive.org
            $url = self::API_BASE_URL . "/metadata/{$archiveId}";
            $response = $this->httpGet($url);

            if ($response['success'] && !empty($response['data'])) {
                $data = json_decode($response['data'], true);

                if ($data && isset($data['metadata'])) {
                    // Extract and normalize metadata
                    $metadata = $this->normalizeMetadata($archiveId, $data);

                    // Cache it
                    $this->metadataCache->set($archiveId, $metadata);

                    $result = [
                        'success' => true,
                        'cached' => false,
                        'data' => $metadata,
                    ];
                } else {
                    $result = [
                        'success' => false,
                        'error' => 'Invalid metadata response',
                    ];
                }
            } else {
                $result = [
                    'success' => false,
                    'error' => $response['error'] ?? 'Failed to fetch metadata',
                ];
            }
        }

        // Log API usage
        if ($this->config['features']['api_logging'] ?? false) {
            $this->logApiUsage('metadata', $cacheHit, microtime(true) - $startTime);
        }

        return $result;
    }

    /**
     * Normalize search parameters
     */
    private function normalizeSearchParams(array $params): array {
        $normalized = [
            'q' => $params['q'] ?? '',
            'page' => max(1, (int)($params['page'] ?? 1)),
            'rows' => min(50, max(1, (int)($params['rows'] ?? 20))),
            'sort' => $params['sort'] ?? 'downloads desc',
        ];

        // Build collection filter
        $collection = $params['collection'] ?? 'all_videos';
        if ($collection && $collection !== 'all_videos') {
            $normalized['collection'] = $collection;
        }

        // Add media type filter for videos
        $normalized['mediatype'] = 'movies';

        return $normalized;
    }

    /**
     * Fetch search results from Archive.org
     */
    private function fetchFromArchive(array $params): array {
        // Build query
        $query = $params['q'];

        // Add collection filter
        if (!empty($params['collection'])) {
            $query .= " AND collection:{$params['collection']}";
        }

        // Add media type filter
        if (!empty($params['mediatype'])) {
            $query .= " AND mediatype:{$params['mediatype']}";
        }

        // Build API URL
        $apiParams = [
            'q' => $query,
            'fl' => 'identifier,title,creator,description,downloads,date,mediatype,collection,runtime',
            'rows' => $params['rows'],
            'page' => $params['page'],
            'output' => 'json',
        ];

        // Handle sort
        $sortMap = [
            'downloads' => 'downloads desc',
            'date' => 'date desc',
            'title' => 'titleSorter asc',
            'creator' => 'creatorSorter asc',
            'relevance' => '',
        ];
        $sort = $params['sort'] ?? 'downloads desc';
        if (isset($sortMap[$sort])) {
            $sort = $sortMap[$sort];
        }
        if (!empty($sort)) {
            $apiParams['sort'] = $sort;
        }

        $url = self::API_BASE_URL . '/advancedsearch.php?' . http_build_query($apiParams);
        $response = $this->httpGet($url);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'API request failed',
            ];
        }

        $data = json_decode($response['data'], true);

        if (!$data || !isset($data['response'])) {
            return [
                'success' => false,
                'error' => 'Invalid API response',
            ];
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Normalize metadata from Archive.org response
     */
    private function normalizeMetadata(string $archiveId, array $data): array {
        $metadata = $data['metadata'] ?? [];
        $files = $data['files'] ?? [];

        // Helper to extract array values
        $extract = function($key) use ($metadata) {
            if (!isset($metadata[$key])) return null;
            return is_array($metadata[$key]) ? $metadata[$key][0] : $metadata[$key];
        };

        // Find video files
        $videoFiles = [];
        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $format = strtolower($file['format'] ?? '');

            // Check for video formats
            if (preg_match('/\.(mp4|ogv|webm|avi|mkv|mov)$/i', $name) ||
                in_array($format, ['h.264', 'mpeg4', 'ogg video', 'webm', '512kb mpeg4'])) {
                $videoFiles[] = [
                    'name' => $name,
                    'format' => $format,
                    'size' => $file['size'] ?? 0,
                    'url' => "https://archive.org/download/{$archiveId}/{$name}",
                ];
            }
        }

        return [
            'identifier' => $archiveId,
            'title' => $extract('title') ?? $archiveId,
            'description' => $extract('description'),
            'creator' => $extract('creator'),
            'date' => $extract('date'),
            'runtime' => $extract('runtime'),
            'mediatype' => $extract('mediatype'),
            'downloads' => (int)($metadata['downloads'] ?? 0),
            'licenseurl' => $extract('licenseurl'),
            'subject' => $metadata['subject'] ?? [],
            'collection' => $metadata['collection'] ?? [],
            'files' => $videoFiles,
            'thumbnail' => "https://archive.org/services/img/{$archiveId}",
        ];
    }

    /**
     * Make HTTP GET request
     */
    private function httpGet(string $url): array {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::API_TIMEOUT,
                'user_agent' => self::USER_AGENT,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            return [
                'success' => false,
                'error' => 'Network request failed',
            ];
        }

        // Check HTTP status
        $status = 200;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/[\d.]+\s+(\d+)/', $http_response_header[0], $matches);
            $status = (int)($matches[1] ?? 200);
        }

        if ($status >= 400) {
            return [
                'success' => false,
                'error' => "HTTP error: $status",
            ];
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Log API usage
     */
    private function logApiUsage(string $endpoint, bool $cacheHit, float $duration): void {
        try {
            $this->db->insert('api_usage_log', [
                'endpoint' => $endpoint,
                'cache_hit' => $cacheHit ? 1 : 0,
                'response_time_ms' => (int)($duration * 1000),
            ]);
        } catch (Exception $e) {
            // Silently fail - logging shouldn't break the request
            error_log("Failed to log API usage: " . $e->getMessage());
        }
    }

    /**
     * Track popular searches
     */
    private function trackPopularSearch(string $query): void {
        try {
            $queryHash = hash('sha256', strtolower(trim($query)));

            $this->db->query(
                "INSERT INTO popular_searches (query, query_hash, search_count, last_searched)
                 VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    search_count = search_count + 1,
                    last_searched = NOW()",
                [$query, $queryHash]
            );
        } catch (Exception $e) {
            // Silently fail
            error_log("Failed to track popular search: " . $e->getMessage());
        }
    }

    /**
     * Get popular searches
     */
    public function getPopularSearches(int $limit = 10): array {
        return $this->db->fetchAll(
            "SELECT query, search_count FROM popular_searches
             ORDER BY search_count DESC LIMIT ?",
            [$limit]
        );
    }
}
