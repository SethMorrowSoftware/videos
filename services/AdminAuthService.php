<?php
/**
 * Admin Authentication Service
 *
 * Handles admin user authentication with secure password hashing
 */

require_once __DIR__ . '/../db/Database.php';

class AdminAuthService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Authenticate admin user
     */
    public function authenticate(string $username, string $password): ?array {
        $user = $this->db->fetchOne(
            "SELECT id, username, password_hash, role FROM admin_users WHERE username = ?",
            [$username]
        );

        if (!$user) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Update last login
        $this->db->query(
            "UPDATE admin_users SET last_login = NOW() WHERE id = ?",
            [$user['id']]
        );

        // Don't return password hash
        unset($user['password_hash']);
        return $user;
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
     * Validate session
     */
    public function validateSession(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['admin_user_id'])) {
            return null;
        }

        return $this->getUser($_SESSION['admin_user_id']);
    }

    /**
     * Start admin session
     */
    public function startSession(array $user): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['admin_logged_in'] = true;
    }

    /**
     * End admin session
     */
    public function endSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION['admin_user_id']);
        unset($_SESSION['admin_username']);
        unset($_SESSION['admin_role']);
        unset($_SESSION['admin_logged_in']);

        session_destroy();
    }
}
