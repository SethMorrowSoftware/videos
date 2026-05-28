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
        // GROUP BY query so a repeated search shows once, ordered by its most
        // recent occurrence. `SELECT DISTINCT query, searched_at` did NOT dedupe
        // — each row's searched_at differs, so every (query, searched_at) pair
        // was already distinct and the same query appeared many times.
        return $this->db->fetchAll(
            "SELECT query, MAX(searched_at) AS searched_at
             FROM search_history
             WHERE user_id = ?
             GROUP BY query
             ORDER BY searched_at DESC
             LIMIT " . (int)$limit,
            [$userId]
        );
    }

    public function clear(int $userId): void {
        $this->db->delete('search_history', 'user_id = ?', [$userId]);
    }
}
