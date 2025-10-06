<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle process actions - Windows compatible
if ($_POST) {
    if (isset($_POST['kill_process'])) {
        $pid = (int)$_POST['pid'];
        $signal = $_POST['signal'] ?? 'TERM';
        
        // Validate signal (adapted for Windows)
        $allowedSignals = ['TERM', 'KILL'];
        if (in_array($signal, $allowedSignals)) {
            try {
                if ($signal === 'KILL') {
                    // Force terminate process
                    exec("taskkill /F /PID {$pid} 2>&1", $output, $return_code);
                } else {
                    // Normal terminate process
                    exec("taskkill /PID {$pid} 2>&1", $output, $return_code);
                }
                
                if ($return_code === 0) {
                    $message = '<div class="alert alert-success">Process ' . $pid . ' terminated successfully.</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to terminate process: ' . implode(' ', $output) . '</div>';
                }
            } catch (Exception $e) {
                $message = '<div class="alert alert-error">Error terminating process: ' . $e->getMessage() . '</div>';
            }
        } else {
            $message = '<div class="alert alert-error">Invalid signal specified. Only TERM and KILL are supported on Windows.</div>';
        }
    }
}

// Get system processes - Windows compatible
function getSystemProcesses($filter = '') {
    $processes = [];
    
    try {
        // Use PowerShell to get process information on Windows
        $command = 'powershell.exe -Command "Get-Process | Select-Object Name,Id,CPU,WorkingSet,VirtualMemorySize,ProcessorAffinity,StartTime | ConvertTo-Json"';
        if (!empty($filter)) {
            $command = 'powershell.exe -Command "Get-Process | Where-Object {$_.Name -like \'*' . addslashes($filter) . '*\'} | Select-Object Name,Id,CPU,WorkingSet,VirtualMemorySize,ProcessorAffinity,StartTime | ConvertTo-Json"';
        }
        
        $output = shell_exec($command);
        
        if ($output) {
            $processData = json_decode($output, true);
            
            if (is_array($processData)) {
                // Handle single process (not array) or multiple processes
                if (isset($processData['Name'])) {
                    $processData = [$processData];
                }
                
                foreach ($processData as $proc) {
                    $processes[] = [
                        'user' => 'N/A', // Not easily available on Windows
                        'pid' => $proc['Id'] ?? 0,
                        'cpu' => isset($proc['CPU']) ? round($proc['CPU'], 2) : 0,
                        'mem' => isset($proc['WorkingSet']) ? round($proc['WorkingSet'] / 1024 / 1024, 1) : 0, // Convert to MB
                        'vsz' => isset($proc['VirtualMemorySize']) ? intval($proc['VirtualMemorySize'] / 1024) : 0, // Convert to KB
                        'rss' => isset($proc['WorkingSet']) ? intval($proc['WorkingSet'] / 1024) : 0, // Convert to KB
                        'tty' => 'N/A',
                        'stat' => 'Running',
                        'start' => isset($proc['StartTime']) ? date('H:i:s', strtotime($proc['StartTime'])) : 'N/A',
                        'time' => isset($proc['CPU']) ? gmdate('H:i:s', intval($proc['CPU'])) : '0:00',
                        'command' => $proc['Name'] ?? 'Unknown'
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Fallback: use basic tasklist command
        $command = 'tasklist /fo csv';
        if (!empty($filter)) {
            $command .= ' /fi "IMAGENAME eq *' . $filter . '*"';
        }
        
        $output = shell_exec($command);
        if ($output) {
            $lines = explode("\n", trim($output));
            array_shift($lines); // Remove header
            
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $parts = str_getcsv(trim($line));
                if (count($parts) >= 5) {
                    $process = [
                        'user' => 'N/A',
                        'pid' => intval($parts[1]),
                        'cpu' => 0, // Not available in tasklist
                        'mem' => floatval(str_replace(',', '', $parts[4])) / 1024, // Convert KB to MB
                        'vsz' => 0,
                        'rss' => floatval(str_replace(',', '', $parts[4])),
                        'tty' => 'N/A',
                        'stat' => 'Running',
                        'start' => 'N/A',
                        'time' => '0:00',
                        'command' => $parts[0]
                    ];
                    
                    // Apply filter if specified
                    if (empty($filter) || 
                        strpos(strtolower($process['command']), strtolower($filter)) !== false) {
                        $processes[] = $process;
                    }
                }
            }
        }
    }
    
    return $processes;
}

// Get system load and stats - Windows compatible
function getSystemLoad() {
    try {
        // Get CPU usage using PowerShell - try multiple methods
        $cpuLoad = 0;
        
        // Method 1: Try Get-Counter for more reliable CPU usage
        $cpuCommand = 'powershell.exe -Command "try { (Get-Counter \'\\Processor(_Total)\\% Processor Time\').CounterSamples[0].CookedValue } catch { 0 }"';
        $cpuOutput = shell_exec($cpuCommand);
        
        if ($cpuOutput && is_numeric(trim($cpuOutput))) {
            $cpuLoad = floatval(trim($cpuOutput)) / 100;
        } else {
            // Method 2: Fallback to WMI
            $cpuCommand2 = 'powershell.exe -Command "Get-WmiObject -Class Win32_Processor | Measure-Object -Property LoadPercentage -Average | Select-Object Average"';
            $cpuOutput2 = shell_exec($cpuCommand2);
            
            if ($cpuOutput2 && preg_match('/Average\s*:\s*(\d+(?:\.\d+)?)/', $cpuOutput2, $matches)) {
                $cpuLoad = floatval($matches[1]) / 100;
            }
        }
        
        // Get system uptime using PowerShell
        $uptimeCommand = 'powershell.exe -Command "(Get-Date) - (Get-CimInstance -ClassName Win32_OperatingSystem).LastBootUpTime | Select-Object Days,Hours,Minutes"';
        $uptimeOutput = shell_exec($uptimeCommand);
        $uptime = "N/A";
        
        if ($uptimeOutput && preg_match('/Days\s*:\s*(\d+).*Hours\s*:\s*(\d+).*Minutes\s*:\s*(\d+)/', $uptimeOutput, $matches)) {
            $uptime = sprintf("%d days, %d:%02d", intval($matches[1]), intval($matches[2]), intval($matches[3]));
        }
        
        // Get memory info using PowerShell
        $memCommand = 'powershell.exe -Command "Get-CimInstance -ClassName Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory"';
        $memOutput = shell_exec($memCommand);
        
        $memTotal = 0;
        $memFree = 0;
        
        if ($memOutput) {
            if (preg_match('/TotalVisibleMemorySize\s*:\s*(\d+)/', $memOutput, $matches)) {
                $memTotal = intval($matches[1]); // Already in KB
            }
            if (preg_match('/FreePhysicalMemory\s*:\s*(\d+)/', $memOutput, $matches)) {
                $memFree = intval($matches[1]); // Already in KB
            }
        }
        
        $memUsed = $memTotal - $memFree;
        
        // Ensure we have valid memory values to prevent division by zero
        if ($memTotal <= 0) {
            $memTotal = 1; // Prevent division by zero
            $memFree = 0;
            $memUsed = 0;
        }
        
        return [
            'load_1min' => $cpuLoad,
            'load_5min' => $cpuLoad, // Windows doesn't have load averages, so we use current CPU
            'load_15min' => $cpuLoad,
            'uptime' => $uptime,
            'memory_total' => $memTotal,
            'memory_free' => $memFree,
            'memory_available' => $memFree,
            'memory_used' => $memUsed
        ];
        
    } catch (Exception $e) {
        // Fallback values - ensure memory_total is never zero to prevent division by zero
        return [
            'load_1min' => 0,
            'load_5min' => 0,
            'load_15min' => 0,
            'uptime' => 'N/A',
            'memory_total' => 1, // Minimum value to prevent division by zero
            'memory_free' => 0,
            'memory_available' => 0,
            'memory_used' => 0
        ];
    }
}

// Get top processes by CPU and Memory
function getTopProcesses($type = 'cpu', $limit = 5) {
    $processes = getSystemProcesses();
    
    if ($type === 'cpu') {
        usort($processes, function($a, $b) {
            return $b['cpu'] <=> $a['cpu'];
        });
    } else {
        usort($processes, function($a, $b) {
            return $b['mem'] <=> $a['mem'];
        });
    }
    
    return array_slice($processes, 0, $limit);
}

$filter = $_GET['filter'] ?? '';
$processes = getSystemProcesses($filter);
$systemLoad = getSystemLoad();
$topCpuProcesses = getTopProcesses('cpu');
$topMemProcesses = getTopProcesses('mem');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Monitor - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
    <meta http-equiv="refresh" content="30">
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-tasks"></i> Process Monitor</h1>
        
        <?= $message ?>
        
        <!-- System Overview -->
        <div class="card">
            <h3>System Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($systemLoad['load_1min'], 2) ?></div>
                    <div class="stat-label">Load (1min)</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($systemLoad['load_5min'], 2) ?></div>
                    <div class="stat-label">Load (5min)</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">
                        <?php 
                        $memoryPercent = ($systemLoad['memory_total'] > 0) 
                            ? number_format(($systemLoad['memory_used'] / $systemLoad['memory_total']) * 100, 1) 
                            : '0.0';
                        echo $memoryPercent;
                        ?>%
                    </div>
                    <div class="stat-label">Memory Usage</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count($processes) ?></div>
                    <div class="stat-label">Active Processes</div>
                </div>
            </div>
            <div class="uptime-info">
                <strong>System Uptime:</strong> <?= htmlspecialchars($systemLoad['uptime']) ?>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <!-- Top CPU Processes -->
            <div class="card">
                <h3>Top CPU Usage</h3>
                <div class="top-processes">
                    <?php foreach ($topCpuProcesses as $process): ?>
                    <div class="process-item">
                        <div class="process-info">
                            <div class="process-name"><?= htmlspecialchars(substr($process['command'], 0, 30)) ?></div>
                            <div class="process-details">PID: <?= $process['pid'] ?> | User: <?= htmlspecialchars($process['user']) ?></div>
                        </div>
                        <div class="process-usage">
                            <span class="cpu-usage"><?= $process['cpu'] ?>%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top Memory Processes -->
            <div class="card">
                <h3>Top Memory Usage</h3>
                <div class="top-processes">
                    <?php foreach ($topMemProcesses as $process): ?>
                    <div class="process-item">
                        <div class="process-info">
                            <div class="process-name"><?= htmlspecialchars(substr($process['command'], 0, 30)) ?></div>
                            <div class="process-details">PID: <?= $process['pid'] ?> | User: <?= htmlspecialchars($process['user']) ?></div>
                        </div>
                        <div class="process-usage">
                            <span class="mem-usage"><?= $process['mem'] ?>%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Process Filter and Actions -->
        <div class="card">
            <div class="process-controls">
                <form method="GET" class="filter-form">
                    <input type="text" name="filter" value="<?= htmlspecialchars($filter) ?>" placeholder="Filter by command or user..." class="form-control" style="width: 300px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="process-monitor.php" class="btn btn-secondary">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </a>
                </form>
            </div>
        </div>

        <!-- Process List -->
        <div class="card">
            <h3>Process List <?= $filter ? '(Filtered: ' . htmlspecialchars($filter) . ')' : '' ?></h3>
            <div class="table-container">
                <table class="table process-table">
                    <thead>
                        <tr>
                            <th>PID</th>
                            <th>User</th>
                            <th>CPU%</th>
                            <th>MEM%</th>
                            <th>VSZ</th>
                            <th>RSS</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Command</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($processes, 0, 50) as $process): ?>
                        <tr class="process-row">
                            <td><strong><?= htmlspecialchars($process['pid']) ?></strong></td>
                            <td><?= htmlspecialchars($process['user']) ?></td>
                            <td class="cpu-cell">
                                <span class="usage-badge cpu-<?= $process['cpu'] > 50 ? 'high' : ($process['cpu'] > 20 ? 'medium' : 'low') ?>">
                                    <?= $process['cpu'] ?>%
                                </span>
                            </td>
                            <td class="mem-cell">
                                <span class="usage-badge mem-<?= $process['mem'] > 10 ? 'high' : ($process['mem'] > 5 ? 'medium' : 'low') ?>">
                                    <?= $process['mem'] ?>%
                                </span>
                            </td>
                            <td><?= number_format($process['vsz']) ?>K</td>
                            <td><?= number_format($process['rss']) ?>K</td>
                            <td>
                                <span class="status-badge status-<?= strtolower(substr($process['stat'], 0, 1)) ?>">
                                    <?= htmlspecialchars($process['stat']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($process['time']) ?></td>
                            <td class="command-cell">
                                <code title="<?= htmlspecialchars($process['command']) ?>">
                                    <?= htmlspecialchars(substr($process['command'], 0, 50)) ?><?= strlen($process['command']) > 50 ? '...' : '' ?>
                                </code>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-warning btn-sm" onclick="showKillDialog(<?= $process['pid'] ?>, '<?= htmlspecialchars($process['command']) ?>')" title="Send Signal">
                                        <i class="fas fa-stop-circle"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (count($processes) > 50): ?>
            <div class="pagination-info">
                Showing first 50 of <?= count($processes) ?> processes. Use filter to narrow results.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Kill Process Modal -->
    <div id="killModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeKillDialog()">&times;</span>
            <h3>Send Signal to Process</h3>
            <form method="POST" id="killForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="pid" id="killPid">
                
                <div class="form-group">
                    <label>Process</label>
                    <div id="processInfo" class="process-info-display"></div>
                </div>
                
                <div class="form-group">
                    <label>Termination Method</label>
                    <select name="signal" class="form-control" required>
                        <option value="TERM">TERM (Graceful termination)</option>
                        <option value="KILL">KILL (Force termination)</option>
                    </select>
                    <small class="form-text text-muted">Note: On Windows, only graceful and force termination are supported.</small>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="kill_process" class="btn btn-danger">
                        <i class="fas fa-stop-circle"></i> Send Signal
                    </button>
                    <button type="button" onclick="closeKillDialog()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
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
        
        .uptime-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            color: var(--text-muted);
        }
        
        .top-processes {
            space-y: 10px;
        }
        
        .process-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .process-name {
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .process-details {
            font-size: 0.8em;
            color: var(--text-muted);
        }
        
        .cpu-usage, .mem-usage {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            background: var(--primary-color);
            color: white;
        }
        
        .process-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .process-table {
            font-size: 0.9em;
        }
        
        .process-row:hover {
            background-color: var(--hover-bg);
        }
        
        .usage-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .cpu-high, .mem-high { background: #dc3545; color: white; }
        .cpu-medium, .mem-medium { background: #ffc107; color: black; }
        .cpu-low, .mem-low { background: #28a745; color: white; }
        
        .status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
        }
        
        .status-r { background: #28a745; color: white; } /* Running */
        .status-s { background: #6c757d; color: white; } /* Sleeping */
        .status-d { background: #dc3545; color: white; } /* Uninterruptible sleep */
        .status-z { background: #ffc107; color: black; } /* Zombie */
        .status-t { background: #17a2b8; color: white; } /* Stopped */
        
        .command-cell {
            max-width: 300px;
        }
        
        .command-cell code {
            font-size: 0.8em;
            background: none;
            padding: 0;
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
            max-width: 500px;
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
        
        .process-info-display {
            padding: 10px;
            background: var(--code-bg);
            border-radius: 4px;
            font-family: monospace;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .pagination-info {
            text-align: center;
            margin-top: 15px;
            color: var(--text-muted);
        }
    </style>

    <script>
        function showKillDialog(pid, command) {
            document.getElementById('killPid').value = pid;
            document.getElementById('processInfo').textContent = `PID: ${pid} - ${command}`;
            document.getElementById('killModal').style.display = 'block';
        }

        function closeKillDialog() {
            document.getElementById('killModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('killModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Auto-refresh notification
        let refreshCounter = 30;
        setInterval(function() {
            refreshCounter--;
            if (refreshCounter <= 0) {
                refreshCounter = 30;
            }
        }, 1000);
    </script>
</body>
</html>