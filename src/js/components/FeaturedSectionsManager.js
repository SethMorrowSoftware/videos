/**
 * FeaturedSectionsManager Component
 * Manages multiple featured content sections on the homepage
 */

import { escapeHtml, extractValue, formatRuntime, getThumbnailUrl } from '../utils/helpers.js';
import { ICONS } from '../utils/icons.js';

export class FeaturedSectionsManager {
  constructor(app) {
    this.app = app;
    this.config = this.loadConfig();
    this.sections = [];
    this.container = document.getElementById('featuredSectionsContainer');
  }

  loadConfig() {
    const configEl = document.getElementById('featuredSectionsConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        console.warn('Failed to parse featured sections config:', e);
      }
    }
    return { sections: [] };
  }

  async init() {
    if (!this.config.sections || this.config.sections.length === 0) {
      return;
    }

    // Filter enabled sections
    const enabledSections = this.config.sections.filter(s => s.enabled !== false);
    if (enabledSections.length === 0) {
      return;
    }

    // Load videos for each section
    for (const section of enabledSections) {
      if (section.videos && section.videos.length > 0) {
        const videos = await this.loadVideosForSection(section);
        if (videos.length > 0) {
          this.sections.push({
            ...section,
            loadedVideos: videos
          });
        }
      }
    }

    this.render();
  }

  async loadVideosForSection(section) {
    const videoPromises = section.videos.map(async (item) => {
      const fallbackVideo = {
        identifier: item.id,
        title: item.title || 'Untitled',
        creator: item.creator || 'Unknown',
        adminNote: item.note
      };

      try {
        const response = await fetch(`https://archive.org/metadata/${item.id}`);
        if (!response.ok) return fallbackVideo;

        const data = await response.json();
        if (!data.metadata) return fallbackVideo;

        return {
          ...data.metadata,
          identifier: item.id,
          adminNote: item.note
        };
      } catch (e) {
        console.warn(`Failed to load video: ${item.id}`, e);
        return fallbackVideo;
      }
    });

    const results = await Promise.all(videoPromises);
    return results.filter(v => v && v.identifier);
  }

  render() {
    if (!this.container || this.sections.length === 0) {
      return;
    }

    // Create HTML for all sections
    const sectionsHTML = this.sections.map(section => this.createSection(section)).join('');
    this.container.innerHTML = sectionsHTML;

    // Add scroll buttons and event listeners for each section
    this.sections.forEach((section, index) => {
      const sectionEl = this.container.querySelector(`[data-section-id="${section.id}"]`);
      if (sectionEl) {
        const grid = sectionEl.querySelector('.featured-section-grid');
        if (grid) {
          this.addScrollButtons(grid, sectionEl);
          this.attachEventListeners(grid, section);
        }
      }
    });
  }

  createSection(section) {
    const hideBtn = this.isHidden(section.id) ? 'Show' : 'Hide';

    return `
      <section class="featured-section" data-section-id="${section.id}" ${this.isHidden(section.id) ? 'style="display: none;"' : ''}>
        <div class="featured-section-header">
          <div>
            <h2 class="featured-section-title">
              ${escapeHtml(section.title)}
            </h2>
            ${section.description ? `<p class="featured-section-description">${escapeHtml(section.description)}</p>` : ''}
          </div>
          <button class="btn btn-ghost" onclick="toggleFeaturedSection('${section.id}')" aria-label="${hideBtn} section">
            ${hideBtn}
          </button>
        </div>
        <div class="featured-section-scroll-container">
          <div class="featured-section-grid">
            ${section.loadedVideos.map(video => this.createCard(video)).join('')}
          </div>
        </div>
      </section>
    `;
  }

  createCard(video) {
    const title = extractValue(video.title) || 'Untitled';
    const creator = extractValue(video.creator) || 'Unknown';
    const thumbUrl = getThumbnailUrl(video.identifier);
    const runtime = formatRuntime(video.runtime);

    return `
      <article class="featured-card" data-identifier="${video.identifier}">
        <div class="featured-card-thumb">
          <img src="${thumbUrl}"
               alt="${escapeHtml(title)}"
               loading="lazy"
               onerror="this.style.display='none'; this.parentNode.innerHTML='<div class=thumb-placeholder>🎬</div>'"/>
          ${runtime ? `<span class="runtime-badge">${runtime}</span>` : ''}
          <div class="featured-card-overlay">
            <span class="play-btn">${ICONS.play}</span>
          </div>
        </div>
        <div class="featured-card-content">
          <h3 class="featured-card-title">${escapeHtml(title)}</h3>
          <p class="featured-card-creator">${escapeHtml(creator)}</p>
          ${video.adminNote ? `<span class="featured-card-note">${ICONS.star} ${escapeHtml(video.adminNote)}</span>` : ''}
        </div>
      </article>
    `;
  }

  addScrollButtons(grid, container) {
    if (container.querySelector('.scroll-btn')) return;

    container.querySelector('.featured-section-scroll-container').classList.add('has-scroll-buttons');

    const leftBtn = document.createElement('button');
    leftBtn.className = 'scroll-btn scroll-btn-left';
    leftBtn.innerHTML = ICONS.chevronLeft;
    leftBtn.setAttribute('aria-label', 'Scroll left');

    const rightBtn = document.createElement('button');
    rightBtn.className = 'scroll-btn scroll-btn-right';
    rightBtn.innerHTML = ICONS.chevronRight;
    rightBtn.setAttribute('aria-label', 'Scroll right');

    container.querySelector('.featured-section-scroll-container').appendChild(leftBtn);
    container.querySelector('.featured-section-scroll-container').appendChild(rightBtn);

    const updateButtonStates = () => {
      const scrollLeft = grid.scrollLeft;
      const scrollWidth = grid.scrollWidth;
      const clientWidth = grid.clientWidth;

      leftBtn.classList.toggle('disabled', scrollLeft <= 0);
      rightBtn.classList.toggle('disabled', scrollLeft >= scrollWidth - clientWidth - 10);
    };

    leftBtn.addEventListener('click', () => {
      grid.scrollBy({ left: -400, behavior: 'smooth' });
    });

    rightBtn.addEventListener('click', () => {
      grid.scrollBy({ left: 400, behavior: 'smooth' });
    });

    grid.addEventListener('scroll', updateButtonStates);
    updateButtonStates();
  }

  attachEventListeners(grid, section) {
    grid.querySelectorAll('.featured-card').forEach(card => {
      card.addEventListener('click', async (e) => {
        if (e.target.closest('a')) return;

        const id = card.dataset.identifier;
        const title = card.querySelector('.featured-card-title').textContent;
        const creator = card.querySelector('.featured-card-creator')?.textContent || 'Unknown';

        await this.app.playVideo(id, title, creator);
      });
    });
  }

  isHidden(sectionId) {
    try {
      const hidden = localStorage.getItem(`hideFeaturedSection_${sectionId}`);
      return hidden === 'true';
    } catch (e) {
      return false;
    }
  }

  toggleSection(sectionId) {
    const section = this.container.querySelector(`[data-section-id="${sectionId}"]`);
    if (!section) return;

    const isCurrentlyHidden = section.style.display === 'none';

    if (isCurrentlyHidden) {
      section.style.display = '';
      try {
        localStorage.removeItem(`hideFeaturedSection_${sectionId}`);
      } catch (e) {}
    } else {
      section.style.display = 'none';
      try {
        localStorage.setItem(`hideFeaturedSection_${sectionId}`, 'true');
      } catch (e) {}
    }
  }
}

// Make toggle function available globally
window.toggleFeaturedSection = function(sectionId) {
  if (window.featuredSectionsManager) {
    window.featuredSectionsManager.toggleSection(sectionId);
  }
};

export default FeaturedSectionsManager;
