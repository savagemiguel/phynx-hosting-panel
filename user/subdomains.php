<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Get user package
$package = getUserPackage($conn, $user_id);

// Handle subdomain creation
if ($_POST && isset($_POST['create_subdomain'])) {
    $domain_id = (int)$_POST['domain_id'];
    $subdomain = strtolower(sanitize($_POST['subdomain']));
    
    // Verify domain ownership
    $query = "SELECT domain_name FROM domains WHERE id = ? AND user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $domain_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $domain = mysqli_fetch_assoc($result);
    
    if ($domain) {
        $full_subdomain = $subdomain . '.' . $domain['domain_name'];
        $document_root = WEB_ROOT . $full_subdomain;
        
        // Create subdomain
        $query = "INSERT INTO subdomains (domain_id, subdomain, document_root) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iss", $domain_id, $subdomain, $document_root);
        
        if (mysqli_stmt_execute($stmt)) {
            // Create directory
            createDirectory($document_root);
            
            // Create virtual host
            createVirtualHost($full_subdomain, $document_root);
            
            // Create DNS A record
            $query = "INSERT INTO dns_zones (domain_id, record_type, name, value, ttl) VALUES (?, 'A', ?, '127.0.0.1', 3600)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "is", $domain_id, $subdomain);
            mysqli_stmt_execute($stmt);
            
            $message = '<div class="alert alert-success">Subdomain created successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Error creating subdomain.</div>';
        }
    }
}

// Get user domains
$query = "SELECT id, domain_name FROM domains WHERE user_id = ? AND status = 'active'";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user_domains = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Get subdomains
$query = "SELECT s.*, d.domain_name FROM subdomains s JOIN domains d ON s.domain_id = d.id WHERE d.user_id = ? ORDER BY s.created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subdomains = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Subdomain Management - Hosting Panel</title>
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
                        <li><a href="domains.php">Domains</a></li>
                        <li><a href="subdomains.php" class="active">Subdomains</a></li>
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
        <h1>Subdomain Management</h1>
        
        <?= $message ?>
        
        <?php if ($user_domains): ?>
        <div class="card">
            <h3>Create Subdomain</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Subdomain</label>
                        <input type="text" name="subdomain" class="form-control" placeholder="blog" required>
                    </div>
                    <div class="form-group">
                        <label>Domain</label>
                        <select name="domain_id" class="form-control" required>
                            <option value="">Select Domain</option>
                            <?php foreach ($user_domains as $domain): ?>
                                <option value="<?= $domain['id'] ?>"><?= htmlspecialchars($domain['domain_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="create_subdomain" class="btn btn-primary">Create Subdomain</button>
            </form>
        </div>
        <?php else: ?>
        <div class="alert alert-error">
            You need to create a domain first before adding subdomains.
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3>My Subdomains</h3>
            <?php if ($subdomains): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Subdomain</th>
                        <th>Document Root</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subdomains as $subdomain): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($subdomain['subdomain'] . '.' . $subdomain['domain_name']) ?></strong><br>
                            <small><a href="http://<?= $subdomain['subdomain'] . '.' . $subdomain['domain_name'] ?>" target="_blank" style="color: var(--primary-color);">Visit Site</a></small>
                        </td>
                        <td><?= htmlspecialchars($subdomain['document_root']) ?></td>
                        <td>
                            <span style="color: <?= $subdomain['status'] === 'active' ? 'var(--success-color)' : 'var(--error-color)' ?>">
                                <?= ucfirst($subdomain['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M j, Y', strtotime($subdomain['created_at'])) ?></td>
                        <td>
                            <button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No subdomains found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>