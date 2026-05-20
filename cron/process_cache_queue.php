#!/usr/bin/env php
<?php
/**
 * Process Cache Queue Cron Job
 *
 * Run this cron job periodically to process the background cache queue.
 * Recommended: Every 5 minutes during active hours, every 15 minutes otherwise.
 *
 * Example crontab entry (note: the slash-star is escaped in this comment to
 * avoid prematurely closing the docblock — when adding to crontab use the
 * standard syntax without the space):
 *
 *   * / 5 * * * * php /path/to/videos/cron/process_cache_queue.php >> /path/to/logs/cache_queue.log 2>&1
 */

// Prevent web access - CLI only for security
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line');
}

// Change to the script's directory
if (!@chdir(__DIR__ . '/..')) {
    fwrite(STDERR, "Could not chdir into install root\n");
    exit(1);
}

// Set up error handling. ini_set + set_time_limit are commonly in
// disable_functions on shared cPanel; guard each call.
error_reporting(E_ALL);
if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
}

// Try to extend the timeout for long-running processes. If set_time_limit
// is disabled, fall back to processing a smaller per-batch limit (see
// the $limit below) so we still finish within the default
// max_execution_time of ~30s on shared hosts.
$hasExtendedTimeout = false;
if (function_exists('set_time_limit')) {
    $hasExtendedTimeout = @set_time_limit(300);
}

require_once __DIR__ . '/../services/LocalStorageService.php';
require_once __DIR__ . '/../cache/CacheManager.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting cache queue processing\n";

try {
    $localStorageService = new LocalStorageService();
    $cacheManager = new CacheManager();

    // Process main queue. Drop the batch size when we couldn't extend the
    // per-request timeout, so a 30s shared-cPanel default still completes
    // cleanly.
    $limit = $hasExtendedTimeout ? 30 : 10;
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
