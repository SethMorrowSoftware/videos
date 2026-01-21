<?php
/**
 * Archive Film Club - Enhanced Admin Panel
 * Manage site settings, featured sections, and content curation
 */

// Simple password protection (change this!)
$ADMIN_PASSWORD = 'filmclub2024';

session_start();

// Handle login
if (isset($_POST['password'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $login_error = 'Invalid password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check if logged in
$is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Load current recommendations
$recommendations_file = __DIR__ . '/recommendations.json';
$current_recommendations = [];
$recommendations_data = ['enabled' => true, 'title' => 'Staff Picks', 'videos' => []];
if (file_exists($recommendations_file)) {
    $content = file_get_contents($recommendations_file);
    $data = json_decode($content, true);
    if ($data) {
        $recommendations_data = $data;
        if (isset($data['videos'])) {
            $current_recommendations = $data['videos'];
        }
    }
}

// Load site settings
$settings_file = __DIR__ . '/site-settings.json';
$site_settings = [
    'siteName' => 'Archive Film Club',
    'tagline' => 'Discover classic films from Archive.org',
    'brandColor' => '#ff0000',
    'accentColor' => '#065fd4',
    'defaultTheme' => 'dark',
    'enableThemeToggle' => true,
    'headerStyle' => 'default',
    'cardStyle' => 'modern',
    'showDownloadCount' => true,
    'showCreator' => true,
    'showDate' => true,
    'enableBookmarks' => false,
    'enableWatchHistory' => true,
    'defaultCollection' => 'all_videos',
    'defaultSort' => 'downloads'
];
if (file_exists($settings_file)) {
    $content = file_get_contents($settings_file);
    $data = json_decode($content, true);
    if ($data) {
        $site_settings = array_merge($site_settings, $data);
    }
}

// Load featured sections
$sections_file = __DIR__ . '/featured-sections.json';
$featured_sections = [];
if (file_exists($sections_file)) {
    $content = file_get_contents($sections_file);
    $data = json_decode($content, true);
    if ($data && isset($data['sections'])) {
        $featured_sections = $data['sections'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Archive Film Club</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0a0a0b;
            --bg-secondary: #111113;
            --bg-tertiary: #1a1a1d;
            --bg-elevated: #242428;
            --bg-hover: #2a2a2f;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
            --text-tertiary: #71717a;
            --border-color: #27272a;
            --border-hover: #3f3f46;
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.1);
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.1);
            --purple: #8b5cf6;
            --pink: #ec4899;
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.5);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* Login Form */
        .login-container {
            max-width: 420px;
            margin: 80px auto;
            padding: 40px;
        }

        .login-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 40px;
            box-shadow: var(--shadow-lg);
        }

        .login-logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-logo-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--purple) 100%);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 28px;
        }

        .login-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .login-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .form-input::placeholder {
            color: var(--text-tertiary);
        }

        .login-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-hover);
            border-color: var(--border-hover);
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            padding: 8px 12px;
        }

        .btn-ghost:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .btn-full { width: 100%; }
        .btn-lg { padding: 14px 24px; font-size: 15px; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            border-radius: var(--radius-md);
        }

        /* Admin Layout */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .admin-sidebar {
            width: 260px;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 100;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-primary);
        }

        .sidebar-logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--purple) 100%);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .sidebar-logo-text {
            font-weight: 600;
            font-size: 16px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            overflow-y: auto;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0 12px;
            margin-bottom: 8px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.15s;
            cursor: pointer;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
        }

        .nav-item:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.15);
            color: var(--accent);
        }

        .nav-item-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.8;
        }

        .nav-item-badge {
            margin-left: auto;
            background: var(--accent);
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid var(--border-color);
        }

        /* Main Content */
        .admin-main {
            flex: 1;
            margin-left: 260px;
            min-height: 100vh;
        }

        .admin-header {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .header-title {
            font-size: 20px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .admin-content {
            padding: 32px;
        }

        /* Panel/Tab Content */
        .panel {
            display: none;
        }

        .panel.active {
            display: block;
        }

        /* Cards */
        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
        }

        .card-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .card-body {
            padding: 24px;
        }

        .card-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Grid Layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        @media (max-width: 1024px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }

        /* Search Section */
        .search-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
        }

        @media (max-width: 1200px) {
            .search-layout { grid-template-columns: 1fr; }
        }

        .search-box {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
        }

        /* Results Grid */
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
        }

        .video-card {
            background: var(--bg-tertiary);
            border-radius: var(--radius-lg);
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
            position: relative;
        }

        .video-card:hover {
            transform: translateY(-4px);
            border-color: var(--accent);
            box-shadow: var(--shadow-md);
        }

        .video-card.selected {
            border-color: var(--success);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
        }

        .video-card-thumb {
            aspect-ratio: 16/9;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
        }

        .video-card-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .video-card:hover .video-card-thumb img {
            transform: scale(1.05);
        }

        .video-card-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--success);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .video-card-content {
            padding: 12px;
        }

        .video-card-title {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        }

        .video-card-meta {
            font-size: 12px;
            color: var(--text-tertiary);
        }

        /* Selected Videos Panel */
        .selected-panel {
            position: sticky;
            top: 90px;
        }

        .selected-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .selected-count {
            background: var(--success);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .selected-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .selected-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            cursor: grab;
            transition: all 0.15s;
        }

        .selected-item:hover {
            border-color: var(--border-hover);
        }

        .selected-item.dragging {
            opacity: 0.5;
            border-color: var(--accent);
        }

        .selected-item-drag {
            color: var(--text-tertiary);
            cursor: grab;
        }

        .selected-item-thumb {
            width: 64px;
            height: 36px;
            border-radius: var(--radius-sm);
            overflow: hidden;
            flex-shrink: 0;
        }

        .selected-item-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .selected-item-info {
            flex: 1;
            min-width: 0;
        }

        .selected-item-title {
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .selected-item-id {
            font-size: 11px;
            color: var(--text-tertiary);
        }

        .selected-item-remove {
            background: transparent;
            border: none;
            color: var(--text-tertiary);
            cursor: pointer;
            padding: 6px;
            border-radius: var(--radius-sm);
            transition: all 0.15s;
        }

        .selected-item-remove:hover {
            background: var(--danger-bg);
            color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-tertiary);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state-title {
            font-size: 16px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .empty-state-text {
            font-size: 14px;
        }

        /* Settings Form */
        .settings-section {
            margin-bottom: 32px;
        }

        .settings-section-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .settings-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .settings-row { grid-template-columns: 1fr; }
        }

        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .color-picker {
            width: 48px;
            height: 48px;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            padding: 0;
            overflow: hidden;
        }

        .color-picker::-webkit-color-swatch-wrapper {
            padding: 0;
        }

        .color-picker::-webkit-color-swatch {
            border: none;
            border-radius: var(--radius-md);
        }

        .color-value {
            font-family: monospace;
            font-size: 14px;
            color: var(--text-secondary);
        }

        /* Toggle Switch */
        .toggle-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
        }

        .toggle-label {
            font-size: 14px;
        }

        .toggle-description {
            font-size: 12px;
            color: var(--text-tertiary);
            margin-top: 2px;
        }

        .toggle {
            position: relative;
            width: 48px;
            height: 26px;
            flex-shrink: 0;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--bg-hover);
            border-radius: 26px;
            transition: 0.2s;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.2s;
        }

        .toggle input:checked + .toggle-slider {
            background: var(--success);
        }

        .toggle input:checked + .toggle-slider::before {
            transform: translateX(22px);
        }

        /* Select Dropdown */
        .form-select {
            width: 100%;
            padding: 12px 40px 12px 16px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            appearance: none;
            cursor: pointer;
            transition: all 0.2s;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='%2371717a' viewBox='0 0 24 24'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--accent);
        }

        /* Save Status */
        .save-status {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            animation: fadeIn 0.3s ease;
        }

        .save-status.success {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .save-status.error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 48px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-color);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .pagination button {
            padding: 8px 16px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            transition: all 0.2s;
        }

        .pagination button:hover:not(:disabled) {
            background: var(--bg-hover);
            border-color: var(--border-hover);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Preview Section */
        .preview-section {
            background: var(--bg-tertiary);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-top: 24px;
        }

        .preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .preview-title {
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .preview-frame {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .preview-iframe {
            width: 100%;
            height: 400px;
            border: none;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat-card-label {
            font-size: 13px;
            color: var(--text-tertiary);
        }

        .stat-card-icon {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .stat-card-icon.blue { background: rgba(59, 130, 246, 0.15); color: var(--accent); }
        .stat-card-icon.green { background: var(--success-bg); color: var(--success); }
        .stat-card-icon.purple { background: rgba(139, 92, 246, 0.15); color: var(--purple); }
        .stat-card-icon.orange { background: var(--warning-bg); color: var(--warning); }

        .stat-card-value {
            font-size: 28px;
            font-weight: 700;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .admin-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }

            .admin-sidebar.open {
                transform: translateX(0);
            }

            .admin-main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: flex !important;
            }
        }

        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            padding: 8px;
            border-radius: var(--radius-sm);
        }

        .mobile-menu-btn:hover {
            background: var(--bg-hover);
        }

        /* Section Divider */
        .divider {
            height: 1px;
            background: var(--border-color);
            margin: 24px 0;
        }

        /* Links */
        .link {
            color: var(--accent);
            text-decoration: none;
            font-size: 14px;
        }

        .link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php if (!$is_logged_in): ?>
    <!-- Login Form -->
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <div class="login-logo-icon">🎬</div>
                <h1 class="login-title">Admin Panel</h1>
                <p class="login-subtitle">Archive Film Club</p>
            </div>
            <?php if (isset($login_error)): ?>
            <div class="login-error">
                <span>⚠️</span> <?= htmlspecialchars($login_error) ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Enter admin password" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-full btn-lg">Login</button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- Admin Panel -->
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="index.php" target="_blank" class="sidebar-logo">
                    <div class="sidebar-logo-icon">🎬</div>
                    <span class="sidebar-logo-text">Film Club</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Content</div>
                    <button class="nav-item active" data-panel="staff-picks">
                        <span class="nav-item-icon">⭐</span>
                        Staff Picks
                        <span class="nav-item-badge" id="navVideoCount"><?= count($current_recommendations) ?></span>
                    </button>
                    <button class="nav-item" data-panel="sections">
                        <span class="nav-item-icon">📂</span>
                        Featured Sections
                    </button>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="nav-item" data-panel="site-settings">
                        <span class="nav-item-icon">⚙️</span>
                        Site Settings
                    </button>
                    <button class="nav-item" data-panel="appearance">
                        <span class="nav-item-icon">🎨</span>
                        Appearance
                    </button>
                    <button class="nav-item" data-panel="display">
                        <span class="nav-item-icon">📺</span>
                        Display Options
                    </button>
                </div>
            </nav>

            <div class="sidebar-footer">
                <a href="index.php" target="_blank" class="btn btn-secondary btn-full" style="margin-bottom: 8px;">
                    <span>👁️</span> View Site
                </a>
                <a href="?logout=1" class="btn btn-ghost btn-full">
                    <span>🚪</span> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">
                        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12h18M3 6h18M3 18h18"/>
                        </svg>
                    </button>
                    <h1 class="header-title" id="panelTitle">Staff Picks</h1>
                </div>
                <div class="header-actions">
                    <button class="btn btn-success" id="globalSaveBtn" onclick="saveCurrentPanel()">
                        <span>💾</span> Save Changes
                    </button>
                </div>
            </header>

            <div class="admin-content">
                <!-- Staff Picks Panel -->
                <div class="panel active" id="panel-staff-picks">
                    <div class="search-layout">
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title">Search Archive.org</h2>
                                    <p class="card-subtitle">Find videos to feature on your site</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="search-box">
                                    <div class="search-input-wrapper">
                                        <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="11" cy="11" r="8"/>
                                            <path d="M21 21l-4.35-4.35"/>
                                        </svg>
                                        <input type="text" class="search-input" id="searchInput" placeholder="Search movies, shows, documentaries...">
                                    </div>
                                    <button class="btn btn-primary" onclick="searchVideos()">
                                        Search
                                    </button>
                                </div>

                                <div id="searchResults">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🔍</div>
                                        <div class="empty-state-title">Search for videos</div>
                                        <p class="empty-state-text">Click on videos to add them to your Staff Picks</p>
                                    </div>
                                </div>

                                <div id="pagination" class="pagination" style="display: none;"></div>
                            </div>
                        </div>

                        <div class="selected-panel">
                            <div class="card">
                                <div class="card-header">
                                    <div>
                                        <h2 class="card-title">Selected Videos</h2>
                                        <p class="card-subtitle">Drag to reorder</p>
                                    </div>
                                    <span class="selected-count" id="selectedCount"><?= count($current_recommendations) ?></span>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label class="form-label">Section Title</label>
                                        <input type="text" class="form-input" id="sectionTitle" value="<?= htmlspecialchars($recommendations_data['title'] ?? 'Staff Picks') ?>" placeholder="Staff Picks">
                                    </div>

                                    <div class="toggle-wrapper">
                                        <div>
                                            <div class="toggle-label">Show on homepage</div>
                                            <div class="toggle-description">Display this section to visitors</div>
                                        </div>
                                        <label class="toggle">
                                            <input type="checkbox" id="enabledToggle" <?= ($recommendations_data['enabled'] ?? true) ? 'checked' : '' ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>

                                    <div class="divider"></div>

                                    <div id="selectedList" class="selected-list">
                                        <!-- Selected videos will appear here -->
                                    </div>

                                    <div id="staffPicksStatus"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Site Settings Panel -->
                <div class="panel" id="panel-site-settings">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Site Settings</h2>
                                <p class="card-subtitle">Configure basic site information</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <span>🏠</span> General
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Site Name</label>
                                        <input type="text" class="form-input" id="siteName" value="<?= htmlspecialchars($site_settings['siteName']) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tagline</label>
                                        <input type="text" class="form-input" id="siteTagline" value="<?= htmlspecialchars($site_settings['tagline']) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <span>🔧</span> Default Behavior
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Default Collection</label>
                                        <select class="form-select" id="defaultCollection">
                                            <option value="all_videos" <?= $site_settings['defaultCollection'] === 'all_videos' ? 'selected' : '' ?>>All Videos</option>
                                            <option value="feature_films" <?= $site_settings['defaultCollection'] === 'feature_films' ? 'selected' : '' ?>>Feature Films</option>
                                            <option value="classic_tv" <?= $site_settings['defaultCollection'] === 'classic_tv' ? 'selected' : '' ?>>Classic TV</option>
                                            <option value="animationandcartoons" <?= $site_settings['defaultCollection'] === 'animationandcartoons' ? 'selected' : '' ?>>Animation & Cartoons</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Default Sort</label>
                                        <select class="form-select" id="defaultSort">
                                            <option value="downloads" <?= $site_settings['defaultSort'] === 'downloads' ? 'selected' : '' ?>>Most Downloaded</option>
                                            <option value="date" <?= $site_settings['defaultSort'] === 'date' ? 'selected' : '' ?>>Date (Newest)</option>
                                            <option value="title" <?= $site_settings['defaultSort'] === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
                                            <option value="relevance" <?= $site_settings['defaultSort'] === 'relevance' ? 'selected' : '' ?>>Relevance</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div id="siteSettingsStatus"></div>
                        </div>
                    </div>
                </div>

                <!-- Appearance Panel -->
                <div class="panel" id="panel-appearance">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Appearance</h2>
                                <p class="card-subtitle">Customize colors and theme</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <span>🎨</span> Colors
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Brand Color</label>
                                        <div class="color-picker-wrapper">
                                            <input type="color" class="color-picker" id="brandColor" value="<?= htmlspecialchars($site_settings['brandColor']) ?>">
                                            <span class="color-value" id="brandColorValue"><?= htmlspecialchars($site_settings['brandColor']) ?></span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Accent Color</label>
                                        <div class="color-picker-wrapper">
                                            <input type="color" class="color-picker" id="accentColor" value="<?= htmlspecialchars($site_settings['accentColor']) ?>">
                                            <span class="color-value" id="accentColorValue"><?= htmlspecialchars($site_settings['accentColor']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <span>🌗</span> Theme
                                </div>
                                <div class="settings-row">
                                    <div class="form-group">
                                        <label class="form-label">Default Theme</label>
                                        <select class="form-select" id="defaultTheme">
                                            <option value="dark" <?= $site_settings['defaultTheme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                                            <option value="light" <?= $site_settings['defaultTheme'] === 'light' ? 'selected' : '' ?>>Light</option>
                                            <option value="system" <?= $site_settings['defaultTheme'] === 'system' ? 'selected' : '' ?>>System Preference</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Card Style</label>
                                        <select class="form-select" id="cardStyle">
                                            <option value="modern" <?= $site_settings['cardStyle'] === 'modern' ? 'selected' : '' ?>>Modern</option>
                                            <option value="classic" <?= $site_settings['cardStyle'] === 'classic' ? 'selected' : '' ?>>Classic</option>
                                            <option value="compact" <?= $site_settings['cardStyle'] === 'compact' ? 'selected' : '' ?>>Compact</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Enable Theme Toggle</div>
                                        <div class="toggle-description">Allow users to switch between light and dark mode</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="enableThemeToggle" <?= ($site_settings['enableThemeToggle'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div id="appearanceStatus"></div>
                        </div>
                    </div>

                    <div class="preview-section">
                        <div class="preview-header">
                            <div class="preview-title">
                                <span>👁️</span> Live Preview
                            </div>
                            <a href="index.php" target="_blank" class="link">Open in new tab →</a>
                        </div>
                        <div class="preview-frame">
                            <iframe src="index.php" class="preview-iframe" id="previewFrame"></iframe>
                        </div>
                    </div>
                </div>

                <!-- Display Options Panel -->
                <div class="panel" id="panel-display">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Display Options</h2>
                                <p class="card-subtitle">Control what information is shown</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <span>📋</span> Video Card Information
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Show Download Count</div>
                                        <div class="toggle-description">Display number of downloads on video cards</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="showDownloadCount" <?= ($site_settings['showDownloadCount'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Show Creator Name</div>
                                        <div class="toggle-description">Display creator/uploader name</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="showCreator" <?= ($site_settings['showCreator'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Show Date</div>
                                        <div class="toggle-description">Display upload/release date</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="showDate" <?= ($site_settings['showDate'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <span>✨</span> Features
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Enable Bookmarks</div>
                                        <div class="toggle-description">Allow users to save favorite videos</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="enableBookmarks" <?= ($site_settings['enableBookmarks'] ?? false) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div>
                                        <div class="toggle-label">Enable Watch History</div>
                                        <div class="toggle-description">Track video progress for resume feature</div>
                                    </div>
                                    <label class="toggle">
                                        <input type="checkbox" id="enableWatchHistory" <?= ($site_settings['enableWatchHistory'] ?? true) ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div id="displayStatus"></div>
                        </div>
                    </div>
                </div>

                <!-- Featured Sections Panel -->
                <div class="panel" id="panel-sections">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Featured Sections</h2>
                                <p class="card-subtitle">Create custom content sections for your homepage</p>
                            </div>
                            <button class="btn btn-primary" onclick="addNewSection()">
                                <span>➕</span> Add Section
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="sectionsList"></div>
                            <div id="sectionsStatus"></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // State
        let selectedVideos = <?= json_encode($current_recommendations) ?>;
        let currentPage = 1;
        let currentQuery = '';
        let totalPages = 1;
        let currentPanel = 'staff-picks';
        let siteSettings = <?= json_encode($site_settings) ?>;
        let draggedItem = null;
        let featuredSections = <?= json_encode($featured_sections) ?>;
        let currentEditingSection = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            renderSelectedList();
            renderFeaturedSections();
            setupNavigation();
            setupColorPickers();
            setupDragAndDrop();

            // Enter key to search
            document.getElementById('searchInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') searchVideos();
            });
        });

        // Navigation
        function setupNavigation() {
            document.querySelectorAll('.nav-item[data-panel]').forEach(item => {
                item.addEventListener('click', () => {
                    const panel = item.dataset.panel;
                    switchPanel(panel);
                });
            });
        }

        function switchPanel(panel) {
            // Update nav items
            document.querySelectorAll('.nav-item[data-panel]').forEach(item => {
                item.classList.toggle('active', item.dataset.panel === panel);
            });

            // Update panels
            document.querySelectorAll('.panel').forEach(p => {
                p.classList.toggle('active', p.id === `panel-${panel}`);
            });

            // Update title
            const titles = {
                'staff-picks': 'Staff Picks',
                'site-settings': 'Site Settings',
                'appearance': 'Appearance',
                'display': 'Display Options',
                'sections': 'Featured Sections'
            };
            document.getElementById('panelTitle').textContent = titles[panel] || panel;
            currentPanel = panel;
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        // Color Pickers
        function setupColorPickers() {
            const brandColor = document.getElementById('brandColor');
            const accentColor = document.getElementById('accentColor');

            brandColor.addEventListener('input', (e) => {
                document.getElementById('brandColorValue').textContent = e.target.value;
            });

            accentColor.addEventListener('input', (e) => {
                document.getElementById('accentColorValue').textContent = e.target.value;
            });
        }

        // Drag and Drop
        function setupDragAndDrop() {
            const list = document.getElementById('selectedList');

            list.addEventListener('dragstart', (e) => {
                if (e.target.classList.contains('selected-item')) {
                    draggedItem = e.target;
                    e.target.classList.add('dragging');
                }
            });

            list.addEventListener('dragend', (e) => {
                if (e.target.classList.contains('selected-item')) {
                    e.target.classList.remove('dragging');
                    draggedItem = null;
                    updateVideoOrder();
                }
            });

            list.addEventListener('dragover', (e) => {
                e.preventDefault();
                const afterElement = getDragAfterElement(list, e.clientY);
                if (draggedItem) {
                    if (afterElement == null) {
                        list.appendChild(draggedItem);
                    } else {
                        list.insertBefore(draggedItem, afterElement);
                    }
                }
            });
        }

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.selected-item:not(.dragging)')];

            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function updateVideoOrder() {
            const items = document.querySelectorAll('.selected-item');
            const newOrder = [];
            items.forEach(item => {
                const id = item.dataset.id;
                const video = selectedVideos.find(v => v.id === id);
                if (video) newOrder.push(video);
            });
            selectedVideos = newOrder;
        }

        // Search videos
        async function searchVideos(page = 1) {
            const query = document.getElementById('searchInput').value.trim();
            if (!query) return;

            currentQuery = query;
            currentPage = page;

            const resultsDiv = document.getElementById('searchResults');
            resultsDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Searching Archive.org...</p></div>';

            try {
                const params = new URLSearchParams({
                    q: `${query} AND mediatype:(movies OR video)`,
                    output: 'json',
                    rows: '24',
                    page: String(page)
                });

                ['identifier', 'title', 'creator', 'year', 'downloads'].forEach(f => {
                    params.append('fl[]', f);
                });

                params.append('sort[]', 'downloads desc');

                const response = await fetch(`https://archive.org/advancedsearch.php?${params}`);
                const data = await response.json();

                if (data.response && data.response.docs) {
                    renderResults(data.response.docs);
                    totalPages = Math.ceil((data.response.numFound || 0) / 24);
                    renderPagination();
                }
            } catch (error) {
                resultsDiv.innerHTML = `<div class="empty-state"><div class="empty-state-icon">❌</div><div class="empty-state-title">Search failed</div><p class="empty-state-text">${error.message}</p></div>`;
            }
        }

        // Render search results
        function renderResults(docs) {
            const resultsDiv = document.getElementById('searchResults');

            if (!docs.length) {
                resultsDiv.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos found</div><p class="empty-state-text">Try a different search term</p></div>';
                return;
            }

            resultsDiv.innerHTML = '<div class="results-grid">' + docs.map(doc => {
                const id = doc.identifier;
                const title = Array.isArray(doc.title) ? doc.title[0] : (doc.title || 'Untitled');
                const creator = Array.isArray(doc.creator) ? doc.creator[0] : (doc.creator || 'Unknown');
                const year = doc.year || '';
                const isSelected = selectedVideos.some(v => v.id === id);
                const thumb = `https://archive.org/services/img/${id}`;

                return `
                    <div class="video-card ${isSelected ? 'selected' : ''}" onclick="toggleVideo('${id}', '${escapeHtml(title)}', '${escapeHtml(creator)}')">
                        <div class="video-card-thumb">
                            <img src="${thumb}" alt="${escapeHtml(title)}" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 9%22><rect fill=%22%23242428%22 width=%2216%22 height=%229%22/><text x=%228%22 y=%225%22 fill=%22%2371717a%22 text-anchor=%22middle%22 font-size=%222%22>🎬</text></svg>'">
                            ${isSelected ? '<div class="video-card-badge"><span>✓</span> Added</div>' : ''}
                        </div>
                        <div class="video-card-content">
                            <div class="video-card-title">${escapeHtml(title)}</div>
                            <div class="video-card-meta">${escapeHtml(creator)}${year ? ' · ' + year : ''}</div>
                        </div>
                    </div>
                `;
            }).join('') + '</div>';
        }

        // Render pagination
        function renderPagination() {
            const paginationDiv = document.getElementById('pagination');

            if (totalPages <= 1) {
                paginationDiv.style.display = 'none';
                return;
            }

            paginationDiv.style.display = 'flex';
            paginationDiv.innerHTML = `
                <button onclick="searchVideos(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>← Previous</button>
                <button disabled style="opacity: 0.7;">Page ${currentPage} of ${totalPages}</button>
                <button onclick="searchVideos(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>Next →</button>
            `;
        }

        // Toggle video selection
        function toggleVideo(id, title, creator) {
            const index = selectedVideos.findIndex(v => v.id === id);

            if (index > -1) {
                selectedVideos.splice(index, 1);
            } else {
                selectedVideos.push({ id, title, creator });
            }

            renderSelectedList();
            updateSearchCards();
        }

        function updateSearchCards() {
            document.querySelectorAll('.video-card').forEach(card => {
                const onclick = card.getAttribute('onclick');
                if (!onclick) return;
                const match = onclick.match(/toggleVideo\('([^']+)'/);
                if (!match) return;
                const cardId = match[1];
                const isSelected = selectedVideos.some(v => v.id === cardId);
                card.classList.toggle('selected', isSelected);

                const badge = card.querySelector('.video-card-badge');
                if (isSelected && !badge) {
                    card.querySelector('.video-card-thumb').insertAdjacentHTML('beforeend', '<div class="video-card-badge"><span>✓</span> Added</div>');
                } else if (!isSelected && badge) {
                    badge.remove();
                }
            });
        }

        // Remove video from selection
        function removeVideo(id) {
            selectedVideos = selectedVideos.filter(v => v.id !== id);
            renderSelectedList();
            updateSearchCards();
        }

        // Render selected videos list
        function renderSelectedList() {
            const listDiv = document.getElementById('selectedList');
            const countSpan = document.getElementById('selectedCount');
            const navCount = document.getElementById('navVideoCount');

            countSpan.textContent = selectedVideos.length;
            navCount.textContent = selectedVideos.length;

            if (!selectedVideos.length) {
                listDiv.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📋</div><div class="empty-state-title">No videos selected</div><p class="empty-state-text">Search and click videos to add them</p></div>';
                return;
            }

            listDiv.innerHTML = selectedVideos.map(video => `
                <div class="selected-item" data-id="${video.id}" draggable="true">
                    <div class="selected-item-drag">⋮⋮</div>
                    <div class="selected-item-thumb">
                        <img src="https://archive.org/services/img/${video.id}" alt="${escapeHtml(video.title)}">
                    </div>
                    <div class="selected-item-info">
                        <div class="selected-item-title">${escapeHtml(video.title)}</div>
                        <div class="selected-item-id">${video.id}</div>
                    </div>
                    <button class="selected-item-remove" onclick="event.stopPropagation(); removeVideo('${video.id}')" title="Remove">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4l8 8M12 4l-8 8"/>
                        </svg>
                    </button>
                </div>
            `).join('');

            setupDragAndDrop();
        }

        // Save current panel
        function saveCurrentPanel() {
            switch(currentPanel) {
                case 'staff-picks':
                    saveStaffPicks();
                    break;
                case 'site-settings':
                case 'appearance':
                case 'display':
                    saveSiteSettings();
                    break;
                case 'sections':
                    saveFeaturedSections();
                    break;
            }
        }

        // Save Staff Picks
        async function saveStaffPicks() {
            const statusDiv = document.getElementById('staffPicksStatus');
            const title = document.getElementById('sectionTitle').value.trim() || 'Staff Picks';
            const enabled = document.getElementById('enabledToggle').checked;

            statusDiv.innerHTML = '<div class="save-status" style="background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color);">💾 Saving...</div>';

            try {
                const response = await fetch('save-recommendations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        enabled: enabled,
                        title: title,
                        videos: selectedVideos
                    })
                });

                const result = await response.json();

                if (result.success) {
                    statusDiv.innerHTML = '<div class="save-status success">✓ Saved successfully!</div>';
                    refreshPreview();
                } else {
                    statusDiv.innerHTML = `<div class="save-status error">✗ Error: ${result.error || 'Unknown error'}</div>`;
                }
            } catch (error) {
                statusDiv.innerHTML = `<div class="save-status error">✗ Error: ${error.message}</div>`;
            }

            setTimeout(() => { statusDiv.innerHTML = ''; }, 3000);
        }

        // Save Site Settings
        async function saveSiteSettings() {
            const settings = {
                siteName: document.getElementById('siteName')?.value || 'Archive Film Club',
                tagline: document.getElementById('siteTagline')?.value || '',
                brandColor: document.getElementById('brandColor')?.value || '#ff0000',
                accentColor: document.getElementById('accentColor')?.value || '#065fd4',
                defaultTheme: document.getElementById('defaultTheme')?.value || 'dark',
                enableThemeToggle: document.getElementById('enableThemeToggle')?.checked ?? true,
                cardStyle: document.getElementById('cardStyle')?.value || 'modern',
                showDownloadCount: document.getElementById('showDownloadCount')?.checked ?? true,
                showCreator: document.getElementById('showCreator')?.checked ?? true,
                showDate: document.getElementById('showDate')?.checked ?? true,
                enableBookmarks: document.getElementById('enableBookmarks')?.checked ?? false,
                enableWatchHistory: document.getElementById('enableWatchHistory')?.checked ?? true,
                defaultCollection: document.getElementById('defaultCollection')?.value || 'all_videos',
                defaultSort: document.getElementById('defaultSort')?.value || 'downloads'
            };

            const statusDivId = currentPanel === 'site-settings' ? 'siteSettingsStatus' :
                               currentPanel === 'appearance' ? 'appearanceStatus' : 'displayStatus';
            const statusDiv = document.getElementById(statusDivId);

            if (statusDiv) {
                statusDiv.innerHTML = '<div class="save-status" style="background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color);">💾 Saving...</div>';
            }

            try {
                const response = await fetch('save-settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(settings)
                });

                const result = await response.json();

                if (result.success) {
                    if (statusDiv) statusDiv.innerHTML = '<div class="save-status success">✓ Saved successfully!</div>';
                    refreshPreview();
                } else {
                    if (statusDiv) statusDiv.innerHTML = `<div class="save-status error">✗ Error: ${result.error || 'Unknown error'}</div>`;
                }
            } catch (error) {
                if (statusDiv) statusDiv.innerHTML = `<div class="save-status error">✗ Error: ${error.message}</div>`;
            }

            if (statusDiv) setTimeout(() => { statusDiv.innerHTML = ''; }, 3000);
        }

        function refreshPreview() {
            const frame = document.getElementById('previewFrame');
            if (frame) {
                frame.src = frame.src;
            }
        }

        // ===== FEATURED SECTIONS MANAGEMENT =====

        function renderFeaturedSections() {
            const container = document.getElementById('sectionsList');
            if (!container) return;

            if (!featuredSections || featuredSections.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📂</div>
                        <div class="empty-state-title">No featured sections yet</div>
                        <p class="empty-state-text">Create sections to organize and showcase content on your homepage</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = featuredSections.map((section, index) => `
                <div class="card" style="margin-bottom: 20px;" data-section-id="${section.id}">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">${escapeHtml(section.title)}</h3>
                            <p class="card-subtitle">${section.videos?.length || 0} videos · ${section.enabled ? '✓ Enabled' : '✗ Disabled'}</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-secondary btn-sm" onclick="editSection('${section.id}')">
                                ✏️ Edit
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteSection('${section.id}')">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                    ${section.description ? `
                        <div class="card-body">
                            <p style="color: var(--text-secondary); font-size: 14px;">${escapeHtml(section.description)}</p>
                        </div>
                    ` : ''}
                </div>
            `).join('');
        }

        function addNewSection() {
            const newSection = {
                id: 'section-' + Date.now(),
                title: 'New Section',
                description: '',
                enabled: true,
                videos: []
            };

            featuredSections.push(newSection);
            renderFeaturedSections();
            editSection(newSection.id);
        }

        function editSection(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            currentEditingSection = sectionId;

            const container = document.getElementById('sectionsList');
            const sectionCard = container.querySelector(`[data-section-id="${sectionId}"]`);
            if (!sectionCard) return;

            sectionCard.innerHTML = `
                <div class="card-header">
                    <h3 class="card-title">Edit Section</h3>
                    <button class="btn btn-ghost btn-sm" onclick="cancelEditSection()">Cancel</button>
                </div>
                <div class="card-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Section Title</label>
                        <input type="text" class="form-input" id="editSectionTitle" value="${escapeHtml(section.title)}" placeholder="e.g., Classic Westerns">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Description (Optional)</label>
                        <input type="text" class="form-input" id="editSectionDescription" value="${escapeHtml(section.description || '')}" placeholder="Brief description of this section">
                    </div>

                    <div class="toggle-wrapper" style="margin-bottom: 24px;">
                        <div>
                            <div class="toggle-label">Show on homepage</div>
                            <div class="toggle-description">Display this section to visitors</div>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" id="editSectionEnabled" ${section.enabled ? 'checked' : ''}>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="divider"></div>

                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <label class="form-label" style="margin: 0;">Videos in this section</label>
                            <button class="btn btn-primary btn-sm" onclick="openVideoSearchModal('${sectionId}')">
                                ➕ Add Videos
                            </button>
                        </div>
                        <div id="sectionVideosList" class="selected-list" style="max-height: 300px;">
                            ${section.videos?.length > 0 ? section.videos.map(video => `
                                <div class="selected-item" data-video-id="${video.id}" draggable="true">
                                    <div class="selected-item-drag">⋮⋮</div>
                                    <div class="selected-item-thumb">
                                        <img src="https://archive.org/services/img/${video.id}" alt="${escapeHtml(video.title)}">
                                    </div>
                                    <div class="selected-item-info">
                                        <div class="selected-item-title">${escapeHtml(video.title)}</div>
                                        <div class="selected-item-id">${video.id}</div>
                                    </div>
                                    <button class="selected-item-remove" onclick="removeVideoFromSection('${sectionId}', '${video.id}')" title="Remove">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 4l8 8M12 4l-8 8"/>
                                        </svg>
                                    </button>
                                </div>
                            `).join('') : '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos yet</div><p class="empty-state-text">Add videos to this section</p></div>'}
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button class="btn btn-success" onclick="saveEditSection('${sectionId}')">
                            💾 Save Section
                        </button>
                        <button class="btn btn-secondary" onclick="cancelEditSection()">
                            Cancel
                        </button>
                    </div>
                </div>
            `;

            setupSectionVideoDragAndDrop();
        }

        function setupSectionVideoDragAndDrop() {
            const list = document.getElementById('sectionVideosList');
            if (!list) return;

            let draggedItem = null;

            list.addEventListener('dragstart', (e) => {
                if (e.target.classList.contains('selected-item')) {
                    draggedItem = e.target;
                    e.target.classList.add('dragging');
                }
            });

            list.addEventListener('dragend', (e) => {
                if (e.target.classList.contains('selected-item')) {
                    e.target.classList.remove('dragging');
                    draggedItem = null;
                    updateSectionVideoOrder();
                }
            });

            list.addEventListener('dragover', (e) => {
                e.preventDefault();
                const afterElement = getDragAfterElement(list, e.clientY);
                if (draggedItem) {
                    if (afterElement == null) {
                        list.appendChild(draggedItem);
                    } else {
                        list.insertBefore(draggedItem, afterElement);
                    }
                }
            });
        }

        function updateSectionVideoOrder() {
            if (!currentEditingSection) return;

            const section = featuredSections.find(s => s.id === currentEditingSection);
            if (!section) return;

            const items = document.querySelectorAll('#sectionVideosList .selected-item');
            const newOrder = [];

            items.forEach(item => {
                const videoId = item.dataset.videoId;
                const video = section.videos.find(v => v.id === videoId);
                if (video) newOrder.push(video);
            });

            section.videos = newOrder;
        }

        function saveEditSection(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const title = document.getElementById('editSectionTitle')?.value.trim();
            const description = document.getElementById('editSectionDescription')?.value.trim();
            const enabled = document.getElementById('editSectionEnabled')?.checked;

            if (!title) {
                alert('Section title is required');
                return;
            }

            section.title = title;
            section.description = description;
            section.enabled = enabled;
            section.updated = new Date().toISOString();

            currentEditingSection = null;
            renderFeaturedSections();

            // Auto-save to server
            saveFeaturedSections();
        }

        function cancelEditSection() {
            currentEditingSection = null;
            renderFeaturedSections();
        }

        function deleteSection(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            if (!confirm(`Delete "${section.title}"? This cannot be undone.`)) {
                return;
            }

            featuredSections = featuredSections.filter(s => s.id !== sectionId);
            renderFeaturedSections();

            // Auto-save to server
            saveFeaturedSections();
        }

        function removeVideoFromSection(sectionId, videoId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            section.videos = section.videos.filter(v => v.id !== videoId);

            // Re-render the video list
            const videosList = document.getElementById('sectionVideosList');
            if (videosList) {
                const video = section.videos;
                if (section.videos.length === 0) {
                    videosList.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos yet</div><p class="empty-state-text">Add videos to this section</p></div>';
                } else {
                    videosList.innerHTML = section.videos.map(video => `
                        <div class="selected-item" data-video-id="${video.id}" draggable="true">
                            <div class="selected-item-drag">⋮⋮</div>
                            <div class="selected-item-thumb">
                                <img src="https://archive.org/services/img/${video.id}" alt="${escapeHtml(video.title)}">
                            </div>
                            <div class="selected-item-info">
                                <div class="selected-item-title">${escapeHtml(video.title)}</div>
                                <div class="selected-item-id">${video.id}</div>
                            </div>
                            <button class="selected-item-remove" onclick="removeVideoFromSection('${sectionId}', '${video.id}')" title="Remove">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 4l8 8M12 4l-8 8"/>
                                </svg>
                            </button>
                        </div>
                    `).join('');
                    setupSectionVideoDragAndDrop();
                }
            }
        }

        function openVideoSearchModal(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            // Create modal overlay
            const modal = document.createElement('div');
            modal.id = 'videoSearchModal';
            modal.style.cssText = `
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                padding: 20px;
            `;

            modal.innerHTML = `
                <div class="card" style="width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto;">
                    <div class="card-header">
                        <h3 class="card-title">Add Videos to ${escapeHtml(section.title)}</h3>
                        <button class="btn btn-ghost btn-sm" onclick="closeVideoSearchModal()">✕ Close</button>
                    </div>
                    <div class="card-body">
                        <div class="search-box">
                            <div class="search-input-wrapper">
                                <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                </svg>
                                <input type="text" class="search-input" id="modalSearchInput" placeholder="Search movies, shows, documentaries...">
                            </div>
                            <button class="btn btn-primary" onclick="searchVideosForSection('${sectionId}')">
                                Search
                            </button>
                        </div>
                        <div id="modalSearchResults">
                            <div class="empty-state">
                                <div class="empty-state-icon">🔍</div>
                                <div class="empty-state-title">Search for videos</div>
                                <p class="empty-state-text">Click on videos to add them to this section</p>
                            </div>
                        </div>
                        <div id="modalPagination" class="pagination" style="display: none;"></div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // Enter key to search
            document.getElementById('modalSearchInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') searchVideosForSection(sectionId);
            });

            // Close on backdrop click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeVideoSearchModal();
            });
        }

        function closeVideoSearchModal() {
            const modal = document.getElementById('videoSearchModal');
            if (modal) {
                modal.remove();
            }
        }

        async function searchVideosForSection(sectionId, page = 1) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const query = document.getElementById('modalSearchInput').value.trim();
            if (!query) return;

            const resultsDiv = document.getElementById('modalSearchResults');
            resultsDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Searching Archive.org...</p></div>';

            try {
                const params = new URLSearchParams({
                    q: `${query} AND mediatype:(movies OR video)`,
                    output: 'json',
                    rows: '24',
                    page: String(page)
                });

                ['identifier', 'title', 'creator', 'year', 'downloads'].forEach(f => {
                    params.append('fl[]', f);
                });

                params.append('sort[]', 'downloads desc');

                const response = await fetch(`https://archive.org/advancedsearch.php?${params}`);
                const data = await response.json();

                if (data.response && data.response.docs) {
                    renderModalResults(data.response.docs, sectionId);
                }
            } catch (error) {
                resultsDiv.innerHTML = `<div class="empty-state"><div class="empty-state-icon">❌</div><div class="empty-state-title">Search failed</div><p class="empty-state-text">${error.message}</p></div>`;
            }
        }

        function renderModalResults(docs, sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const resultsDiv = document.getElementById('modalSearchResults');

            if (!docs.length) {
                resultsDiv.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos found</div><p class="empty-state-text">Try a different search term</p></div>';
                return;
            }

            resultsDiv.innerHTML = '<div class="results-grid">' + docs.map(doc => {
                const id = doc.identifier;
                const title = Array.isArray(doc.title) ? doc.title[0] : (doc.title || 'Untitled');
                const creator = Array.isArray(doc.creator) ? doc.creator[0] : (doc.creator || 'Unknown');
                const year = doc.year || '';
                const isSelected = section.videos.some(v => v.id === id);
                const thumb = `https://archive.org/services/img/${id}`;

                return `
                    <div class="video-card ${isSelected ? 'selected' : ''}" onclick="toggleVideoForSection('${sectionId}', '${id}', '${escapeHtml(title)}', '${escapeHtml(creator)}')">
                        <div class="video-card-thumb">
                            <img src="${thumb}" alt="${escapeHtml(title)}" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 9%22><rect fill=%22%23242428%22 width=%2216%22 height=%229%22/><text x=%228%22 y=%225%22 fill=%22%2371717a%22 text-anchor=%22middle%22 font-size=%222%22>🎬</text></svg>'">
                            ${isSelected ? '<div class="video-card-badge"><span>✓</span> Added</div>' : ''}
                        </div>
                        <div class="video-card-content">
                            <div class="video-card-title">${escapeHtml(title)}</div>
                            <div class="video-card-meta">${escapeHtml(creator)}${year ? ' · ' + year : ''}</div>
                        </div>
                    </div>
                `;
            }).join('') + '</div>';
        }

        function toggleVideoForSection(sectionId, videoId, title, creator) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const index = section.videos.findIndex(v => v.id === videoId);

            if (index > -1) {
                section.videos.splice(index, 1);
            } else {
                section.videos.push({ id: videoId, title: title, creator: creator });
            }

            // Update the modal view
            const cards = document.querySelectorAll('#modalSearchResults .video-card');
            cards.forEach(card => {
                const onclick = card.getAttribute('onclick');
                if (onclick && onclick.includes(`'${videoId}'`)) {
                    const isSelected = section.videos.some(v => v.id === videoId);
                    card.classList.toggle('selected', isSelected);

                    const badge = card.querySelector('.video-card-badge');
                    if (isSelected && !badge) {
                        card.querySelector('.video-card-thumb').insertAdjacentHTML('beforeend', '<div class="video-card-badge"><span>✓</span> Added</div>');
                    } else if (!isSelected && badge) {
                        badge.remove();
                    }
                }
            });

            // Update the section videos list in the background
            const videosList = document.getElementById('sectionVideosList');
            if (videosList && currentEditingSection === sectionId) {
                if (section.videos.length === 0) {
                    videosList.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos yet</div><p class="empty-state-text">Add videos to this section</p></div>';
                } else {
                    videosList.innerHTML = section.videos.map(video => `
                        <div class="selected-item" data-video-id="${video.id}" draggable="true">
                            <div class="selected-item-drag">⋮⋮</div>
                            <div class="selected-item-thumb">
                                <img src="https://archive.org/services/img/${video.id}" alt="${escapeHtml(video.title)}">
                            </div>
                            <div class="selected-item-info">
                                <div class="selected-item-title">${escapeHtml(video.title)}</div>
                                <div class="selected-item-id">${video.id}</div>
                            </div>
                            <button class="selected-item-remove" onclick="removeVideoFromSection('${sectionId}', '${video.id}')" title="Remove">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 4l8 8M12 4l-8 8"/>
                                </svg>
                            </button>
                        </div>
                    `).join('');
                    setupSectionVideoDragAndDrop();
                }
            }
        }

        async function saveFeaturedSections() {
            const statusDiv = document.getElementById('sectionsStatus');

            statusDiv.innerHTML = '<div class="save-status" style="background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color);">💾 Saving...</div>';

            try {
                const response = await fetch('save-featured-sections.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sections: featuredSections })
                });

                const result = await response.json();

                if (result.success) {
                    statusDiv.innerHTML = '<div class="save-status success">✓ Featured sections saved successfully!</div>';
                    refreshPreview();
                } else {
                    statusDiv.innerHTML = `<div class="save-status error">✗ Error: ${result.error || 'Unknown error'}</div>`;
                }
            } catch (error) {
                statusDiv.innerHTML = `<div class="save-status error">✗ Error: ${error.message}</div>`;
            }

            setTimeout(() => { statusDiv.innerHTML = ''; }, 3000);
        }

        // Escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
    <?php endif; ?>
</body>
</html>
