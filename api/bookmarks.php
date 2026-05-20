<?php
/**
 * Bookmarks API Endpoint
 *
 * GET             → list current user's bookmarks
 * POST { action } → add | remove | sync | check
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

// CSRF FIRST for POST so a forged request can't even reach DB code.
if ($api->isPost()) {
    $api->requireCsrf();
}

$userService = new UserService();

if ($api->isGet()) {
    header('Cache-Control: private, no-store');
    $api->data($userService->getBookmarks());
}

// POST
$body = $api->jsonBody();
$action = $body['action'] ?? 'add';

switch ($action) {
    case 'add':
        $id = ApiController::sanitizeArchiveId($api->required($body, 'id'));
        $success = $userService->addBookmark($id, [
            'title' => ApiController::sanitizeText($body['title'] ?? null, 500),
            'creator' => ApiController::sanitizeText($body['creator'] ?? null, 255),
            'thumbnail' => $body['thumbnail'] ?? null,
        ]);
        $api->ok([
            'added' => $success,
            'message' => $success ? 'Bookmark added' : 'Already bookmarked',
        ]);
        break;

    case 'remove':
        $id = ApiController::sanitizeArchiveId($api->required($body, 'id'));
        $userService->removeBookmark($id);
        $api->ok(['message' => 'Bookmark removed']);
        break;

    case 'sync':
        if (!isset($body['bookmarks']) || !is_array($body['bookmarks'])) {
            $api->error('Missing bookmarks array', 400);
        }
        $success = $userService->syncBookmarks($body['bookmarks']);
        if (!$success) {
            $api->error('Sync failed', 500);
        }
        $api->ok(['message' => 'Bookmarks synced']);
        break;

    case 'check':
        $id = ApiController::sanitizeArchiveId($api->required($body, 'id'));
        $api->ok(['bookmarked' => $userService->isBookmarked($id)]);
        break;

    default:
        $api->error('Invalid action', 400);
}
