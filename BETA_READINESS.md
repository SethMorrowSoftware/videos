# Beta Readiness Tracker

Living document tracking the pre-beta audit findings and their status.
Updated: 2026-04-10.

The full audit report lives in the commit message and PR discussion for the
`claude/audit-project-functionality-wSLrV` branch. This file is the short
version that engineers + reviewers should use to check off remaining work.

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
  was dead. Added a relative registration snippet to `partials/header.php` so
  every page (index, player, collection, admin) registers it once using a
  relative URL that stays correct under subdirectory deployments. File:
  `partials/header.php`.

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
