<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$output = '';

if ($_POST) {
    if (!csrf_verify()) {
        $message = '<div class="alert alert-error">Invalid security token</div>';
    } else {
        if (isset($_POST['execute_command'])) {
            $command = $_POST['command'];
            $sanitizedCommand = escapeshellcmd($command);
            
            // Security: Block dangerous commands
            $blockedCommands = ['rm -rf', 'sudo rm', 'format', 'mkfs', 'dd if=', 'reboot', 'shutdown'];
            $isBlocked = false;
            foreach ($blockedCommands as $blocked) {
                if (stripos($command, $blocked) !== false) {
                    $isBlocked = true;
                    break;
                }
            }
            
            if ($isBlocked) {
                $output = "Command blocked for security reasons.";
            } else {
                // Execute command safely
                $output = shell_exec($sanitizedCommand . ' 2>&1');
                if ($output === null) {
                    $output = "Command executed but produced no output.";
                }
            }
        }
    }
}

// Get system info
$systemInfo = [
    'hostname' => gethostname(),
    'os' => php_uname('s') . ' ' . php_uname('r'),
    'php_version' => PHP_VERSION,
    'current_user' => get_current_user(),
    'working_directory' => getcwd()
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Web Terminal - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
    <style>
        .terminal-container {
            background: #000;
            border-radius: 8px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            color: #00ff00;
            min-height: 400px;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .terminal-header {
            color: #fff;
            margin-bottom: 20px;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        
        .terminal-input {
            display: flex;
            align-items: center;
            margin-top: 20px;
        }
        
        .terminal-prompt {
            color: #00ff00;
            margin-right: 10px;
            white-space: nowrap;
        }
        
        .terminal-command {
            flex: 1;
            background: transparent;
            border: none;
            color: #00ff00;
            font-family: inherit;
            font-size: 14px;
            outline: none;
        }
        
        .terminal-output {
            background: #111;
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            color: #fff;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .system-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .info-card {
            background: var(--card-bg);
            padding: 16px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .info-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .command-history {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
        }
        
        .history-item {
            padding: 8px 12px;
            border-radius: 4px;
            margin: 4px 0;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        
        .history-item:hover {
            background: rgba(227, 252, 2, 0.1);
        }
        
        .warning-box {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--warning-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="content-header">
            <h1><i class="fas fa-terminal"></i> Web Terminal</h1>
        </div>
        
        <?= $message ?>
        
        <!-- Security Warning -->
        <div class="warning-box">
            <h4><i class="fas fa-exclamation-triangle"></i> Security Warning</h4>
            <p>This terminal has limited access for security. Dangerous commands like system shutdown, disk formatting, and recursive deletions are blocked. Use with caution.</p>
        </div>
        
        <!-- System Information -->
        <div class="card">
            <h3><i class="fas fa-info-circle"></i> System Information</h3>
            <div class="system-info">
                <div class="info-card">
                    <div class="info-label">Hostname</div>
                    <div class="info-value"><?= htmlspecialchars($systemInfo['hostname']) ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Operating System</div>
                    <div class="info-value"><?= htmlspecialchars($systemInfo['os']) ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">PHP Version</div>
                    <div class="info-value"><?= htmlspecialchars($systemInfo['php_version']) ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Current User</div>
                    <div class="info-value"><?= htmlspecialchars($systemInfo['current_user']) ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Working Directory</div>
                    <div class="info-value"><?= htmlspecialchars($systemInfo['working_directory']) ?></div>
                </div>
            </div>
        </div>
        
        <!-- Terminal -->
        <div class="card">
            <h3><i class="fas fa-terminal"></i> Command Terminal</h3>
            
            <div class="terminal-container">
                <div class="terminal-header">
                    Web Terminal - <?= htmlspecialchars($systemInfo['hostname']) ?> (<?= htmlspecialchars($systemInfo['current_user']) ?>)
                </div>
                
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="terminal-input">
                        <span class="terminal-prompt"><?= htmlspecialchars($systemInfo['current_user']) ?>@<?= htmlspecialchars($systemInfo['hostname']) ?>:~$</span>
                        <input type="text" name="command" class="terminal-command" placeholder="Enter command..." autocomplete="off" autofocus>
                        <button type="submit" name="execute_command" style="display: none;"></button>
                    </div>
                </form>
                
                <?php if ($output): ?>
                <div class="terminal-output"><?= htmlspecialchars($output) ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Common Commands -->
        <div class="card">
            <h3><i class="fas fa-list"></i> Common Commands</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <button onclick="executeCommand('ls -la')" class="btn btn-secondary">
                    <i class="fas fa-folder"></i> List Files (ls -la)
                </button>
                <button onclick="executeCommand('df -h')" class="btn btn-secondary">
                    <i class="fas fa-hdd"></i> Disk Usage (df -h)
                </button>
                <button onclick="executeCommand('ps aux')" class="btn btn-secondary">
                    <i class="fas fa-tasks"></i> Process List (ps aux)
                </button>
                <button onclick="executeCommand('free -h')" class="btn btn-secondary">
                    <i class="fas fa-memory"></i> Memory Usage (free -h)
                </button>
                <button onclick="executeCommand('uptime')" class="btn btn-secondary">
                    <i class="fas fa-clock"></i> System Uptime
                </button>
                <button onclick="executeCommand('whoami')" class="btn btn-secondary">
                    <i class="fas fa-user"></i> Current User
                </button>
                <button onclick="executeCommand('pwd')" class="btn btn-secondary">
                    <i class="fas fa-map-marker-alt"></i> Current Directory
                </button>
                <button onclick="executeCommand('netstat -tlnp')" class="btn btn-secondary">
                    <i class="fas fa-network-wired"></i> Network Ports
                </button>
            </div>
        </div>
        
        <!-- Command History -->
        <div class="command-history">
            <h4><i class="fas fa-history"></i> Command History</h4>
            <div id="commandHistory">
                <p style="color: var(--text-muted); font-style: italic;">Commands you execute will appear here...</p>
            </div>
        </div>
    </div>
    
    <script>
        let commandHistory = JSON.parse(localStorage.getItem('terminalHistory') || '[]');
        
        function updateHistoryDisplay() {
            const historyDiv = document.getElementById('commandHistory');
            if (commandHistory.length === 0) {
                historyDiv.innerHTML = '<p style="color: var(--text-muted); font-style: italic;">Commands you execute will appear here...</p>';
                return;
            }
            
            historyDiv.innerHTML = '';
            commandHistory.slice(-10).reverse().forEach(cmd => {
                const item = document.createElement('div');
                item.className = 'history-item';
                item.textContent = cmd;
                item.onclick = () => {
                    document.querySelector('.terminal-command').value = cmd;
                };
                historyDiv.appendChild(item);
            });
        }
        
        function executeCommand(command) {
            document.querySelector('.terminal-command').value = command;
            document.querySelector('form').submit();
        }
        
        // Handle form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const command = document.querySelector('.terminal-command').value.trim();
            if (command) {
                commandHistory.push(command);
                localStorage.setItem('terminalHistory', JSON.stringify(commandHistory));
            }
        });
        
        // Handle Enter key
        document.querySelector('.terminal-command').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Initialize history display
        updateHistoryDisplay();
        
        // Auto-scroll terminal output
        const output = document.querySelector('.terminal-output');
        if (output) {
            output.scrollTop = output.scrollHeight;
        }
    </script>
</body>
</html>