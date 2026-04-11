# Beta Readiness Tracker

Living document tracking the pre-beta audit findings and their status.
Updated: 2026-04-10.

The latest audit pass (`claude/audit-project-functionality-HV74D`) re-verified
everything on this list against the current tree, corrected the B4 entry, and
added the new findings in the "Audit pass 2026-04-10" section below. This
file is the short version that engineers + reviewers should use to check off
remaining work. A deeper architectural reference lives in `CLAUDE.md`.

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

- [ ] **R4. `ADMIN_PASSWORD` env var fallback.** If the user leaves
  `ADMIN_PASSWORD=...` in `.env` after running `install.php`, they have a
  second admin credential they may not remember. Planned: show a banner in
  the admin dashboard when `ADMIN_PASSWORD` is set and a DB admin exists.

---

## Nice-to-have (post-beta)

- [ ] **Content-Security-Policy** header in root `.htaccess`.
- [ ] **Strict-Transport-Security** (HSTS) header once HTTPS is permanent.
- [ ] **Web app manifest** for PWA installability.
- [ ] **Refactor inline `onerror=` handlers** in `admin.js` (lines ~291, ~911)
  to `addEventListener('error', ...)` for style consistency — not a security
  issue, src is code-controlled.
- [ ] **Verify `recommendations.php`** (95-byte shim at repo root) is still
  needed. Probably dead code.

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

- [ ] **F1. Service worker is not registered on cold loads of auxiliary
  pages.** Because registration lives in `app.js` and `player.js`, visiting
  `login.php` / `register.php` / `collection.php` / `account.php` /
  `admin.php` *before* the homepage or player means no SW install on that
  visit. Real-world impact is tiny (almost every journey starts at
  `index.php`, and once the SW is installed its scope covers the whole
  install directory), but the documentation promise of "every page
  registers the SW" is stronger than reality. **Fix (post-beta):** either
  add a tiny `<script>` snippet to `partials/header.php` that calls
  `navigator.serviceWorker.register('sw.js')` once, or move the existing
  block into a shared `src/js/utils/registerSW.js` and import it from all
  entry pages. **Do not** change the URL to a leading-slash path — that
  would break subdirectory installs.

- [ ] **F3. `index.php` and `player.php` do not `require_once bootstrap.php`.**
  They load `services/SettingsService.php` directly, which only pulls
  `db/Database.php`. Consequences:
    - No PHP session is started on the first render (it gets started later
      when the frontend calls `api/auth/me.php`, which does bootstrap).
    - The PSR-4-ish autoloader is not active in those files, so the
      `partials/header.php` fallback (`new UserContext()`) would silently
      fail if it were included there. In practice it isn't — both pages
      render their own header with `data-auth-nav`, hydrated client-side
      by `AuthNav.js`.
    - Install-scoped cookie path is not applied until a later request
      starts the session via `bootstrap.php`. Browsers will end up with
      the PHP default `session.cookie_path = /`, which is fine for a
      root install and mostly fine for subdir installs (cookies just
      scope slightly broader than ideal).
  **Not a blocker**, but consolidating to `require_once __DIR__ .
  '/bootstrap.php'` at the top of both files would be a one-line tidy-up.

- [ ] **F4. Root-level `recommendations.php` is a JSON file with a
  `.php` extension.** Previously flagged under "Nice-to-have". Confirmed
  dead code: the real endpoint is `api/recommendations.php`. Safe to
  delete — nothing in the codebase references it. Leaving it in place
  doesn't break anything (PHP passes it through as raw output).

- [ ] **F5. `api/collections.php` POST switch cases have no `break`.**
  Each case calls `$api->ok(...)` or `$api->error(...)`, both of which
  `exit`, so control never falls through. Safe, but a lint picks it up.
  Consider adding `break;` for style consistency.

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

**Ready to ship.** No blockers surfaced in this pass. The only code change
in this branch is the one-line `verify-email.php` link fix (F2). F1/F3/F4/F5
are minor polish to pick up after the beta tag.

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
