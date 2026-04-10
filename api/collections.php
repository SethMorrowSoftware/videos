<?php
/**
 * Collections API
 *
 *  GET  /api/collections.php                        → list my collections
 *  GET  /api/collections.php?id=N                   → one collection + items (owner-scoped)
 *  GET  /api/collections.php?username=u&slug=s      → public collection by slug (any user)
 *  GET  /api/collections.php?public=1               → list public collections
 *  GET  /api/collections.php?archive_id=X           → which of my collections contain this video
 *
 *  POST /api/collections.php  { action, ... }
 *    actions:
 *       create       { name, description?, is_public? }
 *       update       { id, name?, description?, is_public?, cover_thumbnail? }
 *       delete       { id }
 *       addItem      { id, video: { archive_id|id, title?, creator?, thumbnail?, note? } }
 *       removeItem   { id, archive_id }
 *       reorderItems { id, archive_ids: [...] }
 *       updateNote   { id, archive_id, note }
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$service = new CollectionService();

if ($api->isGet()) {
    // --- public collection by slug ----------------------------------------
    $username = $api->query('username');
    $slug = $api->query('slug');
    if ($username && $slug) {
        $col = $service->getPublicBySlug((string)$username, (string)$slug);
        if (!$col) {
            $api->error('Collection not found', 404);
        }
        $items = $service->getItems((int)$col['id']);
        $service->trackView((int)$col['id']);
        $api->data(['collection' => $col, 'items' => $items]);
    }

    // --- list public collections -----------------------------------------
    if ($api->query('public')) {
        $limit = min(100, max(1, (int)$api->query('limit', 24)));
        $offset = max(0, (int)$api->query('offset', 0));
        $api->data($service->listPublic($limit, $offset));
    }

    // --- everything below requires auth ----------------------------------
    $user = $api->requireAuth();
    $userId = (int)$user['id'];

    // "Which of my collections contain this video?" — used by the Save UI
    $archiveId = $api->query('archive_id');
    if ($archiveId) {
        $archiveId = ApiController::sanitizeArchiveId((string)$archiveId);
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT c.id, c.name, c.slug, c.is_public
             FROM user_collections c
             JOIN user_collection_items i ON i.collection_id = c.id
             WHERE c.user_id = ? AND i.archive_id = ?",
            [$userId, $archiveId]
        );
        $api->data($rows);
    }

    // Single collection (with items)
    $id = $api->query('id');
    if ($id) {
        $col = $service->getForUser($userId, (int)$id);
        if (!$col) {
            $api->error('Collection not found', 404);
        }
        $items = $service->getItems((int)$col['id']);
        $api->data(['collection' => $col, 'items' => $items]);
    }

    // Default: list my collections
    $api->data($service->listForUser($userId));
}

// ----- POST actions --------------------------------------------------------
$user = $api->requireAuth();
$userId = (int)$user['id'];
$body = $api->jsonBody();
$action = (string)($body['action'] ?? '');

try {
    switch ($action) {
        case 'create': {
            $id = $service->create($userId, [
                'name' => $body['name'] ?? '',
                'description' => $body['description'] ?? null,
                'is_public' => !empty($body['is_public']),
                'cover_thumbnail' => $body['cover_thumbnail'] ?? null,
            ]);
            $collection = $service->getForUser($userId, $id);
            $api->ok(['collection' => $collection, 'message' => 'Collection created']);
        }

        case 'update': {
            $id = (int)($body['id'] ?? 0);
            $ok = $service->update($userId, $id, $body);
            if (!$ok) $api->error('Collection not found', 404);
            $api->ok(['collection' => $service->getForUser($userId, $id), 'message' => 'Collection updated']);
        }

        case 'delete': {
            $id = (int)($body['id'] ?? 0);
            $ok = $service->delete($userId, $id);
            if (!$ok) $api->error('Collection not found', 404);
            $api->ok(['message' => 'Collection deleted']);
        }

        case 'addItem': {
            $id = (int)($body['id'] ?? 0);
            $video = (array)($body['video'] ?? []);
            // Normalize archive_id from either `archive_id` or `id`
            if (!isset($video['archive_id']) && isset($video['id'])) {
                $video['archive_id'] = $video['id'];
            }
            $video['archive_id'] = ApiController::sanitizeArchiveId($video['archive_id'] ?? '');
            $added = $service->addItem($userId, $id, $video);
            $api->ok([
                'added' => $added,
                'message' => $added ? 'Added to collection' : 'Already in collection',
            ]);
        }

        case 'removeItem': {
            $id = (int)($body['id'] ?? 0);
            $archiveId = ApiController::sanitizeArchiveId((string)($body['archive_id'] ?? ''));
            if ($archiveId === '') $api->error('Missing archive_id', 400);
            $ok = $service->removeItem($userId, $id, $archiveId);
            $api->ok(['removed' => $ok]);
        }

        case 'reorderItems': {
            $id = (int)($body['id'] ?? 0);
            $ids = $body['archive_ids'] ?? [];
            if (!is_array($ids)) $api->error('archive_ids must be an array', 400);
            $clean = array_map(
                fn($v) => ApiController::sanitizeArchiveId((string)$v),
                $ids
            );
            $service->reorderItems($userId, $id, $clean);
            $api->ok(['message' => 'Order updated']);
        }

        case 'updateNote': {
            $id = (int)($body['id'] ?? 0);
            $archiveId = ApiController::sanitizeArchiveId((string)($body['archive_id'] ?? ''));
            $note = (string)($body['note'] ?? '');
            if ($archiveId === '') $api->error('Missing archive_id', 400);
            $service->updateItemNote($userId, $id, $archiveId, $note);
            $api->ok(['message' => 'Note updated']);
        }

        default:
            $api->error('Unknown action: ' . $action, 400);
    }
} catch (InvalidArgumentException $e) {
    $api->error($e->getMessage(), 400);
} catch (RuntimeException $e) {
    $api->error($e->getMessage(), 409);
}
