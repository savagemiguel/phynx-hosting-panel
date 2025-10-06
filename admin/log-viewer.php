<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$selectedLog = $_GET['log'] ?? 'apache_access';
$lines = (int)($_GET['lines'] ?? 100);
$search = $_GET['search'] ?? '';
$level = $_GET['level'] ?? '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle log actions
if ($_POST) {
    if (isset($_POST['clear_log'])) {
        $logFile = $_POST['log_file'];
        $allowedLogs = getAvailableLogs();
        
        if (isset($allowedLogs[$logFile])) {
            $filePath = $allowedLogs[$logFile]['path'];
            if (file_exists($filePath) && is_writable($filePath)) {
                file_put_contents($filePath, '');
                $message = '<div class="alert alert-success">Log file cleared successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Cannot clear log file - permission denied or file not found.</div>';
            }
        }
    }
    
    if (isset($_POST['download_log'])) {
        $logFile = $_POST['log_file'];
        $allowedLogs = getAvailableLogs();
        
        if (isset($allowedLogs[$logFile])) {
            $filePath = $allowedLogs[$logFile]['path'];
            if (file_exists($filePath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filePath) . '_' . date('Y-m-d_H-i-s') . '.log"');
                readfile($filePath);
                exit;
            }
        }
    }
}

// Get available log files
function getAvailableLogs() {
    return [
        'apache_access' => [
            'name' => 'Apache Access Log',
            'path' => '/var/log/apache2/access.log',
            'icon' => 'fas fa-globe'
        ],
        'apache_error' => [
            'name' => 'Apache Error Log',
            'path' => '/var/log/apache2/error.log',
            'icon' => 'fas fa-exclamation-triangle'
        ],
        'mysql' => [
            'name' => 'MySQL Error Log',
            'path' => '/var/log/mysql/error.log',
            'icon' => 'fas fa-database'
        ],
        'php_error' => [
            'name' => 'PHP Error Log',
            'path' => '/var/log/php_errors.log',
            'icon' => 'fab fa-php'
        ],
        'system' => [
            'name' => 'System Log',
            'path' => '/var/log/syslog',
            'icon' => 'fas fa-server'
        ],
        'auth' => [
            'name' => 'Authentication Log',
            'path' => '/var/log/auth.log',
            'icon' => 'fas fa-lock'
        ],
        'mail' => [
            'name' => 'Mail Log',
            'path' => '/var/log/mail.log',
            'icon' => 'fas fa-envelope'
        ],
        'fail2ban' => [
            'name' => 'Fail2Ban Log',
            'path' => '/var/log/fail2ban.log',
            'icon' => 'fas fa-ban'
        ]
    ];
}

// Read log file with filtering
function readLogFile($logKey, $lines = 100, $search = '', $level = '') {
    $logs = getAvailableLogs();
    
    if (!isset($logs[$logKey])) {
        return ['error' => 'Invalid log file'];
    }
    
    $filePath = $logs[$logKey]['path'];
    
    if (!file_exists($filePath)) {
        return ['error' => 'Log file not found: ' . $filePath];
    }
    
    if (!is_readable($filePath)) {
        return ['error' => 'Cannot read log file - permission denied'];
    }
    
    // Get file info
    $fileSize = filesize($filePath);
    $lastModified = filemtime($filePath);
    
    // Read last N lines
    $command = "tail -n {$lines} " . escapeshellarg($filePath);
    
    // Apply search filter if specified
    if (!empty($search)) {
        $command .= " | grep -i " . escapeshellarg($search);
    }
    
    // Apply level filter for error logs
    if (!empty($level) && in_array($logKey, ['apache_error', 'mysql', 'php_error', 'system'])) {
        $levelPattern = '';
        switch ($level) {
            case 'error':
                $levelPattern = '\[error\]|\[ERROR\]|ERROR|Error';
                break;
            case 'warning':
                $levelPattern = '\[warn\]|\[WARNING\]|WARNING|Warning';
                break;
            case 'info':
                $levelPattern = '\[info\]|\[INFO\]|INFO|Info';
                break;
        }
        if ($levelPattern) {
            $command .= " | grep -E '" . $levelPattern . "'";
        }
    }
    
    exec($command . ' 2>&1', $output, $returnCode);
    
    if ($returnCode !== 0 && empty($output)) {
        $output = ['No entries found matching the criteria.'];
    }
    
    return [
        'lines' => $output,
        'file_size' => $fileSize,
        'last_modified' => $lastModified,
        'total_lines' => count(file($filePath))
    ];
}

// Get log statistics
function getLogStats() {
    $logs = getAvailableLogs();
    $stats = [];
    
    foreach ($logs as $key => $log) {
        if (file_exists($log['path'])) {
            $stats[$key] = [
                'size' => filesize($log['path']),
                'modified' => filemtime($log['path']),
                'readable' => is_readable($log['path'])
            ];
        } else {
            $stats[$key] = [
                'size' => 0,
                'modified' => 0,
                'readable' => false
            ];
        }
    }
    
    return $stats;
}

$availableLogs = getAvailableLogs();
$logStats = getLogStats();
$logData = readLogFile($selectedLog, $lines, $search, $level);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-file-alt"></i> Log Viewer</h1>
        
        <?= $message ?>
        
        <!-- Log Overview -->
        <div class="card">
            <h3>Log File Overview</h3>
            <div class="log-overview">
                <?php foreach ($availableLogs as $key => $log): ?>
                <div class="log-item">
                    <div class="log-icon">
                        <i class="<?= $log['icon'] ?>"></i>
                    </div>
                    <div class="log-info">
                        <div class="log-name"><?= htmlspecialchars($log['name']) ?></div>
                        <div class="log-details">
                            <?php if (isset($logStats[$key]) && $logStats[$key]['readable']): ?>
                                Size: <?= formatBytes($logStats[$key]['size']) ?> | 
                                Modified: <?= date('M j, H:i', $logStats[$key]['modified']) ?>
                            <?php else: ?>
                                <span class="text-error">Not accessible</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="log-actions">
                        <a href="?log=<?= $key ?>" class="btn btn-sm <?= $selectedLog === $key ? 'btn-primary' : 'btn-secondary' ?>">
                            <?= $selectedLog === $key ? 'Viewing' : 'View' ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Log Controls -->
        <div class="card">
            <h3>Log Controls - <?= htmlspecialchars($availableLogs[$selectedLog]['name']) ?></h3>
            
            <form method="GET" class="log-controls">
                <input type="hidden" name="log" value="<?= htmlspecialchars($selectedLog) ?>">
                
                <div class="control-group">
                    <label>Lines to show:</label>
                    <select name="lines" class="form-control">
                        <option value="50" <?= $lines == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $lines == 100 ? 'selected' : '' ?>>100</option>
                        <option value="250" <?= $lines == 250 ? 'selected' : '' ?>>250</option>
                        <option value="500" <?= $lines == 500 ? 'selected' : '' ?>>500</option>
                        <option value="1000" <?= $lines == 1000 ? 'selected' : '' ?>>1000</option>
                    </select>
                </div>
                
                <div class="control-group">
                    <label>Search:</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search in log..." class="form-control">
                </div>
                
                <?php if (in_array($selectedLog, ['apache_error', 'mysql', 'php_error', 'system'])): ?>
                <div class="control-group">
                    <label>Level:</label>
                    <select name="level" class="form-control">
                        <option value="">All levels</option>
                        <option value="error" <?= $level == 'error' ? 'selected' : '' ?>>Error</option>
                        <option value="warning" <?= $level == 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="info" <?= $level == 'info' ? 'selected' : '' ?>>Info</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                
                <a href="?log=<?= $selectedLog ?>" class="btn btn-secondary">
                    <i class="fas fa-sync-alt"></i> Refresh
                </a>
            </form>
            
            <div class="log-actions-bar">
                <form method="POST" style="display: inline;" onsubmit="return confirm('Download this log file?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="log_file" value="<?= $selectedLog ?>">
                    <button type="submit" name="download_log" class="btn btn-info">
                        <i class="fas fa-download"></i> Download
                    </button>
                </form>
                
                <form method="POST" style="display: inline;" onsubmit="return confirm('This will clear all content from the log file. Are you sure?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="log_file" value="<?= $selectedLog ?>">
                    <button type="submit" name="clear_log" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Clear Log
                    </button>
                </form>
            </div>
        </div>

        <!-- Log Content -->
        <div class="card">
            <div class="log-header">
                <h3>Log Content</h3>
                <?php if (isset($logData['total_lines'])): ?>
                <div class="log-info-bar">
                    Showing last <?= count($logData['lines']) ?> of <?= $logData['total_lines'] ?> lines | 
                    File size: <?= formatBytes($logData['file_size']) ?> |
                    Last modified: <?= date('M j, Y H:i:s', $logData['last_modified']) ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="log-content">
                <?php if (isset($logData['error'])): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($logData['error']) ?></div>
                <?php elseif (empty($logData['lines'])): ?>
                    <div class="alert alert-info">No log entries found.</div>
                <?php else: ?>
                    <pre class="log-lines"><?php
                        foreach ($logData['lines'] as $i => $line) {
                            $lineNumber = $i + 1;
                            $lineClass = '';
                            
                            // Colorize based on content
                            if (preg_match('/\[error\]|\[ERROR\]|ERROR|Error|CRITICAL|FATAL/i', $line)) {
                                $lineClass = 'log-error';
                            } elseif (preg_match('/\[warn\]|\[WARNING\]|WARNING|Warning/i', $line)) {
                                $lineClass = 'log-warning';
                            } elseif (preg_match('/\[info\]|\[INFO\]|INFO|Info/i', $line)) {
                                $lineClass = 'log-info';
                            } elseif (preg_match('/\[debug\]|\[DEBUG\]|DEBUG|Debug/i', $line)) {
                                $lineClass = 'log-debug';
                            }
                            
                            echo '<span class="log-line ' . $lineClass . '" data-line="' . $lineNumber . '">';
                            echo htmlspecialchars($line);
                            echo '</span>' . "\n";
                        }
                    ?></pre>
                <?php endif; ?>
            </div>
            
            <div class="log-footer">
                <button onclick="copyLogContent()" class="btn btn-secondary">
                    <i class="fas fa-copy"></i> Copy All
                </button>
                <button onclick="scrollToTop()" class="btn btn-secondary">
                    <i class="fas fa-arrow-up"></i> Top
                </button>
                <button onclick="scrollToBottom()" class="btn btn-secondary">
                    <i class="fas fa-arrow-down"></i> Bottom
                </button>
                <button onclick="toggleLineNumbers()" class="btn btn-secondary" id="toggleNumbers">
                    <i class="fas fa-list-ol"></i> Toggle Numbers
                </button>
            </div>
        </div>
    </div>

    <style>
        .log-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 15px;
        }
        
        .log-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .log-icon {
            font-size: 1.5em;
            margin-right: 15px;
            color: var(--primary-color);
            min-width: 30px;
        }
        
        .log-info {
            flex: 1;
        }
        
        .log-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .log-details {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .log-controls {
            display: flex;
            align-items: end;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .log-controls .btn {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 0.9em;
            white-space: nowrap;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }
        
        .control-group label {
            font-size: 0.9em;
            margin-bottom: 5px;
            color: var(--text-muted);
        }
        
        .log-actions-bar {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            align-items: center;
            flex-wrap: wrap;
        }
        
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .log-info-bar {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .log-content {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: #1e1e1e;
            color: #d4d4d4;
        }
        
        .log-lines {
            margin: 0;
            padding: 15px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.85em;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .log-line {
            display: block;
            position: relative;
            padding-left: 0;
        }
        
        .log-line.show-numbers::before {
            content: attr(data-line);
            display: inline-block;
            width: 40px;
            margin-right: 10px;
            color: #6a6a6a;
            text-align: right;
            border-right: 1px solid #3a3a3a;
            padding-right: 8px;
        }
        
        .log-error {
            background-color: rgba(244, 67, 54, 0.1);
            color: #ff6b6b;
        }
        
        .log-warning {
            background-color: rgba(255, 152, 0, 0.1);
            color: #ffb74d;
        }
        
        .log-info {
            background-color: rgba(33, 150, 243, 0.1);
            color: #64b5f6;
        }
        
        .log-debug {
            color: #81c784;
        }
        
        .log-footer {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            align-items: center;
            flex-wrap: wrap;
        }
        
        .log-footer .btn,
        .log-actions-bar .btn {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 0.9em;
            white-space: nowrap;
        }
        
        .text-error {
            color: #dc3545;
        }
    </style>

    <script>
        function copyLogContent() {
            const logContent = document.querySelector('.log-lines');
            if (logContent) {
                const range = document.createRange();
                range.selectNode(logContent);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
                document.execCommand('copy');
                window.getSelection().removeAllRanges();
                alert('Log content copied to clipboard!');
            }
        }
        
        function scrollToTop() {
            document.querySelector('.log-content').scrollTop = 0;
        }
        
        function scrollToBottom() {
            const container = document.querySelector('.log-content');
            container.scrollTop = container.scrollHeight;
        }
        
        function toggleLineNumbers() {
            const lines = document.querySelectorAll('.log-line');
            const button = document.getElementById('toggleNumbers');
            
            lines.forEach(line => {
                line.classList.toggle('show-numbers');
            });
            
            if (lines[0] && lines[0].classList.contains('show-numbers')) {
                button.innerHTML = '<i class="fas fa-list"></i> Hide Numbers';
            } else {
                button.innerHTML = '<i class="fas fa-list-ol"></i> Show Numbers';
            }
        }
        
        // Auto-scroll to bottom on page load for real-time feel
        document.addEventListener('DOMContentLoaded', function() {
            scrollToBottom();
        });
    </script>
</body>
</html>