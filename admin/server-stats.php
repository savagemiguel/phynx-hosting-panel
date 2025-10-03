<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

// Get comprehensive server statistics
$systemStats = getSystemStats();
$cpuUsage = getCPUUsage();
$apacheStatus = getApacheStatus();
$mysqlStatus = getMySQLStatus();
$bindStatus = getBindStatus();

// Get additional monitoring data
function getServiceActiveStatus($service) {
    $output = shell_exec("systemctl is-active $service 2>&1");
    return trim($output) === 'active';
}

function getServiceUptime($service) {
    $output = shell_exec("systemctl show -p ActiveEnterTimestamp $service 2>&1");
    if (preg_match('/ActiveEnterTimestamp=(.+)/', $output, $matches)) {
        $startTime = strtotime($matches[1]);
        if ($startTime) {
            return time() - $startTime;
        }
    }
    return 0;
}

function getNetworkStats() {
    $stats = [];
    $output = shell_exec("cat /proc/net/dev 2>/dev/null | tail -n +3");
    if ($output) {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 10) {
                $interface = trim($parts[0], ':');
                $stats[$interface] = [
                    'rx_bytes' => (int)$parts[1],
                    'tx_bytes' => (int)$parts[9]
                ];
            }
        }
    }
    return $stats;
}

function getTopProcesses() {
    $output = shell_exec("ps aux --sort=-%cpu | head -11 2>/dev/null");
    $processes = [];
    if ($output) {
        $lines = explode("\n", trim($output));
        array_shift($lines); // Remove header
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) >= 11) {
                $processes[] = [
                    'user' => $parts[0],
                    'pid' => $parts[1],
                    'cpu' => $parts[2],
                    'mem' => $parts[3],
                    'command' => $parts[10]
                ];
            }
        }
    }
    return array_slice($processes, 0, 10);
}

$networkStats = getNetworkStats();
$topProcesses = getTopProcesses();

// Service monitoring
$services = [
    'apache2' => 'Apache Web Server',
    'mysql' => 'MySQL Database',
    'bind9' => 'BIND DNS Server',
    'ssh' => 'SSH Server', 
    'fail2ban' => 'Fail2Ban',
    'ufw' => 'UFW Firewall'
];

$serviceStatuses = [];
foreach ($services as $service => $name) {
    $serviceStatuses[$service] = [
        'name' => $name,
        'active' => getServiceActiveStatus($service),
        'uptime' => getServiceUptime($service)
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Server Monitoring - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .monitoring-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
        }
        
        .metric-value {
            font-size: 2.5em;
            font-weight: bold;
            color: var(--primary-color);
            margin: 10px 0;
        }
        
        .metric-label {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-online {
            background-color: var(--success-color);
        }
        
        .status-offline {
            background-color: var(--error-color);
        }
        
        .service-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        
        .service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
        }
        
        .service-info {
            display: flex;
            align-items: center;
        }
        
        .service-uptime {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .process-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .process-table th,
        .process-table td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .process-table th {
            background: var(--card-bg);
            font-weight: 600;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 20px;
        }
        
        .refresh-info {
            text-align: center;
            color: var(--text-muted);
            margin: 20px 0;
        }
        
        .alert-critical {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--warning-color);
            color: var(--warning-color);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="content-header">
            <h1><i class="fas fa-chart-line"></i> Server Monitoring</h1>
        </div>
        
        <!-- Critical Alerts -->
        <?php if ($systemStats['memory']['percent'] > 90): ?>
        <div class="alert-critical">
            <i class="fas fa-exclamation-triangle"></i> Critical: Memory usage is at <?= round($systemStats['memory']['percent'], 1) ?>%
        </div>
        <?php elseif ($systemStats['memory']['percent'] > 80): ?>
        <div class="alert-warning">
            <i class="fas fa-exclamation-triangle"></i> Warning: Memory usage is at <?= round($systemStats['memory']['percent'], 1) ?>%
        </div>
        <?php endif; ?>
        
        <?php if ($systemStats['disk']['percent'] > 90): ?>
        <div class="alert-critical">
            <i class="fas fa-exclamation-triangle"></i> Critical: Disk usage is at <?= round($systemStats['disk']['percent'], 1) ?>%
        </div>
        <?php elseif ($systemStats['disk']['percent'] > 80): ?>
        <div class="alert-warning">
            <i class="fas fa-exclamation-triangle"></i> Warning: Disk usage is at <?= round($systemStats['disk']['percent'], 1) ?>%
        </div>
        <?php endif; ?>
        
        <!-- Key Metrics -->
        <div class="monitoring-grid">
            <div class="metric-card">
                <div class="metric-value"><?= round($systemStats['memory']['percent'], 1) ?>%</div>
                <div class="metric-label">
                    <i class="fas fa-memory"></i> Memory Usage<br>
                    <?= formatBytes($systemStats['memory']['used']) ?> / <?= formatBytes($systemStats['memory']['total']) ?>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?= round($systemStats['disk']['percent'], 1) ?>%</div>
                <div class="metric-label">
                    <i class="fas fa-hdd"></i> Disk Usage<br>
                    <?= formatBytes($systemStats['disk']['used']) ?> / <?= formatBytes($systemStats['disk']['total']) ?>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?= $systemStats['cpu']['load_1min'] ?></div>
                <div class="metric-label">
                    <i class="fas fa-microchip"></i> CPU Load (1min)<br>
                    5min: <?= $systemStats['cpu']['load_5min'] ?> | 15min: <?= $systemStats['cpu']['load_15min'] ?>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?= $systemStats['uptime']['formatted'] ?></div>
                <div class="metric-label">
                    <i class="fas fa-clock"></i> System Uptime<br>
                    <?= $systemStats['processes'] ?> processes running
                </div>
            </div>
        </div>
        
        <!-- Service Status -->
        <div class="card">
            <h3><i class="fas fa-cogs"></i> Service Status</h3>
            <div class="service-list">
                <?php foreach ($serviceStatuses as $service => $status): ?>
                <div class="service-item">
                    <div class="service-info">
                        <span class="status-indicator <?= $status['active'] ? 'status-online' : 'status-offline' ?>"></span>
                        <div>
                            <strong><?= htmlspecialchars($status['name']) ?></strong>
                            <div class="service-uptime">
                                <?php if ($status['active'] && $status['uptime'] > 0): ?>
                                    Uptime: <?= gmdate('H:i:s', $status['uptime']) ?>
                                <?php else: ?>
                                    Not running
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <?php if ($status['active']): ?>
                            <span style="color: var(--success-color);">
                                <i class="fas fa-check-circle"></i> Active
                            </span>
                        <?php else: ?>
                            <span style="color: var(--error-color);">
                                <i class="fas fa-times-circle"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Network Statistics -->
        <?php if (!empty($networkStats)): ?>
        <div class="card">
            <h3><i class="fas fa-network-wired"></i> Network Interfaces</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <?php foreach ($networkStats as $interface => $stats): ?>
                <?php if ($interface !== 'lo' && ($stats['rx_bytes'] > 0 || $stats['tx_bytes'] > 0)): ?>
                <div style="background: var(--card-bg); padding: 16px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h4 style="margin: 0 0 8px 0;"><?= htmlspecialchars($interface) ?></h4>
                    <div style="font-size: 12px; color: var(--text-secondary);">
                        RX: <?= formatBytes($stats['rx_bytes']) ?><br>
                        TX: <?= formatBytes($stats['tx_bytes']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Top Processes -->
        <?php if (!empty($topProcesses)): ?>
        <div class="card">
            <h3><i class="fas fa-tasks"></i> Top Processes (by CPU)</h3>
            <div style="overflow-x: auto;">
                <table class="process-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>PID</th>
                            <th>CPU %</th>
                            <th>Memory %</th>
                            <th>Command</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProcesses as $process): ?>
                        <tr>
                            <td><?= htmlspecialchars($process['user']) ?></td>
                            <td><?= htmlspecialchars($process['pid']) ?></td>
                            <td><?= htmlspecialchars($process['cpu']) ?>%</td>
                            <td><?= htmlspecialchars($process['mem']) ?>%</td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?= htmlspecialchars(substr($process['command'], 0, 60)) ?>
                                <?= strlen($process['command']) > 60 ? '...' : '' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resource Usage Chart -->
        <div class="card">
            <h3><i class="fas fa-chart-area"></i> Resource Usage Trends</h3>
            <div class="chart-container">
                <canvas id="resourceChart"></canvas>
            </div>
        </div>
        
        <div class="refresh-info">
            <i class="fas fa-sync-alt"></i> Page automatically refreshes every 30 seconds for real-time monitoring
        </div>
    </div>
    
    <script>
        // Resource Usage Chart
        const ctx = document.getElementById('resourceChart').getContext('2d');
        
        // Simulate historical data (in a real implementation, this would come from a database)
        const timeLabels = [];
        const cpuData = [];
        const memoryData = [];
        const diskData = [];
        
        for (let i = 23; i >= 0; i--) {
            const time = new Date();
            time.setMinutes(time.getMinutes() - i * 5);
            timeLabels.push(time.toTimeString().substr(0, 5));
            
            // Simulate data around current values
            const currentCpu = <?= $systemStats['cpu']['load_1min'] * 10 ?>;
            const currentMem = <?= $systemStats['memory']['percent'] ?>;
            const currentDisk = <?= $systemStats['disk']['percent'] ?>;
            
            cpuData.push(Math.max(0, currentCpu + (Math.random() - 0.5) * 20));
            memoryData.push(Math.max(0, currentMem + (Math.random() - 0.5) * 10));
            diskData.push(Math.max(0, currentDisk + (Math.random() - 0.5) * 5));
        }
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: timeLabels,
                datasets: [{
                    label: 'CPU Load %',
                    data: cpuData,
                    borderColor: '#E3FC02',
                    backgroundColor: 'rgba(227, 252, 2, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Memory %',
                    data: memoryData,
                    borderColor: '#ff6b6b',
                    backgroundColor: 'rgba(255, 107, 107, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Disk %',
                    data: diskData,
                    borderColor: '#4ecdc4',
                    backgroundColor: 'rgba(78, 205, 196, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#ffffff'
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#b8b8b8'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y: {
                        ticks: {
                            color: '#b8b8b8'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Auto-refresh page every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>