-- Archive Film Club - MySQL Schema Migration
-- Version: 1.0.0
-- Compatible with MySQL 5.7+ and MySQL 8.0+

-- =====================================================
-- CORE CONFIGURATION TABLES
-- =====================================================

-- Site settings key-value storage
CREATE TABLE IF NOT EXISTS site_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'boolean', 'number', 'json') DEFAULT 'string',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin users table with secure password storage
CREATE TABLE IF NOT EXISTS admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    role ENUM('admin', 'editor') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FEATURED CONTENT TABLES
-- =====================================================

-- Staff recommended videos
CREATE TABLE IF NOT EXISTS recommended_videos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    creator VARCHAR(255),
    description TEXT,
    thumbnail_url VARCHAR(500),
    display_order INT DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    added_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_enabled_order (enabled, display_order),
    FOREIGN KEY (added_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recommendations settings
CREATE TABLE IF NOT EXISTS recommendations_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enabled TINYINT(1) DEFAULT 1,
    title VARCHAR(255) DEFAULT 'Staff Picks',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Featured sections (custom categories)
CREATE TABLE IF NOT EXISTS featured_sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    section_id VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    enabled TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled_order (enabled, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Videos within featured sections
CREATE TABLE IF NOT EXISTS featured_section_videos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    section_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    creator VARCHAR(255),
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_section_video (section_id, archive_id),
    INDEX idx_section_order (section_id, display_order),
    FOREIGN KEY (section_id) REFERENCES featured_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CACHING TABLES
-- =====================================================

-- Search results cache
CREATE TABLE IF NOT EXISTS search_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cache_key VARCHAR(64) NOT NULL,
    query_params JSON NOT NULL,
    response_data LONGTEXT NOT NULL,
    result_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    hit_count INT DEFAULT 0,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cache_key (cache_key),
    INDEX idx_expires (expires_at),
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Video metadata cache
CREATE TABLE IF NOT EXISTS video_metadata_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    description TEXT,
    creator VARCHAR(255),
    date VARCHAR(100),
    runtime VARCHAR(50),
    mediatype VARCHAR(50),
    downloads INT DEFAULT 0,
    license_url VARCHAR(500),
    subject TEXT,
    files_json LONGTEXT,
    thumbnail_cached TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_expires (expires_at),
    INDEX idx_downloads (downloads DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thumbnail cache tracking
CREATE TABLE IF NOT EXISTS thumbnail_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    original_url VARCHAR(500),
    local_path VARCHAR(500),
    file_size INT,
    width INT,
    height INT,
    mime_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    access_count INT DEFAULT 0,
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- USER DATA TABLES (for persistent user features)
-- =====================================================

-- Anonymous users (session-based)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(64) UNIQUE,
    user_agent VARCHAR(500),
    ip_hash VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    preferences JSON,
    INDEX idx_session (session_id),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User bookmarks
CREATE TABLE IF NOT EXISTS user_bookmarks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    creator VARCHAR(255),
    thumbnail_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_bookmark (user_id, archive_id),
    INDEX idx_user_created (user_id, created_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User watch history
CREATE TABLE IF NOT EXISTS user_watch_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    current_time DECIMAL(10,2) DEFAULT 0,
    duration DECIMAL(10,2) DEFAULT 0,
    progress_percent DECIMAL(5,2) DEFAULT 0,
    last_watched TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    watch_count INT DEFAULT 1,
    UNIQUE KEY uk_user_video (user_id, archive_id),
    INDEX idx_user_recent (user_id, last_watched DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User search history
CREATE TABLE IF NOT EXISTS search_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    query VARCHAR(500) NOT NULL,
    filters JSON,
    result_count INT,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_recent (user_id, searched_at DESC),
    INDEX idx_query (query(100)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ANALYTICS TABLES
-- =====================================================

-- Popular searches tracking
CREATE TABLE IF NOT EXISTS popular_searches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    query VARCHAR(500) NOT NULL,
    query_hash VARCHAR(64) NOT NULL,
    search_count INT DEFAULT 1,
    last_searched TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_query_hash (query_hash),
    INDEX idx_count (search_count DESC),
    INDEX idx_recent (last_searched DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API usage logging
CREATE TABLE IF NOT EXISTS api_usage_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    endpoint VARCHAR(100),
    cache_hit TINYINT(1) DEFAULT 0,
    response_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_endpoint (endpoint, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERT DEFAULT DATA
-- =====================================================

-- Insert default recommendations settings
INSERT INTO recommendations_settings (enabled, title) VALUES (1, 'Staff Picks')
ON DUPLICATE KEY UPDATE title = title;

-- Insert default site settings
INSERT INTO site_settings (setting_key, setting_value, setting_type) VALUES
('siteName', 'Archive Film Club', 'string'),
('tagline', 'Discover classic films from Archive.org', 'string'),
('brandColor', '#ff0000', 'string'),
('accentColor', '#065fd4', 'string'),
('defaultTheme', 'dark', 'string'),
('enableThemeToggle', '1', 'boolean'),
('cardStyle', 'modern', 'string'),
('showDownloadCount', '1', 'boolean'),
('showCreator', '1', 'boolean'),
('showDate', '1', 'boolean'),
('enableBookmarks', '1', 'boolean'),
('enableWatchHistory', '1', 'boolean'),
('defaultCollection', 'all_videos', 'string'),
('defaultSort', 'downloads', 'string')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
