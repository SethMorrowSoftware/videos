<?php
/**
 * API Router
 *
 * Simple router for API endpoints
 * Provides a unified entry point for all API requests
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Enable CORS for same-origin requests
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    // Only allow same-origin or specific domains
    $allowedOrigins = [
        $_SERVER['HTTP_HOST'] ?? '',
        'localhost',
    ];

    $parsedOrigin = parse_url($origin, PHP_URL_HOST);
    if (in_array($parsedOrigin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// API info endpoint
echo json_encode([
    'name' => 'Archive Film Club API',
    'version' => '1.0.0',
    'endpoints' => [
        'GET /api/search.php' => 'Search videos',
        'GET /api/metadata.php' => 'Get video metadata',
        'GET /api/thumbnail.php' => 'Get cached thumbnail',
        'GET /api/settings.php' => 'Get site settings',
        'POST /api/settings.php' => 'Update site settings (admin)',
        'GET /api/recommendations.php' => 'Get recommendations',
        'POST /api/recommendations.php' => 'Update recommendations (admin)',
        'GET /api/sections.php' => 'Get featured sections',
        'POST /api/sections.php' => 'Update featured sections (admin)',
        'GET /api/bookmarks.php' => 'Get user bookmarks',
        'POST /api/bookmarks.php' => 'Add/remove bookmark',
        'GET /api/history.php' => 'Get watch history',
        'POST /api/history.php' => 'Update watch progress',
    ],
]);
