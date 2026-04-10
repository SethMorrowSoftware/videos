<?php
/**
 * User API Endpoint
 *
 * GET  ?action=info         → current session user info + preferences
 * GET  ?action=preferences  → just preferences
 * GET  ?action=searches     → recent search history
 * POST { action: 'preferences', preferences: {...} }
 * POST { action: 'preference', key, value }
 * POST { action: 'clearSearches' }
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$userService = new UserService();

if ($api->isGet()) {
    $action = $api->query('action', 'info');

    switch ($action) {
        case 'info':
            $userService->getOrCreateUser();
            $api->data([
                'hasSession' => true,
                'preferences' => $userService->getPreferences(),
            ]);
            break;

        case 'preferences':
            $api->data($userService->getPreferences());
            break;

        case 'searches':
            $limit = min(50, max(1, (int)$api->query('limit', 10)));
            $api->data($userService->getRecentSearches($limit));
            break;

        default:
            $api->error('Invalid action', 400);
    }
}

// POST
$body = $api->jsonBody();
$action = $body['action'] ?? 'preferences';

switch ($action) {
    case 'preferences':
        if (!isset($body['preferences']) || !is_array($body['preferences'])) {
            $api->error('Missing preferences', 400);
        }
        $userService->setPreferences($body['preferences']);
        $api->ok(['message' => 'Preferences updated']);
        break;

    case 'preference':
        if (!isset($body['key'])) {
            $api->error('Missing key', 400);
        }
        if (!array_key_exists('value', $body)) {
            $api->error('Missing value', 400);
        }
        $userService->setPreference($body['key'], $body['value']);
        $api->ok(['message' => 'Preference updated']);
        break;

    case 'clearSearches':
        $userService->clearSearchHistory();
        $api->ok(['message' => 'Search history cleared']);
        break;

    default:
        $api->error('Invalid action', 400);
}
