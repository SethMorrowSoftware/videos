/**
 * PlayerPlaylist - Rich playlist rendering for the player page
 * Thumbnails, episode metadata, now-playing indicators
 */

import { escapeHtml, extractValue, formatRuntime, formatFileSize } from '../utils/helpers.js';

export class PlayerPlaylist {
  constructor(videoService, playlistService) {
    this.videoService = videoService;
    this.playlistService = playlistService;

    this.sidebar = document.getElementById('playlistSidebar');
    this.titleEl = document.getElementById('playlistTitle');
    this.countEl = document.getElementById('playlistCount');
    this.itemsEl = document.getElementById('playlistItems');

    this.onItemClick = null; // callback
  }

  /**
   * Initialize and show the playlist
   */
  show(meta, videoFiles, startIndex, onItemClick) {
    this.onItemClick = onItemClick;
    const title = extractValue(meta.title) || 'Episodes';
    const creator = extractValue(meta.creator) || 'Unknown';

    this.playlistService.initPlaylist(
      meta.identifier || '',
      title,
      creator,
      meta,
      videoFiles,
      startIndex
    );

    if (this.sidebar) this.sidebar.style.display = 'flex';
    if (this.titleEl) this.titleEl.textContent = 'Episodes';
    if (this.countEl) this.countEl.textContent = `${videoFiles.length} episodes`;

    this.render(startIndex);
  }

  /**
   * Render the playlist items
   */
  render(activeIndex = 0) {
    if (!this.itemsEl) return;

    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;

    const { id, videoFiles, metadata } = playlist;
    const itemTitle = extractValue(metadata?.title) || '';
    const thumbUrl = `https://archive.org/services/img/${id}`;

    this.itemsEl.innerHTML = videoFiles.map((file, i) => {
      const epTitle = this.videoService.getCleanTitle(file.name, itemTitle);
      const duration = formatRuntime(file.length) || '';
      const size = file.size ? formatFileSize(file.size) : '';
      const isActive = i === activeIndex;

      return `
        <div class="playlist-item ${isActive ? 'active' : ''}" data-index="${i}">
          <span class="playlist-item-number">${isActive
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3L19 12L5 21V3Z"/></svg>'
            : (i + 1)}</span>
          <div class="playlist-item-thumb">
            <img src="${thumbUrl}"
                 alt=""
                 loading="lazy"
                 onerror="this.style.display='none'" />
            ${isActive ? `
              <div class="playlist-item-playing-icon">
                <div class="playing-bars"><span></span><span></span><span></span></div>
              </div>
            ` : ''}
            ${duration ? `<span class="playlist-item-duration-badge">${duration}</span>` : ''}
          </div>
          <div class="playlist-item-info">
            <span class="playlist-item-title">${escapeHtml(epTitle)}</span>
            <div class="playlist-item-meta">
              ${duration ? `<span>${duration}</span>` : ''}
              ${size ? `<span>${size}</span>` : ''}
            </div>
          </div>
        </div>
      `;
    }).join('');

    // Attach click listeners
    this.itemsEl.querySelectorAll('.playlist-item').forEach(item => {
      item.addEventListener('click', () => {
        const idx = parseInt(item.dataset.index, 10);
        if (idx !== activeIndex && this.onItemClick) {
          this.onItemClick(idx);
        }
      });
    });

    // Scroll active into view
    requestAnimationFrame(() => {
      const activeItem = this.itemsEl.querySelector('.playlist-item.active');
      if (activeItem) {
        activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    });
  }

  /**
   * Get current playlist data
   */
  getPlaylist() {
    return this.playlistService.getPlaylist();
  }

  /**
   * Check visibility
   */
  isVisible() {
    return this.sidebar && this.sidebar.style.display !== 'none';
  }
}

export default PlayerPlaylist;
