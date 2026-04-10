            <header class="admin-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M3 12h18M3 6h18M3 18h18"/>
                        </svg>
                    </button>
                    <div class="header-breadcrumb">
                        <span>Admin</span>
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                        <span class="header-title" id="panelTitle">Dashboard</span>
                    </div>
                </div>
                <div class="header-actions">
                    <span class="unsaved-dot" id="unsavedDot" title="Unsaved changes"></span>
                    <button class="btn btn-primary btn-sm" id="globalSaveBtn" onclick="saveCurrentPanel()" style="display: none;">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Changes
                    </button>
                    <?php if ($admin_user): ?>
                    <div class="header-user">
                        <div class="header-user-avatar">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <span><?= htmlspecialchars($admin_user['username'] ?? 'Admin') ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </header>
