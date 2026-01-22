#!/usr/bin/env php
<?php
/**
 * Process Cache Queue Cron Job
 *
 * Run this cron job periodically to process the background cache queue.
 * Recommended: Every 5 minutes during active hours, every 15 minutes otherwise.
 *
 * Example crontab entry:
 * */5 * * * * php /path/to/videos/cron/process_cache_queue.php >> /path/to/logs/cache_queue.log 2>&1
 */

// Change to the script's directory
chdir(__DIR__ . '/..');

// Set up error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Prevent timeout for long-running processes
set_time_limit(300);

require_once __DIR__ . '/../services/LocalStorageService.php';
require_once __DIR__ . '/../cache/CacheManager.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting cache queue processing\n";

try {
    $localStorageService = new LocalStorageService();
    $cacheManager = new CacheManager();

    // Process main queue
    $limit = 30; // Process up to 30 items per run
    $results = $localStorageService->processQueue($limit);

    echo "Metadata processed: {$results['metadata_processed']}\n";
    echo "Thumbnails processed: {$results['thumbnails_processed']}\n";

    if (!empty($results['errors'])) {
        echo "Errors (" . count($results['errors']) . "):\n";
        foreach (array_slice($results['errors'], 0, 5) as $error) {
            echo "  - $error\n";
        }
    }

    // Refresh stale data (lower priority, do fewer items)
    $staleResults = $localStorageService->refreshStaleData(5);
    echo "Stale items refreshed: {$staleResults['refreshed']}\n";

    // Record daily stats
    $cacheManager->recordDailyStats();

    // Get and display current stats
    $stats = $cacheManager->getStats();
    echo "\nCurrent cache stats:\n";
    echo "  Metadata entries: {$stats['metadata']['entries']} (permanent: {$stats['metadata']['permanent']}, stale: {$stats['metadata']['stale']})\n";
    echo "  Thumbnail entries: {$stats['thumbnails']['entries']}\n";
    echo "  Queue pending: {$stats['queue']['pending']}\n";

    echo "\n[" . date('Y-m-d H:i:s') . "] Cache queue processing completed\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
