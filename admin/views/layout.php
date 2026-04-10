<?php
/**
 * Admin layout — the authenticated view shell.
 *
 * Expected globals (populated by AdminBootstrap.php):
 *   $useDatabase, $admin_user, $site_settings,
 *   $current_recommendations, $recommendations_data, $featured_sections
 */
?>
    <!-- Admin Panel -->
    <div class="admin-wrapper">
        <!-- Sidebar Overlay (mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="admin-main">
            <?php include __DIR__ . '/header.php'; ?>

            <div class="admin-content">
                <!-- Dashboard Panel -->
                <div class="panel active" id="panel-dashboard">
                    <?php include __DIR__ . '/panels/dashboard.php'; ?>
                </div>

                <!-- Staff Picks Panel -->
                <div class="panel" id="panel-staff-picks">
                    <?php include __DIR__ . '/panels/staff-picks.php'; ?>
                </div>

                <!-- Site Settings Panel -->
                <div class="panel" id="panel-site-settings">
                    <?php include __DIR__ . '/panels/site-settings.php'; ?>
                </div>

                <!-- Appearance Panel -->
                <div class="panel" id="panel-appearance">
                    <?php include __DIR__ . '/panels/appearance.php'; ?>
                </div>

                <!-- Display Options Panel -->
                <div class="panel" id="panel-display">
                    <?php include __DIR__ . '/panels/display.php'; ?>
                </div>

                <!-- Featured Sections Panel -->
                <div class="panel" id="panel-sections">
                    <?php include __DIR__ . '/panels/sections.php'; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
