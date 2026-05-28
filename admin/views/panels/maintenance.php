<?php
/**
 * Maintenance / Database panel — full admins only (gated by $canMaintain in
 * the sidebar + layout includes). Driven by admin/assets/admin-maintenance.js
 * against api/admin/maintenance.php. The site name is printed into the
 * danger-zone confirm hint so the operator knows the exact phrase to type.
 */
$maintSiteName = $site_settings['siteName'] ?? 'Archive Film Club';
?>
                    <!-- Database status -->
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Database</h2>
                                <p class="card-subtitle">Live size and row counts for every table</p>
                            </div>
                            <button class="btn btn-secondary btn-sm" id="maintRefreshStatus" type="button">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                                Refresh
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="maintStatus" class="maint-status">
                                <p class="maint-muted">Loading database status…</p>
                            </div>
                        </div>
                    </div>

                    <!-- Backup -->
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Backup</h2>
                                <p class="card-subtitle">Download a portable SQL snapshot you can restore later</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> What to include
                                </div>
                                <label class="maint-radio">
                                    <input type="radio" name="maintBackupScope" value="full" checked>
                                    <span><strong>Full database</strong> — every table, schema + data. Complete, but cache tables make it larger.</span>
                                </label>
                                <label class="maint-radio">
                                    <input type="radio" name="maintBackupScope" value="nocache">
                                    <span><strong>Database minus caches</strong> — skips the regenerable <code>*_cache</code> tables for a much smaller backup of the data that matters.</span>
                                </label>

                                <div class="maint-actions">
                                    <button class="btn btn-primary" id="maintBackupBtn" type="button">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        Download SQL backup
                                    </button>
                                    <button class="btn btn-secondary" id="maintThumbsBtn" type="button">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                        Download thumbnails (zip)
                                    </button>
                                </div>
                                <p class="maint-muted">Backups download straight to your computer — nothing is written into the web root.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Refresh & maintenance -->
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Refresh &amp; maintenance</h2>
                                <p class="card-subtitle">Apply updates and rebuild regenerable data</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="maint-task">
                                <div class="maint-task-info">
                                    <div class="toggle-label">Apply database updates</div>
                                    <div class="toggle-description">Run any pending schema migrations after a code upgrade. Safe to run anytime — already-applied steps are skipped.</div>
                                </div>
                                <button class="btn btn-secondary" id="maintMigrateBtn" type="button">Run migrations</button>
                            </div>

                            <div class="maint-task">
                                <div class="maint-task-info">
                                    <div class="toggle-label">Clear &amp; re-warm caches</div>
                                    <div class="toggle-description">Flush the search cache, prune expired metadata/thumbnails, and re-warm a batch of stale items.</div>
                                </div>
                                <button class="btn btn-secondary" id="maintCacheBtn" type="button">Refresh caches</button>
                            </div>

                            <div class="maint-task">
                                <div class="maint-task-info">
                                    <div class="toggle-label">Re-fetch metadata from Archive.org</div>
                                    <div class="toggle-description">Re-pull stale item metadata and thumbnails. Bounded per run to stay within the host's time limit.</div>
                                </div>
                                <button class="btn btn-secondary" id="maintMetadataBtn" type="button">Re-fetch metadata</button>
                            </div>

                            <div id="maintRefreshResult" class="maint-result" hidden></div>
                        </div>
                    </div>

                    <!-- Danger zone -->
                    <div class="card maint-danger">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Danger zone</h2>
                                <p class="card-subtitle">Destructive actions — take a backup first</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="maint-task">
                                <div class="maint-task-info">
                                    <div class="toggle-label">Reset content &amp; community data</div>
                                    <div class="toggle-description">
                                        Permanently deletes comments, watch history, bookmarks, collections, search history and all caches.
                                        <strong>Keeps</strong> your schema, every user account, site settings, branding, staff picks and featured sections.
                                    </div>
                                </div>
                            </div>
                            <div class="maint-confirm">
                                <label for="maintResetConfirm">Type <code><?= htmlspecialchars($maintSiteName, ENT_QUOTES) ?></code> to confirm:</label>
                                <input type="text" id="maintResetConfirm" class="maint-input" autocomplete="off"
                                       data-expected="<?= htmlspecialchars($maintSiteName, ENT_QUOTES) ?>"
                                       placeholder="<?= htmlspecialchars($maintSiteName, ENT_QUOTES) ?>">
                                <button class="btn btn-danger" id="maintResetBtn" type="button" disabled>Reset content data</button>
                            </div>
                            <div id="maintResetResult" class="maint-result" hidden></div>
                        </div>
                    </div>
