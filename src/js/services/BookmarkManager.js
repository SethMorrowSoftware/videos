/**
 * BookmarkManager Service
 *
 * Manages the user's bookmarked videos with a write-through localStorage
 * cache and optional backend sync.
 *
 *  - Unauthenticated (guest) users: localStorage is the source of truth.
 *  - Authenticated users: backend is the source of truth, localStorage is
 *    a mirror used for instant reads and offline fallback.
 *
 * The public API stays synchronous so the existing call sites in app.js
 * don't need to await every interaction. Network writes are fire-and-forget.
 */

import { CONFIG } from '../config.js';
import { safeParseJSON, extractValue } from '../utils/helpers.js';
import { ApiService } from './ApiService.js';
import { AuthService } from './AuthService.js';

const STORAGE_KEY = 'bookmarks';

export class BookmarkManager {
  constructor() {
    this.bookmarks = safeParseJSON(localStorage.getItem(STORAGE_KEY)) || [];
    this._syncInFlight = false;
    this._listeners = new Set();

    // Whenever the auth state changes, pull the server-side list.
    // This also runs once synchronously with the current state.
    AuthService.onChange(({ user }) => {
      if (user) {
        this._pullFromServer();
      }
    });

    // First load: trigger a me.php fetch (if not already cached). The
    // resulting onChange callback will pull the server bookmarks.
    AuthService.fetchMe().catch(() => { /* ignore */ });
  }

  // ----- Subscriptions --------------------------------------------------
  onChange(fn) {
    this._listeners.add(fn);
    try { fn(this.bookmarks); } catch (_) {}
    return () => this._listeners.delete(fn);
  }

  _emit() {
    for (const fn of this._listeners) {
      try { fn(this.bookmarks); } catch (_) {}
    }
  }

  // ----- Reads (sync) ---------------------------------------------------
  isBookmarked(id) {
    return this.bookmarks.some(b => b.id === id);
  }

  getAll() {
    return this.bookmarks;
  }

  // ----- Writes ---------------------------------------------------------
  add(video) {
    if (this.isBookmarked(video.identifier)) return false;

    const bookmark = {
      id: video.identifier,
      title: extractValue(video.title),
      creator: extractValue(video.creator),
      thumbnail: video.thumbnail || `https://archive.org/services/img/${video.identifier}`,
      timestamp: Date.now(),
    };

    this.bookmarks.unshift(bookmark);

    if (this.bookmarks.length > CONFIG.MAX_BOOKMARKS) {
      this.bookmarks = this.bookmarks.slice(0, CONFIG.MAX_BOOKMARKS);
    }

    this._persist();
    this._emit();

    // Fire-and-forget server write when logged in.
    if (AuthService.isAuthenticated()) {
      ApiService.addBookmark({
        id: bookmark.id,
        title: bookmark.title,
        creator: bookmark.creator,
        thumbnail: bookmark.thumbnail,
      }).catch(err => console.warn('[BookmarkManager] add sync failed:', err));
    }

    return true;
  }

  remove(id) {
    const before = this.bookmarks.length;
    this.bookmarks = this.bookmarks.filter(b => b.id !== id);
    if (this.bookmarks.length === before) return;

    this._persist();
    this._emit();

    if (AuthService.isAuthenticated()) {
      ApiService.removeBookmark(id)
        .catch(err => console.warn('[BookmarkManager] remove sync failed:', err));
    }
  }

  clear() {
    this.bookmarks = [];
    this._persist();
    this._emit();

    if (AuthService.isAuthenticated()) {
      ApiService.syncBookmarks([])
        .catch(err => console.warn('[BookmarkManager] clear sync failed:', err));
    }
  }

  // ----- Internals ------------------------------------------------------
  _persist() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.bookmarks));
    } catch (e) {
      console.warn('[BookmarkManager] localStorage save failed:', e);
    }
  }

  /**
   * Pull the authoritative bookmark list from the server. Used right after
   * login and on first authenticated page load. The server already holds
   * the merged guest→account data thanks to UserAuthService::mergeGuest(),
   * so we just replace our local mirror with whatever comes back.
   */
  async _pullFromServer() {
    if (this._syncInFlight) return;
    this._syncInFlight = true;
    try {
      const res = await ApiService.getBookmarks();
      const list = Array.isArray(res?.data) ? res.data : [];

      // Normalize to our local shape. BookmarkService returns
      // {id, title, creator, thumbnail, created_at}.
      this.bookmarks = list.map(row => ({
        id: row.id,
        title: row.title || '',
        creator: row.creator || '',
        thumbnail: row.thumbnail || `https://archive.org/services/img/${row.id}`,
        timestamp: row.created_at ? new Date(row.created_at).getTime() : Date.now(),
      }));

      this._persist();
      this._emit();
    } catch (e) {
      console.warn('[BookmarkManager] server pull failed:', e);
    } finally {
      this._syncInFlight = false;
    }
  }
}

export default BookmarkManager;
