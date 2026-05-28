<?php
/**
 * WatchHistoryService
 *
 * Per-user watch history and progress tracking.
 */
class WatchHistoryService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function recent(int $userId, int $limit = 50): array {
        return $this->db->fetchAll(
            "SELECT archive_id AS id, playback_position, duration, progress_percent,
                    last_watched, watch_count
             FROM user_watch_history
             WHERE user_id = ?
             ORDER BY last_watched DESC
             LIMIT " . (int)$limit,
            [$userId]
        );
    }

    public function getProgress(int $userId, string $archiveId): ?array {
        return $this->db->fetchOne(
            "SELECT playback_position, duration, progress_percent
             FROM user_watch_history
             WHERE user_id = ? AND archive_id = ?",
            [$userId, $archiveId]
        );
    }

    public function updateProgress(int $userId, string $archiveId, float $currentTime, float $duration): void {
        // Clamp client-supplied values: a malicious or buggy client can send
        // negative or over-duration figures that skew "continue watching" and
        // engagement metrics. Reject negatives, and bound the derived percent
        // to [0,100] (currentTime can exceed duration on a seek to the end).
        $currentTime = max(0.0, $currentTime);
        $duration = max(0.0, $duration);
        $percent = $duration > 0 ? ($currentTime / $duration) * 100 : 0;
        $percent = max(0.0, min(100.0, $percent));

        $this->db->query(
            "INSERT INTO user_watch_history
                (user_id, archive_id, playback_position, duration, progress_percent)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                playback_position = VALUES(playback_position),
                duration = VALUES(duration),
                progress_percent = VALUES(progress_percent),
                watch_count = watch_count + 1",
            [$userId, $archiveId, $currentTime, $duration, $percent]
        );
    }

    public function clear(int $userId): void {
        $this->db->delete('user_watch_history', 'user_id = ?', [$userId]);
    }
}
