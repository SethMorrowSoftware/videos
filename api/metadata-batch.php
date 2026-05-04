<?php
/**
 * Batch Video Metadata API Endpoint
 *
 * GET ?ids=id1,id2,id3,... → cached metadata for multiple videos in a single request
 * POST { ids: [...] }      → same, but for longer ID lists
 *
 * Returns: { success: true, data: { id1: {...metadata}, id2: {...}, ... } }
 *
 * Heavily cached: results that come fully from local cache get an aggressive
 * cache header so the browser/SW never re-asks. Designed specifically for
 * staff picks / featured sections where the same handful of IDs are loaded
 * on every homepage hit.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

// Collect IDs from either query string or JSON body
$ids = [];
if ($api->isGet()) {
    $idsParam = trim((string)$api->query('ids', ''));
    if ($idsParam !== '') {
        $ids = array_filter(array_map('trim', explode(',', $idsParam)));
    }
} else {
    $body = $api->jsonBody();
    if (isset($body['ids']) && is_array($body['ids'])) {
        $ids = $body['ids'];
    }
}

// Sanitize and dedupe
$cleanIds = [];
foreach ($ids as $id) {
    $clean = ApiController::sanitizeArchiveId($id);
    if ($clean !== '') {
        $cleanIds[$clean] = true;
    }
}
$ids = array_keys($cleanIds);

if (empty($ids)) {
    $api->ok(['data' => (object)[]]);
}

// Cap to a sane limit so the endpoint can't be used to hammer Archive.org
$ids = array_slice($ids, 0, 50);

try {
    $archiveService = new ArchiveOrgService();
    $cacheManager = new CacheManager();

    $results = [];
    $allCached = true;
    $needsFetch = [];

    // First pass: pull everything we already have from local cache
    foreach ($ids as $id) {
        $cached = null;
        try {
            $cached = $cacheManager->getMetadataCache($id);
        } catch (Throwable $e) {
            // Cache table missing — fall back to fetching
        }

        if ($cached !== null) {
            // Strip internal flags before sending to client
            unset($cached['raw_metadata'], $cached['_is_stale']);
            $results[$id] = $cached;
            // Stale entries still get returned — the cache layer will
            // queue them for background refresh.
        } else {
            $needsFetch[] = $id;
            $allCached = false;
        }
    }

    // Second pass: fetch anything that wasn't cached. We do this serially
    // so a slow/failing call to one ID doesn't block the rest indefinitely,
    // but keep them inside this single request so the client only sees
    // one round-trip.
    foreach ($needsFetch as $id) {
        try {
            $fetchResult = $archiveService->getMetadata($id);
            if (!empty($fetchResult['success']) && !empty($fetchResult['data'])) {
                $data = $fetchResult['data'];
                unset($data['raw_metadata'], $data['_is_stale']);
                $results[$id] = $data;
            } else {
                // Return a minimal stub so the client can still render
                // something rather than a missing card
                $results[$id] = [
                    'identifier' => $id,
                    'title' => $id,
                    'thumbnail' => "https://archive.org/services/img/{$id}",
                    '_unavailable' => true,
                ];
            }
        } catch (Throwable $e) {
            error_log("[api/metadata-batch] Fetch failed for {$id}: " . $e->getMessage());
            $results[$id] = [
                'identifier' => $id,
                'title' => $id,
                'thumbnail' => "https://archive.org/services/img/{$id}",
                '_unavailable' => true,
            ];
        }
    }

    // Cache headers: when everything was already cached locally we can be
    // very aggressive. Otherwise use a shorter window so the next page load
    // picks up freshly cached items.
    if ($allCached) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=300, stale-while-revalidate=3600');
    }

    $api->data($results);
} catch (Throwable $e) {
    error_log('[api/metadata-batch] ' . $e->getMessage());
    $api->error('Failed to fetch batch metadata', 500);
}
