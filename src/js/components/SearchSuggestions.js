/**
 * SearchSuggestions Component
 * Provides autocomplete suggestions from search history
 */

import { CONFIG } from '../config.js';
import { safeParseJSON, escapeHtml } from '../utils/helpers.js';
import { ICONS } from '../utils/icons.js';

export class SearchSuggestions {
  constructor(searchInput, onSelect) {
    this.input = searchInput;
    this.onSelect = onSelect;
    this.suggestions = [];
    this.selectedIndex = -1;
    this.history = safeParseJSON(localStorage.getItem('searchHistory')) || [];
    this.createDropdown();
  }

  createDropdown() {
    this.dropdown = document.createElement('div');
    this.dropdown.className = 'search-suggestions';
    this.dropdown.style.display = 'none';
    this.input.parentNode.appendChild(this.dropdown);

    this.input.addEventListener('input', () => this.handleInput());
    this.input.addEventListener('focus', () => this.show());
    this.input.addEventListener('blur', () => setTimeout(() => this.hide(), 200));
    this.input.addEventListener('keydown', (e) => this.handleKeydown(e));
  }

  handleInput() {
    const value = this.input.value.trim();
    if (value.length < 2) {
      this.showHistory();
      return;
    }

    const filtered = this.history.filter(term =>
      term.toLowerCase().includes(value.toLowerCase())
    ).slice(0, 5);

    this.updateSuggestions(filtered);
  }

  showHistory() {
    if (this.history.length > 0) {
      this.updateSuggestions(this.history.slice(0, 5), true);
    }
  }

  updateSuggestions(suggestions, isHistory = false) {
    this.suggestions = suggestions;

    if (suggestions.length === 0) {
      this.hide();
      return;
    }

    this.dropdown.innerHTML = `
      ${isHistory ? '<div class="suggestions-header">Recent Searches</div>' : ''}
      ${suggestions.map((s, i) =>
        `<div class="suggestion-item" data-index="${i}">
          <span class="suggestion-icon">${ICONS.search}</span>
          <span class="suggestion-text">${escapeHtml(s)}</span>
        </div>`
      ).join('')}
    `;

    this.dropdown.style.display = 'block';

    this.dropdown.querySelectorAll('.suggestion-item').forEach(item => {
      item.addEventListener('click', () => {
        const idx = parseInt(item.dataset.index, 10);
        if (!isNaN(idx)) this.selectSuggestion(idx);
      });
    });
  }

  handleKeydown(e) {
    if (!this.suggestions.length) return;

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.selectedIndex = Math.min(this.selectedIndex + 1, this.suggestions.length - 1);
        this.updateSelection();
        break;
      case 'ArrowUp':
        e.preventDefault();
        this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
        this.updateSelection();
        break;
      case 'Enter':
        if (this.selectedIndex >= 0) {
          e.preventDefault();
          this.selectSuggestion(this.selectedIndex);
        }
        break;
      case 'Escape':
        this.hide();
        break;
    }
  }

  updateSelection() {
    this.dropdown.querySelectorAll('.suggestion-item').forEach((item, i) => {
      item.classList.toggle('selected', i === this.selectedIndex);
    });
  }

  selectSuggestion(index) {
    const suggestion = this.suggestions[index];
    if (suggestion) {
      this.input.value = suggestion;
      this.onSelect(suggestion);
      this.hide();
    }
  }

  addToHistory(term) {
    if (!term || term.length < 2) return;

    this.history = this.history.filter(h => h !== term);
    this.history.unshift(term);
    this.history = this.history.slice(0, CONFIG.MAX_SEARCH_HISTORY);

    try {
      localStorage.setItem('searchHistory', JSON.stringify(this.history));
    } catch (e) {
      console.warn('Failed to save search history:', e);
    }
  }

  hide() {
    this.dropdown.style.display = 'none';
    this.selectedIndex = -1;
  }

  show() {
    if (this.input.value.length < 2) {
      this.showHistory();
    } else {
      this.handleInput();
    }
  }

  clearHistory() {
    this.history = [];
    try {
      localStorage.removeItem('searchHistory');
    } catch (e) {
      console.warn('Failed to clear history:', e);
    }
  }
}

export default SearchSuggestions;
