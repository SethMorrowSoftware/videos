<?php
/**
 * Save Site Settings Endpoint
 * Saves admin-configured site settings to database or site-settings.json
 */

// Start session to check admin status
session_start();

// Check for database authentication first
$useDatabase = false;
$authService = null;
$settingsService = null;

try {
    if (file_exists(__DIR__ . '/services/AdminAuthService.php') &&
        file_exists(__DIR__ . '/services/SettingsService.php') &&
        file_exists(__DIR__ . '/.env')) {

        require_once __DIR__ . '/services/AdminAuthService.php';
        require_once __DIR__ . '/services/SettingsService.php';

        $authService = new AdminAuthService();
        $settingsService = new SettingsService();

        if ($authService->hasAdminUsers()) {
            $useDatabase = true;
        }
    }
} catch (Exception $e) {
    // Database not available
}

// Verify admin is logged in
$isAuthorized = false;

if ($useDatabase && $authService) {
    $admin = $authService->validateSession();
    $isAuthorized = $admin !== null;
} else {
    $isAuthorized = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (!$isAuthorized) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Define allowed settings with types and defaults
$allowedSettings = [
    'siteName' => ['type' => 'string', 'default' => 'Archive Film Club', 'maxLength' => 100],
    'tagline' => ['type' => 'string', 'default' => 'Discover classic films from Archive.org', 'maxLength' => 200],
    'brandColor' => ['type' => 'color', 'default' => '#ff0000'],
    'accentColor' => ['type' => 'color', 'default' => '#065fd4'],
    'defaultTheme' => ['type' => 'enum', 'default' => 'dark', 'values' => ['dark', 'light', 'system']],
    'enableThemeToggle' => ['type' => 'bool', 'default' => true],
    'headerStyle' => ['type' => 'enum', 'default' => 'default', 'values' => ['default', 'minimal', 'centered']],
    'cardStyle' => ['type' => 'enum', 'default' => 'modern', 'values' => ['modern', 'classic', 'compact']],
    'showDownloadCount' => ['type' => 'bool', 'default' => true],
    'showCreator' => ['type' => 'bool', 'default' => true],
    'showDate' => ['type' => 'bool', 'default' => true],
    'enableBookmarks' => ['type' => 'bool', 'default' => false],
    'enableWatchHistory' => ['type' => 'bool', 'default' => true],
    'defaultCollection' => ['type' => 'string', 'default' => 'all_videos', 'maxLength' => 50],
    'defaultSort' => ['type' => 'enum', 'default' => 'downloads', 'values' => ['downloads', 'date', 'title', 'relevance', 'creator']]
];

// Sanitize and validate settings
$settings = [];
foreach ($allowedSettings as $key => $config) {
    if (isset($data[$key])) {
        $value = $data[$key];

        switch ($config['type']) {
            case 'string':
                $value = substr(strip_tags(trim($value)), 0, $config['maxLength'] ?? 200);
                break;
            case 'color':
                // Validate hex color
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    // Valid hex color
                } else {
                    $value = $config['default'];
                }
                break;
            case 'bool':
                $value = (bool)$value;
                break;
            case 'enum':
                if (!in_array($value, $config['values'])) {
                    $value = $config['default'];
                }
                break;
        }

        $settings[$key] = $value;
    } else {
        $settings[$key] = $config['default'];
    }
}

// Add timestamp
$settings['updated'] = date('c');

// Save to database if available
$dbSaveSuccess = false;
if ($useDatabase && $settingsService) {
    try {
        $dbSaveSuccess = $settingsService->updateSettings($settings);
    } catch (Exception $e) {
        error_log("Failed to save settings to database: " . $e->getMessage());
    }
}

// Always save to JSON file as backup/fallback
$filename = __DIR__ . '/site-settings.json';
$json = json_encode($settings, JSON_PRETTY_PRINT);

if (file_put_contents($filename, $json) !== false) {
    // Make sure file is readable
    chmod($filename, 0644);

    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully',
        'data' => $settings,
        'database' => $dbSaveSuccess
    ]);
} else {
    // Even if file write fails, database save might have succeeded
    if ($dbSaveSuccess) {
        echo json_encode([
            'success' => true,
            'message' => 'Settings saved to database (file backup failed)',
            'data' => $settings,
            'database' => true
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save settings']);
    }
}
