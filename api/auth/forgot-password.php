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

$body = $api->jsonBody();
$email = strtolower(trim((string)($body['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $api->error('Please enter a valid email address', 400);
}

$auth = new UserAuthService();
$result = $auth->startPasswordReset($email);

if ($result) {
    try {
        $mail = new MailService();
        $mail->sendPasswordReset($result['user'], $result['token']);
    } catch (Throwable $e) {
        error_log('[api/auth/forgot-password] mail failed: ' . $e->getMessage());
    }
}

// Always succeed, regardless of whether the email existed.
$api->ok([
    'message' => 'If an account exists for that email, a reset link has been sent.',
]);
