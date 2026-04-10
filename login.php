<?php
/**
 * Sign in page.
 *
 * GET renders the form. The form POSTs to /api/auth/login.php via fetch()
 * and the JS redirects on success.
 */

require_once __DIR__ . '/bootstrap.php';

// If already logged in, bounce to the "next" URL (or home)
$context = new UserContext();
$current = $context->current();
if ($current && empty($current['is_guest'])) {
    $next = isset($_GET['next']) ? (string)$_GET['next'] : 'index.php';
    // Only allow relative redirects
    if (!preg_match('#^/?[a-z0-9_\-./?=&#]+$#i', $next)) {
        $next = 'index.php';
    }
    header('Location: ' . $next);
    exit;
}

// Guest data preview, so we can show an honest merge prompt on login too
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
        // non-fatal
    }
}
$hasGuestData = ($guestBookmarks + $guestHistory) > 0;

// Load site settings for branding
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
  <title>Sign in · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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
        <h1>Welcome back</h1>
        <p>Sign in to sync your bookmarks and watch history.</p>
      </div>

      <div class="auth-alert auth-alert--error" data-error role="alert"></div>

      <?php if ($hasGuestData): ?>
        <div class="auth-merge-box">
          <strong>We noticed you have some saved data on this device.</strong>
          <div style="margin-top:8px;">
            <?= $guestBookmarks ?> bookmark<?= $guestBookmarks === 1 ? '' : 's' ?>
            and <?= $guestHistory ?> video<?= $guestHistory === 1 ? '' : 's' ?> in watch history.
          </div>
          <label class="auth-checkbox" style="margin-top:8px;">
            <input type="checkbox" data-merge-checkbox checked>
            Move these into my account when I sign in
          </label>
        </div>
      <?php endif; ?>

      <form class="auth-form" data-login-form novalidate>
        <div class="auth-field">
          <label for="identifier">Username or email</label>
          <input id="identifier" name="identifier" type="text" autocomplete="username" required>
        </div>

        <div class="auth-field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <label class="auth-checkbox">
          <input type="checkbox" name="remember" value="1" checked>
          Remember me for 30 days
        </label>

        <button type="submit" class="auth-submit">Sign in</button>
      </form>

      <div class="auth-links">
        <a href="register.php">Create account</a>
        <a href="forgot-password.php">Forgot password?</a>
      </div>
    </div>
  </main>

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-login-form]');
    const errorBox = document.querySelector('[data-error]');
    const submit = form.querySelector('button[type="submit"]');
    const mergeCheckbox = document.querySelector('[data-merge-checkbox]');

    function showError(msg) {
      errorBox.textContent = msg;
      errorBox.setAttribute('data-visible', 'true');
    }
    function clearError() {
      errorBox.removeAttribute('data-visible');
      errorBox.textContent = '';
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearError();
      submit.disabled = true;
      submit.textContent = 'Signing in…';

      try {
        const fd = new FormData(form);
        await AuthService.login({
          identifier: String(fd.get('identifier') || '').trim(),
          password: String(fd.get('password') || ''),
          remember: fd.get('remember') === '1',
          mergeGuest: mergeCheckbox ? mergeCheckbox.checked : true,
        });

        // Redirect to ?next or index
        const params = new URLSearchParams(window.location.search);
        const next = params.get('next') || 'index.php';
        window.location.href = next;
      } catch (err) {
        showError(err.message || 'Sign in failed');
        submit.disabled = false;
        submit.textContent = 'Sign in';
      }
    });
  </script>
</body>
</html>
