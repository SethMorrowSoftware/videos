<?php
/**
 * SearchHistoryService
 *
 * Per-user search history.
 */
class SearchHistoryService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function record(int $userId, string $query, array $filters = [], int $resultCount = 0): void {
        $this->db->insert('search_history', [
            'user_id' => $userId,
            'query' => $query,
            'filters' => json_encode($filters),
            'result_count' => $resultCount,
        ]);
    }

    public function recent(int $userId, int $limit = 10): array {
        return $this->db->fetchAll(
            "SELECT DISTINCT query, searched_at
             FROM search_history
             WHERE user_id = ?
             ORDER BY searched_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    public function clear(int $userId): void {
        $this->db->delete('search_history', 'user_id = ?', [$userId]);
    }
}
