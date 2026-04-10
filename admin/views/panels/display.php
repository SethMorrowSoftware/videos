                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Display Options</h2>
                                <p class="card-subtitle">Control what information is shown</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg> Video Card Information
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Show Download Count</div>
                                        <div class="toggle-description">Display number of downloads on video cards</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="showDownloadCount" <?= ($site_settings['showDownloadCount'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Show Creator Name</div>
                                        <div class="toggle-description">Display creator/uploader name</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="showCreator" <?= ($site_settings['showCreator'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Show Date</div>
                                        <div class="toggle-description">Display upload/release date</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="showDate" <?= ($site_settings['showDate'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Features
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Enable Bookmarks</div>
                                        <div class="toggle-description">Allow users to save favorite videos</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="enableBookmarks" <?= ($site_settings['enableBookmarks'] ?? false) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Enable Watch History</div>
                                        <div class="toggle-description">Track video progress for resume feature</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="enableWatchHistory" <?= ($site_settings['enableWatchHistory'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div id="displayStatus"></div>
                        </div>
                    </div>
