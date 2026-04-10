    <!-- Login Form -->
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <div class="login-logo-icon">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </div>
                <h1 class="login-title">Admin Panel</h1>
                <p class="login-subtitle"><?= htmlspecialchars($site_settings['siteName'] ?? 'Archive Film Club') ?></p>
            </div>
            <?php if (!empty($login_error)): ?>
            <div class="login-error" role="alert" aria-live="polite">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                <?= htmlspecialchars($login_error) ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <?php if ($useDatabase): ?>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-input" placeholder="Enter username" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Enter password" required autocomplete="current-password">
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Enter admin password" required autofocus>
                </div>
                <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 16px; text-align: center;">
                    <a href="install.php" style="color: var(--accent);">Run installer</a> to set up MySQL authentication
                </p>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top: 8px;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                    Sign In
                </button>
            </form>
        </div>
    </div>
