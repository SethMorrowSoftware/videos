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

if ($api->isPost()) {
    $api->requireCsrf();
}

$userService = new UserService();

if ($api->isGet()) {
    header('Cache-Control: private, no-store');
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

// POST (CSRF was checked above before any DB access)
$body = $api->jsonBody();
$action = $body['action'] ?? 'preferences';

switch ($action) {
    case 'preferences':
        if (!isset($body['preferences']) || !is_array($body['preferences'])) {
            $api->error('Missing preferences', 400);
        }
        // Cap preference payload size so a hostile client can't bloat the
        // users.preferences JSON column.
        if (strlen(json_encode($body['preferences'])) > 16384) {
            $api->error('Preferences too large', 413);
        }
        $userService->setPreferences($body['preferences']);
        $api->ok(['message' => 'Preferences updated']);
        break;

    case 'preference':
        if (!isset($body['key']) || !is_string($body['key'])) {
            $api->error('Missing key', 400);
        }
        // Validate key shape -- letters, numbers, underscore, dot, dash only.
        // Caps both length and charset so the preferences blob stays sane.
        $key = $body['key'];
        if (strlen($key) > 64 || !preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            $api->error('Invalid preference key', 400);
        }
        if (!array_key_exists('value', $body)) {
            $api->error('Missing value', 400);
        }
        // Cap individual value size.
        if (is_string($body['value']) && strlen($body['value']) > 2048) {
            $api->error('Preference value too large', 413);
        }
        $userService->setPreference($key, $body['value']);
        $api->ok(['message' => 'Preference updated']);
        break;

    case 'clearSearches':
        $userService->clearSearchHistory();
        $api->ok(['message' => 'Search history cleared']);
        break;

    default:
        $api->error('Invalid action', 400);
}
