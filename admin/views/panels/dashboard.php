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
                                <span class="stat-card-label">Members</span>
                                <div class="stat-card-icon blue">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value" id="dashStatMembers">—</div>
                            <div class="stat-card-desc"><span id="dashStatMembersNew">…</span> new this week</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Active (7 days)</span>
                                <div class="stat-card-icon green">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value" id="dashStatActive">—</div>
                            <div class="stat-card-desc"><span id="dashStatActive24h">…</span> in last 24h</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Comments (7 days)</span>
                                <div class="stat-card-icon purple">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value" id="dashStatComments">—</div>
                            <div class="stat-card-desc">
                                <span id="dashStatReportsPending">…</span> reports pending
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <span class="stat-card-label">Video views (7 days)</span>
                                <div class="stat-card-icon orange">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                </div>
                            </div>
                            <div class="stat-card-value" id="dashStatViews">—</div>
                            <div class="stat-card-desc"><span id="dashStatViews24h">…</span> in last 24h</div>
                        </div>
                    </div>

                    <div class="quick-actions">
                        <a class="quick-action" onclick="switchPanel('metrics'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--accent-soft); color: var(--accent);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">View Metrics</div>
                                <div class="quick-action-desc">Charts and top lists</div>
                            </div>
                        </a>
                        <a class="quick-action" onclick="switchPanel('users'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--success-bg); color: var(--success);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">Manage Users</div>
                                <div class="quick-action-desc">Roles, signups, activity</div>
                            </div>
                        </a>
                        <a class="quick-action" onclick="switchPanel('comments-mod'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--purple-soft); color: var(--purple);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">Moderate Comments</div>
                                <div class="quick-action-desc">Reports, hidden, recent</div>
                            </div>
                        </a>
                        <a class="quick-action" onclick="switchPanel('staff-picks'); return false;" href="#">
                            <div class="quick-action-icon" style="background: var(--warning-bg); color: var(--warning);">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            </div>
                            <div>
                                <div class="quick-action-text">Manage Staff Picks</div>
                                <div class="quick-action-desc">Curate featured videos</div>
                            </div>
                        </a>
                    </div>

                    <div class="dashboard-feeds">
                        <div class="info-card">
                            <div class="info-card-title">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                                Recent signups
                                <a href="#" onclick="switchPanel('users'); return false;" class="dashboard-feed-link">View all</a>
                            </div>
                            <ul class="dashboard-feed" id="dashRecentSignups">
                                <li class="dashboard-feed-empty">Loading…</li>
                            </ul>
                        </div>
                        <div class="info-card">
                            <div class="info-card-title">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                                Recent comments
                                <a href="#" onclick="switchPanel('comments-mod'); return false;" class="dashboard-feed-link">Moderate</a>
                            </div>
                            <ul class="dashboard-feed" id="dashRecentComments">
                                <li class="dashboard-feed-empty">Loading…</li>
                            </ul>
                        </div>
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
