<?php
/**
 * Watch History API Endpoint
 *
 * GET: Returns user's watch history
 * POST: Update watch progress
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/UserService.php';

$userService = new UserService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get action
    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $history = $userService->getWatchHistory($limit);

            echo json_encode([
                'success' => true,
                'data' => $history,
            ]);
            break;

        case 'progress':
            $archiveId = $_GET['id'] ?? '';

            if (empty($archiveId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing video ID']);
                exit;
            }

            $progress = $userService->getProgress($archiveId);

            echo json_encode([
                'success' => true,
                'data' => $progress,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }

    $action = $data['action'] ?? 'update';

    switch ($action) {
        case 'update':
            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing video ID']);
                exit;
            }

            $currentTime = (float)($data['currentTime'] ?? 0);
            $duration = (float)($data['duration'] ?? 0);

            $success = $userService->updateProgress($data['id'], $currentTime, $duration);

            echo json_encode([
                'success' => $success,
                'message' => 'Progress updated',
            ]);
            break;

        case 'clear':
            $success = $userService->clearWatchHistory();

            echo json_encode([
                'success' => $success,
                'message' => 'History cleared',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
