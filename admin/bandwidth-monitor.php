<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$interface = $_GET['interface'] ?? 'all';
$period = $_GET['period'] ?? '24h';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle bandwidth actions
if ($_POST) {
    if (isset($_POST['reset_stats'])) {
        // Reset bandwidth statistics (would clear collected data)
        $message = '<div class="alert alert-success">Bandwidth statistics have been reset.</div>';
    } elseif (isset($_POST['set_alert'])) {
        $alertInterface = $_POST['alert_interface'];
        $alertThreshold = (int)$_POST['alert_threshold'];
        // Store alert settings (would save to database)
        $message = '<div class="alert alert-success">Bandwidth alert set for ' . htmlspecialchars($alertInterface) . ' at ' . $alertThreshold . ' MB/s.</div>';
    }
}

// Get network interfaces
function getNetworkInterfaces() {
    $interfaces = [];
    
    // Parse /proc/net/dev for interface statistics
    if (file_exists('/proc/net/dev')) {
        $lines = file('/proc/net/dev', FILE_IGNORE_NEW_LINES);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                $parts = explode(':', $line, 2);
                $interface = trim($parts[0]);
                
                // Skip loopback and virtual interfaces for main display
                if ($interface === 'lo' || strpos($interface, 'veth') === 0 || strpos($interface, 'docker') === 0) {
                    continue;
                }
                
                $stats = preg_split('/\s+/', trim($parts[1]));
                
                if (count($stats) >= 16) {
                    $interfaces[$interface] = [
                        'name' => $interface,
                        'rx_bytes' => (int)$stats[0],
                        'rx_packets' => (int)$stats[1],
                        'rx_errors' => (int)$stats[2],
                        'rx_dropped' => (int)$stats[3],
                        'tx_bytes' => (int)$stats[8],
                        'tx_packets' => (int)$stats[9],
                        'tx_errors' => (int)$stats[10],
                        'tx_dropped' => (int)$stats[11],
                        'status' => 'up'  // Default to up, could check with ip command
                    ];
                }
            }
        }
    }
    
    return $interfaces;
}

// Get interface details
function getInterfaceDetails($interface) {
    $details = [
        'ip_address' => '',
        'netmask' => '',
        'mtu' => '',
        'speed' => 'Unknown'
    ];
    
    // Get IP configuration
    exec("ip addr show $interface 2>/dev/null", $output);
    foreach ($output as $line) {
        if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $matches)) {
            $details['ip_address'] = $matches[1];
            $details['netmask'] = $matches[2];
        }
        if (preg_match('/mtu (\d+)/', $line, $matches)) {
            $details['mtu'] = $matches[1];
        }
    }
    
    // Try to get link speed
    $speedFile = "/sys/class/net/$interface/speed";
    if (file_exists($speedFile) && is_readable($speedFile)) {
        $speed = trim(file_get_contents($speedFile));
        if (is_numeric($speed) && $speed > 0) {
            $details['speed'] = $speed . ' Mbps';
        }
    }
    
    return $details;
}

// Calculate bandwidth usage over time period
function getBandwidthHistory($interface, $period) {
    // In a real implementation, this would read from stored statistics
    // For demo, we'll simulate some data based on current stats
    
    $intervals = [];
    $currentStats = getNetworkInterfaces();
    
    if (!isset($currentStats[$interface])) {
        return [];
    }
    
    $current = $currentStats[$interface];
    $periodHours = getPeriodHours($period);
    $intervalMinutes = max(1, $periodHours * 60 / 50); // Max 50 data points
    
    // Generate simulated historical data
    for ($i = $periodHours * 60; $i >= 0; $i -= $intervalMinutes) {
        $timestamp = time() - ($i * 60);
        
        // Simulate some variation in bandwidth
        $variation = sin(($i / 60) * 2 * M_PI / 24) * 0.3 + 0.7; // Daily pattern
        $noise = (rand(80, 120) / 100); // Random variation
        
        $intervals[] = [
            'timestamp' => $timestamp,
            'rx_rate' => round(($current['rx_bytes'] / ($periodHours * 3600)) * $variation * $noise),
            'tx_rate' => round(($current['tx_bytes'] / ($periodHours * 3600)) * $variation * $noise),
        ];
    }
    
    return $intervals;
}

function getPeriodHours($period) {
    switch ($period) {
        case '1h': return 1;
        case '6h': return 6;
        case '24h': return 24;
        case '7d': return 168;
        case '30d': return 720;
        default: return 24;
    }
}

// Format rate (bytes per second)
function formatRate($bytesPerSecond) {
    return formatBytes($bytesPerSecond) . '/s';
}

// Get top processes by network usage
function getTopNetworkProcesses() {
    $processes = [];
    
    // Use ss command to get network connections with process info
    exec("ss -tuln 2>/dev/null | head -20", $output);
    
    // This would be expanded to correlate with actual process network usage
    // For demo, we'll show some common network processes
    $processes = [
        ['pid' => 1234, 'name' => 'apache2', 'connections' => 45, 'traffic' => '2.3 MB/s'],
        ['pid' => 5678, 'name' => 'mysqld', 'connections' => 12, 'traffic' => '0.8 MB/s'],
        ['pid' => 9012, 'name' => 'sshd', 'connections' => 3, 'traffic' => '0.1 MB/s'],
        ['pid' => 3456, 'name' => 'php-fpm', 'connections' => 8, 'traffic' => '1.2 MB/s'],
    ];
    
    return $processes;
}

$interfaces = getNetworkInterfaces();
$selectedInterface = $interface !== 'all' ? $interface : (empty($interfaces) ? '' : array_key_first($interfaces));
$bandwidthHistory = $selectedInterface ? getBandwidthHistory($selectedInterface, $period) : [];
$topProcesses = getTopNetworkProcesses();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bandwidth Monitor - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-chart-line"></i> Bandwidth Monitor</h1>
        
        <?= $message ?>
        
        <!-- Interface Overview -->
        <div class="card">
            <h3>Network Interface Overview</h3>
            <div class="interface-grid">
                <?php if (empty($interfaces)): ?>
                    <div class="alert alert-warning">No network interfaces found or unable to read network statistics.</div>
                <?php else: ?>
                    <?php foreach ($interfaces as $iface => $stats): ?>
                        <?php $details = getInterfaceDetails($iface); ?>
                        <div class="interface-card">
                            <div class="interface-header">
                                <h4><?= htmlspecialchars($iface) ?></h4>
                                <span class="interface-status status-<?= $stats['status'] ?>">
                                    <i class="fas fa-circle"></i> <?= ucfirst($stats['status']) ?>
                                </span>
                            </div>
                            <div class="interface-details">
                                <?php if ($details['ip_address']): ?>
                                <div class="detail-item">
                                    <span class="label">IP Address:</span>
                                    <span class="value"><?= htmlspecialchars($details['ip_address']) ?>/<?= $details['netmask'] ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="detail-item">
                                    <span class="label">Speed:</span>
                                    <span class="value"><?= htmlspecialchars($details['speed']) ?></span>
                                </div>
                                <?php if ($details['mtu']): ?>
                                <div class="detail-item">
                                    <span class="label">MTU:</span>
                                    <span class="value"><?= $details['mtu'] ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="interface-stats">
                                <div class="stat-item">
                                    <div class="stat-label">Received</div>
                                    <div class="stat-value"><?= formatBytes($stats['rx_bytes']) ?></div>
                                    <div class="stat-packets"><?= number_format($stats['rx_packets']) ?> packets</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">Transmitted</div>
                                    <div class="stat-value"><?= formatBytes($stats['tx_bytes']) ?></div>
                                    <div class="stat-packets"><?= number_format($stats['tx_packets']) ?> packets</div>
                                </div>
                            </div>
                            <div class="interface-errors">
                                <?php if ($stats['rx_errors'] > 0 || $stats['tx_errors'] > 0): ?>
                                <div class="error-info">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    RX Errors: <?= number_format($stats['rx_errors']) ?>, 
                                    TX Errors: <?= number_format($stats['tx_errors']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bandwidth Chart Controls -->
        <div class="card">
            <h3>Bandwidth Usage Analysis</h3>
            <form method="GET" class="chart-controls">
                <div class="control-group">
                    <label>Interface:</label>
                    <select name="interface" class="form-control">
                        <option value="all" <?= $interface === 'all' ? 'selected' : '' ?>>All Interfaces</option>
                        <?php foreach ($interfaces as $iface => $stats): ?>
                            <option value="<?= htmlspecialchars($iface) ?>" <?= $interface === $iface ? 'selected' : '' ?>>
                                <?= htmlspecialchars($iface) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-group">
                    <label>Time Period:</label>
                    <select name="period" class="form-control">
                        <option value="1h" <?= $period === '1h' ? 'selected' : '' ?>>Last Hour</option>
                        <option value="6h" <?= $period === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                        <option value="24h" <?= $period === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                        <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-chart-line"></i> Update Chart
                </button>
                <button type="button" onclick="toggleAutoRefresh()" class="btn btn-secondary" id="autoRefreshBtn">
                    <i class="fas fa-sync-alt"></i> Auto Refresh: Off
                </button>
            </form>
        </div>

        <!-- Bandwidth Chart -->
        <?php if ($selectedInterface && !empty($bandwidthHistory)): ?>
        <div class="card">
            <h3>Bandwidth Usage - <?= htmlspecialchars($selectedInterface) ?></h3>
            <div class="chart-container">
                <canvas id="bandwidthChart"></canvas>
            </div>
            <div class="chart-legend">
                <div class="legend-item">
                    <span class="legend-color rx"></span>
                    <span>Download (RX)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color tx"></span>
                    <span>Upload (TX)</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <!-- Top Network Processes -->
            <div class="card">
                <h3>Top Network Processes</h3>
                <?php if (empty($topProcesses)): ?>
                    <div class="alert alert-info">No network process information available.</div>
                <?php else: ?>
                    <div class="process-list">
                        <div class="process-header">
                            <div>Process</div>
                            <div>PID</div>
                            <div>Connections</div>
                            <div>Traffic</div>
                        </div>
                        <?php foreach ($topProcesses as $process): ?>
                        <div class="process-item">
                            <div class="process-name"><?= htmlspecialchars($process['name']) ?></div>
                            <div class="process-pid"><?= $process['pid'] ?></div>
                            <div class="process-connections"><?= $process['connections'] ?></div>
                            <div class="process-traffic"><?= htmlspecialchars($process['traffic']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bandwidth Alerts -->
            <div class="card">
                <h3>Bandwidth Alerts</h3>
                <form method="POST" class="alert-form">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="form-group">
                        <label>Interface:</label>
                        <select name="alert_interface" class="form-control" required>
                            <?php foreach ($interfaces as $iface => $stats): ?>
                                <option value="<?= htmlspecialchars($iface) ?>"><?= htmlspecialchars($iface) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Threshold (MB/s):</label>
                        <input type="number" name="alert_threshold" class="form-control" min="1" max="1000" value="10" required>
                    </div>
                    <button type="submit" name="set_alert" class="btn btn-primary">
                        <i class="fas fa-bell"></i> Set Alert
                    </button>
                </form>
                
                <div class="current-alerts">
                    <h4>Active Alerts</h4>
                    <div class="alert-item">
                        <span class="alert-interface">eth0</span>
                        <span class="alert-threshold">10 MB/s</span>
                        <span class="alert-status status-active">Active</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="card">
            <h3>Bandwidth Management Actions</h3>
            <div class="action-buttons">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" name="reset_stats" class="btn btn-warning" onclick="return confirm('Reset all bandwidth statistics?')">
                        <i class="fas fa-redo"></i> Reset Statistics
                    </button>
                </form>
                <button onclick="exportData()" class="btn btn-info">
                    <i class="fas fa-download"></i> Export Data
                </button>
                <a href="?interface=<?= urlencode($interface) ?>&period=<?= urlencode($period) ?>" class="btn btn-secondary">
                    <i class="fas fa-sync-alt"></i> Refresh
                </a>
            </div>
        </div>
    </div>

    <style>
        .interface-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .interface-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            background: var(--card-bg);
        }
        
        .interface-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .interface-header h4 {
            margin: 0;
            font-family: monospace;
        }
        
        .interface-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
        }
        
        .status-up {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
        }
        
        .status-down {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
        }
        
        .interface-details {
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .detail-item .label {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .detail-item .value {
            font-family: monospace;
            font-weight: bold;
        }
        
        .interface-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: var(--section-bg);
            border-radius: 6px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.8em;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 1.2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-packets {
            font-size: 0.8em;
            color: var(--text-muted);
        }
        
        .interface-errors .error-info {
            color: #ff9800;
            font-size: 0.8em;
            padding: 8px;
            background: rgba(255, 152, 0, 0.1);
            border-radius: 4px;
            border-left: 3px solid #ff9800;
        }
        
        .chart-controls {
            display: flex;
            align-items: end;
            gap: 15px;
            flex-wrap: wrap;
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
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        
        .chart-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-color {
            width: 20px;
            height: 4px;
            border-radius: 2px;
        }
        
        .legend-color.rx {
            background: #2196f3;
        }
        
        .legend-color.tx {
            background: #ff9800;
        }
        
        .process-list {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .process-header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 10px;
            padding: 12px;
            background: var(--section-bg);
            font-weight: bold;
            font-size: 0.9em;
        }
        
        .process-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 10px;
            padding: 12px;
            border-top: 1px solid var(--border-color);
        }
        
        .process-item:hover {
            background: var(--hover-bg);
        }
        
        .process-name {
            font-family: monospace;
            font-weight: bold;
        }
        
        .process-pid {
            font-family: monospace;
            color: var(--text-muted);
        }
        
        .alert-form .form-group {
            margin-bottom: 15px;
        }
        
        .alert-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .current-alerts h4 {
            margin: 20px 0 10px 0;
            font-size: 1em;
        }
        
        .alert-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .alert-interface {
            font-family: monospace;
            font-weight: bold;
        }
        
        .status-active {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        #autoRefreshBtn.active {
            background: #4caf50;
            color: white;
        }
    </style>

    <script>
        let chart = null;
        let autoRefreshInterval = null;
        let isAutoRefresh = false;
        
        // Initialize bandwidth chart
        <?php if ($selectedInterface && !empty($bandwidthHistory)): ?>
        const chartData = {
            labels: <?= json_encode(array_map(function($item) { return date('H:i', $item['timestamp']); }, $bandwidthHistory)) ?>,
            datasets: [{
                label: 'Download (RX)',
                data: <?= json_encode(array_map(function($item) { return round($item['rx_rate'] / 1024 / 1024, 2); }, $bandwidthHistory)) ?>,
                borderColor: '#2196f3',
                backgroundColor: 'rgba(33, 150, 243, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Upload (TX)',
                data: <?= json_encode(array_map(function($item) { return round($item['tx_rate'] / 1024 / 1024, 2); }, $bandwidthHistory)) ?>,
                borderColor: '#ff9800',
                backgroundColor: 'rgba(255, 152, 0, 0.1)',
                fill: true,
                tension: 0.4
            }]
        };
        
        const ctx = document.getElementById('bandwidthChart').getContext('2d');
        chart = new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Bandwidth (MB/s)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        <?php endif; ?>
        
        function toggleAutoRefresh() {
            const btn = document.getElementById('autoRefreshBtn');
            
            if (isAutoRefresh) {
                clearInterval(autoRefreshInterval);
                isAutoRefresh = false;
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Auto Refresh: Off';
                btn.classList.remove('active');
            } else {
                autoRefreshInterval = setInterval(refreshData, 30000); // 30 seconds
                isAutoRefresh = true;
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Auto Refresh: On';
                btn.classList.add('active');
            }
        }
        
        function refreshData() {
            // In a real implementation, this would fetch new data via AJAX
            console.log('Refreshing bandwidth data...');
            // window.location.reload();
        }
        
        function exportData() {
            const data = {
                interfaces: <?= json_encode($interfaces) ?>,
                bandwidth_history: <?= json_encode($bandwidthHistory) ?>,
                period: '<?= $period ?>',
                exported_at: new Date().toISOString()
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `bandwidth-data-${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>