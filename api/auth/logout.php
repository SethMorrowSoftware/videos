<?php
/**
 * POST /api/auth/logout
 *
 * Ends the current user's session and clears the remember-me cookie.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');
$api->requireCsrf();

$auth = new UserAuthService();
$auth->logout();

// Return the rotated CSRF token (logout() rotated it) so the client can keep
// making state-changing requests as a guest without a full navigation.
$api->ok(['csrfToken' => csrf_token(), 'message' => 'Logged out']);
