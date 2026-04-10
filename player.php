<?php
/**
 * Archive Film Club - Dedicated Video Player Page
 * Immersive cinema-like player with rich playlist support
 */

// Default settings
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
    'enableBookmarks' => true,
    'enableWatchHistory' => true,
    'defaultCollection' => 'all_videos',
    'defaultSort' => 'downloads'
];

$useDatabase = false;

try {
    if (file_exists(__DIR__ . '/services/SettingsService.php')) {
        require_once __DIR__ . '/services/SettingsService.php';
        $settingsService = new SettingsService();
        $dbSettings = $settingsService->getSettings();
        if (!empty($dbSettings)) {
            $site_settings = array_merge($site_settings, $dbSettings);
            $useDatabase = true;
        }
    }
} catch (Exception $e) {
    error_log("Database settings load failed: " . $e->getMessage());
}

if (!$useDatabase) {
    $settings_file = __DIR__ . '/site-settings.json';
    if (file_exists($settings_file)) {
        $content = file_get_contents($settings_file);
        $data = json_decode($content, true);
        if ($data) {
            $site_settings = array_merge($site_settings, $data);
        }
    }
}

// Initialize OG defaults
$ogTitle = $site_settings['siteName'];
$ogDescription = $site_settings['tagline'];
$ogImage = null;
$ogUrl = null;
$ogType = 'website';
$pageTitle = $site_settings['siteName'];
$useVideoThumbnail = false;

// Fetch video metadata for OG tags
if (isset($_GET['video']) && !empty($_GET['video'])) {
    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['video']);

    if (!empty($videoId)) {
        $metadataUrl = "https://archive.org/metadata/{$videoId}";
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($metadataUrl, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);
            if ($data && isset($data['metadata'])) {
                $metadata = $data['metadata'];

                if (isset($metadata['title'])) {
                    $title = is_array($metadata['title']) ? $metadata['title'][0] : $metadata['title'];
                    $ogTitle = $title;
                    $pageTitle = $title . " - " . $site_settings['siteName'];
                }

                if (isset($metadata['description'])) {
                    $desc = is_array($metadata['description']) ? $metadata['description'][0] : $metadata['description'];
                    $desc = strip_tags($desc);
                    $ogDescription = strlen($desc) > 200 ? substr($desc, 0, 197) . '...' : $desc;
                }

                if (isset($metadata['creator'])) {
                    $creator = is_array($metadata['creator']) ? $metadata['creator'][0] : $metadata['creator'];
                    if (!empty($creator)) {
                        $ogDescription = "By " . $creator . " - " . $ogDescription;
                    }
                }

                $ogImage = "https://archive.org/services/img/{$videoId}";
                $useVideoThumbnail = true;
                $ogType = 'video.other';
            }
        }
    }
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$ogUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if ($useVideoThumbnail && $ogImage) {
    // Use video thumbnail
} else {
    $ogImage = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/og-default.png";
    $ogImage = str_replace('//', '/', $ogImage);
    $ogImage = str_replace(':/', '://', $ogImage);
}

function escapeAttr($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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
  <link rel="stylesheet" href="player-styles.css">
  <link rel="stylesheet" href="auth-styles.css">

  <style>
    :root {
      --brand-color: <?= escapeAttr($site_settings['brandColor']) ?>;
      --brand-color-dark: <?= escapeAttr($brandColorDark) ?>;
      --accent-color: <?= escapeAttr($site_settings['accentColor']) ?>;
      --accent-color-dark: <?= escapeAttr($accentColorDark) ?>;
    }
  </style>

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
<body class="player-page">

  <!-- Player Header -->
  <header class="player-header" id="playerHeader">
    <div class="player-header-content">
      <a href="index.php" class="player-back-btn" title="Back to search" aria-label="Back to search">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="player-back-text">Back to search</span>
      </a>

      <a href="index.php" class="player-logo" title="Go to homepage">
        <div class="player-logo-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="player-logo-text"><?= escapeAttr($site_settings['siteName']) ?></span>
      </a>

      <div class="player-header-actions">
        <div class="header-auth" data-auth-nav></div>
        <button id="bookmarkBtn" class="player-action-btn" title="Bookmark" aria-label="Bookmark this video">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <button id="saveToCollectionBtn" class="player-action-btn" title="Save to collection" aria-label="Save to collection">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M4 6H20M4 12H14M4 18H14M18 15V21M15 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <?php if ($site_settings['enableThemeToggle']): ?>
        <button id="themeToggle" class="player-action-btn theme-toggle" aria-label="Toggle theme" title="Toggle light/dark mode">
          <svg class="sun-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
          <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Cinema Area -->
  <main class="player-main">
    <div class="player-cinema" id="playerCinema">
      <div id="videoWrapper" class="player-video-wrapper">
        <div id="playerLoader" class="player-loader" role="status" aria-busy="true">
          <div class="loading-spinner" aria-hidden="true">
            <div class="spinner-ring"></div>
          </div>
          <span class="loading-text">Loading video...</span>
        </div>
      </div>

      <!-- Controls Overlay Bar (bottom of cinema) -->
      <div class="player-controls-bar" id="controlsBar">
        <div class="controls-bar-left">
          <button id="prevEpisodeBtn" class="pctl-btn" title="Previous episode (Shift+P)" aria-label="Previous episode" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
          </button>
          <button id="nextEpisodeBtn" class="pctl-btn" title="Next episode (Shift+N)" aria-label="Next episode" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 18h2V6h-2zM6 18l8.5-6L6 6z"/></svg>
          </button>
          <span id="episodeIndicator" class="episode-indicator" style="display:none;"></span>
        </div>
        <div class="controls-bar-right">
          <div id="qualitySelector" class="quality-selector" style="display:none;">
            <button id="qualityBtn" class="pctl-btn quality-btn" title="Video quality">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 15V17M12 7V13M8 3H16L21 8V16L16 21H8L3 16V8L8 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span id="qualityLabel" class="quality-label">HD</span>
            </button>
            <div id="qualityMenu" class="quality-menu"></div>
          </div>
          <button id="theaterModeBtn" class="pctl-btn" title="Theater mode (t)" aria-label="Toggle theater mode">
            <svg class="theater-expand" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7V5C3 3.89543 3.89543 3 5 3H7M17 3H19C20.1046 3 21 3.89543 21 5V7M21 17V19C21 20.1046 20.1046 21 19 21H17M7 21H5C3.89543 21 3 20.1046 3 19V17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <svg class="theater-collapse" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 3V5C8 6.10457 7.10457 7 6 7H4M20 7H18C16.8954 7 16 6.10457 16 5V3M16 21V19C16 17.8954 16.8954 17 18 17H20M4 17H6C7.10457 17 8 17.8954 8 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Keyboard Shortcut Indicator -->
    <div id="shortcutIndicator" class="shortcut-indicator" aria-hidden="true"></div>

    <!-- Content Below Video -->
    <div class="player-content" id="playerContent">
      <div class="player-content-main">
        <!-- Video Info -->
        <section id="videoInfo" class="player-video-info">
          <h1 id="videoTitle" class="player-title">Loading...</h1>
          <div class="player-meta-row">
            <span id="videoCreator" class="player-creator"></span>
            <span id="videoDate" class="player-date"></span>
          </div>
          <div id="videoActions" class="player-video-actions">
            <a id="archiveLink" href="#" target="_blank" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M18 13V19C18 20.1046 17.1046 21 16 21H5C3.89543 21 3 20.1046 3 19V8C3 6.89543 3.89543 6 5 6H11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 3H21V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Archive.org
            </a>
            <button id="shareBtn" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 12V20C4 21.1046 4.89543 22 6 22H18C19.1046 22 20 21.1046 20 20V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16 6 12 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="2" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Share
            </button>
            <button id="downloadBtn" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L7 10M12 15L17 10M3 17V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Download
            </button>
          </div>
        </section>

        <!-- Description -->
        <section id="descriptionSection" class="player-description-section" style="display: none;">
          <button id="descriptionToggle" class="player-description-toggle">
            <span>Description</span>
            <svg class="toggle-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div id="descriptionContent" class="player-description-content"></div>
        </section>

        <!-- Downloads Panel -->
        <section id="downloadsPanel" class="player-downloads-panel" style="display: none;">
          <div class="player-downloads-header">
            <h3>Download Options</h3>
            <button id="closeDownloads" class="player-close-panel-btn">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div id="downloadLinks" class="player-download-links"></div>
        </section>
      </div>

      <!-- Sidebar: Playlist -->
      <aside id="playlistSidebar" class="player-sidebar" style="display: none;">
        <div class="player-sidebar-header">
          <div class="sidebar-header-info">
            <h3 id="playlistTitle">Episodes</h3>
            <span id="playlistCount" class="player-sidebar-count"></span>
          </div>
          <div class="sidebar-header-nav">
            <button id="sidebarPrevBtn" class="sidebar-nav-btn" disabled title="Previous episode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button id="sidebarNextBtn" class="sidebar-nav-btn" disabled title="Next episode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div id="playlistItems" class="player-sidebar-items"></div>
      </aside>
    </div>
  </main>

  <!-- Resume Prompt (non-blocking) -->
  <div id="resumePrompt" class="resume-prompt" role="dialog" aria-label="Resume playback" style="display: none;">
    <div class="resume-prompt-content">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/></svg>
      <span id="resumeText">Resume from 0:00?</span>
      <button id="resumeBtn" class="resume-btn">Resume</button>
      <button id="resumeDismiss" class="resume-dismiss" aria-label="Dismiss">&times;</button>
    </div>
  </div>

  <!-- Site Settings -->
  <script id="siteSettingsConfig" type="application/json"><?= json_encode($site_settings) ?></script>

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

      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        var savedTheme = localStorage.getItem('theme');
        if (!savedTheme || savedTheme === 'system') {
          setTheme(e.matches ? 'dark' : 'light');
        }
      });
    })();
  </script>

  <script type="module" src="player.js"></script>
</body>
</html>
