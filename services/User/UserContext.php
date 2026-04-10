<?php
/**
 * UserContext
 *
 * Resolves the "current user" for a request. A visitor is always a user;
 * the question is just whether they're a guest (anonymous, session-bound)
 * or a signed-in account.
 *
 * Resolution order for the current user:
 *   1. $_SESSION['user_id']    ← set by UserAuthService::login()
 *   2. remember-me cookie      ← set by UserAuthService::login(remember=true)
 *   3. Guest row keyed by PHP session id, auto-created if missing
 *
 * Everything that needs to know "which user is making this request"
 * should go through UserContext — never touch $_SESSION directly.
 */
class UserContext {
    private $repo;
    /** @var array|null */
    private $cached = null;

    const REMEMBER_COOKIE = 'afc_remember';

    public function __construct(?UserRepository $repo = null) {
        $this->repo = $repo ?: new UserRepository();
    }

    /**
     * Get the current user (guest or account). Guaranteed non-null for
     * any web request after bootstrap.php has started a session.
     */
    public function current(): array {
        if ($this->cached !== null) {
            return $this->cached;
        }

        // 1. Session-authenticated account user
        if (!empty($_SESSION['user_id'])) {
            $user = $this->repo->findById((int)$_SESSION['user_id']);
            if ($user && !$user['is_guest']) {
                $this->repo->updateLastSeen($user['id']);
                return $this->cached = $user;
            }
            unset($_SESSION['user_id']);
        }

        // 2. Remember-me cookie
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $user = $this->tryRememberCookie($_COOKIE[self::REMEMBER_COOKIE]);
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $this->repo->updateLastSeen($user['id']);
                return $this->cached = $user;
            }
        }

        // 3. Guest user keyed by PHP session id
        $sessionId = session_id();
        $guest = $this->repo->findBySessionId($sessionId);
        if (!$guest) {
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $id = $this->repo->createGuest($sessionId, $userAgent, $ipHash);
            $guest = $this->repo->findById($id);
        } else {
            $this->repo->updateLastSeen($guest['id']);
        }

        return $this->cached = $guest;
    }

    /**
     * Current user's id (guest or account).
     */
    public function currentId(): int {
        return (int)$this->current()['id'];
    }

    /**
     * Is the current visitor an authenticated account (not a guest)?
     */
    public function isAuthenticated(): bool {
        return !$this->current()['is_guest'];
    }

    /**
     * Is the current user an admin (role = admin or editor)?
     */
    public function isAdmin(): bool {
        $role = $this->current()['role'] ?? 'guest';
        return $role === 'admin' || $role === 'editor';
    }

    /**
     * Flush the in-request cache. Call after login/logout/merge so the
     * next current() returns the updated identity.
     */
    public function refresh(): void {
        $this->cached = null;
    }

    /**
     * Get the current guest id, if the visitor is currently a guest,
     * without prompting creation. Used by the signup flow to hand the
     * guest row off to UserRepository::mergeGuestInto().
     */
    public function pendingGuestId(): ?int {
        if (!empty($_SESSION['user_id'])) {
            return null; // already authenticated
        }
        $guest = $this->repo->findBySessionId(session_id());
        return $guest && $guest['is_guest'] ? (int)$guest['id'] : null;
    }

    // =====================================================
    // REMEMBER-ME COOKIE
    // =====================================================

    /**
     * Try to resolve a remember-me cookie value ("tokenId:rawToken")
     * into a user. Returns the user on success, null on failure (and
     * silently clears an invalid cookie).
     */
    private function tryRememberCookie(string $cookieValue): ?array {
        $db = Database::getInstance();
        $hash = hash('sha256', $cookieValue);

        $token = $db->fetchOne(
            "SELECT user_id, expires_at FROM user_auth_tokens
             WHERE token_hash = ? AND purpose = 'remember' AND used_at IS NULL",
            [$hash]
        );

        if (!$token) {
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
            return null;
        }
        if (strtotime($token['expires_at']) < time()) {
            $db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
            return null;
        }

        return $this->repo->findById((int)$token['user_id']);
    }
}
