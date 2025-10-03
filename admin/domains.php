<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Handle domain actions
if ($_POST) {
    if (isset($_POST['create_domain'])) {
        $user_id = (int)$_POST['user_id'];
        $domain_name = strtolower(sanitize($_POST['domain_name']));
        $document_root = WEB_ROOT . $domain_name;
        
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain_name)) {
            $message = '<div class="alert alert-error">Invalid domain name format.</div>';
        } else {
            $query = "INSERT INTO domains (user_id, domain_name, document_root, status) VALUES (?, ?, ?, 'active')";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "iss", $user_id, $domain_name, $document_root);
            
            if (mysqli_stmt_execute($stmt)) {
                $domain_id = mysqli_insert_id($conn);
                
                // Create directory
                createDirectory($document_root);
                
                // Create virtual host
                createVirtualHost($domain_name, $document_root);
                
                // Create default DNS records
                $dns_records = [
                    ['record_type' => 'A', 'name' => '@', 'value' => '127.0.0.1', 'ttl' => 3600, 'priority' => 0],
                    ['record_type' => 'A', 'name' => 'www', 'value' => '127.0.0.1', 'ttl' => 3600, 'priority' => 0],
                    ['record_type' => 'MX', 'name' => '@', 'value' => 'mail.' . $domain_name, 'ttl' => 3600, 'priority' => 10]
                ];
                
                foreach ($dns_records as $record) {
                    $query = "INSERT INTO dns_zones (domain_id, record_type, name, value, ttl, priority) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "isssii", $domain_id, $record['record_type'], $record['name'], $record['value'], $record['ttl'], $record['priority']);
                    mysqli_stmt_execute($stmt);
                }
                
                // Create DNS zone file
                createDNSZoneFile($domain_name, $dns_records);
                
                // Create default index.html
                $index_content = "<!DOCTYPE html>\n<html>\n<head>\n    <title>Welcome to $domain_name</title>\n</head>\n<body>\n    <h1>Welcome to $domain_name</h1>\n    <p>Your domain is now active!</p>\n</body>\n</html>";
                file_put_contents($document_root . '/index.html', $index_content);
                
                $message = '<div class="alert alert-success">Domain created successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Error creating domain.</div>';
            }
        }
    }
    
    if (isset($_POST['delete_domain'])) {
        $domain_id = (int)$_POST['domain_id'];
        
        // Get domain info before deletion
        $query = "SELECT domain_name, document_root FROM domains WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $domain_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $domain_info = mysqli_fetch_assoc($result);
        
        if ($domain_info) {
            // Delete domain and related records (CASCADE will handle DNS zones, etc.)
            $query = "DELETE FROM domains WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $domain_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Delete DNS zone file
                $zone_file = DNS_ZONE_PATH . $domain_info['domain_name'] . '.zone';
                if (file_exists($zone_file)) {
                    unlink($zone_file);
                }
                
                $message = '<div class="alert alert-success">Domain deleted successfully</div>';
            } else {
                $message = '<div class="alert alert-error">Error deleting domain</div>';
            }
        }
    }
    
    if (isset($_POST['update_status'])) {
        $domain_id = (int)$_POST['domain_id'];
        $status = $_POST['status'];
        
        $query = "UPDATE domains SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $status, $domain_id);
        mysqli_stmt_execute($stmt);
        $message = '<div class="alert alert-success">Domain status updated</div>';
    }
}

// Get users for dropdown
$users_result = mysqli_query($conn, "SELECT id, username FROM users WHERE role = 'user' AND status = 'active' ORDER BY username");
$users = mysqli_fetch_all($users_result, MYSQLI_ASSOC);

// Get domains with user info
$domains_result = mysqli_query($conn, "
    SELECT d.*, u.username 
    FROM domains d 
    JOIN users u ON d.user_id = u.id 
    ORDER BY d.created_at DESC
");
$domains = mysqli_fetch_all($domains_result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Domains - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-globe"></i> Domain Management</h1>
        
        <?= $message ?>
        
        <div class="card">
            <h3>Create Domain for User</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Select User</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">Select User</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Domain Name</label>
                        <input type="text" name="domain_name" class="form-control" placeholder="example.com" required>
                        <small style="color: var(--text-muted);">Enter domain without www (e.g., example.com)</small>
                    </div>
                </div>
                <button type="submit" name="create_domain" class="btn btn-primary">Create Domain</button>
            </form>
        </div>
        
        <div class="card">
            <h3>All Domains</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Owner</th>
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
                            <small><a href="http://<?= $domain['domain_name'] ?>" target="_blank" style="color: var(--primary-color);">Visit</a></small>
                        </td>
                        <td><?= htmlspecialchars($domain['username']) ?></td>
                        <td><?= htmlspecialchars($domain['document_root'] ?? 'Not set') ?></td>
                        <td>
                            <span style="color: <?= ($domain['ssl_enabled'] ?? 0) ? 'var(--success-color)' : 'var(--text-muted)' ?>">
                                <?= ($domain['ssl_enabled'] ?? 0) ? 'Enabled' : 'Disabled' ?>
                            </span>
                        </td>
                        <td><?= ucfirst($domain['status']) ?></td>
                        <td><?= date('M j, Y', strtotime($domain['created_at'])) ?></td>
                        <td class="actions-cell">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                    <select name="status" class="form-control" onchange="this.form.submit()" style="width: auto; min-width: 120px; padding: 6px 24px 6px 12px; font-size: 12px; height: 32px;">
                                        <option value="active" <?= $domain['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="suspended" <?= $domain['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                        <option value="pending" <?= $domain['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    </select>
                                    <input type="hidden" name="update_status" value="1">
                                </form>
                                <a href="dns.php?domain_id=<?= $domain['id'] ?>" class="btn btn-primary" style="padding: 6px 16px; font-size: 12px; height: 32px; display: inline-flex; align-items: center;">DNS</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this domain? This action cannot be undone.')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                    <button type="submit" name="delete_domain" class="btn btn-danger" style="padding: 6px 16px; font-size: 12px; height: 32px;">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>