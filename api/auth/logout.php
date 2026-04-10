<?php
/**
 * POST /api/auth/logout
 *
 * Ends the current user's session and clears the remember-me cookie.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');

$auth = new UserAuthService();
$auth->logout();

$api->ok(['message' => 'Logged out']);
