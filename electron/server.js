const express = require('express');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');

const APP_ROOT = path.join(__dirname, '..');

// Simple JSON file store for local data
class LocalStore {
  constructor() {
    this.dataDir = path.join(APP_ROOT, 'electron', 'data');
    if (!fs.existsSync(this.dataDir)) {
      fs.mkdirSync(this.dataDir, { recursive: true });
    }
  }

  _filePath(name) {
    return path.join(this.dataDir, `${name}.json`);
  }

  read(name, defaultValue = null) {
    try {
      const data = fs.readFileSync(this._filePath(name), 'utf-8');
      return JSON.parse(data);
    } catch {
      return defaultValue;
    }
  }

  write(name, data) {
    fs.writeFileSync(this._filePath(name), JSON.stringify(data, null, 2));
  }
}

function fetchUrl(url) {
  return new Promise((resolve, reject) => {
    const mod = url.startsWith('https') ? https : http;
    const req = mod.get(url, { headers: { 'User-Agent': 'ArchiveFilmClub/1.0 Electron' } }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        fetchUrl(res.headers.location).then(resolve).catch(reject);
        return;
      }
      const chunks = [];
      res.on('data', chunk => chunks.push(chunk));
      res.on('end', () => {
        const body = Buffer.concat(chunks);
        resolve({ statusCode: res.statusCode, headers: res.headers, body });
      });
    });
    req.on('error', reject);
    req.setTimeout(15000, () => { req.destroy(); reject(new Error('Request timeout')); });
  });
}

function startServer() {
  return new Promise((resolve) => {
    const app = express();
    const store = new LocalStore();

    app.use(express.json());

    // Load site settings
    const defaultSettings = {
      siteName: 'Archive Film Club',
      tagline: 'Discover classic films from Archive.org',
      brandColor: '#ff0000',
      accentColor: '#065fd4',
      defaultTheme: 'dark',
      enableThemeToggle: true,
      cardStyle: 'modern',
      showDownloadCount: true,
      showCreator: true,
      showDate: true,
      enableBookmarks: true,
      enableWatchHistory: true,
      defaultCollection: 'all_videos',
      defaultSort: 'downloads'
    };

    // Serve index.html for root
    app.get('/', (req, res) => {
      const settings = { ...defaultSettings, ...store.read('settings', {}) };
      const recommendations = store.read('recommendations') || loadJsonFallback('recommendations.json', { enabled: true, title: 'Staff Picks', videos: [] });
      const featuredSections = store.read('featured-sections') || loadJsonFallback('featured-sections.json', { sections: [] });

      const brandColor = settings.brandColor || '#ff0000';
      const accentColor = settings.accentColor || '#065fd4';
      const brandColorDark = darkenColor(brandColor);
      const accentColorDark = darkenColor(accentColor);
      const initialTheme = settings.defaultTheme === 'system' ? 'dark' : (settings.defaultTheme || 'dark');

      const html = buildIndexHtml(settings, recommendations, featuredSections, {
        brandColor, accentColor, brandColorDark, accentColorDark, initialTheme
      });
      res.type('html').send(html);
    });

    // --- API ENDPOINTS ---

    // Search proxy to Archive.org
    app.get('/api/search.php', async (req, res) => {
      try {
        const params = new URLSearchParams({
          q: req.query.q || '*',
          output: 'json',
          rows: req.query.rows || '24',
          page: req.query.page || '1'
        });

        // Add fields
        ['identifier', 'title', 'description', 'date', 'downloads', 'creator', 'runtime', 'licenseurl', 'subject', 'mediatype', 'num_items']
          .forEach(f => params.append('fl[]', f));

        // Add sorting
        const sort = req.query.sort;
        if (sort && sort !== 'relevance') {
          switch (sort) {
            case 'date': params.append('sort[]', 'publicdate desc'); break;
            case 'downloads': params.append('sort[]', 'downloads desc'); break;
            case 'title': params.append('sort[]', 'titleSorter asc'); break;
            case 'creator': params.append('sort[]', 'creatorSorter asc'); break;
          }
        }

        const url = `https://archive.org/advancedsearch.php?${params}`;
        const result = await fetchUrl(url);
        const data = JSON.parse(result.body.toString());

        res.json({
          success: true,
          cached: false,
          data: data
        });
      } catch (err) {
        res.status(500).json({ success: false, error: err.message });
      }
    });

    // Metadata proxy
    app.get('/api/metadata.php', async (req, res) => {
      try {
        const id = req.query.id;
        if (!id) return res.status(400).json({ error: 'Missing id' });

        const result = await fetchUrl(`https://archive.org/metadata/${encodeURIComponent(id)}`);
        const data = JSON.parse(result.body.toString());
        res.json(data);
      } catch (err) {
        res.status(500).json({ error: err.message });
      }
    });

    // Thumbnail proxy
    app.get('/api/thumbnail.php', async (req, res) => {
      try {
        const id = req.query.id;
        if (!id) return res.status(400).json({ error: 'Missing id' });

        const result = await fetchUrl(`https://archive.org/services/img/${encodeURIComponent(id)}`);
        const contentType = result.headers['content-type'] || 'image/jpeg';
        res.set('Content-Type', contentType);
        res.set('Cache-Control', 'public, max-age=86400');
        res.send(result.body);
      } catch (err) {
        res.redirect(`https://archive.org/services/img/${encodeURIComponent(req.query.id || '')}`);
      }
    });

    // Settings
    app.get('/api/settings.php', (req, res) => {
      const settings = { ...defaultSettings, ...store.read('settings', {}) };
      res.json({ success: true, settings });
    });

    app.post('/api/settings.php', (req, res) => {
      const current = store.read('settings', {});
      store.write('settings', { ...current, ...req.body });
      res.json({ success: true });
    });

    // Recommendations
    app.get('/api/recommendations.php', (req, res) => {
      const data = store.read('recommendations') || loadJsonFallback('recommendations.json', { enabled: true, title: 'Staff Picks', videos: [] });
      res.json(data);
    });

    // Sections
    app.get('/api/sections.php', (req, res) => {
      const data = store.read('featured-sections') || loadJsonFallback('featured-sections.json', { sections: [] });
      res.json(data);
    });

    // Bookmarks (localStorage-backed on frontend, but provide API)
    app.get('/api/bookmarks.php', (req, res) => {
      res.json({ success: true, bookmarks: store.read('bookmarks', []) });
    });

    app.post('/api/bookmarks.php', (req, res) => {
      const { action } = req.body;
      let bookmarks = store.read('bookmarks', []);

      if (action === 'add') {
        const exists = bookmarks.find(b => b.id === req.body.id);
        if (!exists) {
          bookmarks.push({ id: req.body.id, title: req.body.title, creator: req.body.creator, thumbnail: req.body.thumbnail, added: new Date().toISOString() });
          store.write('bookmarks', bookmarks);
        }
        res.json({ success: true });
      } else if (action === 'remove') {
        bookmarks = bookmarks.filter(b => b.id !== req.body.id);
        store.write('bookmarks', bookmarks);
        res.json({ success: true });
      } else if (action === 'sync') {
        store.write('bookmarks', req.body.bookmarks || []);
        res.json({ success: true });
      } else {
        res.json({ success: true, bookmarks });
      }
    });

    // History
    app.get('/api/history.php', (req, res) => {
      const history = store.read('history', []);
      if (req.query.action === 'progress' && req.query.id) {
        const entry = history.find(h => h.id === req.query.id);
        return res.json({ success: true, progress: entry || null });
      }
      const limit = parseInt(req.query.limit) || 50;
      res.json({ success: true, history: history.slice(0, limit) });
    });

    app.post('/api/history.php', (req, res) => {
      const { action } = req.body;
      if (action === 'clear') {
        store.write('history', []);
        return res.json({ success: true });
      }
      if (action === 'update') {
        let history = store.read('history', []);
        const idx = history.findIndex(h => h.id === req.body.id);
        const entry = { id: req.body.id, currentTime: req.body.currentTime, duration: req.body.duration, updated: new Date().toISOString() };
        if (idx >= 0) {
          history[idx] = entry;
        } else {
          history.unshift(entry);
        }
        // Keep last 200
        if (history.length > 200) history = history.slice(0, 200);
        store.write('history', history);
        return res.json({ success: true });
      }
      res.json({ success: true });
    });

    // User
    app.get('/api/user.php', (req, res) => {
      res.json({ success: true, preferences: store.read('user-prefs', {}) });
    });

    app.post('/api/user.php', (req, res) => {
      if (req.body.action === 'preferences') {
        store.write('user-prefs', req.body.preferences || {});
      }
      res.json({ success: true });
    });

    // Stats (stub)
    app.get('/api/stats.php', (req, res) => {
      res.json({ success: true, data: [] });
    });

    // API index / health
    app.get('/api/index.php', (req, res) => {
      res.json({ status: 'ok', version: '1.0.0' });
    });
    app.head('/api/index.php', (req, res) => {
      res.sendStatus(200);
    });

    // Serve static files (CSS, JS, images, etc.)
    app.use(express.static(APP_ROOT, {
      index: false, // We handle index ourselves
      extensions: ['html']
    }));

    const server = app.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      console.log(`Server running on http://127.0.0.1:${port}`);
      resolve(port);
    });
  });
}

function loadJsonFallback(filename, defaultValue) {
  try {
    const filePath = path.join(APP_ROOT, filename);
    if (fs.existsSync(filePath)) {
      return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
    }
  } catch {}
  return defaultValue;
}

function darkenColor(hex, percent = 20) {
  hex = hex.replace('#', '');
  if (hex.length !== 6) return '#' + hex;
  let r = parseInt(hex.substring(0, 2), 16);
  let g = parseInt(hex.substring(2, 4), 16);
  let b = parseInt(hex.substring(4, 6), 16);
  r = Math.max(0, Math.round(r - (r * percent / 100)));
  g = Math.max(0, Math.round(g - (g * percent / 100)));
  b = Math.max(0, Math.round(b - (b * percent / 100)));
  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

function esc(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function buildIndexHtml(settings, recommendations, featuredSections, colors) {
  const { brandColor, accentColor, brandColorDark, accentColorDark, initialTheme } = colors;
  const siteName = settings.siteName || 'Archive Film Club';

  return `<!DOCTYPE html>
<html lang="en" data-theme="${esc(initialTheme)}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>${esc(siteName)}</title>
  <meta name="description" content="${esc(settings.tagline)}" />
  <meta name="theme-color" content="${esc(brandColor)}" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://archive.org">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQgOEw0IDE2QzQgMTcuMTA0NiA0Ljg5NTQzIDE4IDYgMThMMTggMThDMTkuMTA0NiAxOCAyMCAxNy4xMDQ2IDIwIDE2VjhDMjAgNi44OTU0MyAxOS4xMDQ2IDYgMTggNkw2IDZDNC44OTU0MyA2IDQgNi44OTU0MyA0IDhaIiBzdHJva2U9IiNmZjAwMDAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+CjxwYXRoIGQ9Ik0xMCAxMkwxNCAxMk0xMiAxMEwxMiAxNCIgc3Ryb2tlPSIjZmYwMDAwIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgo8L3N2Zz4K" />

  <link rel="stylesheet" href="styles.css">

  <style>
    :root {
      --brand-color: ${esc(brandColor)};
      --brand-color-dark: ${esc(brandColorDark)};
      --accent-color: ${esc(accentColor)};
      --accent-color-dark: ${esc(accentColorDark)};
    }
  </style>

  <script>
    (function() {
      var savedTheme = localStorage.getItem('theme');
      var defaultTheme = '${esc(settings.defaultTheme || 'dark')}';
      var theme = savedTheme || defaultTheme;
      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body data-card-style="${esc(settings.cardStyle || 'modern')}">
  <header class="site-header">
    <div class="header-content">
      <button class="mobile-menu-btn" aria-label="Open menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <a href="/" class="logo-section" title="Go to homepage">
        <div class="logo-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="logo-text">${esc(siteName)}</span>
      </a>

      <form id="searchForm" class="header-search-form" role="search" aria-label="Search videos">
        <div class="header-search-input-wrapper">
          <input id="searchInput" type="search" class="header-search-input" placeholder="Search" autocomplete="off" aria-label="Search videos" />
          <button id="clearSearchBtn" class="clear-search-btn" type="button" style="display: none;" aria-label="Clear search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
        <button type="submit" class="search-submit-btn" aria-label="Search">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M21 21L15 15M17 10C17 13.866 13.866 17 10 17C6.13401 17 3 13.866 3 10C3 6.13401 6.13401 3 10 3C13.866 3 17 6.13401 17 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </form>

      <div class="header-end">
        ${settings.enableThemeToggle ? `
        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle light/dark mode">
          <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"></circle>
            <line x1="12" y1="1" x2="12" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="23"></line>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
            <line x1="1" y1="12" x2="3" y2="12"></line>
            <line x1="21" y1="12" x2="23" y2="12"></line>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
          </svg>
          <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        ` : ''}
      </div>
    </div>
  </header>

  <div class="mobile-overlay"></div>

  <main class="main-layout">
    <aside class="sidebar" aria-label="Filter videos">
      <button class="mobile-close-btn" aria-label="Close menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <section class="filter-section">
        <h2 class="filter-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 7H21M6 12H18M9 17H15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Collections
        </h2>
        <div class="filter-field">
          <label for="collection">Select Collection</label>
          <div class="select-wrapper">
            <select id="collection" class="filter-select"></select>
            <svg class="select-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>
      </section>

      <section class="filter-section">
        <h2 class="filter-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 6H21M6 12H18M11 18H13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Sort & Filters
        </h2>

        <div class="filter-field">
          <label for="sortBy">Sort By</label>
          <div class="select-wrapper">
            <select id="sortBy" class="filter-select">
              <option value="relevance">Relevance</option>
              <option value="date">Date (Newest First)</option>
              <option value="downloads" selected>Most Downloaded</option>
              <option value="title">Title (A-Z)</option>
              <option value="creator">Creator (A-Z)</option>
            </select>
            <svg class="select-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>

        <div class="checkbox-group">
          <input id="publicDomain" type="checkbox" class="checkbox-input" />
          <label for="publicDomain" class="checkbox-label">Public Domain Only</label>
        </div>

        <div class="checkbox-group">
          <input id="collectionsOnly" type="checkbox" class="checkbox-input" />
          <label for="collectionsOnly" class="checkbox-label">Collections Only</label>
        </div>
      </section>

      <button id="clearFilters" class="btn btn-secondary btn-full" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Reset All Filters
      </button>

      <div id="searchStats" class="stats">Ready to search</div>
    </aside>

    <section class="content-area">
      <section id="recommendedSection" class="recommended-section" style="display: none;">
        <div class="recommended-header">
          <h2 class="recommended-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
              <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
            </svg>
            Staff Picks
          </h2>
          <button id="hideRecommended" class="btn btn-ghost" aria-label="Hide recommendations">Hide</button>
        </div>
        <div id="recommendedGrid" class="recommended-grid"></div>
      </section>

      <div id="featuredSectionsContainer"></div>

      <div id="playerContainer" class="player" aria-hidden="true">
        <div class="player-controls">
          <button id="playPauseBtn" class="play-pause-btn" aria-label="Play/Pause">
            <svg class="play-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M5 3L19 12L5 21V3Z" fill="currentColor"/>
            </svg>
            <svg class="pause-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:none;">
              <path d="M6 4H10V20H6V4ZM14 4H18V20H14V4Z" fill="currentColor"/>
            </svg>
          </button>
          <div class="player-info">
            <h2 id="playerTitle">No video selected</h2>
            <p id="playerMeta">Select a video to start playing</p>
          </div>
        </div>
        <div class="video-wrapper">
          <div class="player-loader" style="display: none;">
            <div class="loading-spinner">
              <div class="spinner-ring"></div>
            </div>
          </div>
        </div>
      </div>

      <div id="playerInfo" class="player-info-container"></div>

      <div id="loading" class="loading" hidden>
        <div class="loading-spinner">
          <div class="spinner-ring"></div>
        </div>
        <span class="loading-text">Searching archive...</span>
      </div>

      <div id="error" class="error" role="alert" hidden></div>

      <div id="results" class="results-grid"></div>

      <nav id="pagination" class="pagination" aria-label="Page navigation"></nav>
    </section>
  </main>

  <script id="siteSettingsConfig" type="application/json">${JSON.stringify(settings)}</script>
  <script id="recommendedConfig" type="application/json">${JSON.stringify(recommendations)}</script>
  <script id="featuredSectionsConfig" type="application/json">${JSON.stringify(featuredSections)}</script>

  <script>
    (function() {
      var themeToggle = document.getElementById('themeToggle');
      if (!themeToggle) return;

      function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
      }

      function toggleTheme() {
        var currentTheme = document.documentElement.getAttribute('data-theme');
        var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
      }

      themeToggle.addEventListener('click', toggleTheme);

      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        var savedTheme = localStorage.getItem('theme');
        if (!savedTheme || savedTheme === 'system') {
          setTheme(e.matches ? 'dark' : 'light');
        }
      });
    })();
  </script>

  <script type="module" src="app.js"></script>
</body>
</html>`;
}

module.exports = { startServer };
