                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Site Settings</h2>
                                <p class="card-subtitle">Configure basic site information</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg> General
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Site Name</label>
                                        <input type="text" class="form-input" id="siteName" value="<?= htmlspecialchars($site_settings['siteName']) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tagline</label>
                                        <input type="text" class="form-input" id="siteTagline" value="<?= htmlspecialchars($site_settings['tagline']) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Default Behavior
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Default Collection</label>
                                        <select class="form-select" id="defaultCollection">
                                            <option value="all_videos" <?= $site_settings['defaultCollection'] === 'all_videos' ? 'selected' : '' ?>>All Videos</option>
                                            <option value="feature_films" <?= $site_settings['defaultCollection'] === 'feature_films' ? 'selected' : '' ?>>Feature Films</option>
                                            <option value="classic_tv" <?= $site_settings['defaultCollection'] === 'classic_tv' ? 'selected' : '' ?>>Classic TV</option>
                                            <option value="animationandcartoons" <?= $site_settings['defaultCollection'] === 'animationandcartoons' ? 'selected' : '' ?>>Animation & Cartoons</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Default Sort</label>
                                        <select class="form-select" id="defaultSort">
                                            <option value="downloads" <?= $site_settings['defaultSort'] === 'downloads' ? 'selected' : '' ?>>Most Downloaded</option>
                                            <option value="date" <?= $site_settings['defaultSort'] === 'date' ? 'selected' : '' ?>>Date (Newest)</option>
                                            <option value="title" <?= $site_settings['defaultSort'] === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
                                            <option value="relevance" <?= $site_settings['defaultSort'] === 'relevance' ? 'selected' : '' ?>>Relevance</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div id="siteSettingsStatus"></div>
                        </div>
                    </div>
