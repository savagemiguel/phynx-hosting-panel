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
$scanResults = [];

// Handle security scan actions
if ($_POST) {
    if (isset($_POST['run_scan'])) {
        $scanType = $_POST['scan_type'];
        
        switch ($scanType) {
            case 'file_permissions':
                $scanResults = scanFilePermissions();
                break;
            case 'config_audit':
                $scanResults = scanConfigurationFiles();
                break;
            case 'port_scan':
                $scanResults = scanOpenPorts();
                break;
            case 'system_vulnerabilities':
                $scanResults = scanSystemVulnerabilities();
                break;
            case 'web_vulnerabilities':
                $scanResults = scanWebVulnerabilities();
                break;
            case 'full_scan':
                $scanResults = runFullSecurityScan();
                break;
        }
        
        $message = '<div class="alert alert-success">Security scan completed. Results displayed below.</div>';
    }
    
    if (isset($_POST['fix_issue'])) {
        $issueType = $_POST['issue_type'];
        $issuePath = $_POST['issue_path'];
        
        $fixed = fixSecurityIssue($issueType, $issuePath);
        $message = $fixed ? 
            '<div class="alert alert-success">Security issue fixed successfully.</div>' :
            '<div class="alert alert-error">Failed to fix security issue.</div>';
    }
}

// Scan file permissions for security issues
function scanFilePermissions() {
    $issues = [];
    $criticalPaths = [
        '../config.php',
        '../includes/',
        '../admin/',
        '../assets/',
        'C:/Windows/System32/drivers/etc/hosts'
    ];
    
    foreach ($criticalPaths as $path) {
        if (file_exists($path)) {
            $permissions = fileperms($path);
            $octal = substr(sprintf('%o', $permissions), -4);
            
            // Check for overly permissive permissions
            if (is_file($path) && ($permissions & 0002)) {
                $issues[] = [
                    'severity' => 'high',
                    'type' => 'file_permissions',
                    'path' => $path,
                    'issue' => 'File is world-writable',
                    'current' => $octal,
                    'recommendation' => '0644 or 0600'
                ];
            }
            
            if (is_dir($path) && ($permissions & 0002)) {
                $issues[] = [
                    'severity' => 'medium',
                    'type' => 'file_permissions',
                    'path' => $path,
                    'issue' => 'Directory is world-writable',
                    'current' => $octal,
                    'recommendation' => '0755 or 0750'
                ];
            }
        }
    }
    
    return ['type' => 'File Permissions', 'issues' => $issues];
}

// Scan configuration files for security issues
function scanConfigurationFiles() {
    $issues = [];
    
    // Check PHP configuration
    $phpIssues = [];
    
    if (ini_get('display_errors') == '1') {
        $phpIssues[] = 'display_errors is enabled (security risk in production)';
    }
    
    if (ini_get('expose_php') == '1') {
        $phpIssues[] = 'expose_php is enabled (information disclosure)';
    }
    
    if (ini_get('allow_url_fopen') == '1') {
        $phpIssues[] = 'allow_url_fopen is enabled (potential security risk)';
    }
    
    if (ini_get('allow_url_include') == '1') {
        $phpIssues[] = 'allow_url_include is enabled (high security risk)';
    }
    
    foreach ($phpIssues as $issue) {
        $issues[] = [
            'severity' => 'medium',
            'type' => 'php_config',
            'path' => 'php.ini',
            'issue' => $issue,
            'recommendation' => 'Disable in production environment'
        ];
    }
    
    // Check web server configuration
    if (file_exists('../.htaccess')) {
        $htaccess = file_get_contents('../.htaccess');
        
        if (strpos($htaccess, 'ServerSignature') === false) {
            $issues[] = [
                'severity' => 'low',
                'type' => 'web_config',
                'path' => '.htaccess',
                'issue' => 'Server signature not disabled',
                'recommendation' => 'Add "ServerSignature Off" directive'
            ];
        }
        
        if (strpos($htaccess, 'X-Frame-Options') === false) {
            $issues[] = [
                'severity' => 'medium',
                'type' => 'web_config',
                'path' => '.htaccess',
                'issue' => 'X-Frame-Options header not set',
                'recommendation' => 'Add clickjacking protection'
            ];
        }
    }
    
    return ['type' => 'Configuration Audit', 'issues' => $issues];
}

// Scan for open ports
function scanOpenPorts() {
    $issues = [];
    $commonPorts = [21, 22, 23, 25, 53, 80, 110, 143, 443, 993, 995, 3389, 5985, 5986];
    $openPorts = [];
    
    foreach ($commonPorts as $port) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($connection) {
            $openPorts[] = $port;
            fclose($connection);
            
            // Flag potentially risky open ports
            $riskyPorts = [21 => 'FTP', 23 => 'Telnet', 25 => 'SMTP', 3389 => 'RDP'];
            if (isset($riskyPorts[$port])) {
                $issues[] = [
                    'severity' => 'medium',
                    'type' => 'open_port',
                    'path' => "Port $port",
                    'issue' => "{$riskyPorts[$port]} service exposed",
                    'recommendation' => 'Consider closing if not needed or restrict access'
                ];
            }
        }
    }
    
    return ['type' => 'Port Scan', 'issues' => $issues, 'open_ports' => $openPorts];
}

// Scan for system vulnerabilities
function scanSystemVulnerabilities() {
    $issues = [];
    
    // Check Windows version and patches
    exec('systeminfo | findstr /B "OS Name"', $osOutput);
    exec('systeminfo | findstr "System Boot Time"', $bootOutput);
    
    if (!empty($bootOutput)) {
        $bootTime = str_replace('System Boot Time:', '', $bootOutput[0]);
        $bootTimestamp = strtotime(trim($bootTime));
        $daysSinceBoot = (time() - $bootTimestamp) / (24 * 3600);
        
        if ($daysSinceBoot > 30) {
            $issues[] = [
                'severity' => 'medium',
                'type' => 'system_uptime',
                'path' => 'System',
                'issue' => 'System has not been rebooted for ' . round($daysSinceBoot) . ' days',
                'recommendation' => 'Regular reboots help apply security patches'
            ];
        }
    }
    
    // Check for available Windows updates
    $updateCommand = 'powershell.exe -Command "Get-WUList -AcceptAll | Select-Object Title"';
    exec($updateCommand . ' 2>nul', $updateOutput);
    
    if (!empty($updateOutput) && count($updateOutput) > 1) {
        $issues[] = [
            'severity' => 'high',
            'type' => 'windows_updates',
            'path' => 'Windows Update',
            'issue' => count($updateOutput) . ' pending Windows updates',
            'recommendation' => 'Install security updates immediately'
        ];
    }
    
    // Check antivirus status
    $avCommand = 'powershell.exe -Command "Get-MpComputerStatus | Select-Object AntivirusEnabled,RealTimeProtectionEnabled"';
    exec($avCommand . ' 2>nul', $avOutput);
    
    foreach ($avOutput as $line) {
        if (strpos($line, 'False') !== false) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'antivirus',
                'path' => 'Windows Defender',
                'issue' => 'Antivirus or real-time protection disabled',
                'recommendation' => 'Enable Windows Defender or install antivirus'
            ];
            break;
        }
    }
    
    return ['type' => 'System Vulnerabilities', 'issues' => $issues];
}

// Scan for web vulnerabilities
function scanWebVulnerabilities() {
    $issues = [];
    
    // Check for common vulnerable files
    $vulnerableFiles = [
        '../phpinfo.php' => 'phpinfo() file exposes system information',
        '../test.php' => 'Test files should be removed from production',
        '../backup.sql' => 'Database backup files should not be web accessible',
        '../config.bak' => 'Backup configuration files are accessible',
        '../.env' => 'Environment files should not be web accessible'
    ];
    
    foreach ($vulnerableFiles as $file => $risk) {
        if (file_exists($file)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'vulnerable_file',
                'path' => $file,
                'issue' => $risk,
                'recommendation' => 'Remove or move outside web root'
            ];
        }
    }
    
    // Check directory listing
    $testDirs = ['../admin/', '../includes/', '../assets/'];
    foreach ($testDirs as $dir) {
        if (is_dir($dir) && !file_exists($dir . 'index.php') && !file_exists($dir . 'index.html')) {
            $issues[] = [
                'severity' => 'medium',
                'type' => 'directory_listing',
                'path' => $dir,
                'issue' => 'Directory listing may be enabled',
                'recommendation' => 'Add index file or disable directory browsing'
            ];
        }
    }
    
    // Check for SQL injection patterns in logs
    $logFiles = glob('../logs/*.log');
    foreach ($logFiles as $logFile) {
        if (is_readable($logFile)) {
            $logContent = file_get_contents($logFile);
            $sqlPatterns = ["'", 'UNION', 'SELECT.*FROM', 'DROP TABLE', 'INSERT INTO'];
            
            foreach ($sqlPatterns as $pattern) {
                if (stripos($logContent, $pattern) !== false) {
                    $issues[] = [
                        'severity' => 'high',
                        'type' => 'sql_injection_attempt',
                        'path' => $logFile,
                        'issue' => 'Potential SQL injection attempts detected in logs',
                        'recommendation' => 'Review logs and implement input validation'
                    ];
                    break;
                }
            }
        }
    }
    
    return ['type' => 'Web Vulnerabilities', 'issues' => $issues];
}

// Run comprehensive security scan
function runFullSecurityScan() {
    $allResults = [
        scanFilePermissions(),
        scanConfigurationFiles(),
        scanOpenPorts(),
        scanSystemVulnerabilities(),
        scanWebVulnerabilities()
    ];
    
    $combinedIssues = [];
    foreach ($allResults as $result) {
        $combinedIssues = array_merge($combinedIssues, $result['issues']);
    }
    
    return ['type' => 'Full Security Scan', 'issues' => $combinedIssues, 'sub_scans' => $allResults];
}

// Fix security issues
function fixSecurityIssue($issueType, $issuePath) {
    switch ($issueType) {
        case 'file_permissions':
            // Fix file permissions
            if (is_file($issuePath)) {
                return chmod($issuePath, 0644);
            } elseif (is_dir($issuePath)) {
                return chmod($issuePath, 0755);
            }
            break;
            
        case 'vulnerable_file':
            // Remove or rename vulnerable files
            if (file_exists($issuePath)) {
                return unlink($issuePath) || rename($issuePath, $issuePath . '.bak');
            }
            break;
            
        case 'directory_listing':
            // Create index file to prevent directory listing
            $indexFile = rtrim($issuePath, '/') . '/index.php';
            return file_put_contents($indexFile, '<?php header("Location: ../"); exit; ?>') !== false;
    }
    
    return false;
}

// Get security recommendations
function getSecurityRecommendations() {
    return [
        'critical' => [
            'Keep all software updated with latest security patches',
            'Use strong, unique passwords for all accounts',
            'Enable two-factor authentication where possible',
            'Regular security scans and monitoring',
            'Implement proper backup procedures'
        ],
        'general' => [
            'Remove unnecessary services and software',
            'Use HTTPS for all web communications',
            'Implement proper error handling',
            'Regular log monitoring and analysis',
            'Network segmentation and firewall rules',
            'Regular penetration testing'
        ]
    ];
}

$recommendations = getSecurityRecommendations();

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
    <title>Security Scanner - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
            <div class="admin-header">
                <div class="header-left">
                    <h1><i class="fas fa-search"></i> Security Scanner</h1>
                    <p>Comprehensive security vulnerability assessment and recommendations</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="runQuickScan()">
                        <i class="fas fa-bolt"></i> Quick Scan
                    </button>
                </div>
            </div>

            <?= $message ?>

            <!-- Scan Controls -->
            <div class="card">
                <h3>Security Scan Options</h3>
                <div class="scan-controls">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="scan-options">
                            <div class="scan-option">
                                <input type="radio" name="scan_type" value="file_permissions" id="scan_permissions" checked>
                                <label for="scan_permissions">
                                    <i class="fas fa-lock"></i>
                                    <div>
                                        <strong>File Permissions</strong>
                                        <p>Check for insecure file and directory permissions</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="scan-option">
                                <input type="radio" name="scan_type" value="config_audit" id="scan_config">
                                <label for="scan_config">
                                    <i class="fas fa-cog"></i>
                                    <div>
                                        <strong>Configuration Audit</strong>
                                        <p>Review PHP and web server security settings</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="scan-option">
                                <input type="radio" name="scan_type" value="port_scan" id="scan_ports">
                                <label for="scan_ports">
                                    <i class="fas fa-network-wired"></i>
                                    <div>
                                        <strong>Port Scan</strong>
                                        <p>Identify open network ports and services</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="scan-option">
                                <input type="radio" name="scan_type" value="system_vulnerabilities" id="scan_system">
                                <label for="scan_system">
                                    <i class="fas fa-desktop"></i>
                                    <div>
                                        <strong>System Vulnerabilities</strong>
                                        <p>Check for OS updates and system security</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="scan-option">
                                <input type="radio" name="scan_type" value="web_vulnerabilities" id="scan_web">
                                <label for="scan_web">
                                    <i class="fas fa-globe"></i>
                                    <div>
                                        <strong>Web Vulnerabilities</strong>
                                        <p>Scan for common web security issues</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="scan-option full-scan">
                                <input type="radio" name="scan_type" value="full_scan" id="scan_full">
                                <label for="scan_full">
                                    <i class="fas fa-shield-alt"></i>
                                    <div>
                                        <strong>Full Security Scan</strong>
                                        <p>Comprehensive scan including all checks</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="scan-actions">
                            <button type="submit" name="run_scan" class="btn btn-primary btn-lg">
                                <i class="fas fa-play"></i> Start Security Scan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($scanResults)): ?>
            <!-- Scan Results -->
            <div class="card">
                <div class="card-header">
                    <h3>Scan Results: <?= htmlspecialchars($scanResults['type']) ?></h3>
                    <div class="scan-summary">
                        <?php 
                        $criticalCount = count(array_filter($scanResults['issues'], function($issue) { return $issue['severity'] === 'high'; }));
                        $mediumCount = count(array_filter($scanResults['issues'], function($issue) { return $issue['severity'] === 'medium'; }));
                        $lowCount = count(array_filter($scanResults['issues'], function($issue) { return $issue['severity'] === 'low'; }));
                        ?>
                        <span class="severity-count critical"><?= $criticalCount ?> Critical</span>
                        <span class="severity-count medium"><?= $mediumCount ?> Medium</span>
                        <span class="severity-count low"><?= $lowCount ?> Low</span>
                    </div>
                </div>
                
                <?php if (empty($scanResults['issues'])): ?>
                <div class="no-issues">
                    <i class="fas fa-check-circle text-success"></i>
                    <h4>No Security Issues Found</h4>
                    <p>Your system appears to be secure based on this scan.</p>
                </div>
                <?php else: ?>
                <div class="security-issues">
                    <?php foreach ($scanResults['issues'] as $issue): ?>
                    <div class="issue-item severity-<?= $issue['severity'] ?>">
                        <div class="issue-header">
                            <div class="severity-badge <?= $issue['severity'] ?>">
                                <?= ucfirst($issue['severity']) ?>
                            </div>
                            <h4><?= htmlspecialchars($issue['issue']) ?></h4>
                        </div>
                        
                        <div class="issue-details">
                            <div class="issue-info">
                                <strong>Path:</strong> <code><?= htmlspecialchars($issue['path']) ?></code>
                            </div>
                            
                            <?php if (isset($issue['current'])): ?>
                            <div class="issue-info">
                                <strong>Current:</strong> <code><?= htmlspecialchars($issue['current']) ?></code>
                            </div>
                            <?php endif; ?>
                            
                            <div class="issue-recommendation">
                                <strong>Recommendation:</strong> <?= htmlspecialchars($issue['recommendation']) ?>
                            </div>
                        </div>
                        
                        <?php if (in_array($issue['type'], ['file_permissions', 'vulnerable_file', 'directory_listing'])): ?>
                        <div class="issue-actions">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="issue_type" value="<?= htmlspecialchars($issue['type']) ?>">
                                <input type="hidden" name="issue_path" value="<?= htmlspecialchars($issue['path']) ?>">
                                <button type="submit" name="fix_issue" class="btn btn-sm btn-success"
                                        onclick="return confirm('Attempt to automatically fix this issue?')">
                                    <i class="fas fa-wrench"></i> Auto Fix
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if (isset($scanResults['open_ports'])): ?>
                <div class="scan-additional-info">
                    <h4>Open Ports Detected</h4>
                    <div class="port-list">
                        <?php foreach ($scanResults['open_ports'] as $port): ?>
                        <span class="port-badge"><?= $port ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Security Recommendations -->
            <div class="card">
                <h3>Security Best Practices</h3>
                
                <div class="recommendations">
                    <div class="recommendation-section">
                        <h4><i class="fas fa-exclamation-triangle text-danger"></i> Critical Security Measures</h4>
                        <ul class="recommendation-list critical">
                            <?php foreach ($recommendations['critical'] as $rec): ?>
                            <li><?= htmlspecialchars($rec) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div class="recommendation-section">
                        <h4><i class="fas fa-info-circle text-info"></i> General Security Guidelines</h4>
                        <ul class="recommendation-list general">
                            <?php foreach ($recommendations['general'] as $rec): ?>
                            <li><?= htmlspecialchars($rec) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Security Tools -->
            <div class="card">
                <h3>Security Tools & Resources</h3>
                <div class="security-tools">
                    <div class="tool-item">
                        <i class="fas fa-shield-virus"></i>
                        <div>
                            <h4>Malware Scanner</h4>
                            <p>Scan for malicious files and code</p>
                            <a href="malware-scan.php" class="btn btn-sm btn-primary">Launch Scanner</a>
                        </div>
                    </div>
                    
                    <div class="tool-item">
                        <i class="fas fa-fire"></i>
                        <div>
                            <h4>Firewall Configuration</h4>
                            <p>Manage network security rules</p>
                            <a href="firewall-config.php" class="btn btn-sm btn-primary">Configure</a>
                        </div>
                    </div>
                    
                    <div class="tool-item">
                        <i class="fas fa-ban"></i>
                        <div>
                            <h4>Fail2Ban Settings</h4>
                            <p>Automated intrusion prevention</p>
                            <a href="fail2ban.php" class="btn btn-sm btn-primary">Manage</a>
                        </div>
                    </div>
                    
                    <div class="tool-item">
                        <i class="fas fa-file-alt"></i>
                        <div>
                            <h4>Security Logs</h4>
                            <p>Monitor security events and alerts</p>
                            <a href="log-viewer.php" class="btn btn-sm btn-primary">View Logs</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .scan-controls {
            padding: 0;
        }
        
        .scan-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .scan-option {
            position: relative;
        }
        
        .scan-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        
        .scan-option label {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--bg-secondary);
        }
        
        .scan-option input[type="radio"]:checked + label {
            border-color: var(--primary-color);
            background: var(--primary-light);
        }
        
        .scan-option label i {
            font-size: 1.5em;
            margin-right: 12px;
            color: var(--primary-color);
            margin-top: 2px;
        }
        
        .scan-option label strong {
            display: block;
            margin-bottom: 4px;
        }
        
        .scan-option label p {
            margin: 0;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .full-scan label {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            color: white;
        }
        
        .full-scan label p {
            color: rgba(255, 255, 255, 0.9);
        }
        
        .scan-actions {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .scan-summary {
            display: flex;
            gap: 10px;
        }
        
        .severity-count {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
        }
        
        .severity-count.critical {
            background: var(--error-color);
            color: white;
        }
        
        .severity-count.medium {
            background: var(--warning-color);
            color: white;
        }
        
        .severity-count.low {
            background: var(--info-color);
            color: white;
        }
        
        .no-issues {
            text-align: center;
            padding: 40px;
            color: var(--success-color);
        }
        
        .no-issues i {
            font-size: 3em;
            margin-bottom: 15px;
        }
        
        .issue-item {
            border-left: 4px solid;
            margin-bottom: 15px;
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 0 6px 6px 0;
        }
        
        .issue-item.severity-high {
            border-left-color: var(--error-color);
        }
        
        .issue-item.severity-medium {
            border-left-color: var(--warning-color);
        }
        
        .issue-item.severity-low {
            border-left-color: var(--info-color);
        }
        
        .issue-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .severity-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .severity-badge.high {
            background: var(--error-color);
            color: white;
        }
        
        .severity-badge.medium {
            background: var(--warning-color);
            color: white;
        }
        
        .severity-badge.low {
            background: var(--info-color);
            color: white;
        }
        
        .issue-details {
            margin-bottom: 10px;
        }
        
        .issue-info {
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        
        .issue-recommendation {
            padding: 10px;
            background: var(--bg-primary);
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .port-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        
        .port-badge {
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8em;
        }
        
        .recommendations {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .recommendation-section h4 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .recommendation-list {
            margin: 0;
            padding-left: 20px;
        }
        
        .recommendation-list li {
            margin-bottom: 8px;
        }
        
        .security-tools {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .tool-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-secondary);
        }
        
        .tool-item i {
            font-size: 1.5em;
            color: var(--primary-color);
            margin-top: 2px;
        }
        
        .tool-item h4 {
            margin: 0 0 5px 0;
        }
        
        .tool-item p {
            margin: 0 0 10px 0;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        @media (max-width: 768px) {
            .scan-options {
                grid-template-columns: 1fr;
            }
            
            .recommendations {
                grid-template-columns: 1fr;
            }
        }
    </style>
    
    <script>
        function runQuickScan() {
            document.getElementById('scan_permissions').checked = true;
            document.querySelector('form').submit();
        }
        
        // Auto-refresh scan status
        setInterval(function() {
            // Could add AJAX polling for scan status if implementing background scans
        }, 5000);
    </script>
</body>
</html>