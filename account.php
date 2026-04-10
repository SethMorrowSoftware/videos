<?php
/**
 * Account settings page.
 *
 * Requires an authenticated (non-guest) user. Server-side redirects guests
 * to login.php?next=account.php.
 */

require_once __DIR__ . '/bootstrap.php';

$context = new UserContext();
$current = $context->current();

if (!$current || !empty($current['is_guest'])) {
    header('Location: login.php?next=account.php');
    exit;
}

// Basic stats
$db = Database::getInstance();
$bookmarkCount = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?",
    [(int)$current['id']]
);
$historyCount = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM user_watch_history WHERE user_id = ?",
    [(int)$current['id']]
);

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
$currentUser = $current;

$roleLabel = ucfirst($current['role'] ?? 'viewer');
$createdAt = !empty($current['created_at']) ? date('M j, Y', strtotime($current['created_at'])) : '—';
$lastSeen = !empty($current['last_seen']) ? date('M j, Y g:i A', strtotime($current['last_seen'])) : '—';
$verified = !empty($current['email_verified_at']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Account · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="auth-styles.css">
  <style>
    :root {
      --brand-color: <?= htmlspecialchars($site_settings['brandColor'], ENT_QUOTES) ?>;
      --accent-color: <?= htmlspecialchars($site_settings['accentColor'], ENT_QUOTES) ?>;
    }
  </style>
  <script>
    (function() {
      var saved = localStorage.getItem('theme');
      var theme = saved || '<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>';
      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="account-layout">
    <section class="account-section">
      <h2>Profile</h2>
      <dl class="account-meta">
        <dt>Username</dt><dd><?= htmlspecialchars($current['username'], ENT_QUOTES) ?></dd>
        <dt>Display name</dt><dd><?= htmlspecialchars($current['display_name'] ?: $current['username'], ENT_QUOTES) ?></dd>
        <dt>Email</dt>
        <dd>
          <?= htmlspecialchars($current['email'] ?: '—', ENT_QUOTES) ?>
          <?php if ($verified): ?>
            <span style="color:var(--color-success); margin-left:8px;">✓ Verified</span>
          <?php else: ?>
            <span style="color:var(--color-warning); margin-left:8px;">Unverified</span>
          <?php endif; ?>
        </dd>
        <dt>Role</dt><dd><?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></dd>
        <dt>Member since</dt><dd><?= htmlspecialchars($createdAt, ENT_QUOTES) ?></dd>
        <dt>Last active</dt><dd><?= htmlspecialchars($lastSeen, ENT_QUOTES) ?></dd>
        <dt>Bookmarks</dt><dd><?= $bookmarkCount ?></dd>
        <dt>Watch history</dt><dd><?= $historyCount ?> video<?= $historyCount === 1 ? '' : 's' ?></dd>
      </dl>
    </section>

    <section class="account-section">
      <h2>Update profile</h2>
      <div class="auth-alert auth-alert--error" data-profile-error role="alert"></div>
      <div class="auth-alert auth-alert--success" data-profile-success role="status"></div>

      <form class="auth-form" data-profile-form novalidate>
        <div class="auth-field">
          <label for="display_name">Display name</label>
          <input id="display_name" name="display_name" type="text" maxlength="100"
                 value="<?= htmlspecialchars($current['display_name'] ?: '', ENT_QUOTES) ?>">
        </div>
        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email"
                 value="<?= htmlspecialchars($current['email'] ?: '', ENT_QUOTES) ?>">
        </div>
        <button type="submit" class="auth-submit">Save changes</button>
      </form>
    </section>

    <section class="account-section">
      <h2>Change password</h2>
      <div class="auth-alert auth-alert--error" data-pw-error role="alert"></div>
      <div class="auth-alert auth-alert--success" data-pw-success role="status"></div>

      <form class="auth-form" data-password-form novalidate>
        <div class="auth-field">
          <label for="oldPassword">Current password</label>
          <input id="oldPassword" name="oldPassword" type="password" autocomplete="current-password" required>
        </div>
        <div class="auth-field">
          <label for="newPassword">New password</label>
          <input id="newPassword" name="newPassword" type="password" autocomplete="new-password" minlength="8" required>
          <div class="field-hint">At least 8 characters.</div>
        </div>
        <button type="submit" class="auth-submit">Change password</button>
      </form>
    </section>

    <section class="account-section account-danger">
      <h2>Sign out</h2>
      <p style="color:var(--color-text-secondary); font-size:var(--font-size-sm); margin-bottom:var(--space-4);">
        Sign out on this device. Your data stays on the server and will be available when you sign back in.
      </p>
      <button type="button" class="auth-submit" style="background:var(--color-error);" data-logout-btn>
        Sign out
      </button>
    </section>
  </main>

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    function wire(formSelector, errorSelector, successSelector, submitHandler) {
      const form = document.querySelector(formSelector);
      const errorBox = document.querySelector(errorSelector);
      const successBox = document.querySelector(successSelector);
      if (!form) return;
      const submit = form.querySelector('button[type="submit"]');

      const clear = () => {
        errorBox.removeAttribute('data-visible');
        successBox.removeAttribute('data-visible');
      };
      const showErr = (m) => { errorBox.textContent = m; errorBox.setAttribute('data-visible', 'true'); };
      const showOk = (m) => { successBox.textContent = m; successBox.setAttribute('data-visible', 'true'); };

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clear();
        submit.disabled = true;
        try {
          const msg = await submitHandler(new FormData(form));
          showOk(msg || 'Saved');
        } catch (err) {
          showErr(err.message || 'Request failed');
        } finally {
          submit.disabled = false;
        }
      });
    }

    wire('[data-profile-form]', '[data-profile-error]', '[data-profile-success]', async (fd) => {
      await AuthService.updateProfile({
        display_name: String(fd.get('display_name') || '').trim(),
        email: String(fd.get('email') || '').trim(),
      });
      return 'Profile updated';
    });

    wire('[data-password-form]', '[data-pw-error]', '[data-pw-success]', async (fd) => {
      await AuthService.changePassword({
        oldPassword: String(fd.get('oldPassword') || ''),
        newPassword: String(fd.get('newPassword') || ''),
      });
      document.querySelector('[data-password-form]').reset();
      return 'Password changed';
    });

    document.querySelector('[data-logout-btn]')?.addEventListener('click', async () => {
      try {
        await AuthService.logout();
      } finally {
        window.location.href = 'index.php';
      }
    });
  </script>
</body>
</html>
