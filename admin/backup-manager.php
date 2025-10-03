<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$backupType = $_GET['type'] ?? 'files';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle backup actions
if ($_POST) {
    if (isset($_POST['create_backup'])) {
        $type = $_POST['backup_type'];
        $name = $_POST['backup_name'];
        $compression = $_POST['compression'] ?? 'gzip';
        $includePaths = $_POST['include_paths'] ?? [];
        $excludePatterns = $_POST['exclude_patterns'] ?? '';
        
        $result = createBackup($type, $name, $compression, $includePaths, $excludePatterns);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup created successfully: ' . htmlspecialchars($result['file']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger">Backup failed: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['delete_backup'])) {
        $backupFile = $_POST['backup_file'];
        $result = deleteBackup($backupFile);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete backup: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['cleanup_old'])) {
        $days = (int)$_POST['days_old'];
        $result = cleanupOldBackups($days);
        $message = '<div class="alert alert-success">Cleaned up ' . $result['count'] . ' old backups.</div>';
    }
}

// Create backup function
function createBackup($type, $name, $compression = 'gzip', $includePaths = [], $excludePatterns = '') {
    $backupDir = '/var/backups/hosting-panel';
    $timestamp = date('Y-m-d_H-i-s');
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    
    // Ensure backup directory exists
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0750, true);
    }
    
    try {
        switch ($type) {
            case 'files':
                return createFileBackup($backupDir, $safeName, $timestamp, $compression, $includePaths, $excludePatterns);
            
            case 'database':
                return createDatabaseBackup($backupDir, $safeName, $timestamp, $compression);
            
            case 'full':
                return createFullBackup($backupDir, $safeName, $timestamp, $compression, $excludePatterns);
            
            case 'config':
                return createConfigBackup($backupDir, $safeName, $timestamp, $compression);
            
            default:
                return ['success' => false, 'error' => 'Invalid backup type'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function createFileBackup($backupDir, $name, $timestamp, $compression, $includePaths, $excludePatterns) {
    $extension = $compression === 'gzip' ? '.tar.gz' : '.tar';
    $backupFile = "$backupDir/files_{$name}_{$timestamp}$extension";
    
    // Default paths if none specified
    if (empty($includePaths)) {
        $includePaths = ['/var/www', '/home'];
    }
    
    // Build tar command
    $compressFlag = $compression === 'gzip' ? 'z' : '';
    $tarCmd = "tar -c{$compressFlag}f " . escapeshellarg($backupFile);
    
    // Add exclude patterns
    if (!empty($excludePatterns)) {
        $patterns = array_filter(array_map('trim', explode("\n", $excludePatterns)));
        foreach ($patterns as $pattern) {
            $tarCmd .= " --exclude=" . escapeshellarg($pattern);
        }
    }
    
    // Common excludes
    $commonExcludes = ['*.log', '*.tmp', 'cache/*', 'tmp/*', 'node_modules/*'];
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
    
    if ($returnCode === 0) {
        return [
            'success' => true,
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'path' => $backupFile
        ];
    } else {
        return ['success' => false, 'error' => implode("\n", $output)];
    }
}

function createDatabaseBackup($backupDir, $name, $timestamp, $compression) {
    global $pdo;
    
    $extension = $compression === 'gzip' ? '.sql.gz' : '.sql';
    $backupFile = "$backupDir/database_{$name}_{$timestamp}$extension";
    
    // Get database configuration
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
    
    // Create mysqldump command
    $dumpCmd = "mysqldump -h " . escapeshellarg($host) . 
               " -u " . escapeshellarg($username) . 
               " -p" . escapeshellarg($password) . 
               " " . escapeshellarg($dbname);
    
    if ($compression === 'gzip') {
        $dumpCmd .= " | gzip > " . escapeshellarg($backupFile);
    } else {
        $dumpCmd .= " > " . escapeshellarg($backupFile);
    }
    
    exec($dumpCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($backupFile)) {
        return [
            'success' => true,
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'path' => $backupFile
        ];
    } else {
        return ['success' => false, 'error' => implode("\n", $output)];
    }
}

function createFullBackup($backupDir, $name, $timestamp, $compression, $excludePatterns) {
    $extension = $compression === 'gzip' ? '.tar.gz' : '.tar';
    $backupFile = "$backupDir/full_{$name}_{$timestamp}$extension";
    
    // Create database backup first
    $dbResult = createDatabaseBackup($backupDir, $name . '_db', $timestamp, $compression);
    
    // Create file backup
    $includePaths = ['/var/www', '/etc/apache2', '/etc/mysql', '/home'];
    $fileResult = createFileBackup($backupDir, $name . '_files', $timestamp, $compression, $includePaths, $excludePatterns);
    
    if ($dbResult['success'] && $fileResult['success']) {
        // Combine into single archive
        $compressFlag = $compression === 'gzip' ? 'z' : '';
        $combineCmd = "tar -c{$compressFlag}f " . escapeshellarg($backupFile) . 
                      " -C " . escapeshellarg($backupDir) . 
                      " " . escapeshellarg(basename($dbResult['path'])) . 
                      " " . escapeshellarg(basename($fileResult['path']));
        
        exec($combineCmd . " 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            // Clean up individual backups
            unlink($dbResult['path']);
            unlink($fileResult['path']);
            
            return [
                'success' => true,
                'file' => basename($backupFile),
                'size' => filesize($backupFile),
                'path' => $backupFile
            ];
        }
    }
    
    return ['success' => false, 'error' => 'Failed to create full backup'];
}

function createConfigBackup($backupDir, $name, $timestamp, $compression) {
    $extension = $compression === 'gzip' ? '.tar.gz' : '.tar';
    $backupFile = "$backupDir/config_{$name}_{$timestamp}$extension";
    
    $configPaths = [
        '/etc/apache2',
        '/etc/mysql',
        '/etc/php',
        '/etc/ssl/certs',
        dirname(__DIR__) // hosting panel config
    ];
    
    $compressFlag = $compression === 'gzip' ? 'z' : '';
    $tarCmd = "tar -c{$compressFlag}f " . escapeshellarg($backupFile);
    
    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            $tarCmd .= " " . escapeshellarg($path);
        }
    }
    
    exec($tarCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        return [
            'success' => true,
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'path' => $backupFile
        ];
    } else {
        return ['success' => false, 'error' => implode("\n", $output)];
    }
}

// Get existing backups
function getBackupList() {
    $backupDir = '/var/backups/hosting-panel';
    $backups = [];
    
    if (!is_dir($backupDir)) {
        return $backups;
    }
    
    $files = glob($backupDir . '/*.{tar,tar.gz,sql,sql.gz}', GLOB_BRACE);
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $info = [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'created' => filemtime($file),
                'type' => getBackupType(basename($file)),
                'compressed' => strpos($file, '.gz') !== false
            ];
            $backups[] = $info;
        }
    }
    
    // Sort by creation date (newest first)
    usort($backups, function($a, $b) {
        return $b['created'] <=> $a['created'];
    });
    
    return $backups;
}

function getBackupType($filename) {
    if (strpos($filename, 'database_') === 0 || strpos($filename, '.sql') !== false) {
        return 'database';
    } elseif (strpos($filename, 'files_') === 0) {
        return 'files';
    } elseif (strpos($filename, 'full_') === 0) {
        return 'full';
    } elseif (strpos($filename, 'config_') === 0) {
        return 'config';
    } else {
        return 'unknown';
    }
}

function deleteBackup($backupFile) {
    $backupDir = '/var/backups/hosting-panel';
    $fullPath = $backupDir . '/' . basename($backupFile);
    
    if (file_exists($fullPath) && unlink($fullPath)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'File not found or permission denied'];
    }
}

function cleanupOldBackups($days) {
    $backupDir = '/var/backups/hosting-panel';
    $cutoffTime = time() - ($days * 24 * 60 * 60);
    $count = 0;
    
    if (!is_dir($backupDir)) {
        return ['count' => 0];
    }
    
    $files = glob($backupDir . '/*');
    
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoffTime) {
            if (unlink($file)) {
                $count++;
            }
        }
    }
    
    return ['count' => $count];
}

// Get backup statistics
function getBackupStats() {
    $backups = getBackupList();
    $stats = [
        'total_backups' => count($backups),
        'total_size' => 0,
        'by_type' => ['database' => 0, 'files' => 0, 'full' => 0, 'config' => 0],
        'oldest' => null,
        'newest' => null
    ];
    
    foreach ($backups as $backup) {
        $stats['total_size'] += $backup['size'];
        $stats['by_type'][$backup['type']]++;
        
        if ($stats['oldest'] === null || $backup['created'] < $stats['oldest']) {
            $stats['oldest'] = $backup['created'];
        }
        
        if ($stats['newest'] === null || $backup['created'] > $stats['newest']) {
            $stats['newest'] = $backup['created'];
        }
    }
    
    return $stats;
}

$backups = getBackupList();
$stats = getBackupStats();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-save"></i> Backup Manager</h1>
        
        <?= $message ?>
        
        <!-- Backup Statistics -->
        <div class="card">
            <h3>Backup Statistics</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-archive"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['total_backups'] ?></div>
                        <div class="stat-label">Total Backups</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-hdd"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= formatBytes($stats['total_size']) ?></div>
                        <div class="stat-label">Total Size</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-database"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['by_type']['database'] ?></div>
                        <div class="stat-label">Database Backups</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-folder"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['by_type']['files'] ?></div>
                        <div class="stat-label">File Backups</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create New Backup -->
        <div class="card">
            <h3>Create New Backup</h3>
            <form method="POST" class="backup-form" id="backupForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Backup Type:</label>
                        <select name="backup_type" class="form-control" id="backupType" onchange="updateFormFields()" required>
                            <option value="files">Files Only</option>
                            <option value="database">Database Only</option>
                            <option value="config">Configuration Only</option>
                            <option value="full">Full System Backup</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Backup Name:</label>
                        <input type="text" name="backup_name" class="form-control" placeholder="Enter backup name" required>
                        <small class="form-help">Only letters, numbers, hyphens, and underscores allowed</small>
                    </div>
                    <div class="form-group">
                        <label>Compression:</label>
                        <select name="compression" class="form-control">
                            <option value="gzip">GZIP (.gz)</option>
                            <option value="none">No Compression</option>
                        </select>
                    </div>
                </div>
                
                <div id="fileOptions" class="backup-options">
                    <div class="form-group">
                        <label>Include Paths:</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="path_www" value="/var/www" checked>
                                <span class="checkbox-custom"></span>
                                <label for="path_www" class="checkbox-label">
                                    <span class="checkbox-text">Web Files</span>
                                    <span class="checkbox-subtext">/var/www directory</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="path_home" value="/home">
                                <span class="checkbox-custom"></span>
                                <label for="path_home" class="checkbox-label">
                                    <span class="checkbox-text">User Home Directories</span>
                                    <span class="checkbox-subtext">/home directory</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="path_etc" value="/etc">
                                <span class="checkbox-custom"></span>
                                <label for="path_etc" class="checkbox-label">
                                    <span class="checkbox-text">System Configuration</span>
                                    <span class="checkbox-subtext">/etc directory</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="path_opt" value="/opt">
                                <span class="checkbox-custom"></span>
                                <label for="path_opt" class="checkbox-label">
                                    <span class="checkbox-text">Optional Software</span>
                                    <span class="checkbox-subtext">/opt directory</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Exclude Patterns (one per line):</label>
                        <textarea name="exclude_patterns" class="form-control" rows="4" placeholder="*.log&#10;*.tmp&#10;cache/*&#10;node_modules/*"></textarea>
                        <small class="form-help">Shell wildcard patterns to exclude from backup</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="create_backup" class="btn btn-primary">
                        <i class="fas fa-play"></i> Create Backup
                    </button>
                    <button type="button" onclick="showScheduleModal()" class="btn btn-secondary">
                        <i class="fas fa-clock"></i> Schedule Backup
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing Backups -->
        <div class="card">
            <h3>Existing Backups</h3>
            
            <div class="backup-actions">
                <form method="POST" style="display: inline;" onsubmit="return confirm('This will delete all backups older than the specified days. Continue?')">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="cleanup-form">
                        <input type="number" name="days_old" min="1" max="365" value="30" class="form-control" style="width: 80px;">
                        <span>days old</span>
                        <button type="submit" name="cleanup_old" class="btn btn-warning">
                            <i class="fas fa-broom"></i> Cleanup Old
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if (empty($backups)): ?>
                <div class="alert alert-info">No backups found. Create your first backup above.</div>
            <?php else: ?>
                <div class="backup-list">
                    <div class="backup-header">
                        <div>Backup Name</div>
                        <div>Type</div>
                        <div>Size</div>
                        <div>Created</div>
                        <div>Actions</div>
                    </div>
                    <?php foreach ($backups as $backup): ?>
                    <div class="backup-item">
                        <div class="backup-name">
                            <i class="fas fa-<?= getBackupIcon($backup['type']) ?>"></i>
                            <?= htmlspecialchars($backup['filename']) ?>
                            <?php if ($backup['compressed']): ?>
                                <span class="compressed-badge">GZIP</span>
                            <?php endif; ?>
                        </div>
                        <div class="backup-type">
                            <span class="type-badge type-<?= $backup['type'] ?>">
                                <?= strtoupper($backup['type']) ?>
                            </span>
                        </div>
                        <div class="backup-size"><?= formatBytes($backup['size']) ?></div>
                        <div class="backup-date" title="<?= date('Y-m-d H:i:s', $backup['created']) ?>">
                            <?= date('M j, Y H:i', $backup['created']) ?>
                        </div>
                        <div class="backup-actions">
                            <a href="download-backup.php?file=<?= urlencode($backup['filename']) ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-download"></i> Download
                            </a>
                            <button onclick="showRestoreModal('<?= htmlspecialchars($backup['filename']) ?>', '<?= $backup['type'] ?>')" class="btn btn-sm btn-success">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this backup?')">
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
    </div>

    <!-- Schedule Backup Modal -->
    <div id="scheduleModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('scheduleModal')">&times;</span>
            <h3>Schedule Backup</h3>
            <div class="modal-body">
                <p>Backup scheduling will be configured through the Backup Scheduler.</p>
                <a href="backup-scheduler.php" class="btn btn-primary">
                    <i class="fas fa-calendar"></i> Go to Backup Scheduler
                </a>
            </div>
        </div>
    </div>

    <!-- Restore Backup Modal -->
    <div id="restoreModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('restoreModal')">&times;</span>
            <h3>Restore Backup</h3>
            <div class="modal-body">
                <div class="restore-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action will restore data from the selected backup. 
                    Current data may be overwritten. Please ensure you have a recent backup before proceeding.
                </div>
                <p id="restoreInfo"></p>
                <div class="restore-options">
                    <label><input type="checkbox" id="confirmRestore"> I understand the risks and want to proceed</label>
                </div>
                <div class="restore-actions">
                    <button onclick="performRestore()" class="btn btn-danger" disabled id="restoreBtn">
                        <i class="fas fa-undo"></i> Restore Backup
                    </button>
                    <button onclick="closeModal('restoreModal')" class="btn btn-secondary">Cancel</button>
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
        
        .backup-form .form-row {
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
        
        .form-help {
            font-size: 0.8em;
            color: var(--text-muted);
            margin-top: 5px;
        }
        
        .backup-options {
            border-top: 1px solid var(--border-color);
            padding-top: 20px;
            margin-top: 20px;
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
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-start;
        }
        
        .backup-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .cleanup-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .backup-list {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .backup-header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr;
            gap: 15px;
            padding: 15px;
            background: var(--section-bg);
            font-weight: bold;
            border-bottom: 1px solid var(--border-color);
        }
        
        .backup-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            align-items: center;
        }
        
        .backup-item:last-child {
            border-bottom: none;
        }
        
        .backup-item:hover {
            background: var(--hover-bg);
        }
        
        .backup-name {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: monospace;
        }
        
        .compressed-badge {
            background: #4caf50;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7em;
        }
        
        .type-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7em;
            font-weight: bold;
        }
        
        .type-database { background: #2196f3; color: white; }
        .type-files { background: #ff9800; color: white; }
        .type-full { background: #9c27b0; color: white; }
        .type-config { background: #4caf50; color: white; }
        
        .backup-actions {
            display: flex;
            gap: 5px;
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
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
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
        
        .restore-options {
            margin: 20px 0;
        }
        
        .restore-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
        }
        
        .restore-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
    </style>

    <script>
        let selectedBackupFile = '';
        let selectedBackupType = '';
        
        function updateFormFields() {
            const backupType = document.getElementById('backupType').value;
            const fileOptions = document.getElementById('fileOptions');
            
            if (backupType === 'files' || backupType === 'full') {
                fileOptions.style.display = 'block';
            } else {
                fileOptions.style.display = 'none';
            }
        }
        
        function showScheduleModal() {
            document.getElementById('scheduleModal').style.display = 'block';
        }
        
        function showRestoreModal(filename, type) {
            selectedBackupFile = filename;
            selectedBackupType = type;
            
            document.getElementById('restoreInfo').innerHTML = 
                `<strong>Backup:</strong> ${filename}<br><strong>Type:</strong> ${type.toUpperCase()}`;
            
            document.getElementById('restoreModal').style.display = 'block';
            document.getElementById('restoreBtn').disabled = true;
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function performRestore() {
            if (!document.getElementById('confirmRestore').checked) {
                alert('Please confirm that you understand the risks.');
                return;
            }
            
            // In a real implementation, this would make an AJAX request to restore the backup
            alert('Restore functionality will be implemented in the Restore Manager.');
            closeModal('restoreModal');
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
            const modals = ['scheduleModal', 'restoreModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Initialize form
        updateFormFields();
    </script>
</body>
</html>

<?php
function getBackupIcon($type) {
    switch ($type) {
        case 'database': return 'database';
        case 'files': return 'folder';
        case 'full': return 'archive';
        case 'config': return 'cog';
        default: return 'file';
    }
}
?>