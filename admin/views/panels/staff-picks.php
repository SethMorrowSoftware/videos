                    <div class="search-layout">
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title">Search Archive.org</h2>
                                    <p class="card-subtitle">Find videos to feature on your site</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="search-box">
                                    <div class="search-input-wrapper">
                                        <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="11" cy="11" r="8"/>
                                            <path d="M21 21l-4.35-4.35"/>
                                        </svg>
                                        <input type="text" class="search-input" id="searchInput" placeholder="Search movies, shows, documentaries...">
                                    </div>
                                    <button class="btn btn-primary" onclick="searchVideos()">
                                        Search
                                    </button>
                                </div>

                                <div id="searchResults">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🔍</div>
                                        <div class="empty-state-title">Search for videos</div>
                                        <p class="empty-state-text">Click on videos to add them to your Staff Picks</p>
                                    </div>
                                </div>

                                <div id="pagination" class="pagination" style="display: none;"></div>
                            </div>
                        </div>

                        <div class="selected-panel">
                            <div class="card">
                                <div class="card-header">
                                    <div>
                                        <h2 class="card-title">Selected Videos</h2>
                                        <p class="card-subtitle">Drag to reorder</p>
                                    </div>
                                    <span class="selected-count" id="selectedCount"><?= count($current_recommendations) ?></span>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label class="form-label">Section Title</label>
                                        <input type="text" class="form-input" id="sectionTitle" value="<?= htmlspecialchars($recommendations_data['title'] ?? 'Staff Picks') ?>" placeholder="Staff Picks">
                                    </div>

                                    <div class="toggle-wrapper">
                                        <div>
                                            <div class="toggle-label">Show on homepage</div>
                                            <div class="toggle-description">Display this section to visitors</div>
                                        </div>
                                        <label class="toggle">
                                            <input type="checkbox" id="enabledToggle" <?= ($recommendations_data['enabled'] ?? true) ? 'checked' : '' ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>

                                    <div class="divider"></div>

                                    <div id="selectedList" class="selected-list">
                                        <!-- Selected videos will appear here -->
                                    </div>

                                    <div id="staffPicksStatus"></div>
                                </div>
                            </div>
                        </div>
                    </div>
