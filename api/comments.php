<?php
/**
 * Comments API endpoint
 *
 * GET  ?action=list&video=<archive_id>&sort=top|newest&page=N
 * GET  ?action=replies&parent_id=N&after_id=N
 * POST { action: 'create',   video_id, body, parent_id? }
 * POST { action: 'edit',     id, body }
 * POST { action: 'delete',   id }
 * POST { action: 'like',     id }
 * POST { action: 'report',   id, reason? }
 * POST { action: 'moderate', id, op: 'hide'|'restore'|'delete' }  (admin)
 *
 * Comments live ONLY in this database. They are NEVER posted to archive.org.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

if ($api->isPost()) {
    $api->requireCsrf();
}

$service = new CommentService();

// =====================================================
// GET
// =====================================================
if ($api->isGet()) {
    header('Cache-Control: private, no-store');
    $action = $api->query('action', 'list');

    if ($action === 'list') {
        $archiveId = ApiController::sanitizeArchiveId((string)$api->query('video', ''));
        if ($archiveId === '') {
            $api->error('Missing video parameter', 400);
        }
        $sort = ApiController::sanitizeEnum($api->query('sort', 'top'), ['top', 'newest'], 'top');
        $page = max(1, (int)$api->query('page', 1));
        $result = $service->listForVideo($archiveId, ['sort' => $sort, 'page' => $page]);
        $api->ok($result);
    }

    if ($action === 'replies') {
        $parentId = (int)$api->query('parent_id', 0);
        $afterId = (int)$api->query('after_id', 0);
        if ($parentId <= 0) {
            $api->error('Missing parent_id', 400);
        }
        $api->ok($service->listReplies($parentId, $afterId));
    }

    $api->error('Invalid action', 400);
}

// =====================================================
// POST
// =====================================================
$body = $api->jsonBody();
$action = $body['action'] ?? '';

try {
    switch ($action) {
        case 'create': {
            $archiveId = ApiController::sanitizeArchiveId((string)$api->required($body, 'video_id'));
            if ($archiveId === '') {
                $api->error('Invalid video_id', 400);
            }
            $text = (string)$api->required($body, 'body');
            $parentId = isset($body['parent_id']) && $body['parent_id'] !== null
                ? (int)$body['parent_id'] : null;
            $comment = $service->post($archiveId, $text, $parentId);
            $api->ok(['comment' => $comment]);
            break;
        }
        case 'edit': {
            $id = (int)$api->required($body, 'id');
            $text = (string)$api->required($body, 'body');
            $comment = $service->edit($id, $text);
            $api->ok(['comment' => $comment]);
            break;
        }
        case 'delete': {
            $id = (int)$api->required($body, 'id');
            $service->delete($id);
            $api->ok(['message' => 'Comment deleted']);
            break;
        }
        case 'like': {
            $id = (int)$api->required($body, 'id');
            $state = $service->toggleLike($id);
            $api->ok($state);
            break;
        }
        case 'report': {
            $id = (int)$api->required($body, 'id');
            $reason = isset($body['reason']) ? (string)$body['reason'] : null;
            $service->report($id, $reason);
            $api->ok(['message' => 'Report submitted']);
            break;
        }
        case 'moderate': {
            $id = (int)$api->required($body, 'id');
            $op = ApiController::sanitizeEnum($body['op'] ?? '', ['hide', 'restore', 'delete'], '');
            if ($op === '') {
                $api->error('Invalid moderation op', 400);
            }
            $service->moderate($id, $op);
            $api->ok(['message' => 'Moderation applied']);
            break;
        }
        default:
            $api->error('Invalid action', 400);
    }
} catch (RuntimeException $e) {
    // CommentService throws with a status code stuffed in $e->getCode()
    // for auth/permission failures. Default to 400 for validation.
    $code = $e->getCode();
    $status = ($code === 401 || $code === 403 || $code === 429) ? $code : 400;
    $api->error($e->getMessage(), $status);
}
