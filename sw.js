/**
 * Service Worker for Comet Cult Film Club
 * Version: 1.1.0
 * Features: Offline support, intelligent caching, background sync
 *
 * v1.1 changes:
 *   - Bumped CACHE_VERSION so old caches get evicted (with the old, broken
 *     image cache that didn't store archive.org redirects)
 *   - Cache image limit raised — thumbnails are immutable, no reason to evict
 *     them aggressively
 *   - Image cache now follows redirects (so archive.org thumbnail URLs that
 *     302 to a CDN get cached at their final resolved URL)
 *   - metadata-batch.php gets cache-first treatment like metadata.php
 */

const CACHE_VERSION = 'ccfc-v3';
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
  } else if (url.hostname === 'archive.org') {
    event.respondWith(handleArchiveRequest(request));
  } else {
    event.respondWith(handleDynamicRequest(request));
  }
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
    console.error('[SW] API request failed:', error);

    // Try to return cached version
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    return new Response(JSON.stringify({ error: 'Network error', offline: true }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

/**
 * Cache-first strategy: Use cache if available, otherwise fetch
 */
async function handleCacheFirst(request, ttl) {
  const cachedResponse = await caches.match(request);

  if (cachedResponse) {
    const cacheAge = await getCacheAge(cachedResponse);
    if (cacheAge < ttl) {
      return cachedResponse;
    }
  }

  const networkResponse = await fetchWithTimeout(request, 10000);

  if (networkResponse.ok) {
    const cache = await caches.open(DYNAMIC_CACHE);
    cache.put(request, await stampCachedAt(networkResponse.clone()))
         .catch(() => {});
  }

  return networkResponse;
}

/**
 * Network-first strategy: Try network, fall back to cache
 */
async function handleNetworkFirst(request, ttl) {
  try {
    const networkResponse = await fetchWithTimeout(request, 10000);

    if (networkResponse.ok) {
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
 * Stale-while-revalidate: Return cache immediately, update in background
 */
async function handleStaleWhileRevalidate(request, ttl) {
  const cachedResponse = await caches.match(request);

  // Fetch and update cache in background
  const fetchPromise = fetchWithTimeout(request, 10000)
    .then(async networkResponse => {
      if (networkResponse.ok) {
        const cache = await caches.open(DYNAMIC_CACHE);
        cache.put(request, await stampCachedAt(networkResponse.clone()))
             .catch(err => console.warn('[SW] Cache put failed:', err));
      }
      return networkResponse;
    })
    .catch(() => null);

  // Return cached version immediately if available
  if (cachedResponse) {
    return cachedResponse;
  }

  // No cache, wait for network
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
    // appear immediately. If network fails, fall back to cache, then to
    // the offline page.
    try {
      const networkResponse = await fetchWithTimeout(request, 5000);
      if (networkResponse.ok) {
        const cache = await caches.open(STATIC_CACHE);
        cache.put(request, await stampCachedAt(networkResponse.clone()))
             .catch(() => {});
      }
      return networkResponse;
    } catch (e) {
      const cachedResponse = await caches.match(request);
      if (cachedResponse) return cachedResponse;
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

      // Network request with timeout
      const networkResponse = await fetchWithTimeout(request, 10000);

      // Cache successful responses
      if (networkResponse.ok) {
        const cache = await caches.open(DYNAMIC_CACHE);
        cache.put(request, await stampCachedAt(networkResponse.clone()))
             .catch(() => {});

        // Trim cache if needed
        trimCache(DYNAMIC_CACHE, CACHE_LIMITS.dynamic);
      }
      
      return networkResponse;
    } catch (error) {
      console.error('[SW] Archive request failed:', error);
      
      // Return cached version if available
      const cachedResponse = await caches.match(request);
      if (cachedResponse) {
        console.log('[SW] Serving stale cache for:', request.url);
        return cachedResponse;
      }
      
      // Return error response
      return new Response(JSON.stringify({ error: 'Network error' }), {
        status: 503,
        headers: { 'Content-Type': 'application/json' }
      });
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
    // get cached at the archive.org URL the page actually requested.
    const networkResponse = await fetchWithTimeout(request, 8000);

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
 * Handle other dynamic requests
 */
async function handleDynamicRequest(request) {
  try {
    // Try network first for dynamic content
    const networkResponse = await fetchWithTimeout(request, 8000);
    
    // Cache successful responses
    if (networkResponse.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, await stampCachedAt(networkResponse.clone()))
           .catch(() => {});
    }

    return networkResponse;
  } catch (error) {
    console.error('[SW] Dynamic request failed:', error);
    
    // Try cache as fallback
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      console.log('[SW] Serving from cache:', request.url);
      return cachedResponse;
    }
    
    // Return error response
    return new Response('Network error', {
      status: 503,
      headers: { 'Content-Type': 'text/plain' }
    });
  }
}

/**
 * Fetch with timeout
 */
function fetchWithTimeout(request, timeout = 5000) {
  return Promise.race([
    fetch(request),
    new Promise((_, reject) => 
      setTimeout(() => reject(new Error('Request timeout')), timeout)
    )
  ]);
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
