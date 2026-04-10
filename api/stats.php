<?php
/**
 * Stats / Analytics API Endpoint
 *
 * GET ?action=overview|detailed|storage|popular|hitrates  (admin only)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../cache/CacheManager.php';

$api = new ApiController();
$api->requireMethod('GET');
$api->requireAdmin();

/** Format bytes as a human-readable string. */
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

$action = $api->query('action', 'overview');

switch ($action) {
    case 'overview': {
        $cm = new CacheManager();
        $api->data([
            'cache' => $cm->getStats(),
            'hitRates' => $cm->getHitRate('24 hours'),
        ]);
        break;
    }

    case 'detailed': {
        $cm = new CacheManager();
        $api->data($cm->getDetailedStats());
        break;
    }

    case 'storage': {
        $cm = new CacheManager();
        $stats = $cm->getDetailedStats();

        $thumbDir = base_path('thumbnails');
        $thumbDirSize = 0;
        $thumbCount = 0;
        if (is_dir($thumbDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($thumbDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $thumbDirSize += $file->getSize();
                    $thumbCount++;
                }
            }
        }

        $api->data([
            'thumbnails' => [
                'count' => $thumbCount,
                'size_bytes' => $thumbDirSize,
                'size_formatted' => formatBytes($thumbDirSize),
            ],
            'metadata' => [
                'count' => $stats['metadata']['entries'] ?? 0,
                'permanent' => $stats['metadata']['permanent'] ?? 0,
            ],
            'api_savings' => $stats['api_savings'] ?? [],
        ]);
        break;
    }

    case 'popular': {
        $archiveService = new ArchiveOrgService();
        $limit = min(50, max(1, (int)$api->query('limit', 20)));
        $api->data($archiveService->getPopularSearches($limit));
        break;
    }

    case 'hitrates': {
        $cm = new CacheManager();
        $period = $api->query('period', '24 hours');
        $validPeriods = ['1 hour', '6 hours', '24 hours', '7 days', '30 days'];
        if (!in_array($period, $validPeriods, true)) {
            $period = '24 hours';
        }
        $api->data($cm->getHitRate($period));
        break;
    }

    default:
        $api->error('Invalid action', 400);
}
