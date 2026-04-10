<?php
/**
 * Password reset page (landing page for links emailed out by
 * api/auth/forgot-password.php).
 *
 * Takes ?token=... from the query string and POSTs it alongside the new
 * password to /api/auth/reset-password.
 */

require_once __DIR__ . '/bootstrap.php';

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
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Set new password · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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
      if (theme === 'system') theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <h1>Choose a new password</h1>
      </div>

      <?php if ($token === ''): ?>
        <div class="auth-alert auth-alert--error" data-visible="true">
          This reset link is missing a token. Please request a new reset email.
        </div>
        <div class="auth-links">
          <a href="forgot-password.php">Request new link</a>
        </div>
      <?php else: ?>
        <div class="auth-alert auth-alert--error" data-error role="alert"></div>
        <div class="auth-alert auth-alert--success" data-success role="status"></div>

        <form class="auth-form" data-reset-form novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <div class="auth-field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
            <div class="field-hint">At least 8 characters.</div>
          </div>
          <div class="auth-field">
            <label for="confirm">Confirm password</label>
            <input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="8" required>
          </div>
          <button type="submit" class="auth-submit">Set password</button>
        </form>

        <div class="auth-links">
          <a href="login.php">Back to sign in</a>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <?php if ($token !== ''): ?>
  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-reset-form]');
    const errorBox = document.querySelector('[data-error]');
    const successBox = document.querySelector('[data-success]');
    const submit = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errorBox.removeAttribute('data-visible');
      successBox.removeAttribute('data-visible');

      const fd = new FormData(form);
      const password = String(fd.get('password') || '');
      const confirm = String(fd.get('confirm') || '');

      if (password !== confirm) {
        errorBox.textContent = "Passwords don't match.";
        errorBox.setAttribute('data-visible', 'true');
        return;
      }

      submit.disabled = true;
      try {
        await AuthService.resetPassword({
          token: String(fd.get('token') || ''),
          password,
        });
        successBox.textContent = 'Password set. Redirecting to sign in…';
        successBox.setAttribute('data-visible', 'true');
        setTimeout(() => { window.location.href = 'login.php'; }, 1200);
      } catch (err) {
        errorBox.textContent = err.message || 'Reset failed';
        errorBox.setAttribute('data-visible', 'true');
        submit.disabled = false;
      }
    });
  </script>
  <?php endif; ?>
</body>
</html>
