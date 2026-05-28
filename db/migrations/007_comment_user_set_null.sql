-- =====================================================
-- Migration 007: video_comments.user_id ON DELETE SET NULL
-- =====================================================
--
-- Existing installs created video_comments.user_id as NOT NULL with an
-- ON DELETE CASCADE foreign key (original migration 006). Deleting a user then
-- cascaded away ALL of their comments and — because replies cascade on
-- parent_id — could wipe OTHER users' replies under those threads, destroying
-- conversation history the soft-delete design meant to preserve.
--
-- This converts the column to nullable and the FK to ON DELETE SET NULL, so a
-- deleted account's comments are kept (and rendered as "[deleted]") instead of
-- vanishing and taking other people's replies with them. Fresh installs get
-- this directly from the updated migration 006; this migration brings EXISTING
-- installs in line.
--
-- One ADD/MODIFY per statement (the installer runs each `;`-separated statement
-- independently and swallows "already exists / can't DROP / duplicate" errors).
--
-- The original FK was unnamed, so MySQL auto-named it. For this table the first
-- foreign key defined is user_id, which MySQL conventionally names
-- `video_comments_ibfk_1`. If a given server named it differently (some MariaDB
-- versions), the DROP below fails that ONE statement (swallowed) without
-- aborting the migration — on such a host, verify the constraint afterward with
-- `SHOW CREATE TABLE video_comments` and drop/re-add by the real name.
--
-- Safe to run on an existing database. Re-runnable (a second run drops nothing
-- and the duplicate ADD is swallowed).
-- =====================================================

-- 1. Allow NULL (required before a FK can SET NULL on it).
ALTER TABLE video_comments MODIFY user_id INT NULL;

-- 2. Drop the original CASCADE foreign key (auto-named on the first run).
ALTER TABLE video_comments DROP FOREIGN KEY video_comments_ibfk_1;

-- 3. Re-add it, named, as ON DELETE SET NULL. Matches the name migration 006
--    now uses on fresh installs, so this is idempotent across both paths.
ALTER TABLE video_comments
    ADD CONSTRAINT fk_video_comments_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
