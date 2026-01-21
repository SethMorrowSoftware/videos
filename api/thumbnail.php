<?php
/**
 * Thumbnail API Endpoint
 *
 * Serves cached thumbnails or redirects to Archive.org
 */

require_once __DIR__ . '/../cache/ThumbnailCache.php';
require_once __DIR__ . '/../db/Database.php';

// Get video ID
$archiveId = $_GET['id'] ?? '';

// Validate ID
if (empty($archiveId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing video ID']);
    exit;
}

// Sanitize ID
$archiveId = preg_replace('/[^a-zA-Z0-9_-]/', '', $archiveId);

if (empty($archiveId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid video ID']);
    exit;
}

try {
    // Check if thumbnail caching is enabled
    $config = Database::getInstance()->getConfig();
    $cachingEnabled = $config['features']['thumbnail_caching'] ?? true;

    if (!$cachingEnabled) {
        // Redirect to Archive.org
        header("Location: https://archive.org/services/img/{$archiveId}", true, 302);
        exit;
    }

    $thumbnailCache = new ThumbnailCache();

    // This will serve the cached thumbnail or redirect to Archive.org
    $thumbnailCache->serve($archiveId);

} catch (Exception $e) {
    error_log("Thumbnail API error: " . $e->getMessage());

    // Fallback to Archive.org
    header("Location: https://archive.org/services/img/{$archiveId}", true, 302);
    exit;
}
