/**
 * ArchiveVideoSearch - Enhanced Version with Modular Architecture
 * Version: 4.0.0
 * Search/browse page - video playback moved to dedicated player.php
 */

// Import configuration
import { CONFIG, COLLECTIONS } from './src/js/config.js';

// Import utilities
import { ICONS } from './src/js/utils/icons.js';
import {
  safeParseJSON,
  escapeHtml,
  extractValue,
  formatRuntime,
  getThumbnailUrl
} from './src/js/utils/helpers.js';
import { UIFeedback } from './src/js/utils/uiFeedback.js';
import { UrlManager } from './src/js/utils/urlManager.js';

// Import services
import { SearchCache } from './src/js/services/SearchCache.js';
import { SearchService } from './src/js/services/SearchService.js';
import { VideoProgressTracker } from './src/js/services/VideoProgressTracker.js';
import { BookmarkManager } from './src/js/services/BookmarkManager.js';
import { OfflineHandler } from './src/js/services/OfflineHandler.js';
import { BackgroundCacheService } from './src/js/services/BackgroundCacheService.js';

// Import components
import { SearchSuggestions } from './src/js/components/SearchSuggestions.js';
import { RecommendedManager } from './src/js/components/RecommendedManager.js';
import { FeaturedSectionsManager } from './src/js/components/FeaturedSectionsManager.js';
import { Toast } from './src/js/components/Toast.js';
import { LoadingSkeleton } from './src/js/components/LoadingSkeleton.js';
import { AuthNav } from './src/js/components/AuthNav.js';

// Mount auth nav as early as possible so the header doesn't flash empty
AuthNav.mount();

// Main Application Class
class ArchiveVideoSearch {
  constructor() {
    // Core properties
    this.currentPage = 1;
    this.currentQuery = '';
    this.totalResults = 0;
    this.searchDebounceTimer = null;

    // Load site settings from admin panel
    this.siteSettings = this.loadSiteSettings();

    // Feature flags (now driven by admin settings)
    this.enableBookmarks = this.siteSettings.enableBookmarks ?? false;

    // Initialize services
    this.searchService = new SearchService();
    this.progressTracker = new VideoProgressTracker();
    this.searchCache = new SearchCache();
    this.bookmarkManager = new BookmarkManager();
    this.offlineHandler = new OfflineHandler();
    this.backgroundCacheService = new BackgroundCacheService();
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
    this.publicDomain = document.getElementById('publicDomain');
    this.collectionsOnly = document.getElementById('collectionsOnly');
    this.sidebar = document.querySelector('.sidebar');
    this.mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    this.mobileOverlay = document.querySelector('.mobile-overlay');
    this.mobileCloseBtn = document.querySelector('.mobile-close-btn');

    const criticalElements = [
      'searchForm', 'searchInput', 'collection', 'results'
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

    window.addEventListener('popstate', () => {
      this.handleUrlParameters();
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
      lastSearch: this.searchInput?.value,
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

    // Redirect video URLs to dedicated player page
    if (urlState.videoId) {
      let playerUrl = `player.php?video=${encodeURIComponent(urlState.videoId)}`;
      if (urlState.track !== null) playerUrl += `&track=${urlState.track + 1}`;
      if (urlState.timestamp) playerUrl += `&t=${urlState.timestamp}`;
      window.location.replace(playerUrl);
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
      const defaultCollection = this.siteSettings.defaultCollection || 'all_videos';
      const defaultSort = this.siteSettings.defaultSort || 'downloads';

      if (this.collection) this.collection.value = defaultCollection;
      if (this.sortBy) this.sortBy.value = defaultSort;
      this.performSearch();
    }, 500);
  }

  /**
   * Navigate to the dedicated player page
   */
  navigateToPlayer(id, track = null) {
    let url = `player.php?video=${encodeURIComponent(id)}`;
    if (track !== null && track !== undefined) {
      url += `&track=${track + 1}`;
    }
    window.location.href = url;
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

    // Queue displayed items for background caching (thumbnails and metadata)
    // This helps build up local cache as users browse
    if (this.backgroundCacheService) {
      this.backgroundCacheService.queueSearchResults(docs);
    }
  }

  attachCardEventListeners() {
    if (!this.results) return;

    this.results.querySelectorAll('.result-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('button, a')) return;

        const id = card.dataset.identifier;
        const mediatype = card.dataset.mediatype;

        if (mediatype === 'collection') {
          this.openCollection(card, id);
        } else {
          this.navigateToPlayer(id);
        }
      });
    });

    this.results.querySelectorAll('.btn-play, .btn-primary-action').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        const mediatype = card.dataset.mediatype;

        if (mediatype === 'collection') {
          this.openCollection(card, id);
        } else {
          this.navigateToPlayer(id);
        }
      });
    });

    this.results.querySelectorAll('.btn-share').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        this.shareVideo(id);
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
          ${mediatype !== 'collection' ? `<div class="thumb-play-overlay"><span class="play-circle">${ICONS.play}</span></div>` : ''}
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
  // Video Navigation (opens player page)
  // ========================================

  // ========================================
  // Sharing
  // ========================================

  shareVideo(id) {
    const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
    const link = `${window.location.origin}${basePath}player.php?video=${encodeURIComponent(id)}`;

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(link)
        .then(() => this.showMessage('Link copied to clipboard!', 'success'))
        .catch(() => this.showMessage('Could not copy link', 'error'));
    } else {
      prompt('Copy this link:', link);
    }
  }

  // ========================================
  // Messages & Notifications
  // ========================================

  showMessage(msg, type = 'info', duration = 3000) {
    this.toast.show(msg, type, duration);
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

// Service worker registration.
// Using a relative URL ('sw.js') means a /films/ subdirectory install registers
// /films/sw.js with scope /films/ automatically. Do not change to a leading-slash
// path -- that would break subdirectory deployments.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch((err) => {
      console.warn('[SW] Registration failed:', err);
    });
  });
}
