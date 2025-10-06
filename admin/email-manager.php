<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Handle email actions
if ($_POST) {
    if (isset($_POST['delete_email'])) {
        $email_id = (int)$_POST['email_id'];
        
        $query = "DELETE FROM email_accounts WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $email_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Email account deleted successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error deleting email account</div>';
        }
    }
    
    if (isset($_POST['update_quota'])) {
        $email_id = (int)$_POST['email_id'];
        $quota = (int)$_POST['quota'];
        
        $query = "UPDATE email_accounts SET quota = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $quota, $email_id);
        mysqli_stmt_execute($stmt);
        $message = '<div class="alert alert-success">Email quota updated</div>';
    }
}

// Get all email accounts
$email_result = mysqli_query($conn, "
    SELECT e.*, d.domain_name, u.username 
    FROM email_accounts e 
    JOIN domains d ON e.domain_id = d.id 
    JOIN users u ON d.user_id = u.id 
    ORDER BY e.created_at DESC
");
$email_accounts = mysqli_fetch_all($email_result, MYSQLI_ASSOC);

// Get email statistics
$total_emails = mysqli_num_rows($email_result);
$active_emails = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_accounts WHERE status = 'active'")->fetch_assoc()['count'];
$suspended_emails = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_accounts WHERE status = 'suspended'")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1>Email Manager</h1>
        <a href="create-email.php" class="btn btn-primary" style="margin-bottom: 24px;">Create Email Account</a>
        
        <?= $message ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $total_emails ?></div>
                <div class="stat-label">Total Email Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $active_emails ?></div>
                <div class="stat-label">Active Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $suspended_emails ?></div>
                <div class="stat-label">Suspended Accounts</div>
            </div>
        </div>
        
        <div class="card">
            <h3>All Email Accounts</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Email Address</th>
                        <th>Domain</th>
                        <th>Owner</th>
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
                        <td><?= htmlspecialchars($email['domain_name']) ?></td>
                        <td><?= htmlspecialchars($email['username']) ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="email_id" value="<?= $email['id'] ?>">
                                <input type="number" name="quota" value="<?= $email['quota'] ?>" style="width: 80px; padding: 4px; background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 4px;" min="0">
                                <button type="submit" name="update_quota" class="btn btn-primary" style="padding: 4px 8px; font-size: 11px; margin-left: 4px;">Update</button>
                            </form>
                            <br><small style="color: var(--text-muted);"><?= $email['quota'] == 0 ? 'Unlimited' : $email['quota'] . ' MB' ?></small>
                        </td>
                        <td>
                            <span style="color: <?= $email['status'] === 'active' ? 'var(--success-color)' : 'var(--error-color)' ?>">
                                <?= ucfirst($email['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M j, Y', strtotime($email['created_at'])) ?></td>
                        <td class="actions-cell">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this email account?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="email_id" value="<?= $email['id'] ?>">
                                    <button type="submit" name="delete_email" class="btn btn-danger" style="padding: 6px 16px; font-size: 12px; height: 32px;">Delete</button>
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