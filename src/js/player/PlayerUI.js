/**
 * PlayerUI - Handles all UI interactions for the player page
 * Theater mode, quality selector, keyboard indicators, resume prompt
 */

import { escapeHtml, formatFileSize, formatTime } from '../utils/helpers.js';

export class PlayerUI {
  constructor() {
    this.theaterMode = false;
    this.qualityMenuOpen = false;
    this.shortcutTimer = null;

    this.initElements();
    this.setupTheaterMode();
    this.setupQualitySelector();
    this.setupResumePrompt();
  }

  initElements() {
    this.playerCinema = document.getElementById('playerCinema');
    this.controlsBar = document.getElementById('controlsBar');
    this.theaterModeBtn = document.getElementById('theaterModeBtn');
    this.qualitySelector = document.getElementById('qualitySelector');
    this.qualityBtn = document.getElementById('qualityBtn');
    this.qualityLabel = document.getElementById('qualityLabel');
    this.qualityMenu = document.getElementById('qualityMenu');
    this.prevEpisodeBtn = document.getElementById('prevEpisodeBtn');
    this.nextEpisodeBtn = document.getElementById('nextEpisodeBtn');
    this.episodeIndicator = document.getElementById('episodeIndicator');
    this.shortcutIndicator = document.getElementById('shortcutIndicator');
    this.resumePrompt = document.getElementById('resumePrompt');
    this.resumeText = document.getElementById('resumeText');
    this.resumeBtn = document.getElementById('resumeBtn');
    this.resumeDismiss = document.getElementById('resumeDismiss');
    this.sidebarPrevBtn = document.getElementById('sidebarPrevBtn');
    this.sidebarNextBtn = document.getElementById('sidebarNextBtn');
  }

  // ========================================
  // Theater Mode
  // ========================================

  setupTheaterMode() {
    if (!this.theaterModeBtn) return;

    // Restore saved preference
    try {
      this.theaterMode = localStorage.getItem('theaterMode') === 'true';
      if (this.theaterMode) {
        document.body.classList.add('theater-mode');
      }
    } catch (e) {}

    this.theaterModeBtn.addEventListener('click', () => this.toggleTheaterMode());
  }

  toggleTheaterMode() {
    this.theaterMode = !this.theaterMode;
    document.body.classList.toggle('theater-mode', this.theaterMode);
    try {
      localStorage.setItem('theaterMode', this.theaterMode);
    } catch (e) {}
    this.showShortcutIndicator(this.theaterMode ? 'Theater Mode' : 'Default View');
  }

  // ========================================
  // Quality Selector
  // ========================================

  setupQualitySelector() {
    if (!this.qualityBtn || !this.qualityMenu) return;

    this.qualityBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      this.toggleQualityMenu();
    });

    // Close menu on outside click (store reference for potential cleanup)
    this._closeQualityMenuHandler = () => this.closeQualityMenu();
    document.addEventListener('click', this._closeQualityMenuHandler);
    this.qualityMenu.addEventListener('click', (e) => e.stopPropagation());
  }

  toggleQualityMenu() {
    this.qualityMenuOpen = !this.qualityMenuOpen;
    this.qualityMenu.classList.toggle('open', this.qualityMenuOpen);
  }

  closeQualityMenu() {
    this.qualityMenuOpen = false;
    this.qualityMenu.classList.remove('open');
  }

  /**
   * Build quality options from video files
   * @param {Array} files - Available video files for current episode
   * @param {string} currentFileName - Currently playing file name
   * @param {Function} onSelect - Callback when quality is selected
   */
  buildQualityOptions(files, currentFileName, onSelect) {
    if (!files || files.length <= 1) {
      if (this.qualitySelector) this.qualitySelector.style.display = 'none';
      return;
    }

    // Group by base name to find quality variants of the same video
    const mp4Files = files.filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));
    if (mp4Files.length <= 1) {
      if (this.qualitySelector) this.qualitySelector.style.display = 'none';
      return;
    }

    this.qualitySelector.style.display = '';

    this.qualityMenu.innerHTML = mp4Files.map(file => {
      const isActive = file.name === currentFileName;
      const quality = this.getQualityLabel(file.name);
      const size = formatFileSize(file.size);
      return `
        <button class="quality-option ${isActive ? 'active' : ''}" data-filename="${escapeHtml(file.name)}">
          <span>${quality}</span>
          ${size ? `<span class="quality-option-size">${size}</span>` : ''}
        </button>
      `;
    }).join('');

    // Update current label
    const currentQuality = this.getQualityLabel(currentFileName);
    if (this.qualityLabel) this.qualityLabel.textContent = currentQuality;

    // Attach listeners
    this.qualityMenu.querySelectorAll('.quality-option').forEach(opt => {
      opt.addEventListener('click', () => {
        const filename = opt.dataset.filename;
        this.closeQualityMenu();
        if (filename !== currentFileName) {
          onSelect(filename);
        }
      });
    });
  }

  getQualityLabel(filename) {
    const name = (filename || '').toLowerCase();
    if (name.includes('1080p') || name.includes('1920x1080')) return '1080p';
    if (name.includes('720p') || name.includes('1280x720')) return '720p';
    if (name.includes('480p') || name.includes('854x480')) return '480p';
    if (name.includes('360p') || name.includes('640x360')) return '360p';
    if (name.includes('_h264')) return 'H.264';
    if (name.includes('_512kb')) return 'SD';
    if (name.includes('_archive')) return 'Archive';
    return 'Auto';
  }

  // ========================================
  // Episode Navigation UI
  // ========================================

  showEpisodeControls(currentIndex, totalCount) {
    if (this.prevEpisodeBtn) {
      this.prevEpisodeBtn.style.display = '';
      this.prevEpisodeBtn.disabled = currentIndex <= 0;
    }
    if (this.nextEpisodeBtn) {
      this.nextEpisodeBtn.style.display = '';
      this.nextEpisodeBtn.disabled = currentIndex >= totalCount - 1;
    }
    if (this.episodeIndicator) {
      this.episodeIndicator.style.display = '';
      this.episodeIndicator.textContent = `${currentIndex + 1} / ${totalCount}`;
    }
    if (this.sidebarPrevBtn) {
      this.sidebarPrevBtn.disabled = currentIndex <= 0;
    }
    if (this.sidebarNextBtn) {
      this.sidebarNextBtn.disabled = currentIndex >= totalCount - 1;
    }
  }

  hideEpisodeControls() {
    if (this.prevEpisodeBtn) this.prevEpisodeBtn.style.display = 'none';
    if (this.nextEpisodeBtn) this.nextEpisodeBtn.style.display = 'none';
    if (this.episodeIndicator) this.episodeIndicator.style.display = 'none';
  }

  // ========================================
  // Keyboard Shortcut Indicator
  // ========================================

  showShortcutIndicator(text, icon) {
    if (!this.shortcutIndicator) return;

    clearTimeout(this.shortcutTimer);

    const iconSvg = icon || '';
    this.shortcutIndicator.innerHTML = iconSvg ? `${iconSvg} ${escapeHtml(text)}` : escapeHtml(text);
    this.shortcutIndicator.classList.add('visible');

    this.shortcutTimer = setTimeout(() => {
      this.shortcutIndicator.classList.remove('visible');
    }, 800);
  }

  // ========================================
  // Resume Prompt (non-blocking)
  // ========================================

  setupResumePrompt() {
    if (this.resumeDismiss) {
      this.resumeDismiss.addEventListener('click', () => this.hideResumePrompt());
    }
  }

  showResumePrompt(timeStr, onResume) {
    if (!this.resumePrompt || !this.resumeText || !this.resumeBtn) return;

    this.resumeText.textContent = `Resume from ${timeStr}?`;
    this.resumePrompt.style.display = '';

    // Remove old listener and add new
    const newBtn = this.resumeBtn.cloneNode(true);
    this.resumeBtn.parentNode.replaceChild(newBtn, this.resumeBtn);
    this.resumeBtn = newBtn;

    this.resumeBtn.addEventListener('click', () => {
      onResume();
      this.hideResumePrompt();
    });

    // Auto-dismiss after 10s
    this._resumeAutoHide = setTimeout(() => this.hideResumePrompt(), 10000);
  }

  hideResumePrompt() {
    if (this.resumePrompt) this.resumePrompt.style.display = 'none';
    clearTimeout(this._resumeAutoHide);
  }
}

export default PlayerUI;
