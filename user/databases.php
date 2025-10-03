<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Get user package
$package = getUserPackage($conn, $user_id);

// Get database count
$query = "SELECT COUNT(*) as count FROM `databases` WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$db_count = mysqli_fetch_assoc($result)['count'];

// Handle database creation
if ($_POST && isset($_POST['create_database'])) {
    if (!$package) {
        $message = '<div class="alert alert-error">No package assigned.</div>';
    } elseif ($package['databases_limit'] > 0 && $db_count >= $package['databases_limit']) {
        $message = '<div class="alert alert-error">Database limit reached.</div>';
    } else {
        $db_name = $_SESSION['username'] . '_' . sanitize($_POST['database_name']);
        $db_user = $_SESSION['username'] . '_' . sanitize($_POST['database_user']);
        $db_pass = $_POST['database_password'];
        
        if (createDatabase($db_name, $db_user, $db_pass, $conn)) {
            // Save to our tracking table
            $query = "INSERT INTO `databases` (user_id, database_name, database_user, database_password) VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            $hashed_pass = password_hash($db_pass, PASSWORD_DEFAULT);
            mysqli_stmt_bind_param($stmt, "isss", $user_id, $db_name, $db_user, $hashed_pass);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = '<div class="alert alert-success">Database created successfully.</div>';
            }
        } else {
            $message = '<div class="alert alert-error">Error creating database.</div>';
        }
    }
}

// Get user databases
$query = "SELECT * FROM `databases` WHERE user_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$databases = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Management - Hosting Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
            <h3 style="color: var(--primary-color);">Control Panel</h3>
            <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <ul class="sidebar-nav">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="domains.php">Domains</a></li>
            <li><a href="subdomains.php">Subdomains</a></li>
            <li><a href="email.php">Email Accounts</a></li>
            <li><a href="databases.php" class="active">Databases</a></li>
            <li><a href="ftp.php">FTP Accounts</a></li>
            <li><a href="ssl.php">SSL Certificates</a></li>
            <li><a href="backups.php">Backups</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <h1>Database Management</h1>
        
        <?= $message ?>
        
        <?php if ($package && ($package['databases_limit'] == 0 || $db_count < $package['databases_limit'])): ?>
        <div class="card">
            <h3>Create Database</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Database Name</label>
                        <div style="display: flex; align-items: center;">
                            <span style="color: var(--text-muted); margin-right: 8px;"><?= $_SESSION['username'] ?>_</span>
                            <input type="text" name="database_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Database User</label>
                        <div style="display: flex; align-items: center;">
                            <span style="color: var(--text-muted); margin-right: 8px;"><?= $_SESSION['username'] ?>_</span>
                            <input type="text" name="database_user" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="database_password" class="form-control" required>
                        <button type="button" onclick="generateDbPassword()" class="btn btn-success" style="margin-top: 8px; padding: 4px 8px; font-size: 12px;">Generate</button>
                    </div>
                </div>
                <button type="submit" name="create_database" class="btn btn-primary">Create Database</button>
            </form>
        </div>
        <?php elseif ($package): ?>
        <div class="alert alert-error">
            You have reached your database limit (<?= $package['databases_limit'] == 0 ? 'Unlimited' : $package['databases_limit'] ?>).
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3>My Databases (<?= count($databases) ?><?= $package ? '/' . ($package['databases_limit'] == 0 ? 'Unlimited' : $package['databases_limit']) : '' ?>)</h3>
            <?php if ($databases): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Database Name</th>
                        <th>Database User</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($databases as $database): ?>
                    <tr>
                        <td><?= htmlspecialchars($database['database_name']) ?></td>
                        <td><?= htmlspecialchars($database['database_user']) ?></td>
                        <td>
                            <span style="color: <?= $database['status'] === 'active' ? 'var(--success-color)' : 'var(--error-color)' ?>">
                                <?= ucfirst($database['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M j, Y', strtotime($database['created_at'])) ?></td>
                        <td>
                            <a href="http://localhost/pma" target="_blank" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">phpMyAdmin</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No databases found.</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>Database Connection Information</h3>
            <div style="background: var(--bg-tertiary); padding: 16px; border-radius: 8px; font-family: monospace;">
                <strong>Host:</strong> localhost<br>
                <strong>Port:</strong> 3306<br>
                <strong>Username:</strong> [Your database user]<br>
                <strong>Password:</strong> [Your database password]<br>
                <strong>Database:</strong> [Your database name]
            </div>
        </div>
    </div>
    
    <script>
    function generateDbPassword() {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.querySelector('input[name="database_password"]').value = password;
    }
    </script>
</body>
</html>