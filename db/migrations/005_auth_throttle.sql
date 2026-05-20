-- =====================================================
-- Migration 005: Auth throttling + token rate limit table
-- =====================================================
--
-- Tracks failed login attempts (by IP and username) so UserAuthService
-- can rate-limit credential-stuffing / password-spray attacks against
-- /api/auth/login.php and the admin login form. Without this table the
-- service falls back to no throttling, so this migration is non-critical
-- for app function -- but strongly recommended for production.

CREATE TABLE IF NOT EXISTS auth_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_hash VARCHAR(64) NOT NULL,
    identifier VARCHAR(255) NULL COMMENT 'Lowercased username or email if known',
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_recent (ip_hash, created_at),
    INDEX idx_identifier_recent (identifier, created_at),
    INDEX idx_cleanup (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
