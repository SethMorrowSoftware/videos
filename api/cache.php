<?php
/**
 * Batch Cache API Endpoint
 *
 * Handles batch caching requests for metadata and thumbnails.
 * Used by frontend to proactively cache items as users browse.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../services/LocalStorageService.php';
require_once __DIR__ . '/../cache/CacheManager.php';

// Only allow POST for batch operations
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $action = $input['action'] ?? 'queue';
    $localStorageService = new LocalStorageService();
    $cacheManager = new CacheManager();

    switch ($action) {
        case 'queue':
            // Queue items for background caching
            $items = $input['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                throw new Exception('Items array is required');
            }

            $results = [
                'queued_metadata' => 0,
                'queued_thumbnails' => 0,
                'already_cached' => 0,
            ];

            foreach (array_slice($items, 0, 50) as $item) { // Limit to 50 items
                $archiveId = is_string($item) ? $item : ($item['identifier'] ?? null);
                if (!$archiveId || !preg_match('/^[a-zA-Z0-9_-]+$/', $archiveId)) {
                    continue;
                }

                // Check if already fully cached
                $metadata = $cacheManager->getMetadataCache($archiveId);
                $hasThumbnail = $cacheManager->isThumbnailCached($archiveId);

                if ($metadata && $hasThumbnail && !isset($metadata['_is_stale'])) {
                    $results['already_cached']++;
                    continue;
                }

                // Queue for caching
                if (!$metadata || isset($metadata['_is_stale'])) {
                    $cacheManager->queueForCaching($archiveId, 'metadata', 5);
                    $results['queued_metadata']++;
                }

                if (!$hasThumbnail) {
                    $cacheManager->queueForCaching($archiveId, 'thumbnail', 6);
                    $results['queued_thumbnails']++;
                }
            }

            echo json_encode([
                'success' => true,
                'results' => $results,
            ]);
            break;

        case 'cache_immediate':
            // Cache items immediately (use sparingly)
            $items = $input['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                throw new Exception('Items array is required');
            }

            // Limit to 5 items for immediate caching
            $items = array_slice($items, 0, 5);
            $results = $localStorageService->batchCacheFromSearch($items, true);

            echo json_encode([
                'success' => true,
                'results' => $results,
            ]);
            break;

        case 'cache_single':
            // Cache a single item immediately
            $archiveId = $input['archive_id'] ?? null;
            if (!$archiveId || !preg_match('/^[a-zA-Z0-9_-]+$/', $archiveId)) {
                throw new Exception('Valid archive_id is required');
            }

            $cacheThumbnail = $input['cache_thumbnail'] ?? true;
            $result = $localStorageService->cacheItem($archiveId, null, $cacheThumbnail);

            echo json_encode([
                'success' => true,
                'result' => $result,
            ]);
            break;

        case 'process_queue':
            // Process background queue (called by cron or admin)
            // Verify this is an internal/admin request
            $limit = min(50, (int)($input['limit'] ?? 20));
            $results = $localStorageService->processQueue($limit);

            echo json_encode([
                'success' => true,
                'results' => $results,
            ]);
            break;

        case 'refresh_stale':
            // Refresh stale data (called by cron)
            $limit = min(20, (int)($input['limit'] ?? 10));
            $results = $localStorageService->refreshStaleData($limit);

            echo json_encode([
                'success' => true,
                'results' => $results,
            ]);
            break;

        case 'stats':
            // Get caching statistics
            $stats = $localStorageService->getStats();
            echo json_encode([
                'success' => true,
                'stats' => $stats,
            ]);
            break;

        default:
            throw new Exception("Unknown action: $action");
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
