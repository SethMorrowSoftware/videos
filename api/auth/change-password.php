<?php
/**
 * POST /api/auth/change-password
 *
 * Body: { oldPassword, newPassword }
 * Requires authenticated user.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');
$api->requireCsrf();
$user = $api->requireAuth();

$body = $api->jsonBody();
$old = (string)($body['oldPassword'] ?? '');
$new = (string)($body['newPassword'] ?? '');

if (strlen($new) < 8) {
    $api->error('New password must be at least 8 characters', 400);
}

$auth = new UserAuthService();
if (!$auth->changePassword((int)$user['id'], $old, $new)) {
    $api->error('Current password is incorrect', 400);
}

$api->ok(['message' => 'Password changed']);
