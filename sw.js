/**
 * Service Worker for Comet Cult Film Club
 * Version: 1.3.0
 * Features: Offline support, intelligent caching, background sync
 *
 * v1.3 changes:
 *   - fetchWithTimeout now uses a real AbortController so timed-out
 *     requests are actually cancelled instead of leaking. Prior version
 *     raced a setTimeout and let fetch keep running, which surfaced to
 *     the page as TypeError: Failed to fetch and got mis-classified
 *     as "offline".
 *   - Timeouts raised across the board: search.php was being killed at
 *     10s even on uncached requests where archive.org legitimately took
 *     15+ seconds. HTML navigation timeout raised from 5s to 15s.
 *   - offline.html is no longer served on a timeout — only on a true
 *     network error. Timeouts return 504 so the browser shows its own
 *     "not available" rather than a misleading offline splash.
 *   - SWR cache writes now skip success:false, empty-search, and 5xx
 *     responses so the cache can't pollute later searches with junk.
 *
 * v1.2 changes:
 *   - Stopped intercepting unknown cross-origin requests.
 *
 * v1.1 changes:
 *   - Bumped CACHE_VERSION so old caches get evicted
 *   - Image cache follows redirects, gets a generous limit
 *   - metadata-batch.php gets cache-first treatment like metadata.php
 */

const CACHE_VERSION = 'ccfc-v5';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const DYNAMIC_CACHE = `${CACHE_VERSION}-dynamic`;
const IMAGE_CACHE = `${CACHE_VERSION}-images`;

// Custom header we stamp on cached responses so getCacheAge works for
// responses that don't carry a Date header (most archive.org responses
// and any PHP endpoint that doesn't explicitly set one).
const CACHED_AT_HEADER = 'sw-cached-at';

// Static assets to cache on install (relative paths for subdirectory support).
// Only list files we KNOW exist on every page. Player-only assets get
// cached on demand by handleAppRequest so a homepage-first install
// doesn't waste bandwidth fetching the player bundle.
const STATIC_ASSETS = [
  './',
  './styles.css',
  './auth-styles.css',
  './app.js',
  './offline.html',
];

// External resources we WANT cached, but whose failure should NOT abort
// the install (Google Fonts can transiently 503; on-some-hosts the origin
// is blocked outright). Cached opportunistically via cache.add inside a
// Promise.allSettled wrapper.
const STATIC_ASSETS_OPTIONAL = [
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap',
];

// Cache size limits
const CACHE_LIMITS = {
  static: 50,
  dynamic: 100,
  // Thumbnails are immutable + small (~30KB each). Keeping ~2000 in-cache
  // is roughly 60MB, well within typical SW quota — and means a returning
  // user almost never sees a thumbnail load spinner.
  images: 2000
};

// Cache expiration times (in milliseconds)
const CACHE_EXPIRY = {
  static: 7 * 24 * 60 * 60 * 1000,  // 7 days
  dynamic: 24 * 60 * 60 * 1000,     // 1 day
  images: 30 * 24 * 60 * 60 * 1000  // 30 days
};

/**
 * Install event - cache static assets
 *
 * Required assets are added one-at-a-time via Promise.allSettled so a single
 * 404 (e.g. a stylesheet was renamed but the SW version wasn't bumped)
 * doesn't abort the entire install and leave the user without any cache.
 * Optional assets (external fonts) follow the same pattern.
 */
self.addEventListener('install', event => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      await Promise.allSettled(
        STATIC_ASSETS.map(url => cache.add(url).catch(err => {
          console.warn('[SW] precache miss for', url, err && err.message);
        }))
      );
      await Promise.allSettled(
        STATIC_ASSETS_OPTIONAL.map(url => cache.add(url).catch(() => {}))
      );
      await self.skipWaiting();
    })()
  );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', event => {
  console.log('[SW] Activating service worker...');
  
  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        return Promise.all(
          cacheNames
            .filter(name => name.startsWith('ccfc-') && !name.startsWith(CACHE_VERSION))
            .map(name => {
              console.log('[SW] Deleting old cache:', name);
              return caches.delete(name);
            })
        );
      })
      .then(() => self.clients.claim())
  );
});

/**
 * Fetch event - serve from cache when possible
 */
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Skip chrome-extension and other non-http(s) requests
  if (!request.url.startsWith('http')) return;

  // Handle different types of requests. Use destination + path-segment
  // tests (not substring) so `/images/` and `/img-anything/` don't match
  // the archive.org `/services/img/` thumbnail path.
  const isImg = request.destination === 'image'
                || /\/(img|services\/img|services\/img\/)\//.test(url.pathname);

  if (isImg) {
    event.respondWith(handleImageRequest(request));
  } else if (url.origin === location.origin && url.pathname.includes('/api/')) {
    // Handle local API requests with caching (supports subdirectory deployment)
    event.respondWith(handleApiRequest(request));
  } else if (url.origin === location.origin) {
    event.respondWith(handleAppRequest(request));
  } else if (url.hostname === 'archive.org' || url.hostname.endsWith('.archive.org')) {
    event.respondWith(handleArchiveRequest(request));
  }
  // Everything else (Google Fonts CSS, gstatic font files, third-party
  // analytics, etc.) is left for the browser to fetch directly. Routing
  // those through the SW would re-issue the request from script context,
  // which the page CSP `connect-src` blocks even when the original link
  // / @font-face request is allowed by `style-src` / `font-src`.
});

/**
 * Handle local API requests with intelligent caching
 */
async function handleApiRequest(request) {
  const url = new URL(request.url);
  // Extract just the filename from the path (supports subdirectory deployment)
  const pathParts = url.pathname.split('/');
  const endpoint = pathParts[pathParts.length - 1];

  // Endpoints that touch per-user data, auth state, or are explicitly
  // state-changing -- never cached. Anything not on the safelist below
  // also passes through without cache (default safe behavior).
  const noCachePaths = [
    '/api/auth/', '/api/bookmarks.php', '/api/history.php',
    '/api/user.php', '/api/collections.php', '/api/cache.php',
    '/api/stats.php', '/api/diagnose.php',
  ];
  if (noCachePaths.some(p => url.pathname.includes(p))) {
    return fetch(request);
  }

  // Determine cache strategy based on endpoint filename
  const cacheStrategies = {
    'search.php': { ttl: 5 * 60 * 1000, strategy: 'stale-while-revalidate' }, // 5 min, instant repeat searches
    'metadata.php': { ttl: 24 * 60 * 60 * 1000, strategy: 'cache-first' },    // 1 day, metadata rarely changes
    'metadata-batch.php': { ttl: 24 * 60 * 60 * 1000, strategy: 'stale-while-revalidate' },
    'thumbnail.php': { ttl: 365 * 24 * 60 * 60 * 1000, strategy: 'cache-first' }, // 1 year, immutable
    'settings.php': { ttl: 5 * 60 * 1000, strategy: 'stale-while-revalidate' },   // 5 min so admin edits show up promptly
    'recommendations.php': { ttl: 5 * 60 * 1000, strategy: 'stale-while-revalidate' },
    'sections.php': { ttl: 5 * 60 * 1000, strategy: 'stale-while-revalidate' },
  };

  const config = cacheStrategies[endpoint];
  if (!config) {
    // Unknown endpoint: pass through without cache. Safer default than
    // a blanket 5-minute network-first which can cache surprise data.
    return fetch(request);
  }

  try {
    switch (config.strategy) {
      case 'cache-first':
        return await handleCacheFirst(request, config.ttl);

      case 'stale-while-revalidate':
        return await handleStaleWhileRevalidate(request, config.ttl);

      case 'network-first':
      default:
        return await handleNetworkFirst(request, config.ttl);
    }
  } catch (error) {
    console.warn('[SW] API request failed:', error && error.name, url.pathname);

    // Try to return cached version — even past TTL is better than a synthetic 503.
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // No cache — bubble the actual failure up. The page-side code
    // distinguishes real network failure from "I gave up" by inspecting
    // the error name; a synthetic 503 here was confusing the offline
    // classifier and surfacing false "you are offline" messages.
    throw error;
  }
}

/**
 * Cache-first strategy: Use cache if available, otherwise fetch.
 * Timeout raised to 20s — cold metadata/thumbnail lookups against
 * archive.org legitimately take longer than 10s on shared hosting.
 */
async function handleCacheFirst(request, ttl) {
  const cachedResponse = await caches.match(request);

  if (cachedResponse) {
    const cacheAge = await getCacheAge(cachedResponse);
    if (cacheAge < ttl) {
      return cachedResponse;
    }
  }

  const url = new URL(request.url);
  const networkResponse = await fetchWithTimeout(request, 20000);

  if (await isCacheable(networkResponse, url)) {
    const cache = await caches.open(DYNAMIC_CACHE);
    cache.put(request, await stampCachedAt(networkResponse.clone()))
         .catch(() => {});
  }

  return networkResponse;
}

/**
 * Network-first strategy: Try network, fall back to cache.
 */
async function handleNetworkFirst(request, ttl) {
  const url = new URL(request.url);
  try {
    const networkResponse = await fetchWithTimeout(request, 20000);

    if (await isCacheable(networkResponse, url)) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, await stampCachedAt(networkResponse.clone()))
           .catch(() => {});
    }

    return networkResponse;
  } catch (error) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    throw error;
  }
}

/**
 * Stale-while-revalidate: Return cache immediately, update in background.
 *
 * If we have ANY cached copy we return it immediately and let the network
 * refresh happen non-blocking — even when the cache is past TTL. Better
 * to show slightly-stale results than to hang the user behind a slow
 * archive.org call.
 */
async function handleStaleWhileRevalidate(request, ttl) {
  const url = new URL(request.url);
  const cachedResponse = await caches.match(request);

  const fetchPromise = fetchWithTimeout(request, 20000)
    .then(async networkResponse => {
      if (await isCacheable(networkResponse, url)) {
        const cache = await caches.open(DYNAMIC_CACHE);
        cache.put(request, await stampCachedAt(networkResponse.clone()))
             .catch(err => console.warn('[SW] Cache put failed:', err));
      }
      return networkResponse;
    })
    .catch(err => {
      // Background refresh failure is non-fatal when we have cache. The
      // page will keep working off the stale entry and the next request
      // gets another shot at the network.
      if (!cachedResponse) console.warn('[SW] SWR network failed:', err && err.name);
      return null;
    });

  if (cachedResponse) {
    return cachedResponse;
  }

  const networkResponse = await fetchPromise;
  if (networkResponse) {
    return networkResponse;
  }

  throw new Error('No cached or network response available');
}

/**
 * Handle app requests (HTML, CSS, JS).
 *
 * - HTML: network-first. The server renders OG tags from the requested
 *   ?video=... query so a cache-first strategy would serve stale tags to
 *   social-media scrapers. Falls back to cache on offline.
 * - CSS/JS: cache-first with background refresh.
 */
async function handleAppRequest(request) {
  const accept = request.headers.get('accept') || '';
  const isHtml = accept.includes('text/html')
                 || request.destination === 'document';

  if (isHtml) {
    // Network-first for HTML so OG tags / settings updates / new pages
    // appear immediately. If network fails, fall back to cache. Only
    // serve offline.html on a real network error — a timeout against a
    // slow PHP backend is NOT the user being offline, and showing the
    // offline splash for a sluggish backend is the loudest false alarm
    // in the whole app.
    try {
      const networkResponse = await fetchWithTimeout(request, 15000);
      if (networkResponse.ok) {
        const cache = await caches.open(STATIC_CACHE);
        cache.put(request, await stampCachedAt(networkResponse.clone()))
             .catch(() => {});
      }
      return networkResponse;
    } catch (e) {
      const cachedResponse = await caches.match(request);
      if (cachedResponse) return cachedResponse;

      // Real network error (TypeError: Failed to fetch) → offline page.
      // Timeout (our own TimeoutError) → 504 so the browser shows its
      // own "site can't be reached" rather than the offline splash.
      if (e && e.name === 'TimeoutError') {
        return new Response('Upstream timed out. Please retry.', {
          status: 504,
          headers: { 'Content-Type': 'text/plain; charset=utf-8' },
        });
      }

      const offlinePage = await caches.match('./offline.html');
      if (offlinePage) return offlinePage;
      return new Response('Offline — please check your connection.', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    }
  }

  // Static asset: cache-first with background refresh.
  try {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      // Refresh the cache in the background so updated CSS/JS gets picked
      // up after the next reload without blocking this response.
      fetchAndUpdateCache(request, STATIC_CACHE);
      return cachedResponse;
    }
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, await stampCachedAt(networkResponse.clone()))
           .catch(() => {});
    }
    return networkResponse;
  } catch (error) {
    const offlinePage = await caches.match('./offline.html');
    if (offlinePage) return offlinePage;
    return new Response('Offline — please check your connection.', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
}

/**
 * Handle Archive.org API requests
 */
async function handleArchiveRequest(request) {
  const url = new URL(request.url);
  
  // Cache search results and metadata
  if (url.pathname.includes('/advancedsearch.php') || url.pathname.includes('/metadata/')) {
    try {
      // Check cache first
      const cachedResponse = await caches.match(request);
      if (cachedResponse) {
        const cacheAge = await getCacheAge(cachedResponse);
        
        // Use cache if less than 1 hour old
        if (cacheAge < 60 * 60 * 1000) {
          return cachedResponse;
        }
      }

      // Network request with timeout — archive.org search/metadata can
      // legitimately take >10s on a cold path, so 20s buys headroom.
      const networkResponse = await fetchWithTimeout(request, 20000);

      if (await isCacheable(networkResponse, url)) {
        const cache = await caches.open(DYNAMIC_CACHE);
        cache.put(request, await stampCachedAt(networkResponse.clone()))
             .catch(() => {});

        trimCache(DYNAMIC_CACHE, CACHE_LIMITS.dynamic);
      }

      return networkResponse;
    } catch (error) {
      console.warn('[SW] Archive request failed:', error && error.name, request.url);

      // Return cached version if available — even past TTL.
      const cachedResponse = await caches.match(request);
      if (cachedResponse) {
        return cachedResponse;
      }

      // No cache. Bubble the failure up — let the page-side fetch handler
      // decide what to show. Returning a fake 503 here would cause the
      // page to treat archive.org's slowness as a hard offline state.
      throw error;
    }
  }
  
  // Don't cache video files
  return fetch(request);
}

/**
 * Handle image requests with aggressive caching
 *
 * Thumbnails are immutable — once we have one for a given URL, the upstream
 * never changes. Cache forever (subject to LRU trim) and never revalidate.
 */
async function handleImageRequest(request) {
  try {
    // Check cache first - if hit, return instantly without touching the network
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // Network request. Follow redirects so archive.org URLs that 302 to a CDN
    // get cached at the archive.org URL the page actually requested. 15s
    // because archive.org thumbnail service is slow under load and a missed
    // thumbnail is way more annoying than a few extra seconds of placeholder.
    const networkResponse = await fetchWithTimeout(request, 15000);

    // Cache successful image responses (and 304s, which the browser will hydrate)
    if (networkResponse.ok || networkResponse.status === 304) {
      const cache = await caches.open(IMAGE_CACHE);
      // .clone() because the response body is a stream that can only be read once
      cache.put(request, await stampCachedAt(networkResponse.clone())).catch(() => {});

      // Trim cache asynchronously; don't block the response
      trimCache(IMAGE_CACHE, CACHE_LIMITS.images);
    }

    return networkResponse;
  } catch (error) {
    console.error('[SW] Image request failed:', error);

    // Return placeholder image if available
    const placeholderImage = await caches.match('./images/placeholder.png');
    if (placeholderImage) return placeholderImage;

    // Return a 1x1 transparent PNG as ultimate fallback
    return new Response(
      atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
      {
        headers: {
          'Content-Type': 'image/png',
          'Cache-Control': 'no-store'
        }
      }
    );
  }
}

/**
 * Fetch with a real timeout. The previous implementation raced fetch()
 * against a setTimeout reject — but the underlying fetch kept running in
 * the background, which (a) wasted the browser's per-origin connection
 * slot and (b) surfaced to the calling page as "TypeError: Failed to
 * fetch" because the SW response stream was discarded mid-flight. The
 * page code mis-classified that as offline.
 *
 * Now we use a real AbortController so the underlying request is cleanly
 * cancelled, the rejection is a named TimeoutError the caller can
 * distinguish from a real network failure, and downstream cache.put()
 * never fires for partial bodies.
 */
function fetchWithTimeout(request, timeout = 15000) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort('sw-timeout'), timeout);

  // Some callers pass a Request object. Re-issue with our signal so we
  // can actually cancel it. Cloning preserves method/headers/body.
  const init = { signal: controller.signal };
  const reqLike = (request instanceof Request) ? new Request(request, init) : request;
  const promise = (request instanceof Request)
    ? fetch(reqLike)
    : fetch(request, init);

  return promise
    .then(res => { clearTimeout(t); return res; })
    .catch(err => {
      clearTimeout(t);
      if (err && err.name === 'AbortError') {
        const e = new Error('Request timed out');
        e.name = 'TimeoutError';
        throw e;
      }
      throw err;
    });
}

/**
 * Should this response be written to cache? Used to guard against polluting
 * caches with empty result sets, server errors, or app-level failures that
 * happen to come back as HTTP 200. Without this guard, an upstream 5xx or
 * an empty-query 200 would get cached under SWR and serve junk for the
 * next several minutes.
 */
async function isCacheable(response, url) {
  if (!response) return false;
  if (!response.ok) return false;
  if (response.status >= 500) return false;
  // archive.org/thumbnail-like binary responses can't be peeked safely.
  // Only sniff JSON responses on our own API.
  const ct = response.headers.get('content-type') || '';
  if (!ct.includes('json')) return true;
  try {
    const peek = await response.clone().text();
    if (!peek) return false;
    // Cheap shape check — avoid full parse on big payloads.
    if (peek.includes('"success":false')) return false;
    // Empty search results carry "numFound":0 + "docs":[] — caching this
    // would serve "no results" to a later identical search even after the
    // upstream has fresh data. Better to let it miss-then-revalidate.
    if (url && url.pathname && url.pathname.endsWith('/search.php')
        && peek.includes('"numFound":0')) {
      return false;
    }
  } catch {
    // If we can't peek, default to caching — better than dropping good data.
    return true;
  }
  return true;
}

/**
 * Fetch and update cache in background
 */
async function fetchAndUpdateCache(request, cacheName) {
  try {
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, await stampCachedAt(networkResponse));
    }
  } catch (error) {
    // Background refresh failure is non-fatal; the previous cached copy
    // is still serving requests.
  }
}

/**
 * Get cache age from response headers.
 *
 * Prefers our own `sw-cached-at` stamp (set by stampCachedAt before storing
 * in any cache). Falls back to the upstream `date` header. Returns 0 (treat
 * as fresh) when neither is present rather than Infinity (treat as stale),
 * because Infinity caused cache-first paths to ALWAYS refetch, defeating
 * the whole TTL-based strategy when upstream omits the Date header (which
 * archive.org and PHP often do).
 */
async function getCacheAge(response) {
  const stamped = response.headers.get(CACHED_AT_HEADER);
  if (stamped) {
    const t = parseInt(stamped, 10);
    if (Number.isFinite(t)) return Date.now() - t;
  }
  const date = response.headers.get('date');
  if (date) {
    const t = new Date(date).getTime();
    if (!Number.isNaN(t)) return Date.now() - t;
  }
  return 0;
}

/**
 * Return a copy of the response with a sw-cached-at header set to the
 * current epoch time in ms. Use BEFORE cache.put() so the stamp is
 * available on later reads.
 */
async function stampCachedAt(response) {
  if (!response || !response.body) return response;
  // Some responses (opaque, 204, etc.) can't have their body cloned, so
  // we fall back to returning the original. Cache key will then just
  // miss the stamp and fall back to upstream `date` or "fresh".
  try {
    const blob = await response.blob();
    const headers = new Headers(response.headers);
    headers.set(CACHED_AT_HEADER, String(Date.now()));
    return new Response(blob, {
      status: response.status,
      statusText: response.statusText,
      headers,
    });
  } catch (_) {
    return response;
  }
}

/**
 * Trim cache to specified limit
 */
async function trimCache(cacheName, limit) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  
  if (keys.length > limit) {
    const keysToDelete = keys.slice(0, keys.length - limit);
    await Promise.all(
      keysToDelete.map(key => cache.delete(key))
    );
    console.log(`[SW] Trimmed ${keysToDelete.length} items from ${cacheName}`);
  }
}

/**
 * Message handler for cache control
 */
self.addEventListener('message', event => {
  const { action, data } = event.data;
  
  switch (action) {
    case 'SKIP_WAITING':
      self.skipWaiting();
      break;
      
    case 'CLEAR_CACHE':
      event.waitUntil(
        caches.keys()
          .then(names => Promise.all(names.map(name => caches.delete(name))))
          .then(() => {
            if (event.ports && event.ports[0]) {
              event.ports[0].postMessage({ success: true });
            }
          })
          .catch(error => {
            if (event.ports && event.ports[0]) {
              event.ports[0].postMessage({ success: false, error: error.message });
            }
          })
      );
      break;
      
    case 'CACHE_VIDEO':
      // Pre-cache a video for offline viewing
      if (data && data.url) {
        event.waitUntil(
          caches.open(DYNAMIC_CACHE)
            .then(cache => cache.add(data.url))
            .then(() => {
              if (event.ports && event.ports[0]) {
                event.ports[0].postMessage({ success: true });
              }
            })
            .catch(error => {
              if (event.ports && event.ports[0]) {
                event.ports[0].postMessage({ success: false, error: error.message });
              }
            })
        );
      }
      break;
      
    case 'GET_CACHE_SIZE':
      event.waitUntil(
        getCacheSize()
          .then(size => {
            if (event.ports && event.ports[0]) {
              event.ports[0].postMessage({ size });
            }
          })
          .catch(error => {
            if (event.ports && event.ports[0]) {
              event.ports[0].postMessage({ size: 0 });
            }
          })
      );
      break;
  }
});

/**
 * Calculate total cache size.
 *
 * Prefers navigator.storage.estimate() which returns a fast, accurate
 * quota-level estimate (and includes IndexedDB etc.). Walking every
 * cache entry blob-by-blob is O(GB) and locks the SW thread.
 */
async function getCacheSize() {
  if (self.navigator && self.navigator.storage && self.navigator.storage.estimate) {
    try {
      const est = await self.navigator.storage.estimate();
      return est.usage || 0;
    } catch (_) { /* fall through to manual count */ }
  }

  // Fallback: count Content-Length headers (much faster than reading
  // blobs and ~accurate for our static + image caches).
  const cacheNames = await caches.keys();
  let totalSize = 0;
  for (const name of cacheNames) {
    const cache = await caches.open(name);
    const keys = await cache.keys();
    for (const request of keys) {
      const response = await cache.match(request);
      if (response) {
        const len = parseInt(response.headers.get('content-length') || '0', 10);
        if (Number.isFinite(len)) totalSize += len;
      }
    }
  }
  return totalSize;
}

// Note: a background-sync handler was removed -- the prior `syncBookmarks`
// and `syncProgress` callbacks were stubs that just console.log'd. The
// real sync now happens inline via the BookmarkManager and
// VideoProgressTracker JS modules, which call /api/* directly when the
// user is signed in. If we ever re-introduce true background sync,
// register the 'sync' event listener again here.
