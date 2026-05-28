<?php
/**
 * CollectionService
 *
 * Per-user video collections — lightweight curated lists that can be
 * private or shared via a public slug URL like /c/{username}/{slug}.
 *
 * Every mutation is scoped to the owning user; the only non-owner-scoped
 * read is getPublicBySlug(), which powers the public collection view.
 */
class CollectionService {
    private $db;

    const MAX_COLLECTIONS_PER_USER = 100;
    const MAX_ITEMS_PER_COLLECTION = 500;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =====================================================
    // SLUG HELPERS
    // =====================================================

    /**
     * Turn a collection name into a URL-safe slug, then make it unique
     * within the owning user's namespace by appending -2, -3, etc.
     *
     * Hard-capped at MAX_COLLECTIONS_PER_USER iterations. If we don't find
     * a free slug by then (only possible if the user is somehow over cap)
     * we fall back to a random-suffix slug rather than looping forever.
     */
    public function generateSlug(int $userId, string $name): string {
        $base = $this->slugify($name);
        if ($base === '') $base = 'collection';
        $slug = $base;
        $i = 2;
        $maxAttempts = self::MAX_COLLECTIONS_PER_USER + 5;
        while ($i <= $maxAttempts && $this->db->fetchOne(
            "SELECT id FROM user_collections WHERE user_id = ? AND slug = ?",
            [$userId, $slug]
        )) {
            $slug = $base . '-' . $i++;
        }
        if ($i > $maxAttempts) {
            // Defensive fallback: 8 hex chars of randomness, vanishingly
            // unlikely to collide.
            $slug = $base . '-' . bin2hex(random_bytes(4));
        }
        return $slug;
    }

    private function slugify(string $text): string {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        return trim($text, '-');
    }

    // =====================================================
    // READS
    // =====================================================

    /** Lists the collections owned by a specific user. */
    public function listForUser(int $userId): array {
        return $this->db->fetchAll(
            "SELECT id, name, slug, description, cover_thumbnail, is_public,
                    item_count, view_count, created_at, updated_at
             FROM user_collections
             WHERE user_id = ?
             ORDER BY updated_at DESC",
            [$userId]
        );
    }

    /** Fetches a single collection owned by $userId. Returns null if missing. */
    public function getForUser(int $userId, int $collectionId): ?array {
        return $this->db->fetchOne(
            "SELECT * FROM user_collections WHERE id = ? AND user_id = ?",
            [$collectionId, $userId]
        );
    }

    /**
     * Fetches a public collection by owner username + slug. Used by the
     * shareable /c/{username}/{slug} route.
     */
    public function getPublicBySlug(string $username, string $slug): ?array {
        $row = $this->db->fetchOne(
            "SELECT c.*, u.username AS owner_username, u.display_name AS owner_display_name
             FROM user_collections c
             JOIN users u ON u.id = c.user_id
             WHERE u.username = ? AND c.slug = ? AND c.is_public = 1 AND u.is_guest = 0",
            [$username, $slug]
        );
        return $row ?: null;
    }

    /** All items in a collection, in display order. */
    public function getItems(int $collectionId): array {
        return $this->db->fetchAll(
            "SELECT archive_id AS id, title, creator, thumbnail_url AS thumbnail,
                    note, position, created_at
             FROM user_collection_items
             WHERE collection_id = ?
             ORDER BY position ASC, created_at ASC",
            [$collectionId]
        );
    }

    /** Returns all *public* collections, optionally paged. */
    public function listPublic(int $limit = 24, int $offset = 0): array {
        return $this->db->fetchAll(
            "SELECT c.id, c.name, c.slug, c.description, c.cover_thumbnail,
                    c.item_count, c.view_count, c.updated_at,
                    u.username AS owner_username, u.display_name AS owner_display_name
             FROM user_collections c
             JOIN users u ON u.id = c.user_id
             WHERE c.is_public = 1 AND u.is_guest = 0
             ORDER BY c.updated_at DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
            []
        );
    }

    // =====================================================
    // WRITES
    // =====================================================

    /**
     * Create a new collection. Returns the new id or throws on limit.
     */
    public function create(int $userId, array $fields): int {
        $count = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_collections WHERE user_id = ?",
            [$userId]
        );
        if ($count >= self::MAX_COLLECTIONS_PER_USER) {
            throw new RuntimeException('Collection limit reached');
        }

        $name = trim((string)($fields['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name is required');
        }
        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Name is too long');
        }

        $slug = $this->generateSlug($userId, $name);

        return $this->db->insert('user_collections', [
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'description' => isset($fields['description'])
                ? mb_substr(trim((string)$fields['description']), 0, 2000)
                : null,
            'is_public' => !empty($fields['is_public']) ? 1 : 0,
            'cover_thumbnail' => $this->normalizeThumbnailUrl($fields['cover_thumbnail'] ?? null),
        ]);
    }

    /**
     * Trim and length-cap a thumbnail URL, and reject obviously hostile
     * schemes (javascript:, data:, etc.). Returns null for empty/invalid
     * values so collections fall back to the auto-cover-from-first-item
     * behavior.
     */
    private function normalizeThumbnailUrl($value): ?string {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '') return null;
        if (strlen($value) > 500) return null;
        // Only http(s) and same-origin relative paths.
        if (preg_match('#^(https?:)?//#i', $value)) {
            return preg_match('#^https?://#i', $value) ? $value : null;
        }
        // Reject other schemes (javascript:, data:, file:, etc.)
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value)) return null;
        return $value;
    }

    /** Update metadata (name, description, is_public, cover). */
    public function update(int $userId, int $collectionId, array $fields): bool {
        $existing = $this->getForUser($userId, $collectionId);
        if (!$existing) return false;

        $updates = [];
        if (array_key_exists('name', $fields)) {
            $name = trim((string)$fields['name']);
            if ($name === '' || mb_strlen($name) > 150) {
                throw new InvalidArgumentException('Invalid name');
            }
            $updates['name'] = $name;
            // Regenerate slug only if name actually changed
            if ($name !== $existing['name']) {
                $updates['slug'] = $this->generateSlug($userId, $name);
            }
        }
        if (array_key_exists('description', $fields)) {
            $updates['description'] = mb_substr(trim((string)$fields['description']), 0, 2000);
        }
        if (array_key_exists('is_public', $fields)) {
            $updates['is_public'] = !empty($fields['is_public']) ? 1 : 0;
        }
        if (array_key_exists('cover_thumbnail', $fields)) {
            $updates['cover_thumbnail'] = $this->normalizeThumbnailUrl($fields['cover_thumbnail']);
        }

        if (!$updates) return true;

        $this->db->update('user_collections', $updates, 'id = ? AND user_id = ?', [$collectionId, $userId]);
        return true;
    }

    /** Delete a collection (and cascade-delete its items via FK). */
    public function delete(int $userId, int $collectionId): bool {
        $deleted = $this->db->delete(
            'user_collections',
            'id = ? AND user_id = ?',
            [$collectionId, $userId]
        );
        return $deleted > 0;
    }

    // =====================================================
    // ITEMS
    // =====================================================

    /**
     * Add a video to a collection. Returns true if the item was added,
     * false if it was already present. Throws on collection not found
     * or item-limit reached.
     */
    public function addItem(int $userId, int $collectionId, array $video): bool {
        $collection = $this->getForUser($userId, $collectionId);
        if (!$collection) {
            throw new RuntimeException('Collection not found');
        }
        if ((int)$collection['item_count'] >= self::MAX_ITEMS_PER_COLLECTION) {
            throw new RuntimeException('Collection is full');
        }

        $archiveId = (string)($video['archive_id'] ?? $video['id'] ?? '');
        if ($archiveId === '') {
            throw new InvalidArgumentException('Missing archive id');
        }

        // Dedupe
        $existing = $this->db->fetchOne(
            "SELECT id FROM user_collection_items WHERE collection_id = ? AND archive_id = ?",
            [$collectionId, $archiveId]
        );
        if ($existing) return false;

        $nextPos = (int)$this->db->fetchColumn(
            "SELECT COALESCE(MAX(position), 0) + 1 FROM user_collection_items WHERE collection_id = ?",
            [$collectionId]
        );

        $this->db->insert('user_collection_items', [
            'collection_id' => $collectionId,
            'archive_id' => $archiveId,
            'title' => mb_substr((string)($video['title'] ?? ''), 0, 500),
            'creator' => mb_substr((string)($video['creator'] ?? ''), 0, 255),
            'thumbnail_url' => $this->normalizeThumbnailUrl(
                $video['thumbnail'] ?? $video['thumbnail_url'] ?? null
            ),
            'note' => isset($video['note']) ? mb_substr((string)$video['note'], 0, 1000) : null,
            'position' => $nextPos,
        ]);

        $this->refreshCollectionStats($collectionId);
        return true;
    }

    /** Remove an item from a collection. */
    public function removeItem(int $userId, int $collectionId, string $archiveId): bool {
        $collection = $this->getForUser($userId, $collectionId);
        if (!$collection) return false;

        $deleted = $this->db->delete(
            'user_collection_items',
            'collection_id = ? AND archive_id = ?',
            [$collectionId, $archiveId]
        );

        if ($deleted > 0) {
            $this->refreshCollectionStats($collectionId);
        }
        return $deleted > 0;
    }

    /**
     * Reorder items. Accepts an ordered array of archive IDs; items not
     * in the list keep their prior relative order at the end.
     */
    public function reorderItems(int $userId, int $collectionId, array $archiveIds): bool {
        $collection = $this->getForUser($userId, $collectionId);
        if (!$collection) return false;

        $this->db->beginTransaction();
        try {
            $position = 1;
            foreach ($archiveIds as $archiveId) {
                $this->db->query(
                    "UPDATE user_collection_items SET position = ?
                     WHERE collection_id = ? AND archive_id = ?",
                    [$position++, $collectionId, (string)$archiveId]
                );
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * Update a single item's note. Used by the "add a thought" field in
     * CollectionPicker.
     */
    public function updateItemNote(int $userId, int $collectionId, string $archiveId, string $note): bool {
        $collection = $this->getForUser($userId, $collectionId);
        if (!$collection) return false;

        $rows = $this->db->update(
            'user_collection_items',
            ['note' => mb_substr($note, 0, 1000)],
            'collection_id = ? AND archive_id = ?',
            [$collectionId, $archiveId]
        );
        return $rows > 0;
    }

    // =====================================================
    // PUBLIC VIEW HELPERS
    // =====================================================

    /** Bump view count on a public collection (fire and forget). */
    public function trackView(int $collectionId): void {
        try {
            $this->db->query(
                "UPDATE user_collections SET view_count = view_count + 1 WHERE id = ?",
                [$collectionId]
            );
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    // =====================================================
    // INTERNALS
    // =====================================================

    /**
     * Recompute item_count + cover thumbnail for a collection after a
     * mutation. Picks the first item's thumbnail if no explicit cover
     * was set by the owner.
     */
    private function refreshCollectionStats(int $collectionId): void {
        $count = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_collection_items WHERE collection_id = ?",
            [$collectionId]
        );

        // Only auto-set cover if the owner hasn't chosen one.
        $collection = $this->db->fetchOne(
            "SELECT cover_thumbnail FROM user_collections WHERE id = ?",
            [$collectionId]
        );
        $cover = $collection['cover_thumbnail'] ?? null;
        $autoCover = null;
        if (!$cover) {
            $autoCover = $this->db->fetchColumn(
                "SELECT thumbnail_url FROM user_collection_items
                 WHERE collection_id = ? AND thumbnail_url IS NOT NULL
                 ORDER BY position ASC LIMIT 1",
                [$collectionId]
            ) ?: null;
        }

        $this->db->query(
            "UPDATE user_collections
             SET item_count = ?,
                 cover_thumbnail = COALESCE(cover_thumbnail, ?),
                 updated_at = NOW()
             WHERE id = ?",
            [$count, $autoCover, $collectionId]
        );
    }
}
