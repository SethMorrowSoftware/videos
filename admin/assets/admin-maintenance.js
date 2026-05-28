/**
 * admin-maintenance.js — drives the System → Maintenance panel.
 *
 * Talks to api/admin/maintenance.php (full-admin only). CSRF is read from the
 * <meta name="csrf-token"> tag, matching admin-metrics.js. Wrapped in an IIFE
 * because admin.js runs in global scope and we must not collide with it.
 */
(function () {
    'use strict';

    const API = 'api/admin/maintenance.php';

    function csrfToken() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') || '' : '';
    }

    function toast(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'info');
        }
    }

    let authLostHandled = false;
    function handleAuthLost() {
        if (authLostHandled) return;
        authLostHandled = true;
        window.location.href = 'admin.php';
    }

    async function apiGet(action, params = {}) {
        const q = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API}?${q}`, { credentials: 'same-origin' });
        if (res.status === 401 || res.status === 403) { handleAuthLost(); throw new Error('Session expired'); }
        const json = await res.json().catch(() => ({ success: false, error: 'Bad response' }));
        if (!res.ok || !json.success) throw new Error(json.error || 'Request failed');
        return json;
    }

    async function apiPost(body = {}) {
        const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(body),
        });
        if (res.status === 401 || res.status === 403) { handleAuthLost(); throw new Error('Session expired'); }
        const json = await res.json().catch(() => ({ success: false, error: 'Bad response' }));
        if (!res.ok || !json.success) throw new Error(json.error || 'Request failed');
        return json;
    }

    /**
     * POST that yields a file download. The endpoint returns either the file
     * (with Content-Disposition) or a JSON error — detect via content-type.
     */
    async function downloadFile(body, fallbackName) {
        const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(body),
        });
        if (res.status === 401 || res.status === 403) { handleAuthLost(); throw new Error('Session expired'); }
        const ct = res.headers.get('Content-Type') || '';
        if (!res.ok || ct.indexOf('application/json') !== -1) {
            const j = await res.json().catch(() => ({ error: 'Download failed' }));
            throw new Error(j.error || 'Download failed');
        }
        const blob = await res.blob();
        const cd = res.headers.get('Content-Disposition') || '';
        const m = cd.match(/filename="?([^"]+)"?/);
        const name = m ? m[1] : fallbackName;
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        // Revoke a tick later so the download has committed.
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    // -------- formatters --------
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }
    function formatBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        const units = ['KB', 'MB', 'GB', 'TB'];
        let i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < units.length - 1);
        return n.toFixed(n < 10 ? 1 : 0) + ' ' + units[i];
    }
    function formatCount(n) {
        n = Number(n) || 0;
        return n.toLocaleString();
    }

    // -------- status --------
    let statusLoaded = false;

    async function loadStatus(force) {
        const el = document.getElementById('maintStatus');
        if (!el) return;
        if (statusLoaded && !force) return;
        el.innerHTML = '<p class="maint-muted">Loading database status…</p>';
        try {
            const { status } = await apiGet('status');
            renderStatus(status);
            statusLoaded = true;
        } catch (err) {
            el.innerHTML = `<p class="maint-error">${escapeHtml(err.message || 'Could not load status')}</p>`;
        }
    }

    function renderStatus(s) {
        const el = document.getElementById('maintStatus');
        if (!el) return;

        const rows = (s.tables || []).map((t) => `
            <tr>
                <td>${escapeHtml(t.name)}${t.is_cache ? ' <span class="maint-tag">cache</span>' : ''}</td>
                <td class="maint-num">${formatCount(t.rows)}</td>
                <td class="maint-num">${formatBytes(t.size_bytes)}</td>
            </tr>`).join('');

        const lim = s.limits || {};
        const caps = s.capabilities || {};

        el.innerHTML = `
            <div class="maint-stat-grid">
                <div class="maint-stat"><span class="maint-stat-num">${escapeHtml(s.database || '—')}</span><span class="maint-stat-label">Database</span></div>
                <div class="maint-stat"><span class="maint-stat-num">${formatCount(s.table_count)}</span><span class="maint-stat-label">Tables</span></div>
                <div class="maint-stat"><span class="maint-stat-num">${formatCount(s.total_rows)}</span><span class="maint-stat-label">Total rows</span></div>
                <div class="maint-stat"><span class="maint-stat-num">${formatBytes(s.total_size_bytes)}</span><span class="maint-stat-label">Total size</span></div>
            </div>
            <div class="maint-table-wrap">
                <table class="maint-table">
                    <thead><tr><th>Table</th><th class="maint-num">Rows</th><th class="maint-num">Size</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <p class="maint-muted">
                ${formatCount((s.migrations || []).length)} migration file(s) on disk ·
                PHP max execution ${escapeHtml(String(lim.max_execution_time))}s ·
                memory ${escapeHtml(String(lim.memory_limit))} ·
                zip ${caps.zip ? 'available' : 'unavailable'}
            </p>`;
    }

    // -------- busy helper --------
    async function withBusy(btn, labelWhileBusy, fn) {
        if (!btn) return fn();
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = escapeHtml(labelWhileBusy);
        try {
            return await fn();
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }

    function showResult(targetId, html, isError) {
        const el = document.getElementById(targetId);
        if (!el) return;
        el.hidden = false;
        el.className = 'maint-result ' + (isError ? 'maint-result-error' : 'maint-result-ok');
        el.innerHTML = html;
    }

    // -------- wiring --------
    function wire() {
        const panel = document.getElementById('panel-maintenance');
        if (!panel) return; // not a full admin / panel absent

        const refreshBtn = document.getElementById('maintRefreshStatus');
        if (refreshBtn) refreshBtn.addEventListener('click', () => loadStatus(true));

        // Backup
        const backupBtn = document.getElementById('maintBackupBtn');
        if (backupBtn) {
            backupBtn.addEventListener('click', () => {
                const scopeEl = document.querySelector('input[name="maintBackupScope"]:checked');
                const includeCaches = !scopeEl || scopeEl.value === 'full';
                withBusy(backupBtn, 'Preparing…', async () => {
                    try {
                        await downloadFile({ action: 'backup', include_caches: includeCaches }, 'afc-backup.sql');
                        toast('Backup download started', 'success');
                    } catch (err) {
                        toast(err.message || 'Backup failed', 'error');
                    }
                });
            });
        }

        // Thumbnails zip
        const thumbsBtn = document.getElementById('maintThumbsBtn');
        if (thumbsBtn) {
            thumbsBtn.addEventListener('click', () => {
                withBusy(thumbsBtn, 'Zipping…', async () => {
                    try {
                        await downloadFile({ action: 'download-thumbnails' }, 'afc-thumbnails.zip');
                        toast('Thumbnail download started', 'success');
                    } catch (err) {
                        toast(err.message || 'No thumbnails to download', 'error');
                    }
                });
            });
        }

        // Refresh actions
        const migrateBtn = document.getElementById('maintMigrateBtn');
        if (migrateBtn) {
            migrateBtn.addEventListener('click', () => withBusy(migrateBtn, 'Running…', async () => {
                try {
                    const { result } = await apiPost({ action: 'refresh-schema' });
                    showResult('maintRefreshResult',
                        `Applied ${formatCount(result.statements_applied)} statement(s) across ${formatCount(result.files)} migration file(s); ${formatCount(result.statements_skipped)} already applied.`);
                    toast('Migrations complete', 'success');
                    loadStatus(true);
                } catch (err) {
                    showResult('maintRefreshResult', escapeHtml(err.message || 'Migration failed'), true);
                    toast(err.message || 'Migration failed', 'error');
                }
            }));
        }

        const cacheBtn = document.getElementById('maintCacheBtn');
        if (cacheBtn) {
            cacheBtn.addEventListener('click', () => withBusy(cacheBtn, 'Working…', async () => {
                try {
                    const { result } = await apiPost({ action: 'refresh-cache' });
                    const exp = result.expired_removed || {};
                    const expN = Object.values(exp).reduce((a, b) => a + (Number(b) || 0), 0);
                    showResult('maintRefreshResult',
                        `Search cache flushed · ${formatCount(expN)} expired row(s) pruned · ${formatCount(result.stuck_queue_reaped)} stuck queue item(s) reaped.`);
                    toast('Caches refreshed', 'success');
                    loadStatus(true);
                } catch (err) {
                    showResult('maintRefreshResult', escapeHtml(err.message || 'Cache refresh failed'), true);
                    toast(err.message || 'Cache refresh failed', 'error');
                }
            }));
        }

        const metaBtn = document.getElementById('maintMetadataBtn');
        if (metaBtn) {
            metaBtn.addEventListener('click', () => withBusy(metaBtn, 'Fetching…', async () => {
                try {
                    const { result } = await apiPost({ action: 'refresh-metadata', limit: 25 });
                    const r = result.results || {};
                    const n = r.refreshed != null ? r.refreshed : (r.processed != null ? r.processed : '');
                    showResult('maintRefreshResult',
                        `Re-fetched stale metadata from Archive.org${n !== '' ? ' (' + formatCount(n) + ' item(s))' : ''}.`);
                    toast('Metadata refresh complete', 'success');
                    loadStatus(true);
                } catch (err) {
                    showResult('maintRefreshResult', escapeHtml(err.message || 'Metadata refresh failed'), true);
                    toast(err.message || 'Metadata refresh failed', 'error');
                }
            }));
        }

        // Danger zone — content reset (type-to-confirm)
        const confirmInput = document.getElementById('maintResetConfirm');
        const resetBtn = document.getElementById('maintResetBtn');
        if (confirmInput && resetBtn) {
            const expected = (confirmInput.getAttribute('data-expected') || '').trim().toLowerCase();
            const sync = () => {
                resetBtn.disabled = confirmInput.value.trim().toLowerCase() !== expected;
            };
            confirmInput.addEventListener('input', sync);
            sync();

            resetBtn.addEventListener('click', () => {
                if (resetBtn.disabled) return;
                if (!window.confirm('This permanently deletes all community + cache data. This cannot be undone. Continue?')) {
                    return;
                }
                withBusy(resetBtn, 'Resetting…', async () => {
                    try {
                        const { result } = await apiPost({ action: 'content-reset', confirm: confirmInput.value });
                        showResultReset(
                            `Removed ${formatCount(result.rows_removed)} row(s) across ${formatCount(result.tables_cleared)} table(s); deleted ${formatCount(result.thumbnail_files_deleted)} thumbnail file(s).`);
                        toast('Content reset complete', 'success');
                        confirmInput.value = '';
                        sync();
                        loadStatus(true);
                    } catch (err) {
                        showResultReset(escapeHtml(err.message || 'Reset failed'), true);
                        toast(err.message || 'Reset failed', 'error');
                    }
                });
            });
        }
    }

    function showResultReset(html, isError) {
        showResult('maintResetResult', html, isError);
    }

    document.addEventListener('DOMContentLoaded', wire);

    // Lazy-load status the first time the panel is revealed (admin.js fires
    // this when a sidebar item is clicked).
    document.addEventListener('admin:panel-shown', (e) => {
        if (e.detail === 'maintenance') loadStatus(false);
    });
})();
