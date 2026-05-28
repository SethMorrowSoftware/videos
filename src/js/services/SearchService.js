/**
 * SearchService
 * Handles search queries, API calls, and result processing.
 * Uses local caching API with fallback to Archive.org.
 */

import { CONFIG, COLLECTIONS } from '../config.js';

// How many consecutive local-API failures it takes before we stop trying it
// for the rest of the session. Set to a small-but-nonzero value so a single
// transient hiccup (SW timeout, captive portal, archive.org latency spike)
// doesn't permanently flip the app onto the slower public archive.org path.
const LOCAL_API_FAIL_THRESHOLD = 3;

// Per-attempt timeout. Search against archive.org with a cold cache can take
// 15+ seconds on shared hosting, so we give it real room. The previous 10s
// value caused successful-but-slow requests to be cancelled and re-tried
// against the still-slow upstream.
const ATTEMPT_TIMEOUT_MS = 20000;

class TimeoutError extends Error {
  constructor(message = 'Request timed out') {
    super(message);
    this.name = 'TimeoutError';
  }
}

function isNetworkError(err) {
  if (!err) return false;
  if (err.name === 'TypeError') return true; // "Failed to fetch"
  if (err.name === 'AbortError') return false;
  return false;
}

function jitter(ms) {
  return ms + Math.random() * ms * 0.3;
}

export class SearchService {
  constructor() {
    this.collections = COLLECTIONS;
    this.useLocalApi = true;
    this._localApiFailures = 0;
  }

  /**
   * Fetch with retry, timeout, and external-abort support.
   *
   * - Honors a caller-supplied AbortSignal so the search can be cancelled
   *   when the user types a new query before the previous one returns.
   * - Distinguishes "the caller aborted me" from "I timed out", which the
   *   UI uses to avoid showing scary error messages for intentional aborts.
   * - Only retries on transient categories (network error, timeout, 5xx).
   *   4xx responses fail fast since retrying won't help.
   * - Jittered exponential backoff so a flood of clients don't synchronize
   *   their retries against archive.org.
   */
  async fetchWithRetry(url, options = {}, retries = 3) {
    const externalSignal = options.signal;
    let lastErr;

    for (let attempt = 0; attempt < retries; attempt++) {
      const controller = new AbortController();
      const onExternalAbort = () => controller.abort();
      if (externalSignal) {
        if (externalSignal.aborted) {
          const err = new Error('Aborted');
          err.name = 'AbortError';
          throw err;
        }
        externalSignal.addEventListener('abort', onExternalAbort, { once: true });
      }

      const timeoutId = setTimeout(() => controller.abort('timeout'), ATTEMPT_TIMEOUT_MS);

      try {
        const fetchOpts = { ...options, signal: controller.signal };
        delete fetchOpts.retries;
        const response = await fetch(url, fetchOpts);

        clearTimeout(timeoutId);
        if (externalSignal) externalSignal.removeEventListener('abort', onExternalAbort);

        // 2xx / 3xx: success.
        if (response.ok) return response;

        // 4xx: client error, don't retry.
        if (response.status >= 400 && response.status < 500) {
          const err = new Error(`HTTP ${response.status}`);
          err.status = response.status;
          err.retryable = false;
          throw err;
        }

        // 5xx: server error, retry.
        const err = new Error(`HTTP ${response.status}`);
        err.status = response.status;
        err.retryable = true;
        lastErr = err;
      } catch (err) {
        clearTimeout(timeoutId);
        if (externalSignal) externalSignal.removeEventListener('abort', onExternalAbort);

        // External abort -- propagate immediately, no retry.
        if (externalSignal && externalSignal.aborted) {
          const e = new Error('Aborted');
          e.name = 'AbortError';
          throw e;
        }

        // Our own timeout (controller.abort('timeout') above).
        if (err && err.name === 'AbortError') {
          lastErr = new TimeoutError(`Timed out after ${ATTEMPT_TIMEOUT_MS}ms`);
        } else if (err && err.retryable === false) {
          throw err;
        } else if (isNetworkError(err) || (err && err.retryable)) {
          lastErr = err;
        } else {
          lastErr = err;
        }
      }

      // Don't sleep after the last attempt.
      if (attempt < retries - 1) {
        const backoff = jitter(Math.pow(2, attempt) * 500);
        await new Promise(resolve => setTimeout(resolve, backoff));
      }
    }

    throw lastErr || new Error('All retries exhausted');
  }

  /**
   * Build search query based on filters
   */
  buildSearchQuery(options = {}) {
    const {
      query = '*',
      collection = 'all_videos',
      publicDomain = false,
      collectionsOnly = false
    } = options;

    const searchQueryParts = [];
    const knownVideoCollections = Object.keys(this.collections).filter(id => id !== 'all_videos');

    if (collectionsOnly) {
      searchQueryParts.push('mediatype:collection AND collection:movies');

      if (knownVideoCollections.length > 0) {
        searchQueryParts.push(`identifier:(${knownVideoCollections.join(' OR ')})`);
      }
    } else {
      const videoQuery = 'mediatype:(movies OR video OR television)';
      const collectionQuery = knownVideoCollections.length > 0
        ? `(mediatype:collection AND identifier:(${knownVideoCollections.join(' OR ')}))`
        : '';

      if (collectionQuery) {
        searchQueryParts.push(`(${videoQuery} OR ${collectionQuery})`);
      } else {
        searchQueryParts.push(videoQuery);
      }
    }

    if (collection && collection !== 'all_videos') {
      searchQueryParts.push(`collection:(${collection})`);
    }

    if (query && query !== '*') {
      const clean = query.replace(/[:"()]/g, ' ').trim();
      if (clean) searchQueryParts.push(`(${clean})`);
    }

    if (publicDomain) {
      searchQueryParts.push(`(licenseurl:"http://creativecommons.org/publicdomain/mark/1.0/" OR licenseurl:"https://creativecommons.org/publicdomain/mark/1.0/" OR licenseurl:"http://creativecommons.org/publicdomain/")`);
    }

    return searchQueryParts.join(' AND ');
  }

  /**
   * Execute search using local caching API
   */
  async searchViaLocalApi(options = {}) {
    const {
      query = '*',
      page = 1,
      collection = 'all_videos',
      sortBy = 'relevance',
      publicDomain = false,
      collectionsOnly = false,
      signal,
    } = options;

    const searchQuery = this.buildSearchQuery({
      query,
      collection,
      publicDomain,
      collectionsOnly
    });

    const params = new URLSearchParams({
      q: searchQuery,
      page: String(page),
      rows: String(CONFIG.ITEMS_PER_PAGE),
      sort: sortBy
    });

    const response = await this.fetchWithRetry(`api/search.php?${params}`, { signal });
    const data = await response.json();

    if (data.error) {
      throw new Error(data.error);
    }

    if (data.success === false) {
      throw new Error(data.error || 'Search failed');
    }

    if (data.data && data.data.response) {
      return data.data;
    }

    return data;
  }

  /**
   * Execute search directly against Archive.org API (fallback)
   */
  async searchArchiveDirect(options = {}) {
    const {
      query = '*',
      page = 1,
      collection = 'all_videos',
      sortBy = 'relevance',
      publicDomain = false,
      collectionsOnly = false,
      signal,
    } = options;

    const searchQuery = this.buildSearchQuery({
      query,
      collection,
      publicDomain,
      collectionsOnly
    });

    const params = new URLSearchParams({
      q: searchQuery,
      output: 'json',
      rows: String(CONFIG.ITEMS_PER_PAGE),
      page: String(page)
    });

    ['identifier', 'title', 'description', 'date', 'downloads', 'creator', 'runtime', 'licenseurl', 'subject', 'mediatype', 'num_items']
      .forEach(f => params.append('fl[]', f));

    if (sortBy && sortBy !== 'relevance') {
      switch (sortBy) {
        case 'date': params.append('sort[]', 'publicdate desc'); break;
        case 'downloads': params.append('sort[]', 'downloads desc'); break;
        case 'title': params.append('sort[]', 'titleSorter asc'); break;
        case 'creator': params.append('sort[]', 'creatorSorter asc'); break;
      }
    }

    const url = `https://archive.org/advancedsearch.php?${params}`;
    const response = await this.fetchWithRetry(url, { signal });
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    return response.json();
  }

  /**
   * Execute search against Archive.org API (with local cache fallback).
   *
   * Local API failures are *tolerated* up to LOCAL_API_FAIL_THRESHOLD before
   * we permanently fall back to archive.org direct. Previously a single
   * `Failed to fetch` (which the SW happily produces on a timeout) would
   * brick the local API path for the rest of the session.
   */
  async searchArchive(options = {}) {
    if (this.useLocalApi) {
      try {
        const result = await this.searchViaLocalApi(options);
        this._localApiFailures = 0;
        return result;
      } catch (error) {
        // Abort means user navigated away or typed something new — never
        // count it as a local API failure.
        if (error && error.name === 'AbortError') throw error;

        // Hard 404 from the local API means the endpoint doesn't exist
        // (unfinished install, custom routing). Disable immediately.
        if (error && error.status === 404) {
          console.warn('Local API returned 404, disabling for session');
          this.useLocalApi = false;
        } else {
          this._localApiFailures++;
          console.warn(
            `Local API failure ${this._localApiFailures}/${LOCAL_API_FAIL_THRESHOLD}:`,
            error.message
          );
          if (this._localApiFailures >= LOCAL_API_FAIL_THRESHOLD) {
            console.warn('Local API failure threshold reached, falling back to archive.org for the session');
            this.useLocalApi = false;
          }
        }
      }
    }

    return this.searchArchiveDirect(options);
  }

  /**
   * Search for a random video
   */
  async getRandomVideo() {
    const collections = Object.keys(this.collections).filter(c => c !== 'all_videos');
    const randomCollection = collections[Math.floor(Math.random() * collections.length)];
    const randomPage = Math.floor(Math.random() * 10) + 1;

    const data = await this.searchArchive({
      query: '*',
      page: randomPage,
      collection: randomCollection
    });

    const docs = data.response.docs.filter(d => d.mediatype !== 'collection');

    if (docs.length === 0) {
      throw new Error('No videos found');
    }

    return docs[Math.floor(Math.random() * docs.length)];
  }

  /**
   * Calculate pagination info
   */
  getPaginationInfo(numFound, currentPage) {
    const totalPages = Math.ceil(numFound / CONFIG.ITEMS_PER_PAGE);
    const start = (currentPage - 1) * CONFIG.ITEMS_PER_PAGE + 1;
    const end = Math.min(currentPage * CONFIG.ITEMS_PER_PAGE, numFound);

    return {
      totalPages,
      start,
      end,
      hasPrevious: currentPage > 1,
      hasNext: currentPage < totalPages
    };
  }

  /**
   * Get collection display name
   */
  getCollectionDisplayName(collectionId) {
    return this.collections[collectionId] || 'Unknown Collection';
  }

  /**
   * Add a dynamic collection
   */
  addCollection(id, name) {
    const displayName = name.length > 30 ? name.slice(0, 27) + '...' : name;
    this.collections[id] = displayName;
  }

  /**
   * Get all collections
   */
  getCollections() {
    return { ...this.collections };
  }

  /**
   * Get sorted collections for display
   */
  getSortedCollections() {
    return Object.entries(this.collections).sort((a, b) => {
      if (a[0] === 'all_videos') return -1;
      if (b[0] === 'all_videos') return 1;
      return a[1].localeCompare(b[1]);
    });
  }
}

export default SearchService;
