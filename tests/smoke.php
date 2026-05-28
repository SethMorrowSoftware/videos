<?php
/**
 * Minimal no-database smoke test.
 *
 * Boots bootstrap.php (env loader, hand-rolled autoloader, session, CSRF
 * helpers) and then force-loads every service/db/cache class so PHP has to
 * resolve inheritance, interfaces and traits. This catches fatal *link*
 * errors (a missing parent class, a trait conflict, an abstract-method
 * signature mismatch, a duplicated symbol across files) that a per-file
 * `php -l` syntax check cannot see.
 *
 * It deliberately does NOT connect to a database: classes are only loaded,
 * never instantiated, so no MySQL server or network access is required.
 * That keeps it runnable in CI and locally with no fixtures:
 *
 *     php tests/smoke.php
 *
 * Exit code 0 = everything loaded; non-zero = something failed (details on
 * stderr).
 */

error_reporting(E_ALL);
@ini_set('display_errors', '1');

$root = dirname(__DIR__);
$failures = [];

// ---------------------------------------------------------------------------
// 1) bootstrap.php must load without a fatal error.
// ---------------------------------------------------------------------------
try {
    require $root . '/bootstrap.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: bootstrap.php failed to load: {$e->getMessage()}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2) The helper functions bootstrap.php promises must exist.
// ---------------------------------------------------------------------------
$helpers = [
    'env', 'base_path', 'is_https', 'safe_host', 'safe_base_url',
    'app_cookie_path', 'csrf_token', 'csrf_verify', 'csrf_meta_tag',
];
foreach ($helpers as $fn) {
    if (!function_exists($fn)) {
        $failures[] = "bootstrap helper missing: {$fn}()";
    }
}

// The autoloader must have been registered.
if (!defined('ARCHIVE_FILM_CLUB_BOOTSTRAPPED')) {
    $failures[] = 'ARCHIVE_FILM_CLUB_BOOTSTRAPPED was not defined by bootstrap.php';
}

// ---------------------------------------------------------------------------
// 3) Force-load every class so PHP links its parents/interfaces/traits.
//    We discover classes by filename (the autoloader maps ClassName.php ->
//    ClassName), and only touch files that actually declare that symbol so
//    procedural files (db/config.php, admin/controllers/AdminBootstrap.php,
//    which run DB code on include) are skipped.
// ---------------------------------------------------------------------------
$classDirs = ['/services', '/db', '/cache', '/admin/controllers'];
$loaded = 0;

foreach ($classDirs as $dir) {
    $base = $root . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $name = $file->getBasename('.php');
        $src = file_get_contents($file->getPathname());

        // Only treat it as a loadable type if it declares `class|interface|
        // trait <Name>` matching the filename.
        $pattern = '/^\s*(?:abstract\s+|final\s+)*(?:class|interface|trait)\s+'
                 . preg_quote($name, '/') . '\b/m';
        if (!preg_match($pattern, $src)) {
            continue;
        }

        try {
            // autoload=true forces the file to be required and linked.
            $exists = class_exists($name, true)
                   || interface_exists($name, true)
                   || trait_exists($name, true);
            if (!$exists) {
                $failures[] = "could not load declared type: {$name} ({$file->getPathname()})";
            } else {
                $loaded++;
            }
        } catch (\Throwable $e) {
            $failures[] = "fatal loading {$name}: {$e->getMessage()} @ "
                        . "{$e->getFile()}:{$e->getLine()}";
        }
    }
}

if ($loaded === 0) {
    $failures[] = 'no classes were loaded — discovery is broken';
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if ($failures) {
    fwrite(STDERR, "SMOKE TEST FAILED (" . count($failures) . "):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

fwrite(STDOUT, "smoke OK: bootstrap loaded, " . count($helpers)
             . " helpers present, {$loaded} classes linked, no DB required\n");
exit(0);
