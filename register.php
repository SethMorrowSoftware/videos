<?php
/**
 * Registration page.
 *
 * GET renders the form. The form POSTs to /api/auth/register.php via fetch().
 * If the visitor has guest data (bookmarks, history) we show a prompt asking
 * whether to merge it into the new account before submitting.
 */

require_once __DIR__ . '/bootstrap.php';

$context = new UserContext();
$current = $context->current();
if ($current && empty($current['is_guest'])) {
    header('Location: index.php');
    exit;
}

// Guest data preview, so we can show an honest merge prompt
$guestBookmarks = 0;
$guestHistory = 0;
if ($current && !empty($current['is_guest'])) {
    try {
        $db = Database::getInstance();
        $guestBookmarks = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?",
            [(int)$current['id']]
        );
        $guestHistory = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM user_watch_history WHERE user_id = ?",
            [(int)$current['id']]
        );
    } catch (Throwable $e) {
        // Fine — prompt just won't appear
    }
}
$hasGuestData = ($guestBookmarks + $guestHistory) > 0;

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
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Create account · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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

  <main class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <h1>Create your account</h1>
        <p>Keep your bookmarks and history synced across devices.</p>
      </div>

      <div class="auth-alert auth-alert--error" data-error role="alert"></div>

      <?php if ($hasGuestData): ?>
        <div class="auth-merge-box" data-merge-box>
          <strong>We noticed you have some saved data.</strong>
          <div style="margin-top:8px;">
            <?= $guestBookmarks ?> bookmark<?= $guestBookmarks === 1 ? '' : 's' ?>
            and <?= $guestHistory ?> video<?= $guestHistory === 1 ? '' : 's' ?> in watch history.
          </div>
          <label class="auth-checkbox" style="margin-top:8px;">
            <input type="checkbox" data-merge-checkbox checked>
            Move these into my new account
          </label>
        </div>
      <?php endif; ?>

      <form class="auth-form" data-register-form novalidate>
        <div class="auth-field">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" autocomplete="username"
                 minlength="3" maxlength="50" required
                 pattern="[a-zA-Z0-9_\-]+">
          <div class="field-hint">Letters, numbers, underscore and hyphen only.</div>
        </div>

        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required>
        </div>

        <div class="auth-field">
          <label for="display_name">Display name <span style="color:var(--color-text-tertiary); font-weight:normal;">(optional)</span></label>
          <input id="display_name" name="display_name" type="text" autocomplete="nickname" maxlength="100">
        </div>

        <div class="auth-field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password"
                 minlength="8" required>
          <div class="field-hint">At least 8 characters.</div>
        </div>

        <button type="submit" class="auth-submit">Create account</button>
      </form>

      <div class="auth-links">
        <span>Already have an account?</span>
        <a href="login.php">Sign in</a>
      </div>
    </div>
  </main>

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-register-form]');
    const errorBox = document.querySelector('[data-error]');
    const submit = form.querySelector('button[type="submit"]');
    const mergeCheckbox = document.querySelector('[data-merge-checkbox]');

    function showError(msg) {
      errorBox.textContent = msg;
      errorBox.setAttribute('data-visible', 'true');
    }
    function showFieldErrors(errors) {
      const parts = [];
      if (errors && typeof errors === 'object') {
        for (const [field, msg] of Object.entries(errors)) {
          parts.push(`${field}: ${msg}`);
        }
      }
      showError(parts.length ? parts.join('  ') : 'Validation failed');
    }
    function clearError() {
      errorBox.removeAttribute('data-visible');
      errorBox.textContent = '';
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearError();
      submit.disabled = true;
      submit.textContent = 'Creating account…';

      const fd = new FormData(form);
      const payload = {
        username: String(fd.get('username') || '').trim(),
        email: String(fd.get('email') || '').trim(),
        display_name: String(fd.get('display_name') || '').trim(),
        password: String(fd.get('password') || ''),
        mergeGuest: mergeCheckbox ? mergeCheckbox.checked : true
      };

      try {
        await AuthService.register(payload);
        window.location.href = 'index.php';
      } catch (err) {
        if (err && err.errors) {
          showFieldErrors(err.errors);
        } else {
          showError(err.message || 'Registration failed');
        }
        submit.disabled = false;
        submit.textContent = 'Create account';
      }
    });
  </script>
</body>
</html>
