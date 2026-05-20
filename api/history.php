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

// Cheap, no-DB CSRF check before touching anything else.
if ($api->isPost()) {
    $api->requireCsrf();
}

$userService = new UserService();

if ($api->isGet()) {
    header('Cache-Control: private, no-store');
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

// POST (CSRF was checked above before any DB access)
$body = $api->jsonBody();
$action = $body['action'] ?? 'update';

switch ($action) {
    case 'update':
        $id = ApiController::sanitizeArchiveId($api->required($body, 'id'));
        $currentTime = max(0.0, (float)($body['currentTime'] ?? 0));
        // Cap duration at 24 hours -- archive.org has nothing longer and this
        // prevents absurd values (1e308) from corrupting the row.
        $duration = max(0.0, min(86400.0, (float)($body['duration'] ?? 0)));
        // Position can't exceed duration when duration is known.
        if ($duration > 0 && $currentTime > $duration) {
            $currentTime = $duration;
        }
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
