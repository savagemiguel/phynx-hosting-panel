<?php
require_once '../config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Handle MySQL service actions
$result = ''; // Initialize result variable as empty string
if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
if ($_POST['action'] ?? false) {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'start':
            exec('net start mysql 2>&1', $output, $return_code);
            $result = $return_code === 0 ? 'MySQL service started successfully' : 'Failed to start MySQL service';
            break;
        case 'stop':
            exec('net stop mysql 2>&1', $output, $return_code);
            $result = $return_code === 0 ? 'MySQL service stopped successfully' : 'Failed to stop MySQL service';
            break;
        case 'restart':
            exec('net stop mysql && net start mysql 2>&1', $output, $return_code);
            $result = $return_code === 0 ? 'MySQL service restarted successfully' : 'Failed to restart MySQL service';
            break;
    }
}

// Get MySQL service status
exec('sc query mysql 2>&1', $status_output);
$mysql_running = strpos(implode(' ', $status_output), 'RUNNING') !== false;

// Get database statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM users WHERE role = 'user') as total_users,
    (SELECT COUNT(*) FROM domains) as total_domains,
    (SELECT COUNT(*) FROM email_accounts) as total_emails,
    (SELECT COUNT(*) FROM `databases`) as total_databases";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1>Database Manager</h1>

            <?php if (!empty($result)): ?>
                <div class="alert <?= strpos($result, 'successfully') !== false ? 'alert-success' : 'alert-error' ?>">
                    <?= htmlspecialchars($result) ?>
                </div>
            <?php endif; ?>

            <div class="grid">
                <!-- MySQL Service Status -->
                <div class="card">
                    <div class="card-header">
                        <h3>MySQL Service Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="service-status">
                            <span class="status-indicator <?= $mysql_running ? 'status-running' : 'status-stopped' ?>"></span>
                            <span class="status-text"><?= $mysql_running ? 'Running' : 'Stopped' ?></span>
                        </div>
                        
                        <div class="service-controls">
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="start">
                                <button type="submit" class="btn btn-success" <?= $mysql_running ? 'disabled' : '' ?>>Start</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="stop">
                                <button type="submit" class="btn btn-danger" <?= !$mysql_running ? 'disabled' : '' ?>>Stop</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="restart">
                                <button type="submit" class="btn btn-warning" <?= !$mysql_running ? 'disabled' : '' ?>>Restart</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Database Statistics -->
                <div class="card">
                    <div class="card-header">
                        <h3>Database Statistics</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-number"><?= $stats['total_users'] ?></div>
                                <div class="stat-label">Total Users</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= $stats['total_domains'] ?></div>
                                <div class="stat-label">Total Domains</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= $stats['total_emails'] ?></div>
                                <div class="stat-label">Email Accounts</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= $stats['total_databases'] ?></div>
                                <div class="stat-label">User Databases</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- phpMyAdmin Access -->
                <div class="card">
                    <div class="card-header">
                        <h3>Database Management Tools</h3>
                    </div>
                    <div class="card-body">
                        <div class="tool-links">
                            <a href="http://localhost/pma" target="_blank" class="btn btn-primary">
                                <span>📊</span> Open phpMyAdmin
                            </a>
                            <p class="help-text">Access phpMyAdmin to manage databases, tables, and run SQL queries.</p>
                        </div>
                    </div>
                </div>
            </div>
    </div>
</body>
</html>