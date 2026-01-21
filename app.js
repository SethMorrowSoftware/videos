/**
 * ArchiveVideoSearch - Enhanced Version with Modular Architecture
 * Version: 3.0.0
 * Fully modularized with separated services and utilities
 */

// Import configuration
import { CONFIG, COLLECTIONS } from './src/js/config.js';

// Import utilities
import { ICONS } from './src/js/utils/icons.js';
import {
  safeParseJSON,
  escapeHtml,
  sanitizeHtml,
  extractValue,
  formatRuntime,
  formatTime,
  getThumbnailUrl
} from './src/js/utils/helpers.js';
import { UIFeedback } from './src/js/utils/uiFeedback.js';
import { UrlManager } from './src/js/utils/urlManager.js';

// Import services
import { SearchCache } from './src/js/services/SearchCache.js';
import { SearchService } from './src/js/services/SearchService.js';
import { VideoService } from './src/js/services/VideoService.js';
import { PlaylistService } from './src/js/services/PlaylistService.js';
import { VideoProgressTracker } from './src/js/services/VideoProgressTracker.js';
import { BookmarkManager } from './src/js/services/BookmarkManager.js';
import { OfflineHandler } from './src/js/services/OfflineHandler.js';

// Import components
import { SearchSuggestions } from './src/js/components/SearchSuggestions.js';
import { RecommendedManager } from './src/js/components/RecommendedManager.js';
import { FeaturedSectionsManager } from './src/js/components/FeaturedSectionsManager.js';
import { Toast } from './src/js/components/Toast.js';
import { LoadingSkeleton } from './src/js/components/LoadingSkeleton.js';

// Main Application Class
class ArchiveVideoSearch {
  constructor() {
    // Core properties
    this.currentPage = 1;
    this.currentQuery = '';
    this.totalResults = 0;
    this.searchDebounceTimer = null;
    this.keyboardHandler = null;

    // Load site settings from admin panel
    this.siteSettings = this.loadSiteSettings();

    // Feature flags (now driven by admin settings)
    this.useInfiniteScroll = false;
    this.enableTheaterMode = true;
    this.enableBookmarks = this.siteSettings.enableBookmarks ?? false;

    // Initialize services
    this.videoService = new VideoService();
    this.searchService = new SearchService();
    this.playlistService = new PlaylistService(this.videoService);
    this.progressTracker = new VideoProgressTracker();
    this.searchCache = new SearchCache();
    this.bookmarkManager = new BookmarkManager();
    this.offlineHandler = new OfflineHandler();
    this.toast = new Toast();
    this.loadingSkeleton = new LoadingSkeleton();
    this.uiFeedback = new UIFeedback();

    // User preferences
    this.userPreferences = safeParseJSON(localStorage.getItem('userPrefs')) || {};

    // Initialize DOM and event listeners
    this.initializeElements();
    this.setupUIFeedback();
    this.setupEventListeners();
    this.populateCollections();
    this.loadUserPreferences();
    this.setupSearchSuggestions();

    // Initialize recommended section
    this.recommendedManager = new RecommendedManager(this);
    this.recommendedManager.init().then(() => {
      console.log('Recommended section initialized');
    }).catch(err => {
      console.error('Failed to init recommended:', err);
    });

    // Initialize featured sections
    this.featuredSectionsManager = new FeaturedSectionsManager(this);
    this.featuredSectionsManager.init().then(() => {
      console.log('Featured sections initialized');
    }).catch(err => {
      console.error('Failed to init featured sections:', err);
    });

    // Store in window for global access
    window.featuredSectionsManager = this.featuredSectionsManager;

    this.handleUrlParameters();

    // Setup offline handler callbacks
    this.offlineHandler.onStatusChange((isOnline) => {
      if (isOnline && this.currentQuery) {
        this.showMessage('Back online! Refreshing results...', 'success');
        this.performSearch();
      }
    });

    this.updatePageTitle();

    console.log('ArchiveVideoSearch initialized successfully');
  }

  initializeElements() {
    this.searchForm = document.getElementById('searchForm');
    this.searchInput = document.getElementById('searchInput');
    this.searchBtn = document.getElementById('searchBtn');
    this.clearSearchBtn = document.getElementById('clearSearchBtn');
    this.collection = document.getElementById('collection');
    this.sortBy = document.getElementById('sortBy');
    this.clearFilters = document.getElementById('clearFilters');
    this.loading = document.getElementById('loading');
    this.error = document.getElementById('error');
    this.results = document.getElementById('results');
    this.pagination = document.getElementById('pagination');
    this.searchStats = document.getElementById('searchStats');
    this.playerContainer = document.getElementById('playerContainer');
    this.playPauseBtn = document.getElementById('playPauseBtn');
    this.playerTitle = document.getElementById('playerTitle');
    this.playerMeta = document.getElementById('playerMeta');
    this.playerInfo = document.getElementById('playerInfo');
    this.publicDomain = document.getElementById('publicDomain');
    this.collectionsOnly = document.getElementById('collectionsOnly');
    this.sidebar = document.querySelector('.sidebar');
    this.mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    this.mobileOverlay = document.querySelector('.mobile-overlay');
    this.mobileCloseBtn = document.querySelector('.mobile-close-btn');
    this.theaterModeBtn = document.getElementById('theaterModeBtn');
    this.surpriseMeBtn = document.getElementById('surpriseMeBtn');
    this.bookmarksBtn = document.getElementById('bookmarksBtn');

    const criticalElements = [
      'searchForm', 'searchInput', 'collection', 'results', 'playerContainer'
    ];

    for (const elementName of criticalElements) {
      if (!this[elementName]) {
        console.error(`Critical element missing: ${elementName}`);
      }
    }
  }

  setupUIFeedback() {
    this.uiFeedback.setElements({
      loading: this.loading,
      error: this.error,
      results: this.results,
      pagination: this.pagination,
      searchStats: this.searchStats,
      searchBtn: this.searchBtn
    });
  }

  setupEventListeners() {
    // Logo click - go home
    const logoSection = document.querySelector('.logo-section');
    if (logoSection) {
      logoSection.addEventListener('click', (e) => {
        e.preventDefault();
        this.goHome();
      });
    }

    if (this.searchForm) {
      this.searchForm.addEventListener('submit', e => {
        e.preventDefault();
        this.currentPage = 1;
        this.performSearch();
      });
    }

    if (this.searchInput) {
      this.searchInput.addEventListener('input', () => {
        this.debounceSearch(() => {
          const value = this.searchInput.value.trim();
          if (value.length > 2 || value.length === 0) {
            this.currentPage = 1;
            this.performSearch();
          }
        });

        if (this.clearSearchBtn) {
          this.clearSearchBtn.style.display = this.searchInput.value ? 'flex' : 'none';
        }
      });
    }

    if (this.clearSearchBtn) {
      this.clearSearchBtn.addEventListener('click', () => {
        this.searchInput.value = '';
        this.clearSearchBtn.style.display = 'none';
        this.searchInput.focus();
        this.currentPage = 1;
        this.performSearch();
      });
    }

    if (this.collection) {
      this.collection.addEventListener('change', () => {
        this.currentPage = 1;
        this.performSearch();
        this.saveUserPreferences();
      });
    }

    if (this.sortBy) {
      this.sortBy.addEventListener('change', () => {
        if (this.hasActiveSearch()) {
          this.currentPage = 1;
          this.performSearch();
          this.saveUserPreferences();
        }
      });
    }

    [this.publicDomain, this.collectionsOnly].forEach(cb => {
      if (cb) cb.addEventListener('change', () => {
        this.currentPage = 1;
        this.performSearch();
      });
    });

    if (this.clearFilters) {
      this.clearFilters.addEventListener('click', () => this.clearAllFilters());
    }

    if (this.playPauseBtn) {
      this.playPauseBtn.addEventListener('click', () => this.togglePlayPause());
    }

    if (this.theaterModeBtn) {
      this.theaterModeBtn.addEventListener('click', () => this.toggleTheaterMode());
    }

    if (this.surpriseMeBtn) {
      this.surpriseMeBtn.addEventListener('click', () => this.playRandomVideo());
    }

    if (this.bookmarksBtn) {
      this.bookmarksBtn.addEventListener('click', () => this.showBookmarks());
    }

    window.addEventListener('popstate', () => {
      this.handleUrlParameters();
    });

    document.addEventListener('fullscreenchange', () => {
      if (!document.fullscreenElement && this.playerContainer) {
        this.playerContainer.classList.remove('is-fullscreen');
      }
    });

    this.setupMobileMenu();
  }

  setupMobileMenu() {
    if (!this.mobileMenuBtn || !this.sidebar || !this.mobileOverlay || !this.mobileCloseBtn) return;

    const openMenu = () => {
      this.sidebar.classList.add('open');
      this.mobileOverlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    };

    this.mobileMenuBtn.addEventListener('click', openMenu);
    this.mobileOverlay.addEventListener('click', () => this.closeMobileMenu());
    this.mobileCloseBtn.addEventListener('click', () => this.closeMobileMenu());

    [this.collection, this.sortBy, this.publicDomain, this.collectionsOnly].forEach(el => {
      if (el) {
        el.addEventListener('change', () => {
          if (window.innerWidth <= 768 && this.sidebar.classList.contains('open')) {
            setTimeout(() => this.closeMobileMenu(), 200);
          }
        });
      }
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && this.sidebar.classList.contains('open')) {
        this.closeMobileMenu();
      }
    });
  }

  setupSearchSuggestions() {
    if (this.searchInput) {
      this.searchSuggestions = new SearchSuggestions(
        this.searchInput,
        () => {
          this.currentPage = 1;
          this.performSearch();
        }
      );
    }
  }

  debounceSearch(callback, delay = CONFIG.DEBOUNCE_DELAY) {
    clearTimeout(this.searchDebounceTimer);
    this.searchDebounceTimer = setTimeout(callback, delay);
  }

  // ========================================
  // User Preferences
  // ========================================

  saveUserPreferences() {
    const prefs = {
      collection: this.collection?.value,
      sortBy: this.sortBy?.value,
      volume: this.videoService.getVideoControls()?.video?.volume || 1,
      lastSearch: this.searchInput?.value,
      theaterMode: this.playerContainer?.classList.contains('theater-mode'),
      timestamp: Date.now()
    };
    try {
      localStorage.setItem('userPrefs', JSON.stringify(prefs));
    } catch (e) {
      console.warn('Failed to save preferences:', e);
    }
  }

  loadUserPreferences() {
    const collections = this.searchService.getCollections();

    // Use user preferences if available, otherwise fall back to admin settings
    if (this.userPreferences?.collection && collections[this.userPreferences.collection] && this.collection) {
      this.collection.value = this.userPreferences.collection;
    } else if (this.siteSettings.defaultCollection && this.collection) {
      this.collection.value = this.siteSettings.defaultCollection;
    }

    if (this.userPreferences?.sortBy && this.sortBy) {
      this.sortBy.value = this.userPreferences.sortBy;
    } else if (this.siteSettings.defaultSort && this.sortBy) {
      this.sortBy.value = this.siteSettings.defaultSort;
    }
  }

  loadSiteSettings() {
    const configEl = document.getElementById('siteSettingsConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        console.warn('Failed to parse site settings config:', e);
      }
    }
    // Return defaults if no config found
    return {
      siteName: 'Archive Film Club',
      showDownloadCount: true,
      showCreator: true,
      showDate: true,
      enableBookmarks: false,
      enableWatchHistory: true,
      cardStyle: 'modern'
    };
  }

  // ========================================
  // Page & URL Management
  // ========================================

  updatePageTitle(suffix = '') {
    let title = 'Archive Film Club';
    if (suffix) {
      title = `${suffix} - ${title}`;
    } else if (this.totalResults > 0) {
      title = `(${this.totalResults.toLocaleString()}) ${title}`;
    }
    document.title = title;
  }

  closeMobileMenu() {
    if (this.sidebar) this.sidebar.classList.remove('open');
    if (this.mobileOverlay) this.mobileOverlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  hasActiveSearch() {
    return (this.collection?.value !== 'all_videos') ||
           (this.searchInput?.value.trim()) ||
           (this.collectionsOnly?.checked);
  }

  populateCollections() {
    if (!this.collection) return;

    this.collection.innerHTML = '';
    const sortedCollections = this.searchService.getSortedCollections();

    sortedCollections.forEach(([id, label]) => {
      const opt = document.createElement('option');
      opt.value = id;
      opt.textContent = label;
      this.collection.appendChild(opt);
    });
    this.collection.value = 'all_videos';
  }

  handleUrlParameters() {
    const urlState = UrlManager.parseUrlState();

    if (urlState.videoId) {
      this.loadVideoFromUrl(urlState.videoId, urlState.track, urlState.timestamp);
      return;
    }

    if (urlState.search || urlState.collection) {
      if (urlState.search && this.searchInput) this.searchInput.value = urlState.search;
      const collections = this.searchService.getCollections();
      if (urlState.collection && collections[urlState.collection] && this.collection) {
        this.collection.value = urlState.collection;
      }
      if (urlState.page > 0) this.currentPage = urlState.page;
      setTimeout(() => this.performSearch(), 100);
    } else {
      this.loadInitialSearch();
    }
  }

  loadInitialSearch() {
    setTimeout(() => {
      // Use admin settings for defaults, falling back to hardcoded values
      const defaultCollection = this.siteSettings.defaultCollection || 'all_videos';
      const defaultSort = this.siteSettings.defaultSort || 'downloads';

      if (this.collection) this.collection.value = defaultCollection;
      if (this.sortBy) this.sortBy.value = defaultSort;
      this.performSearch();
    }, 500);
  }

  async loadVideoFromUrl(id, track = null, timestamp = null) {
    try {
      this.uiFeedback.showLoading();
      await this.playVideo(id, id, 'Unknown', track);

      if (timestamp && this.videoService.getVideoControls()?.video) {
        setTimeout(() => {
          this.videoService.getVideoControls().video.currentTime = timestamp;
        }, 1000);
      }
    } catch (err) {
      console.error('Deep-link load failed:', err);
      this.uiFeedback.showError('Could not load video from link.');
    } finally {
      this.uiFeedback.hideLoading();
    }
  }

  // ========================================
  // Search & Results
  // ========================================

  async performSearch() {
    if (!this.offlineHandler.isOnline) {
      this.uiFeedback.showError('You are offline. Please check your connection.');
      return;
    }

    const term = this.searchInput?.value.trim() || '';
    this.currentQuery = term || '*';

    if (term && this.searchSuggestions) {
      this.searchSuggestions.addToHistory(term);
    }

    const urlParams = UrlManager.buildSearchUrl({
      search: term || undefined,
      collection: (this.collection?.value !== 'all_videos') ? this.collection.value : undefined,
      page: this.currentPage > 1 ? String(this.currentPage) : undefined
    });
    UrlManager.updateUrl(urlParams, true);

    this.hidePlayer();
    this.uiFeedback.showLoading();
    this.uiFeedback.hideError();

    try {
      const data = await this.searchService.searchArchive({
        query: this.currentQuery,
        page: this.currentPage,
        collection: this.collection?.value,
        sortBy: this.sortBy?.value,
        publicDomain: this.publicDomain?.checked,
        collectionsOnly: this.collectionsOnly?.checked
      });

      if (!data || !data.response) throw new Error('Invalid response from Archive.org');

      const resp = data.response;
      this.totalResults = resp.numFound || 0;

      this.displayResults(resp.docs || []);
      this.updatePagination(resp.numFound || 0);
      this.uiFeedback.updateStats(
        resp.numFound || 0,
        this.currentPage,
        CONFIG.ITEMS_PER_PAGE,
        this.searchService.getCollectionDisplayName(this.collection?.value || 'all_videos')
      );
      this.updatePageTitle();

    } catch (err) {
      console.error('Search error:', err);
      this.uiFeedback.showError(`Search failed: ${err.message}`);
      this.uiFeedback.showFallbackMessage();
    } finally {
      this.uiFeedback.hideLoading();
    }
  }

  displayResults(docs) {
    this.uiFeedback.hideLoading();
    if (!docs || !docs.length) {
      return this.uiFeedback.showNoResults(
        this.currentQuery,
        this.searchService.getCollectionDisplayName(this.collection?.value || 'all_videos')
      );
    }

    if (!this.results) return;

    const resultsHtml = docs.map(d => this.createResultCard(d)).join('');
    this.results.innerHTML = resultsHtml;
    this.attachCardEventListeners();
  }

  attachCardEventListeners() {
    if (!this.results) return;

    this.results.querySelectorAll('.result-card').forEach(card => {
      card.addEventListener('click', async e => {
        if (e.target.closest('button, a')) return;

        const id = card.dataset.identifier;
        const mediatype = card.dataset.mediatype;

        if (mediatype === 'collection') {
          this.openCollection(card, id);
        } else {
          const title = card.querySelector('.result-title').textContent;
          const creatorEl = card.querySelector('.result-creator');
          const creator = creatorEl ? creatorEl.textContent : 'Unknown';
          await this.playVideo(id, title, creator);
        }
      });
    });

    this.results.querySelectorAll('.btn-play, .btn-primary-action').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        const mediatype = card.dataset.mediatype;

        if (mediatype === 'collection') {
          this.openCollection(card, id);
        } else {
          const title = card.querySelector('.result-title').textContent;
          const creatorEl = card.querySelector('.result-creator');
          const creator = creatorEl ? creatorEl.textContent : 'Unknown';
          await this.playVideo(id, title, creator);
        }
      });
    });

    this.results.querySelectorAll('.btn-share').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        const title = card.querySelector('.result-title').textContent;
        this.shareVideo(id, null, title);
      });
    });

    this.results.querySelectorAll('.btn-bookmark').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        const title = card.querySelector('.result-title').textContent;
        const creatorEl = card.querySelector('.result-creator');
        const creator = creatorEl ? creatorEl.textContent : 'Unknown';

        const video = { identifier: id, title, creator };

        if (this.bookmarkManager.isBookmarked(id)) {
          this.bookmarkManager.remove(id);
          btn.classList.remove('bookmarked');
          btn.innerHTML = ICONS.bookmark;
          this.showMessage('Removed from bookmarks', 'info');
        } else {
          this.bookmarkManager.add(video);
          btn.classList.add('bookmarked');
          btn.innerHTML = ICONS.bookmarkFilled;
          this.showMessage('Added to bookmarks!', 'success');
        }
      });
    });
  }

  openCollection(card, id) {
    const collections = this.searchService.getCollections();
    if (!collections[id]) {
      const title = card.querySelector('.result-title').textContent;
      this.searchService.addCollection(id, title);
      this.populateCollections();
    }

    if (this.collection) this.collection.value = id;
    if (this.searchInput) this.searchInput.value = '';
    this.currentPage = 1;

    if (this.collectionsOnly) {
      this.collectionsOnly.checked = false;
    }

    this.performSearch();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  createResultCard(item) {
    const title = extractValue(item.title) || 'Untitled';
    const creator = extractValue(item.creator) || 'Unknown';
    const date = extractValue(item.date) ? new Date(extractValue(item.date)).toLocaleDateString() : '';
    const downloads = Number(item.downloads || 0).toLocaleString();
    const runtime = formatRuntime(item.runtime);
    const href = `https://archive.org/details/${item.identifier}`;
    const thumbUrl = getThumbnailUrl(item.identifier);
    const license = extractValue(item.licenseurl) || '';
    const subject = extractValue(item.subject) || '';
    const isPD = license.includes('publicdomain') || subject.toLowerCase().includes('public domain');
    const mediatype = extractValue(item.mediatype) || 'movies';
    const isBookmarked = this.bookmarkManager.isBookmarked(item.identifier);

    // Get display settings from admin config
    const showCreator = this.siteSettings.showCreator !== false;
    const showDate = this.siteSettings.showDate !== false;
    const showDownloadCount = this.siteSettings.showDownloadCount !== false;

    const progress = this.progressTracker.getProgress(item.identifier);
    const progressBar = progress ? `
      <div class="progress-indicator" style="width: ${progress.percentage}%"></div>
    ` : '';

    let actionButtonHtml;
    if (mediatype === 'collection') {
      actionButtonHtml = `<button class="btn btn-secondary btn-primary-action"><span class="btn-icon">${ICONS.folder}</span> Open Collection</button>`;
    } else {
      actionButtonHtml = `<button class="btn btn-play btn-primary-action"><span class="btn-icon">${ICONS.play}</span> ${progress ? 'Resume' : 'Play'}</button>`;
    }

    const placeholderIcon = mediatype === 'collection' ? '&#128193;' : '&#127916;';

    // Build meta items based on admin settings
    let metaItems = [];
    if (showCreator) {
      metaItems.push(`<span class="result-creator"><span class="meta-icon">${ICONS.user}</span> ${escapeHtml(creator)}</span>`);
    }
    if (showDate && date) {
      metaItems.push(`<span><span class="meta-icon">${ICONS.calendar}</span> ${date}</span>`);
    }
    if (showDownloadCount && downloads) {
      metaItems.push(`<span><span class="meta-icon">${ICONS.download}</span> ${downloads}</span>`);
    }

    return `
      <article class="result-card" data-identifier="${item.identifier}" data-mediatype="${mediatype}">
        <div class="result-thumbnail">
          <img src="${thumbUrl}"
               alt="Thumbnail for ${escapeHtml(title)}"
               class="result-thumb"
               loading="lazy"
               onerror="this.style.display='none'; this.parentNode.innerHTML='<div class=thumb-placeholder>${placeholderIcon}</div>'"/>
          ${runtime && mediatype !== 'collection' ? `<span class="runtime-badge">${runtime}</span>` : ''}
          ${isPD ? `<span class="license-badge">Public Domain</span>` : ''}
          ${progressBar}
        </div>
        <div class="result-content">
          <div class="result-header">
            <h3 class="result-title">${escapeHtml(title)}</h3>
            <div class="result-meta">
              ${metaItems.join('\n              ')}
            </div>
          </div>
          <div class="result-description"></div>
          <div class="result-actions">
            ${actionButtonHtml}
            <a href="${href}" target="_blank" class="btn btn-archive">Archive</a>
            <button class="btn btn-share" title="Share video">${ICONS.link}</button>
            ${this.enableBookmarks && mediatype !== 'collection' ? `
              <button class="btn btn-bookmark ${isBookmarked ? 'bookmarked' : ''}" title="Bookmark">
                ${isBookmarked ? ICONS.bookmarkFilled : ICONS.bookmark}
              </button>
            ` : ''}
          </div>
        </div>
      </article>`;
  }

  // ========================================
  // Video Playback
  // ========================================

  async playVideo(id, title, creator, track = null) {
    this.videoService.setCurrentlyPlaying(id);
    if (this.playerTitle) this.playerTitle.textContent = title;
    if (this.playerMeta) this.playerMeta.textContent = `by ${creator}`;

    UrlManager.updateUrl({ video: id }, true);
    this.updatePageTitle(title);

    if (this.playerContainer) {
      this.playerContainer.classList.add('visible');
      this.playerContainer.removeAttribute('aria-hidden');
    }

    if (this.userPreferences.theaterMode && this.enableTheaterMode && this.playerContainer) {
      this.playerContainer.classList.add('theater-mode');
    }

    this.updatePlayPauseButton(true);

    if (this.playerContainer) {
      this.playerContainer.scrollIntoView({ behavior: 'smooth' });
    }

    const playerLoader = this.playerContainer?.querySelector('.player-loader');
    const videoWrapper = this.playerContainer?.querySelector('.video-wrapper');

    try {
      if (playerLoader) playerLoader.style.display = 'flex';
      const existingVideo = videoWrapper?.querySelector('.video-player');
      if (existingVideo) existingVideo.remove();

      const metadata = await this.videoService.getVideoMetadata(id);

      if (metadata.metadata && metadata.metadata.mediatype === 'collection' && !metadata.files) {
        throw new Error('Collection item has no direct playable media.');
      }

      const videoData = await this.videoService.loadNativeVideo(
        id,
        metadata,
        videoWrapper,
        null,
        this.userPreferences.volume
      );

      // Setup video event listeners
      this.setupVideoEventListeners(videoData.videoElement, id);

      const progress = this.progressTracker.getProgress(id);
      if (progress && videoData.videoElement) {
        setTimeout(() => {
          if (confirm(`Resume from ${formatTime(progress.currentTime)}?`)) {
            videoData.videoElement.currentTime = progress.currentTime;
          }
        }, 1000);
      }

      // Deduplicate video files to avoid showing both .ia and .mp4 for the same episode
      const deduplicatedFiles = this.videoService.deduplicateVideoFiles(videoData.videoFiles);

      if (deduplicatedFiles.length > 1 && this.videoService.hasMultipleUniqueVideos(deduplicatedFiles)) {
        const startIndex = track !== null && track >= 0 && track < deduplicatedFiles.length ? track : 0;
        this.setupPlaylist(id, title, creator, videoData.metadata, deduplicatedFiles, startIndex);
      } else {
        this.displayVideoMetadata(title, creator, videoData.metadata, deduplicatedFiles);
      }

    } catch (err) {
      console.log('Native load failed, falling back to iframe:', err.message);
      this.videoService.loadIframeVideo(id, videoWrapper);
      this.displaySimpleMetadata(title, creator);
    } finally {
      if (playerLoader) playerLoader.style.display = 'none';
    }
  }

  setupVideoEventListeners(videoEl, id) {
    if (!videoEl) return;

    videoEl.addEventListener('play', () => {
      this.updatePlayPauseButton(true);
    });

    videoEl.addEventListener('pause', () => {
      this.updatePlayPauseButton(false);
      if (id && videoEl.currentTime && videoEl.duration) {
        this.progressTracker.saveProgress(id, videoEl.currentTime, videoEl.duration);
      }
    });
  }

  toggleTheaterMode() {
    if (!this.playerContainer) return;

    this.playerContainer.classList.toggle('theater-mode');
    const isTheater = this.playerContainer.classList.contains('theater-mode');

    if (this.theaterModeBtn) {
      this.theaterModeBtn.textContent = isTheater ? 'Exit Theater' : 'Theater Mode';
    }

    this.saveUserPreferences();
    this.showMessage(isTheater ? 'Theater mode enabled' : 'Theater mode disabled', 'info');
  }

  async playRandomVideo() {
    this.uiFeedback.showLoading();

    try {
      const randomVideo = await this.searchService.getRandomVideo();
      const title = extractValue(randomVideo.title);
      const creator = extractValue(randomVideo.creator);

      await this.playVideo(randomVideo.identifier, title, creator);
      this.showMessage('Random video selected!', 'success');
    } catch (err) {
      console.error('Random video failed:', err);
      this.uiFeedback.showError('Could not find a random video. Try again!');
    } finally {
      this.uiFeedback.hideLoading();
    }
  }

  showBookmarks() {
    this.showMessage('Bookmarks feature coming soon!', 'info');
  }

  updatePlayPauseButton(isPlaying) {
    if (!this.playPauseBtn) return;

    const playIcon = this.playPauseBtn.querySelector('.play-icon');
    const pauseIcon = this.playPauseBtn.querySelector('.pause-icon');
    if (isPlaying) {
      if (playIcon) playIcon.style.display = 'none';
      if (pauseIcon) pauseIcon.style.display = 'block';
    } else {
      if (playIcon) playIcon.style.display = 'block';
      if (pauseIcon) pauseIcon.style.display = 'none';
    }
  }

  togglePlayPause() {
    this.videoService.togglePlayPause(this.playerContainer);
  }

  // ========================================
  // Playlist Management
  // ========================================

  setupPlaylist(id, title, creator, metadata, videoFiles, startIndex = 0) {
    this.playlistService.initPlaylist(id, title, creator, metadata, videoFiles, startIndex);
    this.displayPlaylistInterface(startIndex);

    if (startIndex >= 0 && startIndex < videoFiles.length) {
      this.playPlaylistItem(startIndex);
    }
  }

  displayPlaylistInterface(currentIndex = 0) {
    if (!this.playerInfo) return;

    this.playerInfo.innerHTML = this.playlistService.generatePlaylistHTML(currentIndex);
    this.setupPlaylistControls(currentIndex);
  }

  async playPlaylistItem(index) {
    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;

    const file = playlist.videoFiles[index];
    if (!file) return;

    this.playlistService.setCurrentIndex(index);
    UrlManager.updateUrl({ video: playlist.id, track: String(index + 1) }, true);

    this.displayPlaylistInterface(index);

    if (this.playerContainer) {
      this.playerContainer.scrollIntoView({ behavior: 'smooth' });
    }

    const videoWrapper = this.playerContainer?.querySelector('.video-wrapper');

    try {
      const videoData = await this.videoService.loadNativeVideo(
        playlist.id,
        { metadata: playlist.metadata, files: playlist.videoFiles },
        videoWrapper,
        file.name,
        this.userPreferences.volume
      );
      this.setupVideoEventListeners(videoData.videoElement, playlist.id);
    } catch (err) {
      console.error('Error loading playlist item:', err);
      this.markEpisodeAsUnplayable(index);

      const nextPlayableIndex = this.playlistService.findNextPlayable(new Set([index]));
      if (nextPlayableIndex !== -1) {
        setTimeout(() => {
          this.playPlaylistItem(nextPlayableIndex);
        }, 1000);
      } else {
        this.uiFeedback.showError(`Failed to load episode ${index + 1}: ${err.message}`);
      }
    }
  }

  markEpisodeAsUnplayable(index) {
    const episodeItem = this.playerInfo?.querySelector(`[data-index="${index}"]`);
    if (episodeItem) {
      episodeItem.classList.add('unplayable');
      episodeItem.title = 'This video cannot be played directly';
    }
  }

  setupPlaylistControls(currentIndex) {
    if (!this.playerInfo) return;

    const prevBtn = this.playerInfo.querySelector('.prev-btn');
    const nextBtn = this.playerInfo.querySelector('.next-btn');
    const playlist = this.playlistService.getPlaylist();

    this.playerInfo.querySelectorAll('.episode-item').forEach(item => {
      item.addEventListener('click', () => {
        const idx = parseInt(item.dataset.index, 10);
        if (idx !== currentIndex && !item.classList.contains('unplayable')) {
          this.playPlaylistItem(idx);
        }
      });
    });

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        if (this.playlistService.hasPrevious()) {
          this.playPlaylistItem(this.playlistService.previous());
        }
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        if (this.playlistService.hasNext()) {
          this.playPlaylistItem(this.playlistService.next());
        }
      });
    }
  }

  // ========================================
  // Video Metadata Display
  // ========================================

  displaySimpleMetadata(title, creator) {
    if (!this.playerInfo) return;

    this.playerInfo.innerHTML = `
      <div class="video-metadata">
        <h3 class="video-metadata__title">${escapeHtml(title)}</h3>
        <p class="video-metadata__creator"><strong>By:</strong> ${escapeHtml(creator)}</p>
      </div>`;
  }

  displayVideoMetadata(title, creator, metadata, videoFiles) {
    if (!this.playerInfo) return;

    const metadataElement = document.createElement('div');
    metadataElement.className = 'video-metadata';

    let html = `
      <h3 class="video-metadata__title">${escapeHtml(title)}</h3>
      <p class="video-metadata__creator"><strong>By:</strong> ${escapeHtml(creator)}</p>`;

    if (metadata?.description) {
      html += `<div class="video-metadata__description"></div>`;
    }

    if (metadata?.date) html += `<p><strong>Date:</strong> ${escapeHtml(extractValue(metadata.date))}</p>`;
    if (videoFiles.length > 1) html += `<p><strong>Available qualities:</strong> ${videoFiles.length} versions</p>`;

    metadataElement.innerHTML = html;

    if (metadata?.description) {
      const descElement = metadataElement.querySelector('.video-metadata__description');
      if (descElement) {
        descElement.innerHTML = sanitizeHtml(extractValue(metadata.description));
      }
    }

    this.playerInfo.innerHTML = metadataElement.outerHTML;
  }

  // ========================================
  // Sharing
  // ========================================

  shareVideo(id, track = null, title = '') {
    const link = UrlManager.buildVideoShareUrl(id, track);

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(link)
        .then(() => this.showMessage('Link copied to clipboard!', 'success'))
        .catch(() => this.showShareModal(link));
    } else {
      this.showShareModal(link);
    }
  }

  showShareModal(url) {
    const modal = document.createElement('div');
    modal.style.cssText = `position: fixed; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.8); display:flex; align-items:center; justify-content:center; z-index:1000;`;
    modal.innerHTML = `
      <div style="background:#222;color:#fff;padding:2rem;border-radius:1rem;max-width:90vw;text-align:center;">
        <h3>Share this video</h3>
        <input value="${url}" readonly style="width:100%;padding:0.5rem;margin:1rem 0;border:none;border-radius:0.5rem;" />
        <button onclick="this.closest('div').parentNode.remove()" style="padding:0.5rem 1rem;border:none;border-radius:0.5rem;cursor:pointer;background:var(--color-accent);color:#fff;">Close</button>
      </div>`;
    document.body.appendChild(modal);
    modal.querySelector('input').select();
    modal.onclick = e => { if (e.target === modal) modal.remove(); };
  }

  // ========================================
  // Messages & Notifications
  // ========================================

  showMessage(msg, type = 'info', duration = 3000) {
    this.toast.show(msg, type, duration);
  }

  // ========================================
  // Player Management
  // ========================================

  hidePlayer() {
    if (!this.playerContainer) return;

    this.playerContainer.classList.remove('visible');
    this.playerContainer.setAttribute('aria-hidden', 'true');
    this.videoService.setCurrentlyPlaying(null);
    this.playlistService.clearPlaylist();

    if (this.keyboardHandler) {
      document.removeEventListener('keydown', this.keyboardHandler);
      this.keyboardHandler = null;
    }

    const videoWrapper = this.playerContainer.querySelector('.video-wrapper');
    if (videoWrapper) {
      videoWrapper.innerHTML = `<div class="player-loader" style="display: none;"><div class="loading-spinner"><div class="spinner-ring"></div></div></div>`;
    }

    if (this.playerInfo) this.playerInfo.innerHTML = '';
    this.updatePlayPauseButton(false);
  }

  // ========================================
  // Pagination
  // ========================================

  updatePagination(numFound) {
    if (!this.pagination) return;

    const paginationInfo = this.searchService.getPaginationInfo(numFound, this.currentPage);
    if (paginationInfo.totalPages <= 1) {
      this.pagination.innerHTML = '';
      return;
    }

    let html = '';
    if (paginationInfo.hasPrevious) {
      html += `<button data-page="${this.currentPage - 1}">&larr; Previous</button>`;
    }

    if (this.currentPage > 3) {
      html += `<button data-page="1">1</button>`;
      if (this.currentPage > 4) html += `<span>...</span>`;
    }

    const start = Math.max(1, this.currentPage - 2);
    const end = Math.min(paginationInfo.totalPages, this.currentPage + 2);
    for (let i = start; i <= end; i++) {
      html += `<button class="${i === this.currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
    }

    if (this.currentPage < paginationInfo.totalPages - 2) {
      if (this.currentPage < paginationInfo.totalPages - 3) html += `<span>...</span>`;
      html += `<button data-page="${paginationInfo.totalPages}">${paginationInfo.totalPages}</button>`;
    }

    if (paginationInfo.hasNext) {
      html += `<button data-page="${this.currentPage + 1}">Next &rarr;</button>`;
    }

    this.pagination.innerHTML = html;
    this.pagination.querySelectorAll('button[data-page]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.currentPage = parseInt(btn.dataset.page, 10);
        this.performSearch();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  }

  // ========================================
  // Filter & Navigation
  // ========================================

  clearAllFilters() {
    if (this.collection) this.collection.value = 'all_videos';
    if (this.sortBy) this.sortBy.value = 'downloads';
    if (this.searchInput) this.searchInput.value = '';
    if (this.publicDomain) this.publicDomain.checked = false;
    if (this.collectionsOnly) this.collectionsOnly.checked = false;
    this.currentPage = 1;
    this.uiFeedback.clearResults();
    this.uiFeedback.clearPagination();
    this.uiFeedback.clearStats();
    UrlManager.clearUrl();
  }

  goHome() {
    if (this.collection) this.collection.value = 'all_videos';
    if (this.sortBy) this.sortBy.value = 'downloads';
    if (this.searchInput) {
      this.searchInput.value = '';
      if (this.clearSearchBtn) this.clearSearchBtn.style.display = 'none';
    }
    if (this.publicDomain) this.publicDomain.checked = false;
    if (this.collectionsOnly) this.collectionsOnly.checked = false;

    this.currentPage = 1;
    this.currentQuery = '';

    this.hidePlayer();

    UrlManager.clearUrl();

    this.updatePageTitle();

    if (this.recommendedManager && !this.recommendedManager.isHidden) {
      this.recommendedManager.show();
    }

    this.performSearch();

    window.scrollTo({ top: 0, behavior: 'smooth' });

    this.closeMobileMenu();
  }
}

// Initialize the application when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  try {
    window.archiveSearch = new ArchiveVideoSearch();
    console.log('Application loaded successfully');
  } catch (error) {
    console.error('Failed to initialize application:', error);

    const errorMessage = document.createElement('div');
    errorMessage.style.cssText = `
      position: fixed; top: 20px; left: 20px; right: 20px;
      background: #ff4444; color: white; padding: 1rem;
      border-radius: 8px; z-index: 10000;
    `;
    errorMessage.innerHTML = `
      <strong>Application Error:</strong> Failed to load. Please refresh the page.
      <button onclick="location.reload()" style="margin-left: 1rem; padding: 0.5rem; background: white; color: #ff4444; border: none; border-radius: 4px; cursor: pointer;">Refresh</button>
    `;
    document.body.appendChild(errorMessage);
  }
});

export default ArchiveVideoSearch;
