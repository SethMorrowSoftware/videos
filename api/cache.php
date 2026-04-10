<?php
/**
 * Batch Cache API Endpoint
 *
 * POST { action: 'queue' | 'cache_immediate' | 'cache_single'
 *              | 'process_queue' | 'refresh_stale' | 'stats', ... }
 *
 * Used by frontend + cron to proactively cache items.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../cache/CacheManager.php';

// Same-origin only. Removed Access-Control-Allow-Origin: * -- the app does
// not need CORS and wildcard CORS here lets any origin queue caching work.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$api = new ApiController();
$api->requireMethod('POST');

// Actions that read or mutate shared cache state require admin. User-facing
// "queue these items while I browse" actions are open to any same-origin
// session (protected by SameSite=Lax + no-CORS).
$ADMIN_ONLY_ACTIONS = ['process_queue', 'refresh_stale', 'stats'];

try {
    $body = $api->jsonBody();
    $action = $body['action'] ?? 'queue';

    if (in_array($action, $ADMIN_ONLY_ACTIONS, true)) {
        $api->requireAdmin();
    }

    $localStorageService = new LocalStorageService();
    $cacheManager = new CacheManager();

    switch ($action) {
        case 'queue': {
            $items = $body['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                $api->error('Items array is required', 400);
            }
            $results = ['queued_metadata' => 0, 'queued_thumbnails' => 0, 'already_cached' => 0];

            foreach (array_slice($items, 0, 50) as $item) {
                $archiveId = is_string($item) ? $item : ($item['identifier'] ?? null);
                if (!$archiveId || !preg_match('/^[a-zA-Z0-9_-]+$/', $archiveId)) {
                    continue;
                }

                $metadata = $cacheManager->getMetadataCache($archiveId);
                $hasThumbnail = $cacheManager->isThumbnailCached($archiveId);

                if ($metadata && $hasThumbnail && !isset($metadata['_is_stale'])) {
                    $results['already_cached']++;
                    continue;
                }

                if (!$metadata || isset($metadata['_is_stale'])) {
                    $cacheManager->queueForCaching($archiveId, 'metadata', 5);
                    $results['queued_metadata']++;
                }
                if (!$hasThumbnail) {
                    $cacheManager->queueForCaching($archiveId, 'thumbnail', 6);
                    $results['queued_thumbnails']++;
                }
            }
            $api->ok(['results' => $results]);
            break;
        }

        case 'cache_immediate': {
            $items = $body['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                $api->error('Items array is required', 400);
            }
            $items = array_slice($items, 0, 5);
            $results = $localStorageService->batchCacheFromSearch($items, true);
            $api->ok(['results' => $results]);
            break;
        }

        case 'cache_single': {
            $archiveId = $body['archive_id'] ?? null;
            if (!$archiveId || !preg_match('/^[a-zA-Z0-9_-]+$/', $archiveId)) {
                $api->error('Valid archive_id is required', 400);
            }
            $cacheThumbnail = $body['cache_thumbnail'] ?? true;
            $result = $localStorageService->cacheItem($archiveId, null, $cacheThumbnail);
            $api->ok(['result' => $result]);
            break;
        }

        case 'process_queue': {
            $limit = min(50, (int)($body['limit'] ?? 20));
            $api->ok(['results' => $localStorageService->processQueue($limit)]);
            break;
        }

        case 'refresh_stale': {
            $limit = min(20, (int)($body['limit'] ?? 10));
            $api->ok(['results' => $localStorageService->refreshStaleData($limit)]);
            break;
        }

        case 'stats': {
            $api->ok(['stats' => $localStorageService->getStats()]);
            break;
        }

        default:
            $api->error("Unknown action: $action", 400);
    }
} catch (Throwable $e) {
    error_log('[api/cache] ' . $e->getMessage());
    $api->error($e->getMessage(), 400);
}
