# MySQL Migration & Caching Implementation Plan

## Executive Summary

This plan outlines the conversion from a JSON file-based storage system to a MySQL database with a comprehensive caching layer to improve loading times and reduce Archive.org API usage.

---

## Phase 1: MySQL Database Setup & Schema Design

### 1.1 Database Schema

```sql
-- Core Configuration Tables
CREATE TABLE site_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'boolean', 'number', 'json') DEFAULT 'string',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    role ENUM('admin', 'editor') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Featured Content Tables
CREATE TABLE recommended_videos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    creator VARCHAR(255),
    description TEXT,
    thumbnail_url VARCHAR(500),
    display_order INT DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    added_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_enabled_order (enabled, display_order),
    FOREIGN KEY (added_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE featured_sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    section_id VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    enabled TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled_order (enabled, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE featured_section_videos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    section_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    creator VARCHAR(255),
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_section_video (section_id, archive_id),
    INDEX idx_section_order (section_id, display_order),
    FOREIGN KEY (section_id) REFERENCES featured_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Caching Tables
CREATE TABLE search_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cache_key VARCHAR(64) NOT NULL,  -- SHA256 hash of query params
    query_params JSON NOT NULL,       -- Original query parameters
    response_data LONGTEXT NOT NULL,  -- Cached API response (JSON)
    result_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    hit_count INT DEFAULT 0,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cache_key (cache_key),
    INDEX idx_expires (expires_at),
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE video_metadata_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    description TEXT,
    creator VARCHAR(255),
    date VARCHAR(100),
    runtime VARCHAR(50),
    mediatype VARCHAR(50),
    downloads INT DEFAULT 0,
    license_url VARCHAR(500),
    subject TEXT,                     -- Comma-separated tags
    files_json LONGTEXT,              -- Cached files array (for video URLs)
    thumbnail_cached TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_expires (expires_at),
    INDEX idx_downloads (downloads DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE thumbnail_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    original_url VARCHAR(500),
    local_path VARCHAR(500),          -- Path to cached thumbnail
    file_size INT,
    width INT,
    height INT,
    mime_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    access_count INT DEFAULT 0,
    UNIQUE KEY uk_archive_id (archive_id),
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Data Tables (for persistent user features)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(64) UNIQUE,    -- Anonymous session tracking
    user_agent VARCHAR(500),
    ip_hash VARCHAR(64),              -- Hashed IP for privacy
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    preferences JSON,                 -- Theme, sort, collection prefs
    INDEX idx_session (session_id),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_bookmarks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    title VARCHAR(500),
    creator VARCHAR(255),
    thumbnail_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_bookmark (user_id, archive_id),
    INDEX idx_user_created (user_id, created_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_watch_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    archive_id VARCHAR(255) NOT NULL,
    current_time DECIMAL(10,2) DEFAULT 0,
    duration DECIMAL(10,2) DEFAULT 0,
    progress_percent DECIMAL(5,2) DEFAULT 0,
    last_watched TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    watch_count INT DEFAULT 1,
    UNIQUE KEY uk_user_video (user_id, archive_id),
    INDEX idx_user_recent (user_id, last_watched DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE search_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    query VARCHAR(500) NOT NULL,
    filters JSON,                     -- Collection, sort, etc.
    result_count INT,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_recent (user_id, searched_at DESC),
    INDEX idx_query (query(100)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Analytics Tables (optional but useful)
CREATE TABLE popular_searches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    query VARCHAR(500) NOT NULL,
    query_hash VARCHAR(64) NOT NULL,
    search_count INT DEFAULT 1,
    last_searched TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_query_hash (query_hash),
    INDEX idx_count (search_count DESC),
    INDEX idx_recent (last_searched DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_usage_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    endpoint VARCHAR(100),
    cache_hit TINYINT(1) DEFAULT 0,
    response_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_endpoint (endpoint, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 1.2 Files to Create

| File | Purpose |
|------|---------|
| `db/config.php` | Database connection configuration |
| `db/Database.php` | PDO database wrapper class |
| `db/migrations/001_initial_schema.sql` | Initial schema migration |
| `db/seeds/seed_settings.php` | Seed default settings |

### 1.3 Implementation Steps

1. Create `db/config.php` with environment-based configuration
2. Create `Database.php` singleton class with PDO connection pooling
3. Run initial schema migration
4. Migrate existing JSON data to MySQL tables
5. Update all PHP endpoints to use database

---

## Phase 2: PHP Backend Restructuring

### 2.1 New Directory Structure

```
/home/user/videos/
├── api/                          # New API endpoints
│   ├── index.php                 # API router
│   ├── search.php                # Cached search endpoint
│   ├── metadata.php              # Video metadata endpoint
│   ├── thumbnail.php             # Thumbnail proxy/cache
│   ├── settings.php              # Site settings CRUD
│   ├── recommendations.php       # Recommendations CRUD
│   ├── sections.php              # Featured sections CRUD
│   ├── user.php                  # User session/preferences
│   ├── bookmarks.php             # User bookmarks CRUD
│   └── history.php               # Watch history CRUD
├── db/
│   ├── config.php                # DB configuration
│   ├── Database.php              # Database class
│   └── migrations/               # Schema migrations
├── cache/
│   ├── CacheManager.php          # Unified cache manager
│   ├── SearchCache.php           # Search result caching
│   ├── MetadataCache.php         # Video metadata caching
│   └── ThumbnailCache.php        # Thumbnail caching
├── services/
│   ├── ArchiveOrgService.php     # Archive.org API wrapper
│   ├── SettingsService.php       # Settings management
│   └── UserService.php           # User session management
├── thumbnails/                   # Cached thumbnail storage
│   └── .htaccess                 # Serve with proper headers
├── index.php                     # Main page
├── admin.php                     # Admin panel
└── .env                          # Environment variables
```

### 2.2 Key PHP Classes

#### Database Class (`db/Database.php`)
```php
<?php
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $config = require __DIR__ . '/config.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Additional methods: insert, update, delete, transaction support
}
```

#### Cache Manager (`cache/CacheManager.php`)
```php
<?php
class CacheManager {
    private $db;

    // Cache TTL settings (in seconds)
    const SEARCH_TTL = 1800;      // 30 minutes
    const METADATA_TTL = 86400;   // 24 hours
    const THUMBNAIL_TTL = 604800; // 7 days

    public function getSearchCache(string $cacheKey): ?array;
    public function setSearchCache(string $cacheKey, array $params, array $data): void;
    public function getMetadataCache(string $archiveId): ?array;
    public function setMetadataCache(string $archiveId, array $data): void;
    public function getThumbnailPath(string $archiveId): ?string;
    public function cacheThumbnail(string $archiveId, string $url): string;
    public function cleanExpiredCache(): int;
}
```

### 2.3 API Endpoints Design

| Endpoint | Method | Purpose | Cache TTL |
|----------|--------|---------|-----------|
| `GET /api/search.php` | GET | Cached search proxy | 30 min |
| `GET /api/metadata.php?id=X` | GET | Video metadata | 24 hours |
| `GET /api/thumbnail.php?id=X` | GET | Thumbnail proxy | 7 days |
| `GET /api/settings.php` | GET | Get site settings | 1 hour |
| `POST /api/settings.php` | POST | Update settings | - |
| `GET /api/recommendations.php` | GET | Get recommendations | 1 hour |
| `POST /api/recommendations.php` | POST | Update recommendations | - |
| `GET /api/user.php` | GET | Get/create user session | - |
| `GET /api/bookmarks.php` | GET | Get user bookmarks | - |
| `POST /api/bookmarks.php` | POST | Add/remove bookmark | - |
| `GET /api/history.php` | GET | Get watch history | - |
| `POST /api/history.php` | POST | Update progress | - |

---

## Phase 3: Caching Architecture

### 3.1 Multi-Layer Caching Strategy

```
┌─────────────────────────────────────────────────────────────────┐
│                        Client Browser                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ LocalStorage │  │ SessionStore │  │ Service Worker│          │
│  │ (User prefs) │  │ (Search hist)│  │ (Static assets)│         │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       PHP Backend                               │
│  ┌──────────────────────────────────────────────────────┐      │
│  │              In-Memory Cache (APCu)                  │      │
│  │         Hot data: settings, popular searches         │      │
│  │                    TTL: 5 minutes                    │      │
│  └──────────────────────────────────────────────────────┘      │
│                              │                                  │
│                              ▼                                  │
│  ┌──────────────────────────────────────────────────────┐      │
│  │              MySQL Cache Tables                       │      │
│  │  ┌────────────┐ ┌────────────┐ ┌────────────┐       │      │
│  │  │  search_   │ │ metadata_  │ │ thumbnail_ │       │      │
│  │  │   cache    │ │   cache    │ │   cache    │       │      │
│  │  │  30 min    │ │  24 hours  │ │   7 days   │       │      │
│  │  └────────────┘ └────────────┘ └────────────┘       │      │
│  └──────────────────────────────────────────────────────┘      │
│                              │                                  │
│                              ▼                                  │
│  ┌──────────────────────────────────────────────────────┐      │
│  │              File System Cache                        │      │
│  │         Thumbnails: /thumbnails/{id}.jpg              │      │
│  │               Served via Apache/Nginx                 │      │
│  └──────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Archive.org API                             │
│            (Only called on cache miss)                          │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Cache Invalidation Rules

| Cache Type | TTL | Invalidation Trigger |
|------------|-----|---------------------|
| Search results | 30 min | Auto-expire |
| Video metadata | 24 hours | Auto-expire or manual refresh |
| Thumbnails | 7 days | Never (immutable content) |
| Site settings | 1 hour | On admin update |
| User preferences | Session | On user change |
| Popular searches | 1 hour | Auto-refresh from analytics |

### 3.3 Cache Warming Strategy

```php
// Scheduled cron job: cache_warmer.php
// Runs every 30 minutes

class CacheWarmer {
    public function warmPopularSearches(): void {
        // Pre-fetch top 20 popular searches
        $popular = $this->db->query(
            "SELECT query, filters FROM popular_searches
             ORDER BY search_count DESC LIMIT 20"
        );

        foreach ($popular as $search) {
            $this->searchCache->warmCache($search['query'], $search['filters']);
        }
    }

    public function warmFeaturedContent(): void {
        // Pre-fetch metadata for all featured videos
        $featured = $this->db->query(
            "SELECT archive_id FROM recommended_videos WHERE enabled = 1
             UNION
             SELECT archive_id FROM featured_section_videos"
        );

        foreach ($featured as $video) {
            $this->metadataCache->warmCache($video['archive_id']);
            $this->thumbnailCache->warmCache($video['archive_id']);
        }
    }
}
```

---

## Phase 4: Frontend Updates

### 4.1 Updated Service Layer

#### New `src/js/services/ApiService.js`
```javascript
// Unified API service for all backend calls
export class ApiService {
    static BASE_URL = '/api';

    static async search(query, options = {}) {
        const params = new URLSearchParams({
            q: query,
            page: options.page || 1,
            collection: options.collection || 'all_videos',
            sort: options.sort || 'downloads',
            ...options
        });

        const response = await fetch(`${this.BASE_URL}/search.php?${params}`);
        const data = await response.json();

        // Response includes cache metadata
        return {
            results: data.results,
            total: data.total,
            cached: data.cached,      // Was this from cache?
            cacheAge: data.cache_age  // How old is the cache?
        };
    }

    static async getMetadata(archiveId) {
        const response = await fetch(`${this.BASE_URL}/metadata.php?id=${archiveId}`);
        return response.json();
    }

    static async getThumbnailUrl(archiveId) {
        // Returns local cached URL or falls back to Archive.org
        return `${this.BASE_URL}/thumbnail.php?id=${archiveId}`;
    }

    static async syncBookmarks(bookmarks) {
        // Sync local bookmarks with server
    }

    static async syncWatchHistory(history) {
        // Sync watch progress with server
    }
}
```

#### Updated `src/js/services/SearchCache.js`
```javascript
// Enhanced client-side cache with server sync
export class SearchCache {
    constructor() {
        this.memoryCache = new Map();
        this.maxMemoryEntries = 50;
        this.memoryTTL = 5 * 60 * 1000; // 5 minutes local
    }

    async get(params) {
        const key = this.generateKey(params);

        // Check memory cache first
        const memoryResult = this.memoryCache.get(key);
        if (memoryResult && !this.isExpired(memoryResult)) {
            return { ...memoryResult.data, source: 'memory' };
        }

        // Fetch from server (server handles its own caching)
        const response = await ApiService.search(params.query, params);

        // Store in memory cache
        this.memoryCache.set(key, {
            data: response,
            timestamp: Date.now()
        });

        return { ...response, source: response.cached ? 'server-cache' : 'api' };
    }
}
```

### 4.2 Thumbnail Lazy Loading Enhancement

```javascript
// Enhanced thumbnail loading with cache-aware URLs
export class ThumbnailLoader {
    static createThumbnail(archiveId, altText) {
        const img = document.createElement('img');

        // Use cached thumbnail endpoint
        img.src = `/api/thumbnail.php?id=${encodeURIComponent(archiveId)}`;
        img.alt = altText;
        img.loading = 'lazy';
        img.decoding = 'async';

        // Intersection observer for visibility tracking
        this.observeThumbnail(img, archiveId);

        img.onerror = () => {
            img.src = '/assets/placeholder.svg';
        };

        return img;
    }

    static observeThumbnail(img, archiveId) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Track thumbnail view for analytics
                    this.trackView(archiveId);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '100px' });

        observer.observe(img);
    }
}
```

### 4.3 Prefetching Strategy

```javascript
// Intelligent prefetching based on user behavior
export class Prefetcher {
    constructor() {
        this.prefetchQueue = new Set();
        this.observer = null;
        this.initObserver();
    }

    initObserver() {
        // Prefetch thumbnails and metadata for visible + nearby items
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const archiveId = entry.target.dataset.archiveId;
                    this.prefetchMetadata(archiveId);
                }
            });
        }, { rootMargin: '200px' }); // Start prefetching 200px before visible
    }

    async prefetchMetadata(archiveId) {
        if (this.prefetchQueue.has(archiveId)) return;
        this.prefetchQueue.add(archiveId);

        // Low priority fetch
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = `/api/metadata.php?id=${archiveId}`;
        document.head.appendChild(link);
    }

    prefetchSearchResults(query) {
        // Prefetch next page of results
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = `/api/search.php?q=${encodeURIComponent(query)}&page=2`;
        document.head.appendChild(link);
    }
}
```

---

## Phase 5: Service Worker Enhancements

### 5.1 Updated Service Worker Strategy

```javascript
// sw.js - Enhanced caching strategies
const CACHE_VERSIONS = {
    static: 'static-v2',
    api: 'api-v1',
    thumbnails: 'thumbnails-v1'
};

// Cache strategies per request type
const strategies = {
    // Static assets: Cache first, network fallback
    static: async (request) => {
        const cached = await caches.match(request);
        if (cached) return cached;

        const response = await fetch(request);
        const cache = await caches.open(CACHE_VERSIONS.static);
        cache.put(request, response.clone());
        return response;
    },

    // API calls: Network first, cache fallback
    api: async (request) => {
        try {
            const response = await fetch(request);
            const cache = await caches.open(CACHE_VERSIONS.api);
            cache.put(request, response.clone());
            return response;
        } catch (error) {
            return caches.match(request);
        }
    },

    // Thumbnails: Stale-while-revalidate
    thumbnails: async (request) => {
        const cache = await caches.open(CACHE_VERSIONS.thumbnails);
        const cached = await cache.match(request);

        const fetchPromise = fetch(request).then(response => {
            cache.put(request, response.clone());
            return response;
        });

        return cached || fetchPromise;
    }
};

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (url.pathname.startsWith('/api/thumbnail.php')) {
        event.respondWith(strategies.thumbnails(event.request));
    } else if (url.pathname.startsWith('/api/')) {
        event.respondWith(strategies.api(event.request));
    } else if (url.pathname.match(/\.(js|css|png|svg|woff2?)$/)) {
        event.respondWith(strategies.static(event.request));
    }
});
```

---

## Phase 6: Performance Optimizations

### 6.1 Database Optimizations

```sql
-- Partitioning for large tables
ALTER TABLE search_cache PARTITION BY RANGE (UNIX_TIMESTAMP(expires_at)) (
    PARTITION p_current VALUES LESS THAN (UNIX_TIMESTAMP(NOW() + INTERVAL 1 DAY)),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Scheduled cleanup job
CREATE EVENT clean_expired_cache
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    DELETE FROM search_cache WHERE expires_at < NOW();
    DELETE FROM video_metadata_cache WHERE expires_at < NOW();
    DELETE FROM thumbnail_cache WHERE last_accessed < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM users WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY);
END;
```

### 6.2 Thumbnail Optimization

```php
// ThumbnailCache.php - Optimize thumbnails on cache
class ThumbnailCache {
    const THUMBNAIL_WIDTH = 320;
    const THUMBNAIL_QUALITY = 80;

    public function cacheThumbnail(string $archiveId): string {
        $sourceUrl = "https://archive.org/services/img/{$archiveId}";
        $localPath = $this->getThumbnailPath($archiveId);

        // Download original
        $imageData = file_get_contents($sourceUrl);

        // Optimize with GD or Imagick
        $image = imagecreatefromstring($imageData);
        $width = imagesx($image);
        $height = imagesy($image);

        // Resize if too large
        if ($width > self::THUMBNAIL_WIDTH) {
            $newHeight = (self::THUMBNAIL_WIDTH / $width) * $height;
            $resized = imagescale($image, self::THUMBNAIL_WIDTH, $newHeight);
            imagejpeg($resized, $localPath, self::THUMBNAIL_QUALITY);
            imagedestroy($resized);
        } else {
            imagejpeg($image, $localPath, self::THUMBNAIL_QUALITY);
        }

        imagedestroy($image);

        // Store in database
        $this->db->insert('thumbnail_cache', [
            'archive_id' => $archiveId,
            'original_url' => $sourceUrl,
            'local_path' => $localPath,
            'file_size' => filesize($localPath)
        ]);

        return $localPath;
    }
}
```

### 6.3 Response Compression

```php
// Enable gzip for API responses
if (!ob_start("ob_gzhandler")) {
    ob_start();
}

// Add caching headers
header('Cache-Control: public, max-age=1800'); // 30 minutes
header('ETag: "' . md5($responseJson) . '"');
header('Vary: Accept-Encoding');
```

---

## Phase 7: Monitoring & Analytics

### 7.1 Cache Hit Rate Monitoring

```php
// Log cache performance
class CacheMetrics {
    public function logCacheAccess(string $cacheType, bool $hit, int $responseTimeMs): void {
        $this->db->insert('api_usage_log', [
            'endpoint' => $cacheType,
            'cache_hit' => $hit ? 1 : 0,
            'response_time_ms' => $responseTimeMs,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getCacheStats(string $period = '24 hours'): array {
        return $this->db->query("
            SELECT
                endpoint,
                COUNT(*) as total_requests,
                SUM(cache_hit) as cache_hits,
                AVG(response_time_ms) as avg_response_time,
                ROUND(SUM(cache_hit) / COUNT(*) * 100, 2) as hit_rate
            FROM api_usage_log
            WHERE created_at > DATE_SUB(NOW(), INTERVAL {$period})
            GROUP BY endpoint
        ")->fetchAll();
    }
}
```

### 7.2 Admin Dashboard Metrics

Add to admin panel:
- Cache hit rate per type
- API calls saved (estimated)
- Storage usage (thumbnails, database)
- Popular searches
- Response time trends

---

## Implementation Timeline

### Week 1: Database Foundation
- [ ] Set up MySQL database and create schema
- [ ] Create Database class and configuration
- [ ] Migrate existing JSON data to MySQL
- [ ] Update PHP endpoints to use database
- [ ] Add proper password hashing for admin

### Week 2: Caching Layer
- [ ] Implement SearchCache with MySQL storage
- [ ] Implement MetadataCache
- [ ] Implement ThumbnailCache with file storage
- [ ] Create cache cleanup cron job
- [ ] Add cache headers to responses

### Week 3: API Restructuring
- [ ] Create new API endpoint structure
- [ ] Implement search proxy with caching
- [ ] Implement metadata endpoint
- [ ] Implement thumbnail proxy
- [ ] Add user session management

### Week 4: Frontend Integration
- [ ] Create ApiService.js
- [ ] Update SearchService to use new API
- [ ] Update thumbnail loading
- [ ] Implement prefetching
- [ ] Update Service Worker

### Week 5: Optimization & Monitoring
- [ ] Add database indexes and partitioning
- [ ] Implement thumbnail optimization
- [ ] Add response compression
- [ ] Create monitoring dashboard
- [ ] Performance testing and tuning

---

## Environment Variables (.env)

```ini
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=archive_film_club
DB_USERNAME=filmclub_user
DB_PASSWORD=secure_password_here

# Cache Configuration
CACHE_SEARCH_TTL=1800
CACHE_METADATA_TTL=86400
CACHE_THUMBNAIL_TTL=604800

# Admin Configuration
ADMIN_PASSWORD_HASH=$2y$10$...  # bcrypt hash

# Feature Flags
ENABLE_THUMBNAIL_CACHING=true
ENABLE_SEARCH_CACHING=true
ENABLE_USER_SESSIONS=true

# Paths
THUMBNAIL_CACHE_PATH=/home/user/videos/thumbnails
LOG_PATH=/home/user/videos/logs
```

---

## Security Considerations

1. **SQL Injection Prevention**: All queries use prepared statements
2. **Password Security**: bcrypt hashing for admin passwords
3. **Session Security**: Secure, HTTP-only session cookies
4. **Rate Limiting**: Add rate limiting to API endpoints
5. **Input Validation**: Validate all user inputs
6. **CORS**: Restrict API access to same origin
7. **File Permissions**: Restrict access to thumbnails directory

---

## Rollback Plan

If issues occur during migration:

1. Keep JSON files as backup during transition
2. Implement feature flags to toggle between JSON and MySQL
3. Database can be dropped and recreated from JSON backup
4. Frontend can fallback to direct Archive.org API calls

---

## Expected Performance Improvements

| Metric | Before | After (Expected) |
|--------|--------|------------------|
| Search response time | 800-2000ms | 50-200ms (cached) |
| Thumbnail load time | 200-500ms | 20-50ms (local) |
| Metadata fetch time | 500-1500ms | 30-100ms (cached) |
| Archive.org API calls | 100% | ~20% (80% cache hits) |
| Page load (repeat visit) | 3-5s | <1s |
| Offline capability | Limited | Full (cached content) |

---

## Files to Create/Modify Summary

### New Files (27)
```
db/
├── config.php
├── Database.php
└── migrations/
    └── 001_initial_schema.sql

api/
├── index.php
├── search.php
├── metadata.php
├── thumbnail.php
├── settings.php
├── recommendations.php
├── sections.php
├── user.php
├── bookmarks.php
└── history.php

cache/
├── CacheManager.php
├── SearchCache.php
├── MetadataCache.php
└── ThumbnailCache.php

services/
├── ArchiveOrgService.php
├── SettingsService.php
└── UserService.php

src/js/services/
└── ApiService.js

cron/
├── cache_warmer.php
└── cache_cleanup.php

.env
.env.example
```

### Modified Files (8)
```
index.php           # Load settings from DB
admin.php           # Use DB for all operations
app.js              # Integrate ApiService
sw.js               # Enhanced caching strategies
src/js/services/SearchService.js
src/js/services/SearchCache.js
src/js/services/VideoService.js
src/js/components/RecommendedManager.js
```

---

## Next Steps

1. Review and approve this plan
2. Set up MySQL database server
3. Begin Phase 1 implementation
4. Create feature branch for development
5. Implement with incremental commits
6. Test thoroughly before merging
