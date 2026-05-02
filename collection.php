<?php
/**
 * collection.php — view a single collection.
 *
 * Two access modes:
 *   ?id=N             → owner view (requires auth, loads owner's own collection)
 *   ?u=user&s=slug    → public shareable view (anyone, collection must be public)
 *
 * Server-renders the initial payload so the page is crawlable and has
 * no flash of empty state.
 */

require_once __DIR__ . '/bootstrap.php';

$service = new CollectionService();
$context = new UserContext();
$current = $context->current();
$currentUser = (!empty($current) && empty($current['is_guest'])) ? $current : null;

$collection = null;
$items = [];
$ownerMode = false;
$owner = null;
$notFound = false;

if (isset($_GET['id'])) {
    if (!$currentUser) {
        header('Location: login.php?next=' . urlencode('collection.php?id=' . (int)$_GET['id']));
        exit;
    }
    $collection = $service->getForUser((int)$currentUser['id'], (int)$_GET['id']);
    if ($collection) {
        $items = $service->getItems((int)$collection['id']);
        $ownerMode = true;
        $owner = [
            'username' => $currentUser['username'],
            'display_name' => $currentUser['display_name'] ?: $currentUser['username'],
        ];
    } else {
        $notFound = true;
    }
} elseif (isset($_GET['u']) && isset($_GET['s'])) {
    $collection = $service->getPublicBySlug((string)$_GET['u'], (string)$_GET['s']);
    if ($collection) {
        $items = $service->getItems((int)$collection['id']);
        $service->trackView((int)$collection['id']);
        $owner = [
            'username' => $collection['owner_username'],
            'display_name' => $collection['owner_display_name'] ?: $collection['owner_username'],
        ];
        $ownerMode = $currentUser
            && (int)$currentUser['id'] === (int)$collection['user_id'];
    } else {
        $notFound = true;
    }
} else {
    $notFound = true;
}

try {
    $settingsService = new SettingsService();
    $site_settings = array_merge(
        ['siteName' => 'Archive Film Club', 'brandColor' => '#ff0000', 'accentColor' => '#065fd4', 'defaultTheme' => 'dark'],
        $settingsService->getSettings() ?: []
    );
} catch (Throwable $e) {
    $site_settings = ['siteName' => 'Archive Film Club', 'brandColor' => '#ff0000', 'accentColor' => '#065fd4', 'defaultTheme' => 'dark'];
}

$initialTheme = ($site_settings['defaultTheme'] ?? 'dark') === 'system' ? 'dark' : $site_settings['defaultTheme'];

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $collection
    ? $collection['name'] . ' · ' . $site_settings['siteName']
    : 'Collection · ' . $site_settings['siteName'];

$ogDescription = $collection && $collection['description']
    ? mb_substr((string)$collection['description'], 0, 200)
    : 'A curated collection on ' . $site_settings['siteName'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($initialTheme) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= esc($pageTitle) ?></title>
  <meta name="description" content="<?= esc($ogDescription) ?>">

  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= esc($collection['name'] ?? 'Collection') ?>">
  <meta property="og:description" content="<?= esc($ogDescription) ?>">
  <?php if ($collection && $collection['cover_thumbnail']): ?>
    <meta property="og:image" content="<?= esc($collection['cover_thumbnail']) ?>">
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="auth-styles.css">
  <style>
    :root {
      --brand-color: <?= esc($site_settings['brandColor']) ?>;
      --accent-color: <?= esc($site_settings['accentColor']) ?>;
    }
  </style>
  <script>
    (function() {
      var saved = localStorage.getItem('theme');
      var theme = saved || '<?= esc($initialTheme) ?>';
      if (theme === 'system') theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="collection-page">
    <?php if ($notFound): ?>
      <div class="auth-alert auth-alert--error" data-visible="true">
        This collection doesn't exist or isn't public.
      </div>
      <p><a href="collections.php">Back to your collections</a></p>
    <?php else: ?>

      <header class="collection-page-header">
        <?php if ($collection['cover_thumbnail']): ?>
          <div class="collection-page-cover"
               style="background-image:url('<?= esc($collection['cover_thumbnail']) ?>')"></div>
        <?php else: ?>
          <div class="collection-page-cover"></div>
        <?php endif; ?>

        <div style="flex:1; min-width:0;">
          <h1 class="collection-page-title"><?= esc($collection['name']) ?></h1>
          <p class="collection-page-meta">
            by <?= esc($owner['display_name']) ?>
            &middot; <?= (int)$collection['item_count'] ?> video<?= (int)$collection['item_count'] === 1 ? '' : 's' ?>
            <?php if ($collection['is_public']): ?>
              &middot; <span style="color:var(--color-success);">Public</span>
              &middot; <?= (int)$collection['view_count'] ?> view<?= (int)$collection['view_count'] === 1 ? '' : 's' ?>
            <?php else: ?>
              &middot; <span style="color:var(--color-text-tertiary);">Private</span>
            <?php endif; ?>
          </p>
          <?php if ($collection['description']): ?>
            <p class="collection-page-description"><?= esc($collection['description']) ?></p>
          <?php endif; ?>

          <?php if ($ownerMode): ?>
            <div style="margin-top:var(--space-3); display:flex; gap:var(--space-2); flex-wrap:wrap;">
              <button type="button" class="btn btn-secondary" data-edit-btn>Edit</button>
              <button type="button" class="btn btn-secondary" data-share-btn>Copy share link</button>
              <button type="button" class="btn btn-secondary" style="color:var(--color-error);" data-delete-btn>Delete collection</button>
            </div>
          <?php elseif ($collection['is_public']): ?>
            <div style="margin-top:var(--space-3);">
              <button type="button" class="btn btn-secondary" data-share-btn>Copy share link</button>
            </div>
          <?php endif; ?>
        </div>
      </header>

      <?php if (!$items): ?>
        <div class="collection-page-meta" style="text-align:center; padding:var(--space-8);">
          <?php if ($ownerMode): ?>
            This collection is empty. Open any video and tap "Save to collection" to add it.
          <?php else: ?>
            This collection is empty.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="collection-grid">
          <?php foreach ($items as $item): ?>
            <?php
              $thumbnail = $item['thumbnail'] ?: 'https://archive.org/services/img/' . $item['id'];
              $playerUrl = 'player.php?video=' . urlencode($item['id']);
            ?>
            <div class="collection-card" style="cursor:default;">
              <a href="<?= esc($playerUrl) ?>" style="display:contents;">
                <div class="collection-card-cover"
                     style="background-image:url('<?= esc($thumbnail) ?>')"></div>
                <div class="collection-card-body">
                  <h3 class="collection-card-name"><?= esc($item['title'] ?: $item['id']) ?></h3>
                  <div class="collection-card-meta">
                    <?= esc($item['creator'] ?: '') ?>
                  </div>
                </div>
              </a>
              <?php if ($ownerMode): ?>
                <button type="button" class="btn btn-ghost"
                        style="margin:var(--space-2); align-self:flex-end;"
                        data-remove-item
                        data-archive-id="<?= esc($item['id']) ?>">
                  Remove
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </main>

  <?php if ($collection): ?>
  <script type="module">
    import { CollectionService } from './src/js/services/CollectionService.js';

    const COLLECTION_ID = <?= (int)$collection['id'] ?>;
    const OWNER_MODE = <?= $ownerMode ? 'true' : 'false' ?>;
    const IS_PUBLIC = <?= $collection['is_public'] ? 'true' : 'false' ?>;
    <?php
      // Compute install base path so share URLs work in subdirectory deployments.
      $installBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    ?>
    const SHARE_URL = <?= json_encode($collection['is_public']
        ? $installBase . '/collection.php?u=' . $owner['username'] . '&s=' . $collection['slug']
        : null) ?>;

    // Share button
    document.querySelector('[data-share-btn]')?.addEventListener('click', async () => {
      if (!SHARE_URL) return alert('This collection is private. Make it public to share.');
      const url = window.location.origin + SHARE_URL;
      try {
        await navigator.clipboard.writeText(url);
        alert('Share link copied: ' + url);
      } catch {
        prompt('Copy this link:', url);
      }
    });

    if (OWNER_MODE) {
      // Delete
      document.querySelector('[data-delete-btn]')?.addEventListener('click', async () => {
        if (!confirm('Delete this collection? This cannot be undone.')) return;
        try {
          await CollectionService.delete(COLLECTION_ID);
          window.location.href = 'collections.php';
        } catch (err) {
          alert(err.message || 'Delete failed');
        }
      });

      // Edit (name + public toggle, simple prompt for now)
      document.querySelector('[data-edit-btn]')?.addEventListener('click', async () => {
        const currentName = <?= json_encode($collection['name']) ?>;
        const currentPublic = IS_PUBLIC;
        const newName = prompt('Collection name:', currentName);
        if (newName === null) return;
        const makePublic = confirm(currentPublic
          ? 'Keep this collection public? Click Cancel to make it private.'
          : 'Make this collection public? Click Cancel to keep it private.');
        try {
          await CollectionService.update(COLLECTION_ID, {
            name: newName.trim(),
            is_public: makePublic,
          });
          window.location.reload();
        } catch (err) {
          alert(err.message || 'Update failed');
        }
      });

      // Remove item
      document.querySelectorAll('[data-remove-item]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const archiveId = btn.dataset.archiveId;
          if (!confirm('Remove this video from the collection?')) return;
          try {
            await CollectionService.removeItem(COLLECTION_ID, archiveId);
            btn.closest('.collection-card').remove();
          } catch (err) {
            alert(err.message || 'Remove failed');
          }
        });
      });
    }
  </script>
  <?php endif; ?>
</body>
</html>
