<?php
/**
 * Video Metadata API Endpoint
 *
 * Returns cached video metadata from Archive.org
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/ArchiveOrgService.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get video ID
$archiveId = $_GET['id'] ?? '';

// Validate ID
if (empty($archiveId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing video ID']);
    exit;
}

// Sanitize ID (Archive.org IDs can contain letters, numbers, underscores, hyphens)
$archiveId = preg_replace('/[^a-zA-Z0-9_-]/', '', $archiveId);

if (empty($archiveId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid video ID']);
    exit;
}

try {
    $archiveService = new ArchiveOrgService();

    $result = $archiveService->getMetadata($archiveId);

    // Add cache headers
    if ($result['cached'] ?? false) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=3600'); // 1 hour client cache
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=300'); // 5 minutes client cache
    }

    echo json_encode($result);

} catch (Exception $e) {
    error_log("Metadata API error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch metadata. Please try again.',
    ]);
}
