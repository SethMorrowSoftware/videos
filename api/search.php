<?php
/**
 * Search API Endpoint
 *
 * GET ?q=...&page=N&rows=N&collection=X&sort=Y
 * Proxies Archive.org search with caching.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod('GET');

$query = trim((string)$api->query('q', ''));
$page = max(1, (int)$api->query('page', 1));
$rows = min(50, max(1, (int)$api->query('rows', 20)));
$collection = (string)$api->query('collection', 'all_videos');
$sort = (string)$api->query('sort', 'downloads');

if ($query === '') {
    echo json_encode([
        'success' => true,
        'cached' => false,
        'data' => [
            'response' => [
                'numFound' => 0,
                'docs' => [],
            ],
        ],
    ]);
    exit;
}

// NOTE: Do not apply htmlspecialchars here — the query is sent to
// Archive.org's API (via http_build_query, URL-encoded), not rendered
// as HTML. htmlspecialchars would corrupt quotes and ampersands.

try {
    $archiveService = new ArchiveOrgService();
    $result = $archiveService->search([
        'q' => $query,
        'page' => $page,
        'rows' => $rows,
        'collection' => $collection,
        'sort' => $sort,
    ]);

    if ($result['cached'] ?? false) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=300');
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=60');
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('[api/search] ' . $e->getMessage());
    $api->error('Search failed. Please try again.', 500);
}
