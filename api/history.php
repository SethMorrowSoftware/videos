<?php
/**
 * Watch History API Endpoint
 *
 * GET  ?action=list&limit=N   → recent watch history
 * GET  ?action=progress&id=X  → saved progress for one video
 * POST { action: 'update', id, currentTime, duration }
 * POST { action: 'clear' }
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$userService = new UserService();

if ($api->isGet()) {
    $action = $api->query('action', 'list');

    if ($action === 'list') {
        $limit = min(100, max(1, (int)$api->query('limit', 50)));
        $api->data($userService->getWatchHistory($limit));
    }

    if ($action === 'progress') {
        $id = ApiController::sanitizeArchiveId($api->query('id', ''));
        if ($id === '') {
            $api->error('Missing video ID', 400);
        }
        $api->data($userService->getProgress($id));
    }

    $api->error('Invalid action', 400);
}

// POST
$body = $api->jsonBody();
$action = $body['action'] ?? 'update';

switch ($action) {
    case 'update':
        $id = ApiController::sanitizeArchiveId($api->required($body, 'id'));
        $currentTime = (float)($body['currentTime'] ?? 0);
        $duration = (float)($body['duration'] ?? 0);
        $userService->updateProgress($id, $currentTime, $duration);
        $api->ok(['message' => 'Progress updated']);
        break;

    case 'clear':
        $userService->clearWatchHistory();
        $api->ok(['message' => 'History cleared']);
        break;

    default:
        $api->error('Invalid action', 400);
}
