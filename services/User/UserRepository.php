<?php
/**
 * UserRepository
 *
 * All direct DB reads/writes on the `users` table live here.
 * No session handling, no "current user" logic — that's UserContext.
 * No auth — that's UserAuthService.
 *
 * This is a plain repository: give it an id, get a row; hand it a
 * partial user, get an id back.
 */
class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =====================================================
    // LOOKUPS
    // =====================================================

    public function findById(int $id): ?array {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, display_name, avatar_url, role, is_guest,
                    session_id, email_verified_at, preferences, created_at, last_seen
             FROM users WHERE id = ?",
            [$id]
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySessionId(string $sessionId): ?array {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, display_name, avatar_url, role, is_guest,
                    session_id, email_verified_at, preferences, created_at, last_seen
             FROM users WHERE session_id = ?",
            [$sessionId]
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUsername(string $username): ?array {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, password_hash, display_name, avatar_url,
                    role, is_guest, session_id, email_verified_at, preferences,
                    created_at, last_seen
             FROM users WHERE username = ? AND is_guest = 0",
            [$username]
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?array {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, password_hash, display_name, avatar_url,
                    role, is_guest, session_id, email_verified_at, preferences,
                    created_at, last_seen
             FROM users WHERE email = ? AND is_guest = 0",
            [$email]
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Find by username or email — used for login.
     */
    public function findByIdentifier(string $identifier): ?array {
        return strpos($identifier, '@') !== false
            ? $this->findByEmail($identifier)
            : $this->findByUsername($identifier);
    }

    // =====================================================
    // WRITES
    // =====================================================

    public function createGuest(string $sessionId, ?string $userAgent, string $ipHash): int {
        // mb_strcut byte-truncates at a UTF-8 boundary so a partial multibyte
        // sequence can't end up stored in the utf8mb4 column.
        $ua = null;
        if ($userAgent !== null && $userAgent !== '') {
            $ua = function_exists('mb_strcut')
                ? mb_strcut($userAgent, 0, 500, 'UTF-8')
                : substr($userAgent, 0, 500);
        }
        return $this->db->insert('users', [
            'session_id' => $sessionId,
            'user_agent' => $ua,
            'ip_hash' => $ipHash,
            'role' => 'guest',
            'is_guest' => 1,
            'preferences' => json_encode([]),
        ]);
    }

    public function createAccount(array $data): int {
        return $this->db->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'display_name' => $data['display_name'] ?? $data['username'],
            'role' => $data['role'] ?? 'viewer',
            'is_guest' => 0,
            'session_id' => $data['session_id'] ?? null,
            'preferences' => json_encode($data['preferences'] ?? []),
        ]);
    }

    public function updateLastSeen(int $userId): void {
        $this->db->query("UPDATE users SET last_seen = NOW() WHERE id = ?", [$userId]);
    }

    public function updatePassword(int $userId, string $passwordHash): void {
        $this->db->query(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$passwordHash, $userId]
        );
    }

    public function updateProfile(int $userId, array $fields): void {
        $allowed = ['display_name', 'avatar_url', 'email'];
        $set = [];
        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $set[] = "$key = ?";
                $values[] = $fields[$key];
            }
        }
        if (!$set) return;
        $values[] = $userId;
        $this->db->query("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?", $values);
    }

    public function setEmailVerified(int $userId): void {
        $this->db->query("UPDATE users SET email_verified_at = NOW() WHERE id = ?", [$userId]);
    }

    public function setPreferences(int $userId, array $prefs): void {
        $this->db->query(
            "UPDATE users SET preferences = ? WHERE id = ?",
            [json_encode($prefs), $userId]
        );
    }

    public function deleteUser(int $userId): void {
        // Cascades to bookmarks, history, collections, tokens via FK.
        $this->db->delete('users', 'id = ?', [$userId]);
    }

    /**
     * Merge guest user data into an account user, then delete the guest row.
     * Used when a logged-out visitor signs up / logs in and wants to keep
     * their locally-collected bookmarks and history.
     *
     * Bookmarks: add guest's rows that don't collide with the account's.
     * History:   add guest's rows that don't collide.
     * Search history: move all.
     */
    public function mergeGuestInto(int $guestId, int $accountId): void {
        if ($guestId === $accountId) return;

        $this->db->beginTransaction();
        try {
            // Bookmarks
            $this->db->query(
                "INSERT IGNORE INTO user_bookmarks (user_id, archive_id, title, creator, thumbnail_url, created_at)
                 SELECT ?, archive_id, title, creator, thumbnail_url, created_at
                 FROM user_bookmarks WHERE user_id = ?",
                [$accountId, $guestId]
            );

            // Watch history — keep the latest position on collision
            $this->db->query(
                "INSERT INTO user_watch_history
                    (user_id, archive_id, playback_position, duration, progress_percent, last_watched, watch_count)
                 SELECT ?, archive_id, playback_position, duration, progress_percent, last_watched, watch_count
                 FROM user_watch_history WHERE user_id = ?
                 ON DUPLICATE KEY UPDATE
                    playback_position = IF(VALUES(last_watched) > last_watched, VALUES(playback_position), playback_position),
                    duration = IF(VALUES(last_watched) > last_watched, VALUES(duration), duration),
                    progress_percent = IF(VALUES(last_watched) > last_watched, VALUES(progress_percent), progress_percent),
                    last_watched = GREATEST(last_watched, VALUES(last_watched)),
                    watch_count = watch_count + VALUES(watch_count)",
                [$accountId, $guestId]
            );

            // Search history (append)
            $this->db->query(
                "UPDATE search_history SET user_id = ? WHERE user_id = ?",
                [$accountId, $guestId]
            );

            // Remove guest row (cascades any leftovers)
            $this->db->delete('users', 'id = ? AND is_guest = 1', [$guestId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /** Decode preferences JSON, strip password hash from output. */
    private function hydrate(array $row): array {
        if (isset($row['preferences'])) {
            $decoded = json_decode($row['preferences'] ?? '', true);
            $row['preferences'] = is_array($decoded) ? $decoded : [];
        }
        $row['is_guest'] = (bool)($row['is_guest'] ?? true);
        return $row;
    }
}
