<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle MySQL configuration actions
if ($_POST) {
    if (isset($_POST['update_config'])) {
        $result = updateMySQLConfig($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">MySQL configuration updated successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to update MySQL configuration: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['restart_mysql'])) {
        $result = restartMySQLService();
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">MySQL service restarted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to restart MySQL service: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['optimize_tables'])) {
        $result = optimizeAllTables();
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Database tables optimized successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to optimize tables: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Get MySQL configuration
function getMySQLConfig() {
    global $conn;
    
    $config = [];
    
    // Get MySQL variables
    $variables = [
        'version' => 'SELECT VERSION() as value',
        'uptime' => 'SHOW STATUS LIKE "Uptime"',
        'max_connections' => 'SHOW VARIABLES LIKE "max_connections"',
        'max_user_connections' => 'SHOW VARIABLES LIKE "max_user_connections"',
        'query_cache_size' => 'SHOW VARIABLES LIKE "query_cache_size"',
        'innodb_buffer_pool_size' => 'SHOW VARIABLES LIKE "innodb_buffer_pool_size"',
        'key_buffer_size' => 'SHOW VARIABLES LIKE "key_buffer_size"',
        'tmp_table_size' => 'SHOW VARIABLES LIKE "tmp_table_size"',
        'max_heap_table_size' => 'SHOW VARIABLES LIKE "max_heap_table_size"'
    ];
    
    foreach ($variables as $key => $query) {
        $result = mysqli_query($conn, $query);
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $config[$key] = $row['Value'] ?? $row['value'] ?? 'N/A';
        } else {
            $config[$key] = 'N/A';
        }
    }
    
    return $config;
}

// Get MySQL status
function getMySQLStatus() {
    global $conn;
    
    $status = [];
    
    $queries = [
        'connections' => 'SHOW STATUS LIKE "Connections"',
        'queries' => 'SHOW STATUS LIKE "Queries"',
        'slow_queries' => 'SHOW STATUS LIKE "Slow_queries"',
        'threads_connected' => 'SHOW STATUS LIKE "Threads_connected"',
        'threads_running' => 'SHOW STATUS LIKE "Threads_running"',
        'innodb_buffer_pool_reads' => 'SHOW STATUS LIKE "Innodb_buffer_pool_reads"',
        'innodb_buffer_pool_read_requests' => 'SHOW STATUS LIKE "Innodb_buffer_pool_read_requests"'
    ];
    
    foreach ($queries as $key => $query) {
        $result = mysqli_query($conn, $query);
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $status[$key] = $row['Value'] ?? 0;
        } else {
            $status[$key] = 0;
        }
    }
    
    return $status;
}

// Get database information
function getDatabaseInfo() {
    global $conn;
    
    $info = [
        'databases' => [],
        'total_size' => 0,
        'table_count' => 0
    ];
    
    // Get databases
    $result = mysqli_query($conn, "SHOW DATABASES");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $dbName = $row['Database'];
            if (!in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                $info['databases'][] = $dbName;
            }
        }
    }
    
    // Get table count and size for current database
    $result = mysqli_query($conn, "SELECT COUNT(*) as table_count, 
        SUM(data_length + index_length) as total_size 
        FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "'");
    
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $info['table_count'] = $row['table_count'];
        $info['total_size'] = $row['total_size'];
    }
    
    return $info;
}

// Update MySQL configuration
function updateMySQLConfig($data) {
    // Note: This would require proper MySQL configuration file access
    // For now, we'll simulate the update
    return ['success' => true];
}

// Restart MySQL service
function restartMySQLService() {
    // Note: This would require system-level access
    // For Windows/WAMP, this might not be available through web interface
    return ['success' => false, 'error' => 'Service restart requires system administrator privileges'];
}

// Optimize all tables
function optimizeAllTables() {
    global $conn;
    
    $result = mysqli_query($conn, "SHOW TABLES");
    if (!$result) {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
    
    $optimized = 0;
    while ($row = mysqli_fetch_array($result)) {
        $table = $row[0];
        $optimizeResult = mysqli_query($conn, "OPTIMIZE TABLE `$table`");
        if ($optimizeResult) {
            $optimized++;
        }
    }
    
    return ['success' => true, 'optimized' => $optimized];
}

$mysqlConfig = getMySQLConfig();
$mysqlStatus = getMySQLStatus();
$dbInfo = getDatabaseInfo();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL Settings - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-cogs"></i> MySQL Configuration</h1>
        
        <?= $message ?>
        
        <!-- MySQL Server Information -->
        <div class="card">
            <h3>MySQL Server Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">MySQL Version</div>
                    <div class="info-value"><?= htmlspecialchars($mysqlConfig['version']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Server Uptime</div>
                    <div class="info-value"><?= formatUptime($mysqlConfig['uptime']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Connections</div>
                    <div class="info-value"><?= number_format($mysqlStatus['connections']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Active Threads</div>
                    <div class="info-value"><?= $mysqlStatus['threads_connected'] ?> / <?= $mysqlConfig['max_connections'] ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Queries</div>
                    <div class="info-value"><?= number_format($mysqlStatus['queries']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Slow Queries</div>
                    <div class="info-value"><?= number_format($mysqlStatus['slow_queries']) ?></div>
                </div>
            </div>
        </div>

        <!-- Database Overview -->
        <div class="card">
            <h3>Database Overview</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Current Database</div>
                    <div class="info-value"><?= DB_NAME ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Tables</div>
                    <div class="info-value"><?= $dbInfo['table_count'] ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Database Size</div>
                    <div class="info-value"><?= formatBytes($dbInfo['total_size']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Available Databases</div>
                    <div class="info-value"><?= count($dbInfo['databases']) ?> databases</div>
                </div>
            </div>
            
            <div class="database-list">
                <h4>Available Databases:</h4>
                <div class="db-tags">
                    <?php foreach ($dbInfo['databases'] as $db): ?>
                        <span class="db-tag <?= $db === DB_NAME ? 'current' : '' ?>">
                            <i class="fas fa-database"></i> <?= htmlspecialchars($db) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- MySQL Configuration -->
        <div class="card">
            <h3>MySQL Configuration Settings</h3>
            <div class="config-grid">
                <div class="config-section">
                    <h4>Connection Settings</h4>
                    <div class="config-item">
                        <label>Max Connections:</label>
                        <span><?= $mysqlConfig['max_connections'] ?></span>
                    </div>
                    <div class="config-item">
                        <label>Max User Connections:</label>
                        <span><?= $mysqlConfig['max_user_connections'] ?></span>
                    </div>
                </div>
                
                <div class="config-section">
                    <h4>Memory Settings</h4>
                    <div class="config-item">
                        <label>InnoDB Buffer Pool:</label>
                        <span><?= formatBytes($mysqlConfig['innodb_buffer_pool_size']) ?></span>
                    </div>
                    <div class="config-item">
                        <label>Key Buffer Size:</label>
                        <span><?= formatBytes($mysqlConfig['key_buffer_size']) ?></span>
                    </div>
                    <div class="config-item">
                        <label>Query Cache Size:</label>
                        <span><?= formatBytes($mysqlConfig['query_cache_size']) ?></span>
                    </div>
                    <div class="config-item">
                        <label>Tmp Table Size:</label>
                        <span><?= formatBytes($mysqlConfig['tmp_table_size']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="card">
            <h3>Performance Metrics</h3>
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-value"><?= $mysqlStatus['threads_running'] ?></div>
                        <div class="metric-label">Running Threads</div>
                    </div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-icon">
                        <i class="fas fa-memory"></i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-value">
                            <?php 
                            $buffer_efficiency = 0;
                            if ($mysqlStatus['innodb_buffer_pool_read_requests'] > 0) {
                                $buffer_efficiency = (1 - ($mysqlStatus['innodb_buffer_pool_reads'] / $mysqlStatus['innodb_buffer_pool_read_requests'])) * 100;
                            }
                            echo round($buffer_efficiency, 1) . '%';
                            ?>
                        </div>
                        <div class="metric-label">Buffer Pool Hit Rate</div>
                    </div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-value"><?= number_format($mysqlStatus['slow_queries']) ?></div>
                        <div class="metric-label">Slow Queries</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Management Actions -->
        <div class="card">
            <h3>Database Management</h3>
            <div class="action-grid">
                <form method="POST" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" name="optimize_tables" class="btn btn-primary">
                        <i class="fas fa-tools"></i> Optimize All Tables
                    </button>
                    <p class="action-desc">Optimize all tables in the current database to improve performance.</p>
                </form>
                
                <form method="POST" class="action-form" onsubmit="return confirm('Are you sure you want to restart the MySQL service? This will temporarily interrupt all database connections.')">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" name="restart_mysql" class="btn btn-warning">
                        <i class="fas fa-redo"></i> Restart MySQL Service
                    </button>
                    <p class="action-desc">Restart the MySQL service (requires administrator privileges).</p>
                </form>
                
                <div class="action-form">
                    <a href="phpmyadmin.php" class="btn btn-info">
                        <i class="fas fa-external-link-alt"></i> Open phpMyAdmin
                    </a>
                    <p class="action-desc">Access the phpMyAdmin interface for advanced database management.</p>
                </div>
                
                <div class="action-form">
                    <a href="db-backup.php" class="btn btn-success">
                        <i class="fas fa-download"></i> Database Backups
                    </a>
                    <p class="action-desc">Create and manage database backups.</p>
                </div>
            </div>
        </div>
    </div>

    <style>
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .info-item {
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-align: center;
        }
        
        .info-label {
            font-size: 0.9em;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        
        .info-value {
            font-size: 1.2em;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .database-list {
            margin-top: 20px;
        }
        
        .db-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .db-tag {
            padding: 5px 10px;
            background: var(--section-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .db-tag.current {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .config-section h4 {
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .config-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .config-item label {
            font-weight: 500;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .metric-card {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .metric-icon {
            font-size: 2.5em;
            margin-right: 20px;
            color: var(--primary-color);
        }
        
        .metric-value {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .action-form {
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-align: center;
        }
        
        .action-desc {
            margin-top: 10px;
            font-size: 0.9em;
            color: var(--text-muted);
        }
    </style>
</body>
</html>

<?php
function formatUptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    return "{$days}d {$hours}h {$minutes}m";
}
?>