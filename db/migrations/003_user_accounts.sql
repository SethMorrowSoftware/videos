-- =====================================================
-- Migration 003: Unified User Accounts
-- =====================================================
--
-- Goals:
--   1. Turn the session-only `users` table into a real identity layer
--      without breaking existing anonymous sessions.
--   2. Merge `admin_users` into `users` so the app has one identity
--      table, with a `role` column distinguishing admin / editor /
--      viewer / guest.
--   3. Add a `user_auth_tokens` table for remember-me, password reset,
--      and email verification tokens.
--
-- Backward compatibility:
--   - All existing rows in `users` become guests (is_guest = 1).
--   - Existing admin_users rows are copied into `users` with role
--     preserved.
--   - All foreign keys already pointing at users(id) keep working.
--   - Keys left on admin_users so old code reading from it still works
--     until Phase 2 code migration completes. The table is dropped in
--     migration 005 (post-switchover).
--
-- Safe to run on an existing database. Re-runnable.
-- =====================================================

-- --------------------------------------------------
-- 1. Extend `users` with account fields
-- --------------------------------------------------

ALTER TABLE users
    ADD COLUMN username VARCHAR(50) NULL AFTER id,
    ADD COLUMN email VARCHAR(255) NULL AFTER username,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER email,
    ADD COLUMN display_name VARCHAR(100) NULL AFTER password_hash,
    ADD COLUMN avatar_url VARCHAR(500) NULL AFTER display_name,
    ADD COLUMN role ENUM('guest','viewer','editor','admin') NOT NULL DEFAULT 'guest' AFTER avatar_url,
    ADD COLUMN is_guest TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN email_verified_at TIMESTAMP NULL,
    ADD UNIQUE KEY uk_username (username),
    ADD UNIQUE KEY uk_email (email),
    ADD INDEX idx_role (role);

-- --------------------------------------------------
-- 2. Copy any existing admin_users rows into `users`
--    (only if admin_users exists — it should from migration 001)
-- --------------------------------------------------

-- Use INSERT ... SELECT so we preserve username/password/email/role,
-- and set is_guest=0, role from admin_users.role. Skip rows that would
-- collide with an existing username.

INSERT INTO users (
    username,
    email,
    password_hash,
    display_name,
    role,
    is_guest,
    session_id,
    preferences,
    created_at,
    last_seen
)
SELECT
    au.username,
    au.email,
    au.password_hash,
    au.username AS display_name,
    au.role,
    0 AS is_guest,
    NULL AS session_id,
    JSON_OBJECT() AS preferences,
    au.created_at,
    COALESCE(au.last_login, au.created_at) AS last_seen
FROM admin_users au
LEFT JOIN users u ON u.username = au.username
WHERE u.id IS NULL;

-- --------------------------------------------------
-- 3. Auth tokens (remember-me, password reset, email verify)
-- --------------------------------------------------

CREATE TABLE IF NOT EXISTS user_auth_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    purpose ENUM('remember','password_reset','email_verify') NOT NULL,
    user_agent VARCHAR(500) NULL,
    ip_hash VARCHAR(64) NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    UNIQUE KEY uk_token_hash (token_hash),
    INDEX idx_user_purpose (user_id, purpose),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------
-- 4. Prime any pre-existing guest rows with role='guest'
--    (ALTER already defaults to 'guest' but this is defensive)
-- --------------------------------------------------

UPDATE users SET role = 'guest', is_guest = 1
WHERE role IS NULL OR (password_hash IS NULL AND username IS NULL);
