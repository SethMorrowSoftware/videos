<?php
/**
 * Common <head> snippet — print the CSRF meta tag and the global JS
 * config needed by the early-loading scripts. Include this inside <head>
 * on every page that loads app/admin/auth JS.
 *
 * Caller is responsible for everything else (title, OG tags, stylesheets).
 */
if (function_exists('csrf_meta_tag')) {
    echo csrf_meta_tag() . "\n";
}
