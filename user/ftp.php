<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

// Load package details for limits
$package = getUserPackage($conn, $user_id);

// Current FTP accounts count
$query = "SELECT COUNT(*) as count FROM `ftp_accounts` WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$ftp_count = (int) (mysqli_fetch_assoc($result)['count'] ?? 0);

// Handle FTP account creation
if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
if ($_POST && isset($_POST['create_ftp'])) {
    if (!$package) {
        $message = '<div class="alert alert-error">No package assigned.</div>';
    } elseif ($package['ftp_accounts'] > 0 && $ftp_count >= $package['ftp_accounts']) {
        $message = '<div class="alert alert-error">FTP account limit reached.</div>';
    } else {
        $user_suffix = sanitize($_POST['ftp_user'] ?? '');
        $password = $_POST['ftp_password'] ?? '';
        $home_directory = trim($_POST['home_directory'] ?? '');

        if ($user_suffix === '' || $password === '') {
            $message = '<div class="alert alert-error">Please provide a username and password.</div>';
        } else {
            $ftp_user = $_SESSION['username'] . '_' . $user_suffix;
            if ($home_directory === '') {
                $home_directory = rtrim(WEB_ROOT, '/\\') . '/' . $ftp_user;
            }
            // Normalize slashes to forward slashes for consistency on Windows paths
            $home_directory = str_replace('\\', '/', $home_directory);

            // Security: ensure home directory stays under WEB_ROOT
            $webroot_norm = str_replace('\\', '/', rtrim(WEB_ROOT, '/\\'));
            if (strpos($home_directory, $webroot_norm) !== 0) {
                $message = '<div class="alert alert-error">Home directory must be inside ' . htmlspecialchars($webroot_norm) . '.</div>';
            } else {
                // Create directory if not exists
                createDirectory($home_directory);

                // Save FTP account record (store hashed password)
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert = "INSERT INTO `ftp_accounts` (user_id, username, password, home_directory) VALUES (?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $insert);
                mysqli_stmt_bind_param($stmt, "isss", $user_id, $ftp_user, $hashed_password, $home_directory);
                if (mysqli_stmt_execute($stmt)) {
                    $message = '<div class="alert alert-success">FTP account created successfully.</div>';
                    // Update count for rendering conditions
                    $ftp_count++;
                } else {
                    $message = '<div class="alert alert-error">Error creating FTP account.</div>';
                }
            }
        }
    }
}

// Get user FTP accounts
$query = "SELECT * FROM `ftp_accounts` WHERE user_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$ftp_accounts = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>FTP Account Management - Hosting Panel</title>
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
                        <li><a href="ftp.php" class="active">FTP Accounts</a></li>
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
        <h1>FTP Account Management</h1>

        <?= $message ?>

        <?php if ($package && ($package['ftp_accounts'] == 0 || $ftp_count < $package['ftp_accounts'])): ?>
        <div class="card">
            <h3>Create FTP Account</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>FTP Username</label>
                        <div style="display: flex; align-items: center;">
                            <span style="color: var(--text-muted); margin-right: 8px;"><?= $_SESSION['username'] ?>_</span>
                            <input type="text" name="ftp_user" class="form-control" placeholder="username" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="ftp_password" class="form-control" required>
                        <button type="button" onclick="generateFtpPassword()" class="btn btn-success" style="margin-top: 8px; padding: 4px 8px; font-size: 12px;">Generate</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Home Directory</label>
                    <input type="text" name="home_directory" class="form-control" value="<?= htmlspecialchars(rtrim(WEB_ROOT, '/\\') . '/' . $_SESSION['username']) ?>">
                    <small style="color: var(--text-muted);">Directory must be inside <?= htmlspecialchars(rtrim(WEB_ROOT, '/\\')) ?>.</small>
                </div>
                <button type="submit" name="create_ftp" class="btn btn-primary">Create FTP Account</button>
            </form>
        </div>
        <?php elseif ($package): ?>
        <div class="alert alert-error">
            You have reached your FTP account limit (<?= $package['ftp_accounts'] == 0 ? 'Unlimited' : $package['ftp_accounts'] ?>).
        </div>
        <?php else: ?>
        <div class="alert alert-error">
            No package assigned. Contact administrator to assign a hosting package.
        </div>
        <?php endif; ?>

        <div class="card">
            <h3>My FTP Accounts (<?= count($ftp_accounts) ?><?= $package ? '/' . ($package['ftp_accounts'] == 0 ? 'Unlimited' : $package['ftp_accounts']) : '' ?>)</h3>
            <?php if ($ftp_accounts): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Home Directory</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ftp_accounts as $ftp): ?>
                    <tr>
                        <td><?= htmlspecialchars($ftp['username']) ?></td>
                        <td><?= htmlspecialchars($ftp['home_directory']) ?></td>
                        <td>
                            <span style="color: <?= $ftp['status'] === 'active' ? 'var(--success-color)' : 'var(--error-color)' ?>">
                                <?= ucfirst($ftp['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M j, Y', strtotime($ftp['created_at'])) ?></td>
                        <td>
                            <button class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">Change Password</button>
                            <button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No FTP accounts found.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>FTP Client Settings</h3>
            <div style="background: var(--bg-tertiary); padding: 16px; border-radius: 8px; font-family: monospace;">
                <strong>FTP Host:</strong> your-server-hostname<br>
                <strong>Port:</strong> 21<br>
                <strong>Protocol:</strong> FTP (explicit TLS if supported)<br>
                <strong>Username:</strong> <?= $_SESSION['username'] ?>_[your-ftp-username]<br>
                <strong>Password:</strong> [Your FTP password]
            </div>
        </div>
    </div>

    <script>
    function generateFtpPassword() {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.querySelector('input[name="ftp_password"]').value = password;
    }
    </script>
</body>
</html>
