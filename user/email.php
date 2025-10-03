<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Get user package
$package = getUserPackage($user_id, $conn);

// Handle email account creation
if ($_POST && isset($_POST['create_email'])) {
    $domain_id = (int)$_POST['domain_id'];
    $email_prefix = sanitize($_POST['email_prefix']);
    $password = $_POST['password'];
    $quota = (int)$_POST['quota'];
    
    // Verify domain ownership
    $query = "SELECT domain_name FROM domains WHERE id = ? AND user_id = ? AND status = 'active'";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $domain_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $domain = mysqli_fetch_assoc($result);
    
    if ($domain) {
        $full_email = $email_prefix . '@' . $domain['domain_name'];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO email_accounts (domain_id, email, password, quota) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "issi", $domain_id, $full_email, $hashed_password, $quota);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Email account created successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Error creating email account.</div>';
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

// Get email accounts
$query = "SELECT e.*, d.domain_name FROM email_accounts e JOIN domains d ON e.domain_id = d.id WHERE d.user_id = ? ORDER BY e.created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$email_accounts = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Management - Hosting Panel</title>
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
            <li><a href="email.php" class="active">Email Accounts</a></li>
            <li><a href="databases.php">Databases</a></li>
            <li><a href="ftp.php">FTP Accounts</a></li>
            <li><a href="ssl.php">SSL Certificates</a></li>
            <li><a href="backups.php">Backups</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <h1>Email Account Management</h1>
        
        <?= $message ?>
        
        <?php if ($user_domains): ?>
        <div class="card">
            <h3>Create Email Account</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Email Address</label>
                        <div style="display: flex; align-items: center;">
                            <input type="text" name="email_prefix" class="form-control" placeholder="info" required>
                            <span style="margin: 0 8px; color: var(--text-muted);">@</span>
                            <select name="domain_id" class="form-control" required>
                                <option value="">Select Domain</option>
                                <?php foreach ($user_domains as $domain): ?>
                                    <option value="<?= $domain['id'] ?>"><?= htmlspecialchars($domain['domain_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Quota (MB)</label>
                        <input type="number" name="quota" class="form-control" value="100" min="10" max="1000">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" name="create_email" class="btn btn-primary">Create</button>
                    </div>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="alert alert-error">
            You need to create and activate a domain first before adding email accounts.
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3>Email Accounts</h3>
            <?php if ($email_accounts): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Email Address</th>
                        <th>Quota</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($email_accounts as $email): ?>
                    <tr>
                        <td><?= htmlspecialchars($email['email']) ?></td>
                        <td><?= $email['quota'] == 0 ? 'Unlimited' : $email['quota'] . ' MB' ?></td>
                        <td>
                            <span style="color: <?= $email['status'] === 'active' ? 'var(--success-color)' : 'var(--error-color)' ?>">
                                <?= ucfirst($email['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M j, Y', strtotime($email['created_at'])) ?></td>
                        <td>
                            <button class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">Change Password</button>
                            <button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No email accounts found.</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>Email Client Settings</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div>
                    <h4 style="color: var(--primary-color); margin-bottom: 16px;">Incoming Mail (IMAP)</h4>
                    <div style="background: var(--bg-tertiary); padding: 16px; border-radius: 8px; font-family: monospace;">
                        <strong>Server:</strong> mail.yourdomain.com<br>
                        <strong>Port:</strong> 993 (SSL) / 143 (Non-SSL)<br>
                        <strong>Security:</strong> SSL/TLS<br>
                        <strong>Username:</strong> your-email@domain.com<br>
                        <strong>Password:</strong> [Your email password]
                    </div>
                </div>
                <div>
                    <h4 style="color: var(--primary-color); margin-bottom: 16px;">Outgoing Mail (SMTP)</h4>
                    <div style="background: var(--bg-tertiary); padding: 16px; border-radius: 8px; font-family: monospace;">
                        <strong>Server:</strong> mail.yourdomain.com<br>
                        <strong>Port:</strong> 465 (SSL) / 587 (TLS)<br>
                        <strong>Security:</strong> SSL/TLS<br>
                        <strong>Authentication:</strong> Required<br>
                        <strong>Username:</strong> your-email@domain.com
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>