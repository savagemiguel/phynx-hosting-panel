<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$selectedBackup = $_GET['backup'] ?? '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle restore actions
if ($_POST) {
    if (isset($_POST['restore_backup'])) {
        $result = restoreBackup($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup restore completed successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Restore failed: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['verify_backup'])) {
        $backupFile = $_POST['backup_file'];
        $result = verifyBackup($backupFile);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup verification completed. ' . htmlspecialchars($result['message']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger">Backup verification failed: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Get available backups
function getAvailableBackups() {
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
                'compressed' => strpos($file, '.gz') !== false,
                'contents' => getBackupContents($file)
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

// Get backup contents (list files in archive)
function getBackupContents($backupPath) {
    $contents = [];
    $isCompressed = strpos($backupPath, '.gz') !== false;
    
    if (strpos($backupPath, '.sql') !== false) {
        // SQL backup - show database info
        $contents[] = [
            'type' => 'database',
            'name' => 'Database dump',
            'size' => filesize($backupPath)
        ];
    } elseif (strpos($backupPath, '.tar') !== false) {
        // Archive backup - list contents
        $tarCmd = $isCompressed ? 'tar -tzf' : 'tar -tf';
        $output = [];
        exec("$tarCmd " . escapeshellarg($backupPath) . " | head -20", $output);
        
        foreach ($output as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $contents[] = [
                    'type' => 'file',
                    'name' => $line,
                    'size' => 0 // Size not easily available from tar listing
                ];
            }
        }
    }
    
    return array_slice($contents, 0, 10); // Limit to first 10 items
}

// Verify backup integrity
function verifyBackup($backupFile) {
    $backupDir = '/var/backups/hosting-panel';
    $fullPath = $backupDir . '/' . basename($backupFile);
    
    if (!file_exists($fullPath)) {
        return ['success' => false, 'error' => 'Backup file not found'];
    }
    
    $isCompressed = strpos($fullPath, '.gz') !== false;
    $isSql = strpos($fullPath, '.sql') !== false;
    
    try {
        if ($isSql && $isCompressed) {
            // Verify gzipped SQL file
            exec("gzip -t " . escapeshellarg($fullPath) . " 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                return ['success' => false, 'error' => 'Compressed file is corrupted'];
            }
            
            // Check SQL syntax
            exec("zcat " . escapeshellarg($fullPath) . " | head -10 | grep -i 'CREATE\\|INSERT\\|DROP'", $sqlOutput);
            if (empty($sqlOutput)) {
                return ['success' => false, 'error' => 'File does not contain valid SQL'];
            }
            
            return ['success' => true, 'message' => 'SQL backup verified successfully'];
            
        } elseif ($isSql) {
            // Verify uncompressed SQL file
            exec("head -10 " . escapeshellarg($fullPath) . " | grep -i 'CREATE\\|INSERT\\|DROP'", $sqlOutput);
            if (empty($sqlOutput)) {
                return ['success' => false, 'error' => 'File does not contain valid SQL'];
            }
            
            return ['success' => true, 'message' => 'SQL backup verified successfully'];
            
        } elseif (strpos($fullPath, '.tar') !== false) {
            // Verify tar archive
            $tarCmd = $isCompressed ? 'tar -tzf' : 'tar -tf';
            exec("$tarCmd " . escapeshellarg($fullPath) . " >/dev/null 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0) {
                return ['success' => false, 'error' => 'Archive is corrupted or unreadable'];
            }
            
            return ['success' => true, 'message' => 'Archive backup verified successfully'];
        }
        
        return ['success' => true, 'message' => 'Backup file appears to be valid'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Restore backup
function restoreBackup($data) {
    $backupFile = $data['backup_file'];
    $restoreType = $data['restore_type'];
    $restoreLocation = $data['restore_location'] ?? '';
    $overwriteExisting = isset($data['overwrite_existing']);
    
    $backupDir = '/var/backups/hosting-panel';
    $fullPath = $backupDir . '/' . basename($backupFile);
    
    if (!file_exists($fullPath)) {
        return ['success' => false, 'error' => 'Backup file not found'];
    }
    
    try {
        $backupType = getBackupType($backupFile);
        
        switch ($backupType) {
            case 'database':
                return restoreDatabase($fullPath, $overwriteExisting);
            
            case 'files':
                return restoreFiles($fullPath, $restoreLocation, $overwriteExisting);
            
            case 'config':
                return restoreConfig($fullPath, $overwriteExisting);
            
            case 'full':
                return restoreFull($fullPath, $overwriteExisting);
            
            default:
                return ['success' => false, 'error' => 'Unknown backup type'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function restoreDatabase($backupPath, $overwriteExisting) {
    global $pdo;
    
    $isCompressed = strpos($backupPath, '.gz') !== false;
    
    // Get database configuration
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
    
    if (!$overwriteExisting) {
        // Create backup of current database first
        $timestamp = date('Y-m-d_H-i-s');
        $currentBackup = "/tmp/pre_restore_backup_{$timestamp}.sql";
        
        $dumpCmd = "mysqldump -h " . escapeshellarg($host) . 
                   " -u " . escapeshellarg($username) . 
                   " -p" . escapeshellarg($password) . 
                   " " . escapeshellarg($dbname) . 
                   " > " . escapeshellarg($currentBackup);
        
        exec($dumpCmd . " 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            return ['success' => false, 'error' => 'Failed to backup current database'];
        }
    }
    
    // Restore database
    if ($isCompressed) {
        $restoreCmd = "zcat " . escapeshellarg($backupPath) . 
                      " | mysql -h " . escapeshellarg($host) . 
                      " -u " . escapeshellarg($username) . 
                      " -p" . escapeshellarg($password) . 
                      " " . escapeshellarg($dbname);
    } else {
        $restoreCmd = "mysql -h " . escapeshellarg($host) . 
                      " -u " . escapeshellarg($username) . 
                      " -p" . escapeshellarg($password) . 
                      " " . escapeshellarg($dbname) . 
                      " < " . escapeshellarg($backupPath);
    }
    
    exec($restoreCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        return ['success' => true, 'message' => 'Database restored successfully'];
    } else {
        return ['success' => false, 'error' => 'Database restore failed: ' . implode("\n", $output)];
    }
}

function restoreFiles($backupPath, $restoreLocation, $overwriteExisting) {
    $isCompressed = strpos($backupPath, '.gz') !== false;
    
    // Default restore location
    if (empty($restoreLocation)) {
        $restoreLocation = '/';
    }
    
    // Ensure restore location exists and is writable
    if (!is_dir($restoreLocation)) {
        mkdir($restoreLocation, 0755, true);
    }
    
    if (!is_writable($restoreLocation)) {
        return ['success' => false, 'error' => 'Restore location is not writable'];
    }
    
    // Extract files
    $tarCmd = $isCompressed ? 'tar -xzf' : 'tar -xf';
    
    if (!$overwriteExisting) {
        $tarCmd .= ' --keep-old-files';
    }
    
    $extractCmd = "$tarCmd " . escapeshellarg($backupPath) . " -C " . escapeshellarg($restoreLocation);
    
    exec($extractCmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        return ['success' => true, 'message' => 'Files restored successfully'];
    } else {
        return ['success' => false, 'error' => 'File restore failed: ' . implode("\n", $output)];
    }
}

function restoreConfig($backupPath, $overwriteExisting) {
    return restoreFiles($backupPath, '/', $overwriteExisting);
}

function restoreFull($backupPath, $overwriteExisting) {
    // Full backup contains both database and files
    // This would need to be extracted to a temporary location first
    // then restored in parts
    
    $tempDir = '/tmp/full_restore_' . time();
    mkdir($tempDir, 0755, true);
    
    $isCompressed = strpos($backupPath, '.gz') !== false;
    $tarCmd = $isCompressed ? 'tar -xzf' : 'tar -xf';
    
    // Extract full backup to temp directory
    exec("$tarCmd " . escapeshellarg($backupPath) . " -C " . escapeshellarg($tempDir), $output, $returnCode);
    
    if ($returnCode !== 0) {
        return ['success' => false, 'error' => 'Failed to extract full backup'];
    }
    
    // Find and restore database backup
    $dbBackups = glob($tempDir . '/*_db_*.{sql,sql.gz}', GLOB_BRACE);
    if (!empty($dbBackups)) {
        $dbResult = restoreDatabase($dbBackups[0], $overwriteExisting);
        if (!$dbResult['success']) {
            return $dbResult;
        }
    }
    
    // Find and restore file backup
    $fileBackups = glob($tempDir . '/*_files_*.{tar,tar.gz}', GLOB_BRACE);
    if (!empty($fileBackups)) {
        $fileResult = restoreFiles($fileBackups[0], '/', $overwriteExisting);
        if (!$fileResult['success']) {
            return $fileResult;
        }
    }
    
    // Cleanup
    exec("rm -rf " . escapeshellarg($tempDir));
    
    return ['success' => true, 'message' => 'Full system restore completed successfully'];
}

$backups = getAvailableBackups();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-undo"></i> Restore Manager</h1>
        
        <?= $message ?>
        
        <!-- Restore Overview -->
        <div class="card">
            <h3>Backup Restore Overview</h3>
            <div class="restore-overview">
                <div class="overview-item">
                    <div class="overview-icon"><i class="fas fa-archive"></i></div>
                    <div class="overview-info">
                        <div class="overview-title">Available Backups</div>
                        <div class="overview-count"><?= count($backups) ?></div>
                        <div class="overview-description">Backup files ready for restoration</div>
                    </div>
                </div>
                <div class="overview-item">
                    <div class="overview-icon"><i class="fas fa-database"></i></div>
                    <div class="overview-info">
                        <div class="overview-title">Database Backups</div>
                        <div class="overview-count"><?= count(array_filter($backups, function($b) { return $b['type'] === 'database'; })) ?></div>
                        <div class="overview-description">Database restore options</div>
                    </div>
                </div>
                <div class="overview-item">
                    <div class="overview-icon"><i class="fas fa-folder"></i></div>
                    <div class="overview-info">
                        <div class="overview-title">File Backups</div>
                        <div class="overview-count"><?= count(array_filter($backups, function($b) { return $b['type'] === 'files'; })) ?></div>
                        <div class="overview-description">File system restore options</div>
                    </div>
                </div>
                <div class="overview-item">
                    <div class="overview-icon"><i class="fas fa-server"></i></div>
                    <div class="overview-info">
                        <div class="overview-title">Full Backups</div>
                        <div class="overview-count"><?= count(array_filter($backups, function($b) { return $b['type'] === 'full'; })) ?></div>
                        <div class="overview-description">Complete system backups</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Backups -->
        <div class="card">
            <h3>Available Backup Files</h3>
            
            <?php if (empty($backups)): ?>
                <div class="alert alert-info">
                    No backup files found. <a href="backup-manager.php">Create some backups</a> first.
                </div>
            <?php else: ?>
                <div class="backup-list">
                    <?php foreach ($backups as $backup): ?>
                    <div class="backup-card">
                        <div class="backup-header">
                            <div class="backup-info">
                                <div class="backup-name">
                                    <i class="fas fa-<?= getBackupIcon($backup['type']) ?>"></i>
                                    <?= htmlspecialchars($backup['filename']) ?>
                                </div>
                                <div class="backup-meta">
                                    <span class="type-badge type-<?= $backup['type'] ?>">
                                        <?= strtoupper($backup['type']) ?>
                                    </span>
                                    <span class="backup-size"><?= formatBytes($backup['size']) ?></span>
                                    <span class="backup-date"><?= date('M j, Y H:i', $backup['created']) ?></span>
                                    <?php if ($backup['compressed']): ?>
                                        <span class="compression-badge">GZIP</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="backup-actions">
                                <button onclick="showRestoreModal('<?= htmlspecialchars($backup['filename']) ?>', '<?= $backup['type'] ?>')" class="btn btn-success">
                                    <i class="fas fa-undo"></i> Restore
                                </button>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['filename']) ?>">
                                    <button type="submit" name="verify_backup" class="btn btn-info">
                                        <i class="fas fa-check"></i> Verify
                                    </button>
                                </form>
                                <button onclick="showBackupContents('<?= htmlspecialchars($backup['filename']) ?>')" class="btn btn-secondary">
                                    <i class="fas fa-eye"></i> Contents
                                </button>
                            </div>
                        </div>
                        
                        <?php if (!empty($backup['contents'])): ?>
                        <div class="backup-contents" id="contents-<?= md5($backup['filename']) ?>" style="display: none;">
                            <h5>Backup Contents (Preview):</h5>
                            <div class="contents-list">
                                <?php foreach ($backup['contents'] as $item): ?>
                                <div class="content-item">
                                    <i class="fas fa-<?= $item['type'] === 'database' ? 'database' : 'file' ?>"></i>
                                    <span class="content-name"><?= htmlspecialchars($item['name']) ?></span>
                                    <?php if ($item['size'] > 0): ?>
                                        <span class="content-size"><?= formatBytes($item['size']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (count($backup['contents']) >= 10): ?>
                                <div class="content-item">
                                    <i class="fas fa-ellipsis-h"></i>
                                    <span class="content-name">... and more files</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Restore Modal -->
    <div id="restoreModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('restoreModal')">&times;</span>
            <h3>Restore Backup</h3>
            <form method="POST" id="restoreForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="backup_file" id="restoreBackupFile">
                
                <div class="restore-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action will restore data from the selected backup. 
                    Current data may be overwritten or modified. Please ensure you have a recent backup 
                    of your current system before proceeding.
                </div>
                
                <div class="restore-info" id="restoreInfo"></div>
                
                <div class="restore-options">
                    <div class="form-group" id="restoreLocationGroup">
                        <label>Restore Location:</label>
                        <input type="text" name="restore_location" class="form-control" placeholder="Leave empty for original location">
                        <small class="form-help">Specify custom location or leave empty to restore to original paths</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="overwrite_existing">
                            Overwrite existing files/data
                        </label>
                        <small class="form-help">If unchecked, existing files will be preserved</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="confirmRestore">
                            I understand the risks and want to proceed with the restore
                        </label>
                    </div>
                </div>
                
                <div class="restore-actions">
                    <button type="submit" name="restore_backup" class="btn btn-danger" disabled id="restoreBtn">
                        <i class="fas fa-undo"></i> Restore Backup
                    </button>
                    <button type="button" onclick="closeModal('restoreModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .restore-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .overview-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .overview-icon {
            font-size: 2.5em;
            margin-right: 20px;
            color: var(--primary-color);
            opacity: 0.8;
        }
        
        .overview-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .overview-count {
            font-size: 2em;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .overview-description {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .backup-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .backup-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            background: var(--card-bg);
        }
        
        .backup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
        }
        
        .backup-info {
            flex: 1;
        }
        
        .backup-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .backup-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
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
        
        .compression-badge {
            background: #4caf50;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7em;
        }
        
        .backup-size,
        .backup-date {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .backup-actions {
            display: flex;
            gap: 10px;
        }
        
        .backup-contents {
            border-top: 1px solid var(--border-color);
            padding: 20px;
            background: var(--section-bg);
        }
        
        .backup-contents h5 {
            margin-bottom: 15px;
            color: var(--text-muted);
        }
        
        .contents-list {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .content-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .content-item:last-child {
            border-bottom: none;
        }
        
        .content-name {
            flex: 1;
            font-family: monospace;
            font-size: 0.9em;
        }
        
        .content-size {
            font-size: 0.8em;
            color: var(--text-muted);
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
        
        .restore-options .form-group {
            margin-bottom: 15px;
        }
        
        .restore-options label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .checkbox-label {
            display: flex !important;
            align-items: center;
            gap: 8px;
            font-weight: normal !important;
        }
        
        .form-help {
            font-size: 0.8em;
            color: var(--text-muted);
            margin-top: 5px;
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
        
        function showRestoreModal(filename, type) {
            selectedBackupFile = filename;
            selectedBackupType = type;
            
            document.getElementById('restoreBackupFile').value = filename;
            document.getElementById('restoreInfo').innerHTML = 
                `<strong>Backup File:</strong> ${filename}<br><strong>Type:</strong> ${type.toUpperCase()}`;
            
            // Show/hide restore location based on type
            const locationGroup = document.getElementById('restoreLocationGroup');
            if (type === 'files') {
                locationGroup.style.display = 'block';
            } else {
                locationGroup.style.display = 'none';
            }
            
            document.getElementById('restoreModal').style.display = 'block';
            document.getElementById('restoreBtn').disabled = true;
            document.getElementById('confirmRestore').checked = false;
        }
        
        function showBackupContents(filename) {
            const contentId = 'contents-' + btoa(filename).replace(/[^a-zA-Z0-9]/g, '');
            const contentsDiv = document.getElementById(contentId);
            
            if (contentsDiv) {
                if (contentsDiv.style.display === 'none') {
                    contentsDiv.style.display = 'block';
                } else {
                    contentsDiv.style.display = 'none';
                }
            }
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