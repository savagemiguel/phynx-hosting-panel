<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle database user actions
if ($_POST) {
    if (isset($_POST['create_user'])) {
        $result = createDatabaseUser($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Database user created successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to create user: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['update_privileges'])) {
        $result = updateUserPrivileges($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">User privileges updated successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to update privileges: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['delete_user'])) {
        $result = deleteDatabaseUser($_POST['username'], $_POST['host']);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Database user deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete user: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['change_password'])) {
        $result = changeUserPassword($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">User password changed successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to change password: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Create database user
function createDatabaseUser($data) {
    global $conn;
    
    $username = mysqli_real_escape_string($conn, $data['username']);
    $password = $data['password'];
    $host = mysqli_real_escape_string($conn, $data['host'] ?? 'localhost');
    $database = mysqli_real_escape_string($conn, $data['database'] ?? '%');
    
    // Create user
    $query = "CREATE USER '$username'@'$host' IDENTIFIED BY '" . mysqli_real_escape_string($conn, $password) . "'";
    
    if (!mysqli_query($conn, $query)) {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
    
    // Grant privileges if specified
    if (!empty($data['privileges'])) {
        $privileges = implode(', ', $data['privileges']);
        $grantQuery = "GRANT $privileges ON `$database`.* TO '$username'@'$host'";
        
        if (!mysqli_query($conn, $grantQuery)) {
            // If grant fails, try to clean up by dropping the user
            mysqli_query($conn, "DROP USER '$username'@'$host'");
            return ['success' => false, 'error' => 'Failed to grant privileges: ' . mysqli_error($conn)];
        }
    }
    
    // Flush privileges
    mysqli_query($conn, "FLUSH PRIVILEGES");
    
    return ['success' => true];
}

// Update user privileges
function updateUserPrivileges($data) {
    global $conn;
    
    $username = mysqli_real_escape_string($conn, $data['username']);
    $host = mysqli_real_escape_string($conn, $data['host']);
    $database = mysqli_real_escape_string($conn, $data['database'] ?? '%');
    
    // First revoke all privileges
    $revokeQuery = "REVOKE ALL PRIVILEGES ON `$database`.* FROM '$username'@'$host'";
    mysqli_query($conn, $revokeQuery);
    
    // Grant new privileges if specified
    if (!empty($data['privileges'])) {
        $privileges = implode(', ', $data['privileges']);
        $grantQuery = "GRANT $privileges ON `$database`.* TO '$username'@'$host'";
        
        if (!mysqli_query($conn, $grantQuery)) {
            return ['success' => false, 'error' => mysqli_error($conn)];
        }
    }
    
    // Flush privileges
    mysqli_query($conn, "FLUSH PRIVILEGES");
    
    return ['success' => true];
}

// Delete database user
function deleteDatabaseUser($username, $host) {
    global $conn;
    
    $username = mysqli_real_escape_string($conn, $username);
    $host = mysqli_real_escape_string($conn, $host);
    
    $query = "DROP USER '$username'@'$host'";
    
    if (mysqli_query($conn, $query)) {
        mysqli_query($conn, "FLUSH PRIVILEGES");
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Change user password
function changeUserPassword($data) {
    global $conn;
    
    $username = mysqli_real_escape_string($conn, $data['username']);
    $host = mysqli_real_escape_string($conn, $data['host']);
    $password = mysqli_real_escape_string($conn, $data['new_password']);
    
    $query = "ALTER USER '$username'@'$host' IDENTIFIED BY '$password'";
    
    if (mysqli_query($conn, $query)) {
        mysqli_query($conn, "FLUSH PRIVILEGES");
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Get all database users
function getDatabaseUsers() {
    global $conn;
    
    $users = [];
    
    $query = "SELECT User, Host FROM mysql.user WHERE User != '' ORDER BY User, Host";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $userInfo = getUserPrivileges($row['User'], $row['Host']);
            $users[] = [
                'username' => $row['User'],
                'host' => $row['Host'],
                'privileges' => $userInfo['privileges'],
                'databases' => $userInfo['databases']
            ];
        }
    }
    
    return $users;
}

// Get user privileges
function getUserPrivileges($username, $host) {
    global $conn;
    
    $privileges = [];
    $databases = [];
    
    // Get global privileges
    $query = "SHOW GRANTS FOR '$username'@'$host'";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_array($result)) {
            $grant = $row[0];
            
            // Parse grant statement
            if (preg_match('/GRANT (.+) ON (.+) TO/', $grant, $matches)) {
                $privs = $matches[1];
                $database = $matches[2];
                
                if ($database !== '*.*') {
                    $database = str_replace(['`', '*'], '', $database);
                    if (!in_array($database, $databases)) {
                        $databases[] = $database;
                    }
                }
                
                // Extract individual privileges
                $privList = explode(',', $privs);
                foreach ($privList as $priv) {
                    $priv = trim($priv);
                    if ($priv !== 'ALL PRIVILEGES' && !in_array($priv, $privileges)) {
                        $privileges[] = $priv;
                    }
                }
            }
        }
    }
    
    return [
        'privileges' => $privileges,
        'databases' => $databases
    ];
}

// Get available privileges
function getAvailablePrivileges() {
    return [
        'SELECT' => 'Read data from tables',
        'INSERT' => 'Insert new data into tables',
        'UPDATE' => 'Modify existing data in tables',
        'DELETE' => 'Delete data from tables',
        'CREATE' => 'Create new tables and databases',
        'DROP' => 'Delete tables and databases',
        'ALTER' => 'Modify table structure',
        'INDEX' => 'Create and drop indexes',
        'LOCK TABLES' => 'Lock tables for reading',
        'CREATE TEMPORARY TABLES' => 'Create temporary tables',
        'EXECUTE' => 'Execute stored procedures',
        'CREATE VIEW' => 'Create views',
        'SHOW VIEW' => 'Show view definitions',
        'CREATE ROUTINE' => 'Create stored procedures and functions',
        'ALTER ROUTINE' => 'Modify stored procedures and functions',
        'EVENT' => 'Create, modify and delete events',
        'TRIGGER' => 'Create and drop triggers'
    ];
}

// Get database list
function getDatabaseList() {
    global $conn;
    
    $databases = [];
    $result = mysqli_query($conn, "SHOW DATABASES");
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $dbName = $row['Database'];
            if (!in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                $databases[] = $dbName;
            }
        }
    }
    
    return $databases;
}

$databaseUsers = getDatabaseUsers();
$availablePrivileges = getAvailablePrivileges();
$databaseList = getDatabaseList();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Users - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-users-cog"></i> Database User Management</h1>
        
        <?= $message ?>
        
        <!-- Create New User -->
        <div class="card">
            <h3>Create New Database User</h3>
            <form method="POST" class="user-form" id="createUserForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" class="form-control" required 
                               pattern="[a-zA-Z0-9_]+" title="Username can only contain letters, numbers, and underscores">
                    </div>
                    <div class="form-group">
                        <label>Host:</label>
                        <select name="host" class="form-control">
                            <option value="localhost">localhost</option>
                            <option value="%">% (any host)</option>
                            <option value="127.0.0.1">127.0.0.1</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Password:</label>
                        <div class="password-input">
                            <input type="password" name="password" class="form-control" required 
                                   minlength="8" id="userPassword">
                            <button type="button" onclick="togglePassword('userPassword')" class="toggle-password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength"></div>
                    </div>
                    <div class="form-group">
                        <label>Database:</label>
                        <select name="database" class="form-control">
                            <option value="%">All Databases (*)</option>
                            <?php foreach ($databaseList as $db): ?>
                                <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="privileges-section">
                    <h4>User Privileges</h4>
                    <div class="privileges-grid">
                        <?php foreach ($availablePrivileges as $privilege => $description): ?>
                            <label class="privilege-item">
                                <input type="checkbox" name="privileges[]" value="<?= $privilege ?>">
                                <div class="privilege-info">
                                    <span class="privilege-name"><?= $privilege ?></span>
                                    <span class="privilege-desc"><?= $description ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="privilege-presets">
                        <button type="button" onclick="selectPreset('read')" class="btn btn-sm btn-secondary">Read Only</button>
                        <button type="button" onclick="selectPreset('write')" class="btn btn-sm btn-secondary">Read + Write</button>
                        <button type="button" onclick="selectPreset('admin')" class="btn btn-sm btn-secondary">Admin</button>
                        <button type="button" onclick="selectPreset('none')" class="btn btn-sm btn-secondary">Clear All</button>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="create_user" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Create User
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset Form
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing Users -->
        <div class="card">
            <h3>Existing Database Users</h3>
            
            <?php if (empty($databaseUsers)): ?>
                <div class="no-users">
                    <i class="fas fa-users"></i>
                    <p>No database users found.</p>
                </div>
            <?php else: ?>
                <div class="users-list">
                    <?php foreach ($databaseUsers as $user): ?>
                    <div class="user-item">
                        <div class="user-info">
                            <div class="user-header">
                                <h4><?= htmlspecialchars($user['username']) ?>@<?= htmlspecialchars($user['host']) ?></h4>
                                <div class="user-badges">
                                    <?php if (!empty($user['databases'])): ?>
                                        <span class="badge db-badge"><?= count($user['databases']) ?> DB(s)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($user['privileges'])): ?>
                                        <span class="badge priv-badge"><?= count($user['privileges']) ?> Privilege(s)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="user-details">
                                <?php if (!empty($user['databases'])): ?>
                                    <div class="detail-section">
                                        <strong>Databases:</strong>
                                        <span class="database-list">
                                            <?= implode(', ', array_map('htmlspecialchars', $user['databases'])) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($user['privileges'])): ?>
                                    <div class="detail-section">
                                        <strong>Privileges:</strong>
                                        <div class="privilege-tags">
                                            <?php foreach ($user['privileges'] as $priv): ?>
                                                <span class="privilege-tag"><?= htmlspecialchars($priv) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="user-actions">
                            <button onclick="editUser('<?= htmlspecialchars($user['username']) ?>', '<?= htmlspecialchars($user['host']) ?>')" 
                                    class="btn btn-sm btn-info">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button onclick="changePassword('<?= htmlspecialchars($user['username']) ?>', '<?= htmlspecialchars($user['host']) ?>')" 
                                    class="btn btn-sm btn-warning">
                                <i class="fas fa-key"></i> Password
                            </button>
                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="username" value="<?= htmlspecialchars($user['username']) ?>">
                                <input type="hidden" name="host" value="<?= htmlspecialchars($user['host']) ?>">
                                <button type="submit" name="delete_user" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- User Security Guidelines -->
        <div class="card">
            <h3>Database User Security Guidelines</h3>
            <div class="security-guidelines">
                <div class="guideline-item">
                    <i class="fas fa-shield-alt"></i>
                    <div class="guideline-content">
                        <h4>Use Strong Passwords</h4>
                        <p>Database passwords should be at least 12 characters long and contain a mix of letters, numbers, and symbols.</p>
                    </div>
                </div>
                
                <div class="guideline-item">
                    <i class="fas fa-user-lock"></i>
                    <div class="guideline-content">
                        <h4>Principle of Least Privilege</h4>
                        <p>Grant users only the minimum privileges necessary for their tasks. Avoid giving unnecessary administrative privileges.</p>
                    </div>
                </div>
                
                <div class="guideline-item">
                    <i class="fas fa-network-wired"></i>
                    <div class="guideline-content">
                        <h4>Restrict Host Access</h4>
                        <p>Use specific host restrictions instead of '%' (any host) when possible to limit where users can connect from.</p>
                    </div>
                </div>
                
                <div class="guideline-item">
                    <i class="fas fa-clock"></i>
                    <div class="guideline-content">
                        <h4>Regular Audits</h4>
                        <p>Regularly review user accounts and privileges. Remove unused accounts and update privileges as roles change.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editUserModal')">&times;</span>
            <h3>Edit User Privileges</h3>
            <form method="POST" id="editUserForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="username" id="editUsername">
                <input type="hidden" name="host" id="editHost">
                
                <div class="form-group">
                    <label>Database:</label>
                    <select name="database" class="form-control" id="editDatabase">
                        <option value="%">All Databases (*)</option>
                        <?php foreach ($databaseList as $db): ?>
                            <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="privileges-section">
                    <h4>Privileges</h4>
                    <div class="privileges-grid" id="editPrivileges">
                        <?php foreach ($availablePrivileges as $privilege => $description): ?>
                            <label class="privilege-item">
                                <input type="checkbox" name="privileges[]" value="<?= $privilege ?>">
                                <div class="privilege-info">
                                    <span class="privilege-name"><?= $privilege ?></span>
                                    <span class="privilege-desc"><?= $description ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="update_privileges" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Privileges
                    </button>
                    <button type="button" onclick="closeModal('editUserModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="passwordModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('passwordModal')">&times;</span>
            <h3>Change User Password</h3>
            <form method="POST" id="passwordForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="username" id="passwordUsername">
                <input type="hidden" name="host" id="passwordHost">
                
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
                    <button type="submit" name="change_password" class="btn btn-warning">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                    <button type="button" onclick="closeModal('passwordModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .user-form .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
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
        
        .privileges-section {
            margin: 20px 0;
        }
        
        .privileges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 10px;
            margin: 15px 0;
        }
        
        .privilege-item {
            display: flex;
            align-items: flex-start;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .privilege-item:hover {
            background: var(--section-bg);
        }
        
        .privilege-item input[type="checkbox"] {
            margin-right: 10px;
            margin-top: 2px;
        }
        
        .privilege-info {
            flex: 1;
        }
        
        .privilege-name {
            font-weight: 500;
            display: block;
            margin-bottom: 3px;
        }
        
        .privilege-desc {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .privilege-presets {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .no-users {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .no-users i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .users-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .user-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .user-header h4 {
            margin: 0;
            font-family: 'Courier New', monospace;
        }
        
        .user-badges {
            display: flex;
            gap: 5px;
        }
        
        .badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
        }
        
        .db-badge {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .priv-badge {
            background: rgba(0, 123, 255, 0.2);
            color: #007bff;
        }
        
        .user-details {
            margin-top: 10px;
        }
        
        .detail-section {
            margin-bottom: 10px;
        }
        
        .database-list {
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        
        .privilege-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }
        
        .privilege-tag {
            background: var(--section-bg);
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            border: 1px solid var(--border-color);
        }
        
        .user-actions {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }
        
        .security-guidelines {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .guideline-item {
            display: flex;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .guideline-item i {
            font-size: 2em;
            color: var(--primary-color);
            margin-right: 20px;
            flex-shrink: 0;
        }
        
        .guideline-content h4 {
            margin-bottom: 10px;
        }
        
        .guideline-content p {
            color: var(--text-muted);
            margin: 0;
        }
        
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: var(--card-bg);
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
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
        
        function selectPreset(preset) {
            const checkboxes = document.querySelectorAll('#createUserForm input[name="privileges[]"]');
            
            // Clear all first
            checkboxes.forEach(cb => cb.checked = false);
            
            if (preset === 'read') {
                ['SELECT', 'SHOW VIEW'].forEach(priv => {
                    const cb = document.querySelector(`#createUserForm input[value="${priv}"]`);
                    if (cb) cb.checked = true;
                });
            } else if (preset === 'write') {
                ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'SHOW VIEW'].forEach(priv => {
                    const cb = document.querySelector(`#createUserForm input[value="${priv}"]`);
                    if (cb) cb.checked = true;
                });
            } else if (preset === 'admin') {
                checkboxes.forEach(cb => cb.checked = true);
            }
        }
        
        function editUser(username, host) {
            document.getElementById('editUsername').value = username;
            document.getElementById('editHost').value = host;
            document.getElementById('editUserModal').style.display = 'block';
        }
        
        function changePassword(username, host) {
            document.getElementById('passwordUsername').value = username;
            document.getElementById('passwordHost').value = host;
            document.getElementById('passwordModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
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
        
        // Add event listeners for password strength
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInputs = ['userPassword', 'newPassword'];
            
            passwordInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function() {
                        const strengthDiv = document.getElementById(inputId === 'userPassword' ? 'passwordStrength' : 'newPasswordStrength');
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
    </script>
</body>
</html>