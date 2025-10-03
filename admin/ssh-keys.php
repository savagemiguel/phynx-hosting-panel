<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle SSH key actions
if ($_POST) {
    if (isset($_POST['generate_key'])) {
        $user_id = (int)$_POST['user_id'];
        $key_name = sanitize($_POST['key_name']);
        $key_type = $_POST['key_type']; // rsa, ed25519
        $key_size = (int)$_POST['key_size'];
        
        // Get user info
        $user_query = "SELECT username FROM users WHERE id = ?";
        $user_stmt = mysqli_prepare($conn, $user_query);
        mysqli_stmt_bind_param($user_stmt, "i", $user_id);
        mysqli_stmt_execute($user_stmt);
        $user_result = mysqli_stmt_get_result($user_stmt);
        $user = mysqli_fetch_assoc($user_result);
        
        if ($user) {
            $key_path = "/home/{$user['username']}/.ssh/{$key_name}";
            
            // Generate SSH key
            if ($key_type === 'ed25519') {
                $cmd = "ssh-keygen -t ed25519 -f {$key_path} -N '' -C '{$user['username']}@hosting-panel' 2>&1";
            } else {
                $cmd = "ssh-keygen -t rsa -b {$key_size} -f {$key_path} -N '' -C '{$user['username']}@hosting-panel' 2>&1";
            }
            
            exec($cmd, $output, $return_code);
            
            if ($return_code === 0) {
                // Read the public key
                $public_key = file_get_contents($key_path . '.pub');
                
                // Store in database
                $query = "INSERT INTO ssh_keys (user_id, key_name, public_key, key_type, created_at) VALUES (?, ?, ?, ?, NOW())";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "isss", $user_id, $key_name, $public_key, $key_type);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = '<div class="alert alert-success">SSH key generated successfully for ' . htmlspecialchars($user['username']) . '</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to save SSH key to database.</div>';
                }
            } else {
                $message = '<div class="alert alert-error">Failed to generate SSH key: ' . implode(' ', $output) . '</div>';
            }
        }
    }
    
    if (isset($_POST['upload_key'])) {
        $user_id = (int)$_POST['user_id'];
        $key_name = sanitize($_POST['key_name']);
        $public_key = trim($_POST['public_key']);
        
        // Validate SSH key format
        if (preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) [A-Za-z0-9+\/]+=*( .*)?$/', $public_key)) {
            $key_type = explode(' ', $public_key)[0];
            
            // Store in database
            $query = "INSERT INTO ssh_keys (user_id, key_name, public_key, key_type, created_at) VALUES (?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "isss", $user_id, $key_name, $public_key, $key_type);
            
            if (mysqli_stmt_execute($stmt)) {
                // Get user info and add to authorized_keys
                $user_query = "SELECT username FROM users WHERE id = ?";
                $user_stmt = mysqli_prepare($conn, $user_query);
                mysqli_stmt_bind_param($user_stmt, "i", $user_id);
                mysqli_stmt_execute($user_stmt);
                $user_result = mysqli_stmt_get_result($user_stmt);
                $user = mysqli_fetch_assoc($user_result);
                
                if ($user) {
                    $auth_keys_path = "/home/{$user['username']}/.ssh/authorized_keys";
                    file_put_contents($auth_keys_path, $public_key . "\n", FILE_APPEND | LOCK_EX);
                    chmod($auth_keys_path, 0600);
                    
                    $message = '<div class="alert alert-success">SSH key uploaded and authorized successfully.</div>';
                } else {
                    $message = '<div class="alert alert-success">SSH key uploaded successfully.</div>';
                }
            } else {
                $message = '<div class="alert alert-error">Failed to save SSH key.</div>';
            }
        } else {
            $message = '<div class="alert alert-error">Invalid SSH public key format.</div>';
        }
    }
    
    if (isset($_POST['delete_key'])) {
        $key_id = (int)$_POST['key_id'];
        
        // Get key info before deleting
        $key_query = "SELECT sk.*, u.username FROM ssh_keys sk JOIN users u ON sk.user_id = u.id WHERE sk.id = ?";
        $key_stmt = mysqli_prepare($conn, $key_query);
        mysqli_stmt_bind_param($key_stmt, "i", $key_id);
        mysqli_stmt_execute($key_stmt);
        $key_result = mysqli_stmt_get_result($key_stmt);
        $key_data = mysqli_fetch_assoc($key_result);
        
        if ($key_data) {
            // Remove from authorized_keys file
            $auth_keys_path = "/home/{$key_data['username']}/.ssh/authorized_keys";
            if (file_exists($auth_keys_path)) {
                $auth_keys = file_get_contents($auth_keys_path);
                $auth_keys = str_replace($key_data['public_key'] . "\n", '', $auth_keys);
                file_put_contents($auth_keys_path, $auth_keys);
            }
            
            // Delete from database
            $query = "DELETE FROM ssh_keys WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $key_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = '<div class="alert alert-success">SSH key deleted successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to delete SSH key.</div>';
            }
        }
    }
}

// Get all users for dropdown
$users_query = "SELECT id, username FROM users WHERE role = 'user' ORDER BY username";
$users_result = mysqli_query($conn, $users_query);
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}

// Get all SSH keys
$keys_query = "SELECT sk.*, u.username, u.email FROM ssh_keys sk 
               JOIN users u ON sk.user_id = u.id 
               ORDER BY sk.created_at DESC";
$keys_result = mysqli_query($conn, $keys_query);
$ssh_keys = [];
while ($row = mysqli_fetch_assoc($keys_result)) {
    $ssh_keys[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSH Key Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-key"></i> SSH Key Manager</h1>
        
        <?= $message ?>
        
        <!-- SSH Key Statistics -->
        <div class="card">
            <h3>SSH Key Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= count($ssh_keys) ?></div>
                    <div class="stat-label">Total SSH Keys</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($ssh_keys, function($k) { return strpos($k['key_type'], 'ed25519') !== false; })) ?></div>
                    <div class="stat-label">Ed25519 Keys</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($ssh_keys, function($k) { return strpos($k['key_type'], 'rsa') !== false; })) ?></div>
                    <div class="stat-label">RSA Keys</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_unique(array_column($ssh_keys, 'user_id'))) ?></div>
                    <div class="stat-label">Users with Keys</div>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <!-- Generate New SSH Key -->
            <div class="card">
                <h3>Generate New SSH Key</h3>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label>User</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">Select User</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Key Name</label>
                        <input type="text" name="key_name" class="form-control" placeholder="id_rsa_hosting" required>
                        <small class="help-text">Name for the SSH key file (no spaces)</small>
                    </div>
                    <div class="form-group">
                        <label>Key Type</label>
                        <select name="key_type" class="form-control" required onchange="toggleKeySize(this.value)">
                            <option value="ed25519">Ed25519 (Recommended)</option>
                            <option value="rsa">RSA</option>
                        </select>
                    </div>
                    <div class="form-group" id="key-size-group" style="display: none;">
                        <label>Key Size (bits)</label>
                        <select name="key_size" class="form-control">
                            <option value="2048">2048</option>
                            <option value="4096" selected>4096</option>
                        </select>
                    </div>
                    <button type="submit" name="generate_key" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Generate SSH Key
                    </button>
                </form>
            </div>

            <!-- Upload SSH Key -->
            <div class="card">
                <h3>Upload Existing SSH Key</h3>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label>User</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">Select User</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Key Name</label>
                        <input type="text" name="key_name" class="form-control" placeholder="my_laptop_key" required>
                    </div>
                    <div class="form-group">
                        <label>Public Key</label>
                        <textarea name="public_key" class="form-control" rows="4" placeholder="ssh-rsa AAAAB3NzaC1yc2EAAAA... or ssh-ed25519 AAAAC3NzaC1lZDI1NTE5..." required></textarea>
                        <small class="help-text">Paste the complete public key (including key type and optional comment)</small>
                    </div>
                    <button type="submit" name="upload_key" class="btn btn-success">
                        <i class="fas fa-upload"></i> Upload SSH Key
                    </button>
                </form>
            </div>
        </div>

        <!-- SSH Keys List -->
        <div class="card">
            <h3>Existing SSH Keys</h3>
            <?php if (empty($ssh_keys)): ?>
                <div class="alert alert-info">No SSH keys found. Generate or upload a key to get started.</div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Key Name</th>
                                <th>Type</th>
                                <th>Fingerprint</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ssh_keys as $key): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($key['username']) ?></strong>
                                    <br><small><?= htmlspecialchars($key['email']) ?></small>
                                </td>
                                <td><code><?= htmlspecialchars($key['key_name']) ?></code></td>
                                <td>
                                    <span class="key-type key-<?= strtolower(str_replace('-', '', $key['key_type'])) ?>">
                                        <?= strtoupper($key['key_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <code class="fingerprint" title="<?= htmlspecialchars($key['public_key']) ?>">
                                        <?= substr(md5($key['public_key']), 0, 16) ?>...
                                    </code>
                                </td>
                                <td><?= date('M j, Y', strtotime($key['created_at'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-info btn-sm" onclick="showKey(<?= $key['id'] ?>)" title="View Public Key">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this SSH key?')">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <button type="submit" name="delete_key" class="btn btn-danger btn-sm" title="Delete Key">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- SSH Key Instructions -->
        <div class="card">
            <h3>SSH Key Usage Instructions</h3>
            <div class="instructions">
                <h4><i class="fas fa-info-circle"></i> How to use SSH keys:</h4>
                <ol>
                    <li><strong>Generate or Upload:</strong> Create a new SSH key or upload an existing public key</li>
                    <li><strong>Download Private Key:</strong> If generated here, download the private key to your local machine</li>
                    <li><strong>Set Permissions:</strong> On your local machine: <code>chmod 600 ~/.ssh/your_private_key</code></li>
                    <li><strong>Connect via SSH:</strong> <code>ssh -i ~/.ssh/your_private_key username@server</code></li>
                </ol>
                
                <h4><i class="fas fa-shield-alt"></i> Security Best Practices:</h4>
                <ul>
                    <li>Use Ed25519 keys for better security and performance</li>
                    <li>Use RSA keys with at least 4096 bits if Ed25519 is not supported</li>
                    <li>Never share your private key with anyone</li>
                    <li>Use different keys for different purposes/servers</li>
                    <li>Regularly rotate your SSH keys</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Key View Modal -->
    <div id="keyModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>SSH Public Key</h3>
            <textarea id="keyContent" readonly style="width: 100%; height: 200px; font-family: monospace;"></textarea>
            <button onclick="copyKey()" class="btn btn-primary">Copy to Clipboard</button>
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
        
        .key-type {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .key-sshrsa { background: #d1ecf1; color: #0c5460; }
        .key-sshed25519 { background: #d4edda; color: #155724; }
        .key-ecdsasha2nistp256 { background: #fff3cd; color: #856404; }
        
        .fingerprint {
            font-family: monospace;
            font-size: 0.85em;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .instructions {
            line-height: 1.6;
        }
        
        .instructions h4 {
            margin-top: 20px;
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        .instructions ol, .instructions ul {
            margin-left: 20px;
        }
        
        .instructions code {
            background: var(--code-bg);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
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
            margin: 15% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
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
        
        .help-text {
            font-size: 0.8em;
            color: var(--text-muted);
            margin-top: 4px;
        }
    </style>

    <script>
        function toggleKeySize(keyType) {
            const keySizeGroup = document.getElementById('key-size-group');
            if (keyType === 'rsa') {
                keySizeGroup.style.display = 'block';
            } else {
                keySizeGroup.style.display = 'none';
            }
        }

        function showKey(keyId) {
            const keys = <?= json_encode($ssh_keys) ?>;
            const key = keys.find(k => k.id == keyId);
            if (key) {
                document.getElementById('keyContent').value = key.public_key;
                document.getElementById('keyModal').style.display = 'block';
            }
        }

        function closeModal() {
            document.getElementById('keyModal').style.display = 'none';
        }

        function copyKey() {
            const keyContent = document.getElementById('keyContent');
            keyContent.select();
            document.execCommand('copy');
            alert('SSH key copied to clipboard!');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('keyModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>