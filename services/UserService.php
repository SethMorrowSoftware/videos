<?php
/**
 * User Service
 *
 * Handles anonymous user sessions, bookmarks, and watch history
 */

require_once __DIR__ . '/../db/Database.php';

class UserService {
    private $db;
    private $userId = null;
    private $sessionId = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =====================================================
    // SESSION MANAGEMENT
    // =====================================================

    /**
     * Get or create user from session
     */
    public function getOrCreateUser(): int {
        if ($this->userId !== null) {
            return $this->userId;
        }

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->sessionId = session_id();

        // Try to find existing user
        $user = $this->db->fetchOne(
            "SELECT id FROM users WHERE session_id = ?",
            [$this->sessionId]
        );

        if ($user) {
            $this->userId = $user['id'];

            // Update last seen
            $this->db->query(
                "UPDATE users SET last_seen = NOW() WHERE id = ?",
                [$this->userId]
            );
        } else {
            // Create new user
            $this->userId = $this->createUser();
        }

        return $this->userId;
    }

    /**
     * Create a new anonymous user
     */
    private function createUser(): int {
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return $this->db->insert('users', [
            'session_id' => $this->sessionId,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            'ip_hash' => $ipHash,
            'preferences' => json_encode([]),
        ]);
    }

    /**
     * Get user ID (without creating)
     */
    public function getUserId(): ?int {
        if ($this->userId !== null) {
            return $this->userId;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $this->db->fetchOne(
            "SELECT id FROM users WHERE session_id = ?",
            [session_id()]
        );

        return $user ? $user['id'] : null;
    }

    // =====================================================
    // PREFERENCES
    // =====================================================

    /**
     * Get user preferences
     */
    public function getPreferences(): array {
        $userId = $this->getUserId();
        if (!$userId) {
            return [];
        }

        $user = $this->db->fetchOne(
            "SELECT preferences FROM users WHERE id = ?",
            [$userId]
        );

        return $user ? (json_decode($user['preferences'], true) ?? []) : [];
    }

    /**
     * Update user preferences
     */
    public function setPreferences(array $prefs): bool {
        $userId = $this->getOrCreateUser();

        $this->db->query(
            "UPDATE users SET preferences = ? WHERE id = ?",
            [json_encode($prefs), $userId]
        );

        return true;
    }

    /**
     * Update a single preference
     */
    public function setPreference(string $key, $value): bool {
        $prefs = $this->getPreferences();
        $prefs[$key] = $value;
        return $this->setPreferences($prefs);
    }

    // =====================================================
    // BOOKMARKS
    // =====================================================

    /**
     * Get user bookmarks
     */
    public function getBookmarks(): array {
        $userId = $this->getUserId();
        if (!$userId) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT archive_id as id, title, creator, thumbnail_url as thumbnail, created_at
             FROM user_bookmarks
             WHERE user_id = ?
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    /**
     * Add a bookmark
     */
    public function addBookmark(string $archiveId, array $metadata = []): bool {
        $userId = $this->getOrCreateUser();

        try {
            $this->db->insert('user_bookmarks', [
                'user_id' => $userId,
                'archive_id' => $archiveId,
                'title' => $metadata['title'] ?? null,
                'creator' => $metadata['creator'] ?? null,
                'thumbnail_url' => $metadata['thumbnail'] ?? null,
            ]);
            return true;
        } catch (Exception $e) {
            // Might be duplicate
            return false;
        }
    }

    /**
     * Remove a bookmark
     */
    public function removeBookmark(string $archiveId): bool {
        $userId = $this->getUserId();
        if (!$userId) {
            return false;
        }

        $this->db->delete('user_bookmarks', 'user_id = ? AND archive_id = ?', [$userId, $archiveId]);
        return true;
    }

    /**
     * Check if video is bookmarked
     */
    public function isBookmarked(string $archiveId): bool {
        $userId = $this->getUserId();
        if (!$userId) {
            return false;
        }

        $result = $this->db->fetchOne(
            "SELECT id FROM user_bookmarks WHERE user_id = ? AND archive_id = ?",
            [$userId, $archiveId]
        );

        return $result !== null;
    }

    /**
     * Sync bookmarks (replace all)
     */
    public function syncBookmarks(array $bookmarks): bool {
        $userId = $this->getOrCreateUser();

        $this->db->beginTransaction();
        try {
            // Clear existing
            $this->db->delete('user_bookmarks', 'user_id = ?', [$userId]);

            // Add new bookmarks
            foreach ($bookmarks as $bookmark) {
                $this->db->insert('user_bookmarks', [
                    'user_id' => $userId,
                    'archive_id' => $bookmark['id'],
                    'title' => $bookmark['title'] ?? null,
                    'creator' => $bookmark['creator'] ?? null,
                    'thumbnail_url' => $bookmark['thumbnail'] ?? null,
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    // =====================================================
    // WATCH HISTORY
    // =====================================================

    /**
     * Get watch history
     */
    public function getWatchHistory(int $limit = 50): array {
        $userId = $this->getUserId();
        if (!$userId) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT archive_id as id, playback_position, duration, progress_percent, last_watched, watch_count
             FROM user_watch_history
             WHERE user_id = ?
             ORDER BY last_watched DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Update watch progress
     */
    public function updateProgress(string $archiveId, float $currentTime, float $duration): bool {
        $userId = $this->getOrCreateUser();

        $progressPercent = $duration > 0 ? ($currentTime / $duration) * 100 : 0;

        $this->db->query(
            "INSERT INTO user_watch_history (user_id, archive_id, playback_position, duration, progress_percent)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                playback_position = VALUES(playback_position),
                duration = VALUES(duration),
                progress_percent = VALUES(progress_percent),
                watch_count = watch_count + 1",
            [$userId, $archiveId, $currentTime, $duration, $progressPercent]
        );

        return true;
    }

    /**
     * Get progress for a video
     */
    public function getProgress(string $archiveId): ?array {
        $userId = $this->getUserId();
        if (!$userId) {
            return null;
        }

        return $this->db->fetchOne(
            "SELECT playback_position, duration, progress_percent
             FROM user_watch_history
             WHERE user_id = ? AND archive_id = ?",
            [$userId, $archiveId]
        );
    }

    /**
     * Clear watch history
     */
    public function clearWatchHistory(): bool {
        $userId = $this->getUserId();
        if (!$userId) {
            return false;
        }

        $this->db->delete('user_watch_history', 'user_id = ?', [$userId]);
        return true;
    }

    // =====================================================
    // SEARCH HISTORY
    // =====================================================

    /**
     * Add to search history
     */
    public function addSearchHistory(string $query, array $filters = [], int $resultCount = 0): void {
        $userId = $this->getOrCreateUser();

        $this->db->insert('search_history', [
            'user_id' => $userId,
            'query' => $query,
            'filters' => json_encode($filters),
            'result_count' => $resultCount,
        ]);
    }

    /**
     * Get recent searches
     */
    public function getRecentSearches(int $limit = 10): array {
        $userId = $this->getUserId();
        if (!$userId) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT DISTINCT query, searched_at
             FROM search_history
             WHERE user_id = ?
             ORDER BY searched_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Clear search history
     */
    public function clearSearchHistory(): bool {
        $userId = $this->getUserId();
        if (!$userId) {
            return false;
        }

        $this->db->delete('search_history', 'user_id = ?', [$userId]);
        return true;
    }
}
