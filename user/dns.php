<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$domain_id = (int)$_GET['domain_id'];
$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Verify domain ownership (mysqli)
$stmt = mysqli_prepare($conn, "SELECT * FROM domains WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $domain_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$domain = mysqli_fetch_assoc($result);

if (!$domain) {
    header('Location: domains.php');
    exit;
}

// Handle DNS record actions
if ($_POST) {
    if (isset($_POST['add_record'])) {
        $record_type = $_POST['record_type'];
        $name = sanitize($_POST['name']);
        $value = sanitize($_POST['value']);
        $ttl = (int)$_POST['ttl'];
        $priority = (int)$_POST['priority'];
        
        $stmt = mysqli_prepare($conn, "INSERT INTO dns_zones (domain_id, record_type, name, value, ttl, priority) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isssii", $domain_id, $record_type, $name, $value, $ttl, $priority);
        if (mysqli_stmt_execute($stmt)) {
            // Regenerate DNS zone file
            $records_stmt = mysqli_prepare($conn, "SELECT * FROM dns_zones WHERE domain_id = ?");
            mysqli_stmt_bind_param($records_stmt, "i", $domain_id);
            mysqli_stmt_execute($records_stmt);
            $records_res = mysqli_stmt_get_result($records_stmt);
            $all_records = mysqli_fetch_all($records_res, MYSQLI_ASSOC);
            createDNSZoneFile($domain['domain_name'], $all_records);

            $message = '<div class="alert alert-success">DNS record added successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error adding DNS record</div>';
        }
    }
    
    if (isset($_POST['delete_record'])) {
        $record_id = (int)$_POST['record_id'];
        
        $stmt = mysqli_prepare($conn, "DELETE FROM dns_zones WHERE id = ? AND domain_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $record_id, $domain_id);
        mysqli_stmt_execute($stmt);
        
        // Regenerate DNS zone file
        $records_stmt = mysqli_prepare($conn, "SELECT * FROM dns_zones WHERE domain_id = ?");
        mysqli_stmt_bind_param($records_stmt, "i", $domain_id);
        mysqli_stmt_execute($records_stmt);
        $records_res = mysqli_stmt_get_result($records_stmt);
        $all_records = mysqli_fetch_all($records_res, MYSQLI_ASSOC);
        createDNSZoneFile($domain['domain_name'], $all_records);
        
        $message = '<div class="alert alert-success">DNS record deleted successfully</div>';
    }
}

// Get DNS records
$stmt = mysqli_prepare($conn, "SELECT * FROM dns_zones WHERE domain_id = ? ORDER BY record_type, name");
mysqli_stmt_bind_param($stmt, "i", $domain_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$dns_records = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>DNS Management - <?= htmlspecialchars($domain['domain_name']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
            <h3 style="color: var(--primary-color);">User Panel</h3>
            <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <ul class="sidebar-nav">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="domains.php">My Domains</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <h1>DNS Management - <?= htmlspecialchars($domain['domain_name']) ?></h1>
        <a href="domains.php" class="btn btn-primary" style="margin-bottom: 24px;">← Back to My Domains</a>
        
        <?= $message ?>
        
        <div class="card">
            <h3>Add DNS Record</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr 2fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="record_type" class="form-control" required>
                            <option value="A">A</option>
                            <option value="AAAA">AAAA</option>
                            <option value="CNAME">CNAME</option>
                            <option value="MX">MX</option>
                            <option value="TXT">TXT</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" placeholder="@ or subdomain" required>
                    </div>
                    <div class="form-group">
                        <label>Value</label>
                        <input type="text" name="value" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>TTL</label>
                        <input type="number" name="ttl" class="form-control" value="3600" required>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <input type="number" name="priority" class="form-control" value="0">
                    </div>
                </div>
                <button type="submit" name="add_record" class="btn btn-primary">Add Record</button>
            </form>
        </div>
        
        <div class="card">
            <h3>Current DNS Records</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Value</th>
                        <th>TTL</th>
                        <th>Priority</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dns_records as $record): ?>
                    <tr>
                        <td><?= $record['record_type'] ?></td>
                        <td><?= htmlspecialchars($record['name']) ?></td>
                        <td><?= htmlspecialchars($record['value']) ?></td>
                        <td><?= $record['ttl'] ?></td>
                        <td><?= $record['priority'] ?></td>
                        <td>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this record?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
                                <button type="submit" name="delete_record" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>