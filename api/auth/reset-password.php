<?php
/**
 * POST /api/auth/reset-password
 *
 * Body: { token, password }
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');

$body = $api->jsonBody();
$token = (string)($body['token'] ?? '');
$password = (string)($body['password'] ?? '');

if ($token === '') {
    $api->error('Missing token', 400);
}
if (strlen($password) < 8) {
    $api->error('Password must be at least 8 characters', 400);
}

$auth = new UserAuthService();
if (!$auth->completePasswordReset($token, $password)) {
    $api->error('Invalid or expired reset token', 400);
}

$api->ok(['message' => 'Password reset successfully. You can now log in.']);
