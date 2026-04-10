<?php
/**
 * POST /api/auth/login
 *
 * Body: { identifier, password, remember?, mergeGuest? }
 *   identifier = username or email
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');

$body = $api->jsonBody();
$identifier = $api->str($body, 'identifier');
$password = $body['password'] ?? '';
$remember = ApiController::sanitizeBool($body['remember'] ?? false);
$mergeGuest = ApiController::sanitizeBool($body['mergeGuest'] ?? true);

if (!$identifier || !is_string($password) || $password === '') {
    $api->error('Missing credentials', 400);
}

$context = new UserContext();
$pendingGuestId = $context->pendingGuestId();

$auth = new UserAuthService();
$user = $auth->login($identifier, $password, $remember);

if (!$user) {
    $api->error('Invalid username or password', 401);
}

// Merge guest bookmarks/history into the account if we were guests.
if ($mergeGuest && $pendingGuestId && $pendingGuestId !== (int)$user['id']) {
    try {
        $auth->mergeGuest($pendingGuestId, (int)$user['id']);
    } catch (Throwable $e) {
        error_log('[api/auth/login] merge failed: ' . $e->getMessage());
    }
}

$api->ok(['user' => $user, 'message' => 'Logged in']);
