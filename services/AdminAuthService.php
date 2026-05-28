<?php
/**
 * Admin Authentication Service
 *
 * Handles admin user authentication with secure password hashing
 */

require_once __DIR__ . '/../db/Database.php';

class AdminAuthService {
    // Brute-force throttle for the legacy admin login path. Mirrors
    // UserAuthService's per-IP limit and shares the same auth_attempts table.
    const THROTTLE_WINDOW_MINUTES = 10;
    const THROTTLE_MAX_PER_IP = 20;

    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Authenticate admin user.
     *
     * After migration 003 the canonical store is `users` (role = admin|editor).
     * We check that first and fall back to legacy `admin_users` for sites that
     * haven't run the migration yet.
     */
    public function authenticate(string $username, string $password): ?array {
        // Throttle this legacy path through the same per-IP limit and
        // auth_attempts table that UserAuthService::login uses, so it isn't an
        // unthrottled brute-force channel. Fails open if the table isn't
        // present (migration 005 not run).
        if ($this->isThrottled()) {
            throw new RuntimeException('Too many sign-in attempts. Please wait a few minutes and try again.');
        }

        $user = $this->verifyCredentials($username, $password);
        $this->recordAttempt($username, $user !== null);
        return $user;
    }

    /**
     * Verify admin credentials against the unified `users` table, falling back
     * to the legacy `admin_users` table. Returns the user row (without the
     * password hash) on success, null on failure.
     */
    private function verifyCredentials(string $username, string $password): ?array {
        // New unified users table
        $user = $this->db->fetchOne(
            "SELECT id, username, email, password_hash, role
             FROM users
             WHERE username = ? AND is_guest = 0 AND role IN ('admin','editor')",
            [$username]
        );

        // Legacy admin_users fallback (pre-migration)
        if (!$user) {
            $user = $this->db->fetchOne(
                "SELECT id, username, password_hash, role FROM admin_users WHERE username = ?",
                [$username]
            );
            if ($user && password_verify($password, $user['password_hash'])) {
                $this->db->query(
                    "UPDATE admin_users SET last_login = NOW() WHERE id = ?",
                    [$user['id']]
                );
                unset($user['password_hash']);
                return $user;
            }
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        $this->db->query("UPDATE users SET last_seen = NOW() WHERE id = ?", [$user['id']]);
        unset($user['password_hash']);
        return $user;
    }

    /**
     * Per-IP brute-force check against the shared auth_attempts table. Returns
     * false (allow) if the table is missing so admin login still works before
     * migration 005 is run.
     */
    private function isThrottled(): bool {
        try {
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $count = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE ip_hash = ? AND success = 0
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$ipHash, self::THROTTLE_WINDOW_MINUTES]
            );
            return $count >= self::THROTTLE_MAX_PER_IP;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Record one admin login attempt for throttling/audit. Best-effort — a
     * write failure must not block login.
     */
    private function recordAttempt(string $username, bool $success): void {
        try {
            $this->db->insert('auth_attempts', [
                'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                'identifier' => mb_substr(mb_strtolower(trim($username)), 0, 255),
                'success' => $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            // auth_attempts missing (migration 005 not run) or DB unavailable.
        }
    }

    /**
     * Create admin user
     */
    public function createUser(string $username, string $password, string $email = null, string $role = 'admin'): ?int {
        // Check if username exists
        $existing = $this->db->fetchOne(
            "SELECT id FROM admin_users WHERE username = ?",
            [$username]
        );

        if ($existing) {
            return null;
        }

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        return $this->db->insert('admin_users', [
            'username' => $username,
            'password_hash' => $passwordHash,
            'email' => $email,
            'role' => $role,
        ]);
    }

    /**
     * Update admin password
     */
    public function updatePassword(int $userId, string $newPassword): bool {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->query(
            "UPDATE admin_users SET password_hash = ? WHERE id = ?",
            [$passwordHash, $userId]
        );

        return true;
    }

    /**
     * Check if any admin users exist
     */
    public function hasAdminUsers(): bool {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM admin_users");
        return $count > 0;
    }

    /**
     * Get admin user by ID
     */
    public function getUser(int $id): ?array {
        $user = $this->db->fetchOne(
            "SELECT id, username, email, role, created_at, last_login FROM admin_users WHERE id = ?",
            [$id]
        );

        return $user ?: null;
    }

    /**
     * Get all admin users
     */
    public function getAllUsers(): array {
        return $this->db->fetchAll(
            "SELECT id, username, email, role, created_at, last_login FROM admin_users ORDER BY username"
        );
    }

    /**
     * Delete admin user
     */
    public function deleteUser(int $id): bool {
        // Don't delete last admin
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM admin_users WHERE role = 'admin'");
        if ($count <= 1) {
            $user = $this->getUser($id);
            if ($user && $user['role'] === 'admin') {
                return false;
            }
        }

        $this->db->delete('admin_users', 'id = ?', [$id]);
        return true;
    }

    /**
     * Validate session — recognizes both the legacy admin session
     * ($_SESSION['admin_user_id']) and the new unified session
     * ($_SESSION['user_id']) set by UserAuthService::login().
     *
     * Returns the user row (without password hash) if the caller is an
     * admin or editor, null otherwise.
     */
    public function validateSession(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // New unified session (Phase 2+)
        if (!empty($_SESSION['user_id'])) {
            $user = $this->db->fetchOne(
                "SELECT id, username, email, role FROM users
                 WHERE id = ? AND is_guest = 0 AND role IN ('admin','editor')",
                [(int)$_SESSION['user_id']]
            );
            if ($user) return $user;
        }

        // Legacy admin session
        if (!empty($_SESSION['admin_user_id'])) {
            return $this->getUser((int)$_SESSION['admin_user_id']);
        }

        return null;
    }

    /**
     * Start admin session.
     *
     * Sets both the new unified session keys ($_SESSION['user_id'])
     * and the legacy admin-only keys so callers that haven't been
     * migrated yet continue to work.
     */
    public function startSession(array $user): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];

        // Legacy keys (kept for admin.php backward compat)
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['admin_logged_in'] = true;
    }

    /**
     * End admin session (clears both legacy and new session keys,
     * plus any remember-me cookie).
     */
    public function endSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Kill any remember-me cookie + token
        if (!empty($_COOKIE['afc_remember'])) {
            $hash = hash('sha256', $_COOKIE['afc_remember']);
            try {
                $this->db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            } catch (Throwable $e) { /* table may not exist yet */ }
            $path = function_exists('app_cookie_path') ? app_cookie_path() : '/';
            setcookie('afc_remember', '', time() - 3600, $path);
        }

        unset(
            $_SESSION['user_id'],
            $_SESSION['user_role'],
            $_SESSION['admin_user_id'],
            $_SESSION['admin_username'],
            $_SESSION['admin_role'],
            $_SESSION['admin_logged_in']
        );

        session_destroy();
    }
}
