<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

// CSRF verification for POST requests
if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Get user package
$package = getUserPackage($conn, $user_id);
$domain_count = getUserDomainCount($conn, $user_id);

// Handle domain creation
if ($_POST && isset($_POST['create_domain'])) {
    if (!$package) {
        $message = '<div class="alert alert-error">No package assigned. Contact administrator.</div>';
    } elseif ($package['domains_limit'] > 0 && $domain_count >= $package['domains_limit']) {
        $message = '<div class="alert alert-error">Domain limit reached for your package.</div>';
    } else {
        $domain_name = strtolower(sanitize($_POST['domain_name']));
        $enable_ssl = isset($_POST['enable_ssl']);
        $create_www = isset($_POST['create_www']);
        $server_ip = $_POST['server_ip'] ?? '';
        
        $username = $_SESSION['username'];
        $document_root = WEB_ROOT . $username . '/' . $domain_name;
        
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain_name)) {
            $message = '<div class="alert alert-error">Invalid domain name format.</div>';
        } elseif (!filter_var($server_ip, FILTER_VALIDATE_IP)) {
            $message = '<div class="alert alert-error">Please provide a valid server IP address.</div>';
        } else {
            // Check if domain exists
            $query = "SELECT id FROM domains WHERE domain_name = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "s", $domain_name);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                $message = '<div class="alert alert-error">Domain already exists.</div>';
            } else {
                // Create domain
                $query = "INSERT INTO domains (user_id, domain_name, document_root, ssl_enabled) VALUES (?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $query);
                $ssl_flag = $enable_ssl ? 1 : 0;
                mysqli_stmt_bind_param($stmt, "issi", $user_id, $domain_name, $document_root, $ssl_flag);
                
                if (mysqli_stmt_execute($stmt)) {
                    $domain_id = mysqli_insert_id($conn);
                    
                    // Create directory structure
                    createDirectory($document_root);
                    createDirectory($document_root . '/public_html');
                    createDirectory($document_root . '/logs');
                    createDirectory($document_root . '/ssl');
                    
                    // Set proper ownership
                    $user_info = getUserById($conn, $user_id);
                    if ($user_info) {
                        // This would be handled by system commands in production
                        // exec("chown -R {$user_info['username']}:www-data $document_root");
                    }
                    
                    // Create enhanced virtual host
                    createEnhancedVirtualHost($domain_name, $document_root, $enable_ssl, $username);
                    
                    // Create comprehensive DNS records
                    $dns_records = [
                        ['record_type' => 'A', 'name' => '@', 'value' => $server_ip, 'ttl' => 3600, 'priority' => 0],
                        ['record_type' => 'MX', 'name' => '@', 'value' => 'mail.' . $domain_name, 'ttl' => 3600, 'priority' => 10],
                        ['record_type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 a mx ip4:' . $server_ip . ' ~all', 'ttl' => 3600, 'priority' => 0]
                    ];
                    
                    // Add www record if requested
                    if ($create_www) {
                        $dns_records[] = ['record_type' => 'CNAME', 'name' => 'www', 'value' => $domain_name, 'ttl' => 3600, 'priority' => 0];
                    }
                    
                    // Add mail records
                    $dns_records[] = ['record_type' => 'A', 'name' => 'mail', 'value' => $server_ip, 'ttl' => 3600, 'priority' => 0];
                    $dns_records[] = ['record_type' => 'A', 'name' => 'ftp', 'value' => $server_ip, 'ttl' => 3600, 'priority' => 0];
                    
                    foreach ($dns_records as $record) {
                        $query = "INSERT INTO dns_zones (domain_id, record_type, name, value, ttl, priority) VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "isssii", $domain_id, $record['record_type'], $record['name'], $record['value'], $record['ttl'], $record['priority']);
                        mysqli_stmt_execute($stmt);
                    }
                    
                    // Create DNS zone file
                    createDNSZoneFile($domain_name, $dns_records);
                    
                    // Create comprehensive default page
                    $index_content = createDefaultWebPage($domain_name, $username, $enable_ssl);
                    file_put_contents($document_root . '/public_html/index.html', $index_content);
                    
                    // Create .htaccess for security
                    $htaccess_content = createSecureHtaccess();
                    file_put_contents($document_root . '/public_html/.htaccess', $htaccess_content);
                    
                    // Schedule SSL certificate if requested
                    if ($enable_ssl) {
                        scheduleSSLCertificate($domain_id, $domain_name);
                    }
                    
                    // Log domain creation
                    error_log("Domain created: $domain_name for user: $username (ID: $user_id)", 3, '/var/log/hosting-panel/domain-creation.log');
                    
                    $message = '<div class="alert alert-success">Domain created successfully! It may take a few minutes to propagate.</div>';
                } else {
                    $message = '<div class="alert alert-error">Error creating domain: ' . mysqli_error($conn) . '</div>';
                }
            }
        }
    }
}

// Get user domains
$query = "SELECT * FROM domains WHERE user_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$domains = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Domain Management - Hosting Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
    <div class="sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
            <h3 style="color: var(--primary-color);">Control Panel</h3>
            <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <div class="sidebar-nav">
            <div class="sidebar-group" data-group-key="user-overview">
                <div class="group-header" role="button" aria-expanded="false">
                    <span class="group-label">Overview</span>
                    <span class="group-arrow">▶</span>
                </div>
                <div class="group-items">
                    <ul class="sidebar-nav">
                        <li><a href="index.php">Dashboard</a></li>
                        <li><a href="profile.php">Profile</a></li>
                    </ul>
                </div>
            </div>

            <div class="sidebar-group open" data-group-key="user-hosting">
                <div class="group-header" role="button" aria-expanded="true">
                    <span class="group-label">Hosting</span>
                    <span class="group-arrow">▶</span>
                </div>
                <div class="group-items">
                    <ul class="sidebar-nav">
                        <li><a href="domains.php" class="active">Domains</a></li>
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
        <h1>Domain Management</h1>
        
        <?= $message ?>
        
        <?php if ($package && ($package['domains_limit'] == 0 || $domain_count < $package['domains_limit'])): ?>
        <div class="card">
            <h3>Add New Domain</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Domain Name</label>
                    <input type="text" name="domain_name" class="form-control" placeholder="example.com" required>
                    <small style="color: var(--text-muted);">Enter domain without www (e.g., example.com)</small>
                </div>
                <button type="submit" name="create_domain" class="btn btn-primary">Create Domain</button>
            </form>
        </div>
        <?php elseif ($package): ?>
        <div class="alert alert-error">
            You have reached your domain limit (<?= $package['domains_limit'] == 0 ? 'Unlimited' : $package['domains_limit'] ?>). Upgrade your package to add more domains.
        </div>
        <?php else: ?>
        <div class="alert alert-error">
            No package assigned. Contact administrator to assign a hosting package.
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3>My Domains (<?= count($domains) ?><?= $package ? '/' . ($package['domains_limit'] == 0 ? 'Unlimited' : $package['domains_limit']) : '' ?>)</h3>
            <?php if ($domains): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Document Root</th>
                        <th>SSL</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $domain): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($domain['domain_name']) ?></strong><br>
                            <small><a href="http://<?= $domain['domain_name'] ?>" target="_blank" style="color: var(--primary-color);">Visit Site</a></small>
                        </td>
                        <td><?= htmlspecialchars($domain['document_root']) ?></td>
                        <td>
                            <span style="color: <?= ($domain['ssl_enabled'] ?? 0) ? 'var(--success-color)' : 'var(--text-muted)' ?>">
                                <?= ($domain['ssl_enabled'] ?? 0) ? 'Enabled' : 'Disabled' ?>
                            </span>
                        </td>
                        <td>
                            <span style="color: <?= $domain['status'] === 'active' ? 'var(--success-color)' : ($domain['status'] === 'suspended' ? 'var(--error-color)' : 'var(--warning-color)') ?>">
                                <?= ucfirst($domain['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M j, Y', strtotime($domain['created_at'])) ?></td>
                        <td>
                            <a href="dns.php?domain_id=<?= $domain['id'] ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">DNS</a>
                            <a href="subdomains.php?domain_id=<?= $domain['id'] ?>" class="btn btn-success" style="padding: 6px 12px; font-size: 12px;">Subdomains</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No domains found. Create your first domain above.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>