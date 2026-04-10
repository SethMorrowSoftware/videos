                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Appearance</h2>
                                <p class="card-subtitle">Customize colors and theme</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10c.55 0 1-.45 1-1v-.53a1 1 0 011-1h1.03c2.76 0 5-2.24 5-5 0-4.97-4.49-8.47-8.03-8.47z"/></svg> Colors
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Brand Color</label>
                                        <div class="color-picker-wrapper">
                                            <input type="color" class="color-picker" id="brandColor" value="<?= htmlspecialchars($site_settings['brandColor']) ?>">
                                            <span class="color-value" id="brandColorValue"><?= htmlspecialchars($site_settings['brandColor']) ?></span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Accent Color</label>
                                        <div class="color-picker-wrapper">
                                            <input type="color" class="color-picker" id="accentColor" value="<?= htmlspecialchars($site_settings['accentColor']) ?>">
                                            <span class="color-value" id="accentColorValue"><?= htmlspecialchars($site_settings['accentColor']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg> Theme
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Default Theme</label>
                                        <select class="form-select" id="defaultTheme">
                                            <option value="dark" <?= $site_settings['defaultTheme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                                            <option value="light" <?= $site_settings['defaultTheme'] === 'light' ? 'selected' : '' ?>>Light</option>
                                            <option value="system" <?= $site_settings['defaultTheme'] === 'system' ? 'selected' : '' ?>>System Preference</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Card Style</label>
                                        <select class="form-select" id="cardStyle">
                                            <option value="modern" <?= $site_settings['cardStyle'] === 'modern' ? 'selected' : '' ?>>Modern</option>
                                            <option value="classic" <?= $site_settings['cardStyle'] === 'classic' ? 'selected' : '' ?>>Classic</option>
                                            <option value="compact" <?= $site_settings['cardStyle'] === 'compact' ? 'selected' : '' ?>>Compact</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Enable Theme Toggle</div>
                                        <div class="toggle-description">Allow users to switch between light and dark mode</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="enableThemeToggle" <?= ($site_settings['enableThemeToggle'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div id="appearanceStatus"></div>
                        </div>
                    </div>

                    <div class="preview-section">
                        <div class="preview-header">
                            <div class="preview-title">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Live Preview
                            </div>
                            <a href="index.php" target="_blank" class="link">Open in new tab →</a>
                        </div>
                        <div class="preview-frame">
                            <iframe src="index.php" class="preview-iframe" id="previewFrame"></iframe>
                        </div>
                    </div>
