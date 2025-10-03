<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle snapshot actions
if ($_POST) {
    if (isset($_POST['create_snapshot'])) {
        $result = createSnapshot($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Snapshot created successfully: ' . htmlspecialchars($result['snapshot_name']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to create snapshot: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['restore_snapshot'])) {
        $snapshotId = $_POST['snapshot_id'];
        $result = restoreSnapshot($snapshotId);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Snapshot restored successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to restore snapshot: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['delete_snapshot'])) {
        $snapshotId = $_POST['snapshot_id'];
        $result = deleteSnapshot($snapshotId);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Snapshot deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete snapshot: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Create snapshots table if not exists
function initializeSnapshotsTable() {
    global $conn;
    
    $createTable = "CREATE TABLE IF NOT EXISTS system_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        snapshot_type ENUM('quick', 'database', 'files', 'config', 'full') NOT NULL DEFAULT 'quick',
        file_path VARCHAR(500),
        file_size BIGINT DEFAULT 0,
        status ENUM('creating', 'completed', 'failed', 'restoring') NOT NULL DEFAULT 'creating',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        restored_at TIMESTAMP NULL,
        metadata JSON
    )";
    
    $result = mysqli_query($conn, $createTable);
    if (!$result) {
        error_log("Failed to create snapshots table: " . mysqli_error($conn));
    }
}

// Create system snapshot
function createSnapshot($data) {
    global $conn;
    
    initializeSnapshotsTable();
    
    $name = $data['snapshot_name'];
    $description = $data['description'] ?? '';
    $snapshotType = $data['snapshot_type'];
    
    // Insert snapshot record
    $stmt = mysqli_prepare($conn, "INSERT INTO system_snapshots (name, description, snapshot_type, status) VALUES (?, ?, ?, 'creating')");
    mysqli_stmt_bind_param($stmt, "sss", $name, $description, $snapshotType);
    
    if (mysqli_stmt_execute($stmt)) {
        $snapshotId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        $timestamp = date('Y-m-d_H-i-s');
        $snapshotDir = '/var/snapshots';
        
        // Ensure snapshot directory exists
        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0750, true);
        }
        
        $result = null;
        
        switch ($snapshotType) {
            case 'quick':
                $result = createQuickSnapshot($snapshotId, $name, $timestamp, $snapshotDir);
                break;
                
            case 'database':
                $result = createDatabaseSnapshot($snapshotId, $name, $timestamp, $snapshotDir);
                break;
                
            case 'files':
                $result = createFilesSnapshot($snapshotId, $name, $timestamp, $snapshotDir, $data);
                break;
                
            case 'config':
                $result = createConfigSnapshot($snapshotId, $name, $timestamp, $snapshotDir);
                break;
                
            case 'full':
                $result = createFullSnapshot($snapshotId, $name, $timestamp, $snapshotDir);
                break;
                
            default:
                $result = ['success' => false, 'error' => 'Invalid snapshot type'];
        }
        
        if ($result['success']) {
            // Update snapshot record with file details
            $stmt = mysqli_prepare($conn, "UPDATE system_snapshots SET file_path = ?, file_size = ?, status = 'completed', metadata = ? WHERE id = ?");
            $metadata = json_encode($result['metadata'] ?? []);
            mysqli_stmt_bind_param($stmt, "sisi", $result['file_path'], $result['file_size'], $metadata, $snapshotId);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                return ['success' => true, 'snapshot_name' => $name, 'snapshot_id' => $snapshotId];
            } else {
                mysqli_stmt_close($stmt);
                return ['success' => false, 'error' => mysqli_error($conn)];
            }
        } else {
            // Mark snapshot as failed
            $stmt = mysqli_prepare($conn, "UPDATE system_snapshots SET status = 'failed' WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $snapshotId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            
            return $result;
        }
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Quick snapshot - critical system state only
function createQuickSnapshot($snapshotId, $name, $timestamp, $snapshotDir) {
    $snapshotFile = "$snapshotDir/quick_{$name}_{$timestamp}.tar.gz";
    
    // Quick snapshot includes: database, hosting panel config, key system configs
    $quickPaths = [
        dirname(__DIR__), // Hosting panel directory
        '/etc/apache2/sites-available',
        '/etc/mysql/conf.d',
        '/etc/hosts',
        '/etc/hostname'
    ];
    
    $tarCmd = "tar -czf " . escapeshellarg($snapshotFile);
    
    // Add existing paths only
    foreach ($quickPaths as $path) {
        if (file_exists($path)) {
            $tarCmd .= " " . escapeshellarg($path);
        }
    }
    
    // Create database dump for quick snapshot
    $dbDumpFile = "/tmp/quick_db_dump_{$timestamp}.sql";
    $dumpResult = createDatabaseDump($dbDumpFile);
    
    if ($dumpResult['success']) {
        $tarCmd .= " " . escapeshellarg($dbDumpFile);
    }
    
    exec($tarCmd . " 2>&1", $output, $returnCode);
    
    // Clean up temp database dump
    if (file_exists($dbDumpFile)) {
        unlink($dbDumpFile);
    }
    
    if ($returnCode === 0 && file_exists($snapshotFile)) {
        return [
            'success' => true,
            'file_path' => $snapshotFile,
            'file_size' => filesize($snapshotFile),
            'metadata' => [
                'type' => 'quick',
                'includes' => ['database', 'hosting_panel', 'system_config'],
                'creation_time' => time()
            ]
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to create quick snapshot: ' . implode("\n", $output)];
    }
}

// Database snapshot
function createDatabaseSnapshot($snapshotId, $name, $timestamp, $snapshotDir) {
    $snapshotFile = "$snapshotDir/database_{$name}_{$timestamp}.sql.gz";
    
    $result = createDatabaseDump($snapshotFile, true); // Compressed
    
    if ($result['success']) {
        return [
            'success' => true,
            'file_path' => $snapshotFile,
            'file_size' => filesize($snapshotFile),
            'metadata' => [
                'type' => 'database',
                'database' => DB_NAME,
                'creation_time' => time()
            ]
        ];
    }
    
    return $result;
}

// Files snapshot
function createFilesSnapshot($snapshotId, $name, $timestamp, $snapshotDir, $data) {
    $snapshotFile = "$snapshotDir/files_{$name}_{$timestamp}.tar.gz";
    
    $includePaths = $data['include_paths'] ?? ['/var/www'];
    $excludePatterns = $data['exclude_patterns'] ?? '';
    
    $tarCmd = "tar -czf " . escapeshellarg($snapshotFile);
    
    // Add exclude patterns
    if (!empty($excludePatterns)) {
        $patterns = array_filter(array_map('trim', explode("\n", $excludePatterns)));
        foreach ($patterns as $pattern) {
            $tarCmd .= " --exclude=" . escapeshellarg($pattern);
        }
    }
    
    // Common excludes for snapshots
    $commonExcludes = ['*.log', '*.tmp', 'cache/*', 'tmp/*'];
    foreach ($commonExcludes as $exclude) {
        $tarCmd .= " --exclude=" . escapeshellarg($exclude);
    }
    
    // Add include paths
    foreach ($includePaths as $path) {
        if (is_dir($path)) {
            $tarCmd .= " " . escapeshellarg($path);
        }
    }
    
    exec($tarCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($snapshotFile)) {
        return [
            'success' => true,
            'file_path' => $snapshotFile,
            'file_size' => filesize($snapshotFile),
            'metadata' => [
                'type' => 'files',
                'included_paths' => $includePaths,
                'excluded_patterns' => $excludePatterns,
                'creation_time' => time()
            ]
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to create files snapshot: ' . implode("\n", $output)];
    }
}

// Config snapshot
function createConfigSnapshot($snapshotId, $name, $timestamp, $snapshotDir) {
    $snapshotFile = "$snapshotDir/config_{$name}_{$timestamp}.tar.gz";
    
    $configPaths = [
        '/etc/apache2',
        '/etc/mysql',
        '/etc/php',
        '/etc/ssl/certs',
        '/etc/hosts',
        '/etc/hostname',
        '/etc/fstab',
        dirname(__DIR__) // Hosting panel config
    ];
    
    $tarCmd = "tar -czf " . escapeshellarg($snapshotFile);
    
    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            $tarCmd .= " " . escapeshellarg($path);
        }
    }
    
    exec($tarCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($snapshotFile)) {
        return [
            'success' => true,
            'file_path' => $snapshotFile,
            'file_size' => filesize($snapshotFile),
            'metadata' => [
                'type' => 'config',
                'config_paths' => $configPaths,
                'creation_time' => time()
            ]
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to create config snapshot: ' . implode("\n", $output)];
    }
}

// Full system snapshot
function createFullSnapshot($snapshotId, $name, $timestamp, $snapshotDir) {
    $snapshotFile = "$snapshotDir/full_{$name}_{$timestamp}.tar.gz";
    
    // Create database dump first
    $dbDumpFile = "/tmp/full_db_dump_{$timestamp}.sql";
    $dbResult = createDatabaseDump($dbDumpFile);
    
    if (!$dbResult['success']) {
        return ['success' => false, 'error' => 'Failed to create database dump for full snapshot'];
    }
    
    // Create comprehensive archive
    $tarCmd = "tar -czf " . escapeshellarg($snapshotFile);
    
    // Exclude large/unnecessary directories
    $excludeDirs = [
        '/proc/*',
        '/sys/*',
        '/dev/*',
        '/tmp/*',
        '/var/tmp/*',
        '/var/log/*',
        '/var/cache/*',
        '*.log',
        '*.tmp'
    ];
    
    foreach ($excludeDirs as $exclude) {
        $tarCmd .= " --exclude=" . escapeshellarg($exclude);
    }
    
    // Include key system directories
    $includePaths = [
        '/etc',
        '/var/www',
        '/home',
        '/opt',
        dirname(__DIR__), // Hosting panel
        $dbDumpFile
    ];
    
    foreach ($includePaths as $path) {
        if (file_exists($path)) {
            $tarCmd .= " " . escapeshellarg($path);
        }
    }
    
    exec($tarCmd . " 2>&1", $output, $returnCode);
    
    // Clean up temp database dump
    if (file_exists($dbDumpFile)) {
        unlink($dbDumpFile);
    }
    
    if ($returnCode === 0 && file_exists($snapshotFile)) {
        return [
            'success' => true,
            'file_path' => $snapshotFile,
            'file_size' => filesize($snapshotFile),
            'metadata' => [
                'type' => 'full',
                'includes' => ['database', 'system_config', 'web_files', 'user_data'],
                'creation_time' => time()
            ]
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to create full snapshot: ' . implode("\n", $output)];
    }
}

// Helper function to create database dump
function createDatabaseDump($outputFile, $compress = false) {
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
    
    $dumpCmd = "mysqldump -h " . escapeshellarg($host) . 
               " -u " . escapeshellarg($username) . 
               " -p" . escapeshellarg($password) . 
               " " . escapeshellarg($dbname);
    
    if ($compress) {
        $dumpCmd .= " | gzip > " . escapeshellarg($outputFile);
    } else {
        $dumpCmd .= " > " . escapeshellarg($outputFile);
    }
    
    exec($dumpCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($outputFile)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'Database dump failed: ' . implode("\n", $output)];
    }
}

// Get all snapshots
function getSnapshots() {
    global $conn;
    
    initializeSnapshotsTable();
    
    $result = mysqli_query($conn, "SELECT * FROM system_snapshots ORDER BY created_at DESC");
    if ($result) {
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }
    return [];
}

// Restore snapshot
function restoreSnapshot($snapshotId) {
    global $conn;
    
    // Get snapshot details
    $stmt = mysqli_prepare($conn, "SELECT * FROM system_snapshots WHERE id = ? AND status = 'completed'");
    mysqli_stmt_bind_param($stmt, "i", $snapshotId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $snapshot = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$snapshot) {
        return ['success' => false, 'error' => 'Snapshot not found or not completed'];
    }
    
    if (!file_exists($snapshot['file_path'])) {
        return ['success' => false, 'error' => 'Snapshot file not found'];
    }
    
    // Mark as restoring
    $stmt = mysqli_prepare($conn, "UPDATE system_snapshots SET status = 'restoring' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $snapshotId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
        
    $result = performSnapshotRestore($snapshot);
    
    if ($result['success']) {
        // Mark as completed and update restore time
        $stmt = mysqli_prepare($conn, "UPDATE system_snapshots SET status = 'completed', restored_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $snapshotId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        // Revert status
        $stmt = mysqli_prepare($conn, "UPDATE system_snapshots SET status = 'completed' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $snapshotId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    
    return $result;
}

// Perform actual snapshot restore
function performSnapshotRestore($snapshot) {
    $filePath = $snapshot['file_path'];
    $snapshotType = $snapshot['snapshot_type'];
    
    switch ($snapshotType) {
        case 'quick':
            return restoreQuickSnapshot($filePath);
            
        case 'database':
            return restoreDatabaseSnapshot($filePath);
            
        case 'files':
        case 'config':
            return restoreFilesSnapshot($filePath);
            
        case 'full':
            return restoreFullSnapshot($filePath);
            
        default:
            return ['success' => false, 'error' => 'Unknown snapshot type'];
    }
}

function restoreQuickSnapshot($filePath) {
    // Extract to temporary directory first
    $tempDir = '/tmp/snapshot_restore_' . time();
    mkdir($tempDir, 0755, true);
    
    $extractCmd = "tar -xzf " . escapeshellarg($filePath) . " -C " . escapeshellarg($tempDir);
    exec($extractCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        return ['success' => false, 'error' => 'Failed to extract snapshot'];
    }
    
    // Restore database if present
    $dbFiles = glob($tempDir . '/*.sql');
    if (!empty($dbFiles)) {
        $restoreResult = restoreDatabaseFromFile($dbFiles[0]);
        if (!$restoreResult['success']) {
            return $restoreResult;
        }
    }
    
    // Restore files (carefully, excluding database dump)
    exec("find " . escapeshellarg($tempDir) . " -name '*.sql' -delete"); // Remove SQL files
    exec("rsync -av --exclude='*.sql' " . escapeshellarg($tempDir) . "/ /", $output, $returnCode);
    
    // Cleanup
    exec("rm -rf " . escapeshellarg($tempDir));
    
    return ['success' => $returnCode === 0, 'error' => $returnCode !== 0 ? 'File restore failed' : ''];
}

function restoreDatabaseSnapshot($filePath) {
    return restoreDatabaseFromFile($filePath);
}

function restoreFilesSnapshot($filePath) {
    $extractCmd = "tar -xzf " . escapeshellarg($filePath) . " -C /";
    exec($extractCmd . " 2>&1", $output, $returnCode);
    
    return ['success' => $returnCode === 0, 'error' => $returnCode !== 0 ? implode("\n", $output) : ''];
}

function restoreFullSnapshot($filePath) {
    return restoreQuickSnapshot($filePath); // Same process as quick snapshot
}

function restoreDatabaseFromFile($sqlFile) {
    $isCompressed = strpos($sqlFile, '.gz') !== false;
    
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
    
    if ($isCompressed) {
        $restoreCmd = "zcat " . escapeshellarg($sqlFile) . 
                      " | mysql -h " . escapeshellarg($host) . 
                      " -u " . escapeshellarg($username) . 
                      " -p" . escapeshellarg($password) . 
                      " " . escapeshellarg($dbname);
    } else {
        $restoreCmd = "mysql -h " . escapeshellarg($host) . 
                      " -u " . escapeshellarg($username) . 
                      " -p" . escapeshellarg($password) . 
                      " " . escapeshellarg($dbname) . 
                      " < " . escapeshellarg($sqlFile);
    }
    
    exec($restoreCmd . " 2>&1", $output, $returnCode);
    
    return ['success' => $returnCode === 0, 'error' => $returnCode !== 0 ? implode("\n", $output) : ''];
}

// Delete snapshot
function deleteSnapshot($snapshotId) {
    global $conn;
    
    // Get snapshot file path
    $stmt = mysqli_prepare($conn, "SELECT file_path FROM system_snapshots WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $snapshotId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $snapshot = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($snapshot && file_exists($snapshot['file_path'])) {
        unlink($snapshot['file_path']);
    }
    
    // Delete database record
    $stmt = mysqli_prepare($conn, "DELETE FROM system_snapshots WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $snapshotId);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Get snapshot statistics
function getSnapshotStats() {
    global $conn;
    
    initializeSnapshotsTable();
    
    $stats = [
        'total' => 0,
        'total_size' => 0,
        'by_type' => [],
        'recent_count' => 0
    ];
    
    $result = mysqli_query($conn, "SELECT snapshot_type, COUNT(*) as count, SUM(file_size) as size FROM system_snapshots WHERE status = 'completed' GROUP BY snapshot_type");
    if ($result) {
        $results = mysqli_fetch_all($result, MYSQLI_ASSOC);
        
        foreach ($results as $row) {
            $stats['total'] += $row['count'];
            $stats['total_size'] += $row['size'];
            $stats['by_type'][$row['snapshot_type']] = [
                'count' => $row['count'],
                'size' => $row['size']
            ];
        }
    }
        
    // Count recent snapshots (last 7 days)
    $result = mysqli_query($conn, "SELECT COUNT(*) as recent FROM system_snapshots WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $stats['recent_count'] = (int)$row['recent'];
    }
    
    return $stats;
}

$snapshots = getSnapshots();
$stats = getSnapshotStats();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Snapshot Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-camera"></i> Snapshot Manager</h1>
        
        <?= $message ?>
        
        <!-- Snapshot Statistics -->
        <div class="card">
            <h3>Snapshot Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-camera"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total Snapshots</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-hdd"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= formatBytes($stats['total_size']) ?></div>
                        <div class="stat-label">Storage Used</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-bolt"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['by_type']['quick']['count'] ?? 0 ?></div>
                        <div class="stat-label">Quick Snapshots</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['recent_count'] ?></div>
                        <div class="stat-label">Recent (7 days)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create New Snapshot -->
        <div class="card">
            <h3>Create System Snapshot</h3>
            <form method="POST" class="snapshot-form" id="snapshotForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Snapshot Name:</label>
                        <input type="text" name="snapshot_name" class="form-control" placeholder="e.g., Pre-update-snapshot" required>
                    </div>
                    <div class="form-group">
                        <label>Snapshot Type:</label>
                        <select name="snapshot_type" class="form-control" id="snapshotType" onchange="updateSnapshotOptions()" required>
                            <option value="quick">Quick Snapshot (Recommended)</option>
                            <option value="database">Database Only</option>
                            <option value="files">Files Only</option>
                            <option value="config">Configuration Only</option>
                            <option value="full">Full System</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description:</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Optional: Describe what this snapshot is for..."></textarea>
                </div>
                
                <div id="fileOptions" class="snapshot-options" style="display: none;">
                    <div class="form-group">
                        <label>Include Paths:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="include_paths[]" value="/var/www" checked> Web Files (/var/www)</label>
                            <label><input type="checkbox" name="include_paths[]" value="/home"> User Home Directories (/home)</label>
                            <label><input type="checkbox" name="include_paths[]" value="/opt"> Optional Software (/opt)</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Exclude Patterns:</label>
                        <textarea name="exclude_patterns" class="form-control" rows="3" placeholder="*.log&#10;*.tmp&#10;cache/*&#10;node_modules/*"></textarea>
                    </div>
                </div>
                
                <div class="snapshot-info">
                    <div class="info-item" id="quickInfo">
                        <i class="fas fa-info-circle"></i>
                        <strong>Quick Snapshot:</strong> Creates a lightweight snapshot containing database, hosting panel configuration, and key system settings. Recommended for routine backups.
                    </div>
                    <div class="info-item" id="databaseInfo" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Database Snapshot:</strong> Creates a compressed backup of the entire database. Fast and efficient for data protection.
                    </div>
                    <div class="info-item" id="filesInfo" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Files Snapshot:</strong> Creates a backup of selected file system paths. Useful for protecting web files and user data.
                    </div>
                    <div class="info-item" id="configInfo" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Configuration Snapshot:</strong> Backs up system configuration files including Apache, MySQL, PHP, and hosting panel settings.
                    </div>
                    <div class="info-item" id="fullInfo" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Full System Snapshot:</strong> Comprehensive backup including database, files, and configuration. Takes longer but provides complete protection.
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="create_snapshot" class="btn btn-primary">
                        <i class="fas fa-camera"></i> Create Snapshot
                    </button>
                    <button type="button" onclick="resetForm()" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing Snapshots -->
        <div class="card">
            <h3>System Snapshots</h3>
            
            <?php if (empty($snapshots)): ?>
                <div class="alert alert-info">No snapshots created yet. Create your first snapshot above for quick system recovery.</div>
            <?php else: ?>
                <div class="snapshot-list">
                    <?php foreach ($snapshots as $snapshot): ?>
                    <div class="snapshot-item">
                        <div class="snapshot-header">
                            <div class="snapshot-info">
                                <div class="snapshot-name">
                                    <i class="fas fa-<?= getSnapshotIcon($snapshot['snapshot_type']) ?>"></i>
                                    <?= htmlspecialchars($snapshot['name']) ?>
                                </div>
                                <div class="snapshot-meta">
                                    <span class="type-badge type-<?= $snapshot['snapshot_type'] ?>">
                                        <?= strtoupper($snapshot['snapshot_type']) ?>
                                    </span>
                                    <span class="status-badge status-<?= $snapshot['status'] ?>">
                                        <?= strtoupper($snapshot['status']) ?>
                                    </span>
                                    <span class="snapshot-size"><?= formatBytes($snapshot['file_size']) ?></span>
                                    <span class="snapshot-date"><?= date('M j, Y H:i', strtotime($snapshot['created_at'])) ?></span>
                                </div>
                                <?php if (!empty($snapshot['description'])): ?>
                                <div class="snapshot-description">
                                    <?= htmlspecialchars($snapshot['description']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="snapshot-actions">
                                <?php if ($snapshot['status'] === 'completed'): ?>
                                    <button onclick="showRestoreModal(<?= $snapshot['id'] ?>, '<?= htmlspecialchars($snapshot['name']) ?>', '<?= $snapshot['snapshot_type'] ?>')" class="btn btn-success" title="Restore Snapshot">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                <?php endif; ?>
                                <button onclick="showSnapshotDetails(<?= $snapshot['id'] ?>)" class="btn btn-info" title="View Details">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this snapshot? This action cannot be undone.')">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="snapshot_id" value="<?= $snapshot['id'] ?>">
                                    <button type="submit" name="delete_snapshot" class="btn btn-danger" title="Delete Snapshot">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <?php if ($snapshot['restored_at']): ?>
                        <div class="snapshot-restore-info">
                            <i class="fas fa-history"></i>
                            Last restored: <?= date('M j, Y H:i', strtotime($snapshot['restored_at'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('restoreModal')">&times;</span>
            <h3>Restore Snapshot</h3>
            <form method="POST" id="restoreForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="snapshot_id" id="restoreSnapshotId">
                
                <div class="restore-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Critical Warning:</strong> Restoring a snapshot will overwrite current system state. 
                    This action cannot be undone. Ensure you have a recent snapshot before proceeding.
                </div>
                
                <div class="restore-info" id="restoreInfo"></div>
                
                <div class="restore-options">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="confirmRestore">
                            I understand the risks and want to restore this snapshot
                        </label>
                    </div>
                </div>
                
                <div class="restore-actions">
                    <button type="submit" name="restore_snapshot" class="btn btn-danger" disabled id="restoreBtn">
                        <i class="fas fa-undo"></i> Restore Snapshot
                    </button>
                    <button type="button" onclick="closeModal('restoreModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .stat-icon {
            font-size: 2.5em;
            margin-right: 20px;
            color: var(--primary-color);
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .snapshot-form .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            font-weight: normal;
            margin-bottom: 0;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .snapshot-options {
            border-top: 1px solid var(--border-color);
            padding-top: 20px;
            margin-top: 20px;
        }
        
        .snapshot-info {
            margin: 20px 0;
        }
        
        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 15px;
            background: var(--section-bg);
            border-radius: 6px;
            border-left: 3px solid var(--primary-color);
        }
        
        .info-item i {
            color: var(--primary-color);
            margin-top: 2px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-start;
        }
        
        .snapshot-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .snapshot-item {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            background: var(--card-bg);
        }
        
        .snapshot-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px;
        }
        
        .snapshot-info {
            flex: 1;
        }
        
        .snapshot-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .snapshot-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .snapshot-description {
            color: var(--text-muted);
            font-style: italic;
            margin-top: 10px;
        }
        
        .type-badge,
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7em;
            font-weight: bold;
        }
        
        .type-quick { background: #4caf50; color: white; }
        .type-database { background: #2196f3; color: white; }
        .type-files { background: #ff9800; color: white; }
        .type-config { background: #9c27b0; color: white; }
        .type-full { background: #f44336; color: white; }
        
        .status-completed { background: rgba(76, 175, 80, 0.2); color: #4caf50; }
        .status-creating { background: rgba(255, 152, 0, 0.2); color: #ff9800; }
        .status-failed { background: rgba(244, 67, 54, 0.2); color: #f44336; }
        .status-restoring { background: rgba(33, 150, 243, 0.2); color: #2196f3; }
        
        .snapshot-size,
        .snapshot-date {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .snapshot-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .snapshot-restore-info {
            padding: 10px 20px;
            background: rgba(76, 175, 80, 0.1);
            border-top: 1px solid var(--border-color);
            font-size: 0.9em;
            color: #4caf50;
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
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
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
        
        .restore-warning {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid #f44336;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .restore-warning i {
            color: #f44336;
            margin-right: 10px;
        }
        
        .restore-info {
            background: var(--section-bg);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .restore-options {
            margin: 20px 0;
        }
        
        .checkbox-label {
            display: flex !important;
            align-items: center;
            gap: 8px;
            font-weight: normal !important;
        }
        
        .restore-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
    </style>

    <script>
        function updateSnapshotOptions() {
            const snapshotType = document.getElementById('snapshotType').value;
            const fileOptions = document.getElementById('fileOptions');
            
            // Hide all info items
            document.querySelectorAll('.info-item').forEach(item => {
                item.style.display = 'none';
            });
            
            // Show relevant info
            document.getElementById(snapshotType + 'Info').style.display = 'flex';
            
            // Show file options for files type
            if (snapshotType === 'files') {
                fileOptions.style.display = 'block';
            } else {
                fileOptions.style.display = 'none';
            }
        }
        
        function resetForm() {
            document.getElementById('snapshotForm').reset();
            updateSnapshotOptions();
        }
        
        function showRestoreModal(snapshotId, snapshotName, snapshotType) {
            document.getElementById('restoreSnapshotId').value = snapshotId;
            document.getElementById('restoreInfo').innerHTML = 
                `<strong>Snapshot:</strong> ${snapshotName}<br><strong>Type:</strong> ${snapshotType.toUpperCase()}`;
            
            document.getElementById('restoreModal').style.display = 'block';
            document.getElementById('restoreBtn').disabled = true;
            document.getElementById('confirmRestore').checked = false;
        }
        
        function showSnapshotDetails(snapshotId) {
            alert('Snapshot details view will be implemented.');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Enable restore button when checkbox is checked
        document.addEventListener('DOMContentLoaded', function() {
            const confirmCheckbox = document.getElementById('confirmRestore');
            const restoreBtn = document.getElementById('restoreBtn');
            
            if (confirmCheckbox && restoreBtn) {
                confirmCheckbox.addEventListener('change', function() {
                    restoreBtn.disabled = !this.checked;
                });
            }
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const restoreModal = document.getElementById('restoreModal');
            
            if (event.target == restoreModal) {
                restoreModal.style.display = 'none';
            }
        }
        
        // Initialize form
        updateSnapshotOptions();
    </script>
</body>
</html>

<?php
function getSnapshotIcon($type) {
    switch ($type) {
        case 'quick': return 'bolt';
        case 'database': return 'database';
        case 'files': return 'folder';
        case 'config': return 'cog';
        case 'full': return 'archive';
        default: return 'camera';
    }
}
?>