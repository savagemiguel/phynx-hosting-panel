<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$scanPath = $_GET['path'] ?? '/var/www/html';
$scanPath = realpath($scanPath) ?: '/var/www/html';

// Security: Ensure path is within allowed directories
$allowedPaths = ['/var/www', '/home', '/tmp', '/opt'];
$isAllowed = false;
foreach ($allowedPaths as $allowed) {
    if (strpos($scanPath, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    $scanPath = '/var/www/html';
}

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle disk operations
if ($_POST) {
    if (isset($_POST['cleanup_logs'])) {
        $result = cleanupLogs($_POST['log_paths']);
        $message = $result['success'] 
            ? '<div class="alert alert-success">Log cleanup completed. ' . formatFileSize($result['freed']) . ' freed.</div>'
            : '<div class="alert alert-danger">Cleanup failed: ' . htmlspecialchars($result['error']) . '</div>';
    } elseif (isset($_POST['cleanup_temp'])) {
        $result = cleanupTempFiles($_POST['temp_paths']);
        $message = $result['success'] 
            ? '<div class="alert alert-success">Temporary files cleaned. ' . formatFileSize($result['freed']) . ' freed.</div>'
            : '<div class="alert alert-danger">Cleanup failed: ' . htmlspecialchars($result['error']) . '</div>';
    } elseif (isset($_POST['delete_large_files'])) {
        $result = deleteLargeFiles($_POST['file_paths']);
        $message = $result['success'] 
            ? '<div class="alert alert-success">' . $result['deleted'] . ' large files deleted. ' . formatFileSize($result['freed']) . ' freed.</div>'
            : '<div class="alert alert-danger">Delete failed: ' . htmlspecialchars($result['error']) . '</div>';
    }
}

// Get system disk usage
function getSystemDiskUsage() {
    $usage = [];
    
    // Get disk usage for main partitions
    $partitions = [
        '/' => 'Root Partition',
        '/var' => 'Var Partition', 
        '/home' => 'Home Partition',
        '/tmp' => 'Temp Partition'
    ];
    
    foreach ($partitions as $path => $label) {
        if (is_dir($path)) {
            $total = disk_total_space($path);
            $free = disk_free_space($path);
            $used = $total - $free;
            
            $usage[] = [
                'path' => $path,
                'label' => $label,
                'total' => $total,
                'used' => $used,
                'free' => $free,
                'percent' => $total > 0 ? ($used / $total) * 100 : 0
            ];
        }
    }
    
    return $usage;
}

// Get directory sizes
function getDirectorySizes($basePath, $maxDepth = 2) {
    $sizes = [];
    
    try {
        $iterator = new RecursiveDirectoryIterator($basePath);
        $iterator = new RecursiveIteratorIterator($iterator);
        
        $dirSizes = [];
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $dir = dirname($file->getPathname());
                $relativeDir = str_replace($basePath, '', $dir);
                $depth = substr_count($relativeDir, DIRECTORY_SEPARATOR);
                
                if ($depth <= $maxDepth) {
                    if (!isset($dirSizes[$dir])) {
                        $dirSizes[$dir] = ['size' => 0, 'files' => 0];
                    }
                    $dirSizes[$dir]['size'] += $file->getSize();
                    $dirSizes[$dir]['files']++;
                }
            }
        }
        
        foreach ($dirSizes as $path => $data) {
            $relativePath = str_replace($basePath, '', $path) ?: '/';
            $sizes[] = [
                'path' => $path,
                'relative_path' => $relativePath,
                'size' => $data['size'],
                'files' => $data['files']
            ];
        }
        
        // Sort by size descending
        usort($sizes, function($a, $b) {
            return $b['size'] - $a['size'];
        });
        
    } catch (Exception $e) {
        // Handle permission errors gracefully
    }
    
    return array_slice($sizes, 0, 20); // Return top 20
}

// Find large files
function findLargeFiles($basePath, $minSize = 100 * 1024 * 1024) { // 100MB default
    $largeFiles = [];
    
    try {
        $iterator = new RecursiveDirectoryIterator($basePath);
        $iterator = new RecursiveIteratorIterator($iterator);
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getSize() >= $minSize) {
                $largeFiles[] = [
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'extension' => pathinfo($file->getFilename(), PATHINFO_EXTENSION)
                ];
            }
        }
        
        // Sort by size descending
        usort($largeFiles, function($a, $b) {
            return $b['size'] - $a['size'];
        });
        
    } catch (Exception $e) {
        // Handle permission errors gracefully
    }
    
    return array_slice($largeFiles, 0, 50); // Return top 50
}

// Find duplicate files
function findDuplicateFiles($basePath) {
    $duplicates = [];
    $hashes = [];
    
    try {
        $iterator = new RecursiveDirectoryIterator($basePath);
        $iterator = new RecursiveIteratorIterator($iterator);
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getSize() > 1024) { // Only check files > 1KB
                $hash = md5_file($file->getPathname());
                
                if (!isset($hashes[$hash])) {
                    $hashes[$hash] = [];
                }
                
                $hashes[$hash][] = [
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime()
                ];
            }
        }
        
        // Find groups with multiple files
        foreach ($hashes as $hash => $files) {
            if (count($files) > 1) {
                $totalSize = array_sum(array_column($files, 'size'));
                $wastedSpace = $totalSize - $files[0]['size']; // Size that could be freed
                
                $duplicates[] = [
                    'hash' => $hash,
                    'files' => $files,
                    'count' => count($files),
                    'size' => $files[0]['size'],
                    'wasted_space' => $wastedSpace
                ];
            }
        }
        
        // Sort by wasted space descending
        usort($duplicates, function($a, $b) {
            return $b['wasted_space'] - $a['wasted_space'];
        });
        
    } catch (Exception $e) {
        // Handle permission errors gracefully
    }
    
    return array_slice($duplicates, 0, 20); // Return top 20 groups
}

// Get cleanup suggestions
function getCleanupSuggestions() {
    $suggestions = [];
    
    // Log files
    $logPaths = [
        '/var/log/apache2' => 'Apache Log Files',
        '/var/log/nginx' => 'Nginx Log Files',
        '/var/log/mysql' => 'MySQL Log Files',
        '/var/log/php' => 'PHP Log Files'
    ];
    
    foreach ($logPaths as $path => $description) {
        if (is_dir($path)) {
            $size = getDirectorySize($path);
            if ($size > 100 * 1024 * 1024) { // > 100MB
                $suggestions[] = [
                    'type' => 'logs',
                    'path' => $path,
                    'description' => $description,
                    'size' => $size,
                    'potential_savings' => $size * 0.8, // Estimate 80% can be cleaned
                    'action' => 'cleanup_logs'
                ];
            }
        }
    }
    
    // Temporary files
    $tempPaths = [
        '/tmp' => 'System Temporary Files',
        '/var/tmp' => 'Variable Temporary Files',
        '/var/cache' => 'System Cache Files'
    ];
    
    foreach ($tempPaths as $path => $description) {
        if (is_dir($path)) {
            $size = getDirectorySize($path);
            if ($size > 50 * 1024 * 1024) { // > 50MB
                $suggestions[] = [
                    'type' => 'temp',
                    'path' => $path,
                    'description' => $description,
                    'size' => $size,
                    'potential_savings' => $size * 0.9, // Estimate 90% can be cleaned
                    'action' => 'cleanup_temp'
                ];
            }
        }
    }
    
    return $suggestions;
}

// Calculate directory size
function getDirectorySize($directory) {
    $size = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    } catch (Exception $e) {
        // Handle permission errors
    }
    
    return $size;
}

// Cleanup functions
function cleanupLogs($logPaths) {
    $totalFreed = 0;
    
    foreach ($logPaths as $path) {
        if (is_dir($path)) {
            $files = glob($path . '/*.log*');
            foreach ($files as $file) {
                if (filemtime($file) < strtotime('-30 days')) { // Older than 30 days
                    $size = filesize($file);
                    if (unlink($file)) {
                        $totalFreed += $size;
                    }
                }
            }
        }
    }
    
    return ['success' => true, 'freed' => $totalFreed];
}

function cleanupTempFiles($tempPaths) {
    $totalFreed = 0;
    
    foreach ($tempPaths as $path) {
        if (is_dir($path)) {
            $files = glob($path . '/*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < strtotime('-7 days')) { // Older than 7 days
                    $size = filesize($file);
                    if (unlink($file)) {
                        $totalFreed += $size;
                    }
                }
            }
        }
    }
    
    return ['success' => true, 'freed' => $totalFreed];
}

function deleteLargeFiles($filePaths) {
    $totalFreed = 0;
    $deleted = 0;
    
    foreach ($filePaths as $path) {
        if (is_file($path)) {
            $size = filesize($path);
            if (unlink($path)) {
                $totalFreed += $size;
                $deleted++;
            }
        }
    }
    
    return ['success' => true, 'freed' => $totalFreed, 'deleted' => $deleted];
}

// Format file size
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', '') . ' ' . $units[$power];
}

$systemUsage = getSystemDiskUsage();
$directorySizes = getDirectorySizes($scanPath);
$largeFiles = findLargeFiles($scanPath);
$duplicateFiles = findDuplicateFiles($scanPath);
$cleanupSuggestions = getCleanupSuggestions();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disk Usage Analyzer - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-hdd"></i> Disk Usage Analyzer</h1>
        
        <?= $message ?>
        
        <!-- Disk Usage Overview -->
        <div class="overview-section">
            <h2>System Disk Usage</h2>
            <div class="disk-grid">
                <?php foreach ($systemUsage as $disk): ?>
                <div class="disk-card">
                    <div class="disk-header">
                        <h3><?= htmlspecialchars($disk['label']) ?></h3>
                        <span class="disk-path"><?= htmlspecialchars($disk['path']) ?></span>
                    </div>
                    <div class="disk-usage">
                        <div class="usage-bar">
                            <div class="usage-fill" style="width: <?= min($disk['percent'], 100) ?>%"></div>
                        </div>
                        <div class="usage-text">
                            <?= number_format($disk['percent'], 1) ?>% used
                        </div>
                    </div>
                    <div class="disk-stats">
                        <div class="stat-item">
                            <span class="stat-label">Used:</span>
                            <span class="stat-value"><?= formatFileSize($disk['used']) ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Free:</span>
                            <span class="stat-value"><?= formatFileSize($disk['free']) ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total:</span>
                            <span class="stat-value"><?= formatFileSize($disk['total']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Scan Controls -->
        <div class="scan-controls card">
            <h3>Directory Scan</h3>
            <div class="scan-form">
                <div class="path-input">
                    <label>Scan Path:</label>
                    <input type="text" id="scanPath" value="<?= htmlspecialchars($scanPath) ?>" class="form-control">
                    <button onclick="scanDirectory()" class="btn btn-primary">
                        <i class="fas fa-search"></i> Scan Directory
                    </button>
                </div>
                <div class="scan-options">
                    <label class="checkbox-label">
                        <input type="checkbox" id="includeHidden" checked>
                        Include Hidden Files
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="followSymlinks">
                        Follow Symbolic Links
                    </label>
                </div>
            </div>
        </div>

        <!-- Directory Sizes -->
        <div class="card">
            <div class="section-header">
                <h3>Directory Sizes</h3>
                <span class="current-path">Scanning: <?= htmlspecialchars($scanPath) ?></span>
            </div>
            
            <?php if (empty($directorySizes)): ?>
                <div class="no-data">
                    <i class="fas fa-folder-open"></i>
                    <p>No directories found or insufficient permissions.</p>
                </div>
            <?php else: ?>
                <div class="directory-chart-container">
                    <canvas id="directoryChart" width="400" height="200"></canvas>
                </div>
                <div class="directory-list">
                    <?php foreach ($directorySizes as $index => $dir): ?>
                    <div class="directory-item" data-path="<?= htmlspecialchars($dir['path']) ?>">
                        <div class="directory-info">
                            <div class="directory-name">
                                <i class="fas fa-folder"></i>
                                <span class="path"><?= htmlspecialchars($dir['relative_path']) ?></span>
                            </div>
                            <div class="directory-stats">
                                <span class="size"><?= formatFileSize($dir['size']) ?></span>
                                <span class="files"><?= number_format($dir['files']) ?> files</span>
                            </div>
                        </div>
                        <div class="directory-actions">
                            <button onclick="drillDown('<?= htmlspecialchars($dir['path']) ?>')" class="btn btn-sm btn-info">
                                <i class="fas fa-search-plus"></i> Drill Down
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Large Files -->
        <div class="card">
            <div class="section-header">
                <h3>Large Files (>100MB)</h3>
                <div class="header-actions">
                    <button onclick="showBulkDeleteModal()" class="btn btn-sm btn-danger" 
                            <?= empty($largeFiles) ? 'disabled' : '' ?>>
                        <i class="fas fa-trash-alt"></i> Bulk Delete
                    </button>
                </div>
            </div>
            
            <?php if (empty($largeFiles)): ?>
                <div class="no-data">
                    <i class="fas fa-file"></i>
                    <p>No large files found in this directory.</p>
                </div>
            <?php else: ?>
                <div class="large-files-list">
                    <?php foreach ($largeFiles as $file): ?>
                    <div class="file-item">
                        <div class="file-checkbox">
                            <input type="checkbox" class="large-file-checkbox" 
                                   value="<?= htmlspecialchars($file['path']) ?>">
                        </div>
                        <div class="file-info">
                            <div class="file-name">
                                <i class="fas fa-file"></i>
                                <span class="path" title="<?= htmlspecialchars($file['path']) ?>">
                                    <?= htmlspecialchars(basename($file['path'])) ?>
                                </span>
                                <span class="extension">.<?= htmlspecialchars($file['extension']) ?></span>
                            </div>
                            <div class="file-meta">
                                <span class="file-size"><?= formatFileSize($file['size']) ?></span>
                                <span class="file-modified">Modified: <?= date('M j, Y', $file['modified']) ?></span>
                                <span class="file-path"><?= htmlspecialchars(dirname($file['path'])) ?></span>
                            </div>
                        </div>
                        <div class="file-actions">
                            <button onclick="deleteFile('<?= htmlspecialchars($file['path']) ?>')" 
                                    class="btn btn-sm btn-danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Duplicate Files -->
        <div class="card">
            <div class="section-header">
                <h3>Duplicate Files</h3>
                <div class="potential-savings">
                    Potential Savings: <?= formatFileSize(array_sum(array_column($duplicateFiles, 'wasted_space'))) ?>
                </div>
            </div>
            
            <?php if (empty($duplicateFiles)): ?>
                <div class="no-data">
                    <i class="fas fa-copy"></i>
                    <p>No duplicate files found.</p>
                </div>
            <?php else: ?>
                <div class="duplicates-list">
                    <?php foreach ($duplicateFiles as $group): ?>
                    <div class="duplicate-group">
                        <div class="group-header">
                            <h4><?= $group['count'] ?> identical files</h4>
                            <span class="group-size">
                                <?= formatFileSize($group['size']) ?> each 
                                (<?= formatFileSize($group['wasted_space']) ?> wasted)
                            </span>
                        </div>
                        <div class="duplicate-files">
                            <?php foreach ($group['files'] as $index => $file): ?>
                            <div class="duplicate-file <?= $index === 0 ? 'original' : 'duplicate' ?>">
                                <div class="file-indicator">
                                    <?= $index === 0 ? '<i class="fas fa-star"></i> Keep' : '<i class="fas fa-copy"></i> Duplicate' ?>
                                </div>
                                <div class="file-path">
                                    <?= htmlspecialchars($file['path']) ?>
                                </div>
                                <div class="file-date">
                                    <?= date('M j, Y H:i', $file['modified']) ?>
                                </div>
                                <?php if ($index > 0): ?>
                                <div class="file-action">
                                    <button onclick="deleteDuplicate('<?= htmlspecialchars($file['path']) ?>')" 
                                            class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Cleanup Suggestions -->
        <div class="card">
            <h3>Cleanup Suggestions</h3>
            
            <?php if (empty($cleanupSuggestions)): ?>
                <div class="no-suggestions">
                    <i class="fas fa-broom"></i>
                    <p>No cleanup suggestions at this time. Your system looks clean!</p>
                </div>
            <?php else: ?>
                <div class="suggestions-list">
                    <?php foreach ($cleanupSuggestions as $suggestion): ?>
                    <div class="suggestion-item <?= $suggestion['type'] ?>">
                        <div class="suggestion-icon">
                            <i class="fas <?= $suggestion['type'] === 'logs' ? 'fa-file-alt' : 'fa-trash-alt' ?>"></i>
                        </div>
                        <div class="suggestion-info">
                            <h4><?= htmlspecialchars($suggestion['description']) ?></h4>
                            <p><?= htmlspecialchars($suggestion['path']) ?></p>
                            <div class="suggestion-stats">
                                <span class="current-size">Current: <?= formatFileSize($suggestion['size']) ?></span>
                                <span class="potential-savings">
                                    Potential Savings: <?= formatFileSize($suggestion['potential_savings']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="suggestion-action">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="<?= $suggestion['action'] ?>" value="1">
                                <input type="hidden" name="<?= $suggestion['type'] ?>_paths[]" 
                                       value="<?= htmlspecialchars($suggestion['path']) ?>">
                                <button type="submit" class="btn btn-warning"
                                        onclick="return confirm('Are you sure you want to clean up <?= htmlspecialchars($suggestion['description']) ?>?')">
                                    <i class="fas fa-broom"></i> Clean Up
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bulk Delete Modal -->
    <div id="bulkDeleteModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('bulkDeleteModal')">&times;</span>
            <h3>Bulk Delete Large Files</h3>
            <form method="POST" id="bulkDeleteForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="selected-files" id="selectedFilesList">
                    <p>No files selected.</p>
                </div>
                
                <div class="warning-notice">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action cannot be undone. Make sure you have backups of important files.
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="delete_large_files" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                        <i class="fas fa-trash-alt"></i> Delete Selected Files
                    </button>
                    <button type="button" onclick="closeModal('bulkDeleteModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .overview-section {
            margin-bottom: 30px;
        }
        
        .disk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .disk-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
        }
        
        .disk-header {
            margin-bottom: 15px;
        }
        
        .disk-header h3 {
            margin: 0;
            font-size: 1.2em;
        }
        
        .disk-path {
            font-family: monospace;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .usage-bar {
            width: 100%;
            height: 20px;
            background: var(--section-bg);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .usage-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #ffc107, #dc3545);
            transition: width 0.3s;
        }
        
        .usage-text {
            text-align: center;
            font-weight: 500;
            margin-bottom: 15px;
        }
        
        .disk-stats {
            display: flex;
            justify-content: space-between;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-label {
            display: block;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .stat-value {
            display: block;
            font-weight: 500;
        }
        
        .scan-controls {
            margin-bottom: 20px;
        }
        
        .scan-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .path-input {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .path-input label {
            min-width: 80px;
        }
        
        .path-input input {
            flex: 1;
        }
        
        .scan-options {
            display: flex;
            gap: 20px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .current-path {
            font-family: monospace;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .potential-savings {
            color: var(--success-color);
            font-weight: 500;
        }
        
        .no-data, .no-suggestions {
            text-align: center;
            padding: 60px;
            color: var(--text-muted);
        }
        
        .no-data i, .no-suggestions i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .directory-chart-container {
            margin-bottom: 30px;
            height: 300px;
        }
        
        .directory-list, .large-files-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .directory-item, .file-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--section-bg);
        }
        
        .file-checkbox {
            margin-right: 15px;
        }
        
        .directory-info, .file-info {
            flex: 1;
            min-width: 0;
        }
        
        .directory-name, .file-name {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }
        
        .path {
            font-family: monospace;
            font-weight: 500;
        }
        
        .extension {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .directory-stats, .file-meta {
            display: flex;
            gap: 15px;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .size {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .duplicates-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .duplicate-group {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .group-header {
            background: var(--section-bg);
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .group-header h4 {
            margin: 0;
        }
        
        .group-size {
            font-family: monospace;
            color: var(--text-muted);
        }
        
        .duplicate-files {
            display: flex;
            flex-direction: column;
        }
        
        .duplicate-file {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .duplicate-file:last-child {
            border-bottom: none;
        }
        
        .duplicate-file.original {
            background: rgba(40, 167, 69, 0.1);
        }
        
        .file-indicator {
            min-width: 100px;
            font-size: 0.9em;
        }
        
        .file-path {
            flex: 1;
            font-family: monospace;
            font-size: 0.9em;
        }
        
        .file-date {
            min-width: 120px;
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .suggestions-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .suggestion-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--section-bg);
        }
        
        .suggestion-item.logs {
            border-left: 4px solid #17a2b8;
        }
        
        .suggestion-item.temp {
            border-left: 4px solid #ffc107;
        }
        
        .suggestion-icon {
            font-size: 2.5em;
            margin-right: 20px;
            color: var(--primary-color);
        }
        
        .suggestion-info {
            flex: 1;
        }
        
        .suggestion-info h4 {
            margin: 0 0 5px 0;
        }
        
        .suggestion-info p {
            margin: 0 0 10px 0;
            font-family: monospace;
            color: var(--text-muted);
        }
        
        .suggestion-stats {
            display: flex;
            gap: 15px;
            font-size: 0.9em;
        }
        
        .current-size {
            color: var(--text-muted);
        }
        
        .selected-files {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            background: var(--section-bg);
        }
        
        .warning-notice {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .warning-notice i {
            margin-right: 8px;
        }
    </style>

    <script>
        // Initialize directory chart
        document.addEventListener('DOMContentLoaded', function() {
            initializeDirectoryChart();
        });
        
        function initializeDirectoryChart() {
            const ctx = document.getElementById('directoryChart');
            if (!ctx) return;
            
            const directories = <?= json_encode(array_slice($directorySizes, 0, 10)) ?>;
            
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: directories.map(d => d.relative_path || 'Root'),
                    datasets: [{
                        data: directories.map(d => d.size),
                        backgroundColor: [
                            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                            '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
                            '#4BC0C0', '#FF6384'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const size = formatFileSize(context.raw);
                                    return context.label + ': ' + size;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        function scanDirectory() {
            const path = document.getElementById('scanPath').value;
            window.location.href = `?path=${encodeURIComponent(path)}`;
        }
        
        function drillDown(path) {
            document.getElementById('scanPath').value = path;
            scanDirectory();
        }
        
        function deleteFile(filePath) {
            if (confirm(`Are you sure you want to delete this file?\n\n${filePath}\n\nThis action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="delete_large_files" value="1">
                    <input type="hidden" name="file_paths[]" value="${filePath}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteDuplicate(filePath) {
            if (confirm(`Are you sure you want to delete this duplicate file?\n\n${filePath}\n\nThis action cannot be undone.`)) {
                deleteFile(filePath);
            }
        }
        
        function showBulkDeleteModal() {
            const checkboxes = document.querySelectorAll('.large-file-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select files to delete first.');
                return;
            }
            
            updateSelectedFilesList();
            document.getElementById('bulkDeleteModal').style.display = 'block';
        }
        
        function updateSelectedFilesList() {
            const checkboxes = document.querySelectorAll('.large-file-checkbox:checked');
            const filesList = document.getElementById('selectedFilesList');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            if (checkboxes.length === 0) {
                filesList.innerHTML = '<p>No files selected.</p>';
                confirmBtn.disabled = true;
                return;
            }
            
            let html = `<p><strong>${checkboxes.length} file(s) selected for deletion:</strong></p><ul>`;
            let totalSize = 0;
            
            checkboxes.forEach(cb => {
                const fileItem = cb.closest('.file-item');
                const fileName = fileItem.querySelector('.path').textContent;
                const fileSize = fileItem.querySelector('.file-size').textContent;
                
                html += `<li>${fileName} (${fileSize})</li>`;
                
                // Add hidden input for form submission
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'file_paths[]';
                hiddenInput.value = cb.value;
                document.getElementById('bulkDeleteForm').appendChild(hiddenInput);
            });
            
            html += '</ul>';
            filesList.innerHTML = html;
            confirmBtn.disabled = false;
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            
            // Clear form inputs when closing
            if (modalId === 'bulkDeleteModal') {
                const hiddenInputs = document.querySelectorAll('#bulkDeleteForm input[name="file_paths[]"]');
                hiddenInputs.forEach(input => input.remove());
            }
        }
        
        // Update selected files list when checkboxes change
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('large-file-checkbox')) {
                // Clear previous hidden inputs
                const hiddenInputs = document.querySelectorAll('#bulkDeleteForm input[name="file_paths[]"]');
                hiddenInputs.forEach(input => input.remove());
            }
        });
        
        // Format file size function for JavaScript
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Auto-refresh scan path input
        document.getElementById('scanPath').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                scanDirectory();
            }
        });
    </script>
</body>
</html>