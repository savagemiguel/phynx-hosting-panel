<?php
require_once '../config.php';
require_once '../includes/functions.php';

// Check admin authentication
requireAdmin(true);

// Verify CSRF token
if ($_POST && (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'])) {
    exit('Invalid CSRF token'); 
}

$message = '';

// Handle firewall actions
if ($_POST) {
    if (isset($_POST['toggle_firewall'])) {
        $action = $_POST['action'];
        
        if ($action === 'enable') {
            // Enable Windows Firewall
            exec('netsh advfirewall set allprofiles state on 2>&1', $output, $return_code);
            if ($return_code === 0) {
                $message = '<div class="alert alert-success">Windows Firewall enabled successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to enable firewall: ' . implode(' ', $output) . '</div>';
            }
        } elseif ($action === 'disable') {
            exec('netsh advfirewall set allprofiles state off 2>&1', $output, $return_code);
            if ($return_code === 0) {
                $message = '<div class="alert alert-success">Windows Firewall disabled successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to disable firewall: ' . implode(' ', $output) . '</div>';
            }
        }
    }
    
    if (isset($_POST['add_rule'])) {
        $ruleName = $_POST['rule_name'];
        $protocol = $_POST['protocol'];
        $port = $_POST['port'];
        $direction = $_POST['direction'];
        $action = $_POST['rule_action'];
        $remoteIP = $_POST['remote_ip'] ?: 'any';
        
        // Add firewall rule using netsh
        $command = "netsh advfirewall firewall add rule name=\"$ruleName\" dir=$direction action=$action protocol=$protocol localport=$port";
        if ($remoteIP !== 'any') {
            $command .= " remoteip=$remoteIP";
        }
        
        exec($command . ' 2>&1', $output, $return_code);
        if ($return_code === 0) {
            $message = '<div class="alert alert-success">Firewall rule added successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Failed to add rule: ' . implode(' ', $output) . '</div>';
        }
    }
    
    if (isset($_POST['delete_rule'])) {
        $ruleName = $_POST['rule_name'];
        exec("netsh advfirewall firewall delete rule name=\"$ruleName\" 2>&1", $output, $return_code);
        if ($return_code === 0) {
            $message = '<div class="alert alert-success">Firewall rule deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Failed to delete rule: ' . implode(' ', $output) . '</div>';
        }
    }
    
    if (isset($_POST['block_ip'])) {
        $ipAddress = $_POST['ip_address'];
        $ruleName = "Block_IP_" . str_replace('.', '_', $ipAddress);
        
        exec("netsh advfirewall firewall add rule name=\"$ruleName\" dir=in action=block remoteip=$ipAddress 2>&1", $output, $return_code);
        if ($return_code === 0) {
            $message = '<div class="alert alert-success">IP address blocked successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Failed to block IP: ' . implode(' ', $output) . '</div>';
        }
    }
}

// Get firewall status
function getFirewallStatus() {
    exec('netsh advfirewall show allprofiles state 2>&1', $output);
    $status = ['domain' => 'unknown', 'private' => 'unknown', 'public' => 'unknown'];
    
    foreach ($output as $line) {
        if (strpos($line, 'Domain Profile') !== false) {
            $next = next($output);
            $status['domain'] = strpos($next, 'ON') !== false ? 'enabled' : 'disabled';
        } elseif (strpos($line, 'Private Profile') !== false) {
            $next = next($output);
            $status['private'] = strpos($next, 'ON') !== false ? 'enabled' : 'disabled';
        } elseif (strpos($line, 'Public Profile') !== false) {
            $next = next($output);
            $status['public'] = strpos($next, 'ON') !== false ? 'enabled' : 'disabled';
        }
    }
    
    return $status;
}

// Get firewall rules
function getFirewallRules() {
    exec('netsh advfirewall firewall show rule name=all 2>&1', $output);
    $rules = [];
    $currentRule = [];
    
    foreach ($output as $line) {
        if (strpos($line, 'Rule Name:') !== false) {
            if (!empty($currentRule)) {
                $rules[] = $currentRule;
            }
            $currentRule = ['name' => trim(str_replace('Rule Name:', '', $line))];
        } elseif (strpos($line, 'Direction:') !== false) {
            $currentRule['direction'] = trim(str_replace('Direction:', '', $line));
        } elseif (strpos($line, 'Protocol:') !== false) {
            $currentRule['protocol'] = trim(str_replace('Protocol:', '', $line));
        } elseif (strpos($line, 'Action:') !== false) {
            $currentRule['action'] = trim(str_replace('Action:', '', $line));
        } elseif (strpos($line, 'Local Port:') !== false) {
            $currentRule['port'] = trim(str_replace('Local Port:', '', $line));
        }
    }
    
    if (!empty($currentRule)) {
        $rules[] = $currentRule;
    }
    
    // Filter out system rules and only show custom rules
    return array_filter($rules, function($rule) {
        return !in_array($rule['name'], [
            'Core Networking', 'File and Printer Sharing', 'Windows Management Instrumentation',
            'Remote Desktop', 'Windows Remote Management'
        ]);
    });
}

// Get network interfaces
function getNetworkInterfaces() {
    exec('netsh interface show interface 2>&1', $output);
    $interfaces = [];
    
    foreach ($output as $line) {
        if (preg_match('/^\s*(Enabled|Disabled)\s+(Connected|Disconnected)\s+(\w+)\s+(.+)$/', trim($line), $matches)) {
            $interfaces[] = [
                'status' => $matches[1],
                'connection' => $matches[2],
                'type' => $matches[3],
                'name' => trim($matches[4])
            ];
        }
    }
    
    return $interfaces;
}

$firewallStatus = getFirewallStatus();
$firewallRules = getFirewallRules();
$networkInterfaces = getNetworkInterfaces();

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firewall Configuration - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
            <div class="admin-header">
                <div class="header-left">
                    <h1><i class="fas fa-fire"></i> Firewall Configuration</h1>
                    <p>Manage Windows Firewall settings and security rules</p>
                </div>
                <div class="header-actions">
                    <form method="GET" style="display: inline;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Refresh Status
                        </button>
                    </form>
                </div>
            </div>

            <?= $message ?>

            <!-- Firewall Status Dashboard -->
            <div class="stats-grid">
                <div class="stat-card <?= $firewallStatus['domain'] === 'enabled' ? 'status-active' : 'status-inactive' ?>">
                    <div class="stat-icon"><i class="fas fa-building"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= ucfirst($firewallStatus['domain']) ?></div>
                        <div class="stat-label">Domain Profile</div>
                    </div>
                </div>
                <div class="stat-card <?= $firewallStatus['private'] === 'enabled' ? 'status-active' : 'status-inactive' ?>">
                    <div class="stat-icon"><i class="fas fa-home"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= ucfirst($firewallStatus['private']) ?></div>
                        <div class="stat-label">Private Profile</div>
                    </div>
                </div>
                <div class="stat-card <?= $firewallStatus['public'] === 'enabled' ? 'status-active' : 'status-inactive' ?>">
                    <div class="stat-icon"><i class="fas fa-globe"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= ucfirst($firewallStatus['public']) ?></div>
                        <div class="stat-label">Public Profile</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-list"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= count($firewallRules) ?></div>
                        <div class="stat-label">Custom Rules</div>
                    </div>
                </div>
            </div>

            <div class="grid">
                <!-- Firewall Controls -->
                <div class="card">
                    <h3>Firewall Controls</h3>
                    <div class="firewall-controls">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="enable">
                            <button type="submit" name="toggle_firewall" class="btn btn-success">
                                <i class="fas fa-shield-alt"></i> Enable Firewall
                            </button>
                        </form>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="disable">
                            <button type="submit" name="toggle_firewall" class="btn btn-danger" 
                                    onclick="return confirmAction('Are you sure you want to disable the firewall? This will reduce system security.')">
                                <i class="fas fa-shield-alt"></i> Disable Firewall
                            </button>
                        </form>
                    </div>
                    
                    <div class="security-notice">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Security Notice:</strong> Disabling the firewall exposes your system to potential security threats. 
                        Only disable when absolutely necessary and for the shortest time possible.
                    </div>
                </div>

                <!-- Add New Rule -->
                <div class="card">
                    <h3>Add Firewall Rule</h3>
                    <form method="POST" class="rule-form">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <div class="form-group">
                            <label>Rule Name</label>
                            <input type="text" name="rule_name" class="form-control" required 
                                   placeholder="e.g., Allow HTTP Traffic">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Protocol</label>
                                <select name="protocol" class="form-control" required>
                                    <option value="TCP">TCP</option>
                                    <option value="UDP">UDP</option>
                                    <option value="Any">Any</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Port</label>
                                <input type="text" name="port" class="form-control" required 
                                       placeholder="80, 443, 8080-8090">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Direction</label>
                                <select name="direction" class="form-control" required>
                                    <option value="in">Inbound</option>
                                    <option value="out">Outbound</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Action</label>
                                <select name="rule_action" class="form-control" required>
                                    <option value="allow">Allow</option>
                                    <option value="block">Block</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Remote IP Address (optional)</label>
                            <input type="text" name="remote_ip" class="form-control" 
                                   placeholder="Leave empty for any IP, or specify like 192.168.1.0/24">
                        </div>
                        
                        <button type="submit" name="add_rule" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Rule
                        </button>
                    </form>
                </div>
            </div>

            <!-- Existing Firewall Rules -->
            <div class="card">
                <div class="card-header">
                    <h3>Current Firewall Rules</h3>
                    <p>Custom firewall rules currently active on the system</p>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Rule Name</th>
                                <th>Direction</th>
                                <th>Protocol</th>
                                <th>Port</th>
                                <th>Action</th>
                                <th>Operations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($firewallRules)): ?>
                            <tr>
                                <td colspan="6" class="text-center">No custom firewall rules found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($firewallRules as $rule): ?>
                            <tr>
                                <td><?= htmlspecialchars($rule['name']) ?></td>
                                <td>
                                    <span class="badge <?= $rule['direction'] === 'In' ? 'badge-info' : 'badge-warning' ?>">
                                        <?= $rule['direction'] ?>bound
                                    </span>
                                </td>
                                <td><?= $rule['protocol'] ?? 'Any' ?></td>
                                <td><?= $rule['port'] ?? 'Any' ?></td>
                                <td>
                                    <span class="badge <?= ($rule['action'] ?? 'Allow') === 'Allow' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $rule['action'] ?? 'Allow' ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="rule_name" value="<?= htmlspecialchars($rule['name']) ?>">
                                        <button type="submit" name="delete_rule" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick IP Blocking -->
            <div class="card">
                <h3>Quick IP Blocking</h3>
                <form method="POST" class="ip-block-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label>IP Address to Block</label>
                            <input type="text" name="ip_address" class="form-control" required 
                                   placeholder="192.168.1.100 or 10.0.0.0/24" pattern="[0-9./]+">
                        </div>
                        <div class="form-group" style="flex: 0 0 auto; align-self: flex-end;">
                            <button type="submit" name="block_ip" class="btn btn-danger">
                                <i class="fas fa-ban"></i> Block IP
                            </button>
                        </div>
                    </div>
                </form>
                
                <div class="ip-examples">
                    <strong>Examples:</strong>
                    <ul>
                        <li><code>192.168.1.100</code> - Block specific IP</li>
                        <li><code>192.168.1.0/24</code> - Block entire subnet</li>
                        <li><code>10.0.0.0/8</code> - Block large network range</li>
                    </ul>
                </div>
            </div>

            <!-- Network Interfaces -->
            <div class="card">
                <h3>Network Interfaces</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Interface Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Connection</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($networkInterfaces as $interface): ?>
                            <tr>
                                <td><?= htmlspecialchars($interface['name']) ?></td>
                                <td><?= htmlspecialchars($interface['type']) ?></td>
                                <td>
                                    <span class="badge <?= $interface['status'] === 'Enabled' ? 'badge-success' : 'badge-secondary' ?>">
                                        <?= $interface['status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $interface['connection'] === 'Connected' ? 'badge-info' : 'badge-warning' ?>">
                                        <?= $interface['connection'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <style>
        .firewall-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .security-notice {
            padding: 15px;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            color: #856404;
        }
        
        .rule-form .form-row {
            display: flex;
            gap: 15px;
        }
        
        .rule-form .form-row .form-group {
            flex: 1;
        }
        
        .ip-block-form .form-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        
        .ip-examples {
            margin-top: 15px;
            padding: 10px;
            background: var(--bg-secondary);
            border-radius: 6px;
            font-size: 0.9em;
        }
        
        .ip-examples ul {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }
        
        .ip-examples code {
            background: var(--primary-color);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        
        .status-active {
            border-left: 4px solid var(--success-color);
        }
        
        .status-inactive {
            border-left: 4px solid var(--error-color);
        }
    </style>
    
    <script>
    function confirmAction(message) {
        return confirm(message);
    }
    
    // Ensure no modals are stuck open
    document.addEventListener('DOMContentLoaded', function() {
        // Remove any stuck modal backdrops
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
        
        // Ensure body is not modal-open
        document.body.classList.remove('modal-open');
        
        // Clear any overlay styles
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    });
    </script>
</body>
</html>