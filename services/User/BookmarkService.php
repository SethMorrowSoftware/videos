<?php
/**
 * BookmarkService
 *
 * Per-user bookmark CRUD. Takes a user id — doesn't care whether
 * the caller is a guest or an account. UserContext resolves that.
 */
class BookmarkService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(int $userId): array {
        return $this->db->fetchAll(
            "SELECT archive_id AS id, title, creator, thumbnail_url AS thumbnail, created_at
             FROM user_bookmarks
             WHERE user_id = ?
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    public function exists(int $userId, string $archiveId): bool {
        $row = $this->db->fetchOne(
            "SELECT id FROM user_bookmarks WHERE user_id = ? AND archive_id = ?",
            [$userId, $archiveId]
        );
        return $row !== null;
    }

    public function add(int $userId, string $archiveId, array $metadata = []): bool {
        try {
            $this->db->insert('user_bookmarks', [
                'user_id' => $userId,
                'archive_id' => $archiveId,
                'title' => $metadata['title'] ?? null,
                'creator' => $metadata['creator'] ?? null,
                'thumbnail_url' => $metadata['thumbnail'] ?? null,
            ]);
            return true;
        } catch (Throwable $e) {
            // Unique key collision → already bookmarked
            return false;
        }
    }

    public function remove(int $userId, string $archiveId): void {
        $this->db->delete('user_bookmarks', 'user_id = ? AND archive_id = ?', [$userId, $archiveId]);
    }

    public function sync(int $userId, array $bookmarks): bool {
        $this->db->beginTransaction();
        try {
            $this->db->delete('user_bookmarks', 'user_id = ?', [$userId]);
            foreach ($bookmarks as $b) {
                if (empty($b['id'])) continue;
                $this->db->insert('user_bookmarks', [
                    'user_id' => $userId,
                    'archive_id' => $b['id'],
                    'title' => $b['title'] ?? null,
                    'creator' => $b['creator'] ?? null,
                    'thumbnail_url' => $b['thumbnail'] ?? null,
                ]);
            }
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('[BookmarkService::sync] ' . $e->getMessage());
            return false;
        }
    }

    public function count(int $userId): int {
        $n = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?",
            [$userId]
        );
        return (int)$n;
    }
}
