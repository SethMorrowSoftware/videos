<?php
/**
 * Video Metadata API Endpoint
 *
 * GET ?id=X → cached video metadata from Archive.org
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod('GET');

$archiveId = ApiController::sanitizeArchiveId($api->query('id', ''));
if ($archiveId === '') {
    $api->error('Missing or invalid video ID', 400);
}

try {
    $archiveService = new ArchiveOrgService();
    $result = $archiveService->getMetadata($archiveId);

    if ($result['cached'] ?? false) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=3600');
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=300');
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('[api/metadata] ' . $e->getMessage());
    $api->error('Failed to fetch metadata. Please try again.', 500);
}
