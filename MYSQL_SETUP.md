# MySQL Setup Guide for Archive Film Club

This guide explains how to set up MySQL for Archive Film Club on cPanel shared hosting.

## Quick Start

1. **Run the installer**: Visit `https://yourdomain.com/install.php`
2. Follow the step-by-step setup wizard
3. Delete `install.php` after setup is complete

---

## Manual Setup (Alternative)

### Step 1: Create Database in cPanel

1. Log in to your cPanel account
2. Go to **MySQL Databases**
3. Create a new database (e.g., `yourusername_filmclub`)
4. Create a new MySQL user with a strong password
5. Add the user to the database with **ALL PRIVILEGES**

### Step 2: Configure the Application

Create a `.env` file in your site root:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=yourusername_filmclub
DB_USERNAME=yourusername_filmuser
DB_PASSWORD=your_secure_password
```

### Step 3: Run the Database Migration

Option A - Via web browser:
- Visit `install.php` and follow the steps

Option B - Via command line (SSH access required):
```bash
cd /home/yourusername/public_html/videos
mysql -u yourusername_filmuser -p yourusername_filmclub < db/migrations/001_initial_schema.sql
```

### Step 4: Create Admin User

The installer will prompt you to create an admin account. If doing manually:

```php
<?php
require_once 'services/AdminAuthService.php';
$auth = new AdminAuthService();
$auth->createUser('admin', 'your_password', 'admin@example.com');
```

---

## Directory Structure

After setup, your directory structure should look like:

```
/videos/
├── api/                    # API endpoints
│   ├── search.php
│   ├── metadata.php
│   ├── thumbnail.php
│   └── ...
├── cache/                  # Cache management classes
│   ├── CacheManager.php
│   └── ...
├── cron/                   # Scheduled tasks
│   ├── cache_cleanup.php
│   └── cache_warmer.php
├── db/                     # Database layer
│   ├── config.php
│   ├── Database.php
│   └── migrations/
├── services/               # Business logic
│   ├── ArchiveOrgService.php
│   ├── SettingsService.php
│   └── ...
├── thumbnails/             # Cached thumbnails
├── .env                    # Database configuration
├── index.php
├── admin.php
└── ...
```

---

## Cron Jobs (Optional but Recommended)

Add these cron jobs in cPanel for optimal performance:

### Cache Cleanup (Hourly)
```
0 * * * * php /home/yourusername/public_html/videos/cron/cache_cleanup.php >> /home/yourusername/logs/cache_cleanup.log 2>&1
```

### Cache Warmer (Every 30 minutes)
```
*/30 * * * * php /home/yourusername/public_html/videos/cron/cache_warmer.php >> /home/yourusername/logs/cache_warmer.log 2>&1
```

---

## Feature Flags

You can enable/disable features in your `.env` file:

```ini
# Disable thumbnail caching (saves disk space)
ENABLE_THUMBNAIL_CACHING=false

# Disable search caching (always fresh results)
ENABLE_SEARCH_CACHING=false

# Disable user session tracking
ENABLE_USER_SESSIONS=false

# Disable API logging (saves database space)
ENABLE_API_LOGGING=false
```

---

## Cache TTL Settings

Adjust cache durations in `.env`:

```ini
# Search results: 30 minutes (1800 seconds)
CACHE_SEARCH_TTL=1800

# Video metadata: 24 hours (86400 seconds)
CACHE_METADATA_TTL=86400

# Thumbnails: 7 days (604800 seconds)
CACHE_THUMBNAIL_TTL=604800

# Site settings: 1 hour (3600 seconds)
CACHE_SETTINGS_TTL=3600
```

---

## Backward Compatibility

The application maintains full backward compatibility:

- **Without MySQL**: Falls back to JSON files (`site-settings.json`, `recommendations.json`, etc.)
- **Legacy Admin**: If no database admin users exist, uses the simple password authentication
- **Both systems**: You can use both MySQL and JSON simultaneously during migration

---

## Troubleshooting

### Database Connection Failed
- Verify credentials in `.env`
- Check that the database user has proper permissions
- Ensure `localhost` is the correct host (some hosts use `127.0.0.1`)

### Permission Errors
- `.env` file should be readable by PHP (644)
- `thumbnails/` directory should be writable (755)
- JSON files should be writable for fallback (644)

### Admin Login Not Working
- Clear browser cookies/session
- Check if admin user exists in database
- Verify password hash was stored correctly

### Cache Not Working
- Check if cache tables were created
- Verify database connection
- Check PHP error logs for issues

---

## Performance Tips

1. **Enable OPcache**: PHP OPcache significantly improves performance
2. **Use cron jobs**: Automated cache warming prevents cold starts
3. **Monitor cache hit rates**: Check admin stats to ensure caching is effective
4. **Adjust TTLs**: Increase cache TTLs if your content changes infrequently

---

## Security Notes

1. Delete `install.php` after setup
2. Never commit `.env` to version control
3. Use strong passwords for database and admin
4. Keep the `thumbnails/` directory protected with the included `.htaccess`
5. Regularly update PHP and MySQL

---

## Support

For issues or questions:
- Check the `IMPLEMENTATION_PLAN.md` for technical details
- Review PHP error logs for debugging
- Ensure all required PHP extensions are enabled (PDO, pdo_mysql, json, gd)
