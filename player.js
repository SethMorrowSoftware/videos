/**
 * Archive Film Club - Dedicated Player Page v2.0
 * Immersive video player with rich playlist, quality selector, theater mode
 */

import { VideoService } from './src/js/services/VideoService.js';
import { PlaylistService } from './src/js/services/PlaylistService.js';
import { VideoProgressTracker } from './src/js/services/VideoProgressTracker.js';
import { BookmarkManager } from './src/js/services/BookmarkManager.js';
import { Toast } from './src/js/components/Toast.js';
import { AuthNav } from './src/js/components/AuthNav.js';
import { CollectionPicker } from './src/js/components/CollectionPicker.js';
import { PlayerUI } from './src/js/player/PlayerUI.js';
import { PlayerPlaylist } from './src/js/player/PlayerPlaylist.js';

// Mount auth nav early
AuthNav.mount();
import {
  escapeHtml,
  sanitizeHtml,
  extractValue,
  formatRuntime,
  formatTime,
  formatFileSize
} from './src/js/utils/helpers.js';

class VideoPlayer {
  constructor() {
    this.videoService = new VideoService();
    this.playlistService = new PlaylistService(this.videoService);
    this.progressTracker = new VideoProgressTracker();
    this.bookmarkManager = new BookmarkManager();
    this.toast = new Toast();
    this.ui = new PlayerUI();
    this.playlist = new PlayerPlaylist(this.videoService, this.playlistService);

    this.siteSettings = this.loadSiteSettings();
    this.videoId = null;
    this.trackIndex = null;
    this.metadata = null;
    this.videoFiles = [];
    this.allVideoFiles = []; // All files including quality variants
    this.currentFileName = null;
    this.descriptionExpanded = true;
    // Tracks playlist indices that have failed to play in this session,
    // so cascading auto-skip never re-tries a known-bad item.
    this.failedPlaylistIndices = new Set();

    this.initElements();
    this.setupEventListeners();
    this.parseUrlAndLoad();
  }

  loadSiteSettings() {
    const el = document.getElementById('siteSettingsConfig');
    if (el) {
      try { return JSON.parse(el.textContent); } catch (e) {}
    }
    return {};
  }

  initElements() {
    this.videoWrapper = document.getElementById('videoWrapper');
    this.playerLoader = document.getElementById('playerLoader');
    this.videoTitle = document.getElementById('videoTitle');
    this.videoCreator = document.getElementById('videoCreator');
    this.videoDate = document.getElementById('videoDate');
    this.videoMetaPills = document.getElementById('videoMetaPills');
    this.videoTagsRow = document.getElementById('videoTagsRow');
    this.archiveLink = document.getElementById('archiveLink');
    this.descriptionSection = document.getElementById('descriptionSection');
    this.descriptionToggle = document.getElementById('descriptionToggle');
    this.descriptionContent = document.getElementById('descriptionContent');
    this.downloadsPanel = document.getElementById('downloadsPanel');
    this.downloadLinks = document.getElementById('downloadLinks');
    this.shareBtn = document.getElementById('shareBtn');
    this.bookmarkBtn = document.getElementById('bookmarkBtn');
    this.saveToCollectionBtn = document.getElementById('saveToCollectionBtn');
    this.downloadBtn = document.getElementById('downloadBtn');
    this.closeDownloads = document.getElementById('closeDownloads');
    this.upNextOverlay = document.getElementById('upNextOverlay');
    this.upNextThumb = document.getElementById('upNextThumb');
    if (this.upNextThumb) {
      this.upNextThumb.addEventListener('error', () => {
        this.upNextThumb.hidden = true;
      });
      this.upNextThumb.addEventListener('load', () => {
        this.upNextThumb.hidden = false;
      });
    }
    this.upNextTitle = document.getElementById('upNextTitle');
    this.upNextCountdown = document.getElementById('upNextCountdown');
    this.upNextPlay = document.getElementById('upNextPlay');
    this.upNextCancel = document.getElementById('upNextCancel');
    this.playerCinema = document.getElementById('playerCinema');
    this._upNextTimer = null;
  }

  setupEventListeners() {
    // Share
    if (this.shareBtn) this.shareBtn.addEventListener('click', () => this.shareVideo());

    // Bookmark
    if (this.bookmarkBtn) this.bookmarkBtn.addEventListener('click', () => this.toggleBookmark());

    // Save to collection
    if (this.saveToCollectionBtn) {
      this.saveToCollectionBtn.addEventListener('click', () => this.openCollectionPicker());
    }

    // Downloads
    if (this.downloadBtn) this.downloadBtn.addEventListener('click', () => this.toggleDownloads());
    if (this.closeDownloads) this.closeDownloads.addEventListener('click', () => this.hideDownloads());

    // Description
    if (this.descriptionToggle) this.descriptionToggle.addEventListener('click', () => this.toggleDescription());

    // Keyboard
    document.addEventListener('keydown', (e) => this.handleKeyboard(e));

    // Popstate
    window.addEventListener('popstate', () => this.parseUrlAndLoad());

    // Episode navigation buttons
    if (this.ui.prevEpisodeBtn) {
      this.ui.prevEpisodeBtn.addEventListener('click', () => this.playPreviousEpisode());
    }
    if (this.ui.nextEpisodeBtn) {
      this.ui.nextEpisodeBtn.addEventListener('click', () => this.playNextEpisode());
    }
    if (this.ui.sidebarPrevBtn) {
      this.ui.sidebarPrevBtn.addEventListener('click', () => this.playPreviousEpisode());
    }
    if (this.ui.sidebarNextBtn) {
      this.ui.sidebarNextBtn.addEventListener('click', () => this.playNextEpisode());
    }

    // Up Next overlay
    if (this.upNextPlay) this.upNextPlay.addEventListener('click', () => this.confirmUpNext());
    if (this.upNextCancel) this.upNextCancel.addEventListener('click', () => this.cancelUpNext());

    // Mirror fullscreen state on the page so CSS can adapt the layout.
    // Triggered by the native <video> fullscreen button or the `f` keyboard shortcut.
    document.addEventListener('fullscreenchange', () => this._syncFullscreenState());
    document.addEventListener('webkitfullscreenchange', () => this._syncFullscreenState());
  }

  /**
   * Fullscreen target is the cinema container, NOT the bare <video>:
   *   - keeps the Up Next overlay + shortcut indicator visible across episodes
   *   - keeps fullscreen alive when the playlist auto-advances, because
   *     VideoService now reuses the <video> element instead of replacing
   *     the wrapper's innerHTML.
   */
  toggleFullscreen() {
    const target = this.playerCinema || this.videoWrapper;
    if (!target) return;
    const fsEl = document.fullscreenElement || document.webkitFullscreenElement;

    if (fsEl) {
      const exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (exit) exit.call(document);
      return;
    }

    const req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (!req) return;
    const result = req.call(target);
    if (result && typeof result.catch === 'function') {
      result.catch(err => {
        console.warn('[player] fullscreen request rejected:', err && err.message);
      });
    }
  }

  _syncFullscreenState() {
    const fs = document.fullscreenElement || document.webkitFullscreenElement;
    document.body.classList.toggle('player-is-fullscreen', !!fs);

    // If the user entered fullscreen via the <video>'s native button
    // (common on mobile/touch), fullscreen lives on the bare <video> and
    // NOT on the cinema container. That breaks two things:
    //   1) The Up Next overlay + shortcut indicator are siblings of the
    //      video, so they're invisible in fullscreen.
    //   2) Swapping the <source> for the next episode forces the browser
    //      to drop fullscreen, so auto-advance kicks the user back out.
    // Promote fullscreen to the cinema container — browsers allow
    // re-requesting fullscreen on a different element while already in
    // fullscreen, and the transition is seamless.
    if (fs && this.playerCinema && fs !== this.playerCinema) {
      this._promoteFullscreenToCinema();
    }
  }

  _promoteFullscreenToCinema() {
    if (this._promotingFullscreen) return;
    const target = this.playerCinema;
    if (!target) return;
    const req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (!req) return;
    this._promotingFullscreen = true;
    try {
      const result = req.call(target);
      if (result && typeof result.then === 'function') {
        result.finally(() => { this._promotingFullscreen = false; });
      } else {
        this._promotingFullscreen = false;
      }
    } catch (e) {
      this._promotingFullscreen = false;
    }
  }

  // ========================================
  // Keyboard Shortcuts
  // ========================================

  handleKeyboard(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;

    const video = this.videoWrapper?.querySelector('video');

    switch (e.key) {
      case ' ':
      case 'k':
        if (!video) return;
        e.preventDefault();
        if (video.paused) {
          video.play();
          this.ui.showShortcutIndicator('Play', '<svg width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M5 3L19 12L5 21V3Z"/></svg>');
        } else {
          video.pause();
          this.ui.showShortcutIndicator('Pause', '<svg width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M6 4H10V20H6V4ZM14 4H18V20H14V4Z"/></svg>');
        }
        break;
      case 'f':
        e.preventDefault();
        this.toggleFullscreen();
        break;
      case 'm':
        if (!video) return;
        e.preventDefault();
        video.muted = !video.muted;
        this.ui.showShortcutIndicator(video.muted ? 'Muted' : 'Unmuted');
        break;
      case 't':
        e.preventDefault();
        this.ui.toggleTheaterMode();
        break;
      case 'ArrowLeft':
        if (!video) return;
        e.preventDefault();
        video.currentTime = Math.max(0, video.currentTime - 5);
        this.ui.showShortcutIndicator('-5s');
        break;
      case 'ArrowRight':
        if (!video) return;
        e.preventDefault();
        video.currentTime = Math.min(video.duration || 0, video.currentTime + 5);
        this.ui.showShortcutIndicator('+5s');
        break;
      case 'ArrowUp':
        if (!video) return;
        e.preventDefault();
        video.volume = Math.min(1, video.volume + 0.1);
        this.ui.showShortcutIndicator(`Volume ${Math.round(video.volume * 100)}%`);
        break;
      case 'ArrowDown':
        if (!video) return;
        e.preventDefault();
        video.volume = Math.max(0, video.volume - 0.1);
        this.ui.showShortcutIndicator(`Volume ${Math.round(video.volume * 100)}%`);
        break;
      case 'j':
        if (!video) return;
        e.preventDefault();
        video.currentTime = Math.max(0, video.currentTime - 10);
        this.ui.showShortcutIndicator('-10s');
        break;
      case 'l':
        if (!video) return;
        e.preventDefault();
        video.currentTime = Math.min(video.duration || 0, video.currentTime + 10);
        this.ui.showShortcutIndicator('+10s');
        break;
      case 'N':
        if (e.shiftKey) {
          e.preventDefault();
          this.playNextEpisode();
        }
        break;
      case 'P':
        if (e.shiftKey) {
          e.preventDefault();
          this.playPreviousEpisode();
        }
        break;
    }
  }

  // ========================================
  // URL Parsing & Loading
  // ========================================

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
    this.failedPlaylistIndices = new Set();

    try {
      const metadata = await this.videoService.getVideoMetadata(this.videoId);
      this.metadata = metadata;

      const meta = metadata.metadata || metadata;
      this.updateVideoInfo(meta);

      // Pre-compute the playable + deduplicated file list BEFORE we kick
      // off playback, so multi-episode items load the requested track
      // (or episode 1) instead of whatever the global "best quality"
      // heuristic happens to pick.
      const allFiles = this.videoService.getVideoFiles(
        metadata.metadata ? metadata : { metadata, files: metadata.files }
      );
      const deduplicatedFiles = this.videoService.deduplicateVideoFiles(allFiles);
      const isMultiEpisode = deduplicatedFiles.length > 1
        && this.videoService.hasMultipleUniqueVideos(deduplicatedFiles);

      let initialFile = null;
      let startIndex = 0;
      if (isMultiEpisode) {
        startIndex = (this.trackIndex !== null
            && this.trackIndex >= 0
            && this.trackIndex < deduplicatedFiles.length)
          ? this.trackIndex : 0;
        initialFile = deduplicatedFiles[startIndex]?.name || null;
      }

      // Load and play
      const videoData = await this.videoService.loadNativeVideo(
        this.videoId,
        metadata,
        this.videoWrapper,
        initialFile,
        this.getSavedVolume()
      );

      this.hideLoader();
      this.currentFileName = videoData.selectedFile?.name;
      this.setupVideoListeners(videoData.videoElement);

      // Non-blocking resume
      const progress = this.progressTracker.getProgress(this.videoId);
      if (progress && videoData.videoElement && !this.requestedTimestamp) {
        const timeStr = formatTime(progress.currentTime);
        this.ui.showResumePrompt(timeStr, () => {
          videoData.videoElement.currentTime = progress.currentTime;
        });
      }

      // Apply requested timestamp
      if (this.requestedTimestamp && videoData.videoElement) {
        setTimeout(() => {
          videoData.videoElement.currentTime = this.requestedTimestamp;
        }, 300);
      }

      this.videoFiles = deduplicatedFiles;
      this.allVideoFiles = videoData.videoFiles;

      if (isMultiEpisode) {
        this.setupPlaylist(meta, deduplicatedFiles, startIndex);
      }

      // Quality selector for non-playlist single videos
      if (!this.playlist.isVisible()) {
        this.ui.buildQualityOptions(videoData.videoFiles, this.currentFileName, (filename) => {
          this.switchQuality(filename);
        });
      }

      // Downloads
      this.buildDownloadLinks(deduplicatedFiles.length > 0 ? deduplicatedFiles : videoData.videoFiles);

      // Bookmark state
      this.updateBookmarkState();

    } catch (err) {
      console.warn('Native load failed, trying iframe:', err.message);
      try {
        this.videoService.loadIframeVideo(this.videoId, this.videoWrapper);
        this.hideLoader();
        if (this.metadata) {
          const meta = this.metadata.metadata || this.metadata;
          this.updateVideoInfo(meta);
        }
      } catch (iframeErr) {
        this.showError('Failed to load video. <a href="index.php">Browse videos</a>');
      }
    }
  }

  // ========================================
  // Video Info
  // ========================================

  updateVideoInfo(meta) {
    const title = extractValue(meta.title) || this.videoId;
    const creator = extractValue(meta.creator) || '';
    const date = extractValue(meta.date) || '';
    const description = extractValue(meta.description) || '';

    document.title = `${title} - ${this.siteSettings.siteName || 'Archive Film Club'}`;

    if (this.videoTitle) this.videoTitle.textContent = title;

    if (this.videoCreator && creator) {
      this.videoCreator.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 20C4 16.6863 7.58172 14 12 14C16.4183 14 20 16.6863 20 20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ${escapeHtml(creator)}`;
    }

    if (this.videoDate && date) {
      const formatted = new Date(date).toLocaleDateString();
      this.videoDate.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ${formatted}`;
    }

    if (this.archiveLink) {
      this.archiveLink.href = `https://archive.org/details/${this.videoId}`;
    }

    this._renderMetaPills(meta);
    this._renderTags(meta);

    if (description && description.length > 10) {
      if (this.descriptionSection) this.descriptionSection.style.display = 'block';
      if (this.descriptionContent) {
        this.descriptionContent.innerHTML = sanitizeHtml(description);
        this.descriptionContent.classList.add('expanded');
      }
      if (this.descriptionToggle) this.descriptionToggle.classList.add('expanded');
    }
  }

  /**
   * Render metadata pills (year, runtime, language, mediatype, license, downloads, rating).
   * Pulls from Archive.org's metadata blob, which is loose JSON — every field
   * may be a string or array, hence the extractValue helper.
   */
  _renderMetaPills(meta) {
    if (!this.videoMetaPills) return;
    const pills = [];

    const date = extractValue(meta.date) || '';
    if (date) {
      const year = String(date).slice(0, 4);
      if (/^\d{4}$/.test(year)) pills.push({ icon: 'calendar', text: year });
    }

    const runtime = formatRuntime(extractValue(meta.runtime));
    if (runtime) pills.push({ icon: 'clock', text: runtime });

    const language = extractValue(meta.language);
    if (language) pills.push({ icon: 'globe', text: this._titleCase(String(language)) });

    const mediatype = extractValue(meta.mediatype);
    if (mediatype && mediatype !== 'movies') {
      pills.push({ icon: 'media', text: this._titleCase(String(mediatype)) });
    }

    const downloads = parseInt(extractValue(meta.downloads), 10);
    if (!isNaN(downloads) && downloads > 0) {
      pills.push({ icon: 'download', text: `${this._formatCount(downloads)} downloads` });
    }

    const reviews = parseInt(extractValue(meta.num_reviews), 10);
    const avg = parseFloat(extractValue(meta.avg_rating));
    if (!isNaN(avg) && avg > 0) {
      const ratingText = !isNaN(reviews) && reviews > 0
        ? `${avg.toFixed(1)} (${reviews})`
        : avg.toFixed(1);
      pills.push({ icon: 'star', text: ratingText, tone: 'star' });
    }

    const license = String(extractValue(meta.licenseurl) || '').toLowerCase();
    if (license.includes('publicdomain')) {
      pills.push({ icon: 'shield', text: 'Public Domain', tone: 'success' });
    } else if (license.includes('creativecommons')) {
      pills.push({ icon: 'shield', text: 'Creative Commons', tone: 'accent' });
    }

    if (!pills.length) {
      this.videoMetaPills.innerHTML = '';
      this.videoMetaPills.style.display = 'none';
      return;
    }

    const icons = {
      calendar: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
      clock: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
      globe: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20z"/></svg>',
      media: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M22 8 16 12 22 16"/></svg>',
      download: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
      star: '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linejoin="round"><polygon points="12 2 15.1 8.6 22 9.6 17 14.4 18.2 21.3 12 18 5.8 21.3 7 14.4 2 9.6 8.9 8.6 12 2"/></svg>',
      shield: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    };

    this.videoMetaPills.style.display = 'flex';
    this.videoMetaPills.innerHTML = pills.map(p =>
      `<span class="player-meta-pill ${p.tone ? 'tone-' + p.tone : ''}">
        ${icons[p.icon] || ''}
        <span>${escapeHtml(p.text)}</span>
      </span>`
    ).join('');
  }

  /**
   * Render up to 8 subject/topic tags as pills.
   */
  _renderTags(meta) {
    if (!this.videoTagsRow) return;
    const subjects = meta.subject;
    if (!subjects) {
      this.videoTagsRow.innerHTML = '';
      this.videoTagsRow.style.display = 'none';
      return;
    }
    const list = Array.isArray(subjects)
      ? subjects
      : String(subjects).split(/[;,]+/).map(s => s.trim()).filter(Boolean);

    const seen = new Set();
    const dedup = [];
    for (const t of list) {
      const k = t.toLowerCase();
      if (k && !seen.has(k)) { seen.add(k); dedup.push(t); }
      if (dedup.length >= 8) break;
    }

    if (!dedup.length) {
      this.videoTagsRow.innerHTML = '';
      this.videoTagsRow.style.display = 'none';
      return;
    }

    this.videoTagsRow.style.display = 'flex';
    this.videoTagsRow.innerHTML =
      '<span class="player-tags-label">Topics</span>' +
      dedup.map(t =>
        `<a class="player-tag" href="index.php?q=${encodeURIComponent(t)}" title="Search ${escapeHtml(t)}">#${escapeHtml(t)}</a>`
      ).join('');
  }

  _formatCount(n) {
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(n >= 10_000_000 ? 0 : 1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(n >= 10_000 ? 0 : 1) + 'K';
    return String(n);
  }

  _titleCase(s) {
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  // ========================================
  // Video Event Listeners
  // ========================================

  setupVideoListeners(videoEl) {
    if (!videoEl) return;

    // Reusing the <video> element across track changes means this gets
    // called multiple times against the same node. Bail early on the
    // second+ call so we don't stack pause/ended/volume listeners.
    if (videoEl.dataset.afcListenersAttached === '1') return;
    videoEl.dataset.afcListenersAttached = '1';

    videoEl.addEventListener('pause', () => {
      if (this.videoId && videoEl.currentTime && videoEl.duration) {
        this.progressTracker.saveProgress(this.videoId, videoEl.currentTime, videoEl.duration);
        const idx = this.playlistService.getCurrentIndex();
        if (this.playlist.isVisible() && this.playlistService.getPlaylist()) {
          this.playlist.saveTrackProgress(idx, videoEl.currentTime, videoEl.duration);
        }
      }
    });

    // Periodically persist per-episode progress to drive playlist progress bars.
    if (this._trackProgressInterval) clearInterval(this._trackProgressInterval);
    this._trackProgressInterval = setInterval(() => {
      if (!videoEl || videoEl.paused || !videoEl.duration) return;
      const idx = this.playlistService.getCurrentIndex();
      if (this.playlist.isVisible() && this.playlistService.getPlaylist()) {
        this.playlist.saveTrackProgress(idx, videoEl.currentTime, videoEl.duration);
      }
    }, 5000);

    videoEl.addEventListener('ended', () => {
      if (this.videoId && videoEl.duration) {
        this.progressTracker.saveProgress(this.videoId, videoEl.duration, videoEl.duration);
      }
      const idx = this.playlistService.getCurrentIndex();
      if (this.playlist.isVisible() && this.playlistService.getPlaylist()) {
        this.playlist.markWatched(idx, videoEl.duration);
      }
      // Up Next overlay (only if there is a next + an actual playlist)
      if (this.playlistService.hasNext()) {
        this.showUpNext();
      }
    });

    videoEl.addEventListener('volumechange', () => {
      try { localStorage.setItem('playerVolume', videoEl.volume); } catch (e) {}
    });

    // Recover from unplayable files (404, codec mismatch, network errors).
    // In playlist mode we mark the current track as bad and auto-skip to
    // the next playable one. In single-video mode we fall back to the
    // Archive.org iframe, which can handle codecs the native element
    // can't.
    videoEl.addEventListener('error', () => {
      const code = videoEl.error?.code;
      const msg = videoEl.error?.message || '';
      // Ignore the spurious empty error fired when we replace src during
      // teardown — error code 4 (MEDIA_ERR_SRC_NOT_SUPPORTED) with an
      // empty currentSrc fits that pattern.
      if (!videoEl.currentSrc) return;
      console.warn('[player] video element error', { code, msg, src: videoEl.currentSrc });

      const pl = this.playlistService.getPlaylist();
      if (pl && pl.videoFiles.length > 1) {
        const failedIdx = this.playlistService.getCurrentIndex();
        this.failedPlaylistIndices.add(failedIdx);
        this.toast.show(`Episode ${failedIdx + 1} could not be played, skipping…`, 'error');
        const nextIdx = this.playlistService.findNextPlayable(this.failedPlaylistIndices);
        if (nextIdx !== -1) {
          this.playPlaylistItem(nextIdx);
        } else {
          this.toast.show('No more playable episodes in this playlist.', 'error');
        }
      } else {
        this.toast.show('Native playback failed, switching to embed…', 'info');
        try {
          this.videoService.loadIframeVideo(this.videoId, this.videoWrapper);
        } catch (e) {
          this.showError('Unable to play this video. <a href="index.php">Browse videos</a>');
        }
      }
    });
  }

  /**
   * Show the "Up Next" countdown overlay. Auto-advances after 8 seconds
   * unless the user clicks Cancel.
   */
  showUpNext() {
    if (!this.upNextOverlay) {
      // Fallback to immediate auto-play if the overlay markup isn't present.
      const nextIdx = this.playlistService.next();
      if (nextIdx !== -1) this.playPlaylistItem(nextIdx);
      return;
    }
    const pl = this.playlistService.getPlaylist();
    if (!pl) return;
    const nextIdx = this.playlistService.getCurrentIndex() + 1;
    const nextFile = pl.videoFiles[nextIdx];
    if (!nextFile) return;

    const itemTitle = extractValue(pl.metadata?.title) || '';
    const cleanTitle = this.videoService.getCleanTitle(nextFile.name, itemTitle);

    if (this.upNextThumb) {
      this.upNextThumb.hidden = false;
      this.upNextThumb.src = `https://archive.org/services/img/${pl.id}`;
    }
    if (this.upNextTitle) {
      this.upNextTitle.textContent = cleanTitle;
    }

    let remaining = 8;
    if (this.upNextCountdown) {
      this.upNextCountdown.textContent = `Playing in ${remaining}…`;
    }
    this.upNextOverlay.classList.add('visible');

    if (this._upNextTimer) clearInterval(this._upNextTimer);
    this._upNextTimer = setInterval(() => {
      remaining--;
      if (this.upNextCountdown) {
        this.upNextCountdown.textContent = remaining > 0
          ? `Playing in ${remaining}…`
          : 'Playing next…';
      }
      if (remaining <= 0) {
        this.confirmUpNext();
      }
    }, 1000);
  }

  confirmUpNext() {
    this.cancelUpNext();
    if (this.playlistService.hasNext()) {
      const idx = this.playlistService.next();
      this.playPlaylistItem(idx);
    }
  }

  cancelUpNext() {
    if (this._upNextTimer) {
      clearInterval(this._upNextTimer);
      this._upNextTimer = null;
    }
    if (this.upNextOverlay) this.upNextOverlay.classList.remove('visible');
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
  // Quality Switching
  // ========================================

  async switchQuality(filename) {
    const video = this.videoWrapper?.querySelector('video');
    const currentTime = video?.currentTime || 0;
    const wasPaused = video?.paused;

    this.showLoader();

    try {
      const videoData = await this.videoService.loadNativeVideo(
        this.videoId,
        this.metadata,
        this.videoWrapper,
        filename,
        this.getSavedVolume()
      );
      this.hideLoader();
      this.currentFileName = filename;
      this.setupVideoListeners(videoData.videoElement);

      // Restore position after quality switch
      if (videoData.videoElement) {
        videoData.videoElement.addEventListener('loadedmetadata', () => {
          videoData.videoElement.currentTime = currentTime;
          if (!wasPaused) videoData.videoElement.play();
        }, { once: true });
      }

      // Update quality UI
      this.ui.buildQualityOptions(this.allVideoFiles, filename, (fn) => {
        this.switchQuality(fn);
      });

      this.toast.show('Quality changed', 'info');
    } catch (err) {
      this.hideLoader();
      this.toast.show('Failed to switch quality', 'error');
    }
  }

  // ========================================
  // Playlist
  // ========================================

  setupPlaylist(meta, videoFiles, startIndex) {
    this.playlist.show(
      { ...meta, identifier: this.videoId },
      videoFiles,
      startIndex,
      (idx) => this.playPlaylistItem(idx)
    );

    this.ui.showEpisodeControls(startIndex, videoFiles.length);
  }

  async playPlaylistItem(index) {
    const pl = this.playlistService.getPlaylist();
    if (!pl) return;

    const file = pl.videoFiles[index];
    if (!file) return;

    this.cancelUpNext();
    this.playlistService.setCurrentIndex(index);

    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('track', String(index + 1));
    window.history.replaceState({}, '', url);

    this.playlist.render(index);
    this.ui.showEpisodeControls(index, pl.videoFiles.length);
    this.showLoader();

    try {
      const videoData = await this.videoService.loadNativeVideo(
        pl.id,
        { metadata: pl.metadata, files: pl.videoFiles },
        this.videoWrapper,
        file.name,
        this.getSavedVolume()
      );
      this.hideLoader();
      this.currentFileName = file.name;
      this.setupVideoListeners(videoData.videoElement);

      // Update title
      const epTitle = this.videoService.getCleanTitle(file.name, extractValue(pl.metadata?.title));
      if (this.videoTitle) this.videoTitle.textContent = epTitle;

    } catch (err) {
      console.error('Error loading playlist item:', err);
      this.hideLoader();
      this.failedPlaylistIndices.add(index);
      this.toast.show(`Failed to load episode ${index + 1}`, 'error');

      const nextIdx = this.playlistService.findNextPlayable(this.failedPlaylistIndices);
      if (nextIdx !== -1) {
        setTimeout(() => this.playPlaylistItem(nextIdx), 1000);
      } else {
        this.toast.show('No more playable episodes in this playlist.', 'error');
      }
    }
  }

  playNextEpisode() {
    if (this.playlistService.hasNext()) {
      const idx = this.playlistService.next();
      this.playPlaylistItem(idx);
    }
  }

  playPreviousEpisode() {
    if (this.playlistService.hasPrevious()) {
      const idx = this.playlistService.previous();
      this.playPlaylistItem(idx);
    }
  }

  // ========================================
  // Downloads
  // ========================================

  buildDownloadLinks(videoFiles) {
    if (!this.downloadLinks || !videoFiles.length) return;

    const mp4Files = videoFiles.filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));
    const otherFiles = videoFiles.filter(f => !(f.name || '').toLowerCase().endsWith('.mp4'));

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
    if (this.descriptionContent) this.descriptionContent.classList.toggle('expanded', this.descriptionExpanded);
    if (this.descriptionToggle) this.descriptionToggle.classList.toggle('expanded', this.descriptionExpanded);
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
    this.bookmarkBtn.innerHTML = isBookmarked
      ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z"/></svg>'
      : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  // ========================================
  // Collections
  // ========================================

  openCollectionPicker() {
    if (!this.videoId) return;
    const title = this.videoTitle?.textContent || this.videoId;
    const creator = this.videoCreator?.textContent || '';
    CollectionPicker.open({
      video: {
        identifier: this.videoId,
        title,
        creator,
        thumbnail: `https://archive.org/services/img/${this.videoId}`,
      },
      onChange: () => {
        this.toast.show('Collection updated', 'success');
      },
    });
  }

  // ========================================
  // Share
  // ========================================

  shareVideo() {
    const track = this.playlistService.getCurrentIndex();
    const pl = this.playlistService.getPlaylist();
    let link = `${window.location.origin}${window.location.pathname}?video=${this.videoId}`;
    if (pl && track > 0) link += `&track=${track + 1}`;

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
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Share link');
    modal.innerHTML = `
      <div class="share-modal">
        <h3>Share this video</h3>
        <input type="text" readonly class="share-modal-input" />
        <button type="button" class="share-modal-close">Close</button>
      </div>`;
    const input = modal.querySelector('input');
    input.value = url;
    document.body.appendChild(modal);
    input.focus();
    input.select();
    // See app.js showShareFallback — close() must tear down the keydown
    // listener no matter how the modal is dismissed.
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const close = () => {
      document.removeEventListener('keydown', onKey);
      modal.remove();
    };
    modal.querySelector('.share-modal-close').onclick = close;
    modal.onclick = (e) => { if (e.target === modal) close(); };
    document.addEventListener('keydown', onKey);
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

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  try {
    window.videoPlayer = new VideoPlayer();
  } catch (error) {
    console.error('Failed to initialize player:', error);
  }
});

export default VideoPlayer;

// Service worker registration (same rationale as app.js -- relative URL
// so subdirectory installs resolve correctly).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch((err) => {
      console.warn('[SW] Registration failed:', err);
    });
  });
}
