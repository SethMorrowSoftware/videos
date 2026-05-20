<?php
/**
 * Recommendations API Endpoint
 *
 * GET  → staff picks
 * POST → update staff picks (admin only)
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$settingsService = new SettingsService();

if ($api->isGet()) {
    header('Cache-Control: public, max-age=300');
    $api->data($settingsService->getRecommendations());
}

// POST
$api->requireCsrf();
$api->requireAdmin();
$body = $api->jsonBody();

if (!isset($body['videos']) || !is_array($body['videos'])) {
    $api->error('Missing videos array', 400);
}

$videos = [];
foreach ($body['videos'] as $video) {
    if (!is_array($video) || empty($video['id']) || !is_string($video['id'])) {
        continue;
    }
    $videos[] = [
        'id' => ApiController::sanitizeArchiveId($video['id']),
        'title' => ApiController::sanitizeText($video['title'] ?? '', 200),
        'creator' => ApiController::sanitizeText($video['creator'] ?? '', 100),
    ];
}

$recommendations = [
    'enabled' => ApiController::sanitizeBool($body['enabled'] ?? true),
    'title' => ApiController::sanitizeText($body['title'] ?? 'Staff Picks', 50),
    'videos' => $videos,
    'updated' => date('c'),
];

$dbSaveSuccess = false;
try {
    $dbSaveSuccess = $settingsService->updateRecommendations($recommendations);
} catch (Throwable $e) {
    error_log('[api/recommendations] DB save failed: ' . $e->getMessage());
}

// JSON fallback -- LOCK_EX guards against concurrent admin saves.
$jsonPath = base_path('recommendations.json');
@file_put_contents($jsonPath, json_encode($recommendations, JSON_PRETTY_PRINT), LOCK_EX);
@chmod($jsonPath, 0644);

if (!$dbSaveSuccess && !file_exists($jsonPath)) {
    $api->error('Failed to save recommendations', 500);
}

$api->ok([
    'message' => 'Saved ' . count($videos) . ' videos',
    'data' => $recommendations,
    'database' => $dbSaveSuccess,
]);
