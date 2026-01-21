<?php
/**
 * Search API Endpoint
 *
 * Proxies Archive.org search with caching
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/ArchiveOrgService.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get search parameters
$query = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$rows = min(50, max(1, (int)($_GET['rows'] ?? 20)));
$collection = $_GET['collection'] ?? 'all_videos';
$sort = $_GET['sort'] ?? 'downloads';

// Validate query
if (empty($query)) {
    // Return empty results for empty query
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

// Sanitize query
$query = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');

try {
    $archiveService = new ArchiveOrgService();

    $result = $archiveService->search([
        'q' => $query,
        'page' => $page,
        'rows' => $rows,
        'collection' => $collection,
        'sort' => $sort,
    ]);

    // Add cache headers
    if ($result['cached'] ?? false) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=300'); // 5 minutes client cache
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=60'); // 1 minute client cache
    }

    echo json_encode($result);

} catch (Exception $e) {
    error_log("Search API error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Search failed. Please try again.',
    ]);
}
