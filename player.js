/**
 * Archive Film Club - Dedicated Player Page
 * Handles video playback, playlist management, and metadata display
 */

import { VideoService } from './src/js/services/VideoService.js';
import { PlaylistService } from './src/js/services/PlaylistService.js';
import { VideoProgressTracker } from './src/js/services/VideoProgressTracker.js';
import { BookmarkManager } from './src/js/services/BookmarkManager.js';
import { Toast } from './src/js/components/Toast.js';
import {
  safeParseJSON,
  escapeHtml,
  sanitizeHtml,
  extractValue,
  formatRuntime,
  formatTime,
  formatFileSize
} from './src/js/utils/helpers.js';
import { ICONS } from './src/js/utils/icons.js';

class VideoPlayer {
  constructor() {
    this.videoService = new VideoService();
    this.playlistService = new PlaylistService(this.videoService);
    this.progressTracker = new VideoProgressTracker();
    this.bookmarkManager = new BookmarkManager();
    this.toast = new Toast();

    this.siteSettings = this.loadSiteSettings();
    this.videoId = null;
    this.trackIndex = null;
    this.metadata = null;
    this.videoFiles = [];
    this.descriptionExpanded = false;

    this.initElements();
    this.setupEventListeners();
    this.parseUrlAndLoad();
  }

  loadSiteSettings() {
    const configEl = document.getElementById('siteSettingsConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        return {};
      }
    }
    return {};
  }

  initElements() {
    this.videoWrapper = document.getElementById('videoWrapper');
    this.playerLoader = document.getElementById('playerLoader');
    this.videoTitle = document.getElementById('videoTitle');
    this.videoCreator = document.getElementById('videoCreator');
    this.videoDate = document.getElementById('videoDate');
    this.archiveLink = document.getElementById('archiveLink');
    this.descriptionSection = document.getElementById('descriptionSection');
    this.descriptionToggle = document.getElementById('descriptionToggle');
    this.descriptionContent = document.getElementById('descriptionContent');
    this.downloadsPanel = document.getElementById('downloadsPanel');
    this.downloadLinks = document.getElementById('downloadLinks');
    this.playlistSidebar = document.getElementById('playlistSidebar');
    this.playlistTitle = document.getElementById('playlistTitle');
    this.playlistCount = document.getElementById('playlistCount');
    this.playlistItems = document.getElementById('playlistItems');
    this.shareBtn = document.getElementById('shareBtn');
    this.shareBtn2 = document.getElementById('shareBtn2');
    this.bookmarkBtn = document.getElementById('bookmarkBtn');
    this.downloadBtn = document.getElementById('downloadBtn');
    this.closeDownloads = document.getElementById('closeDownloads');
  }

  setupEventListeners() {
    // Share buttons
    [this.shareBtn, this.shareBtn2].forEach(btn => {
      if (btn) btn.addEventListener('click', () => this.shareVideo());
    });

    // Bookmark
    if (this.bookmarkBtn) {
      this.bookmarkBtn.addEventListener('click', () => this.toggleBookmark());
    }

    // Download panel
    if (this.downloadBtn) {
      this.downloadBtn.addEventListener('click', () => this.toggleDownloads());
    }
    if (this.closeDownloads) {
      this.closeDownloads.addEventListener('click', () => this.hideDownloads());
    }

    // Description toggle
    if (this.descriptionToggle) {
      this.descriptionToggle.addEventListener('click', () => this.toggleDescription());
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => this.handleKeyboard(e));

    // Browser back/forward
    window.addEventListener('popstate', () => this.parseUrlAndLoad());
  }

  handleKeyboard(e) {
    // Don't handle if typing in an input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    const video = this.videoWrapper?.querySelector('video');
    if (!video) return;

    switch (e.key) {
      case ' ':
      case 'k':
        e.preventDefault();
        video.paused ? video.play() : video.pause();
        break;
      case 'f':
        e.preventDefault();
        if (document.fullscreenElement) {
          document.exitFullscreen();
        } else {
          video.requestFullscreen();
        }
        break;
      case 'm':
        e.preventDefault();
        video.muted = !video.muted;
        break;
      case 'ArrowLeft':
        e.preventDefault();
        video.currentTime = Math.max(0, video.currentTime - 10);
        break;
      case 'ArrowRight':
        e.preventDefault();
        video.currentTime = Math.min(video.duration, video.currentTime + 10);
        break;
      case 'ArrowUp':
        e.preventDefault();
        video.volume = Math.min(1, video.volume + 0.1);
        break;
      case 'ArrowDown':
        e.preventDefault();
        video.volume = Math.max(0, video.volume - 0.1);
        break;
      case 'j':
        e.preventDefault();
        video.currentTime = Math.max(0, video.currentTime - 10);
        break;
      case 'l':
        e.preventDefault();
        video.currentTime = Math.min(video.duration, video.currentTime + 10);
        break;
    }
  }

  parseUrlAndLoad() {
    const params = new URLSearchParams(window.location.search);
    const videoId = params.get('video');
    const track = params.get('track') ? parseInt(params.get('track'), 10) - 1 : null;
    const timestamp = params.get('t') ? parseInt(params.get('t'), 10) : null;

    if (!videoId) {
      this.showError('No video specified. <a href="index.php">Browse videos</a>');
      return;
    }

    this.videoId = videoId;
    this.trackIndex = track;
    this.requestedTimestamp = timestamp;
    this.loadVideo();
  }

  async loadVideo() {
    this.showLoader();

    try {
      const metadata = await this.videoService.getVideoMetadata(this.videoId);
      this.metadata = metadata;

      const meta = metadata.metadata || metadata;
      this.updateVideoInfo(meta);

      // Load and play video
      const videoData = await this.videoService.loadNativeVideo(
        this.videoId,
        metadata,
        this.videoWrapper,
        null,
        this.getSavedVolume()
      );

      this.hideLoader();
      this.setupVideoListeners(videoData.videoElement);

      // Handle resume
      const progress = this.progressTracker.getProgress(this.videoId);
      if (progress && videoData.videoElement && !this.requestedTimestamp) {
        setTimeout(() => {
          if (confirm(`Resume from ${formatTime(progress.currentTime)}?`)) {
            videoData.videoElement.currentTime = progress.currentTime;
          }
        }, 500);
      }

      // Apply requested timestamp
      if (this.requestedTimestamp && videoData.videoElement) {
        setTimeout(() => {
          videoData.videoElement.currentTime = this.requestedTimestamp;
        }, 500);
      }

      // Deduplicate and check for playlist
      const deduplicatedFiles = this.videoService.deduplicateVideoFiles(videoData.videoFiles);
      this.videoFiles = deduplicatedFiles;

      if (deduplicatedFiles.length > 1 && this.videoService.hasMultipleUniqueVideos(deduplicatedFiles)) {
        const startIndex = this.trackIndex !== null && this.trackIndex >= 0 && this.trackIndex < deduplicatedFiles.length
          ? this.trackIndex : 0;
        this.setupPlaylist(meta, deduplicatedFiles, startIndex);
      }

      // Setup downloads
      this.buildDownloadLinks(deduplicatedFiles);

      // Update bookmark state
      this.updateBookmarkState();

    } catch (err) {
      console.warn('Native load failed, trying iframe:', err.message);
      try {
        this.videoService.loadIframeVideo(this.videoId, this.videoWrapper);
        this.hideLoader();

        // Still try to show metadata
        if (this.metadata) {
          const meta = this.metadata.metadata || this.metadata;
          this.updateVideoInfo(meta);
        }
      } catch (iframeErr) {
        this.showError(`Failed to load video: ${err.message}`);
      }
    }
  }

  updateVideoInfo(meta) {
    const title = extractValue(meta.title) || this.videoId;
    const creator = extractValue(meta.creator) || '';
    const date = extractValue(meta.date) || '';
    const description = extractValue(meta.description) || '';

    document.title = `${title} - ${this.siteSettings.siteName || 'Archive Film Club'}`;

    if (this.videoTitle) this.videoTitle.textContent = title;

    if (this.videoCreator) {
      if (creator) {
        this.videoCreator.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 20C4 16.6863 7.58172 14 12 14C16.4183 14 20 16.6863 20 20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ${escapeHtml(creator)}`;
      }
    }

    if (this.videoDate && date) {
      const formattedDate = new Date(date).toLocaleDateString();
      this.videoDate.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ${formattedDate}`;
    }

    if (this.archiveLink) {
      this.archiveLink.href = `https://archive.org/details/${this.videoId}`;
    }

    if (description && description.length > 10) {
      if (this.descriptionSection) this.descriptionSection.style.display = 'block';
      if (this.descriptionContent) this.descriptionContent.innerHTML = sanitizeHtml(description);
    }
  }

  setupVideoListeners(videoEl) {
    if (!videoEl) return;

    videoEl.addEventListener('pause', () => {
      if (this.videoId && videoEl.currentTime && videoEl.duration) {
        this.progressTracker.saveProgress(this.videoId, videoEl.currentTime, videoEl.duration);
      }
    });

    videoEl.addEventListener('ended', () => {
      if (this.videoId && videoEl.duration) {
        this.progressTracker.saveProgress(this.videoId, videoEl.duration, videoEl.duration);
      }
      // Auto-play next in playlist
      if (this.playlistService.hasNext()) {
        const nextIdx = this.playlistService.next();
        this.playPlaylistItem(nextIdx);
      }
    });

    videoEl.addEventListener('volumechange', () => {
      try {
        localStorage.setItem('playerVolume', videoEl.volume);
      } catch (e) {}
    });
  }

  getSavedVolume() {
    try {
      const vol = parseFloat(localStorage.getItem('playerVolume'));
      return isNaN(vol) ? 1 : vol;
    } catch (e) {
      return 1;
    }
  }

  // ========================================
  // Playlist
  // ========================================

  setupPlaylist(meta, videoFiles, startIndex) {
    const title = extractValue(meta.title) || this.videoId;
    const creator = extractValue(meta.creator) || 'Unknown';

    this.playlistService.initPlaylist(this.videoId, title, creator, meta, videoFiles, startIndex);

    if (this.playlistSidebar) this.playlistSidebar.style.display = 'flex';
    if (this.playlistTitle) this.playlistTitle.textContent = 'Episodes';
    if (this.playlistCount) this.playlistCount.textContent = `${videoFiles.length} videos`;

    this.renderPlaylist(startIndex);

    // Play the correct track if specified
    if (startIndex > 0) {
      this.playPlaylistItem(startIndex);
    }
  }

  renderPlaylist(activeIndex = 0) {
    if (!this.playlistItems) return;

    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;

    const { videoFiles, metadata } = playlist;
    const itemTitle = extractValue(metadata?.title) || '';

    this.playlistItems.innerHTML = videoFiles.map((file, i) => {
      const epTitle = this.videoService.getCleanTitle(file.name, itemTitle);
      const duration = formatRuntime(file.length) || '';
      const isActive = i === activeIndex;

      return `
        <div class="playlist-item ${isActive ? 'active' : ''}" data-index="${i}">
          <div class="playlist-item-number">${isActive ?
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3L19 12L5 21V3Z"/></svg>' :
            (i + 1)}</div>
          <div class="playlist-item-info">
            <span class="playlist-item-title">${escapeHtml(epTitle)}</span>
            ${duration ? `<span class="playlist-item-duration">${duration}</span>` : ''}
          </div>
        </div>
      `;
    }).join('');

    // Attach click listeners
    this.playlistItems.querySelectorAll('.playlist-item').forEach(item => {
      item.addEventListener('click', () => {
        const idx = parseInt(item.dataset.index, 10);
        if (idx !== activeIndex) {
          this.playPlaylistItem(idx);
        }
      });
    });

    // Scroll active item into view
    const activeItem = this.playlistItems.querySelector('.playlist-item.active');
    if (activeItem) {
      activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  async playPlaylistItem(index) {
    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;

    const file = playlist.videoFiles[index];
    if (!file) return;

    this.playlistService.setCurrentIndex(index);

    // Update URL without full reload
    const url = new URL(window.location);
    url.searchParams.set('track', String(index + 1));
    window.history.replaceState({}, '', url);

    this.renderPlaylist(index);
    this.showLoader();

    try {
      const videoData = await this.videoService.loadNativeVideo(
        playlist.id,
        { metadata: playlist.metadata, files: playlist.videoFiles },
        this.videoWrapper,
        file.name,
        this.getSavedVolume()
      );
      this.hideLoader();
      this.setupVideoListeners(videoData.videoElement);

      // Update title to episode name
      const epTitle = this.videoService.getCleanTitle(file.name, extractValue(playlist.metadata?.title));
      if (this.videoTitle) {
        this.videoTitle.textContent = epTitle;
      }
    } catch (err) {
      console.error('Error loading playlist item:', err);
      this.hideLoader();
      this.toast.show(`Failed to load episode ${index + 1}`, 'error');

      // Try next playable
      const nextIdx = this.playlistService.findNextPlayable(new Set([index]));
      if (nextIdx !== -1) {
        setTimeout(() => this.playPlaylistItem(nextIdx), 1000);
      }
    }
  }

  // ========================================
  // Downloads
  // ========================================

  buildDownloadLinks(videoFiles) {
    if (!this.downloadLinks || !videoFiles.length) return;

    const mp4Files = videoFiles.filter(f =>
      (f.name || '').toLowerCase().endsWith('.mp4')
    );
    const otherFiles = videoFiles.filter(f =>
      !(f.name || '').toLowerCase().endsWith('.mp4')
    );

    let html = '';

    const createLink = (file) => {
      const url = `https://archive.org/download/${this.videoId}/${encodeURIComponent(file.name)}`;
      const size = formatFileSize(file.size);
      const quality = this.videoService.getQualityLabel(file.name);
      return `
        <a href="${url}" target="_blank" download="${escapeHtml(file.name)}" class="download-item">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L7 10M12 15L17 10M3 17V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div class="download-item-info">
            <span class="download-item-quality">${quality}</span>
            ${size ? `<span class="download-item-size">${size}</span>` : ''}
          </div>
        </a>
      `;
    };

    if (mp4Files.length > 0) {
      html += '<div class="download-group-label">MP4 (Recommended)</div>';
      html += mp4Files.map(createLink).join('');
    }

    if (otherFiles.length > 0) {
      html += '<div class="download-group-label">Other Formats</div>';
      html += otherFiles.map(createLink).join('');
    }

    html += `
      <a href="https://archive.org/download/${this.videoId}" target="_blank" class="download-item download-item-all">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 6C3 4.89543 3.89543 4 5 4H9L11 6H19C20.1046 6 21 6.89543 21 8V18C21 19.1046 20.1046 20 19 20H5C3.89543 20 3 19.1046 3 18V6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div class="download-item-info">
          <span class="download-item-quality">Browse All Files</span>
          <span class="download-item-size">View complete archive</span>
        </div>
      </a>
    `;

    this.downloadLinks.innerHTML = html;
  }

  toggleDownloads() {
    if (!this.downloadsPanel) return;
    const visible = this.downloadsPanel.style.display !== 'none';
    this.downloadsPanel.style.display = visible ? 'none' : 'block';
  }

  hideDownloads() {
    if (this.downloadsPanel) this.downloadsPanel.style.display = 'none';
  }

  // ========================================
  // Description
  // ========================================

  toggleDescription() {
    this.descriptionExpanded = !this.descriptionExpanded;
    if (this.descriptionContent) {
      this.descriptionContent.classList.toggle('expanded', this.descriptionExpanded);
    }
    if (this.descriptionToggle) {
      this.descriptionToggle.classList.toggle('expanded', this.descriptionExpanded);
    }
  }

  // ========================================
  // Bookmarks
  // ========================================

  toggleBookmark() {
    if (!this.videoId) return;

    if (this.bookmarkManager.isBookmarked(this.videoId)) {
      this.bookmarkManager.remove(this.videoId);
      this.updateBookmarkState();
      this.toast.show('Removed from bookmarks', 'info');
    } else {
      const title = this.videoTitle?.textContent || this.videoId;
      const creator = this.videoCreator?.textContent || 'Unknown';
      this.bookmarkManager.add({ identifier: this.videoId, title, creator });
      this.updateBookmarkState();
      this.toast.show('Added to bookmarks!', 'success');
    }
  }

  updateBookmarkState() {
    if (!this.bookmarkBtn) return;
    const isBookmarked = this.bookmarkManager.isBookmarked(this.videoId);
    this.bookmarkBtn.classList.toggle('active', isBookmarked);
    if (isBookmarked) {
      this.bookmarkBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z"/></svg>';
    } else {
      this.bookmarkBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
  }

  // ========================================
  // Share
  // ========================================

  shareVideo() {
    const track = this.playlistService.getCurrentIndex();
    const playlist = this.playlistService.getPlaylist();
    let link = `${window.location.origin}${window.location.pathname}?video=${this.videoId}`;
    if (playlist && track > 0) {
      link += `&track=${track + 1}`;
    }

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(link)
        .then(() => this.toast.show('Link copied to clipboard!', 'success'))
        .catch(() => this.showShareFallback(link));
    } else {
      this.showShareFallback(link);
    }
  }

  showShareFallback(url) {
    const modal = document.createElement('div');
    modal.className = 'share-modal-overlay';
    modal.innerHTML = `
      <div class="share-modal">
        <h3>Share this video</h3>
        <input value="${url}" readonly class="share-modal-input" />
        <button class="share-modal-close">Close</button>
      </div>`;
    document.body.appendChild(modal);
    modal.querySelector('input').select();
    modal.querySelector('.share-modal-close').onclick = () => modal.remove();
    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
  }

  // ========================================
  // UI Helpers
  // ========================================

  showLoader() {
    if (this.playerLoader) this.playerLoader.style.display = 'flex';
  }

  hideLoader() {
    if (this.playerLoader) this.playerLoader.style.display = 'none';
  }

  showError(message) {
    this.hideLoader();
    if (this.videoWrapper) {
      this.videoWrapper.innerHTML = `
        <div class="player-error">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
            <path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <p>${message}</p>
        </div>
      `;
    }
    if (this.videoTitle) this.videoTitle.textContent = 'Video not found';
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  try {
    window.videoPlayer = new VideoPlayer();
  } catch (error) {
    console.error('Failed to initialize player:', error);
  }
});

export default VideoPlayer;
