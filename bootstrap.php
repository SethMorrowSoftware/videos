<?php
/**
 * Application Bootstrap
 *
 * Single entrypoint for loading configuration, environment, autoloading,
 * and starting the session. Every PHP entrypoint (index.php, player.php,
 * admin.php, api/*.php, etc.) should require_once this file.
 *
 * No Composer required - this is a hand-rolled autoloader.
 */

if (defined('ARCHIVE_FILM_CLUB_BOOTSTRAPPED')) {
    return;
}
define('ARCHIVE_FILM_CLUB_BOOTSTRAPPED', true);
define('ARCHIVE_FILM_CLUB_ROOT', __DIR__);

// =====================================================
// .env LOADER
// =====================================================

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// =====================================================
// AUTOLOADER (PSR-4-ish, but hand-rolled)
// =====================================================
//
// Maps class names to directories. We keep it simple:
//   - No namespaces required (matches legacy code style)
//   - Looks in services/, services/Http/, services/Auth/, services/Mail/,
//     services/User/, services/Collection/, db/, cache/, admin/controllers/
//
// New code should drop files into these dirs and the class will load
// automatically with no require_once needed.

spl_autoload_register(function ($class) {
    // Strip any leading backslash
    $class = ltrim($class, '\\');

    $searchPaths = [
        __DIR__ . '/services/',
        __DIR__ . '/services/Http/',
        __DIR__ . '/services/Auth/',
        __DIR__ . '/services/Mail/',
        __DIR__ . '/services/User/',
        __DIR__ . '/services/Collection/',
        __DIR__ . '/db/',
        __DIR__ . '/cache/',
        __DIR__ . '/admin/controllers/',
    ];

    foreach ($searchPaths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// =====================================================
// SESSION
// =====================================================

/**
 * Compute the cookie path for this install. Scopes session + auth
 * cookies to the install directory so two installs on the same
 * domain (e.g. /films/ and /shorts/) don't stomp on each other, and
 * so links in a /films/ install don't leak cookies to a sibling
 * /shorts/ install. Used by bootstrap.php, UserContext, UserAuthService,
 * and AdminAuthService.
 */
function app_cookie_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $path = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // API endpoints live one level deeper; strip /api[/...] so cookies
    // are set at the install root and are visible everywhere in the app.
    if (preg_match('#^(.*)/api(/.*)?$#', $path, $m)) {
        $path = $m[1];
    }
    if ($path === '' || $path === false) return '/';
    return substr($path, -1) === '/' ? $path : $path . '/';
}

if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie for auth use
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_cookie_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// =====================================================
// GLOBAL CONFIG ACCESS
// =====================================================

/**
 * Fetch a value from the environment with a default.
 */
function env(string $key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    // Coerce common boolean strings
    $lower = strtolower($value);
    if ($lower === 'true') return true;
    if ($lower === 'false') return false;
    if ($lower === 'null') return null;
    return $value;
}

/**
 * Absolute path helper anchored at project root.
 */
function base_path(string $relative = ''): string {
    $relative = ltrim($relative, '/');
    return ARCHIVE_FILM_CLUB_ROOT . ($relative === '' ? '' : '/' . $relative);
}
