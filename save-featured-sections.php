<?php
/**
 * Save Featured Sections API Endpoint
 * Handles saving featured content sections to database or JSON
 */

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
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Validate data structure
if (!isset($data['sections']) || !is_array($data['sections'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data structure']);
    exit;
}

// Validate and sanitize each section
$sections = [];
foreach ($data['sections'] as $section) {
    // Required fields
    if (!isset($section['id']) || !isset($section['title'])) {
        continue;
    }

    $sanitizedSection = [
        'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', $section['id']),
        'title' => htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'),
        'enabled' => isset($section['enabled']) ? (bool)$section['enabled'] : true,
        'videos' => []
    ];

    // Optional fields
    if (isset($section['description'])) {
        $sanitizedSection['description'] = htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8');
    }

    // Validate videos array
    if (isset($section['videos']) && is_array($section['videos'])) {
        foreach ($section['videos'] as $video) {
            if (isset($video['id'])) {
                $sanitizedVideo = [
                    'id' => preg_replace('/[^a-zA-Z0-9_.-]/', '', $video['id']),
                    'title' => isset($video['title']) ? htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8') : '',
                    'creator' => isset($video['creator']) ? htmlspecialchars($video['creator'], ENT_QUOTES, 'UTF-8') : ''
                ];

                if (isset($video['note'])) {
                    $sanitizedVideo['note'] = htmlspecialchars($video['note'], ENT_QUOTES, 'UTF-8');
                }

                $sanitizedSection['videos'][] = $sanitizedVideo;
            }
        }
    }

    $sanitizedSection['updated'] = date('c');
    $sections[] = $sanitizedSection;
}

// Prepare output data
$output = [
    'sections' => $sections,
    'updated' => date('c')
];

// Save to database if available
$dbSaveSuccess = false;
if ($useDatabase && $settingsService) {
    try {
        $dbSaveSuccess = $settingsService->updateFeaturedSections($output);
    } catch (Exception $e) {
        error_log("Failed to save featured sections to database: " . $e->getMessage());
    }
}

// Always save to JSON file as backup/fallback
$file = __DIR__ . '/featured-sections.json';
$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    if ($dbSaveSuccess) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Featured sections saved to database',
            'sections_count' => count($sections),
            'database' => true
        ]);
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to encode JSON']);
    exit;
}

$written = file_put_contents($file, $json);

if ($written === false) {
    if ($dbSaveSuccess) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Featured sections saved to database',
            'sections_count' => count($sections),
            'database' => true
        ]);
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to write file']);
    exit;
}

// Set appropriate permissions
@chmod($file, 0644);

// Return success
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Featured sections saved successfully',
    'sections_count' => count($sections),
    'database' => $dbSaveSuccess
]);
