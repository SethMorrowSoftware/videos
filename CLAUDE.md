# CLAUDE.md

Architectural reference for Claude when working in this repo. Keep this file
in sync with reality — it is the long-form companion to `BETA_READINESS.md`
(which tracks only open issues).

## What this app is

**Archive Film Club** — a PHP + vanilla-JS web app that wraps the Archive.org
Advanced Search API with a caching layer, a curated-content admin panel, and
user accounts (bookmarks, watch history, shareable collections). Optional
Electron wrapper in `electron/`. Target host is cPanel shared hosting with
PHP 7.2+ and MySQL 5.7+ (JSON file fallback for pre-install state).

- **72 PHP files**, **31 JS files**, ~10k LOC of ES6 modules under `src/js/`.
- **No Composer**, no build step — `bootstrap.php` hand-rolls a PSR-4-ish
  autoloader. Frontend uses native ES modules served directly.

## Entrypoints (repo root)

| File | Role |
|---|---|
| `index.php` | Search/browse homepage; server-renders OG tags, delegates UI to `app.js` |
| `player.php` | Dedicated video player page, loads `player.js` |
| `admin.php` | Thin shell → `admin/controllers/AdminBootstrap.php` + `admin/views/*` + `admin/assets/admin.{css,js}` |
| `install.php` | Setup wizard — dual-guarded by `.installed` file + admin-exists DB check; **delete or htaccess-block after install** |
| `login.php` / `register.php` / `forgot-password.php` / `reset-password.php` / `verify-email.php` / `account.php` | Auth pages; all POST to `api/auth/*.php` |
| `collection.php` / `collections.php` | My-collections list + single collection view (owner via `?id=`, public via `?u=&s=`) |
| `bootstrap.php` | **Load this first from every entrypoint.** Loads `.env`, registers autoloader, starts hardened session with install-scoped cookie path |

The root-level `recommendations.php` shim (95-byte JSON-with-`.php`-extension
dead code) was deleted in the 2026-04-11 audit pass. Staff picks now live
exclusively at `api/recommendations.php`.

## Backend architecture

```
bootstrap.php              .env loader, autoloader, session hardening, app_cookie_path()
services/
  Http/ApiController.php   base class for api/*.php — JSON responses, auth gates, sanitizers
  Auth/UserAuthService.php register/login/logout/remember/password-reset/email-verify
  User/UserContext.php     current-user resolver (session → remember cookie → auto-created guest)
  User/UserRepository.php  CRUD on unified users table, guest merging
  User/BookmarkService.php, WatchHistoryService.php, SearchHistoryService.php
  Collection/CollectionService.php  per-user collections with public slugs
  Mail/MailService.php     SMTP (PHPMailer-free) or PHP mail() fallback; host-header-poisoning safe
  ArchiveOrgService.php    Archive.org API client + proactive caching glue
  SettingsService.php      site settings, staff picks, featured sections (DB + JSON fallback)
  LocalStorageService.php  JSON file fallback layer used when DB unavailable
  AdminAuthService.php     legacy shim — kept for pre-migration-003 installs
  UserService.php          legacy session-only user record (pre-unified-users)
db/
  Database.php             PDO singleton, prepared statements, transaction helper, upsert
  config.php               loads .env, returns config array (also used by Database.php)
  migrations/              001_initial_schema, 002_permanent_local_cache, 003_user_accounts, 004_collections
cache/
  CacheManager.php         orchestrator for search/metadata/thumbnail caches
  SearchCache.php, MetadataCache.php, ThumbnailCache.php
cron/                      cache_cleanup.php, cache_warmer.php, process_cache_queue.php
admin/
  controllers/AdminBootstrap.php   auth + data loading for admin.php
  views/{login,layout,header,sidebar}.php + views/panels/{dashboard,staff-picks,sections,site-settings,appearance,display}.php
  assets/admin.{css,js}            hydrated by window.ADMIN_BOOTSTRAP JSON blob
api/
  search / metadata / thumbnail / recommendations / sections / settings / stats / cache / diagnose / bookmarks / history / collections / user
  auth/ {login, logout, register, me, profile, change-password, forgot-password, reset-password}
partials/header.php        shared site header (used by server-rendered pages; client pages render their own and hydrate via AuthNav)
```

### Request lifecycle

1. Entrypoint `require_once bootstrap.php` → `.env` loaded, autoloader
   registered, session started with install-scoped `app_cookie_path()`.
2. Page-rendering PHP uses services directly; API endpoints instantiate
   `ApiController` which pre-sends `Content-Type: application/json`.
3. `ApiController::currentUser()` prefers `UserAuthService` (unified users
   table), falls back to legacy `AdminAuthService` session.
4. Frontend pages mount `AuthNav` and fetch `api/auth/me.php` to hydrate the
   header. Service worker registration happens from `app.js` and `player.js`
   only (see F1 in BETA_READINESS).

### Auth + session model

- **Unified `users` table** (migration 003): guests and accounts share one
  table, distinguished by `is_guest` + `role` (`guest|viewer|editor|admin`).
- `UserContext` is the single source of truth for "who is this request?":
  - checks `$_SESSION['user_id']` → remember-me cookie → creates guest row
    keyed by PHP session id. Every request resolves to a non-null user.
- Remember-me / password-reset / email-verify tokens live in
  `user_auth_tokens`, SHA-256 hashed at rest, raw token only in the cookie
  or email link. `session_regenerate_id(true)` on login / register /
  password reset. Password hashing is `password_hash(PASSWORD_DEFAULT)`.
- Guest→account merge on signup moves bookmarks, history, and searches into
  the new account id, then deletes the guest row.
- **First registered account is auto-promoted to `admin`** (bootstrap).
- `ADMIN_PASSWORD` env var is a break-glass fallback when DB is unavailable;
  should be unset after install (see R4 in BETA_READINESS).

### Caching layers (in order)

1. **Browser + service worker** — `sw.js` caches static assets, metadata,
   and images with cache-first / stale-while-revalidate strategies.
   Register URL is relative so subdirectory installs get correct scope.
2. **Database caches** — `search_cache` (30 min), `video_metadata_cache`
   (24 hr), `thumbnail_cache` with filesystem copies in `/thumbnails/`.
3. **JSON file fallback** — when DB is missing, `LocalStorageService`
   reads/writes `site-settings.json`, `featured-sections.json`,
   `recommendations.json` in the repo root.
4. **Async queue** — `cron/process_cache_queue.php` drains a DB-backed
   queue so user-facing requests never block on Archive.org fetches.

TTLs are overridable via `.env` (`CACHE_*_TTL`) and feature-flaggable
(`ENABLE_*_CACHING`).

## Frontend architecture (`src/js/`)

Native ES modules — no bundler.

- `config.js` — constants + `COLLECTIONS` dropdown list
- `utils/` — `helpers.js`, `icons.js` (inline SVGs), `uiFeedback.js`, `urlManager.js`
- `services/`
  - `ApiService.js` — wraps `/api/*.php` (BASE_URL=`'api'`, relative)
  - `AuthService.js` — wraps `/api/auth/*`, pub/sub state, `AuthError` class (AUTH_BASE=`'api/auth'`, relative)
  - `SearchService.js`, `SearchCache.js`, `VideoService.js`, `VideoProgressTracker.js`
  - `BookmarkManager.js`, `PlaylistService.js`, `CollectionService.js`
  - `BackgroundCacheService.js`, `OfflineHandler.js`
- `components/` — `AuthNav`, `SearchSuggestions`, `RecommendedManager`, `FeaturedSectionsManager`, `CollectionPicker`, `Toast`, `LoadingSkeleton`
- `player/` — `PlayerUI.js`, `PlayerPlaylist.js`
- `app.js` (root) and `player.js` (root) are the entry module scripts that
  import from `src/js/`.

**Subdirectory deployment is a contract:** `AUTH_BASE`, `ApiService.BASE_URL`,
service-worker registration URL, `collection.php` share URL, and all
`<a href>`s use relative paths. Never change them to leading-slash paths.

## Security baseline (verified in BETA_READINESS audit passes)

- Prepared statements everywhere via `Database.php`; no string interpolation.
- `htmlspecialchars(..., ENT_QUOTES)` on every template branch.
- Session cookie: `httponly`, `secure` (when HTTPS), `samesite=Lax`,
  install-scoped `path` from `app_cookie_path()` (strips `/api` off
  `SCRIPT_NAME`).
- SSRF pin in `api/metadata.php` + `api/thumbnail.php` to `archive.org`.
- `api/diagnose.php` is admin-gated; `api/cache.php` admin gates the
  destructive actions; wildcard CORS header removed from `api/.htaccess`.
- `afc_safe_next()` open-redirect whitelist (server + client).
- Root `.htaccess` forces HTTPS, denies `.env`/`Database.php`/`config.php`/
  `upgrade_cache.php`/`*.md`/`*.sql`/`*.log`, disables indexing, sets
  `X-Content-Type-Options` / `X-Frame-Options` / `Referrer-Policy`.
- Installer: dual guard (`.installed` file + admin row in `users`),
  chmods `.env` to `0600`, refuses to re-run once admin exists.

**Known gaps (accepted for beta, see BETA_READINESS.md):** no CSRF tokens
(relies on SameSite=Lax + JSON POST), no login rate-limiting, CSP/HSTS
headers not set yet, `ADMIN_PASSWORD` fallback still recognized.

## Config (environment)

All config is `.env` based, loaded by `bootstrap.php` AND `db/config.php`.
Use `env('KEY', $default)` inside app code.

- `DB_*` — MySQL connection. Without a DB the app falls back to JSON files.
- `CACHE_*_TTL` + `ENABLE_*_CACHING` — cache knobs per layer.
- `APP_URL` — base URL for email links (defaults auto-detected from
  `SERVER_NAME` + install dir, host-header-poisoning blocked).
- `MAIL_FROM`, `MAIL_FROM_NAME`, `SMTP_*` — email; falls back to `mail()`.
- `ADMIN_PASSWORD` — break-glass admin fallback; leave empty post-install.
- `THUMBNAIL_CACHE_PATH`, `LOG_PATH` — custom paths, default relative.

## Working conventions for Claude in this repo

- **Always `require_once __DIR__ . '/bootstrap.php'`** at the top of new PHP
  entrypoints. Don't start sessions manually. Don't `require` service files
  directly — let the autoloader find them under
  `services/`, `services/{Http,Auth,Mail,User,Collection}/`, `db/`, `cache/`,
  `admin/controllers/`.
- **Subclass or instantiate `ApiController`** in every `api/*.php` handler.
  Use `$api->requireMethod([...])`, `$api->requireAuth()`, `$api->requireAdmin()`,
  `$api->jsonBody()`, `$api->ok(...)`, `$api->error(...)`. Don't write raw
  `echo json_encode(...)` — error paths must stay consistent.
- **Subdirectory-safe URLs only** — relative paths on both server and client.
  Never introduce a leading-slash fetch/register call; it breaks cPanel
  subdirectory installs (`/films/`, `/shorts/`). When rendering share links
  server-side, mirror `collection.php:214`'s `$installBase` pattern.
- **Prepared statements only.** Use `Database::getInstance()->query/fetchOne/
  fetchAll/insert/update/delete/upsert`. Never build SQL by concatenation.
- **Migrations are idempotent.** Every `CREATE TABLE` uses `IF NOT EXISTS`,
  every `ALTER TABLE` wraps column/index adds in duplicate-swallowing logic
  in `install.php`. Preserve that pattern in new migrations; number them
  sequentially.
- **JSON file fallback is real.** When adding a setting, write through
  `SettingsService` so it works both with and without a database.
  `LocalStorageService` is the JSON-mode implementation.
- **Escape on output, not input.** Input sanitizers on `ApiController` are
  conveniences (`sanitizeText`, `sanitizeArchiveId`, `sanitizeHexColor`,
  `sanitizeBool`, `sanitizeEnum`), not a substitute for `htmlspecialchars`
  in templates or prepared statements in SQL.
- **Respect the role model.** `role IN ('admin','editor')` for admin gates,
  never check usernames. `is_guest=0` for "is a real account". Use
  `UserContext`, not raw `$_SESSION` reads.
- **PHP lint gate:** every PHP file must pass `php -l`. All 72 files
  currently do on PHP 8.4.
- **Don't add frameworks, package managers, or build steps** without an
  explicit request. The whole point of this codebase is "upload to cPanel
  and it works."
- **Before declaring a task done, re-read `BETA_READINESS.md`** to make sure
  your change doesn't regress a verified-good item or contradict an
  accepted-risk rationale. If you fix a `[ ] TODO` item there, move it to
  `[x] DONE` in the same commit.

## Useful commands

```bash
# Lint every PHP file (catches syntax regressions across refactors)
find . -name "*.php" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l

# Manual migration run
mysql -u USER -p DB < db/migrations/001_initial_schema.sql
mysql -u USER -p DB < db/migrations/002_permanent_local_cache.sql
mysql -u USER -p DB < db/migrations/003_user_accounts.sql
mysql -u USER -p DB < db/migrations/004_collections.sql

# Electron desktop shell (optional; needs Node 18+)
npm install && npm run dev
```

## Pointers

- Open issues + audit status: `BETA_READINESS.md`
- User-facing README + API reference: `README.md`
- cPanel setup walkthrough: `MYSQL_SETUP.md`
- Root `.htaccess` — rewrite rules, security denies, HTTPS force,
  post-install `install.php` block (currently commented — uncomment after
  setup).
