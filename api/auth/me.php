<?php
/**
 * GET /api/auth/me
 *
 * Returns the currently-authenticated user, or { authenticated: false }
 * if the visitor is a guest. Also returns guest flags so the frontend
 * can decide whether to prompt "merge data on signup?".
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('GET');

$context = new UserContext();
$user = $context->current();

// Strip anything sensitive + derived fields
unset($user['password_hash'], $user['ip_hash'], $user['user_agent'], $user['session_id']);

$authenticated = !$user['is_guest'];

$guestInfo = null;
if ($user['is_guest']) {
    $db = Database::getInstance();
    $bookmarks = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?",
        [(int)$user['id']]
    );
    $history = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM user_watch_history WHERE user_id = ?",
        [(int)$user['id']]
    );
    $guestInfo = [
        'id' => (int)$user['id'],
        'bookmarks' => $bookmarks,
        'history' => $history,
        'hasData' => ($bookmarks + $history) > 0,
    ];
}

$api->ok([
    'authenticated' => $authenticated,
    'user' => $authenticated ? $user : null,
    'guest' => $guestInfo,
]);
