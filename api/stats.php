<?php
/**
 * Stats/Analytics API Endpoint
 *
 * Returns cache statistics and popular searches
 * Admin-only endpoint
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/AdminAuthService.php';
require_once __DIR__ . '/../services/ArchiveOrgService.php';
require_once __DIR__ . '/../cache/CacheManager.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check admin authentication
$authService = new AdminAuthService();
$admin = $authService->validateSession();

if (!$admin) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? 'overview';

switch ($action) {
    case 'overview':
        $cacheManager = new CacheManager();

        echo json_encode([
            'success' => true,
            'data' => [
                'cache' => $cacheManager->getStats(),
                'hitRates' => $cacheManager->getHitRate('24 hours'),
            ],
        ]);
        break;

    case 'popular':
        $archiveService = new ArchiveOrgService();
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

        echo json_encode([
            'success' => true,
            'data' => $archiveService->getPopularSearches($limit),
        ]);
        break;

    case 'hitrates':
        $cacheManager = new CacheManager();
        $period = $_GET['period'] ?? '24 hours';

        // Validate period
        $validPeriods = ['1 hour', '6 hours', '24 hours', '7 days', '30 days'];
        if (!in_array($period, $validPeriods)) {
            $period = '24 hours';
        }

        echo json_encode([
            'success' => true,
            'data' => $cacheManager->getHitRate($period),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
