<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle DNS record actions
if ($_POST) {
    if (isset($_POST['add_record'])) {
        $domain_id = (int)$_POST['domain_id'];
        $record_type = $_POST['record_type'];
        $name = sanitize($_POST['name']);
        $value = sanitize($_POST['value']);
        $ttl = (int)$_POST['ttl'] ?: 3600;
        $priority = (int)$_POST['priority'];
        
        $query = "INSERT INTO dns_zones (domain_id, record_type, name, value, ttl, priority) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "isssii", $domain_id, $record_type, $name, $value, $ttl, $priority);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">DNS record added successfully.</div>';
            
            // Update DNS zone file
            $domain_query = "SELECT domain_name FROM domains WHERE id = ?";
            $domain_stmt = mysqli_prepare($conn, $domain_query);
            mysqli_stmt_bind_param($domain_stmt, "i", $domain_id);
            mysqli_stmt_execute($domain_stmt);
            $domain_result = mysqli_stmt_get_result($domain_stmt);
            $domain_data = mysqli_fetch_assoc($domain_result);
            
            if ($domain_data) {
                // Get all DNS records for this domain
                $records_query = "SELECT record_type, name, value, ttl, priority FROM dns_zones WHERE domain_id = ?";
                $records_stmt = mysqli_prepare($conn, $records_query);
                mysqli_stmt_bind_param($records_stmt, "i", $domain_id);
                mysqli_stmt_execute($records_stmt);
                $records_result = mysqli_stmt_get_result($records_stmt);
                $dns_records = [];
                while ($row = mysqli_fetch_assoc($records_result)) {
                    $dns_records[] = $row;
                }
                createDNSZoneFile($domain_data['domain_name'], $dns_records);
            }
        } else {
            $message = '<div class="alert alert-error">Error adding DNS record.</div>';
        }
    }
    
    if (isset($_POST['delete_record'])) {
        $record_id = (int)$_POST['record_id'];
        
        // Get domain info before deleting
        $domain_query = "SELECT d.domain_name FROM domains d JOIN dns_zones dz ON d.id = dz.domain_id WHERE dz.id = ?";
        $domain_stmt = mysqli_prepare($conn, $domain_query);
        mysqli_stmt_bind_param($domain_stmt, "i", $record_id);
        mysqli_stmt_execute($domain_stmt);
        $domain_result = mysqli_stmt_get_result($domain_stmt);
        $domain_data = mysqli_fetch_assoc($domain_result);
        
        $query = "DELETE FROM dns_zones WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $record_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">DNS record deleted successfully.</div>';
            
            // Update DNS zone file
            if ($domain_data) {
                // Get remaining DNS records for this domain
                $domain_id_query = "SELECT id FROM domains WHERE domain_name = ?";
                $domain_id_stmt = mysqli_prepare($conn, $domain_id_query);
                mysqli_stmt_bind_param($domain_id_stmt, "s", $domain_data['domain_name']);
                mysqli_stmt_execute($domain_id_stmt);
                $domain_id_result = mysqli_stmt_get_result($domain_id_stmt);
                $domain_id_row = mysqli_fetch_assoc($domain_id_result);
                
                if ($domain_id_row) {
                    $records_query = "SELECT record_type, name, value, ttl, priority FROM dns_zones WHERE domain_id = ?";
                    $records_stmt = mysqli_prepare($conn, $records_query);
                    mysqli_stmt_bind_param($records_stmt, "i", $domain_id_row['id']);
                    mysqli_stmt_execute($records_stmt);
                    $records_result = mysqli_stmt_get_result($records_stmt);
                    $dns_records = [];
                    while ($row = mysqli_fetch_assoc($records_result)) {
                        $dns_records[] = $row;
                    }
                    createDNSZoneFile($domain_data['domain_name'], $dns_records);
                }
            }
        } else {
            $message = '<div class="alert alert-error">Error deleting DNS record.</div>';
        }
    }
}

// Get all domains with their DNS records
$domains_query = "SELECT d.id, d.domain_name, d.status, COUNT(dz.id) as record_count 
                 FROM domains d 
                 LEFT JOIN dns_zones dz ON d.id = dz.domain_id 
                 GROUP BY d.id 
                 ORDER BY d.domain_name";
$domains_result = mysqli_query($conn, $domains_query);
$domains = [];
while ($row = mysqli_fetch_assoc($domains_result)) {
    $domains[] = $row;
}

// Get recent DNS records
$recent_records_query = "SELECT dz.*, d.domain_name 
                        FROM dns_zones dz 
                        JOIN domains d ON dz.domain_id = d.id 
                        ORDER BY dz.created_at DESC 
                        LIMIT 10";
$recent_records_result = mysqli_query($conn, $recent_records_query);
$recent_records = [];
while ($row = mysqli_fetch_assoc($recent_records_result)) {
    $recent_records[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DNS Records Management - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-network-wired"></i> DNS Records Management</h1>
        
        <?= $message ?>
        
        <!-- DNS Overview -->
        <div class="card">
            <h3>DNS Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= count($domains) ?></div>
                    <div class="stat-label">Total Domains</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= array_sum(array_column($domains, 'record_count')) ?></div>
                    <div class="stat-label">Total DNS Records</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($domains, function($d) { return $d['status'] === 'active'; })) ?></div>
                    <div class="stat-label">Active Domains</div>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <!-- Domains with DNS Records -->
            <div class="card">
                <h3>Domains & DNS Records</h3>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Status</th>
                                <th>Records</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domains as $domain): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($domain['domain_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $domain['status'] ?>">
                                        <?= ucfirst($domain['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge"><?= $domain['record_count'] ?> records</span>
                                </td>
                                <td>
                                    <a href="dns.php?domain_id=<?= $domain['id'] ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-edit"></i> Manage DNS
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add New DNS Record -->
            <div class="card">
                <h3>Add DNS Record</h3>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label>Domain</label>
                        <select name="domain_id" class="form-control" required>
                            <option value="">Select Domain</option>
                            <?php foreach ($domains as $domain): ?>
                                <option value="<?= $domain['id'] ?>"><?= htmlspecialchars($domain['domain_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Record Type</label>
                        <select name="record_type" class="form-control" required>
                            <option value="A">A Record</option>
                            <option value="AAAA">AAAA Record</option>
                            <option value="CNAME">CNAME Record</option>
                            <option value="MX">MX Record</option>
                            <option value="TXT">TXT Record</option>
                            <option value="NS">NS Record</option>
                            <option value="SRV">SRV Record</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" placeholder="@ or subdomain" required>
                        <small class="help-text">Use @ for root domain, or enter subdomain name</small>
                    </div>
                    <div class="form-group">
                        <label>Value</label>
                        <input type="text" name="value" class="form-control" placeholder="IP address or target" required>
                    </div>
                    <div class="grid" style="grid-template-columns: 1fr 1fr;">
                        <div class="form-group">
                            <label>TTL (seconds)</label>
                            <input type="number" name="ttl" class="form-control" value="3600" min="300" max="86400">
                        </div>
                        <div class="form-group">
                            <label>Priority (for MX/SRV)</label>
                            <input type="number" name="priority" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <button type="submit" name="add_record" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add DNS Record
                    </button>
                </form>
            </div>
        </div>

        <!-- Recent DNS Records -->
        <div class="card">
            <h3>Recent DNS Records</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Value</th>
                            <th>TTL</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_records as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['domain_name']) ?></td>
                            <td><span class="record-type record-<?= strtolower($record['record_type']) ?>"><?= $record['record_type'] ?></span></td>
                            <td><code><?= htmlspecialchars($record['name']) ?></code></td>
                            <td><code><?= htmlspecialchars($record['value']) ?></code></td>
                            <td><?= $record['ttl'] ?>s</td>
                            <td><?= date('M j, Y', strtotime($record['created_at'])) ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this DNS record?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
                                    <button type="submit" name="delete_record" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        
        .record-type {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .record-a { background: #d1ecf1; color: #0c5460; }
        .record-aaaa { background: #d1ecf1; color: #0c5460; }
        .record-cname { background: #d4edda; color: #155724; }
        .record-mx { background: #fff3cd; color: #856404; }
        .record-txt { background: #e2e3e5; color: #383d41; }
        .record-ns { background: #f8d7da; color: #721c24; }
        .record-srv { background: #e7e8ea; color: #383d41; }
        
        .badge {
            background: var(--primary-color);
            color: #000000;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            overflow: hidden;
        }
        
        .help-text {
            font-size: 0.8em;
            color: var(--text-muted);
            margin-top: 4px;
        }
    </style>
</body>
</html>