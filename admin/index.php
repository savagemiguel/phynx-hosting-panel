<?php
// Ensure proper path resolution
$basePath = dirname(__DIR__);
require_once $basePath . '/config.php';
require_once $basePath . '/includes/functions.php';

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug output to confirm PHP is working
if (!function_exists('isLoggedIn')) {
    die('Error: Functions not loaded properly. Check includes/functions.php');
}

requireAdmin();

// Handle AJAX requests for live updates
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'uptime':
            $uptime = getSystemUptime();
            echo json_encode(['uptime' => $uptime['formatted']]);
            exit;
            
        case 'stats':
            $stats = getSystemStats();
            echo json_encode([
                'memory' => [
                    'percent' => $stats['memory']['percent'],
                    'used' => formatBytes($stats['memory']['used']),
                    'total' => formatBytes($stats['memory']['total'])
                ],
                'disk' => [
                    'percent' => $stats['disk']['percent'],
                    'used' => formatBytes($stats['disk']['used']),
                    'total' => formatBytes($stats['disk']['total'])
                ],
                'cpu' => [
                    'load_1min' => $stats['cpu']['load_1min'],
                    'load_5min' => $stats['cpu']['load_5min'],
                    'load_15min' => $stats['cpu']['load_15min']
                ]
            ]);
            exit;
            
        default:
            echo json_encode(['error' => 'Invalid request']);
            exit;
    }
}

// Get statistics
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$totalUsers = mysqli_fetch_assoc($result)['count'];

$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM domains");
$totalDomains = mysqli_fetch_assoc($result)['count'];

$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM packages WHERE status = 'active'");
$totalPackages = mysqli_fetch_assoc($result)['count'];

$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE status = 'pending'");
$pendingUsers = mysqli_fetch_assoc($result)['count'];

// Get system statistics
$systemStats = getSystemStats();
$cpuUsage = getCPUUsage();
$apacheStatus = getApacheStatus();
$mysqlStatus = getMySQLStatus();
$bindStatus = getBindStatus();

// Get additional service statistics
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_accounts");
$totalEmailAccounts = mysqli_fetch_assoc($result)['count'];

$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM `databases`");
$totalDatabases = mysqli_fetch_assoc($result)['count'];

$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM ssl_certificates");
$totalSSLCerts = mysqli_fetch_assoc($result)['count'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - Hosting Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
    <style>
        .stat-detail {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        .status-ok {
            border-left: 4px solid var(--success-color);
        }
        
        .status-error {
            border-left: 4px solid var(--error-color);
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--bg-tertiary);
            border-radius: 4px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success-color), var(--warning-color), var(--error-color));
            transition: width 0.3s ease;
        }
        
        .refresh-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 16px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            z-index: 1000;
        }
        
        .refresh-btn:hover {
            background: var(--primary-hover);
        }
        
        /* Debug styles for sidebar expansion */
        
        
        .group-toggle {
            cursor: pointer !important;
            user-select: none !important;
        }
        
        .group-toggle:hover {
            background: rgba(227, 252, 2, 0.1) !important;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <div style="position: fixed; top: 10px; right: 10px; z-index: 9999;">
            <button onclick="window.testSidebar()" style="padding: 8px 12px; background: #E3FC02; color: black; border: none; border-radius: 4px; cursor: pointer;">
                Test Sidebar Toggle
            </button>
            <button onclick="window.debugSidebar()" style="padding: 8px 12px; background: #ff6b6b; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 5px;">
                Debug Info
            </button>
        </div>
        
        <h1>Dashboard</h1>
        
        <!-- System Status Overview -->
        <div class="card" style="margin-bottom: 24px;">
            <h3>🖥️ Server Status</h3>
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
                <div class="stat-card" data-percent="<?= round($systemStats['memory']['percent'], 1) ?>" style="--progress-width: <?= round($systemStats['memory']['percent'], 1) ?>%;">
                    <div class="stat-number"><?= round($systemStats['memory']['percent'], 1) ?>%</div>
                    <div class="stat-label">Memory Usage</div>
                    <div class="stat-detail"><?= formatBytes($systemStats['memory']['used']) ?> / <?= formatBytes($systemStats['memory']['total']) ?></div>
                </div>
                <div class="stat-card" data-percent="<?= round($systemStats['disk']['percent'], 1) ?>" style="--progress-width: <?= round($systemStats['disk']['percent'], 1) ?>%;">
                    <div class="stat-number"><?= round($systemStats['disk']['percent'], 1) ?>%</div>
                    <div class="stat-label">Disk Usage</div>
                    <div class="stat-detail"><?= formatBytes($systemStats['disk']['used']) ?> / <?= formatBytes($systemStats['disk']['total']) ?></div>
                </div>
                <div class="stat-card" data-percent="<?= min(($systemStats['cpu']['load_1min'] / 4) * 100, 100) ?>" style="--progress-width: <?= min(($systemStats['cpu']['load_1min'] / 4) * 100, 100) ?>%;">
                    <div class="stat-number"><?= $systemStats['cpu']['load_1min'] ?></div>
                    <div class="stat-label">CPU Load (1min)</div>
                    <div class="stat-detail"><?= $systemStats['cpu']['load_5min'] ?> | <?= $systemStats['cpu']['load_15min'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $systemStats['uptime']['formatted'] ?></div>
                    <div class="stat-label">System Uptime</div>
                    <div class="stat-detail"><?= $systemStats['processes'] ?> proc</div>
                </div>
            </div>
        </div>

        <!-- Service Status -->
        <div class="card" style="margin-bottom: 24px;">
            <h3>🔧 Service Status</h3>
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                <div class="stat-card <?= $apacheStatus['active'] ? 'status-ok' : 'status-error' ?>">
                    <div class="stat-number"><?= $apacheStatus['active'] ? '✅' : '❌' ?></div>
                    <div class="stat-label">Apache</div>
                    <div class="stat-detail"><?= ucfirst($apacheStatus['status']) ?></div>
                </div>
                <div class="stat-card <?= $mysqlStatus['active'] ? 'status-ok' : 'status-error' ?>">
                    <div class="stat-number"><?= $mysqlStatus['active'] ? '✅' : '❌' ?></div>
                    <div class="stat-label">MySQL</div>
                    <div class="stat-detail"><?= ucfirst($mysqlStatus['status']) ?></div>
                </div>
                <div class="stat-card <?= $bindStatus['active'] ? 'status-ok' : 'status-error' ?>">
                    <div class="stat-number"><?= $bindStatus['active'] ? '✅' : '❌' ?></div>
                    <div class="stat-label">BIND DNS</div>
                    <div class="stat-detail"><?= ucfirst($bindStatus['status']) ?></div>
                </div>
            </div>
        </div>

        <!-- Panel Statistics -->
        <div class="card" style="margin-bottom: 24px;">
            <h3>📊 Panel Statistics</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $totalUsers ?></div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-detail"><?= $pendingUsers ?> pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $totalDomains ?></div>
                    <div class="stat-label">Total Domains</div>
                    <div class="stat-detail"><?= $totalEmailAccounts ?> email accounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $totalDatabases ?></div>
                    <div class="stat-label">User Databases</div>
                    <div class="stat-detail"><?= $totalPackages ?> active packages</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= formatBytes($systemStats['network']['rx_bytes']) ?></div>
                    <div class="stat-label">Network RX</div>
                    <div class="stat-detail"><?= formatBytes($systemStats['network']['tx_bytes']) ?> TX</div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h3>Recent Users</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = mysqli_query($conn, "SELECT id, username, email, status, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 5");
                    while ($user = mysqli_fetch_assoc($result)):
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= ucfirst($user['status']) ?></td>
                        <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                        <td class="actions-cell">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <a href="users.php?edit=<?= $user['id'] ?>" class="btn btn-primary" style="padding: 6px 16px; font-size: 12px; height: 32px; display: inline-flex; align-items: center;">Edit</a>
                                <form method="POST" action="users.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" name="delete_user" class="btn btn-danger" style="padding: 6px 16px; font-size: 12px; height: 32px;">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Quick Actions -->
        <div class="card">
            <h3>🚀 Quick Actions</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <a href="users.php" class="btn btn-primary" style="text-align: center; padding: 16px;">
                    👥 Manage Users
                </a>
                <a href="domains.php" class="btn btn-primary" style="text-align: center; padding: 16px;">
                    🌐 Domain Overview
                </a>
                <a href="database-manager.php" class="btn btn-primary" style="text-align: center; padding: 16px;">
                    🗄️ Database Manager
                </a>
                <a href="email-manager.php" class="btn btn-primary" style="text-align: center; padding: 16px;">
                    📧 Email Manager
                </a>
                <a href="general-settings.php" class="btn btn-primary" style="text-align: center; padding: 16px;">
                    ⚙️ Settings
                </a>
                <a href="php-settings.php" class="btn btn-primary" style="text-align: center; padding: 16px;">
                    🐘 PHP Settings
                </a>
            </div>
        </div>
    </div>

    <button class="refresh-btn" onclick="refreshStats()" title="Refresh Statistics">
        🔄
    </button>

    <script>
        // Auto-refresh stats every 30 seconds
        let refreshInterval;
        
        function refreshStats() {
            location.reload();
        }
        
        function startAutoRefresh() {
            refreshInterval = setInterval(refreshStats, 9000000); // 30 seconds
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }
        
        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
            
            // Add progress bars for memory and disk usage
            addProgressBars();
        });
        
        function addProgressBars() {
            const memoryCard = document.querySelector('.stat-card:first-child');
            const diskCard = document.querySelector('.stat-card:nth-child(2)');
            
            if (memoryCard) {
                const memoryPercent = <?= $systemStats['memory']['percent'] ?>;
                const memoryBar = document.createElement('div');
                memoryBar.className = 'progress-bar';
                memoryBar.innerHTML = `<div class="progress-fill" style="width: ${memoryPercent}%"></div>`;
                memoryCard.appendChild(memoryBar);
            }
            
            if (diskCard) {
                const diskPercent = <?= $systemStats['disk']['percent'] ?>;
                const diskBar = document.createElement('div');
                diskBar.className = 'progress-bar';
                diskBar.innerHTML = `<div class="progress-fill" style="width: ${diskPercent}%"></div>`;
                diskCard.appendChild(diskBar);
            }
        }
        
        // Stop refresh when user leaves page
        window.addEventListener('beforeunload', stopAutoRefresh);
    </script>
</body>
</html>