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

// Handle Fail2Ban actions (Windows EventLog equivalent)
if ($_POST) {
    if (isset($_POST['create_jail'])) {
        $jailName = $_POST['jail_name'];
        $logFile = $_POST['log_file'];
        $maxRetries = (int)$_POST['max_retries'];
        $banTime = (int)$_POST['ban_time'];
        $findTime = (int)$_POST['find_time'];
        $pattern = $_POST['pattern'];
        
        // Create Windows scheduled task for monitoring (simulated Fail2Ban)
        $taskName = "Fail2Ban_" . $jailName;
        $scriptPath = createMonitoringScript($jailName, $logFile, $pattern, $maxRetries, $banTime, $findTime);
        
        if ($scriptPath) {
            $command = "schtasks /create /tn \"$taskName\" /tr \"powershell.exe -ExecutionPolicy Bypass -File '$scriptPath'\" /sc minute /mo 1 /ru SYSTEM";
            exec($command . ' 2>&1', $output, $return_code);
            
            if ($return_code === 0) {
                $message = '<div class="alert alert-success">Fail2Ban jail created and monitoring started.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to create monitoring task: ' . implode(' ', $output) . '</div>';
            }
        } else {
            $message = '<div class="alert alert-error">Failed to create monitoring script.</div>';
        }
    }
    
    if (isset($_POST['toggle_jail'])) {
        $jailName = $_POST['jail_name'];
        $action = $_POST['action'];
        $taskName = "Fail2Ban_" . $jailName;
        
        if ($action === 'start') {
            exec("schtasks /run /tn \"$taskName\" 2>&1", $output, $return_code);
            $message = $return_code === 0 ? 
                '<div class="alert alert-success">Jail started successfully.</div>' :
                '<div class="alert alert-error">Failed to start jail.</div>';
        } else {
            exec("schtasks /end /tn \"$taskName\" 2>&1", $output, $return_code);
            $message = $return_code === 0 ? 
                '<div class="alert alert-success">Jail stopped successfully.</div>' :
                '<div class="alert alert-error">Failed to stop jail.</div>';
        }
    }
    
    if (isset($_POST['unban_ip'])) {
        $ipAddress = $_POST['ip_address'];
        $ruleName = "Fail2Ban_Block_" . str_replace('.', '_', $ipAddress);
        
        exec("netsh advfirewall firewall delete rule name=\"$ruleName\" 2>&1", $output, $return_code);
        if ($return_code === 0) {
            $message = '<div class="alert alert-success">IP address unbanned successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Failed to unban IP or IP was not banned.</div>';
        }
    }
    
    if (isset($_POST['ban_ip'])) {
        $ipAddress = $_POST['ip_address'];
        $banTime = (int)$_POST['ban_time'];
        
        banIP($ipAddress, $banTime);
        $message = '<div class="alert alert-success">IP address banned successfully.</div>';
    }
}

// Create monitoring script for Windows
function createMonitoringScript($jailName, $logFile, $pattern, $maxRetries, $banTime, $findTime) {
    $scriptsDir = dirname(__DIR__) . '/scripts';
    if (!is_dir($scriptsDir)) {
        mkdir($scriptsDir, 0755, true);
    }
    
    $scriptPath = $scriptsDir . "/fail2ban_$jailName.ps1";
    
    $scriptContent = "# Fail2Ban Monitor for $jailName
\$logFile = '$logFile'
\$pattern = '$pattern'
\$maxRetries = $maxRetries
\$banTime = $banTime
\$findTime = $findTime
\$trackingFile = '$scriptsDir/fail2ban_tracking_$jailName.txt'

# Initialize tracking file if it doesn't exist
if (!(Test-Path \$trackingFile)) {
    New-Item -ItemType File -Path \$trackingFile -Force | Out-Null
}

# Read tracking data
\$trackingData = @{}
if (Test-Path \$trackingFile) {
    Get-Content \$trackingFile | ForEach-Object {
        if (\$_ -match '^(.+?)\\|(.+?)\\|(.+)\$') {
            \$trackingData[\$matches[1]] = @{
                'count' = [int]\$matches[2]
                'lastSeen' = [DateTime]\$matches[3]
            }
        }
    }
}

# Check if log file exists
if (!(Test-Path \$logFile)) {
    Write-Host \"Log file not found: \$logFile\"
    exit 1
}

# Monitor log file for pattern matches
\$currentTime = Get-Date
\$recentEntries = Get-Content \$logFile -Tail 100 | Where-Object { \$_ -match \$pattern }

foreach (\$entry in \$recentEntries) {
    # Extract IP address from log entry
    if (\$entry -match '\\b(?:[0-9]{1,3}\\.){3}[0-9]{1,3}\\b') {
        \$ip = \$matches[0]
        
        # Update tracking
        if (\$trackingData.ContainsKey(\$ip)) {
            if ((\$currentTime - \$trackingData[\$ip]['lastSeen']).TotalSeconds -le \$findTime) {
                \$trackingData[\$ip]['count']++
            } else {
                \$trackingData[\$ip]['count'] = 1
            }
        } else {
            \$trackingData[\$ip] = @{
                'count' = 1
                'lastSeen' = \$currentTime
            }
        }
        
        \$trackingData[\$ip]['lastSeen'] = \$currentTime
        
        # Check if should ban
        if (\$trackingData[\$ip]['count'] -ge \$maxRetries) {
            # Ban the IP
            \$ruleName = \"Fail2Ban_Block_\" + (\$ip -replace '\\\.', '_')
            netsh advfirewall firewall add rule name=\"\$ruleName\" dir=in action=block remoteip=\$ip
            
            # Schedule unban
            \$unbanTime = \$currentTime.AddSeconds(\$banTime)
            \$unbanScript = \"netsh advfirewall firewall delete rule name=\\\"\$ruleName\\\"\"
            schtasks /create /tn \"Fail2Ban_Unban_\$ip\" /tr \"cmd /c \$unbanScript\" /sc once /st \$(\$unbanTime.ToString('HH:mm')) /sd \$(\$unbanTime.ToString('MM/dd/yyyy')) /ru SYSTEM /f
            
            Write-Host \"Banned IP: \$ip for \$banTime seconds\"
            \$trackingData[\$ip]['count'] = 0
        }
    }
}

# Save tracking data
\$trackingContent = @()
foreach (\$ip in \$trackingData.Keys) {
    # Clean old entries
    if ((\$currentTime - \$trackingData[\$ip]['lastSeen']).TotalSeconds -le (\$findTime * 2)) {
        \$trackingContent += \"\$ip|\$(\$trackingData[\$ip]['count'])|\$(\$trackingData[\$ip]['lastSeen'])\"
    }
}
\$trackingContent | Out-File -FilePath \$trackingFile -Encoding UTF8
";
    
    if (file_put_contents($scriptPath, $scriptContent) !== false) {
        return $scriptPath;
    }
    return false;
}

// Ban IP function
function banIP($ipAddress, $banTime) {
    $ruleName = "Fail2Ban_Block_" . str_replace('.', '_', $ipAddress);
    
    // Add firewall rule
    exec("netsh advfirewall firewall add rule name=\"$ruleName\" dir=in action=block remoteip=$ipAddress 2>&1");
    
    // Schedule unban
    $unbanTime = date('H:i', time() + $banTime);
    $unbanDate = date('m/d/Y', time() + $banTime);
    $unbanCommand = "netsh advfirewall firewall delete rule name=\"$ruleName\"";
    
    exec("schtasks /create /tn \"Fail2Ban_Unban_$ipAddress\" /tr \"cmd /c $unbanCommand\" /sc once /st $unbanTime /sd $unbanDate /ru SYSTEM /f 2>&1");
}

// Get active monitoring tasks
function getActiveTasks() {
    exec('schtasks /query /fo csv | findstr "Fail2Ban_"', $output);
    $tasks = [];
    
    foreach ($output as $line) {
        $data = str_getcsv($line);
        if (isset($data[0]) && strpos($data[0], 'Fail2Ban_') !== false) {
            $tasks[] = [
                'name' => $data[0],
                'status' => $data[3] ?? 'Unknown',
                'next_run' => $data[4] ?? 'N/A'
            ];
        }
    }
    
    return $tasks;
}

// Get banned IPs
function getBannedIPs() {
    exec('netsh advfirewall firewall show rule name=all | findstr /i "Fail2Ban_Block"', $output);
    $bannedIPs = [];
    
    foreach ($output as $line) {
        if (preg_match('/Fail2Ban_Block_(\d+_\d+_\d+_\d+)/', $line, $matches)) {
            $ip = str_replace('_', '.', $matches[1]);
            $bannedIPs[] = $ip;
        }
    }
    
    return array_unique($bannedIPs);
}

// Get recent security events from Windows Event Log
function getSecurityEvents() {
    $events = [];
    
    // Get failed login attempts (Event ID 4625)
    exec('wevtutil qe Security /q:"*[System[(EventID=4625)]]" /c:10 /rd:true /f:text', $output);
    
    $currentEvent = [];
    foreach ($output as $line) {
        if (strpos($line, 'Event[') !== false) {
            if (!empty($currentEvent)) {
                $events[] = $currentEvent;
            }
            $currentEvent = ['type' => 'Failed Login', 'details' => []];
        } elseif (!empty(trim($line))) {
            $currentEvent['details'][] = trim($line);
        }
    }
    
    if (!empty($currentEvent)) {
        $events[] = $currentEvent;
    }
    
    return array_slice($events, 0, 10);
}

$activeTasks = getActiveTasks();
$bannedIPs = getBannedIPs();
$securityEvents = getSecurityEvents();

// Predefined jail templates
$jailTemplates = [
    'ssh' => [
        'name' => 'SSH Protection',
        'log_file' => 'C:/Windows/System32/winevt/Logs/Security.evtx',
        'pattern' => 'EventID=4625',
        'max_retries' => 3,
        'ban_time' => 3600,
        'find_time' => 600
    ],
    'rdp' => [
        'name' => 'RDP Protection', 
        'log_file' => 'C:/Windows/System32/winevt/Logs/Security.evtx',
        'pattern' => 'EventID=4625.*TerminalServices',
        'max_retries' => 3,
        'ban_time' => 7200,
        'find_time' => 300
    ],
    'web' => [
        'name' => 'Web Server Protection',
        'log_file' => 'C:/inetpub/logs/LogFiles/W3SVC1/u_ex*.log',
        'pattern' => '(404|403|401)',
        'max_retries' => 10,
        'ban_time' => 1800,
        'find_time' => 600
    ]
];

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
    <title>Fail2Ban Settings - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
            <div class="admin-header">
                <div class="header-left">
                    <h1><i class="fas fa-ban"></i> Fail2Ban Settings</h1>
                    <p>Automated intrusion prevention and IP banning system</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh Status
                    </button>
                </div>
            </div>

            <?= $message ?>

            <!-- Statistics Dashboard -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= count($activeTasks) ?></div>
                        <div class="stat-label">Active Jails</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-ban"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= count($bannedIPs) ?></div>
                        <div class="stat-label">Banned IPs</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?= count($securityEvents) ?></div>
                        <div class="stat-label">Recent Threats</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Monitoring</div>
                    </div>
                </div>
            </div>

            <div class="grid">
                <!-- Quick Jail Templates -->
                <div class="card">
                    <h3>Quick Jail Setup</h3>
                    <div class="jail-templates">
                        <?php foreach ($jailTemplates as $key => $template): ?>
                        <div class="template-item">
                            <div class="template-info">
                                <h4><?= $template['name'] ?></h4>
                                <p>Max Retries: <?= $template['max_retries'] ?> | Ban Time: <?= $template['ban_time'] ?>s</p>
                            </div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="jail_name" value="<?= $key ?>">
                                <input type="hidden" name="log_file" value="<?= $template['log_file'] ?>">
                                <input type="hidden" name="pattern" value="<?= $template['pattern'] ?>">
                                <input type="hidden" name="max_retries" value="<?= $template['max_retries'] ?>">
                                <input type="hidden" name="ban_time" value="<?= $template['ban_time'] ?>">
                                <input type="hidden" name="find_time" value="<?= $template['find_time'] ?>">
                                <button type="submit" name="create_jail" class="btn btn-sm btn-primary">
                                    <i class="fas fa-plus"></i> Create
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Manual IP Management -->
                <div class="card">
                    <h3>Manual IP Management</h3>
                    
                    <!-- Ban IP -->
                    <div class="action-section">
                        <h4>Ban IP Address</h4>
                        <form method="POST" class="ban-form">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <input type="text" name="ip_address" class="form-control" required 
                                           placeholder="192.168.1.100" pattern="[0-9.]+">
                                </div>
                                <div class="form-group">
                                    <select name="ban_time" class="form-control">
                                        <option value="3600">1 Hour</option>
                                        <option value="21600">6 Hours</option>
                                        <option value="86400">24 Hours</option>
                                        <option value="604800">1 Week</option>
                                        <option value="2592000">30 Days</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="ban_ip" class="btn btn-danger">
                                        <i class="fas fa-ban"></i> Ban IP
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Unban IP -->
                    <div class="action-section">
                        <h4>Unban IP Address</h4>
                        <form method="POST" class="unban-form">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <input type="text" name="ip_address" class="form-control" required 
                                           placeholder="192.168.1.100" pattern="[0-9.]+">
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="unban_ip" class="btn btn-success">
                                        <i class="fas fa-unlock"></i> Unban IP
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Custom Jail Creation -->
            <div class="card">
                <h3>Create Custom Jail</h3>
                <form method="POST" class="jail-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Jail Name</label>
                            <input type="text" name="jail_name" class="form-control" required 
                                   placeholder="e.g., custom-web">
                        </div>
                        
                        <div class="form-group">
                            <label>Log File Path</label>
                            <input type="text" name="log_file" class="form-control" required 
                                   placeholder="C:/logs/application.log">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Pattern to Match</label>
                        <input type="text" name="pattern" class="form-control" required 
                               placeholder="Failed login|Authentication failed">
                        <small class="form-text">Regular expression or text pattern to detect in log files</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Max Retries</label>
                            <input type="number" name="max_retries" class="form-control" required 
                                   value="3" min="1" max="50">
                        </div>
                        
                        <div class="form-group">
                            <label>Ban Time (seconds)</label>
                            <input type="number" name="ban_time" class="form-control" required 
                                   value="3600" min="60">
                        </div>
                        
                        <div class="form-group">
                            <label>Find Time (seconds)</label>
                            <input type="number" name="find_time" class="form-control" required 
                                   value="600" min="60">
                        </div>
                    </div>
                    
                    <button type="submit" name="create_jail" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Jail
                    </button>
                </form>
            </div>

            <!-- Active Monitoring Tasks -->
            <div class="card">
                <h3>Active Monitoring Tasks</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Task Name</th>
                                <th>Status</th>
                                <th>Next Run</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activeTasks)): ?>
                            <tr>
                                <td colspan="4" class="text-center">No monitoring tasks active</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($activeTasks as $task): ?>
                            <tr>
                                <td><?= htmlspecialchars($task['name']) ?></td>
                                <td>
                                    <span class="badge <?= $task['status'] === 'Running' ? 'badge-success' : 'badge-warning' ?>">
                                        <?= htmlspecialchars($task['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($task['next_run']) ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="jail_name" value="<?= str_replace('Fail2Ban_', '', $task['name']) ?>">
                                        <input type="hidden" name="action" value="stop">
                                        <button type="submit" name="toggle_jail" class="btn btn-sm btn-warning">
                                            <i class="fas fa-stop"></i> Stop
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

            <!-- Currently Banned IPs -->
            <div class="card">
                <h3>Currently Banned IPs</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>Ban Reason</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bannedIPs)): ?>
                            <tr>
                                <td colspan="3" class="text-center">No IPs currently banned</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($bannedIPs as $ip): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($ip) ?></code></td>
                                <td>Fail2Ban Protection</td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="ip_address" value="<?= htmlspecialchars($ip) ?>">
                                        <button type="submit" name="unban_ip" class="btn btn-sm btn-success">
                                            <i class="fas fa-unlock"></i> Unban
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

            <!-- Recent Security Events -->
            <div class="card">
                <h3>Recent Security Events</h3>
                <div class="security-events">
                    <?php if (empty($securityEvents)): ?>
                    <p class="text-center">No recent security events detected</p>
                    <?php else: ?>
                    <?php foreach ($securityEvents as $event): ?>
                    <div class="event-item">
                        <div class="event-header">
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                            <strong><?= htmlspecialchars($event['type']) ?></strong>
                        </div>
                        <div class="event-details">
                            <?php foreach (array_slice($event['details'], 0, 3) as $detail): ?>
                            <div><?= htmlspecialchars($detail) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        .jail-templates {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .template-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-secondary);
        }
        
        .template-info h4 {
            margin: 0 0 5px 0;
            color: var(--primary-color);
        }
        
        .template-info p {
            margin: 0;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .action-section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .action-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .action-section h4 {
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .ban-form .form-row,
        .unban-form .form-row,
        .jail-form .form-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        
        .security-events {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .event-item {
            padding: 10px;
            margin-bottom: 10px;
            border-left: 4px solid var(--warning-color);
            background: var(--bg-secondary);
            border-radius: 0 6px 6px 0;
        }
        
        .event-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
        }
        
        .event-details {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .event-details div {
            margin-bottom: 2px;
        }
        
        code {
            background: var(--primary-color);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</body>
</html>