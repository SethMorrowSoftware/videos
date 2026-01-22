<?php
/**
 * Thumbnail API Endpoint
 *
 * Serves cached thumbnails or downloads from Archive.org and caches them.
 * ALWAYS falls back to Archive.org redirect if anything goes wrong.
 */

// Start output buffering immediately to catch any accidental output
ob_start();

// Get video ID early so we can redirect on any error
$archiveId = $_GET['id'] ?? '';
$archiveId = preg_replace('/[^a-zA-Z0-9_-]/', '', $archiveId);

// Helper function to redirect to Archive.org (fallback)
function redirectToArchive($id) {
    // Clean any buffered output that might interfere with headers
    while (ob_get_level()) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header("Location: https://archive.org/services/img/{$id}", true, 302);
    }
    exit;
}

// Validate ID
if (empty($archiveId)) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing video ID']);
    exit;
}

// Register shutdown function to catch fatal errors
register_shutdown_function(function() use ($archiveId) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Thumbnail API fatal error for {$archiveId}: " . $error['message']);
        redirectToArchive($archiveId);
    }
});

// Wrap everything in try-catch for maximum safety
try {
    // Try to load database - if this fails, redirect immediately
    $dbFile = __DIR__ . '/../db/Database.php';
    if (!file_exists($dbFile)) {
        throw new Exception("Database file not found");
    }

    require_once $dbFile;

    $db = Database::getInstance();
    $config = $db->getConfig();

    // Check if thumbnail caching is enabled
    $cachingEnabled = $config['features']['thumbnail_caching'] ?? true;
    if (!$cachingEnabled) {
        redirectToArchive($archiveId);
    }

    // Check if we have this thumbnail cached locally
    $result = null;
    try {
        $result = $db->fetchOne(
            "SELECT local_path FROM thumbnail_cache WHERE archive_id = ?",
            [$archiveId]
        );
    } catch (Exception $e) {
        // Table might not exist - that's OK, just redirect
        error_log("Thumbnail cache query failed: " . $e->getMessage());
        redirectToArchive($archiveId);
    }

    $localPath = null;

    if ($result && !empty($result['local_path']) && file_exists($result['local_path'])) {
        // We have it cached!
        $localPath = $result['local_path'];

        // Update access count (best effort, ignore failures)
        try {
            $db->query(
                "UPDATE thumbnail_cache SET access_count = access_count + 1, last_accessed = NOW() WHERE archive_id = ?",
                [$archiveId]
            );
        } catch (Exception $e) {
            // Ignore - not critical
        }
    } else {
        // NOT CACHED - Try to download from Archive.org and cache it
        $localPath = downloadAndCacheThumbnail($archiveId, $db, $config);
    }

    // Serve the file if we have it
    if ($localPath && file_exists($localPath)) {
        serveFile($localPath);
    } else {
        // Caching failed - redirect to Archive.org
        redirectToArchive($archiveId);
    }

} catch (Throwable $e) {
    // Catch absolutely everything (Exception and Error)
    error_log("Thumbnail API error for {$archiveId}: " . $e->getMessage());
    redirectToArchive($archiveId);
}

/**
 * Download thumbnail from Archive.org and cache it locally
 * Returns null if caching fails (caller will redirect to Archive.org)
 */
function downloadAndCacheThumbnail($archiveId, $db, $config) {
    $sourceUrl = "https://archive.org/services/img/{$archiveId}";

    // Download the image
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $imageData = @file_get_contents($sourceUrl, false, $context);

    if ($imageData === false || strlen($imageData) < 100) {
        return null;
    }

    // Verify it's an image
    if (!class_exists('finfo')) {
        return null; // finfo extension not available
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($imageData);

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        return null;
    }

    // Check if GD is available
    if (!function_exists('imagecreatefromstring')) {
        return null; // GD extension not available
    }

    // Create image from data
    $image = @imagecreatefromstring($imageData);
    if (!$image) {
        return null;
    }

    $width = imagesx($image);
    $height = imagesy($image);

    // Resize if too large (max 480px wide)
    $maxWidth = 480;
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)(($newWidth / $width) * $height);

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        imagedestroy($image);
        $image = $resized;
        $width = $newWidth;
        $height = $newHeight;
    }

    // Determine thumbnail directory and normalize path
    $thumbnailDir = $config['paths']['thumbnails'] ?? dirname(__DIR__) . '/thumbnails';

    // Normalize the path if possible
    $realDir = realpath($thumbnailDir);
    if ($realDir !== false) {
        $thumbnailDir = $realDir;
    }

    // Ensure directory exists and is writable
    if (!is_dir($thumbnailDir)) {
        if (!@mkdir($thumbnailDir, 0755, true)) {
            imagedestroy($image);
            return null;
        }
    }

    if (!is_writable($thumbnailDir)) {
        imagedestroy($image);
        return null;
    }

    // Generate safe filename
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $archiveId);
    $localPath = $thumbnailDir . '/' . $safeId . '.jpg';

    // Save as JPEG
    $success = @imagejpeg($image, $localPath, 85);
    imagedestroy($image);

    if (!$success || !file_exists($localPath)) {
        return null;
    }

    // Store in database (best effort - ignore failures)
    try {
        $fileSize = filesize($localPath);
        $db->query(
            "INSERT INTO thumbnail_cache (archive_id, original_url, local_path, file_size, width, height, mime_type, access_count, last_accessed)
             VALUES (?, ?, ?, ?, ?, ?, 'image/jpeg', 1, NOW())
             ON DUPLICATE KEY UPDATE
                local_path = VALUES(local_path),
                file_size = VALUES(file_size),
                width = VALUES(width),
                height = VALUES(height),
                access_count = access_count + 1,
                last_accessed = NOW()",
            [$archiveId, $sourceUrl, $localPath, $fileSize, $width, $height]
        );
    } catch (Exception $e) {
        // Database insert failed, but we still have the file - that's OK
    }

    return $localPath;
}

/**
 * Serve a file with proper caching headers
 */
function serveFile($path) {
    // Clean output buffer before serving
    while (ob_get_level()) {
        ob_end_clean();
    }

    $mime = @mime_content_type($path) ?: 'image/jpeg';
    $size = @filesize($path);
    $mtime = @filemtime($path);
    $etag = md5($path . $mtime);

    // Check for If-None-Match header (browser cache)
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }

    // Send headers and file
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=604800'); // 7 days
    header('ETag: "' . $etag . '"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache: HIT');

    readfile($path);
    exit;
}
