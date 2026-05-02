<?php
/**
 * POST /api/auth/profile
 *
 * Body: { display_name?, email? }
 *
 * Updates the authenticated user's profile fields. Email is normalized to
 * lower-case and checked for uniqueness against other accounts. Changing
 * the email clears email_verified_at so the user re-verifies.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod('POST');
$user = $api->requireAuth();

$body = $api->jsonBody();
$userId = (int)$user['id'];

$updates = [];
$errors = [];

if (array_key_exists('display_name', $body)) {
    $displayName = trim((string)$body['display_name']);
    if ($displayName === '') {
        $errors['display_name'] = 'Display name cannot be empty';
    } elseif (mb_strlen($displayName) > 100) {
        $errors['display_name'] = 'Display name is too long';
    } else {
        $updates['display_name'] = $displayName;
    }
}

$db = Database::getInstance();
$emailChanged = false;

if (array_key_exists('email', $body)) {
    $email = strtolower(trim((string)$body['email']));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email address';
    } elseif ($email !== strtolower($user['email'] ?? '')) {
        // Uniqueness check
        $taken = $db->fetchOne(
            "SELECT id FROM users WHERE email = ? AND id <> ? AND is_guest = 0",
            [$email, $userId]
        );
        if ($taken) {
            $errors['email'] = 'Email is already in use';
        } else {
            $updates['email'] = $email;
            $emailChanged = true;
        }
    }
}

if ($errors) {
    $api->error('Validation failed', 422, ['errors' => $errors]);
}

if (!$updates) {
    $api->ok(['message' => 'Nothing to update']);
}

$repo = new UserRepository();
$repo->updateProfile($userId, $updates);

if ($emailChanged) {
    // Drop verified-at so the user re-verifies the new address.
    $db->query("UPDATE users SET email_verified_at = NULL WHERE id = ?", [$userId]);

    // Fire-and-forget verification email. MailService falls back to PHP mail()
    // when SMTP isn't configured, so we always attempt to send.
    try {
        $auth = new UserAuthService();
        $token = $auth->startEmailVerification($userId);
        $freshUser = $repo->findById($userId);
        $mail = new MailService();
        $mail->sendEmailVerification($freshUser, $token);
    } catch (Throwable $e) {
        error_log('[api/auth/profile] verification email: ' . $e->getMessage());
    }
}

$fresh = $repo->findById($userId);
unset($fresh['password_hash']);

$api->ok(['user' => $fresh, 'message' => 'Profile updated']);
