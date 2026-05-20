<?php
/**
 * Settings API Endpoint
 *
 * GET  → public site settings
 * POST → update settings (admin only)
 *
 * Validation rules (previously in save-settings.php) live here so there's
 * exactly one path for admin settings writes.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$settingsService = new SettingsService();

if ($api->isGet()) {
    // Settings are public read but vary per install; short TTL so updates
    // are visible quickly. No `private` here -- settings are not user-specific.
    header('Cache-Control: public, max-age=300');
    $api->data($settingsService->getSettings());
}

// POST
$api->requireCsrf();
$api->requireAdmin();
$body = $api->jsonBody();

// Allow-list of settings with validation rules.
$schema = [
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
    'enableBookmarks' => ['type' => 'bool', 'default' => true],
    'enableWatchHistory' => ['type' => 'bool', 'default' => true],
    'defaultCollection' => ['type' => 'string', 'default' => 'all_videos', 'maxLength' => 50],
    'defaultSort' => ['type' => 'enum', 'default' => 'downloads', 'values' => ['downloads', 'date', 'title', 'relevance', 'creator']],
];

$clean = [];
foreach ($schema as $key => $rule) {
    if (!array_key_exists($key, $body)) {
        $clean[$key] = $rule['default'];
        continue;
    }
    $value = $body[$key];
    switch ($rule['type']) {
        case 'string':
            $clean[$key] = ApiController::sanitizeText($value, $rule['maxLength'] ?? 200);
            break;
        case 'color':
            $clean[$key] = ApiController::sanitizeHexColor($value, $rule['default']);
            break;
        case 'bool':
            $clean[$key] = ApiController::sanitizeBool($value);
            break;
        case 'enum':
            $clean[$key] = ApiController::sanitizeEnum($value, $rule['values'], $rule['default']);
            break;
        default:
            $clean[$key] = $rule['default'];
    }
}
$clean['updated'] = date('c');

// Persist to database
$dbSaveSuccess = false;
try {
    $dbSaveSuccess = $settingsService->updateSettings($clean);
} catch (Throwable $e) {
    error_log('[api/settings] DB save failed: ' . $e->getMessage());
}

// Best-effort JSON fallback for sites without DB. LOCK_EX prevents two
// concurrent admin saves from producing a half-written file.
$jsonPath = base_path('site-settings.json');
@file_put_contents($jsonPath, json_encode($clean, JSON_PRETTY_PRINT), LOCK_EX);
@chmod($jsonPath, 0644);

if (!$dbSaveSuccess && !file_exists($jsonPath)) {
    $api->error('Failed to save settings', 500);
}

$api->ok([
    'message' => 'Settings saved successfully',
    'data' => $clean,
    'database' => $dbSaveSuccess,
]);
