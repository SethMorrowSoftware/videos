<?php
/**
 * Admin database-maintenance API
 *
 * GET  ?action=status                 → DB snapshot (tables, sizes, limits)
 *
 * POST { action: 'backup', include_caches }          → streams a .sql download
 * POST { action: 'download-thumbnails' }             → streams a .zip download
 * POST { action: 'refresh-schema' }                  → run pending migrations
 * POST { action: 'refresh-cache' }                   → flush + re-warm caches
 * POST { action: 'refresh-metadata', limit }         → re-fetch stale metadata
 * POST { action: 'content-reset', confirm }          → wipe community + cache data
 *
 * ALL actions require a FULL admin (role === 'admin'). requireAdmin() also
 * admits 'editor' for comment moderation, so this file gates strictly on top
 * of it — the database surface is far too powerful for the curation role.
 * Destructive actions additionally require a typed confirmation.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

if ($api->isPost()) {
    $api->requireCsrf();
}

$admin = $api->requireAdmin();
if (($admin['role'] ?? '') !== 'admin') {
    $api->error('This area manages the database and is restricted to full administrators.', 403);
}

/**
 * One audit line per maintenance action. There is no dedicated audit table
 * yet, so this goes to the PHP error log — enough to answer "who reset the
 * site and when". (A persistent admin_actions table is a sensible follow-up.)
 */
$audit = function (string $action, array $extra = []) use ($admin) {
    $who = ($admin['username'] ?? ('id#' . ($admin['id'] ?? '?')));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    error_log('[admin-maintenance] user=' . $who . ' ip=' . $ip
        . ' action=' . $action
        . ($extra ? ' ' . json_encode($extra) : ''));
};

$svc = new MaintenanceService();

// ---- GET: status (read-only) ----
if ($api->isGet()) {
    $action = (string)$api->query('action', 'status');
    if ($action === 'status') {
        header('Cache-Control: private, no-store');
        $api->ok(['status' => $svc->databaseStatus()]);
    }
    $api->error('Invalid action', 400);
}

// ---- POST: actions ----
$body = $api->jsonBody();
$action = $body['action'] ?? '';

try {
    switch ($action) {

        case 'backup': {
            $includeCaches = array_key_exists('include_caches', $body)
                ? ApiController::sanitizeBool($body['include_caches'])
                : true;
            $audit('backup', ['include_caches' => $includeCaches]);

            $fname = 'afc-backup-' . gmdate('Ymd-His')
                . ($includeCaches ? '' : '-nocache') . '.sql';

            // Override the JSON content-type the controller set in its ctor.
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');

            $svc->streamBackup($includeCaches);
            exit;
        }

        case 'download-thumbnails': {
            $path = $svc->buildThumbnailsZip();
            if ($path === null) {
                // No headers sent yet → safe to return a JSON error.
                $api->error('No cached thumbnails to download (or the zip extension is unavailable).', 400);
            }
            $audit('download-thumbnails');

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="afc-thumbnails-' . gmdate('Ymd-His') . '.zip"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');

            readfile($path);
            @unlink($path);
            exit;
        }

        case 'refresh-schema': {
            $audit('refresh-schema');
            $api->ok(['result' => $svc->runMigrations()]);
        }

        case 'refresh-cache': {
            $audit('refresh-cache');
            $api->ok(['result' => $svc->refreshCaches()]);
        }

        case 'refresh-metadata': {
            $limit = (int)($body['limit'] ?? 25);
            $limit = max(1, min(200, $limit));
            $audit('refresh-metadata', ['limit' => $limit]);
            $api->ok(['result' => $svc->refetchMetadata($limit)]);
        }

        case 'content-reset': {
            // Type-to-confirm: the supplied phrase must match the site name.
            $expected = 'Archive Film Club';
            try {
                $settings = (new SettingsService())->getSettings();
                if (!empty($settings['siteName'])) {
                    $expected = (string)$settings['siteName'];
                }
            } catch (Throwable $e) { /* fall back to default name */ }

            $confirm = trim((string)($body['confirm'] ?? ''));
            if (strcasecmp($confirm, trim($expected)) !== 0) {
                $api->error('Confirmation text did not match the site name. Type "' . $expected . '" to confirm.', 400);
            }

            $audit('content-reset');
            $api->ok(['result' => $svc->contentReset()]);
        }

        default:
            $api->error('Invalid action', 400);
    }
} catch (RuntimeException $e) {
    $api->error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[api/admin/maintenance] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $api->error('Internal error while performing maintenance action.', 500);
}
