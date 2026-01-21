<?php
/**
 * Featured Sections API Endpoint
 *
 * GET: Returns featured sections
 * POST: Updates featured sections (requires admin authentication)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../services/AdminAuthService.php';

$settingsService = new SettingsService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public endpoint - return sections
    $sections = $settingsService->getFeaturedSections();

    header('Cache-Control: public, max-age=3600'); // 1 hour cache
    echo json_encode([
        'success' => true,
        'data' => $sections,
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin only - update sections
    $authService = new AdminAuthService();
    $admin = $authService->validateSession();

    if (!$admin) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }

    // Update sections
    $success = $settingsService->updateFeaturedSections($data);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Sections updated successfully',
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update sections',
        ]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
