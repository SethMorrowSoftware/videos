# Beta Readiness Tracker

Living document tracking the pre-beta audit findings and their status.
Updated: 2026-04-11.

The latest audit pass (`claude/audit-beta-release-Rm7Oj`) re-verified
everything on this list against the current tree, resolved F1/F3/F4/F5
and R4, and re-lints clean on PHP 8.4. This file is the short version
that engineers + reviewers should use to check off remaining work. A
deeper architectural reference lives in `CLAUDE.md`.

---

## Status legend

- [x] **DONE** — fixed and in this branch
- [ ] **TODO** — known issue, not yet addressed
- [~] **INTENTIONAL** — reviewed, accepted as-is for beta with rationale

---

## Blockers (must be resolved before beta)

- [x] **B1. `api/diagnose.php` was publicly accessible.** It leaked DB name,
  DOCUMENT_ROOT, script paths, installed extensions, and migration status to
  any anonymous visitor. Now gated behind `requireAdmin()` via `ApiController`.
  File: `api/diagnose.php`.

- [x] **B2. `api/cache.php` was unauthenticated and emitted
  `Access-Control-Allow-Origin: *`.** Any origin could POST to queue caching
  work. Fixed: wildcard CORS headers removed; admin-only actions
  (`process_queue`, `refresh_stale`, `stats`) now require `requireAdmin()`;
  user-facing actions (`queue`, `cache_single`, `cache_immediate`) remain open
  to same-origin sessions only. File: `api/cache.php`.

- [x] **B3. `api/.htaccess` was forcing `Access-Control-Allow-Origin "*"` on
  every PHP response in the API directory.** Removed. The app is same-origin
  and does not need CORS. File: `api/.htaccess`.

- [x] **B4. Service worker (`sw.js`) was never registered.** Offline feature
  was dead. Registration is wired into `app.js` (line ~828) and `player.js`
  (line ~700), both via a relative `'sw.js'` URL so subdirectory installs get
  the correct `/subdir/sw.js` scope automatically. The first visit to
  `index.php` or `player.php` activates the SW across the whole install
  scope, so later cold visits to auxiliary pages (login, admin, collections)
  still hit it. **NOTE (2026-04-10):** a previous edit of this tracker
  claimed registration lived in `partials/header.php`; that was wrong and
  has been corrected. See "Audit pass 2026-04-10 — F1" below for follow-up.

- [x] **B5. `install.php` re-run protection was fragile.** Only checked
  `.installed` file; deleting that file re-opened the installer. Added a
  defensive DB check that also aborts if an admin already exists in the
  `users` table, and added a post-install `<FilesMatch>` deny block in
  `.htaccess` that users can uncomment. Also chmod `.env` to `0600` after
  writing. Files: `install.php`, `.htaccess`, `README.md`.

---

## Risks (should fix soon, not strict blockers)

- [x] **R3. `.env` file permissions.** `install.php` now chmods the file to
  `0600` after writing so other users on the cPanel node cannot read it.

- [ ] **R1. CSRF — endpoints rely solely on SameSite=Lax.** No `ApiController`
  method validates a CSRF token. For beta this is acceptable (SameSite=Lax
  blocks the standard cross-origin POST CSRF in all modern browsers and all
  state-changing endpoints use POST with JSON bodies, not top-level navigation
  GETs). **Post-beta**, add double-submit tokens in `ApiController` plus a
  `<meta name="csrf-token">` tag in `partials/header.php` and have
  `AuthService.js` / `ApiService.js` auto-include the `X-CSRF-Token` header.

- [ ] **R2. No brute-force / rate limiting on `api/auth/login.php` and
  `api/auth/forgot-password.php`.** Beta can ship without this on a low-traffic
  install, but the first time anyone notices the site it *will* be scraped.
  Fix plan: add a `rate_limits` table (columns `key`, `created_at`), insert on
  each attempt, reject when count in the sliding window exceeds a threshold
  (5/15min by IP is a reasonable starting point).

- [x] **R4. `ADMIN_PASSWORD` env var fallback.** If the user leaves
  `ADMIN_PASSWORD=...` in `.env` after running `install.php`, they have a
  second admin credential they may not remember. **Fixed:**
  `admin/controllers/AdminBootstrap.php` now computes
  `$adminPasswordFallbackActive` whenever a DB admin exists AND
  `ADMIN_PASSWORD` is set, and `admin/views/panels/dashboard.php` renders
  a dismissable warning banner at the top of the dashboard telling the
  operator to remove the env line. (The fallback login path itself is
  unchanged — it's still useful when the DB is unreachable.)

---

## Nice-to-have (post-beta)

- [ ] **Content-Security-Policy** header in root `.htaccess`.
- [ ] **Strict-Transport-Security** (HSTS) header once HTTPS is permanent.
- [ ] **Web app manifest** for PWA installability.
- [ ] **Refactor inline `onerror=` handlers** in `admin.js` (lines ~291, ~911)
  to `addEventListener('error', ...)` for style consistency — not a security
  issue, src is code-controlled.
- [x] **Verified `recommendations.php`** (95-byte shim at repo root) was
  dead code and has been deleted. The real endpoint remains
  `api/recommendations.php`. Nothing in the codebase references the root
  shim; `sw.js`'s cache-strategy map keys on the filename so it still
  applies to the API endpoint.

---

## Verified good (do not regress)

These passed audit and should stay that way:

- [x] Subdirectory deployment (paths, cookies, email links, redirects).
- [x] SQL injection — all queries prepared via `db/Database.php`.
- [x] XSS — consistent `htmlspecialchars(..., ENT_QUOTES)` in templates.
- [x] Session hardening — httponly, secure, samesite=Lax, scoped path.
- [x] `session_regenerate_id(true)` on login + privilege change + password
  reset.
- [x] `password_hash(PASSWORD_DEFAULT)` / `password_verify` throughout.
- [x] Password reset tokens — single-use, 2h expiry, hashed-at-rest,
  cryptographically random.
- [x] Email verification tokens — same treatment.
- [x] `afc_safe_next()` open-redirect whitelist.
- [x] `api/thumbnail.php` and `api/metadata.php` SSRF — hardcoded archive.org.
- [x] Mass-assignment on register — role set server-side only.
- [x] Migrations are idempotent, declare FKs, use utf8mb4.
- [x] JSON fallback for settings / local storage / user service.
- [x] Admin gating on `api/settings.php`, `api/stats.php`, `api/sections.php`,
  `api/recommendations.php`, `admin.php`.
- [x] Root `.htaccess` denies `.env`, `Database.php`, `config.php`,
  `upgrade_cache.php`; sets `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`; disables directory indexing.
- [x] All 72 PHP files pass `php -l`.

---

## Audit pass 2026-04-10 (branch `claude/audit-project-functionality-HV74D`)

Second end-to-end review against the post-B1–B5 tree. All 72 PHP files
compile clean on PHP 8.4 (`php -l`). Subdirectory handling, auth flow,
cookie scoping, email base-URL, SW scope, sanitizers, and prepared
statements all re-verified. New findings below.

### Fixed in this branch

- [x] **F2. `verify-email.php` failed-state link pointed at `profile.php`
  which does not exist** (the profile/settings page is `account.php`).
  Users hitting an expired/invalid verification token landed on a dead
  link. Fixed in `verify-email.php` (link now points at `account.php`).

### New findings (not blockers)

- [x] **F1. Service worker is not registered on cold loads of auxiliary
  pages.** **Fixed:** `partials/header.php` now emits a tiny inline
  `<script>` block at the end of the partial that calls
  `navigator.serviceWorker.register('sw.js')` (relative URL, subdirectory
  safe). The partial is included by `login.php`, `register.php`,
  `forgot-password.php`, `reset-password.php`, `verify-email.php`,
  `account.php`, `collection.php`, and `collections.php`, so cold landings
  on any of those pages now install the SW. `app.js` and `player.js`
  still register for `index.php` / `player.php`; browsers de-dup repeat
  registrations by URL so double-registration is harmless.

- [x] **F3. `index.php` and `player.php` do not `require_once bootstrap.php`.**
  **Fixed:** both files now start with `require_once __DIR__ .
  '/bootstrap.php';` so the install-scoped session cookie, the
  autoloader, and the `.env` loader run on the very first render. The
  old `require_once __DIR__ . '/services/SettingsService.php';` lines
  were removed since the class is now autoloaded. `SettingsService`
  instantiation is now wrapped in `catch (Throwable $e)` rather than
  `catch (Exception $e)` so autoloader Errors from a fully-missing
  services dir fall back to JSON mode instead of 500ing.

- [x] **F4. Root-level `recommendations.php` dead code.** **Deleted.**
  The real endpoint remains `api/recommendations.php`.

- [x] **F5. `api/collections.php` POST switch cases have no `break`.**
  **Fixed:** each case now ends with an explicit `break;` after its
  `$api->ok(...)` / `$api->error(...)` call. Functionally a no-op (both
  helpers `exit`), but keeps strict linters happy and signals intent.

### Re-verified (still good)

- [x] Subdirectory deployment:
    - `bootstrap.php::app_cookie_path()` strips `/api[/...]` so cookies
      are scoped to the install root from every entrypoint.
    - `MailService::baseUrl()` prefers `APP_URL`, falls back to
      `SERVER_NAME` + install dir computed from `SCRIPT_NAME` with the
      same `/api` strip. Host-header poisoning blocked.
    - `sw.js` `STATIC_ASSETS` are all relative (`./`, `./styles.css`, …).
    - `app.js:829` and `player.js:700` register the SW via a relative
      `'sw.js'` URL so scope tracks the install dir automatically.
    - Frontend `AuthService.AUTH_BASE='api/auth'` and
      `ApiService.BASE_URL='api'` are both relative, resolved against
      `document.baseURI`.
    - `collection.php:214` computes `$installBase` for share URLs.
- [x] Security hardening:
    - Prepared statements everywhere via `db/Database.php`.
    - `htmlspecialchars(..., ENT_QUOTES)` in every template branch.
    - `password_hash(PASSWORD_DEFAULT)` + `password_verify`, never MD5/SHA1.
    - `session_regenerate_id(true)` on login, register, and password reset.
    - Remember-me / reset / verify tokens are SHA-256 hashed at rest in
      `user_auth_tokens`; raw token only transits in the email link.
    - `MailService::fromHeader()` + `stripCrlf()` blocks header injection.
    - `afc_safe_next()` open-redirect whitelist is mirrored on the client.
    - `api/metadata.php` / `api/thumbnail.php` SSRF-pinned to archive.org.
    - `api/diagnose.php` admin-gated post-install.
    - `api/cache.php` admin-only for `process_queue`/`refresh_stale`/`stats`;
      wildcard CORS header removed.
    - Root `.htaccess` denies `.env`, `Database.php`, `config.php`,
      `upgrade_cache.php`, `*.md`, `*.sql`, `*.log`; disables indexing;
      forces HTTPS; sets nosniff / frame-options / referrer-policy.
- [x] Installer:
    - Dual guard (`.installed` file + admin existence in `users` table).
    - Swallows idempotent "already exists / duplicate column" errors.
    - Chmods `.env` to 0600 after writing.
    - Creates admin in the unified `users` table (role='admin',
      is_guest=0) so the first login path matches the rest of the app.
- [x] Auth flow:
    - Unified `users` table (migration 003): accounts + guests, role column.
    - `UserContext` resolves session → remember cookie → auto-created
      guest row keyed by PHP session id.
    - `UserAuthService::mergeGuest()` moves bookmarks/history/searches
      into the new account on register/login.
    - First registered account is auto-promoted to `admin`.
- [x] All 72 PHP files pass `php -l` on PHP 8.4.19.

### Beta verdict

**Ready to ship.** No blockers surfaced in this pass.

---

## Audit pass 2026-04-11 (branch `claude/audit-beta-release-Rm7Oj`)

Third end-to-end review + polish pass against the post-B1–B5, post-F2
tree. All 71 PHP files (was 72; `recommendations.php` shim deleted)
compile clean on PHP 8.4 with `php -l`.

### Fixed in this branch

- **F1** — SW registration now also fires from `partials/header.php`, so
  cold landings on auxiliary pages install the worker. Both
  `app.js`/`player.js` and the partial use the same relative
  `'sw.js'` URL so browsers de-dup the registration.
- **F3** — `index.php` and `player.php` now `require_once bootstrap.php`
  at the very top. Install-scoped session cookies + autoloader active
  from the first render.
- **F4** — Root-level `recommendations.php` dead shim deleted.
- **F5** — Explicit `break;` statements added to the POST switch in
  `api/collections.php` for lint + style consistency.
- **R4** — Admin dashboard now renders a warning banner when a DB admin
  exists and `ADMIN_PASSWORD` is still set in `.env`. The fallback
  login path itself is intentionally preserved for DB-outage recovery.

### Re-verified (still good)

- All items from the 2026-04-10 pass remain intact.
- All 71 PHP files pass `php -l` on PHP 8.4.
- No new blockers surfaced. The remaining open items (R1 CSRF tokens,
  R2 login rate-limiting, and the nice-to-have CSP/HSTS/manifest work)
  are unchanged from the previous pass.

---

## Cleanup done in this branch

- [x] Removed `IMPLEMENTATION_PLAN.md` — historical planning doc superseded
  by `README.md` and this tracker.

---

## Deployment checklist for cPanel beta

1. Upload files to target directory (root or subdirectory, both supported).
2. Create MySQL database + user in cPanel.
3. Visit `https://yourhost/yoursubdir/install.php` and run the wizard.
4. **Delete `install.php`** (or uncomment the `<FilesMatch>` block in
   `.htaccess`).
5. Set `APP_URL` in `.env` to the full base URL so password-reset emails
   contain correct links (optional — will auto-detect from `SERVER_NAME`
   + install subdir if left blank).
6. Configure SMTP in `.env` if `mail()` is not available on the host.
7. Optional: add cron entries for `cron/cache_cleanup.php`,
   `cron/cache_warmer.php`, `cron/process_cache_queue.php`.
8. Visit `/admin.php`, log in, configure appearance + staff picks.
9. Verify service worker registered: open DevTools → Application → Service
   Workers; should see `sw.js` active with scope matching install dir.
