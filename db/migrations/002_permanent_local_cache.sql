-- Archive Film Club - Permanent Local Cache Migration
-- Version: 2.0.0
-- Purpose: Enable permanent local storage of metadata and thumbnails
--          to minimize Archive.org API usage

-- =====================================================
-- EXTEND METADATA CACHE FOR PERMANENT STORAGE
-- =====================================================

-- Add columns for permanent storage and raw data
ALTER TABLE video_metadata_cache
    ADD COLUMN IF NOT EXISTS is_permanent TINYINT(1) DEFAULT 1 COMMENT 'Keep this data permanently',
    ADD COLUMN IF NOT EXISTS is_stale TINYINT(1) DEFAULT 0 COMMENT 'Mark for background refresh',
    ADD COLUMN IF NOT EXISTS raw_metadata_json LONGTEXT COMMENT 'Full Archive.org metadata response',
    ADD COLUMN IF NOT EXISTS last_refreshed TIMESTAMP NULL COMMENT 'When data was last refreshed from API',
    ADD COLUMN IF NOT EXISTS refresh_count INT DEFAULT 0 COMMENT 'Number of times refreshed',
    ADD COLUMN IF NOT EXISTS collection_json TEXT COMMENT 'Collections this item belongs to',
    ADD COLUMN IF NOT EXISTS thumbnail_cached TINYINT(1) DEFAULT 0 COMMENT 'Whether thumbnail is cached locally';

-- Add index for stale items (for background refresh)
CREATE INDEX IF NOT EXISTS idx_stale ON video_metadata_cache (is_stale, last_refreshed);

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

-- Add columns for permanent search caching
ALTER TABLE search_cache
    ADD COLUMN IF NOT EXISTS is_permanent TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_stale TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_refreshed TIMESTAMP NULL;

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
