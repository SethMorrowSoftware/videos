<?php
/**
 * Cache Warmer Cron Job
 *
 * Run this every 30 minutes to pre-cache popular content
 * cPanel: Add to Cron Jobs with: php /home/yourusername/public_html/videos/cron/cache_warmer.php
 */

// Prevent web access - CLI only for security
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line');
}

require_once __DIR__ . '/../services/ArchiveOrgService.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../cache/ThumbnailCache.php';

echo "Cache Warmer Started: " . date('Y-m-d H:i:s') . "\n";

try {
    $archiveService = new ArchiveOrgService();
    $settingsService = new SettingsService();
    $thumbnailCache = new ThumbnailCache();

    // Warm popular searches
    echo "\nWarming popular searches...\n";
    $popularSearches = $archiveService->getPopularSearches(20);

    foreach ($popularSearches as $search) {
        echo "  - Warming: {$search['query']}\n";
        try {
            $archiveService->search(['q' => $search['query']]);
        } catch (Exception $e) {
            echo "    Error: {$e->getMessage()}\n";
        }
        usleep(500000); // 0.5 second delay to be nice to Archive.org
    }

    // Warm featured content
    echo "\nWarming featured content...\n";

    // Get recommendations
    $recommendations = $settingsService->getRecommendations();
    foreach ($recommendations['videos'] ?? [] as $video) {
        echo "  - Warming metadata: {$video['id']}\n";
        try {
            $archiveService->getMetadata($video['id']);
            $thumbnailCache->cache($video['id']);
        } catch (Exception $e) {
            echo "    Error: {$e->getMessage()}\n";
        }
        usleep(300000); // 0.3 second delay
    }

    // Get featured sections
    $sections = $settingsService->getFeaturedSections();
    foreach ($sections['sections'] ?? [] as $section) {
        if (!($section['enabled'] ?? true)) continue;

        foreach ($section['videos'] ?? [] as $video) {
            echo "  - Warming metadata: {$video['id']}\n";
            try {
                $archiveService->getMetadata($video['id']);
                $thumbnailCache->cache($video['id']);
            } catch (Exception $e) {
                echo "    Error: {$e->getMessage()}\n";
            }
            usleep(300000); // 0.3 second delay
        }
    }

    echo "\nCache Warmer Completed: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
