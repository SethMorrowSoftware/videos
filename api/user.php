<?php
/**
 * User API Endpoint
 *
 * Manages user sessions and preferences
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/UserService.php';

$userService = new UserService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get user data
    $action = $_GET['action'] ?? 'info';

    switch ($action) {
        case 'info':
            // Get or create user
            $userId = $userService->getOrCreateUser();
            $prefs = $userService->getPreferences();

            echo json_encode([
                'success' => true,
                'data' => [
                    'hasSession' => true,
                    'preferences' => $prefs,
                ],
            ]);
            break;

        case 'preferences':
            $prefs = $userService->getPreferences();

            echo json_encode([
                'success' => true,
                'data' => $prefs,
            ]);
            break;

        case 'searches':
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
            $searches = $userService->getRecentSearches($limit);

            echo json_encode([
                'success' => true,
                'data' => $searches,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update user data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }

    $action = $data['action'] ?? 'preferences';

    switch ($action) {
        case 'preferences':
            if (!isset($data['preferences']) || !is_array($data['preferences'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing preferences']);
                exit;
            }

            $success = $userService->setPreferences($data['preferences']);

            echo json_encode([
                'success' => $success,
                'message' => 'Preferences updated',
            ]);
            break;

        case 'preference':
            if (!isset($data['key']) || !isset($data['value'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing key or value']);
                exit;
            }

            $success = $userService->setPreference($data['key'], $data['value']);

            echo json_encode([
                'success' => $success,
                'message' => 'Preference updated',
            ]);
            break;

        case 'clearSearches':
            $success = $userService->clearSearchHistory();

            echo json_encode([
                'success' => $success,
                'message' => 'Search history cleared',
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
