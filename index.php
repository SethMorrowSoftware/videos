<?php
/**
 * Archive Film Club - PHP Backend for Social Media Meta Tags
 * Fetches video metadata and injects Open Graph tags for sharing
 */

// Load site settings
$settings_file = __DIR__ . '/site-settings.json';
$site_settings = [
    'siteName' => 'Archive Film Club',
    'tagline' => 'Discover classic films from Archive.org',
    'brandColor' => '#ff0000',
    'accentColor' => '#065fd4',
    'defaultTheme' => 'dark',
    'enableThemeToggle' => true,
    'cardStyle' => 'modern',
    'showDownloadCount' => true,
    'showCreator' => true,
    'showDate' => true,
    'enableBookmarks' => false,
    'enableWatchHistory' => true,
    'defaultCollection' => 'all_videos',
    'defaultSort' => 'downloads'
];
if (file_exists($settings_file)) {
    $content = file_get_contents($settings_file);
    $data = json_decode($content, true);
    if ($data) {
        $site_settings = array_merge($site_settings, $data);
    }
}

// Initialize default values
$ogTitle = $site_settings['siteName'];
$ogDescription = $site_settings['tagline'];
$ogImage = null;
$ogUrl = null;
$ogType = 'website';
$pageTitle = $site_settings['siteName'];
$useVideoThumbnail = false;

// Only try to fetch video thumbnail if we have a specific video parameter
// (not for homepage, search results, or collection browsing)
if (isset($_GET['video']) && !empty($_GET['video'])) {
    // Sanitize the video ID
    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['video']);

    if (!empty($videoId)) {
        // Fetch metadata from Archive.org
        $metadataUrl = "https://archive.org/metadata/{$videoId}";

        // Configure request with timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
                'ignore_errors' => true
            ]
        ]);

        // Attempt to fetch metadata
        $response = @file_get_contents($metadataUrl, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);

            if ($data && isset($data['metadata'])) {
                $metadata = $data['metadata'];

                // Extract title
                if (isset($metadata['title'])) {
                    $title = is_array($metadata['title']) ? $metadata['title'][0] : $metadata['title'];
                    $ogTitle = $title;
                    $pageTitle = $title . " - " . $site_settings['siteName'];
                }

                // Extract description
                if (isset($metadata['description'])) {
                    $desc = is_array($metadata['description']) ? $metadata['description'][0] : $metadata['description'];
                    // Strip HTML tags and limit length
                    $desc = strip_tags($desc);
                    $ogDescription = strlen($desc) > 200 ? substr($desc, 0, 197) . '...' : $desc;
                }

                // Extract creator for richer description
                if (isset($metadata['creator'])) {
                    $creator = is_array($metadata['creator']) ? $metadata['creator'][0] : $metadata['creator'];
                    if (!empty($creator)) {
                        $ogDescription = "By " . $creator . " - " . $ogDescription;
                    }
                }

                // Set thumbnail image
                $ogImage = "https://archive.org/services/img/{$videoId}";

                // Mark that we found a video and should use its thumbnail
                $useVideoThumbnail = true;

                // Set type to video
                $ogType = 'video.other';
            }
        }
    }
}

// Set canonical URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$ogUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Determine which image to use
if ($useVideoThumbnail && $ogImage) {
    // We have a specific video - use its thumbnail from Archive.org
} else {
    // Homepage, search results, or failed video fetch - use local default
    $ogImage = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/og-default.png";
    $ogImage = str_replace('//', '/', $ogImage);
    $ogImage = str_replace(':/', '://', $ogImage);
}

// Helper function to safely output HTML attributes
function escapeAttr($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Calculate darker brand color
function darkenColor($hex, $percent = 20) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $r = max(0, $r - ($r * $percent / 100));
    $g = max(0, $g - ($g * $percent / 100));
    $b = max(0, $b - ($b * $percent / 100));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

$brandColorDark = darkenColor($site_settings['brandColor']);
$accentColorDark = darkenColor($site_settings['accentColor']);
$initialTheme = $site_settings['defaultTheme'] === 'system' ? 'dark' : $site_settings['defaultTheme'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= escapeAttr($initialTheme) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title><?= escapeAttr($pageTitle) ?></title>
  <meta name="description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta name="theme-color" content="<?= escapeAttr($site_settings['brandColor']) ?>" />

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="<?= escapeAttr($ogType) ?>" />
  <meta property="og:url" content="<?= escapeAttr($ogUrl) ?>" />
  <meta property="og:title" content="<?= escapeAttr($ogTitle) ?>" />
  <meta property="og:description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta property="og:image" content="<?= escapeAttr($ogImage) ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:site_name" content="<?= escapeAttr($site_settings['siteName']) ?>" />

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:url" content="<?= escapeAttr($ogUrl) ?>" />
  <meta name="twitter:title" content="<?= escapeAttr($ogTitle) ?>" />
  <meta name="twitter:description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta name="twitter:image" content="<?= escapeAttr($ogImage) ?>" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://archive.org">

  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQgOEw0IDE2QzQgMTcuMTA0NiA0Ljg5NTQzIDE4IDYgMThMMTggMThDMTkuMTA0NiAxOCAyMCAxNy4xMDQ2IDIwIDE2VjhDMjAgNi44OTU0MyAxOS4xMDQ2IDYgMTggNkw2IDZDNC44OTU0MyA2IDQgNi44OTU0MyA0IDhaIiBzdHJva2U9IiNmZjAwMDAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+CjxwYXRoIGQ9Ik0xMCAxMkwxNCAxMk0xMiAxMEwxMiAxNCIgc3Ryb2tlPSIjZmYwMDAwIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgo8L3N2Zz4K" />

  <link rel="stylesheet" href="styles.css">

  <!-- Custom brand colors from admin settings -->
  <style>
    :root {
      --brand-color: <?= escapeAttr($site_settings['brandColor']) ?>;
      --brand-color-dark: <?= escapeAttr($brandColorDark) ?>;
      --accent-color: <?= escapeAttr($site_settings['accentColor']) ?>;
      --accent-color-dark: <?= escapeAttr($accentColorDark) ?>;
    }
  </style>

  <!-- Theme initialization (before body renders to prevent flash) -->
  <script>
    (function() {
      var savedTheme = localStorage.getItem('theme');
      var defaultTheme = '<?= escapeAttr($site_settings['defaultTheme']) ?>';
      var theme = savedTheme || defaultTheme;

      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }

      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body data-card-style="<?= escapeAttr($site_settings['cardStyle']) ?>">
  <header class="site-header">
    <div class="header-content">
      <button class="mobile-menu-btn" aria-label="Open menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <!-- Clickable Logo - Goes Home -->
      <a href="index.php" class="logo-section" title="Go to homepage">
        <div class="logo-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="logo-text"><?= escapeAttr($site_settings['siteName']) ?></span>
      </a>

      <!-- Centered Search Bar -->
      <form id="searchForm" class="header-search-form" role="search" aria-label="Search videos">
        <div class="header-search-input-wrapper">
          <input
            id="searchInput"
            type="search"
            class="header-search-input"
            placeholder="Search"
            autocomplete="off"
            aria-label="Search videos"
          />
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

      <!-- Right side with theme toggle -->
      <div class="header-end">
        <?php if ($site_settings['enableThemeToggle']): ?>
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
        <?php endif; ?>
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
      <!-- Recommended Section (Admin Picks) -->
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

  <!-- Site Settings Configuration -->
  <script id="siteSettingsConfig" type="application/json"><?= json_encode($site_settings) ?></script>

  <!-- Admin Recommended Videos Configuration -->
  <!-- Loaded from recommendations.json (managed via admin.php) -->
  <?php
  $recommendations_file = __DIR__ . '/recommendations.json';
  $recommendations_config = '{"enabled":false,"title":"Staff Picks","videos":[]}';

  if (file_exists($recommendations_file)) {
      $content = file_get_contents($recommendations_file);
      if ($content) {
          $recommendations_config = $content;
      }
  }
  ?>
  <script id="recommendedConfig" type="application/json"><?= $recommendations_config ?></script>

  <!-- Theme Toggle Script -->
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

      // Listen for system theme changes
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
</html>
