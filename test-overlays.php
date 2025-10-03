<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sidebar Overlay Test</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="admin-layout">
        <!-- Modern Sidebar -->
        <nav class="modern-sidebar collapsed" id="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <i class="fas fa-server brand-icon"></i>
                    <span class="brand-text">Admin Panel</span>
                </div>
                <button class="toggle-btn" id="toggleSidebarBtn">
                    <i class="fas fa-bars"></i>
                </button>
            </div>

            <div class="sidebar-content">
                <!-- Users Section -->
                <div class="nav-group" data-group="users">
                    <div class="nav-item group-toggle" data-tooltip="Users Management">
                        <i class="nav-icon fas fa-users"></i>
                        <span class="nav-text">Users</span>
                        <i class="nav-arrow fas fa-chevron-down"></i>
                        <span class="nav-badge">3</span>
                    </div>
                    <div class="nav-submenu">
                        <a href="admin/users.php" class="sub-item">
                            <i class="fas fa-user-plus"></i>
                            <span>Manage Users</span>
                        </a>
                        <a href="admin/users.php?view=pending" class="sub-item">
                            <i class="fas fa-user-clock"></i>
                            <span>Pending Approval</span>
                        </a>
                        <a href="admin/users.php?view=banned" class="sub-item">
                            <i class="fas fa-user-slash"></i>
                            <span>Banned Users</span>
                        </a>
                    </div>
                </div>

                <!-- Hosting Section -->
                <div class="nav-group" data-group="hosting">
                    <div class="nav-item group-toggle" data-tooltip="Hosting Services">
                        <i class="nav-icon fas fa-server"></i>
                        <span class="nav-text">Hosting</span>
                        <i class="nav-arrow fas fa-chevron-down"></i>
                    </div>
                    <div class="nav-submenu">
                        <a href="admin/packages.php" class="sub-item">
                            <i class="fas fa-box"></i>
                            <span>Hosting Packages</span>
                        </a>
                        <a href="admin/domains.php" class="sub-item">
                            <i class="fas fa-globe"></i>
                            <span>Domain Management</span>
                        </a>
                        <a href="admin/vhost-templates.php" class="sub-item">
                            <i class="fas fa-code"></i>
                            <span>VHost Templates</span>
                        </a>
                    </div>
                </div>

                <!-- Security Section -->
                <div class="nav-group" data-group="security">
                    <div class="nav-item group-toggle" data-tooltip="Security & SSL">
                        <i class="nav-icon fas fa-shield-alt"></i>
                        <span class="nav-text">Security</span>
                        <i class="nav-arrow fas fa-chevron-down"></i>
                        <span class="nav-badge security-badge">2</span>
                    </div>
                    <div class="nav-submenu">
                        <a href="admin/ssl-automation.php" class="sub-item">
                            <i class="fas fa-lock"></i>
                            <span>SSL Certificates</span>
                        </a>
                        <a href="admin/security-settings.php" class="sub-item">
                            <i class="fas fa-cog"></i>
                            <span>Security Settings</span>
                        </a>
                        <a href="admin/firewall.php" class="sub-item">
                            <i class="fas fa-fire"></i>
                            <span>Firewall Rules</span>
                        </a>
                        <a href="admin/intrusion-detection.php" class="sub-item">
                            <i class="fas fa-eye"></i>
                            <span>Intrusion Detection</span>
                        </a>
                    </div>
                </div>

                <!-- System Section -->
                <div class="nav-group" data-group="system">
                    <div class="nav-item group-toggle" data-tooltip="System Settings">
                        <i class="nav-icon fas fa-cogs"></i>
                        <span class="nav-text">System</span>
                        <i class="nav-arrow fas fa-chevron-down"></i>
                    </div>
                    <div class="nav-submenu">
                        <a href="admin/general-settings.php" class="sub-item">
                            <i class="fas fa-sliders-h"></i>
                            <span>General Settings</span>
                        </a>
                        <a href="admin/php-settings.php" class="sub-item">
                            <i class="fab fa-php"></i>
                            <span>PHP Configuration</span>
                        </a>
                        <a href="admin/migrations.php" class="sub-item">
                            <i class="fas fa-database"></i>
                            <span>Database Migrations</span>
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-wrapper">
                <h1>Overlay Test Page</h1>
                <p>This page tests the sidebar overlay functionality:</p>
                
                <div style="padding: 20px; background: var(--card-bg); border-radius: 8px; margin: 20px 0;">
                    <h3>Test Instructions:</h3>
                    <ol>
                        <li><strong>Tooltips:</strong> Hover over navigation icons to see tooltips appear as overlays to the right</li>
                        <li><strong>Dropdown Menus:</strong> Hover over navigation groups to see dropdown menus appear as overlays</li>
                        <li><strong>Positioning:</strong> Both should appear as fixed overlays that don't affect page layout</li>
                        <li><strong>Visibility:</strong> Overlays should be clearly visible with proper backdrop blur and shadows</li>
                    </ol>
                    
                    <h3>Expected Behavior:</h3>
                    <ul>
                        <li>✅ Tooltips appear to the RIGHT of navigation icons with proper centering</li>
                        <li>✅ Dropdown menus appear to the RIGHT of navigation groups</li>
                        <li>✅ High z-index ensures overlays appear above all content</li>
                        <li>✅ Backdrop blur provides modern glass effect</li>
                        <li>✅ Smooth transitions for professional feel</li>
                    </ul>
                </div>

                <div style="height: 100vh; background: linear-gradient(45deg, #1e293b, #334155); display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;">
                    <div style="text-align: center;">
                        <p>Scroll content to test overlay positioning</p>
                        <p>Overlays should remain properly positioned</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/js/modern-sidebar.js"></script>
    <script>
        // Initialize sidebar in collapsed state for testing
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.add('collapsed');
                console.log('Test page: Sidebar initialized in collapsed state');
            }
        });
    </script>
</body>
</html>