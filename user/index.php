<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Get user package info
$package = getUserPackage($conn, $user_id);
$domain_count = getUserDomainCount($conn, $user_id);

// Get user domains
$query = "SELECT * FROM domains WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$recent_domains = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Dashboard - Hosting Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
    <div class="sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
            <h3 style="color: var(--primary-color);">User Panel</h3>
            <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <div class="sidebar-nav">
            <div class="sidebar-group open" data-group-key="user-overview">
                <div class="group-header" role="button" aria-expanded="true">
                    <span class="group-label">Overview</span>
                    <span class="group-arrow">▶</span>
                </div>
                <div class="group-items">
                    <ul class="sidebar-nav">
                        <li><a href="index.php" class="active">Dashboard</a></li>
                        <li><a href="profile.php">Profile</a></li>
                    </ul>
                </div>
            </div>

            <div class="sidebar-group" data-group-key="user-hosting">
                <div class="group-header" role="button" aria-expanded="false">
                    <span class="group-label">Hosting</span>
                    <span class="group-arrow">▶</span>
                </div>
                <div class="group-items">
                    <ul class="sidebar-nav">
                        <li><a href="domains.php">Domains</a></li>
                        <li><a href="subdomains.php">Subdomains</a></li>
                        <li><a href="ssl.php">SSL Certificates</a></li>
                        <li><a href="dns.php">DNS</a></li>
                        <li><a href="file-manager.php">File Manager</a></li>
                    </ul>
                </div>
            </div>

            <div class="sidebar-group" data-group-key="user-services">
                <div class="group-header" role="button" aria-expanded="false">
                    <span class="group-label">Services</span>
                    <span class="group-arrow">▶</span>
                </div>
                <div class="group-items">
                    <ul class="sidebar-nav">
                        <li><a href="email.php">Email Accounts</a></li>
                        <li><a href="databases.php">Databases</a></li>
                        <li><a href="ftp.php">FTP Accounts</a></li>
                        <li><a href="backups.php">Backups</a></li>
                    </ul>
                </div>
            </div>

            <div class="sidebar-group" data-group-key="user-session">
                <div class="group-header" role="button" aria-expanded="false">
                    <span class="group-label">Session</span>
                    <span class="group-arrow">▶</span>
                </div>
                <div class="group-items">
                    <ul class="sidebar-nav">
                        <li><a href="../logout.php">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <h1>Dashboard</h1>
        
        <?php if ($package): ?>
        <div class="card">
            <h3>Package Information - <?= htmlspecialchars($package['name']) ?></h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; margin-top: 16px;">
                <div>
                    <strong>Disk Space:</strong><br>
                    <?= $package['disk_space'] == 0 ? 'Unlimited' : formatBytes($package['disk_space'] * 1024 * 1024) ?>
                </div>
                <div>
                    <strong>Bandwidth:</strong><br>
                    <?= $package['bandwidth'] == 0 ? 'Unlimited' : formatBytes($package['bandwidth'] * 1024 * 1024) ?>
                </div>
                <div>
                    <strong>Domains:</strong><br>
                    <?= $domain_count ?> / <?= $package['domains_limit'] == 0 ? 'Unlimited' : $package['domains_limit'] ?>
                </div>
                <div>
                    <strong>Email Accounts:</strong><br>
                    <?= $package['email_accounts'] == 0 ? 'Unlimited' : $package['email_accounts'] ?>
                </div>
                <div>
                    <strong>Databases:</strong><br>
                    <?= $package['databases_limit'] == 0 ? 'Unlimited' : $package['databases_limit'] ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $domain_count ?></div>
                <div class="stat-label">Total Domains</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $package ? ($package['domains_limit'] == 0 ? 'Unlimited' : $package['domains_limit'] - $domain_count) : 0 ?></div>
                <div class="stat-label">Available Domains</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $package ? ($package['email_accounts'] == 0 ? 'Unlimited' : $package['email_accounts']) : 0 ?></div>
                <div class="stat-label">Email Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $package ? ($package['databases_limit'] == 0 ? 'Unlimited' : $package['databases_limit']) : 0 ?></div>
                <div class="stat-label">Databases</div>
            </div>
        </div>
        
        <?php if ($recent_domains): ?>
        <div class="card">
            <h3>Recent Domains</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_domains as $domain): ?>
                    <tr>
                        <td><?= htmlspecialchars($domain['domain_name']) ?></td>
                        <td><?= ucfirst($domain['status']) ?></td>
                        <td><?= date('M j, Y', strtotime($domain['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <a href="domains.php" class="btn btn-primary" style="margin-top: 16px;">View All Domains</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>