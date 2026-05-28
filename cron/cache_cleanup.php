<?php
/**
 * Cache Cleanup Cron Job
 *
 * Run this hourly to clean up expired cache entries
 * cPanel: Add to Cron Jobs with: php /home/yourusername/public_html/videos/cron/cache_cleanup.php
 */

// Prevent web access - CLI only for security. Some cron daemons invoke PHP
// under a cgi-fcgi SAPI rather than 'cli'; defined('STDIN') is true for any
// real command-line invocation, so accept that too (a web request never has it).
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    http_response_code(403);
    die('This script must be run from the command line');
}

require_once __DIR__ . '/../cache/CacheManager.php';

echo "Cache Cleanup Started: " . date('Y-m-d H:i:s') . "\n";

try {
    $cacheManager = new CacheManager();
    $deleted = $cacheManager->cleanExpiredCache();

    echo "Cleanup Results:\n";
    echo "  - Search cache entries: {$deleted['search']}\n";
    echo "  - Metadata cache entries: {$deleted['metadata']}\n";
    echo "  - Thumbnail files: {$deleted['thumbnails']}\n";

    // Get current stats
    $stats = $cacheManager->getStats();
    echo "\nCurrent Cache Stats:\n";
    echo "  - Active search entries: {$stats['search']['entries']}\n";
    echo "  - Active metadata entries: {$stats['metadata']['entries']}\n";
    echo "  - Cached thumbnails: {$stats['thumbnails']['entries']}\n";

    echo "\nCache Cleanup Completed: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
