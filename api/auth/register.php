<?php
/**
 * POST /api/auth/register
 *
 * Body: { username, email, password, display_name?, mergeGuest? }
 *
 * On success, issues an authenticated session immediately (and merges
 * guest bookmarks/history if mergeGuest=true).
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');

$body = $api->jsonBody();
$auth = new UserAuthService();
$context = new UserContext();

// Capture guest id before registration so we can merge on success.
$pendingGuestId = $context->pendingGuestId();

$result = $auth->register($body);

if ($result['user'] === null) {
    $api->error('Validation failed', 422, ['errors' => $result['errors']]);
}

$user = $result['user'];

// Optionally merge guest data into the new account.
$mergeGuest = ApiController::sanitizeBool($body['mergeGuest'] ?? true);
if ($mergeGuest && $pendingGuestId && $pendingGuestId !== (int)$user['id']) {
    try {
        $auth->mergeGuest($pendingGuestId, (int)$user['id']);
    } catch (Throwable $e) {
        error_log('[api/auth/register] merge failed: ' . $e->getMessage());
        // Not fatal — registration succeeded.
    }
}

// Log the new user in by setting the session
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_role'] = $user['role'];

// Fire-and-forget verification email. MailService falls back to PHP mail()
// when SMTP isn't configured, so we always attempt to send.
try {
    $token = $auth->startEmailVerification((int)$user['id']);
    $mail = new MailService();
    $mail->sendEmailVerification($user, $token);
} catch (Throwable $e) {
    error_log('[api/auth/register] verification email: ' . $e->getMessage());
}

$api->ok([
    'user' => $user,
    'message' => 'Account created',
]);
