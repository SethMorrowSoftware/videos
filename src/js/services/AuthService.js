/**
 * AuthService - Frontend auth client
 *
 * Wraps all /api/auth/* endpoints, caches the current user, and
 * provides a pub/sub API so BookmarkManager / WatchHistory / header UI
 * can react to login/logout in real time.
 *
 * Response contract (from services/Http/ApiController.php):
 *   success → { success: true, ...payload }
 *   error   → { success: false, error: "...", ?errors: {...} }
 */

// Relative path so subdirectory deployments (e.g. /films/ on cPanel)
// resolve correctly against document.baseURI.
const AUTH_BASE = 'api/auth';

/**
 * Typed error we can throw from network calls, carrying the raw
 * body so callers can dig for { errors: {...} } validation bags.
 */
export class AuthError extends Error {
  constructor(message, status, body) {
    super(message);
    this.name = 'AuthError';
    this.status = status;
    this.body = body || null;
    this.errors = body && body.errors ? body.errors : null;
  }
}

async function apiCall(path, { method = 'GET', body = null } = {}) {
  const options = {
    method,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
  };
  if (body !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(`${AUTH_BASE}${path}`, options);
  } catch (netErr) {
    throw new AuthError('Network error', 0, null);
  }

  let parsed = null;
  try {
    parsed = await response.json();
  } catch (_) {
    // non-JSON body
  }

  if (!response.ok || (parsed && parsed.success === false)) {
    const msg = (parsed && (parsed.error || parsed.message)) || `Request failed (${response.status})`;
    throw new AuthError(msg, response.status, parsed);
  }
  return parsed || {};
}

// -------------------------------------------------------------------
// State + pub/sub
// -------------------------------------------------------------------
let cachedUser = null;
let cachedGuest = null;
let fetchMePromise = null;
const listeners = new Set();

function notify() {
  for (const fn of listeners) {
    try { fn({ user: cachedUser, guest: cachedGuest }); }
    catch (e) { console.warn('[AuthService] listener error:', e); }
  }
}

function setState({ user, guest }) {
  cachedUser = user || null;
  cachedGuest = guest || null;
  notify();
}

// -------------------------------------------------------------------
// Public API
// -------------------------------------------------------------------
export const AuthService = {
  AuthError,

  /**
   * Subscribe to auth state changes. Returns an unsubscribe fn.
   * Called synchronously with current state on subscription.
   */
  onChange(fn) {
    listeners.add(fn);
    // Fire immediately so subscribers render in sync
    try { fn({ user: cachedUser, guest: cachedGuest }); } catch (e) { /* ignore */ }
    return () => listeners.delete(fn);
  },

  /** Currently-cached user. May be null until fetchMe() runs. */
  getUser() {
    return cachedUser;
  },

  /** Currently-cached guest info (bookmarks/history counts). */
  getGuest() {
    return cachedGuest;
  },

  isAuthenticated() {
    return !!cachedUser;
  },

  /**
   * Fetch /api/auth/me and update the cached user. Idempotent and
   * de-duped: concurrent callers share the same in-flight promise.
   */
  async fetchMe({ force = false } = {}) {
    if (!force && cachedUser) return cachedUser;
    if (fetchMePromise) return fetchMePromise;

    fetchMePromise = (async () => {
      try {
        const res = await apiCall('/me.php');
        setState({
          user: res.authenticated ? res.user : null,
          guest: res.guest || null,
        });
        return cachedUser;
      } catch (err) {
        setState({ user: null, guest: null });
        return null;
      } finally {
        fetchMePromise = null;
      }
    })();

    return fetchMePromise;
  },

  async login({ identifier, password, remember = true, mergeGuest = true }) {
    const res = await apiCall('/login.php', {
      method: 'POST',
      body: { identifier, password, remember, mergeGuest },
    });
    setState({ user: res.user, guest: null });
    return res.user;
  },

  async register({ username, email, password, display_name = '', mergeGuest = true }) {
    const res = await apiCall('/register.php', {
      method: 'POST',
      body: { username, email, password, display_name, mergeGuest },
    });
    setState({ user: res.user, guest: null });
    return res.user;
  },

  async logout() {
    try {
      await apiCall('/logout.php', { method: 'POST', body: {} });
    } finally {
      setState({ user: null, guest: null });
    }
  },

  async forgotPassword(email) {
    return apiCall('/forgot-password.php', {
      method: 'POST',
      body: { email },
    });
  },

  async resetPassword({ token, password }) {
    return apiCall('/reset-password.php', {
      method: 'POST',
      body: { token, password },
    });
  },

  async changePassword({ oldPassword, newPassword }) {
    return apiCall('/change-password.php', {
      method: 'POST',
      body: { oldPassword, newPassword },
    });
  },

  async updateProfile(fields) {
    const res = await apiCall('/profile.php', {
      method: 'POST',
      body: fields,
    });
    if (res.user) {
      setState({ user: res.user, guest: cachedGuest });
    }
    return res.user;
  },

  /** Test helper — wipes cached state without a network call. */
  _reset() {
    setState({ user: null, guest: null });
  },
};

export default AuthService;
