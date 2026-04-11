                    <?php if (!empty($adminPasswordFallbackActive)): ?>
                    <div class="dashboard-warning" role="alert" style="margin-bottom:20px;padding:14px 18px;border-radius:10px;border:1px solid var(--warning,#f5a623);background:var(--warning-bg,#fff6e5);color:var(--warning,#8a5a00);display:flex;gap:12px;align-items:flex-start;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        <div>
                            <strong>Break-glass admin password is still active.</strong>
                            <div style="margin-top:4px;font-size:13px;line-height:1.5;">
                                A database admin account exists on this install, but <code>ADMIN_PASSWORD</code> is still set in your <code>.env</code> file. That gives anyone with the env file a second way to sign in. Remove the <code>ADMIN_PASSWORD=</code> line (or leave it blank) and reload this page.
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="dashboard-welcome">
                        <h2>Welcome back<?= $admin_user ? ', ' . htmlspecialchars($admin_user['username'] ?? 'Admin') : '' ?></h2>
                        <p>Here's an overview of your <?= htmlspecialchars($site_settings['siteName'] ?? 'Archive Film Club') ?> site.</p>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Staff Picks</span>
                                <div class="stat-card-icon blue">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value"><?= count($current_recommendations) ?></div>
                            <div class="stat-card-desc">Curated videos</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Sections</span>
                                <div class="stat-card-icon purple">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value"><?= count($featured_sections) ?></div>
                            <div class="stat-card-desc">Featured sections</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Theme</span>
                                <div class="stat-card-icon green">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10c.55 0 1-.45 1-1v-.53a1 1 0 011-1h1.03c2.76 0 5-2.24 5-5 0-4.97-4.49-8.47-8.03-8.47z"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value" style="font-size: 22px; text-transform: capitalize;"><?= htmlspecialchars($site_settings['defaultTheme'] ?? 'Dark') ?></div>
                            <div class="stat-card-desc">Active theme</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Storage</span>
                                <div class="stat-card-icon orange">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value" style="font-size: 22px;"><?= $useDatabase ? 'MySQL' : 'JSON' ?></div>
                            <div class="stat-card-desc"><?= $useDatabase ? 'Database connected' : 'File-based storage' ?></div>
                        </div>
                    </div>

                    <div class="quick-actions">
                        <a class="quick-action" onclick="switchPanel('staff-picks'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--accent-soft); color: var(--accent);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">Manage Staff Picks</div>
                                <div class="quick-action-desc">Curate featured videos</div>
                            </div>
                        </a>
                        <a class="quick-action" onclick="switchPanel('sections'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--purple-soft); color: var(--purple);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">Featured Sections</div>
                                <div class="quick-action-desc">Organize homepage content</div>
                            </div>
                        </a>
                        <a class="quick-action" onclick="switchPanel('appearance'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--success-bg); color: var(--success);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10c.55 0 1-.45 1-1v-.53a1 1 0 011-1h1.03c2.76 0 5-2.24 5-5 0-4.97-4.49-8.47-8.03-8.47z"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">Appearance</div>
                                <div class="quick-action-desc">Colors, theme, card styles</div>
                            </div>
                        </a>
                        <a class="quick-action" href="index.php" target="_blank">
                            <div class="quick-action-icon" style="background: var(--warning-bg); color: var(--warning);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">View Live Site</div>
                                <div class="quick-action-desc">Opens in a new tab</div>
                            </div>
                        </a>
                    </div>

                    <div class="info-cards">
                        <div class="info-card">
                            <div class="info-card-title">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                                Site Configuration
                            </div>
                            <ul class="info-card-list">
                                <li>
                                    <span class="label">Site Name</span>
                                    <span class="value"><?= htmlspecialchars($site_settings['siteName']) ?></span>
                                </li>
                                <li>
                                    <span class="label">Default Collection</span>
                                    <span class="value" style="text-transform: capitalize;"><?= str_replace('_', ' ', htmlspecialchars($site_settings['defaultCollection'])) ?></span>
                                </li>
                                <li>
                                    <span class="label">Card Style</span>
                                    <span class="value" style="text-transform: capitalize;"><?= htmlspecialchars($site_settings['cardStyle'] ?? 'modern') ?></span>
                                </li>
                                <li>
                                    <span class="label">Bookmarks</span>
                                    <span class="badge <?= ($site_settings['enableBookmarks'] ?? false) ? 'badge-success' : 'badge-warning' ?>">
                                        <?= ($site_settings['enableBookmarks'] ?? false) ? 'Enabled' : 'Disabled' ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div class="info-card">
                            <div class="info-card-title">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                System Status
                            </div>
                            <ul class="info-card-list">
                                <li>
                                    <span class="label">Storage Mode</span>
                                    <span class="badge <?= $useDatabase ? 'badge-success' : 'badge-warning' ?>">
                                        <?= $useDatabase ? 'MySQL' : 'JSON Files' ?>
                                    </span>
                                </li>
                                <li>
                                    <span class="label">Authentication</span>
                                    <span class="value"><?= $useDatabase ? 'Database Auth' : 'Password Auth' ?></span>
                                </li>
                                <li>
                                    <span class="label">Watch History</span>
                                    <span class="badge <?= ($site_settings['enableWatchHistory'] ?? false) ? 'badge-success' : 'badge-warning' ?>">
                                        <?= ($site_settings['enableWatchHistory'] ?? false) ? 'Enabled' : 'Disabled' ?>
                                    </span>
                                </li>
                                <li>
                                    <span class="label">Theme Toggle</span>
                                    <span class="badge <?= ($site_settings['enableThemeToggle'] ?? true) ? 'badge-success' : 'badge-warning' ?>">
                                        <?= ($site_settings['enableThemeToggle'] ?? true) ? 'Enabled' : 'Disabled' ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
