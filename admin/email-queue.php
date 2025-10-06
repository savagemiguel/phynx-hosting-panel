<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Create email queue table if it doesn't exist
$create_queue_table = "
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_email VARCHAR(255) NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL
)";
mysqli_query($conn, $create_queue_table);

// Handle queue actions
if ($_POST) {
    if (isset($_POST['send_test_email'])) {
        $to_email = sanitize($_POST['to_email']);
        $subject = sanitize($_POST['subject']);
        $message_body = sanitize($_POST['message']);
        
        $query = "INSERT INTO email_queue (from_email, to_email, subject, message) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        $from_email = 'noreply@' . $_SERVER['HTTP_HOST'];
        mysqli_stmt_bind_param($stmt, "ssss", $from_email, $to_email, $subject, $message_body);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Email added to queue</div>';
        }
    }
    
    if (isset($_POST['clear_queue'])) {
        mysqli_query($conn, "DELETE FROM email_queue WHERE status = 'sent'");
        $message = '<div class="alert alert-success">Sent emails cleared from queue</div>';
    }
    
    if (isset($_POST['retry_failed'])) {
        mysqli_query($conn, "UPDATE email_queue SET status = 'pending', attempts = 0 WHERE status = 'failed'");
        $message = '<div class="alert alert-success">Failed emails reset to pending</div>';
    }
}

// Get queue statistics
$pending_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_queue WHERE status = 'pending'")->fetch_assoc()['count'];
$sent_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_queue WHERE status = 'sent'")->fetch_assoc()['count'];
$failed_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_queue WHERE status = 'failed'")->fetch_assoc()['count'];

// Get queue items
$queue_result = mysqli_query($conn, "SELECT * FROM email_queue ORDER BY created_at DESC LIMIT 50");
$queue_items = mysqli_fetch_all($queue_result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Queue - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1>Email Queue</h1>
        
        <?= $message ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $pending_count ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $sent_count ?></div>
                <div class="stat-label">Sent</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $failed_count ?></div>
                <div class="stat-label">Failed</div>
            </div>
        </div>
        
        <div class="card">
            <h3>Send Test Email</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>To Email</label>
                        <input type="email" name="to_email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" value="Test Email from Hosting Panel" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" class="form-control" rows="4" required>This is a test email from your hosting panel.</textarea>
                </div>
                <button type="submit" name="send_test_email" class="btn btn-primary">Add to Queue</button>
            </form>
        </div>
        
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Email Queue (Last 50)</h3>
                <div>
                    <form method="POST" style="display: inline;">
                        <?php csrf_field(); ?>
                        <button type="submit" name="retry_failed" class="btn btn-success" style="margin-right: 8px;">Retry Failed</button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <?php csrf_field(); ?>
                        <button type="submit" name="clear_queue" class="btn btn-danger" onclick="return confirm('Clear all sent emails?')">Clear Sent</button>
                    </form>
                </div>
            </div>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Created</th>
                        <th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['from_email']) ?></td>
                        <td><?= htmlspecialchars($item['to_email']) ?></td>
                        <td><?= htmlspecialchars($item['subject']) ?></td>
                        <td>
                            <span style="color: <?= 
                                $item['status'] === 'sent' ? 'var(--success-color)' : 
                                ($item['status'] === 'failed' ? 'var(--error-color)' : 'var(--warning-color)') 
                            ?>">
                                <?= ucfirst($item['status']) ?>
                            </span>
                        </td>
                        <td><?= $item['attempts'] ?></td>
                        <td><?= date('M j, H:i', strtotime($item['created_at'])) ?></td>
                        <td><?= $item['sent_at'] ? date('M j, H:i', strtotime($item['sent_at'])) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h3>Queue Processing</h3>
            <p style="color: var(--text-secondary); margin-bottom: 16px;">
                The email queue processes automatically. You can also set up a cron job to process emails:
            </p>
            <div style="background: var(--bg-tertiary); padding: 16px; border-radius: 8px; font-family: monospace;">
                */5 * * * * php /path/to/hosting-panel/process-email-queue.php
            </div>
        </div>
    </div>
</body>
</html>
