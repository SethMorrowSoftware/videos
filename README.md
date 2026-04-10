# Archive Film Club

A feature-rich web application for discovering and watching classic films from [Archive.org](https://archive.org). Built with PHP, vanilla JavaScript, and a modern dark UI.

## Features

### For Viewers
- **Search & Browse** - Full-text search across Archive.org's video library with 20+ collection filters
- **Video Player** - Dedicated player page with theater mode, quality selector, and playlist support
- **Bookmarks** - Save favorite videos for quick access
- **Watch History** - Automatic progress tracking with resume support
- **Offline Support** - Service Worker caching for offline browsing of previously visited pages
- **Theme Toggle** - Switch between dark and light mode
- **Responsive Design** - Works on desktop, tablet, and mobile

### For Admins
- **Dashboard** - Overview of site stats, configuration, and quick actions
- **Staff Picks** - Curate featured videos with drag-and-drop ordering and Archive.org search
- **Featured Sections** - Create custom content sections for the homepage
- **Site Settings** - Configure site name, default collection, sort order, and more
- **Appearance** - Customize brand colors, theme, and card styles with live preview
- **Display Options** - Toggle video card metadata, bookmarks, and watch history features

### Backend
- **MySQL Database** - Full database support with JSON file fallback
- **Multi-layer Caching** - Search results (30 min), video metadata (24 hr), and thumbnails (7 day)
- **RESTful API** - Complete API for search, metadata, bookmarks, history, and settings
- **Cron Jobs** - Automated cache cleanup, warming, and async processing
- **Electron Desktop App** - Optional desktop client with Express backend

## Requirements

- **PHP** 7.2+ with PDO extension
- **MySQL** 5.7+ (optional, falls back to JSON files)
- **Apache** with mod_rewrite enabled (or Nginx with equivalent rules)
- **Node.js** 18+ (only needed for the Electron desktop app)

## Quick Start

### 1. Download & Deploy

Upload the project files to your web server's document root or a subdirectory:

```bash
git clone https://github.com/morroware/videos.git
cd videos
```

### 2. Configure Environment

Copy the example environment file and update with your database credentials:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### 3. Run the Installer

Visit `https://yourdomain.com/install.php` in your browser. The setup wizard will:

1. Test your database connection
2. Run the database migrations
3. Create your admin account
4. Verify the installation

> **Important:** Delete `install.php` after setup is complete.

### 4. Access the Admin Panel

Visit `https://yourdomain.com/admin.php` and log in with the credentials you created.

## Manual Database Setup

If you prefer to set up the database manually (e.g., via SSH):

```bash
# Run the initial schema migration
mysql -u your_db_user -p your_database_name < db/migrations/001_initial_schema.sql

# Run additional migrations
mysql -u your_db_user -p your_database_name < db/migrations/002_permanent_local_cache.sql
mysql -u your_db_user -p your_database_name < db/migrations/003_user_accounts.sql
```

Create an admin user programmatically:

```php
<?php
require_once 'services/AdminAuthService.php';
$auth = new AdminAuthService();
$auth->createUser('admin', 'your_secure_password', 'admin@example.com');
```

## cPanel Hosting

For cPanel shared hosting (e.g., Hostinger, Bluehost, GoDaddy):

1. Go to **cPanel > MySQL Databases** and create a database + user
2. Add the user to the database with **ALL PRIVILEGES**
3. Upload files to `public_html/` (or a subdirectory)
4. Create the `.env` file via **cPanel > File Manager**
5. Visit `install.php` to complete setup

See [MYSQL_SETUP.md](MYSQL_SETUP.md) for a detailed cPanel walkthrough.

## Project Structure

```
videos/
├── index.php                  # Main search/browse page
├── player.php                 # Video player page
├── admin.php                  # Admin control panel
├── install.php                # Setup wizard
├── app.js                     # Main frontend app
├── player.js                  # Player logic
├── styles.css                 # Main stylesheet
├── player-styles.css          # Player styles
├── sw.js                      # Service worker
│
├── api/                       # REST API endpoints
│   ├── search.php             # Video search
│   ├── metadata.php           # Video metadata
│   ├── thumbnail.php          # Thumbnail proxy/cache
│   ├── bookmarks.php          # User bookmarks
│   ├── history.php            # Watch history
│   ├── recommendations.php    # Staff picks
│   ├── sections.php           # Featured sections
│   ├── settings.php           # Site settings
│   ├── stats.php              # Analytics
│   ├── cache.php              # Cache management
│   └── diagnose.php           # System diagnostics
│
├── db/                        # Database layer
│   ├── Database.php           # PDO singleton wrapper
│   ├── config.php             # Configuration loader
│   └── migrations/            # SQL migration files
│
├── services/                  # Business logic
│   ├── AdminAuthService.php   # Authentication
│   ├── SettingsService.php    # Settings management
│   ├── ArchiveOrgService.php  # Archive.org API client
│   ├── LocalStorageService.php# Local caching service
│   └── UserService.php        # User management
│
├── cache/                     # Cache management
│   ├── CacheManager.php       # Cache orchestrator
│   ├── SearchCache.php        # Search result cache
│   ├── MetadataCache.php      # Metadata cache
│   └── ThumbnailCache.php     # Thumbnail cache
│
├── cron/                      # Scheduled tasks
│   ├── cache_cleanup.php      # Expire old cache entries
│   ├── cache_warmer.php       # Pre-warm popular queries
│   └── process_cache_queue.php# Async cache processing
│
├── src/js/                    # Frontend modules
│   ├── config.js              # App configuration
│   ├── components/            # UI components
│   ├── services/              # API & data services
│   ├── player/                # Player modules
│   └── utils/                 # Helpers & utilities
│
├── electron/                  # Desktop app (optional)
│   ├── main.js                # Electron main process
│   └── server.js              # Express backend
│
├── .env.example               # Environment template
├── .htaccess                  # Apache rewrite rules
└── package.json               # Node.js dependencies
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_DATABASE` | - | Database name |
| `DB_USERNAME` | - | Database user |
| `DB_PASSWORD` | - | Database password |
| `CACHE_SEARCH_TTL` | `1800` | Search cache duration (seconds) |
| `CACHE_METADATA_TTL` | `86400` | Metadata cache duration (seconds) |
| `CACHE_THUMBNAIL_TTL` | `604800` | Thumbnail cache duration (seconds) |
| `CACHE_SETTINGS_TTL` | `3600` | Settings cache duration (seconds) |
| `ENABLE_THUMBNAIL_CACHING` | `true` | Enable local thumbnail caching |
| `ENABLE_SEARCH_CACHING` | `true` | Enable search result caching |
| `ENABLE_USER_SESSIONS` | `true` | Enable user session tracking |
| `ENABLE_API_LOGGING` | `true` | Enable API request logging |
| `APP_URL` | auto | Base URL used in email links (e.g. password reset) |
| `MAIL_FROM` | - | From address for outgoing mail |
| `MAIL_FROM_NAME` | site name | From name for outgoing mail |
| `SMTP_HOST` | - | SMTP server (leave blank to use PHP mail()) |
| `SMTP_PORT` | `587` | SMTP port |
| `SMTP_USERNAME` | - | SMTP auth username |
| `SMTP_PASSWORD` | - | SMTP auth password |
| `SMTP_ENCRYPTION` | `tls` | `tls` (STARTTLS) or `ssl` |

### Cron Jobs (Optional)

Set up cron jobs for automated cache management:

```crontab
# Clean expired cache entries every hour
0 * * * * php /path/to/videos/cron/cache_cleanup.php

# Warm cache for popular searches daily
0 3 * * * php /path/to/videos/cron/cache_warmer.php

# Process async cache queue every 5 minutes
*/5 * * * * php /path/to/videos/cron/process_cache_queue.php
```

## API Reference

All API endpoints are located under `/api/` and return JSON responses.

| Endpoint | Method | Description |
|---|---|---|
| `/api/search.php` | GET | Search videos (`q`, `collection`, `sort`, `page`, `rows`) |
| `/api/metadata.php` | GET | Get video metadata (`id`) |
| `/api/thumbnail.php` | GET | Get/cache thumbnail (`id`) |
| `/api/recommendations.php` | GET | Get staff picks |
| `/api/sections.php` | GET | Get featured sections |
| `/api/settings.php` | GET | Get site settings |
| `/api/bookmarks.php` | GET/POST/DELETE | Manage user bookmarks |
| `/api/history.php` | GET/POST | Watch history & progress |
| `/api/stats.php` | GET | Site analytics |
| `/api/cache.php` | GET/POST | Cache management |
| `/api/diagnose.php` | GET | System diagnostics |

## Desktop App (Electron)

An optional Electron wrapper is included for running as a desktop application:

```bash
# Install dependencies
npm install

# Run in development mode
npm run dev

# Run in production mode
npm start
```

## Tech Stack

- **Backend:** PHP 7.2+, MySQL 5.7+, PDO
- **Frontend:** Vanilla JavaScript (ES6 modules), HTML5, CSS3
- **Styling:** CSS Custom Properties, Grid, Flexbox
- **Caching:** Multi-layer (database + local storage + service worker)
- **Desktop:** Electron 33, Express 4
- **External API:** Archive.org Advanced Search API

## License

This project is provided as-is for personal and educational use.
