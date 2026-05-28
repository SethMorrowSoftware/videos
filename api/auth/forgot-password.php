<?php
/**
 * POST /api/auth/forgot-password
 *
 * Body: { email }
 *
 * Always returns success to avoid leaking whether an email is registered.
 * If the email matches an account and SMTP is configured, sends a reset
 * link. The token itself is never returned in the response.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');
$api->requireCsrf();

$body = $api->jsonBody();
$email = strtolower(trim((string)($body['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $api->error('Please enter a valid email address', 400);
}

// Emit the identical generic response BEFORE doing any account-specific work,
// then deliver mail after the client connection is closed. This keeps response
// timing independent of whether the email is registered (only a real account
// triggers the variable-latency SMTP send), closing the enumeration-by-timing
// gap (M8). On hosts with fastcgi_finish_request (PHP-FPM / LiteSpeed) the
// connection is closed before the reset work runs; elsewhere the work still
// runs, just without the early close.
$payload = json_encode([
    'success' => true,
    'message' => 'If an account exists for that email, a reset link has been sent.',
]);
http_response_code(200);
header('Content-Length: ' . strlen($payload));
echo $payload;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Post-response: throttle, look up the account, and email the reset link if it
// matched. Failures are logged, never surfaced — the client already has the
// generic success response.
try {
    $auth = new UserAuthService();
    $result = $auth->startPasswordReset($email);
    if ($result) {
        $mail = new MailService();
        $mail->sendPasswordReset($result['user'], $result['token']);
    }
} catch (Throwable $e) {
    error_log('[api/auth/forgot-password] ' . $e->getMessage());
}
exit;
