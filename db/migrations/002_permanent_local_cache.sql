-- Archive Film Club - Permanent Local Cache Migration
-- Version: 2.0.1
-- Purpose: Enable permanent local storage of metadata and thumbnails
--          to minimize Archive.org API usage
--
-- Compatibility note (2.0.1): "ADD COLUMN IF NOT EXISTS" and
-- "CREATE INDEX IF NOT EXISTS" are MariaDB-only -- MySQL 5.7 and 8.x
-- both reject them with a 1064 syntax error. install.php's outer loop
-- catches "Duplicate column" / "Duplicate key name" errors so the bare
-- ALTERs below are safe to re-run on either engine.

-- =====================================================
-- EXTEND METADATA CACHE FOR PERMANENT STORAGE
-- =====================================================

-- One column per ALTER so a duplicate-column error on one doesn't abort
-- the rest. install.php swallows the duplicate-column errors as it walks
-- each statement (see the catch block around `$db->query($statement)`).

ALTER TABLE video_metadata_cache
    ADD COLUMN is_permanent TINYINT(1) DEFAULT 1 COMMENT 'Keep this data permanently';
ALTER TABLE video_metadata_cache
    ADD COLUMN is_stale TINYINT(1) DEFAULT 0 COMMENT 'Mark for background refresh';
ALTER TABLE video_metadata_cache
    ADD COLUMN raw_metadata_json LONGTEXT COMMENT 'Full Archive.org metadata response';
ALTER TABLE video_metadata_cache
    ADD COLUMN last_refreshed TIMESTAMP NULL COMMENT 'When data was last refreshed from API';
ALTER TABLE video_metadata_cache
    ADD COLUMN refresh_count INT DEFAULT 0 COMMENT 'Number of times refreshed';
ALTER TABLE video_metadata_cache
    ADD COLUMN collection_json TEXT COMMENT 'Collections this item belongs to';
ALTER TABLE video_metadata_cache
    ADD COLUMN thumbnail_cached TINYINT(1) DEFAULT 0 COMMENT 'Whether thumbnail is cached locally';
-- Note: this column was already declared in migration 001. On an install
-- that ran 001 first, the ALTER above will throw "Duplicate column" and
-- install.php's outer loop swallows that specific error.

-- Add index for stale items (for background refresh)
CREATE INDEX idx_stale ON video_metadata_cache (is_stale, last_refreshed);

-- Make expires_at nullable (for permanent storage)
ALTER TABLE video_metadata_cache MODIFY COLUMN expires_at TIMESTAMP NULL;

-- =====================================================
-- COLLECTION METADATA CACHE
-- =====================================================

CREATE TABLE IF NOT EXISTS collection_metadata_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    collection_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    description TEXT,
    creator VARCHAR(255),
    item_count INT DEFAULT 0,
    thumbnail_url VARCHAR(500),
    thumbnail_cached TINYINT(1) DEFAULT 0,
    raw_metadata_json LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_refreshed TIMESTAMP NULL,
    is_stale TINYINT(1) DEFAULT 0,
    UNIQUE KEY uk_collection_id (collection_id),
    INDEX idx_stale (is_stale, last_refreshed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CACHE QUEUE FOR BACKGROUND PROCESSING
-- =====================================================

CREATE TABLE IF NOT EXISTS cache_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    cache_type ENUM('metadata', 'thumbnail', 'collection') NOT NULL,
    priority INT DEFAULT 5 COMMENT '1=highest, 10=lowest',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    UNIQUE KEY uk_item_type (archive_id, cache_type),
    INDEX idx_pending (status, priority, created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEARCH RESULTS CACHE IMPROVEMENTS
-- =====================================================

-- Add columns for permanent search caching (one statement each so a
-- duplicate-column error on one doesn't abort the whole migration).
ALTER TABLE search_cache
    ADD COLUMN is_permanent TINYINT(1) DEFAULT 0;
ALTER TABLE search_cache
    ADD COLUMN is_stale TINYINT(1) DEFAULT 0;
ALTER TABLE search_cache
    ADD COLUMN last_refreshed TIMESTAMP NULL;

-- Extend search cache TTL (make expires_at nullable)
ALTER TABLE search_cache MODIFY COLUMN expires_at TIMESTAMP NULL;

-- =====================================================
-- CACHED ITEMS REGISTRY (tracks all locally cached items)
-- =====================================================

CREATE TABLE IF NOT EXISTS cached_items_registry (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    has_metadata TINYINT(1) DEFAULT 0,
    has_thumbnail TINYINT(1) DEFAULT 0,
    has_files_list TINYINT(1) DEFAULT 0,
    total_size_bytes BIGINT DEFAULT 0,
    first_cached TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    access_count INT DEFAULT 0,
    source VARCHAR(50) DEFAULT 'user_browse' COMMENT 'How this item was discovered',
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_last_accessed (last_accessed),
    INDEX idx_access_count (access_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CACHE STATISTICS TABLE
-- =====================================================

CREATE TABLE IF NOT EXISTS cache_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    stat_date DATE NOT NULL,
    metadata_cached INT DEFAULT 0,
    thumbnails_cached INT DEFAULT 0,
    collections_cached INT DEFAULT 0,
    total_storage_bytes BIGINT DEFAULT 0,
    api_calls_saved INT DEFAULT 0,
    cache_hit_count INT DEFAULT 0,
    cache_miss_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_stat_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- UPDATE DEFAULT SETTINGS
-- =====================================================

INSERT INTO site_settings (setting_key, setting_value, setting_type) VALUES
('enableLocalCaching', '1', 'boolean'),
('cacheMetadataPermanently', '1', 'boolean'),
('cacheThumbnailsOnView', '1', 'boolean'),
('backgroundCacheEnabled', '1', 'boolean'),
('maxThumbnailCacheMB', '500', 'number'),
('refreshStaleAfterDays', '30', 'number')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
