<?php
/**
 * UserAuthService
 *
 * Account lifecycle: register, login, logout, remember-me, password reset,
 * email verification. Works with the unified `users` table — both admins
 * and regular viewers live there, distinguished by the `role` column.
 *
 * This replaces the dedicated AdminAuthService over time. During the
 * migration window, AdminAuthService::validateSession() is kept as a
 * thin shim for any code that still reads $_SESSION['admin_user_id'].
 */
class UserAuthService {
    private $repo;
    private $context;
    private $db;

    // Token settings
    const REMEMBER_TTL_DAYS = 30;
    const PASSWORD_RESET_TTL_HOURS = 2;
    const EMAIL_VERIFY_TTL_DAYS = 7;

    public function __construct(?UserRepository $repo = null, ?UserContext $context = null) {
        $this->repo = $repo ?: new UserRepository();
        $this->context = $context ?: new UserContext();
        $this->db = Database::getInstance();
    }

    // =====================================================
    // CURRENT USER
    // =====================================================

    /**
     * Return the currently-authenticated account user (not a guest),
     * or null if nobody is logged in. Used by ApiController::currentUser().
     */
    public function currentUser(): ?array {
        $user = $this->context->current();
        if (!$user || !empty($user['is_guest'])) {
            return null;
        }
        // Strip secrets before returning
        unset($user['password_hash']);
        return $user;
    }

    // =====================================================
    // REGISTRATION
    // =====================================================

    /**
     * Register a new account.
     *
     * @param array $data ['username', 'email', 'password', 'display_name'?]
     * @return array ['user' => [...], 'errors' => []]
     */
    public function register(array $data): array {
        $username = trim($data['username'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $displayName = trim($data['display_name'] ?? '') ?: $username;

        $errors = $this->validateRegistration($username, $email, $password);
        if ($errors) {
            return ['user' => null, 'errors' => $errors];
        }

        // First account becomes an admin (bootstrap), everyone else is 'viewer'.
        $existing = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE is_guest = 0");
        $role = $existing === 0 ? 'admin' : 'viewer';

        $userId = $this->repo->createAccount([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName,
            'role' => $role,
        ]);

        $user = $this->repo->findById($userId);
        unset($user['password_hash']);

        return ['user' => $user, 'errors' => []];
    }

    /**
     * Validation rules for registration. Returns an array of field → message.
     */
    public function validateRegistration(string $username, string $email, string $password): array {
        $errors = [];

        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
            $errors['username'] = 'Username must be 3–50 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors['username'] = 'Username may only contain letters, numbers, _, ., and -';
        } elseif ($this->repo->findByUsername($username)) {
            $errors['username'] = 'Username is already taken';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        } elseif ($this->repo->findByEmail($email)) {
            $errors['email'] = 'An account with this email already exists';
        }

        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        return $errors;
    }

    // =====================================================
    // LOGIN / LOGOUT
    // =====================================================

    /**
     * Attempt to log in with username-or-email + password.
     * On success, starts a session (and a remember-me cookie if requested)
     * and returns the user. On failure, returns null.
     */
    public function login(string $identifier, string $password, bool $remember = false): ?array {
        $user = $this->repo->findByIdentifier($identifier);
        if (!$user || empty($user['password_hash'])) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Regenerate session id to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];

        if ($remember) {
            $this->issueRememberToken((int)$user['id']);
        }

        $this->repo->updateLastSeen((int)$user['id']);
        $this->context->refresh();

        unset($user['password_hash']);
        return $user;
    }

    public function logout(): void {
        // Kill remember cookie + token
        if (!empty($_COOKIE[UserContext::REMEMBER_COOKIE])) {
            $hash = hash('sha256', $_COOKIE[UserContext::REMEMBER_COOKIE]);
            $this->db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            setcookie(UserContext::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
        }

        unset($_SESSION['user_id'], $_SESSION['user_role']);
        session_regenerate_id(true);
        $this->context->refresh();
    }

    // =====================================================
    // GUEST MERGE
    // =====================================================

    /**
     * Move a guest user's bookmarks, history, and search history
     * into an account user, then delete the guest row.
     */
    public function mergeGuest(int $guestId, int $accountId): void {
        $this->repo->mergeGuestInto($guestId, $accountId);
        $this->context->refresh();
    }

    // =====================================================
    // PASSWORD
    // =====================================================

    /**
     * Change password for the currently-authenticated user.
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool {
        $user = $this->db->fetchOne(
            "SELECT password_hash FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return false;
        }
        if (strlen($newPassword) < 8) {
            return false;
        }
        $this->repo->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        return true;
    }

    /**
     * Start a password reset — issues a one-time token. Caller is
     * responsible for emailing it via MailService.
     * Returns the raw token (not stored — only the hash is).
     */
    public function startPasswordReset(string $email): ?array {
        $user = $this->repo->findByEmail($email);
        if (!$user) return null;

        $rawToken = bin2hex(random_bytes(32));
        $this->storeToken((int)$user['id'], $rawToken, 'password_reset',
            '+' . self::PASSWORD_RESET_TTL_HOURS . ' hours');

        return ['user' => $user, 'token' => $rawToken];
    }

    /**
     * Complete a password reset given the raw token and new password.
     */
    public function completePasswordReset(string $rawToken, string $newPassword): bool {
        if (strlen($newPassword) < 8) return false;

        $hash = hash('sha256', $rawToken);
        $token = $this->db->fetchOne(
            "SELECT id, user_id, expires_at, used_at FROM user_auth_tokens
             WHERE token_hash = ? AND purpose = 'password_reset'",
            [$hash]
        );
        if (!$token || $token['used_at'] !== null || strtotime($token['expires_at']) < time()) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $this->repo->updatePassword((int)$token['user_id'],
                password_hash($newPassword, PASSWORD_DEFAULT));
            $this->db->query(
                "UPDATE user_auth_tokens SET used_at = NOW() WHERE id = ?",
                [$token['id']]
            );
            // Invalidate every other reset + remember token for this user
            $this->db->delete('user_auth_tokens',
                "user_id = ? AND purpose IN ('password_reset','remember') AND id != ?",
                [(int)$token['user_id'], (int)$token['id']]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('[UserAuthService::completePasswordReset] ' . $e->getMessage());
            return false;
        }
    }

    // =====================================================
    // EMAIL VERIFICATION
    // =====================================================

    public function startEmailVerification(int $userId): string {
        $rawToken = bin2hex(random_bytes(32));
        $this->storeToken($userId, $rawToken, 'email_verify',
            '+' . self::EMAIL_VERIFY_TTL_DAYS . ' days');
        return $rawToken;
    }

    public function completeEmailVerification(string $rawToken): bool {
        $hash = hash('sha256', $rawToken);
        $token = $this->db->fetchOne(
            "SELECT id, user_id, expires_at, used_at FROM user_auth_tokens
             WHERE token_hash = ? AND purpose = 'email_verify'",
            [$hash]
        );
        if (!$token || $token['used_at'] !== null || strtotime($token['expires_at']) < time()) {
            return false;
        }

        $this->repo->setEmailVerified((int)$token['user_id']);
        $this->db->query(
            "UPDATE user_auth_tokens SET used_at = NOW() WHERE id = ?",
            [$token['id']]
        );
        return true;
    }

    // =====================================================
    // TOKEN STORAGE HELPERS
    // =====================================================

    private function storeToken(int $userId, string $rawToken, string $purpose, string $expires): void {
        $this->db->insert('user_auth_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'purpose' => $purpose,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'expires_at' => date('Y-m-d H:i:s', strtotime($expires)),
        ]);
    }

    private function issueRememberToken(int $userId): void {
        $rawToken = bin2hex(random_bytes(32));
        $this->storeToken($userId, $rawToken, 'remember',
            '+' . self::REMEMBER_TTL_DAYS . ' days');

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(
            UserContext::REMEMBER_COOKIE,
            $rawToken,
            [
                'expires' => time() + (self::REMEMBER_TTL_DAYS * 86400),
                'path' => app_cookie_path(),
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
