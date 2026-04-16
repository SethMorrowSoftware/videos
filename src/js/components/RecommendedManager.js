/**
 * RecommendedManager Component
 * Manages the Staff Picks / Recommended videos section
 */

import { escapeHtml, extractValue, formatRuntime, getThumbnailUrl } from '../utils/helpers.js';
import { ICONS } from '../utils/icons.js';

export class RecommendedManager {
  constructor(app) {
    this.app = app;
    this.config = this.loadConfig();
    this.videos = [];
    this.section = document.getElementById('recommendedSection');
    this.grid = document.getElementById('recommendedGrid');
    this.hideBtn = document.getElementById('hideRecommended');

    const hiddenVal = localStorage.getItem('hideRecommended');
    this.isHidden = hiddenVal === 'true';

    this.setupEventListeners();
  }

  loadConfig() {
    const configEl = document.getElementById('recommendedConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        console.warn('Failed to parse recommended config:', e);
      }
    }
    return { enabled: false, videos: [] };
  }

  setupEventListeners() {
    if (this.hideBtn) {
      this.hideBtn.addEventListener('click', () => this.hide());
    }
  }

  async init() {
    if (!this.config.enabled || !this.config.videos?.length || this.isHidden) {
      return;
    }

    await this.loadVideos();
    this.render();
  }

  async loadVideos() {
    const videoPromises = this.config.videos.map(async (item) => {
      try {
        const response = await fetch(`https://archive.org/metadata/${item.id}`);
        if (!response.ok) return null;

        const data = await response.json();
        if (!data.metadata) return null;

        return {
          ...data.metadata,
          identifier: item.id,
          adminNote: item.note
        };
      } catch (e) {
        console.warn(`Failed to load recommended video: ${item.id}`, e);
        return null;
      }
    });

    const results = await Promise.all(videoPromises);
    this.videos = results.filter(v => v !== null);
  }

  render() {
    if (!this.section || !this.grid || this.videos.length === 0) {
      return;
    }

    this.section.style.display = 'block';

    // Create scroll container with navigation buttons
    this.grid.innerHTML = this.videos.map(video => this.createCard(video)).join('');

    // Add scroll buttons
    this.addScrollButtons();

    // Attach event listeners - navigate to dedicated player page
    this.grid.querySelectorAll('.recommended-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;

        const id = card.dataset.identifier;
        this.app.navigateToPlayer(id);
      });
    });
  }

  addScrollButtons() {
    const container = this.grid.parentElement;
    if (!container || container.querySelector('.scroll-btn')) return;

    container.classList.add('recommended-scroll-container');

    const leftBtn = document.createElement('button');
    leftBtn.className = 'scroll-btn scroll-btn-left';
    leftBtn.innerHTML = ICONS.chevronLeft;
    leftBtn.setAttribute('aria-label', 'Scroll left');

    const rightBtn = document.createElement('button');
    rightBtn.className = 'scroll-btn scroll-btn-right';
    rightBtn.innerHTML = ICONS.chevronRight;
    rightBtn.setAttribute('aria-label', 'Scroll right');

    container.appendChild(leftBtn);
    container.appendChild(rightBtn);

    const updateButtonStates = () => {
      const scrollLeft = this.grid.scrollLeft;
      const scrollWidth = this.grid.scrollWidth;
      const clientWidth = this.grid.clientWidth;

      leftBtn.classList.toggle('disabled', scrollLeft <= 0);
      rightBtn.classList.toggle('disabled', scrollLeft >= scrollWidth - clientWidth - 10);
    };

    leftBtn.addEventListener('click', () => {
      this.grid.scrollBy({ left: -400, behavior: 'smooth' });
    });

    rightBtn.addEventListener('click', () => {
      this.grid.scrollBy({ left: 400, behavior: 'smooth' });
    });

    this.grid.addEventListener('scroll', updateButtonStates);
    updateButtonStates();
  }

  createCard(video) {
    const title = extractValue(video.title) || 'Untitled';
    const creator = extractValue(video.creator) || 'Unknown';
    const thumbUrl = getThumbnailUrl(video.identifier);
    const runtime = formatRuntime(video.runtime);

    return `
      <article class="recommended-card" data-identifier="${video.identifier}">
        <div class="recommended-card-thumb">
          <img src="${thumbUrl}"
               alt="${escapeHtml(title)}"
               loading="eager"
               onerror="this.style.display='none'; this.parentNode.innerHTML='<div class=thumb-placeholder>🎬</div>'"/>
          ${runtime ? `<span class="runtime-badge">${runtime}</span>` : ''}
          <div class="recommended-card-overlay">
            <span class="play-btn">${ICONS.play}</span>
          </div>
        </div>
        <div class="recommended-card-content">
          <h3 class="recommended-card-title">${escapeHtml(title)}</h3>
          <p class="recommended-card-creator">${escapeHtml(creator)}</p>
          ${video.adminNote ? `<span class="recommended-card-note">${ICONS.star} ${escapeHtml(video.adminNote)}</span>` : ''}
        </div>
      </article>
    `;
  }

  hide() {
    if (this.section) {
      this.section.style.display = 'none';
    }
    this.isHidden = true;
    try {
      localStorage.setItem('hideRecommended', 'true');
    } catch (e) {
      console.warn('Failed to save preference:', e);
    }
  }

  show() {
    this.isHidden = false;
    try {
      localStorage.removeItem('hideRecommended');
    } catch (e) {
      console.warn('Failed to save preference:', e);
    }
    this.init();
  }
}

export default RecommendedManager;
