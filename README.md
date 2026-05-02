# Archive Film Club

A feature-rich web application for discovering and watching classic films from [Archive.org](https://archive.org). Built with PHP, vanilla JavaScript, and a modern dark UI.

## Features

### For Viewers
- **Search & Browse** - Full-text search across Archive.org's video library with 20+ collection filters and search suggestions
- **Video Player** - Dedicated player page with multi-episode playlists, quality selector, theater mode, resume-from-where-you-left-off, and an "Up Next" countdown that survives auto-advance
- **User Accounts** - Register, log in, log out, password reset by email, optional email verification, "remember me" tokens, and account self-service
- **Bookmarks** - Save favorite videos for quick access; survives logout via guest session and merges into your account on signup
- **Collections** - Create personal collections of videos, reorder items, add per-item notes, and share publicly with a slug-based URL
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
- **Unified User Model** - Guests and accounts share one `users` table, distinguished by `is_guest` and a `role` of `guest|viewer|editor|admin`. Guest activity merges into the new account on signup
- **MySQL Database** - Full database support with JSON file fallback when the DB is unavailable
- **Multi-layer Caching** - Browser + service worker, then DB caches (search 30 min, metadata 24 hr, thumbnails 7 day), then JSON file fallback, fronted by an async DB-backed queue
- **RESTful API** - Complete API for search, metadata, bookmarks, history, collections, user, settings, and auth
- **Email** - SMTP (no PHPMailer dependency) with a PHP `mail()` fallback; host-header-poisoning safe
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
2. Run the database migrations (001 → 004)
3. Create your admin account
4. Verify the installation

> The first user registered through the installer (or via `register.php` on a
> fresh install) is automatically promoted to `admin`. Every subsequent signup
> defaults to the `viewer` role.

> **Important — do one of the following after setup:**
>
> 1. **Delete `install.php`** from the server (recommended), **or**
> 2. Open `.htaccess` and **uncomment the `<FilesMatch "^install\.php$">` deny block** near the bottom.
>
> The installer also refuses to re-run once an admin user exists in the
> database, but these web-server-level locks are the safest backstop.

### 4. Access the Admin Panel

Visit `https://yourdomain.com/admin.php` and log in with the credentials you created.

## Manual Database Setup

If you prefer to set up the database manually (e.g., via SSH), run the
migrations in order:

```bash
mysql -u your_db_user -p your_database_name < db/migrations/001_initial_schema.sql
mysql -u your_db_user -p your_database_name < db/migrations/002_permanent_local_cache.sql
mysql -u your_db_user -p your_database_name < db/migrations/003_user_accounts.sql
mysql -u your_db_user -p your_database_name < db/migrations/004_collections.sql
```

After the migrations are in place, create the first admin account by
visiting `register.php` in your browser — the first registered account is
auto-promoted to `admin`. Alternatively, set `ADMIN_PASSWORD` in `.env` as
a break-glass fallback (and unset it once a real admin account exists).

## cPanel Hosting

For cPanel shared hosting (e.g., Hostinger, Bluehost, GoDaddy):

1. Go to **cPanel > MySQL Databases** and create a database + user
2. Add the user to the database with **ALL PRIVILEGES**
3. Upload files to `public_html/` (or a subdirectory like `public_html/films/`)
4. Create the `.env` file via **cPanel > File Manager**
5. Visit `install.php` to complete setup

> **Subdirectory installs work out of the box.** All client and server URLs
> in this app are relative, the session cookie is install-scoped via
> `app_cookie_path()`, and the service worker registers from the install
> directory — so `/films/` and `/shorts/` on the same domain run fully
> independent installs without colliding.

The setup flow above is the current, supported path for cPanel installs.

## Project Structure

```
videos/
├── bootstrap.php              # Loaded first by every PHP entrypoint:
│                              #   .env loader, autoloader, hardened session
├── index.php                  # Search / browse homepage
├── player.php                 # Video player page
├── admin.php                  # Admin control panel
├── install.php                # Setup wizard (delete or htaccess-block after install)
├── account.php                # Account self-service page
├── login.php                  # Sign in
├── register.php               # Sign up
├── forgot-password.php        # Request a reset link
├── reset-password.php         # Reset via emailed token
├── verify-email.php           # Email-verification handler
├── collection.php             # Single collection view (owner or public-by-slug)
├── collections.php            # My-collections list
├── app.js                     # Homepage frontend entry
├── player.js                  # Player frontend entry
├── styles.css                 # Main stylesheet
├── player-styles.css          # Player styles
├── auth-styles.css            # Login / register / reset styles
├── sw.js                      # Service worker
│
├── api/                       # REST API endpoints
│   ├── search.php             # Video search
│   ├── metadata.php           # Video metadata
│   ├── thumbnail.php          # Thumbnail proxy/cache
│   ├── bookmarks.php          # User bookmarks
│   ├── history.php            # Watch history
│   ├── collections.php        # Collections CRUD + public view
│   ├── user.php               # User info, preferences, search history
│   ├── recommendations.php    # Staff picks
│   ├── sections.php           # Featured sections
│   ├── settings.php           # Site settings
│   ├── stats.php              # Analytics
│   ├── cache.php              # Cache management (admin-gated writes)
│   ├── diagnose.php           # System diagnostics (admin-gated)
│   └── auth/                  # Account authentication endpoints
│       ├── login.php
│       ├── logout.php
│       ├── register.php
│       ├── me.php
│       ├── profile.php
│       ├── change-password.php
│       ├── forgot-password.php
│       └── reset-password.php
│
├── partials/                  # Shared server-rendered fragments
│   └── header.php             # Site header partial
│
├── db/                        # Database layer
│   ├── Database.php           # PDO singleton + helpers
│   ├── config.php             # Configuration loader
│   └── migrations/            # 001 → 004 SQL migration files
│
├── services/                  # Business logic (PSR-4 autoloaded)
│   ├── ArchiveOrgService.php  # Archive.org API client + cache glue
│   ├── SettingsService.php    # Site settings (DB + JSON fallback)
│   ├── LocalStorageService.php# JSON-file fallback layer
│   ├── AdminAuthService.php   # Legacy shim (pre-migration-003 installs)
│   ├── UserService.php        # Legacy session-only user record
│   ├── Http/
│   │   └── ApiController.php  # Base class for api/*.php handlers
│   ├── Auth/
│   │   └── UserAuthService.php# Register, login, password reset, email verify
│   ├── User/
│   │   ├── UserContext.php    # Resolves the current request to a user/guest
│   │   ├── UserRepository.php # users table CRUD + guest-merge
│   │   ├── BookmarkService.php
│   │   ├── WatchHistoryService.php
│   │   └── SearchHistoryService.php
│   ├── Collection/
│   │   └── CollectionService.php
│   └── Mail/
│       └── MailService.php    # SMTP or PHP mail() fallback
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
├── admin/                     # Admin panel internals
│   ├── controllers/           # Bootstrap + data loading
│   ├── views/                 # PHP templates (panels)
│   └── assets/                # admin.css / admin.js
│
├── src/js/                    # Frontend ES modules
│   ├── config.js              # App configuration
│   ├── components/            # UI components (AuthNav, Toast, …)
│   ├── services/              # API + data services
│   ├── player/                # Player modules
│   └── utils/                 # Helpers, icons, URL manager
│
├── electron/                  # Desktop app (optional)
│   ├── main.js                # Electron main process
│   └── server.js              # Express backend
│
├── .env.example               # Environment template
├── .htaccess                  # Apache rewrite rules + security denies
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
| `APP_URL` | auto | Base URL used in email links (e.g. password reset). Set explicitly in production |

## Maintenance Notes

- Keep `README.md` as the canonical documentation source for setup and operations.
- Historical internal audit/planning notes have been removed from the repository to reduce drift.
- If behavior changes, update this README in the same pull request as the code change.
| `MAIL_FROM` | - | From address for outgoing mail |
| `MAIL_FROM_NAME` | site name | From name for outgoing mail |
| `SMTP_HOST` | - | SMTP server (leave blank to use PHP mail()) |
| `SMTP_PORT` | `587` | SMTP port |
| `SMTP_USERNAME` | - | SMTP auth username |
| `SMTP_PASSWORD` | - | SMTP auth password |
| `SMTP_ENCRYPTION` | `tls` | `tls` (STARTTLS) or `ssl` |
| `ADMIN_PASSWORD` | - | Break-glass admin fallback used only when the DB is unavailable. Leave empty post-install |
| `THUMBNAIL_CACHE_PATH` | `<install>/thumbnails` | Filesystem path for cached thumbnail files |
| `LOG_PATH` | `<install>/logs` | Filesystem path for app log files |

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

All API endpoints are located under `/api/` and return JSON responses. All
handlers extend `services/Http/ApiController.php`, which sets the JSON
content type, gates auth/admin where needed, and provides input
sanitizers.

### Public + viewer endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/search.php` | GET | Search videos (`q`, `collection`, `sort`, `page`, `rows`) |
| `/api/metadata.php` | GET | Get video metadata (`id`) |
| `/api/thumbnail.php` | GET | Get/cache thumbnail (`id`) |
| `/api/recommendations.php` | GET | Get staff picks |
| `/api/sections.php` | GET | Get featured sections |
| `/api/settings.php` | GET | Get site settings |
| `/api/bookmarks.php` | GET/POST/DELETE | Manage bookmarks for the current user/guest |
| `/api/history.php` | GET/POST | Watch history & progress for the current user/guest |
| `/api/collections.php` | GET/POST | Collections CRUD; supports public lookup by `username` + `slug` |
| `/api/user.php` | GET/POST | User info, preferences, and recent search history |
| `/api/stats.php` | GET | Site analytics |

### Authentication endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/auth/register.php` | POST | Create an account; first registered account is auto-promoted to admin |
| `/api/auth/login.php` | POST | Sign in; issues a session and optional remember-me token |
| `/api/auth/logout.php` | POST | Invalidate session + remember-me token |
| `/api/auth/me.php` | GET | Return the current user (used to hydrate the header `AuthNav`) |
| `/api/auth/profile.php` | POST | Update display name / email (re-triggers email verification on change) |
| `/api/auth/change-password.php` | POST | Change password while signed in |
| `/api/auth/forgot-password.php` | POST | Send password-reset email |
| `/api/auth/reset-password.php` | POST | Consume a reset token and set a new password |

### Admin endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/cache.php` | GET/POST | Cache stats (GET); destructive actions require `admin`/`editor` role |
| `/api/diagnose.php` | GET | System diagnostics; admin-gated |

## Security

The app ships with a verified security baseline:

- **Prepared statements everywhere** via `db/Database.php`; no SQL string
  concatenation
- **Output escaping** with `htmlspecialchars(..., ENT_QUOTES)` on every
  template branch
- **Hardened sessions** — `httponly`, `secure` (when HTTPS), `SameSite=Lax`,
  and an install-scoped cookie `path` so sibling subdirectory installs
  don't share sessions
- **Password storage** with `password_hash(PASSWORD_DEFAULT)`; remember-me,
  password-reset, and email-verification tokens are SHA-256 hashed at rest
  and only the raw token leaves the server (in the cookie or email link)
- **`session_regenerate_id(true)`** on login, register, and password reset
- **SSRF pin** — the metadata + thumbnail proxies refuse to fetch anything
  outside `archive.org`
- **Open-redirect whitelist** via `afc_safe_next()` (server + client)
- **`.htaccess` defenses** — HTTPS force, deny on `.env`, `Database.php`,
  `config.php`, `*.md`, `*.sql`, `*.log`, no directory indexing, and
  standard `X-Content-Type-Options` / `X-Frame-Options` /
  `Referrer-Policy` headers
- **Installer dual-guard** — refuses to run once a `.installed` marker
  exists or once an admin row is present, and `chmod`s `.env` to `0600`

> **Post-install hardening:** delete `install.php` from the server *or*
> uncomment the `<FilesMatch "^install\.php$">` deny block near the
> bottom of `.htaccess`. Unset `ADMIN_PASSWORD` in `.env` once you have a
> real admin account.

Known gaps accepted for beta:
no CSRF tokens (the app relies on `SameSite=Lax` + JSON-only POSTs), no
login rate-limiting, and `Content-Security-Policy` / `Strict-Transport-Security`
headers are not yet emitted.

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
