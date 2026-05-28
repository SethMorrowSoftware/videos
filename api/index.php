<?php
/**
 * API Router
 *
 * Simple router for API endpoints
 * Provides a unified entry point for all API requests
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// CORS: only reflect an Origin whose host matches THIS install's configured
// host. safe_host() pins to APP_URL / SERVER_NAME and deliberately ignores the
// client-controlled Host header — the previous allow-list trusted HTTP_HOST,
// which an attacker can set on a host that doesn't pin it. localhost/127.0.0.1
// are literal constants kept for local dev, not derived from the request.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowedHosts = [safe_host(), 'localhost', '127.0.0.1'];
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost !== null && in_array($originHost, $allowedHosts, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
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
