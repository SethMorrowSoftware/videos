/**
 * API Service - Unified API communication layer
 *
 * Handles all communication with the backend API endpoints
 * with caching metadata support and error handling
 */

/**
 * Read the per-page CSRF token printed in <head> by csrf_meta_tag().
 * Pulled lazily so we don't fight script load order.
 */
let _csrfToken = null;
export function getCsrfToken() {
    if (_csrfToken !== null) return _csrfToken;
    const meta = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')
        : null;
    _csrfToken = meta ? meta.getAttribute('content') || '' : '';
    return _csrfToken;
}

/**
 * Build the standard request init for a state-changing JSON call. Adds
 * Content-Type, same-origin credentials, and the X-CSRF-Token header.
 */
export function jsonRequestInit(method, body) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };
    if (method !== 'GET' && method !== 'HEAD') {
        const t = getCsrfToken();
        if (t) headers['X-CSRF-Token'] = t;
    }
    return {
        method,
        headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    };
}

export class ApiService {
    // Relative path so subdirectory deployments (e.g. /films/ on cPanel)
    // resolve correctly against document.baseURI.
    static BASE_URL = 'api';

    /**
     * Search for videos using the cached search endpoint
     */
    static async search(query, options = {}) {
        const params = new URLSearchParams({
            q: query,
            page: options.page || 1,
            rows: options.rows || 20,
            collection: options.collection || 'all_videos',
            sort: options.sort || 'downloads',
        });

        try {
            const response = await fetch(`${this.BASE_URL}/search.php?${params}`);

            if (!response.ok) {
                throw new Error(`Search failed: ${response.status}`);
            }

            const data = await response.json();

            // Add cache metadata from headers
            return {
                ...data,
                cacheHit: response.headers.get('X-Cache') === 'HIT',
            };
        } catch (error) {
            console.error('ApiService.search error:', error);
            throw error;
        }
    }

    /**
     * Get video metadata
     */
    static async getMetadata(archiveId) {
        try {
            const response = await fetch(
                `${this.BASE_URL}/metadata.php?id=${encodeURIComponent(archiveId)}`
            );

            if (!response.ok) {
                throw new Error(`Metadata fetch failed: ${response.status}`);
            }

            const data = await response.json();

            return {
                ...data,
                cacheHit: response.headers.get('X-Cache') === 'HIT',
            };
        } catch (error) {
            console.error('ApiService.getMetadata error:', error);
            throw error;
        }
    }

    /**
     * Get thumbnail URL (uses cached endpoint)
     */
    static getThumbnailUrl(archiveId) {
        return `${this.BASE_URL}/thumbnail.php?id=${encodeURIComponent(archiveId)}`;
    }

    /**
     * Get site settings
     */
    static async getSettings() {
        try {
            const response = await fetch(`${this.BASE_URL}/settings.php`);

            if (!response.ok) {
                throw new Error(`Settings fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getSettings error:', error);
            throw error;
        }
    }

    /**
     * Get recommendations
     */
    static async getRecommendations() {
        try {
            const response = await fetch(`${this.BASE_URL}/recommendations.php`);

            if (!response.ok) {
                throw new Error(`Recommendations fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getRecommendations error:', error);
            throw error;
        }
    }

    /**
     * Get featured sections
     */
    static async getSections() {
        try {
            const response = await fetch(`${this.BASE_URL}/sections.php`);

            if (!response.ok) {
                throw new Error(`Sections fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getSections error:', error);
            throw error;
        }
    }

    // =====================================================
    // USER ENDPOINTS
    // =====================================================

    /**
     * Get user session/preferences
     */
    static async getUser() {
        try {
            const response = await fetch(`${this.BASE_URL}/user.php`);

            if (!response.ok) {
                throw new Error(`User fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getUser error:', error);
            throw error;
        }
    }

    /**
     * Update user preferences
     */
    static async updatePreferences(preferences) {
        try {
            const response = await fetch(`${this.BASE_URL}/user.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: (() => {
                    const h = { 'Content-Type': 'application/json' };
                    const t = getCsrfToken();
                    if (t) h['X-CSRF-Token'] = t;
                    return h;
                })(),
                body: JSON.stringify({
                    action: 'preferences',
                    preferences,
                }),
            });

            if (!response.ok) {
                throw new Error(`Preferences update failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.updatePreferences error:', error);
            throw error;
        }
    }

    // =====================================================
    // BOOKMARKS
    // =====================================================

    /**
     * Get user bookmarks
     */
    static async getBookmarks() {
        try {
            const response = await fetch(`${this.BASE_URL}/bookmarks.php`);

            if (!response.ok) {
                throw new Error(`Bookmarks fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getBookmarks error:', error);
            throw error;
        }
    }

    /**
     * Add a bookmark
     */
    static async addBookmark(video) {
        try {
            const response = await fetch(`${this.BASE_URL}/bookmarks.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: (() => {
                    const h = { 'Content-Type': 'application/json' };
                    const t = getCsrfToken();
                    if (t) h['X-CSRF-Token'] = t;
                    return h;
                })(),
                body: JSON.stringify({
                    action: 'add',
                    id: video.id || video.identifier,
                    title: video.title,
                    creator: video.creator,
                    thumbnail: video.thumbnail,
                }),
            });

            if (!response.ok) {
                throw new Error(`Bookmark add failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.addBookmark error:', error);
            throw error;
        }
    }

    /**
     * Remove a bookmark
     */
    static async removeBookmark(archiveId) {
        try {
            const response = await fetch(`${this.BASE_URL}/bookmarks.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: (() => {
                    const h = { 'Content-Type': 'application/json' };
                    const t = getCsrfToken();
                    if (t) h['X-CSRF-Token'] = t;
                    return h;
                })(),
                body: JSON.stringify({
                    action: 'remove',
                    id: archiveId,
                }),
            });

            if (!response.ok) {
                throw new Error(`Bookmark remove failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.removeBookmark error:', error);
            throw error;
        }
    }

    /**
     * Sync all bookmarks
     */
    static async syncBookmarks(bookmarks) {
        try {
            const response = await fetch(`${this.BASE_URL}/bookmarks.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: (() => {
                    const h = { 'Content-Type': 'application/json' };
                    const t = getCsrfToken();
                    if (t) h['X-CSRF-Token'] = t;
                    return h;
                })(),
                body: JSON.stringify({
                    action: 'sync',
                    bookmarks,
                }),
            });

            if (!response.ok) {
                throw new Error(`Bookmarks sync failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.syncBookmarks error:', error);
            throw error;
        }
    }

    // =====================================================
    // WATCH HISTORY
    // =====================================================

    /**
     * Get watch history
     */
    static async getWatchHistory(limit = 50) {
        try {
            const response = await fetch(
                `${this.BASE_URL}/history.php?action=list&limit=${limit}`
            );

            if (!response.ok) {
                throw new Error(`History fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getWatchHistory error:', error);
            throw error;
        }
    }

    /**
     * Get progress for a specific video
     */
    static async getVideoProgress(archiveId) {
        try {
            const response = await fetch(
                `${this.BASE_URL}/history.php?action=progress&id=${encodeURIComponent(archiveId)}`
            );

            if (!response.ok) {
                throw new Error(`Progress fetch failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.getVideoProgress error:', error);
            throw error;
        }
    }

    /**
     * Update watch progress
     */
    static async updateProgress(archiveId, currentTime, duration) {
        try {
            const response = await fetch(`${this.BASE_URL}/history.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: (() => {
                    const h = { 'Content-Type': 'application/json' };
                    const t = getCsrfToken();
                    if (t) h['X-CSRF-Token'] = t;
                    return h;
                })(),
                body: JSON.stringify({
                    action: 'update',
                    id: archiveId,
                    currentTime,
                    duration,
                }),
            });

            if (!response.ok) {
                throw new Error(`Progress update failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            // Silently fail - progress updates shouldn't block playback
            console.warn('ApiService.updateProgress error:', error);
            return { success: false };
        }
    }

    /**
     * Clear watch history
     */
    static async clearWatchHistory() {
        try {
            const response = await fetch(`${this.BASE_URL}/history.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: (() => {
                    const h = { 'Content-Type': 'application/json' };
                    const t = getCsrfToken();
                    if (t) h['X-CSRF-Token'] = t;
                    return h;
                })(),
                body: JSON.stringify({ action: 'clear' }),
            });

            if (!response.ok) {
                throw new Error(`History clear failed: ${response.status}`);
            }

            return response.json();
        } catch (error) {
            console.error('ApiService.clearWatchHistory error:', error);
            throw error;
        }
    }

    // =====================================================
    // UTILITY METHODS
    // =====================================================

    /**
     * Check if the API is available
     */
    static async healthCheck() {
        try {
            const response = await fetch(`${this.BASE_URL}/index.php`, {
                method: 'HEAD',
            });
            return response.ok;
        } catch {
            return false;
        }
    }

    /**
     * Get popular searches (if available)
     */
    static async getPopularSearches(limit = 10) {
        try {
            const response = await fetch(
                `${this.BASE_URL}/stats.php?action=popular&limit=${limit}`
            );

            if (!response.ok) {
                return { success: false, data: [] };
            }

            return response.json();
        } catch {
            return { success: false, data: [] };
        }
    }
}

export default ApiService;
