<?php
/**
 * Save Recommendations Endpoint
 * Saves admin-selected videos to database or recommendations.json
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

// Validate data structure
if (!isset($data['videos']) || !is_array($data['videos'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing videos array']);
    exit;
}

// Sanitize videos
$videos = [];
foreach ($data['videos'] as $video) {
    if (isset($video['id']) && is_string($video['id'])) {
        $videos[] = [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', $video['id']),
            'title' => isset($video['title']) ? substr(strip_tags($video['title']), 0, 200) : '',
            'creator' => isset($video['creator']) ? substr(strip_tags($video['creator']), 0, 100) : ''
        ];
    }
}

// Build recommendations object
$recommendations = [
    'enabled' => isset($data['enabled']) ? (bool)$data['enabled'] : true,
    'title' => isset($data['title']) ? substr(strip_tags($data['title']), 0, 50) : 'Staff Picks',
    'videos' => $videos,
    'updated' => date('c')
];

// Save to database if available
$dbSaveSuccess = false;
if ($useDatabase && $settingsService) {
    try {
        $dbSaveSuccess = $settingsService->updateRecommendations($recommendations);
    } catch (Exception $e) {
        error_log("Failed to save recommendations to database: " . $e->getMessage());
    }
}

// Always save to JSON file as backup/fallback
$filename = __DIR__ . '/recommendations.json';
$json = json_encode($recommendations, JSON_PRETTY_PRINT);

if (file_put_contents($filename, $json) !== false) {
    // Make sure file is readable
    chmod($filename, 0644);

    echo json_encode([
        'success' => true,
        'message' => 'Saved ' . count($videos) . ' videos',
        'data' => $recommendations,
        'database' => $dbSaveSuccess
    ]);
} else {
    // Even if file write fails, database save might have succeeded
    if ($dbSaveSuccess) {
        echo json_encode([
            'success' => true,
            'message' => 'Saved ' . count($videos) . ' videos to database',
            'data' => $recommendations,
            'database' => true
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save recommendations']);
    }
}
