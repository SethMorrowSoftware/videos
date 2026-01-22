/**
 * Service Worker for Comet Cult Film Club
 * Version: ALPHA-1.0.0
 * Features: Offline support, intelligent caching, background sync
 */

const CACHE_VERSION = 'ccfc-alpha-v1';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const DYNAMIC_CACHE = `${CACHE_VERSION}-dynamic`;
const IMAGE_CACHE = `${CACHE_VERSION}-images`;

// Static assets to cache on install (relative paths for subdirectory support)
const STATIC_ASSETS = [
  './',
  './index.html',
  './styles.css',
  './app.js',
  './manifest.json',
  'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap'
];

// Cache size limits
const CACHE_LIMITS = {
  static: 50,
  dynamic: 100,
  images: 200
};

// Cache expiration times (in milliseconds)
const CACHE_EXPIRY = {
  static: 7 * 24 * 60 * 60 * 1000,  // 7 days
  dynamic: 24 * 60 * 60 * 1000,     // 1 day
  images: 30 * 24 * 60 * 60 * 1000  // 30 days
};

/**
 * Install event - cache static assets
 */
self.addEventListener('install', event => {
  console.log('[SW] Installing service worker...');
  
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => {
        console.log('[SW] Pre-caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting())
      .catch(err => {
        console.error('[SW] Failed to pre-cache:', err);
      })
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

  // Handle different types of requests
  if (request.destination === 'image' || url.pathname.includes('/img/')) {
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

  // Determine cache strategy based on endpoint filename
  const cacheStrategies = {
    'search.php': { ttl: 5 * 60 * 1000, strategy: 'network-first' },      // 5 min
    'metadata.php': { ttl: 60 * 60 * 1000, strategy: 'cache-first' },     // 1 hour
    'thumbnail.php': { ttl: 7 * 24 * 60 * 60 * 1000, strategy: 'cache-first' }, // 7 days
    'settings.php': { ttl: 60 * 60 * 1000, strategy: 'stale-while-revalidate' }, // 1 hour
    'recommendations.php': { ttl: 60 * 60 * 1000, strategy: 'stale-while-revalidate' },
    'sections.php': { ttl: 60 * 60 * 1000, strategy: 'stale-while-revalidate' },
  };

  const config = cacheStrategies[endpoint] || { ttl: 5 * 60 * 1000, strategy: 'network-first' };

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
    cache.put(request, networkResponse.clone());
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
      cache.put(request, networkResponse.clone());
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
    .then(networkResponse => {
      if (networkResponse.ok) {
        caches.open(DYNAMIC_CACHE).then(cache => {
          cache.put(request, networkResponse.clone());
        });
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
 * Handle app requests (HTML, CSS, JS)
 */
async function handleAppRequest(request) {
  try {
    // Try cache first
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      // Update cache in background for HTML files
      if (request.headers.get('accept')?.includes('text/html')) {
        fetchAndUpdateCache(request, STATIC_CACHE);
      }
      return cachedResponse;
    }

    // Network fallback
    const networkResponse = await fetch(request);
    
    // Cache successful responses
    if (networkResponse.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    console.error('[SW] App request failed:', error);
    
    // Return offline page if available
    const offlinePage = await caches.match('./offline.html');
    if (offlinePage) return offlinePage;
    
    // Fallback to a basic offline response
    return new Response('Offline - Please check your connection', {
      status: 503,
      headers: { 'Content-Type': 'text/plain' }
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
        cache.put(request, networkResponse.clone());
        
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
 */
async function handleImageRequest(request) {
  try {
    // Check cache first
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // Network request
    const networkResponse = await fetchWithTimeout(request, 5000);
    
    // Cache successful image responses
    if (networkResponse.ok) {
      const cache = await caches.open(IMAGE_CACHE);
      cache.put(request, networkResponse.clone());
      
      // Trim cache if needed
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
      cache.put(request, networkResponse.clone());
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
      cache.put(request, networkResponse);
    }
  } catch (error) {
    console.error('[SW] Background update failed:', error);
  }
}

/**
 * Get cache age from response headers
 */
async function getCacheAge(response) {
  const date = response.headers.get('date');
  if (!date) return Infinity;
  
  const responseTime = new Date(date).getTime();
  const now = Date.now();
  return now - responseTime;
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
            event.ports[0].postMessage({ success: true });
          })
          .catch(error => {
            event.ports[0].postMessage({ success: false, error: error.message });
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
              event.ports[0].postMessage({ success: true });
            })
            .catch(error => {
              event.ports[0].postMessage({ success: false, error: error.message });
            })
        );
      }
      break;
      
    case 'GET_CACHE_SIZE':
      event.waitUntil(
        getCacheSize()
          .then(size => {
            event.ports[0].postMessage({ size });
          })
      );
      break;
  }
});

/**
 * Calculate total cache size
 */
async function getCacheSize() {
  const cacheNames = await caches.keys();
  let totalSize = 0;
  
  for (const name of cacheNames) {
    const cache = await caches.open(name);
    const keys = await cache.keys();
    
    for (const request of keys) {
      const response = await cache.match(request);
      if (response) {
        const blob = await response.blob();
        totalSize += blob.size;
      }
    }
  }
  
  return totalSize;
}

/**
 * Background sync for bookmarks and progress
 */
self.addEventListener('sync', event => {
  if (event.tag === 'sync-bookmarks') {
    event.waitUntil(syncBookmarks());
  } else if (event.tag === 'sync-progress') {
    event.waitUntil(syncProgress());
  }
});

/**
 * Sync bookmarks with server (when implemented)
 */
async function syncBookmarks() {
  console.log('[SW] Syncing bookmarks...');
  // This would sync with a backend service when available
  // For now, bookmarks are stored in localStorage only
}

/**
 * Sync watch progress with server (when implemented)
 */
async function syncProgress() {
  console.log('[SW] Syncing watch progress...');
  // This would sync with a backend service when available
  // For now, progress is stored in localStorage only
}

console.log('[SW] Service worker loaded');