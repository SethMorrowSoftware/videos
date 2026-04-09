<?php
/**
 * Database Configuration
 *
 * For cPanel users: Edit the values below or use a .env file
 *
 * IMPORTANT: Keep this file secure and never commit with real credentials
 */

// Check if .env file exists and load it
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;

        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            $value = trim($value, '"\'');

            // Set as environment variable
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Get database configuration
 * Supports both .env file and direct configuration for cPanel users
 */
return [
    // Database connection settings
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_DATABASE') ?: 'archive_film_club',
    'username' => getenv('DB_USERNAME') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',

    // Cache TTL settings (in seconds)
    'cache' => [
        'search_ttl' => (int)(getenv('CACHE_SEARCH_TTL') ?: 1800),      // 30 minutes
        'metadata_ttl' => (int)(getenv('CACHE_METADATA_TTL') ?: 86400),  // 24 hours
        'thumbnail_ttl' => (int)(getenv('CACHE_THUMBNAIL_TTL') ?: 604800), // 7 days
        'settings_ttl' => (int)(getenv('CACHE_SETTINGS_TTL') ?: 3600),   // 1 hour
    ],

    // Feature flags
    'features' => [
        'thumbnail_caching' => filter_var(getenv('ENABLE_THUMBNAIL_CACHING') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'search_caching' => filter_var(getenv('ENABLE_SEARCH_CACHING') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'user_sessions' => filter_var(getenv('ENABLE_USER_SESSIONS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'api_logging' => filter_var(getenv('ENABLE_API_LOGGING') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    ],

    // Paths (use dirname() for clean paths without '..')
    'paths' => [
        'thumbnails' => getenv('THUMBNAIL_CACHE_PATH') ?: dirname(__DIR__) . '/thumbnails',
        'logs' => getenv('LOG_PATH') ?: dirname(__DIR__) . '/logs',
    ],
];
