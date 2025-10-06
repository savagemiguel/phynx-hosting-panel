<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle FTP account operations
if ($_POST) {
    if (isset($_POST['create_ftp_account'])) {
        $result = createFTPAccount($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">FTP account created successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to create FTP account: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['update_ftp_account'])) {
        $result = updateFTPAccount($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">FTP account updated successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to update FTP account: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['delete_ftp_account'])) {
        $result = deleteFTPAccount($_POST['ftp_username']);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">FTP account deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete FTP account: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['change_ftp_password'])) {
        $result = changeFTPPassword($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">FTP password changed successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to change password: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Create FTP account
function createFTPAccount($data) {
    global $conn;
    
    $username = mysqli_real_escape_string($conn, $data['username']);
    $password = $data['password'];
    $home_directory = mysqli_real_escape_string($conn, $data['home_directory']);
    $quota_size = (int)$data['quota_size'];
    $quota_files = (int)$data['quota_files'];
    $ul_bandwidth = (int)$data['ul_bandwidth'];
    $dl_bandwidth = (int)$data['dl_bandwidth'];
    $max_connections = (int)$data['max_connections'];
    $status = isset($data['status']) ? 1 : 0;
    
    // Check if username already exists
    $checkQuery = "SELECT username FROM ftp_accounts WHERE username = '$username'";
    $checkResult = mysqli_query($conn, $checkQuery);
    
    if (mysqli_num_rows($checkResult) > 0) {
        return ['success' => false, 'error' => 'Username already exists'];
    }
    
    // Create home directory if it doesn't exist
    if (!file_exists($home_directory)) {
        if (!mkdir($home_directory, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create home directory'];
        }
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $query = "INSERT INTO ftp_accounts (username, password, home_directory, quota_size, quota_files, 
              ul_bandwidth, dl_bandwidth, max_connections, status, created_at) 
              VALUES ('$username', '$hashed_password', '$home_directory', $quota_size, $quota_files, 
              $ul_bandwidth, $dl_bandwidth, $max_connections, $status, NOW())";
    
    if (mysqli_query($conn, $query)) {
        // Update FTP server configuration
        updateFTPConfig();
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Update FTP account
function updateFTPAccount($data) {
    global $conn;
    
    $id = (int)$data['id'];
    $home_directory = mysqli_real_escape_string($conn, $data['home_directory']);
    $quota_size = (int)$data['quota_size'];
    $quota_files = (int)$data['quota_files'];
    $ul_bandwidth = (int)$data['ul_bandwidth'];
    $dl_bandwidth = (int)$data['dl_bandwidth'];
    $max_connections = (int)$data['max_connections'];
    $status = isset($data['status']) ? 1 : 0;
    
    $query = "UPDATE ftp_accounts SET 
              home_directory = '$home_directory',
              quota_size = $quota_size,
              quota_files = $quota_files,
              ul_bandwidth = $ul_bandwidth,
              dl_bandwidth = $dl_bandwidth,
              max_connections = $max_connections,
              status = $status,
              updated_at = NOW()
              WHERE id = $id";
    
    if (mysqli_query($conn, $query)) {
        updateFTPConfig();
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Delete FTP account
function deleteFTPAccount($username) {
    global $conn;
    
    $username = mysqli_real_escape_string($conn, $username);
    
    $query = "DELETE FROM ftp_accounts WHERE username = '$username'";
    
    if (mysqli_query($conn, $query)) {
        updateFTPConfig();
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Change FTP password
function changeFTPPassword($data) {
    global $conn;
    
    $id = (int)$data['id'];
    $new_password = $data['new_password'];
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $query = "UPDATE ftp_accounts SET password = '$hashed_password', updated_at = NOW() WHERE id = $id";
    
    if (mysqli_query($conn, $query)) {
        updateFTPConfig();
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Update FTP server configuration
function updateFTPConfig() {
    // This function would update your FTP server configuration
    // Implementation depends on your FTP server (vsftpd, pure-ftpd, etc.)
    
    // For vsftpd with virtual users:
    $configFile = '/etc/vsftpd/virtual_users.txt';
    $accounts = getAllFTPAccounts();
    
    $content = '';
    foreach ($accounts as $account) {
        if ($account['status'] == 1) {
            $content .= $account['username'] . "\n";
            $content .= $account['password'] . "\n";
        }
    }
    
    // In production, you'd write to the actual config file
    // file_put_contents($configFile, $content);
    
    return true;
}

// Get all FTP accounts
function getAllFTPAccounts() {
    global $conn;
    
    $accounts = [];
    $query = "SELECT * FROM ftp_accounts ORDER BY username";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $accounts[] = $row;
        }
    }
    
    return $accounts;
}

// Get FTP statistics
function getFTPStatistics() {
    global $conn;
    
    $stats = [
        'total_accounts' => 0,
        'active_accounts' => 0,
        'inactive_accounts' => 0,
        'total_quota' => 0,
        'used_space' => 0
    ];
    
    // Total and active accounts
    $query = "SELECT COUNT(*) as total, SUM(status) as active FROM ftp_accounts";
    $result = mysqli_query($conn, $query);
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_accounts'] = $row['total'];
        $stats['active_accounts'] = $row['active'];
        $stats['inactive_accounts'] = $row['total'] - $row['active'];
    }
    
    // Total quota
    $query = "SELECT SUM(quota_size) as total_quota FROM ftp_accounts WHERE status = 1";
    $result = mysqli_query($conn, $query);
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_quota'] = $row['total_quota'] ?? 0;
    }
    
    return $stats;
}

// Calculate directory size
function getDirectorySize($directory) {
    if (!is_dir($directory)) {
        return 0;
    }
    
    $size = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}

// Format file size
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', '') . ' ' . $units[$power];
}

// Test FTP connection
function testFTPConnection($host, $username, $password, $port = 21) {
    $connection = @ftp_connect($host, $port, 10);
    
    if (!$connection) {
        return ['success' => false, 'error' => 'Could not connect to FTP server'];
    }
    
    $login = @ftp_login($connection, $username, $password);
    
    if (!$login) {
        ftp_close($connection);
        return ['success' => false, 'error' => 'Login failed'];
    }
    
    $systype = ftp_systype($connection);
    ftp_close($connection);
    
    return ['success' => true, 'systype' => $systype];
}

$ftpAccounts = getAllFTPAccounts();
$ftpStats = getFTPStatistics();

// Create FTP accounts table if it doesn't exist
$createTableQuery = "
CREATE TABLE IF NOT EXISTS ftp_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    home_directory VARCHAR(255) NOT NULL,
    quota_size INT DEFAULT 0,
    quota_files INT DEFAULT 0,
    ul_bandwidth INT DEFAULT 0,
    dl_bandwidth INT DEFAULT 0,
    max_connections INT DEFAULT 5,
    status TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createTableQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FTP Accounts - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-server"></i> FTP Account Management</h1>
        
        <?= $message ?>
        
        <!-- FTP Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $ftpStats['total_accounts'] ?></h3>
                    <p>Total Accounts</p>
                </div>
            </div>
            
            <div class="stat-card active">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $ftpStats['active_accounts'] ?></h3>
                    <p>Active Accounts</p>
                </div>
            </div>
            
            <div class="stat-card inactive">
                <div class="stat-icon">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $ftpStats['inactive_accounts'] ?></h3>
                    <p>Inactive Accounts</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-hdd"></i>
                </div>
                <div class="stat-content">
                    <h3><?= formatFileSize($ftpStats['total_quota'] * 1024 * 1024) ?></h3>
                    <p>Total Quota</p>
                </div>
            </div>
        </div>

        <!-- FTP Account Actions -->
        <div class="action-toolbar">
            <button onclick="showCreateAccountModal()" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Create FTP Account
            </button>
            <button onclick="testFTPServer()" class="btn btn-info">
                <i class="fas fa-network-wired"></i> Test FTP Server
            </button>
            <button onclick="showBulkActionsModal()" class="btn btn-secondary">
                <i class="fas fa-tasks"></i> Bulk Actions
            </button>
            <button onclick="exportAccounts()" class="btn btn-success">
                <i class="fas fa-download"></i> Export Accounts
            </button>
        </div>

        <!-- FTP Accounts List -->
        <div class="card">
            <h3>FTP Accounts</h3>
            
            <?php if (empty($ftpAccounts)): ?>
                <div class="no-accounts">
                    <i class="fas fa-server"></i>
                    <p>No FTP accounts found.</p>
                    <button onclick="showCreateAccountModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create First Account
                    </button>
                </div>
            <?php else: ?>
                <div class="accounts-table-container">
                    <table class="accounts-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll" onchange="toggleAllSelection()"></th>
                                <th>Username</th>
                                <th>Home Directory</th>
                                <th>Quota</th>
                                <th>Bandwidth</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ftpAccounts as $account): 
                                $usedSpace = is_dir($account['home_directory']) ? getDirectorySize($account['home_directory']) : 0;
                                $quotaUsage = $account['quota_size'] > 0 ? ($usedSpace / ($account['quota_size'] * 1024 * 1024)) * 100 : 0;
                            ?>
                            <tr data-id="<?= $account['id'] ?>" class="<?= $account['status'] ? 'active' : 'inactive' ?>">
                                <td><input type="checkbox" class="account-checkbox" value="<?= $account['id'] ?>"></td>
                                <td>
                                    <div class="account-info">
                                        <strong><?= htmlspecialchars($account['username']) ?></strong>
                                        <div class="account-meta">
                                            <span class="connections">Max: <?= $account['max_connections'] ?> conn.</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="directory-info">
                                        <span class="directory-path"><?= htmlspecialchars($account['home_directory']) ?></span>
                                        <div class="directory-size"><?= formatFileSize($usedSpace) ?> used</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="quota-info">
                                        <div class="quota-text">
                                            <?= $account['quota_size'] > 0 ? formatFileSize($account['quota_size'] * 1024 * 1024) : 'Unlimited' ?>
                                        </div>
                                        <?php if ($account['quota_size'] > 0): ?>
                                        <div class="quota-bar">
                                            <div class="quota-usage" style="width: <?= min($quotaUsage, 100) ?>%"></div>
                                        </div>
                                        <div class="quota-percentage"><?= number_format($quotaUsage, 1) ?>%</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="bandwidth-info">
                                        <div class="bandwidth-item">
                                            <i class="fas fa-upload"></i> <?= $account['ul_bandwidth'] > 0 ? formatFileSize($account['ul_bandwidth'] * 1024) . '/s' : 'Unlimited' ?>
                                        </div>
                                        <div class="bandwidth-item">
                                            <i class="fas fa-download"></i> <?= $account['dl_bandwidth'] > 0 ? formatFileSize($account['dl_bandwidth'] * 1024) . '/s' : 'Unlimited' ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?= $account['status'] ? 'active' : 'inactive' ?>">
                                        <i class="fas <?= $account['status'] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                        <?= $account['status'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($account['created_at'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="editAccount(<?= $account['id'] ?>)" class="btn btn-sm btn-info" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="changePassword(<?= $account['id'] ?>)" class="btn btn-sm btn-warning" title="Change Password">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <button onclick="toggleStatus(<?= $account['id'] ?>, <?= $account['status'] ?>)" 
                                                class="btn btn-sm <?= $account['status'] ? 'btn-secondary' : 'btn-success' ?>" 
                                                title="<?= $account['status'] ? 'Disable' : 'Enable' ?>">
                                            <i class="fas <?= $account['status'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </button>
                                        <button onclick="deleteAccount(<?= $account['id'] ?>, '<?= htmlspecialchars($account['username']) ?>')" 
                                                class="btn btn-sm btn-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- FTP Server Configuration -->
        <div class="card">
            <h3>FTP Server Configuration</h3>
            <div class="config-grid">
                <div class="config-item">
                    <div class="config-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="config-content">
                        <h4>Server Settings</h4>
                        <p>Configure FTP server parameters, ports, and security settings.</p>
                        <button onclick="showServerConfigModal()" class="btn btn-sm btn-primary">
                            <i class="fas fa-cogs"></i> Configure
                        </button>
                    </div>
                </div>
                
                <div class="config-item">
                    <div class="config-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="config-content">
                        <h4>Security Settings</h4>
                        <p>Manage SSL/TLS, IP restrictions, and authentication methods.</p>
                        <button onclick="showSecurityConfigModal()" class="btn btn-sm btn-info">
                            <i class="fas fa-lock"></i> Security
                        </button>
                    </div>
                </div>
                
                <div class="config-item">
                    <div class="config-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="config-content">
                        <h4>Usage Statistics</h4>
                        <p>View FTP server usage, transfer logs, and performance metrics.</p>
                        <button onclick="showUsageStatsModal()" class="btn btn-sm btn-success">
                            <i class="fas fa-analytics"></i> Statistics
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Account Modal -->
    <div id="createAccountModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('createAccountModal')">&times;</span>
            <h3>Create FTP Account</h3>
            <form method="POST" id="createAccountForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" class="form-control" required 
                               pattern="[a-zA-Z0-9_.-]+" title="Username can only contain letters, numbers, dots, dashes, and underscores">
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <div class="password-input">
                            <input type="password" name="password" class="form-control" required 
                                   minlength="8" id="accountPassword">
                            <button type="button" onclick="togglePassword('accountPassword')" class="toggle-password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Home Directory:</label>
                    <input type="text" name="home_directory" class="form-control" required 
                           value="/var/www/html/" placeholder="/var/www/html/username">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quota Size (MB):</label>
                        <input type="number" name="quota_size" class="form-control" min="0" value="1000">
                        <small class="form-text">0 = Unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Max Files:</label>
                        <input type="number" name="quota_files" class="form-control" min="0" value="0">
                        <small class="form-text">0 = Unlimited</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Upload Bandwidth (KB/s):</label>
                        <input type="number" name="ul_bandwidth" class="form-control" min="0" value="0">
                        <small class="form-text">0 = Unlimited</small>
                    </div>
                    <div class="form-group">
                        <label>Download Bandwidth (KB/s):</label>
                        <input type="number" name="dl_bandwidth" class="form-control" min="0" value="0">
                        <small class="form-text">0 = Unlimited</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Connections:</label>
                        <input type="number" name="max_connections" class="form-control" min="1" value="5">
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="status" checked>
                            Active Account
                        </label>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="create_ftp_account" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                    <button type="button" onclick="closeModal('createAccountModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Account Modal -->
    <div id="editAccountModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editAccountModal')">&times;</span>
            <h3>Edit FTP Account</h3>
            <form method="POST" id="editAccountForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" id="editAccountId">
                
                <div class="form-group">
                    <label>Home Directory:</label>
                    <input type="text" name="home_directory" class="form-control" required id="editHomeDirectory">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quota Size (MB):</label>
                        <input type="number" name="quota_size" class="form-control" min="0" id="editQuotaSize">
                    </div>
                    <div class="form-group">
                        <label>Max Files:</label>
                        <input type="number" name="quota_files" class="form-control" min="0" id="editQuotaFiles">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Upload Bandwidth (KB/s):</label>
                        <input type="number" name="ul_bandwidth" class="form-control" min="0" id="editUlBandwidth">
                    </div>
                    <div class="form-group">
                        <label>Download Bandwidth (KB/s):</label>
                        <input type="number" name="dl_bandwidth" class="form-control" min="0" id="editDlBandwidth">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Connections:</label>
                        <input type="number" name="max_connections" class="form-control" min="1" id="editMaxConnections">
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="status" id="editStatus">
                            Active Account
                        </label>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="update_ftp_account" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Account
                    </button>
                    <button type="button" onclick="closeModal('editAccountModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changePasswordModal')">&times;</span>
            <h3>Change FTP Password</h3>
            <form method="POST" id="changePasswordForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" id="changePasswordId">
                
                <div class="form-group">
                    <label>New Password:</label>
                    <div class="password-input">
                        <input type="password" name="new_password" class="form-control" required 
                               minlength="8" id="newPassword">
                        <button type="button" onclick="togglePassword('newPassword')" class="toggle-password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="newPasswordStrength"></div>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="change_ftp_password" class="btn btn-warning">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                    <button type="button" onclick="closeModal('changePasswordModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-card.active {
            border-left: 4px solid #28a745;
        }
        
        .stat-card.inactive {
            border-left: 4px solid #dc3545;
        }
        
        .stat-icon {
            font-size: 2.5em;
            color: var(--primary-color);
        }
        
        .stat-content h3 {
            margin: 0;
            font-size: 2em;
            font-weight: bold;
        }
        
        .stat-content p {
            margin: 0;
            color: var(--text-muted);
        }
        
        .action-toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .no-accounts {
            text-align: center;
            padding: 60px;
            color: var(--text-muted);
        }
        
        .no-accounts i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .accounts-table-container {
            overflow-x: auto;
        }
        
        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .accounts-table th,
        .accounts-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .accounts-table th {
            background: var(--section-bg);
            font-weight: 500;
        }
        
        .accounts-table tr.inactive {
            opacity: 0.6;
        }
        
        .account-info strong {
            display: block;
            margin-bottom: 4px;
        }
        
        .account-meta {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .directory-info {
            font-family: monospace;
        }
        
        .directory-path {
            font-size: 0.9em;
            display: block;
        }
        
        .directory-size {
            font-size: 0.8em;
            color: var(--text-muted);
        }
        
        .quota-info {
            min-width: 120px;
        }
        
        .quota-bar {
            width: 100%;
            height: 6px;
            background: var(--section-bg);
            border-radius: 3px;
            margin: 4px 0;
            overflow: hidden;
        }
        
        .quota-usage {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #ffc107, #dc3545);
            transition: width 0.3s;
        }
        
        .quota-percentage {
            font-size: 0.8em;
            color: var(--text-muted);
        }
        
        .bandwidth-info {
            font-size: 0.9em;
        }
        
        .bandwidth-item {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 2px;
        }
        
        .bandwidth-item i {
            width: 12px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .status-badge.active {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .status-badge.inactive {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .config-item {
            display: flex;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .config-icon {
            font-size: 2.5em;
            color: var(--primary-color);
            margin-right: 20px;
            flex-shrink: 0;
        }
        
        .config-content h4 {
            margin-bottom: 10px;
        }
        
        .config-content p {
            color: var(--text-muted);
            margin-bottom: 15px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 25px;
        }
        
        .password-input {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
        }
        
        .password-strength {
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
    </style>

    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
        
        function showCreateAccountModal() {
            document.getElementById('createAccountModal').style.display = 'block';
        }
        
        function editAccount(id) {
            // Fetch account data and populate form
            const row = document.querySelector(`tr[data-id="${id}"]`);
            if (row) {
                // This would be populated from actual data
                document.getElementById('editAccountId').value = id;
                document.getElementById('editAccountModal').style.display = 'block';
            }
        }
        
        function changePassword(id) {
            document.getElementById('changePasswordId').value = id;
            document.getElementById('changePasswordModal').style.display = 'block';
        }
        
        function deleteAccount(id, username) {
            if (confirm(`Are you sure you want to delete FTP account "${username}"? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="delete_ftp_account" value="1">
                    <input type="hidden" name="ftp_username" value="${username}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleStatus(id, currentStatus) {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'enable' : 'disable';
            
            if (confirm(`Are you sure you want to ${action} this FTP account?`)) {
                // Submit status change
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="update_ftp_account" value="1">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="status" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleAllSelection() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.account-checkbox');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
        }
        
        function testFTPServer() {
            alert('FTP server test functionality would be implemented here');
        }
        
        function showBulkActionsModal() {
            alert('Bulk actions modal would be implemented here');
        }
        
        function exportAccounts() {
            alert('Account export functionality would be implemented here');
        }
        
        function showServerConfigModal() {
            alert('Server configuration modal would be implemented here');
        }
        
        function showSecurityConfigModal() {
            alert('Security configuration modal would be implemented here');
        }
        
        function showUsageStatsModal() {
            alert('Usage statistics modal would be implemented here');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 8) strength++;
            else feedback.push('At least 8 characters');
            
            if (/[a-z]/.test(password)) strength++;
            else feedback.push('Lowercase letter');
            
            if (/[A-Z]/.test(password)) strength++;
            else feedback.push('Uppercase letter');
            
            if (/\d/.test(password)) strength++;
            else feedback.push('Number');
            
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            else feedback.push('Special character');
            
            return { strength, feedback };
        }
        
        // Auto-fill home directory based on username
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.querySelector('input[name="username"]');
            const homeDirInput = document.querySelector('input[name="home_directory"]');
            
            if (usernameInput && homeDirInput) {
                usernameInput.addEventListener('input', function() {
                    homeDirInput.value = `/var/www/html/${this.value}`;
                });
            }
            
            // Password strength monitoring
            const passwordInputs = ['accountPassword', 'newPassword'];
            
            passwordInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function() {
                        const strengthDiv = document.getElementById(inputId === 'accountPassword' ? 'passwordStrength' : 'newPasswordStrength');
                        const result = checkPasswordStrength(this.value);
                        
                        let color = 'red';
                        let text = 'Weak';
                        
                        if (result.strength >= 3) {
                            color = 'orange';
                            text = 'Medium';
                        }
                        if (result.strength >= 5) {
                            color = 'green';
                            text = 'Strong';
                        }
                        
                        strengthDiv.innerHTML = `<span style="color: ${color}">${text}</span> - Missing: ${result.feedback.join(', ')}`;
                    });
                }
            });
        });
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>