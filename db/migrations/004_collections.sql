-- =====================================================
-- Migration 004: User Collections (shareable playlists)
-- =====================================================
--
-- Lets any authenticated user curate their own themed lists of videos,
-- with optional public visibility (shareable via slug URL).
--
-- Tables:
--   user_collections       — one row per collection (owner, name, slug,
--                            description, is_public, cover art, counts)
--   user_collection_items  — join rows: videos in each collection, with
--                            ordering and optional note
--
-- Design notes:
--   - Slugs are unique per-owner, not globally, so different users can
--     each have a "favorites" collection. Public URLs therefore need
--     the owner's username in the path: /c/{username}/{slug}.
--   - Cascading deletes: deleting a user deletes their collections and
--     items, and deleting a collection deletes its items.
--   - Item count + video count are denormalized on the collection row
--     for cheap list rendering, kept in sync by the service layer.
--
-- Safe to re-run (IF NOT EXISTS on everything).
-- =====================================================

CREATE TABLE IF NOT EXISTS user_collections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NULL,
    cover_thumbnail VARCHAR(500) NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    item_count INT NOT NULL DEFAULT 0,
    view_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_slug (user_id, slug),
    INDEX idx_user_created (user_id, created_at DESC),
    INDEX idx_public_updated (is_public, updated_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_collection_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    collection_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500) NULL,
    creator VARCHAR(255) NULL,
    thumbnail_url VARCHAR(500) NULL,
    note TEXT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_collection_archive (collection_id, archive_id),
    INDEX idx_collection_position (collection_id, position),
    FOREIGN KEY (collection_id) REFERENCES user_collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
