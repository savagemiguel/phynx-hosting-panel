<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$edit_package = null;

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Get package for editing if edit parameter is set
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $query = "SELECT * FROM packages WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $edit_package = mysqli_fetch_assoc($result);
}

// Handle package actions
if ($_POST) {
    if (isset($_POST['update_package'])) {
        $package_id = (int)$_POST['package_id'];
        $name = sanitize($_POST['name']);
        $disk_space = (int)$_POST['disk_space'];
        $bandwidth = (int)$_POST['bandwidth'];
        $domains_limit = (int)$_POST['domains_limit'];
        $subdomains_limit = (int)$_POST['subdomains_limit'];
        $email_accounts = (int)$_POST['email_accounts'];
        $databases_limit = (int)$_POST['databases_limit'];
        $ftp_accounts = (int)$_POST['ftp_accounts'];
        $ssl_certificates = (int)$_POST['ssl_certificates'];
        $price = (float)$_POST['price'];
        
        $query = "UPDATE packages SET name = ?, disk_space = ?, bandwidth = ?, domains_limit = ?, subdomains_limit = ?, email_accounts = ?, databases_limit = ?, ftp_accounts = ?, ssl_certificates = ?, price = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "siiiiiiiidi", $name, $disk_space, $bandwidth, $domains_limit, $subdomains_limit, $email_accounts, $databases_limit, $ftp_accounts, $ssl_certificates, $price, $package_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Package updated successfully</div>';
            header('Location: packages.php');
            exit;
        } else {
            $message = '<div class="alert alert-error">Error: ' . mysqli_error($conn) . '</div>';
        }
    }
    
    if (isset($_POST['create_package'])) {
        $name = sanitize($_POST['name']);
        $disk_space = (int)$_POST['disk_space'];
        $bandwidth = (int)$_POST['bandwidth'];
        $domains_limit = (int)$_POST['domains_limit'];
        $subdomains_limit = (int)$_POST['subdomains_limit'];
        $email_accounts = (int)$_POST['email_accounts'];
        $databases_limit = (int)$_POST['databases_limit'];
        $ftp_accounts = (int)$_POST['ftp_accounts'];
        $ssl_certificates = (int)$_POST['ssl_certificates'];
        $price = (float)$_POST['price'];
        
        $query = "INSERT INTO packages (name, disk_space, bandwidth, domains_limit, subdomains_limit, email_accounts, databases_limit, ftp_accounts, ssl_certificates, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "siiiiiiiid", $name, $disk_space, $bandwidth, $domains_limit, $subdomains_limit, $email_accounts, $databases_limit, $ftp_accounts, $ssl_certificates, $price);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Package created successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error: ' . mysqli_error($conn) . '</div>';
        }
    }
    
    if (isset($_POST['toggle_status'])) {
        $package_id = (int)$_POST['package_id'];
        $current_status = $_POST['current_status'];
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        
        $query = "UPDATE packages SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $new_status, $package_id);
        mysqli_stmt_execute($stmt);
        $message = '<div class="alert alert-success">Package status updated</div>';
    }
}

// Get packages
$packages_result = mysqli_query($conn, "SELECT * FROM packages ORDER BY created_at DESC");
$packages = mysqli_fetch_all($packages_result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Packages - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1>Package Management</h1>
        
        <?= $message ?>
        
        <div class="card">
            <h3><?= $edit_package ? 'Edit Package' : 'Create New Package' ?></h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <?php if ($edit_package): ?>
                    <input type="hidden" name="package_id" value="<?= $edit_package['id'] ?>">
                <?php endif; ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Package Name</label>
                        <input type="text" name="name" class="form-control" value="<?= $edit_package ? htmlspecialchars($edit_package['name']) : '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Disk Space (MB)</label>
                        <input type="number" name="disk_space" class="form-control" value="<?= $edit_package ? $edit_package['disk_space'] : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Bandwidth (MB)</label>
                        <input type="number" name="bandwidth" class="form-control" value="<?= $edit_package ? $edit_package['bandwidth'] : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Domains Limit</label>
                        <input type="number" name="domains_limit" class="form-control" value="<?= $edit_package ? $edit_package['domains_limit'] : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Subdomains Limit</label>
                        <input type="number" name="subdomains_limit" class="form-control" value="<?= $edit_package ? $edit_package['subdomains_limit'] : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Email Accounts</label>
                        <input type="number" name="email_accounts" class="form-control" value="<?= $edit_package ? $edit_package['email_accounts'] : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Databases Limit</label>
                        <input type="number" name="databases_limit" class="form-control" value="<?= $edit_package ? $edit_package['databases_limit'] : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>FTP Accounts</label>
                        <input type="number" name="ftp_accounts" class="form-control" value="<?= $edit_package ? ($edit_package['ftp_accounts'] ?? 0) : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>SSL Certificates</label>
                        <input type="number" name="ssl_certificates" class="form-control" value="<?= $edit_package ? ($edit_package['ssl_certificates'] ?? 0) : '' ?>" required>
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Price ($)</label>
                        <input type="number" step="0.01" name="price" class="form-control" value="<?= $edit_package ? $edit_package['price'] : '' ?>" required>
                    </div>
                </div>
                <button type="submit" name="<?= $edit_package ? 'update_package' : 'create_package' ?>" class="btn btn-primary"><?= $edit_package ? 'Update Package' : 'Create Package' ?></button>
                <?php if ($edit_package): ?>
                    <a href="packages.php" class="btn btn-secondary" style="margin-left: 12px; background: var(--bg-tertiary); color: var(--text-primary);">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="card">
            <h3>All Packages</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Disk Space</th>
                        <th>Bandwidth</th>
                        <th>Domains</th>
                        <th>Email</th>
                        <th>Databases</th>
                        <th>FTP</th>
                        <th>SSL</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $package): ?>
                    <tr>
                        <td><?= htmlspecialchars($package['name']) ?></td>
                        <td><?= $package['disk_space'] == 0 ? 'Unlimited' : formatBytes($package['disk_space'] * 1024 * 1024) ?></td>
                        <td><?= $package['bandwidth'] == 0 ? 'Unlimited' : formatBytes($package['bandwidth'] * 1024 * 1024) ?></td>
                        <td><?= $package['domains_limit'] == 0 ? 'Unlimited' : $package['domains_limit'] ?></td>
                        <td><?= $package['email_accounts'] == 0 ? 'Unlimited' : $package['email_accounts'] ?></td>
                        <td><?= $package['databases_limit'] == 0 ? 'Unlimited' : $package['databases_limit'] ?></td>
                        <td><?= ($package['ftp_accounts'] ?? 0) == 0 ? 'Unlimited' : ($package['ftp_accounts'] ?? 0) ?></td>
                        <td><?= ($package['ssl_certificates'] ?? 0) == 0 ? 'Unlimited' : ($package['ssl_certificates'] ?? 0) ?></td>
                        <td>$<?= number_format($package['price'], 2) ?></td>
                        <td><?= ucfirst($package['status']) ?></td>
                        <td class="actions-cell">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <a href="packages.php?edit=<?= $package['id'] ?>" class="btn btn-primary" style="padding: 6px 16px; font-size: 12px; height: 32px; display: inline-flex; align-items: center;">Edit</a>
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="package_id" value="<?= $package['id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $package['status'] ?>">
                                    <button type="submit" name="toggle_status" class="btn <?= $package['status'] === 'active' ? 'btn-danger' : 'btn-success' ?>" style="padding: 6px 16px; font-size: 12px; height: 32px;">
                                        <?= $package['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
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