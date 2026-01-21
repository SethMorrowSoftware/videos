<?php
/**
 * Bookmarks API Endpoint
 *
 * GET: Returns user's bookmarks
 * POST: Add/remove/sync bookmarks
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/UserService.php';

$userService = new UserService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get user bookmarks
    $bookmarks = $userService->getBookmarks();

    echo json_encode([
        'success' => true,
        'data' => $bookmarks,
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }

    $action = $data['action'] ?? 'add';

    switch ($action) {
        case 'add':
            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing video ID']);
                exit;
            }

            $success = $userService->addBookmark($data['id'], [
                'title' => $data['title'] ?? null,
                'creator' => $data['creator'] ?? null,
                'thumbnail' => $data['thumbnail'] ?? null,
            ]);

            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Bookmark added' : 'Already bookmarked',
            ]);
            break;

        case 'remove':
            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing video ID']);
                exit;
            }

            $success = $userService->removeBookmark($data['id']);

            echo json_encode([
                'success' => $success,
                'message' => 'Bookmark removed',
            ]);
            break;

        case 'sync':
            if (!isset($data['bookmarks']) || !is_array($data['bookmarks'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing bookmarks array']);
                exit;
            }

            $success = $userService->syncBookmarks($data['bookmarks']);

            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Bookmarks synced' : 'Sync failed',
            ]);
            break;

        case 'check':
            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing video ID']);
                exit;
            }

            $isBookmarked = $userService->isBookmarked($data['id']);

            echo json_encode([
                'success' => true,
                'bookmarked' => $isBookmarked,
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
