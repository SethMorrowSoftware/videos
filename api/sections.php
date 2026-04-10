<?php
/**
 * Featured Sections API Endpoint
 *
 * GET  → featured sections
 * POST → update featured sections (admin only)
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$settingsService = new SettingsService();

if ($api->isGet()) {
    header('Cache-Control: public, max-age=3600');
    $api->data($settingsService->getFeaturedSections());
}

// POST
$api->requireAdmin();
$body = $api->jsonBody();

if (!isset($body['sections']) || !is_array($body['sections'])) {
    $api->error('Invalid data structure', 400);
}

$sections = [];
foreach ($body['sections'] as $section) {
    if (!is_array($section) || !isset($section['id'], $section['title'])) {
        continue;
    }

    $clean = [
        'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$section['id']),
        'title' => ApiController::sanitizeText($section['title'], 255),
        'description' => isset($section['description']) ? ApiController::sanitizeText($section['description'], 1000) : '',
        'enabled' => ApiController::sanitizeBool($section['enabled'] ?? true),
        'videos' => [],
    ];

    if (isset($section['videos']) && is_array($section['videos'])) {
        foreach ($section['videos'] as $video) {
            if (!is_array($video) || empty($video['id'])) continue;
            $cleanVideo = [
                'id' => ApiController::sanitizeArchiveId($video['id']),
                'title' => ApiController::sanitizeText($video['title'] ?? '', 500),
                'creator' => ApiController::sanitizeText($video['creator'] ?? '', 255),
            ];
            if (isset($video['note'])) {
                $cleanVideo['note'] = ApiController::sanitizeText($video['note'], 500);
            }
            $clean['videos'][] = $cleanVideo;
        }
    }

    $clean['updated'] = date('c');
    $sections[] = $clean;
}

$output = [
    'sections' => $sections,
    'updated' => date('c'),
];

$dbSaveSuccess = false;
try {
    $dbSaveSuccess = $settingsService->updateFeaturedSections($output);
} catch (Throwable $e) {
    error_log('[api/sections] DB save failed: ' . $e->getMessage());
}

// JSON fallback
$jsonPath = base_path('featured-sections.json');
@file_put_contents($jsonPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
@chmod($jsonPath, 0644);

if (!$dbSaveSuccess && !file_exists($jsonPath)) {
    $api->error('Failed to save featured sections', 500);
}

$api->ok([
    'message' => 'Featured sections saved successfully',
    'sections_count' => count($sections),
    'database' => $dbSaveSuccess,
]);
