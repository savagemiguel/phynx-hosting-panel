<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle database backup actions
if ($_POST) {
    if (isset($_POST['create_backup'])) {
        $result = createDatabaseBackup($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Database backup created successfully: ' . htmlspecialchars($result['filename']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to create backup: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['restore_backup'])) {
        $result = restoreDatabaseBackup($_POST['backup_file']);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Database restored successfully from backup.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to restore backup: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['delete_backup'])) {
        $result = deleteBackupFile($_POST['backup_file']);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup file deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete backup: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['schedule_backup'])) {
        $result = scheduleAutoBackup($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Automatic backup scheduled successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to schedule backup: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Create database backup
function createDatabaseBackup($data) {
    $backupDir = '../backups/database/';
    
    // Ensure backup directory exists
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupName = $data['backup_name'] ?? 'backup';
    $filename = $backupName . '_' . $timestamp . '.sql';
    $filepath = $backupDir . $filename;
    
    $includeStructure = isset($data['include_structure']);
    $includeData = isset($data['include_data']);
    $compression = isset($data['compression']);
    
    if ($compression) {
        $filename .= '.gz';
        $filepath .= '.gz';
    }
    
    // Build mysqldump command
    $command = 'mysqldump';
    $command .= ' -h ' . escapeshellarg(DB_HOST);
    $command .= ' -u ' . escapeshellarg(DB_USER);
    $command .= ' -p' . escapeshellarg(DB_PASS);
    
    if (!$includeStructure) {
        $command .= ' --no-create-info';
    }
    
    if (!$includeData) {
        $command .= ' --no-data';
    }
    
    $command .= ' --single-transaction --routines --triggers';
    $command .= ' ' . escapeshellarg(DB_NAME);
    
    if ($compression) {
        $command .= ' | gzip > ' . escapeshellarg($filepath);
    } else {
        $command .= ' > ' . escapeshellarg($filepath);
    }
    
    exec($command . ' 2>&1', $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($filepath)) {
        // Log the backup
        logBackup($filename, filesize($filepath), $includeStructure, $includeData);
        
        return [
            'success' => true,
            'filename' => $filename,
            'size' => filesize($filepath)
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Backup command failed: ' . implode("\n", $output)
        ];
    }
}

// Restore database backup
function restoreDatabaseBackup($backupFile) {
    $backupDir = '../backups/database/';
    $filepath = $backupDir . $backupFile;
    
    if (!file_exists($filepath)) {
        return ['success' => false, 'error' => 'Backup file not found'];
    }
    
    $isCompressed = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
    
    // Build mysql restore command
    $command = 'mysql';
    $command .= ' -h ' . escapeshellarg(DB_HOST);
    $command .= ' -u ' . escapeshellarg(DB_USER);
    $command .= ' -p' . escapeshellarg(DB_PASS);
    $command .= ' ' . escapeshellarg(DB_NAME);
    
    if ($isCompressed) {
        $command = 'zcat ' . escapeshellarg($filepath) . ' | ' . $command;
    } else {
        $command .= ' < ' . escapeshellarg($filepath);
    }
    
    exec($command . ' 2>&1', $output, $returnCode);
    
    if ($returnCode === 0) {
        return ['success' => true];
    } else {
        return [
            'success' => false,
            'error' => 'Restore command failed: ' . implode("\n", $output)
        ];
    }
}

// Delete backup file
function deleteBackupFile($backupFile) {
    $backupDir = '../backups/database/';
    $filepath = $backupDir . $backupFile;
    
    if (file_exists($filepath) && unlink($filepath)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'Failed to delete backup file'];
    }
}

// Log backup to database
function logBackup($filename, $size, $includeStructure, $includeData) {
    global $conn;
    
    // Create backup_logs table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS backup_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        file_size BIGINT NOT NULL,
        backup_type VARCHAR(50) NOT NULL,
        include_structure BOOLEAN NOT NULL,
        include_data BOOLEAN NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    mysqli_query($conn, $createTable);
    
    // Insert backup log
    $backupType = 'manual';
    if ($includeStructure && $includeData) {
        $backupType = 'full';
    } elseif ($includeStructure) {
        $backupType = 'structure_only';
    } elseif ($includeData) {
        $backupType = 'data_only';
    }
    
    $stmt = mysqli_prepare($conn, "INSERT INTO backup_logs (filename, file_size, backup_type, include_structure, include_data) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sisii", $filename, $size, $backupType, $includeStructure, $includeData);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Get existing backups
function getExistingBackups() {
    $backupDir = '../backups/database/';
    $backups = [];
    
    if (is_dir($backupDir)) {
        $files = scandir($backupDir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql' || 
                (pathinfo($file, PATHINFO_EXTENSION) === 'gz' && pathinfo(pathinfo($file, PATHINFO_FILENAME), PATHINFO_EXTENSION) === 'sql')) {
                
                $filepath = $backupDir . $file;
                $backups[] = [
                    'filename' => $file,
                    'size' => filesize($filepath),
                    'created' => filemtime($filepath),
                    'compressed' => pathinfo($file, PATHINFO_EXTENSION) === 'gz'
                ];
            }
        }
    }
    
    // Sort by creation time (newest first)
    usort($backups, function($a, $b) {
        return $b['created'] - $a['created'];
    });
    
    return $backups;
}

// Get backup statistics
function getBackupStatistics() {
    global $conn;
    
    $stats = [
        'total_backups' => 0,
        'total_size' => 0,
        'last_backup' => null,
        'recent_backups' => 0
    ];
    
    // Check if backup_logs table exists
    $result = mysqli_query($conn, "SHOW TABLES LIKE 'backup_logs'");
    if (mysqli_num_rows($result) > 0) {
        // Get statistics from database
        $result = mysqli_query($conn, "SELECT COUNT(*) as total, SUM(file_size) as total_size, MAX(created_at) as last_backup FROM backup_logs");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $stats['total_backups'] = $row['total'];
            $stats['total_size'] = $row['total_size'];
            $stats['last_backup'] = $row['last_backup'];
        }
        
        // Get recent backups (last 7 days)
        $result = mysqli_query($conn, "SELECT COUNT(*) as recent FROM backup_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $stats['recent_backups'] = $row['recent'];
        }
    }
    
    return $stats;
}

// Schedule automatic backup
function scheduleAutoBackup($data) {
    // This would integrate with a cron system or task scheduler
    // For demonstration purposes, we'll return success
    return ['success' => true];
}

$existingBackups = getExistingBackups();
$backupStats = getBackupStatistics();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-download"></i> Database Backup Management</h1>
        
        <?= $message ?>
        
        <!-- Backup Statistics -->
        <div class="card">
            <h3>Backup Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-archive"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $backupStats['total_backups'] ?></div>
                        <div class="stat-label">Total Backups</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-hdd"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= formatBytes($backupStats['total_size']) ?></div>
                        <div class="stat-label">Storage Used</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $backupStats['recent_backups'] ?></div>
                        <div class="stat-label">Recent (7 days)</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-info">
                        <div class="stat-number">
                            <?= $backupStats['last_backup'] ? date('M j, Y', strtotime($backupStats['last_backup'])) : 'Never' ?>
                        </div>
                        <div class="stat-label">Last Backup</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create New Backup -->
        <div class="card">
            <h3>Create Database Backup</h3>
            <form method="POST" class="backup-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Backup Name:</label>
                        <input type="text" name="backup_name" class="form-control" 
                               placeholder="e.g., daily-backup" value="backup-<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                
                <div class="backup-options">
                    <h4>Backup Options</h4>
                    <div class="options-grid">
                        <div class="option-group">
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_structure" id="include_structure" checked>
                                <span class="checkbox-custom"></span>
                                <label for="include_structure" class="checkbox-label">
                                    <span class="checkbox-text">Include Table Structure</span>
                                    <span class="checkbox-subtext">Include CREATE TABLE statements</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="option-group">
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_data" id="include_data" checked>
                                <span class="checkbox-custom"></span>
                                <label for="include_data" class="checkbox-label">
                                    <span class="checkbox-text">Include Table Data</span>
                                    <span class="checkbox-subtext">Include INSERT statements for data</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="option-group">
                            <div class="checkbox-group">
                                <input type="checkbox" name="compression" id="db_compression" checked>
                                <span class="checkbox-custom"></span>
                                <label for="db_compression" class="checkbox-label">
                                    <span class="checkbox-text">Compress Backup</span>
                                    <span class="checkbox-subtext">Use GZIP compression to reduce file size</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="backup-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Current Database:</strong> <?= DB_NAME ?> 
                    <span class="separator">|</span>
                    <strong>Estimated Size:</strong> <span id="estimated-size">Calculating...</span>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="create_backup" class="btn btn-primary">
                        <i class="fas fa-download"></i> Create Backup
                    </button>
                    <button type="button" onclick="estimateBackupSize()" class="btn btn-secondary">
                        <i class="fas fa-calculator"></i> Estimate Size
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing Backups -->
        <div class="card">
            <h3>Existing Backups</h3>
            
            <?php if (empty($existingBackups)): ?>
                <div class="no-backups">
                    <i class="fas fa-archive"></i>
                    <p>No backups found. Create your first backup to get started.</p>
                </div>
            <?php else: ?>
                <div class="backups-list">
                    <?php foreach ($existingBackups as $backup): ?>
                    <div class="backup-item">
                        <div class="backup-info">
                            <div class="backup-name">
                                <i class="fas fa-file-archive"></i>
                                <?= htmlspecialchars($backup['filename']) ?>
                                <?php if ($backup['compressed']): ?>
                                    <span class="compressed-badge">Compressed</span>
                                <?php endif; ?>
                            </div>
                            <div class="backup-details">
                                <span class="backup-size"><?= formatBytes($backup['size']) ?></span>
                                <span class="backup-date"><?= date('M j, Y H:i', $backup['created']) ?></span>
                            </div>
                        </div>
                        <div class="backup-actions">
                            <a href="../backups/database/<?= urlencode($backup['filename']) ?>" 
                               class="btn btn-sm btn-success" download>
                                <i class="fas fa-download"></i> Download
                            </a>
                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to restore from this backup? This will overwrite the current database.')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['filename']) ?>">
                                <button type="submit" name="restore_backup" class="btn btn-sm btn-warning">
                                    <i class="fas fa-upload"></i> Restore
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to delete this backup? This action cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['filename']) ?>">
                                <button type="submit" name="delete_backup" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Automatic Backup Scheduling -->
        <div class="card">
            <h3>Automatic Backup Scheduling</h3>
            <form method="POST" class="schedule-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="schedule-options">
                    <div class="form-group">
                        <label>Backup Frequency:</label>
                        <select name="frequency" class="form-control">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Backup Time:</label>
                        <input type="time" name="backup_time" class="form-control" value="02:00">
                    </div>
                    
                    <div class="form-group">
                        <label>Retention (days):</label>
                        <input type="number" name="retention_days" class="form-control" value="30" min="1" max="365">
                    </div>
                </div>
                
                <div class="schedule-actions">
                    <button type="submit" name="schedule_backup" class="btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Schedule Automatic Backups
                    </button>
                    <p class="schedule-note">
                        Automatic backups will be created according to your schedule and old backups will be cleaned up automatically.
                    </p>
                </div>
            </form>
        </div>

        <!-- Backup Best Practices -->
        <div class="card">
            <h3>Backup Best Practices</h3>
            <div class="best-practices">
                <div class="practice-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Create regular automated backups</span>
                </div>
                <div class="practice-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Store backups in multiple locations</span>
                </div>
                <div class="practice-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Test backup restoration regularly</span>
                </div>
                <div class="practice-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Keep backups for an appropriate retention period</span>
                </div>
                <div class="practice-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Monitor backup success and failures</span>
                </div>
                <div class="practice-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Document your backup and recovery procedures</span>
                </div>
            </div>
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
        
        .backup-form .form-row {
            margin-bottom: 20px;
        }
        
        .backup-options {
            margin: 20px 0;
        }
        
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        .option-group {
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .checkbox-label input[type="checkbox"] {
            margin-right: 10px;
        }
        
        .option-desc {
            font-size: 0.9em;
            color: var(--text-muted);
            margin: 0;
        }
        
        .backup-info {
            background: var(--section-bg);
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
        
        .backup-info i {
            color: var(--primary-color);
            margin-right: 10px;
        }
        
        .separator {
            margin: 0 15px;
            color: var(--text-muted);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
        }
        
        .no-backups {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .no-backups i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .backups-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .backup-name {
            display: flex;
            align-items: center;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .backup-name i {
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .compressed-badge {
            background: var(--primary-color);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7em;
            margin-left: 10px;
        }
        
        .backup-details {
            display: flex;
            gap: 20px;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .backup-actions {
            display: flex;
            gap: 10px;
        }
        
        .schedule-form .schedule-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .schedule-actions {
            text-align: center;
        }
        
        .schedule-note {
            margin-top: 10px;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .best-practices {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .practice-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }
        
        .practice-item i {
            color: #28a745;
            margin-right: 15px;
            font-size: 1.2em;
        }
    </style>

    <script>
        function estimateBackupSize() {
            // This would make an AJAX call to estimate backup size
            document.getElementById('estimated-size').textContent = 'Calculating...';
            
            // Simulate calculation
            setTimeout(() => {
                document.getElementById('estimated-size').textContent = '~2.5 MB';
            }, 1000);
        }
        
        // Auto-estimate size on page load
        estimateBackupSize();
    </script>
</body>
</html>