/**
 * CSRF token — single source of truth for the whole frontend.
 *
 * The server prints the current token into <meta name="csrf-token"> on every
 * full page render (csrf_meta_tag() in bootstrap.php), and rotates it whenever
 * the session changes (login / register / logout). Before this module each
 * service (ApiService, CollectionService, AuthService) cached its own copy of
 * the meta value, so after an AJAX login that didn't navigate, those caches —
 * and the meta tag itself — still held the PRE-login token and the next
 * state-changing request would 403.
 *
 * Centralizing fixes that: every caller reads getCsrfToken() here, and the
 * auth flow calls setCsrfToken() with the rotated token returned by the
 * login/register/logout endpoints, which updates both this cache AND the meta
 * tag (so code that reads the meta directly, e.g. BackgroundCacheService, also
 * sees the new value) without needing a full navigation.
 */

let _token = null;

/** Current CSRF token. Reads the <meta> tag once, then caches. */
export function getCsrfToken() {
  if (_token !== null) return _token;
  const meta = typeof document !== 'undefined'
    ? document.querySelector('meta[name="csrf-token"]')
    : null;
  _token = meta ? (meta.getAttribute('content') || '') : '';
  return _token;
}

/**
 * Replace the cached token (and keep the <meta> tag in sync). Call this on an
 * auth-state change with the rotated token the server returned.
 */
export function setCsrfToken(token) {
  if (typeof token !== 'string' || token === '') return;
  _token = token;
  if (typeof document !== 'undefined') {
    let meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.setAttribute('name', 'csrf-token');
      (document.head || document.documentElement).appendChild(meta);
    }
    meta.setAttribute('content', token);
  }
}

/**
 * Drop the cache so the next getCsrfToken() re-reads the <meta> tag. Use when
 * the token changed but the new value isn't in hand (falls back to the meta).
 */
export function clearCsrfToken() {
  _token = null;
  return getCsrfToken();
}
