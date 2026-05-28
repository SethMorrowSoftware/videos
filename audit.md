# Archive Film Club — Full Project Audit

**Audit date:** 2026-05-28
**Scope:** Complete codebase — PHP backend (bootstrap, API, services, cache, cron, DB, installer, admin), frontend JS (`app.js`, `player.js`, `sw.js`, `src/js/**`), server-rendered pages, styles, and the Electron wrapper.
**Method:** Every source file was read and analyzed. Findings marked **[verified]** were confirmed by tracing the exact code path; findings marked **[flagged]** are credible from analysis and have a clear remediation but were not exercised against a live DB.

This document records **bugs and risks**. A separate **UI/UX** section at the end records the design review and the polish applied in this pass.

---

## Executive summary

The project is, on the whole, carefully built: prepared statements everywhere, consistent server-side output escaping, hardened sessions, CSRF tokens on every state-changing endpoint, SSRF-pinned proxies, hashed tokens at rest, and an installer with a dual lock. The README's "Security" section is largely accurate (one drift noted below).

However, the audit found a **small number of high-impact bugs that break core features today**, plus a privilege-escalation path:

1. **`LIMIT ?` parameter binding is broken across the app** (native prepared statements + string binding). This silently breaks watch-history lists, recent searches, the public-collections list, popular-search stats, **and the entire cron cache pipeline**. — *Critical, verified.*
2. **An `editor` can promote themselves (or anyone) to `admin`.** — *Critical, verified.*
3. **Reflected XSS via the `?video=` URL parameter** on the player page. — *High, verified.*

These three should be fixed before any public launch. The rest are graded below.

> **Update:** the three headline issues (C1, C2, H1) were **fixed in this PR** at the maintainer's request. Each carries a "Status — FIXED" note below. All other findings remain documented for triage.

---

## CRITICAL

### C1. `LIMIT ?` / `OFFSET ?` bound parameters throw under native prepared statements **[verified]**
**Where:** root cause `db/Database.php:38` + `:86`; broken call sites:
`services/User/WatchHistoryService.php:21`, `services/User/SearchHistoryService.php:29`,
`services/Collection/CollectionService.php:121`, `services/ArchiveOrgService.php:768`,
`cache/CacheManager.php:204`, `:375`, `:464`.

`Database` connects with `PDO::ATTR_EMULATE_PREPARES => false` (native server-side prepares) and `query()` runs `$stmt->execute($params)`, which binds **every** parameter as a string (`PDO::PARAM_STR`). MySQL's native protocol rejects a quoted string in a `LIMIT`/`OFFSET` clause:

```
SQLSTATE[42000]: Syntax error or access violation: 1064 ... near "'50'"
```

So every query that binds `?` into `LIMIT`/`OFFSET` throws at runtime. Concretely this breaks:
- **Watch history list** — `WatchHistoryService::recent()` → `api/history.php?action=list`
- **Recent searches** — `SearchHistoryService` → `api/user.php?action=searches`
- **Public collections list** — `CollectionService::listPublic()` → `collections.php` / `api/collections.php`
- **Popular searches** — `ArchiveOrgService:768` → `api/stats.php?action=popular`
- **The async cache queue + warmer + stale-refresh** — `CacheManager::getStaleSearches()/getStaleMetadata()/getPendingCacheItems()` → all three cron jobs throw on their first query.

Depending on the caller, the failure surfaces either as an HTTP 500 (global handler) or as a silently-empty result (callers that `try/catch` and return `[]`). Either way the feature is non-functional.

**Fix (pick one):**
- Bind the limit/offset with an explicit integer type (`$stmt->bindValue($i, (int)$limit, PDO::PARAM_INT)`), **or**
- Validate-and-interpolate the integer directly (`"... LIMIT " . (int)$limit`) as `MetricsService` already does, **or**
- Set `PDO::ATTR_EMULATE_PREPARES => true` in `Database::connect()` (simplest; makes all `LIMIT ?` work, with the usual emulation trade-offs).

> Note: `MetricsService` and `CommentService` interpolate `(int)`-cast limits/offsets directly, so they are **not** affected — confirming interpolation is the project's already-working pattern.

**Status — FIXED in this PR.** All 7 call sites now interpolate an `(int)`-cast `LIMIT`/`OFFSET` (and the one `INTERVAL ? DAY`) directly and drop the value from the bound-params array — matching the existing `MetricsService` pattern. Provably injection-safe (the interpolated values are integer casts). `grep` confirms no bound `?` remains in any `LIMIT`/`OFFSET`, and all files lint clean. The native-prepares mode (`EMULATE_PREPARES => false`) was intentionally left unchanged to avoid altering global query/return-type behavior.

### C2. Privilege escalation — an `editor` can grant themselves `admin` **[verified]**
**Where:** `api/admin/metrics.php:32, 98-112`; `services/Admin/MetricsService.php:383-388`.

`requireAdmin()` deliberately admits both `admin` **and** `editor` (`ApiController.php:201`). The `set-role` POST action's only guard is:

```php
if ($userId === (int)$admin['id'] && $role !== 'admin') { /* block self-demote */ }
```

This blocks demoting *yourself*, but an `editor` can POST `{action:'set-role', user_id:<own id>, role:'admin'}` — the second clause (`$role !== 'admin'`) is false, so the guard does not fire — or target any other account. `MetricsService::setRole()` performs the `UPDATE` with no caller-role check. Result: the lower-trust `editor` role (intended for content curation) can seize full `admin`.

**Fix:** Gate user-role mutations behind a strict admin-only check (`if (($admin['role'] ?? '') !== 'admin') $api->error('Admin only', 403);`) before `set-role`, and refuse to assign `admin` from a non-admin caller.

**Status — FIXED in this PR.** `set-role` now rejects any caller whose role isn't exactly `admin` (403). Editors retain comment moderation (`moderate`/`resolve-reports`) but can no longer touch user roles. *(Note: the related last-admin lockout, H4, was left documented — the maintainer scoped this PR to C1/C2/H1.)*

---

## HIGH

### H1. Reflected XSS via the `?video=` parameter on the player page **[verified]**
**Where:** `player.js:583/592` (source) → `player.js:1223/1227/1247` (`buildDownloadLinks`, written via `innerHTML` at `:1256`); also `src/js/player/PlayerPlaylist.js:103/113` and `:308/358` (series cover + episode thumbnails).

`this.videoId = params.get('video')` is taken raw from the URL and interpolated, unescaped, into HTML attribute strings assigned via `innerHTML`:

```js
const url = `https://archive.org/download/${this.videoId}/${encodeURIComponent(file.name)}`;
return `<a href="${url}" ...>`;            // breaks out of the href attribute
...
this.downloadLinks.innerHTML = html;
```

A crafted link such as `player.php?video="><img src=x onerror=alert(document.cookie)>` injects markup. The download panel and the multi-episode playlist both render `this.videoId` this way.

**Exploitability:** `buildDownloadLinks` runs only after the metadata fetch succeeds, and the API sanitizes the id (`sanitizeArchiveId` strips `"<>`). So the attacker must own an archive.org item whose *sanitized* name matches the stripped payload while the raw `?video=` value still carries the breakout characters — achievable because archive.org item identifiers are freely creatable. The fix is trivial regardless, and a raw URL parameter must never reach `innerHTML` unescaped.

**Fix:** Escape `this.videoId`/`id` with `escapeHtml()` before interpolation (or build the anchors/images with `document.createElement` and assign `.href`/`.src` as properties). `helpers.js` already exports `escapeHtml`.

**Status — FIXED in this PR.** Two layers: (1) `parseUrlAndLoad()` now sanitizes the `?video=` param to `[a-zA-Z0-9_.-]` at the source (the same charset the server's `sanitizeArchiveId` enforces), which neutralizes every downstream sink — download links, the playlist cover/thumbnail (`identifier` is derived from `videoId`), and the share link; (2) defense-in-depth `escapeHtml()` was added at the download-link `href` sinks (`player.js`) and the playlist `<img src>` sinks (`PlayerPlaylist.js`). `node --check` passes on both files.

### H2. Public registration auto-promotes the first account to `admin`, with no install gate and a race **[verified]**
**Where:** `services/Auth/UserAuthService.php:78-94`; `register.php` / `api/auth/register.php`.

Registration is publicly open and independent of `install.php`. `register()` does a non-atomic `SELECT COUNT(*) FROM users WHERE is_guest = 0` and grants `role='admin'` when the count is 0. Two problems:
- **No install gate:** if migrations are applied but the admin account hasn't been created yet (e.g. DB deployed before completing installer step 3), the **first stranger to hit `/register.php` becomes the site admin**.
- **Race (TOCTOU):** two simultaneous registrations on a fresh install can both read 0 and both be created as `admin`.

**Fix:** Create the admin only via `install.php` (always register web signups as `viewer`), or wrap the count+insert in a transaction with a row lock / one-shot `install_state` flag so the bootstrap admin can't be won by a public registrant or a race.

**Status — FIXED in PR #62.** Chose the install-gate approach: `register()` now always assigns `role='viewer'` (the COUNT-then-insert auto-promotion is gone). The bootstrap admin is created solely by `install.php`'s direct `INSERT` (`role='admin'`), which is independent of `register()`, so the installer flow is unchanged. Closes both the install-gate hole and the TOCTOU race.

### H3. Admin "searches" metrics are silently always zero (wrong column) **[verified]**
**Where:** `services/Admin/MetricsService.php:121-124` (`contentTotals` `searches_7d`) and `:181-184` (`dailySeries('searches')`).

Both query `FROM search_history WHERE created_at > ...`, but `search_history` defines only `searched_at` (migration `001:201`). The queries throw `Unknown column 'created_at'`, which `intQuery`/`dailySeries` catch and convert to `0` / an empty series. The admin dashboard's 7-day search count and the searches chart are therefore **permanently zero** with no error shown — a silent data-correctness bug.

**Fix:** Use `searched_at` in both queries.

**Status — FIXED in PR #62.** Both queries (`contentTotals` `searches_7d` and `dailySeriesSql('searches')`) now select/filter on `searched_at`. The `created_at` references on `users`/`video_comments` (which do have that column) were left untouched.

### H4. Last-admin lockout via `set-role` **[verified]**
**Where:** `api/admin/metrics.php:98-112`; `services/Admin/MetricsService.php:383-388`.

`set-role` blocks demoting *your own* admin role, but nothing stops an admin from demoting the **only other** admin (or any path leaving zero admins). `AdminAuthService::deleteUser` has a last-admin guard; the role-change path does not. The org can lock itself out of all admin access with no UI recovery.

**Fix:** Before demoting any `admin`, count remaining admins and refuse if the change would reach zero.

**Status — FIXED in PR #62.** `MetricsService::setRole()` now counts remaining `admin`s and throws (API → 400) when the change would remove the last one. The guard lives in the service so it covers a second admin or a stale admin session, not just the API's self-demote block.

### H5. SMTP envelope/header `To:` not CRLF-sanitized (defense-in-depth gap) **[flagged]**
**Where:** `services/Mail/MailService.php:188-191`.

`$subject` and `$from` are passed through `stripCrlf()`, but `$to` is written into `RCPT TO:<$to>` and the `To:` header relying solely on the upstream `FILTER_VALIDATE_EMAIL`. That validator does reject embedded newlines, so this is not currently exploitable — but `$to` is the one field without the defense-in-depth strip the others get.

**Fix:** Run `$to` (and every value placed into an SMTP command or header) through `stripCrlf()` too.

**Status — FIXED in PR #62.** `$to` is now `stripCrlf()`'d at the top of `sendViaSmtp()`, before it reaches `RCPT TO:<$to>` and the `To:` header.

---

## MEDIUM

### M1. Cache queue claim is non-atomic + stuck-item leak **[flagged]**
**Where:** `cache/CacheManager.php:441-474`; `services/LocalStorageService.php:257-294`.
The queue is claimed with a `SELECT ... WHERE status='pending'` followed by a separate `UPDATE ... status='processing'` — no `SELECT ... FOR UPDATE`/`GET_LOCK`/atomic claim. Two overlapping cron runs (likely on shared hosting with a short `max_execution_time` and a `*/5` schedule) process the same rows, defeating dedup. Worse, a run killed mid-item leaves rows stuck in `processing` forever — `getPendingCacheItems` only selects `pending`, so they never retry. (Largely moot until **C1** is fixed, since these queries currently throw.)
**Fix:** Claim atomically (`UPDATE cache_queue SET status='processing', attempts=attempts+1 WHERE id=? AND status='pending'` and only proceed when `rowCount()===1`); add a reaper that resets stale `processing` rows. Add a `GET_LOCK`/flock overlap guard to `cron/process_cache_queue.php`.

### M2. Migration 003 is not safely re-runnable for incremental upgrades **[verified]**
**Where:** `db/migrations/003_user_accounts.sql:30-41`; runner `install.php:217-238`.
Migration 003 is a single multi-clause `ALTER TABLE users` (8 columns + 3 keys). The installer splits on `;` (so it's one statement) and swallows "Duplicate column" errors. A fresh first run applies all clauses atomically (fine). But because MySQL aborts the whole ALTER on the first already-existing clause, **adding a new clause to this migration later and re-running will not apply it** — the ALTER dies on `ADD COLUMN username` and is swallowed. This contradicts the file's own "Safe to run … Re-runnable" comment.
**Fix:** Split into one `ALTER TABLE users ADD COLUMN …;` per clause (the one-per-statement style migration 002 already uses).

### M3. "Permanent cache" depends on migration 002's `MODIFY` having succeeded **[verified]**
**Where:** `db/migrations/001_initial_schema.sql:100,125` (`expires_at TIMESTAMP NOT NULL`) vs `002:42,100` (`MODIFY … TIMESTAMP NULL`) vs `cache/CacheManager.php:281-283` (writes `expires_at = NULL`).
Permanent cache rows are written with `expires_at = NULL`. That only works if migration 002 made the column nullable. If 002 didn't fully apply, inserting `NULL` either errors (strict mode) or coerces to `0000-00-00`/`CURRENT_TIMESTAMP` (non-strict), making every "permanent" row instantly expired.
**Fix:** Declare `expires_at TIMESTAMP NULL DEFAULT NULL` directly in migration 001, and verify 002's `MODIFY` post-install.

### M4. Renaming a collection regenerates its slug and dead-links shared URLs **[flagged]**
**Where:** `services/Collection/CollectionService.php:185-215`.
`update()` regenerates `slug` from the new name on every rename, so any previously-shared `/c/{username}/{old-slug}` link 404s — defeating the purpose of shareable collections.
**Fix:** Keep the existing slug on rename (only mint a new one on explicit request), or store slug history and 301 old → current.

### M5. Lucene query injection / unvalidated `collection` into the archive.org query **[flagged]**
**Where:** `services/ArchiveOrgService.php:424-461`; reached from `api/search.php:17-18`.
User-controlled `q` and `collection` are concatenated raw into the Archive.org Lucene query (`AND collection:{...}`). `http_build_query` URL-encodes the result (so this is **not** SSRF and not DB injection), but a caller can still alter query semantics (inject `AND`/`OR`/field filters, break out of the intended collection scope). `sort` is also passed through unvalidated.
**Fix:** Allow-list `collection` against `[a-zA-Z0-9_.-]`, validate `sort` against the known sort map, and quote user terms.

### M6. CORS allow-list is derived from the client-controlled `Host` header **[flagged]**
**Where:** `api/index.php:13-27`.
`$allowedOrigins` is built from `HTTP_HOST` and a matching `Origin` is reflected into `Access-Control-Allow-Origin`. On a host that doesn't pin `Host`, this can be coerced. Impact is limited (this is the info/router endpoint, no credentials echoed), but the pattern is unsafe and inconsistent with the hardened `api/.htaccess`.
**Fix:** Drop the dynamic list; compare `parse_url($origin, PHP_URL_HOST)` against `safe_host()`/`APP_URL` only.

### M7. `forgot-password` has no per-IP throttle **[flagged]**
**Where:** `api/auth/forgot-password.php`; `services/Auth/UserAuthService.php:304-342`.
Reset tokens are capped at 3/hr **per account**, but nothing limits how many distinct emails one client submits. An attacker can loop the endpoint over an email list to generate outbound mail volume and probe response timing. (Login *is* throttled per-IP; the reset flow is not.)
**Fix:** Apply the same per-IP `auth_attempts`-style throttle to `forgot-password`.

### M8. Password-reset email enumeration via timing **[flagged]**
**Where:** `services/Auth/UserAuthService.php:304-342`.
The "email unknown" branch returns immediately, while the "email exists" branch does extra DB work + token insert + SMTP send. The response-time delta lets an attacker enumerate registered emails despite the identical user-facing message.
**Fix:** Normalize work/timing across both branches (or enqueue mail asynchronously).

### M9. Legacy `AdminAuthService::authenticate` login path is unthrottled **[flagged]**
**Where:** `services/AdminAuthService.php:24-57`.
The new `UserAuthService::login` is rate-limited, but the legacy `admin_users` auth path has no throttling and no `auth_attempts` logging, giving an attacker an unthrottled brute-force channel where it's still wired.
**Fix:** Route the legacy path through the same throttle, or deprecate/remove it post-migration.

### M10. Service worker token / post-login CSRF staleness **[verified]**
**Where:** `src/js/services/ApiService.js:12`, `src/js/services/CollectionService.js:21`, `src/js/services/AuthService.js:180`; server rotates token at `UserAuthService.php:173`.
`login()` rotates `$_SESSION['csrf_token']` server-side, but the client's `<meta name="csrf-token">` and the per-module cached `_csrfToken` are not refreshed without a full navigation. A write performed after login *without* navigating would send the stale token and 403. Currently masked because the auth pages navigate (redirect) after login.
**Fix:** Centralize one token cache and invalidate it on auth-state change, or always re-read the meta tag for state-changing requests.

### M11. `progress_percent` is not clamped **[flagged]**
**Where:** `services/User/WatchHistoryService.php:35-48`.
`$percent = ($currentTime / $duration) * 100` with no `[0,100]` clamp and no rejection of negative/over-duration inputs. A client can store >100% (or negative), skewing "continue watching" UI and engagement metrics.
**Fix:** `max(0, min(100, $percent))` and reject negative `currentTime`/`duration`.

### M12. Admin moderation `delete` doesn't decrement parent `reply_count` **[flagged]**
**Where:** `services/Comments/CommentService.php:365-367` vs self-service `delete()` at `:184-189`.
Self-service `delete()` decrements the parent's `reply_count`; the admin `moderate(…, 'delete')` path soft-deletes without decrementing it. The displayed reply count then overstates visible replies and "show more replies" can over-report.
**Fix:** Decrement the parent `reply_count` in the moderation delete branch too.

### M13. `ON DELETE CASCADE` on `video_comments.user_id` destroys whole threads **[flagged]**
**Where:** `db/migrations/006_comments.sql:33-34`.
Deleting a user cascades to their comments, and because replies cascade on `parent_id`, deleting one user can wipe **other users' replies** under those threads — contradicting the soft-delete "keep threads for consistency" design.
**Fix:** `ON DELETE SET NULL` on `video_comments.user_id` and render "deleted user".

### M14. Electron static mount serves PHP source and `.env` in cleartext **[flagged]**
**Where:** `electron/server.js:282-285`.
`express.static(APP_ROOT)` serves the whole project root. PHP isn't executed, but the **raw source** of `db/config.php`, `bootstrap.php`, `.env`, and `.git/` is served as plain text — any page in the Electron window could `fetch('/.env')` and read DB credentials. (Secondary: Electron is optional and not part of the cPanel deployment.)
**Fix:** Serve only an explicit static subtree, or denylist `db/`, `.env`, `.git`, `*.php`.

### M15. `urlManager.parseUrlState` double-decodes the search param **[verified]**
**Where:** `src/js/utils/urlManager.js:57`.
`decodeURIComponent(params.get('search'))` — `URLSearchParams.get()` already returns a decoded value, so this decodes twice. A search containing a literal `%` throws `URIError`; `+`/`%`-sequences corrupt. The decoded value is then written into the search input.
**Fix:** Use `params.get('search')` directly; drop the extra `decodeURIComponent`.

---

## LOW

- **L1. `Database::transaction()` has no nesting guard [flagged]** — `db/Database.php:215-225`. Nested `beginTransaction()` throws; `rollback()` isn't guarded by `inTransaction()`. Use depth tracking + SAVEPOINTs and guard rollback.
- **L2. `Database::upsert()` returns unreliable `lastInsertId()` on the UPDATE branch [flagged]** — `db/Database.php:166-189`. Returns 0 for existing-row updates; document or `SELECT` the id.
- **L3. No `password_needs_rehash` on login [flagged]** — `services/Auth/UserAuthService.php:149-185`. Old-cost hashes never upgrade.
- **L4. `getStaleMetadata` NULL handling [flagged]** — `cache/CacheManager.php:370-377`. `last_refreshed < …` is NULL (not true) for never-refreshed permanent rows, so they're never picked up. Add `last_refreshed IS NULL OR …`.
- **L5. `cron/cache_warmer.php` has no wall-clock budget [flagged]** — warms 20 searches + every featured/recommended video serially with sleeps; killed mid-loop on hosts with a 30s limit. Add an elapsed-time break like `process_cache_queue.php`.
- **L6. `cleanExpiredCache()` loads all stale thumbnail rows with no `LIMIT` [flagged]** — `cache/CacheManager.php:626-630`. Batch the deletes. (Default retention 0/disabled, so latent.)
- **L7. CLI guard uses `php_sapi_name() !== 'cli'` [flagged]** — cron jobs would 403 themselves under a `cgi-fcgi` cron SAPI. Also allow `defined('STDIN')`.
- **L8. `formatFileSize(0)`/large-value edge [flagged]** — `src/js/utils/helpers.js:116-121`. `log(0)` → `-Infinity` index; guarded by the `!bytes` check for 0 but not for sub-1-byte values. Minor.
- **L9. `SearchHistoryService` DISTINCT doesn't dedupe latest-per-query [flagged]** — `services/User/SearchHistoryService.php:23-32`. Use `GROUP BY query ORDER BY MAX(searched_at)`.
- **L10. Thumbnail `delete()` uses unguarded `unlink()` [flagged]** — `cache/ThumbnailCache.php:248-258`. Emits a warning on permission error; use `@unlink` + existence check.
- **L11. Installer reflects raw DB connection error to the browser [flagged]** — `install.php:177-180`. Escaped (no XSS) but leaks DB host/user to whoever reaches an un-installed instance. Log detail, show generic message.

---

## Documentation drift

- **README "Known gaps" is stale.** `README.md:437-440` states the app has "no CSRF tokens" and "no login rate-limiting." Both are **implemented**: a full CSRF system lives in `bootstrap.php` (`csrf_token`/`csrf_verify`/`csrf_meta_tag`) and is enforced by `ApiController::requireCsrf()` on every non-GET endpoint; login throttling exists in `UserAuthService::isLoginThrottled()` with migration `005_auth_throttle.sql`. Update the README so the security posture isn't understated.
- **README migration list says "001 → 005"** (`README.md:84`, `:114`) but `006_comments.sql` exists and is required for the comments feature. Update the manual-setup step and installer notes.
- **README "PHP 7.2+"** appears in the Tech Stack (`:459`) while Requirements say 7.4+. Pick one (code uses `?:`/typed properties consistent with 7.4+).

---

## Verified clean (checked, not bugs)

- **SQL injection:** all user data is bound; `insert/update/delete` build placeholders from server-controlled column names only. No string concatenation of user input into queries (the Lucene `q` is the only user-influenced query text, and it goes to archive.org, not the DB).
- **Server-side XSS:** every dynamic echo in `index.php`, `player.php`, `collection(s).php`, the auth pages, `account.php`, and all admin views is escaped (`htmlspecialchars ENT_QUOTES` / `escapeAttr`). OG/meta tags are escaped; `?video=` is filtered to `[a-zA-Z0-9_-]` server-side; inline JSON uses `JSON_HEX_TAG`.
- **Host-header poisoning:** `safe_host()`/`safe_base_url()` ignore `HTTP_HOST` and pin to `APP_URL`/`SERVER_NAME` for emailed links.
- **Tokens at rest:** remember-me, reset, and verify tokens are SHA-256 hashed; raw tokens are 32 bytes from `random_bytes`; reset tokens are single-use with prior-token invalidation.
- **Thumbnail path traversal:** ids are sanitized to `[a-zA-Z0-9_-]` before use as filenames on both the serve and write paths; `realpath()` normalizes. Not exploitable.
- **SSRF:** metadata/thumbnail proxies pin the host to `archive.org`.
- **Service worker:** the no-cache list correctly excludes `auth/`, `bookmarks.php`, `history.php`, `user.php`, `collections.php`, `cache.php`, `stats.php`; per-user comment responses fall through to network. No private data is cached cross-user. SW registration is relative (subdirectory-install safe).
- **Open redirect:** `afc_safe_next()` rejects `//host`, `\`, scheme URLs, and non-URL-safe chars (server + client).
- **CSRF header on the client:** every POST/DELETE fetch wrapper sends `X-CSRF-Token`.
- **Charset:** schema + DSN are `utf8mb4` end-to-end; emoji in titles/comments insert correctly.
- **No persistent PDO connections** (no shared-host connection exhaustion from that).
- **Electron:** `nodeIntegration:false` + `contextIsolation:true`; no `child_process`/command injection.

---

## UI/UX review & polish

The frontend is, on inspection, **already mature and professionally built**. The pass below records (1) the strengths confirmed during review, (2) the concrete change applied in this pass, and (3) prioritized recommendations deliberately left as recommendations because they are cross-cutting design decisions that should be validated visually (this environment has no MySQL/live archive.org, so the running JS app can't be rendered here — editing a tuned UI blind would risk regressions).

### Strengths confirmed (no change needed)
- **Design system:** token-driven (`styles.css:11+`) with **documented WCAG-AA contrast fixes** baked into the text-color tokens (`styles.css:25-29`).
- **Accessibility:** skip links on the two primary pages, `:focus-visible` usage, an `.sr-only` utility, ARIA roles on loading/error regions (`index.php:589,596`), and a **universal `prefers-reduced-motion` reset** (`styles.css:2989`) that covers every page loading the stylesheet (including the animation-heavy player).
- **Theming:** `system` theme honored via `matchMedia` with a **live change listener** (`index.php:653`), and a no-flash inline theme bootstrap in `<head>` on every page.
- **Performance:** `preconnect`/`dns-prefetch` to fonts + archive.org, `preload` of CSS/JS, above-the-fold thumbnail `preload` with `fetchpriority="high"`, lazy `loading="lazy"` images.
- **PWA / resilience:** manifest, install-scoped relative SW registration, and an **offline page that auto-recovers** by probing the API every 5s and on `online`/`focus`.
- **Polish details:** inline-SVG favicon (no file upload needed on cPanel — smart), `⌘K` / `/` search shortcuts with platform-aware hint, back-to-top, accessible password-reveal toggle, correct `autocomplete` tokens on auth forms, guest→account merge prompt, first-load legal disclaimer modal, and `JSON_HEX_TAG`-escaped inline JSON config.
- **Consistency:** `<html lang="en">` on every page; dynamic output uniformly escaped.

### Change applied in this pass
- **Added a `<noscript>` fallback** to `index.php` and `player.php` (+ `.noscript-banner` styles in `styles.css`). The entire results/player surface is JS-rendered, so with scripting off (or if the module bundle is blocked) the user previously saw a **blank, broken-looking page**. The banner explains the requirement and links to the underlying archive.org collection (the player deep-links to the specific item when a `?video=` id is present). **Zero risk to the normal experience** — `<noscript>` renders nothing when JS is enabled. PHP lints clean; CSS uses existing tokens.

### Recommendations (prioritized — validate visually before shipping)
1. **Persistent footer with attribution.** The "we don't host this — report to the Internet Archive" message currently appears only in the **one-time** disclaimer modal. A small, always-present `<footer>` (archive.org attribution + Report-a-problem / DMCA / Terms links, mirroring `partials/head-common.php`'s modal links) reinforces the legal posture for a site streaming third-party content and is the single biggest "looks finished" win. Ship as a shared `partials/footer.php` included on all content pages for consistency.
2. **App-load failure detection.** `<noscript>` only covers *disabled* JS. If `app.js`/`player.js` (ES modules) fail to load or throw during init (proxy stripping, network blip, ancient browser), the page stays blank with JS "on." Add a tiny inline, non-module watchdog that reveals the existing `#error` element with a "couldn't load — retry / open archive.org" message if the app hasn't initialized within ~8s. Tune the timeout to avoid false positives on slow links.
3. **Fix the search input double-decode (bug M15).** `urlManager.js:57` double-`decodeURIComponent`s the `search` param, so any query containing `%` throws and breaks the search box — a user-facing UX defect with a one-line fix.
4. **Centralize the CSRF token (bug M10).** Harmless today (auth pages navigate after login) but will surface as a 403 in any future write-without-navigation flow; one shared, invalidate-on-auth-change token cache removes the footgun.
5. **Verify first-run empty states** for bookmarks, collections, and watch history have friendly copy + a call-to-action (the `.no-results*` styles exist at `styles.css:2224+`; confirm each surface uses them rather than rendering nothing).
6. **`theme-color` light/dark variants** via `<meta name="theme-color" media="(prefers-color-scheme: …)">` for nicer mobile browser chrome in light mode.
7. **`offline.html` branding:** it hardcodes `#ff0000`/`#0a0a0b`. It's a static SW-served file so it can't read admin settings at runtime — consider templating it at install time (or neutralizing the accent) so a re-branded install stays consistent offline.

> None of items 1–7 are blockers. Items 3 and 4 are the documented bugs that most directly affect the live experience; the rest are incremental polish.

