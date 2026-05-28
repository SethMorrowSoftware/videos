/**
 * BackgroundCacheService
 *
 * Queues items for background caching as users browse.
 * This helps build up a local cache of metadata and thumbnails
 * to reduce Archive.org API usage over time.
 */

// Permanent-disable threshold — a single transient SW timeout used to
// brick this service for the rest of the session, which then cascaded
// into thumbnails not getting prefetched and the UI feeling broken.
const PERMANENT_DISABLE_AFTER_404 = true; // 404 means the endpoint genuinely doesn't exist
const TRANSIENT_FAILURE_THRESHOLD = 5;

export class BackgroundCacheService {
  constructor() {
    this.pendingItems = new Set();
    this.batchSize = 20;
    this.batchDelay = 2000;
    this.batchTimer = null;
    this.enabled = true;
    this.apiEndpoint = 'api/cache.php';
    this._consecutiveFailures = 0;
  }

  /**
   * Queue an item for background caching
   */
  queueItem(identifier) {
    if (!this.enabled || !identifier) return;

    this.pendingItems.add(identifier);

    // Schedule batch processing
    this.scheduleBatch();
  }

  /**
   * Queue multiple items for background caching
   */
  queueItems(identifiers) {
    if (!this.enabled || !Array.isArray(identifiers)) return;

    identifiers.forEach(id => {
      if (id) this.pendingItems.add(id);
    });

    // Schedule batch processing
    this.scheduleBatch();
  }

  /**
   * Queue items from search results
   */
  queueSearchResults(docs) {
    if (!this.enabled || !Array.isArray(docs)) return;

    const identifiers = docs
      .map(doc => doc.identifier)
      .filter(Boolean);

    this.queueItems(identifiers);
  }

  /**
   * Schedule batch processing
   */
  scheduleBatch() {
    // Clear existing timer
    if (this.batchTimer) {
      clearTimeout(this.batchTimer);
    }

    // Schedule new batch
    this.batchTimer = setTimeout(() => {
      this.processBatch();
    }, this.batchDelay);
  }

  /**
   * Process pending items in batch
   */
  async processBatch() {
    if (this.pendingItems.size === 0) return;

    // Get items to process
    const items = Array.from(this.pendingItems).slice(0, this.batchSize);

    // Remove from pending
    items.forEach(id => this.pendingItems.delete(id));

    try {
      const response = await fetch(this.apiEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: (() => {
          const h = { 'Content-Type': 'application/json' };
          const meta = document.querySelector('meta[name="csrf-token"]');
          if (meta) h['X-CSRF-Token'] = meta.getAttribute('content') || '';
          return h;
        })(),
        body: JSON.stringify({
          action: 'queue',
          items: items,
        }),
      });

      if (!response.ok) {
        const e = new Error(`HTTP ${response.status}`);
        e.status = response.status;
        throw e;
      }

      const result = await response.json();
      this._consecutiveFailures = 0;

      if (result.success) {
        console.debug(`Background cache: queued ${result.results.queued_metadata} metadata, ${result.results.queued_thumbnails} thumbnails, ${result.results.already_cached} already cached`);
      }
    } catch (error) {
      // 404 means the endpoint isn't on the server — disable for the
      // session, retries are pointless.
      if (PERMANENT_DISABLE_AFTER_404 && error.status === 404) {
        console.warn('Background cache API not available (404), disabling for session');
        this.enabled = false;
        return;
      }

      // Everything else is treated as transient. A single SW timeout used
      // to look identical to "API not available" because both produced
      // "Failed to fetch" — that bricked background caching for the rest
      // of the session. Now we count failures and only give up after a
      // sustained pattern.
      this._consecutiveFailures++;
      console.warn(
        `Background cache batch failed (${this._consecutiveFailures}/${TRANSIENT_FAILURE_THRESHOLD}):`,
        error.message
      );

      if (this._consecutiveFailures >= TRANSIENT_FAILURE_THRESHOLD) {
        console.warn('Background cache: too many consecutive failures, pausing for this session');
        this.enabled = false;
        return;
      }

      // Re-queue items for retry on next batch.
      items.forEach(id => this.pendingItems.add(id));
    }

    // If more items pending, schedule another batch
    if (this.pendingItems.size > 0) {
      this.scheduleBatch();
    }
  }

  /**
   * Immediately cache a single item (for high-priority items like currently playing video)
   */
  async cacheItemImmediately(identifier) {
    if (!identifier) return null;

    try {
      const response = await fetch(this.apiEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: (() => {
          const h = { 'Content-Type': 'application/json' };
          const meta = document.querySelector('meta[name="csrf-token"]');
          if (meta) h['X-CSRF-Token'] = meta.getAttribute('content') || '';
          return h;
        })(),
        body: JSON.stringify({
          action: 'cache_single',
          archive_id: identifier,
          cache_thumbnail: true,
        }),
      });

      if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
      }

      const result = await response.json();
      return result;
    } catch (error) {
      console.warn('Immediate cache failed:', error.message);
      return null;
    }
  }

  /**
   * Get caching statistics
   */
  async getStats() {
    try {
      const response = await fetch(this.apiEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: (() => {
          const h = { 'Content-Type': 'application/json' };
          const meta = document.querySelector('meta[name="csrf-token"]');
          if (meta) h['X-CSRF-Token'] = meta.getAttribute('content') || '';
          return h;
        })(),
        body: JSON.stringify({
          action: 'stats',
        }),
      });

      if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
      }

      const result = await response.json();
      return result.stats || null;
    } catch (error) {
      console.warn('Failed to get cache stats:', error.message);
      return null;
    }
  }

  /**
   * Enable background caching
   */
  enable() {
    this.enabled = true;
  }

  /**
   * Disable background caching
   */
  disable() {
    this.enabled = false;
    if (this.batchTimer) {
      clearTimeout(this.batchTimer);
      this.batchTimer = null;
    }
  }

  /**
   * Check if background caching is enabled
   */
  isEnabled() {
    return this.enabled;
  }

  /**
   * Get number of pending items
   */
  getPendingCount() {
    return this.pendingItems.size;
  }
}

export default BackgroundCacheService;
