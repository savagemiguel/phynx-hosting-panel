<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$currentPath = $_GET['path'] ?? '/var/www/html';
$currentPath = realpath($currentPath) ?: '/var/www/html';

// Security: Ensure path is within allowed directories
$allowedPaths = ['/var/www', '/home', '/opt'];
$isAllowed = false;
foreach ($allowedPaths as $allowed) {
    if (strpos($currentPath, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    $currentPath = '/var/www/html';
}

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle permission operations
if ($_POST) {
    if (isset($_POST['change_permissions'])) {
        $result = changePermissions($_POST);
        $message = $result['success'] 
            ? '<div class="alert alert-success">Permissions updated successfully for ' . $result['updated'] . ' item(s).</div>'
            : '<div class="alert alert-danger">Failed to update permissions: ' . htmlspecialchars($result['error']) . '</div>';
    } elseif (isset($_POST['change_ownership'])) {
        $result = changeOwnership($_POST);
        $message = $result['success'] 
            ? '<div class="alert alert-success">Ownership updated successfully for ' . $result['updated'] . ' item(s).</div>'
            : '<div class="alert alert-danger">Failed to update ownership: ' . htmlspecialchars($result['error']) . '</div>';
    } elseif (isset($_POST['apply_preset'])) {
        $result = applyPermissionPreset($_POST);
        $message = $result['success'] 
            ? '<div class="alert alert-success">Preset applied successfully to ' . $result['updated'] . ' item(s).</div>'
            : '<div class="alert alert-danger">Failed to apply preset: ' . htmlspecialchars($result['error']) . '</div>';
    } elseif (isset($_POST['fix_permissions'])) {
        $result = fixCommonPermissions($currentPath);
        $message = $result['success'] 
            ? '<div class="alert alert-success">Fixed permissions for ' . $result['fixed'] . ' item(s).</div>'
            : '<div class="alert alert-danger">Permission fix failed: ' . htmlspecialchars($result['error']) . '</div>';
    }
}

// Change permissions function
function changePermissions($data) {
    $paths = $data['paths'];
    $mode = $data['mode'];
    $recursive = isset($data['recursive']);
    $updated = 0;
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            if ($recursive && is_dir($path)) {
                $updated += changePermissionsRecursive($path, $mode);
            } else {
                if (chmod($path, octdec($mode))) {
                    $updated++;
                }
            }
        }
    }
    
    return ['success' => $updated > 0, 'updated' => $updated, 'error' => 'Some items could not be updated'];
}

// Recursive permission change
function changePermissionsRecursive($dir, $mode) {
    $updated = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            if (chmod($item, octdec($mode))) {
                $updated++;
            }
        }
        
        // Change the directory itself
        if (chmod($dir, octdec($mode))) {
            $updated++;
        }
        
    } catch (Exception $e) {
        // Handle permission errors
    }
    
    return $updated;
}

// Change ownership function
function changeOwnership($data) {
    $paths = $data['paths'];
    $owner = $data['owner'] ?? null;
    $group = $data['group'] ?? null;
    $recursive = isset($data['recursive']);
    $updated = 0;
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            if ($recursive && is_dir($path)) {
                $updated += changeOwnershipRecursive($path, $owner, $group);
            } else {
                $success = true;
                if ($owner && !chown($path, $owner)) $success = false;
                if ($group && !chgrp($path, $group)) $success = false;
                if ($success) $updated++;
            }
        }
    }
    
    return ['success' => $updated > 0, 'updated' => $updated, 'error' => 'Some items could not be updated'];
}

// Recursive ownership change
function changeOwnershipRecursive($dir, $owner, $group) {
    $updated = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $success = true;
            if ($owner && !chown($item, $owner)) $success = false;
            if ($group && !chgrp($item, $group)) $success = false;
            if ($success) $updated++;
        }
        
        // Change the directory itself
        $success = true;
        if ($owner && !chown($dir, $owner)) $success = false;
        if ($group && !chgrp($dir, $group)) $success = false;
        if ($success) $updated++;
        
    } catch (Exception $e) {
        // Handle permission errors
    }
    
    return $updated;
}

// Apply permission preset
function applyPermissionPreset($data) {
    $preset = $data['preset'];
    $path = $data['path'];
    $recursive = isset($data['recursive']);
    
    $presets = [
        'web_files' => ['files' => '644', 'dirs' => '755'],
        'web_secure' => ['files' => '600', 'dirs' => '700'],
        'public_read' => ['files' => '644', 'dirs' => '755'],
        'private' => ['files' => '600', 'dirs' => '700'],
        'executable' => ['files' => '755', 'dirs' => '755']
    ];
    
    if (!isset($presets[$preset])) {
        return ['success' => false, 'error' => 'Invalid preset'];
    }
    
    $config = $presets[$preset];
    $updated = 0;
    
    if ($recursive && is_dir($path)) {
        $updated = applyPresetRecursive($path, $config);
    } else {
        $mode = is_dir($path) ? $config['dirs'] : $config['files'];
        if (chmod($path, octdec($mode))) {
            $updated = 1;
        }
    }
    
    return ['success' => $updated > 0, 'updated' => $updated, 'error' => 'Failed to apply preset'];
}

// Apply preset recursively
function applyPresetRecursive($dir, $config) {
    $updated = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $mode = $item->isDir() ? $config['dirs'] : $config['files'];
            if (chmod($item, octdec($mode))) {
                $updated++;
            }
        }
        
        // Change the directory itself
        if (chmod($dir, octdec($config['dirs']))) {
            $updated++;
        }
        
    } catch (Exception $e) {
        // Handle permission errors
    }
    
    return $updated;
}

// Fix common permission issues
function fixCommonPermissions($basePath) {
    $fixed = 0;
    
    // Common file extensions and their recommended permissions
    $fileTypes = [
        'php' => '644',
        'html' => '644',
        'css' => '644',
        'js' => '644',
        'txt' => '644',
        'log' => '644',
        'conf' => '600',
        'config' => '600'
    ];
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                
                if (isset($fileTypes[$ext])) {
                    $currentPerms = substr(sprintf('%o', fileperms($file)), -3);
                    $recommendedPerms = $fileTypes[$ext];
                    
                    if ($currentPerms !== $recommendedPerms) {
                        if (chmod($file, octdec($recommendedPerms))) {
                            $fixed++;
                        }
                    }
                }
            } elseif ($file->isDir()) {
                // Ensure directories are accessible
                $currentPerms = substr(sprintf('%o', fileperms($file)), -3);
                if ($currentPerms !== '755') {
                    if (chmod($file, 0755)) {
                        $fixed++;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
    
    return ['success' => true, 'fixed' => $fixed];
}

// Get directory contents with permission info
function getDirectoryContents($path) {
    $items = [];
    
    if (!is_dir($path)) {
        return $items;
    }
    
    $files = scandir($path);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = $path . '/' . $file;
        $stat = stat($filePath);
        $perms = fileperms($filePath);
        
        $items[] = [
            'name' => $file,
            'path' => $filePath,
            'type' => is_dir($filePath) ? 'directory' : 'file',
            'size' => $stat['size'],
            'modified' => $stat['mtime'],
            'permissions' => substr(sprintf('%o', $perms), -3),
            'permissions_octal' => sprintf('%04o', $perms),
            'permissions_symbolic' => getSymbolicPermissions($perms),
            'owner' => function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'] : $stat['uid'],
            'group' => function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid'])['name'] ?? $stat['gid'] : $stat['gid'],
            'owner_id' => $stat['uid'],
            'group_id' => $stat['gid'],
            'readable' => is_readable($filePath),
            'writable' => is_writable($filePath),
            'executable' => is_executable($filePath)
        ];
    }
    
    // Sort directories first, then files
    usort($items, function($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'directory' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    
    return $items;
}

// Convert octal permissions to symbolic notation
function getSymbolicPermissions($perms) {
    $symbolic = '';
    
    // File type
    if (($perms & 0xC000) == 0xC000) $symbolic .= 's'; // Socket
    elseif (($perms & 0xA000) == 0xA000) $symbolic .= 'l'; // Symbolic Link
    elseif (($perms & 0x8000) == 0x8000) $symbolic .= '-'; // Regular
    elseif (($perms & 0x6000) == 0x6000) $symbolic .= 'b'; // Block special
    elseif (($perms & 0x4000) == 0x4000) $symbolic .= 'd'; // Directory
    elseif (($perms & 0x2000) == 0x2000) $symbolic .= 'c'; // Character special
    elseif (($perms & 0x1000) == 0x1000) $symbolic .= 'p'; // FIFO pipe
    else $symbolic .= 'u'; // Unknown
    
    // Owner permissions
    $symbolic .= (($perms & 0x0100) ? 'r' : '-');
    $symbolic .= (($perms & 0x0080) ? 'w' : '-');
    $symbolic .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-'));
    
    // Group permissions
    $symbolic .= (($perms & 0x0020) ? 'r' : '-');
    $symbolic .= (($perms & 0x0010) ? 'w' : '-');
    $symbolic .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-'));
    
    // Other permissions
    $symbolic .= (($perms & 0x0004) ? 'r' : '-');
    $symbolic .= (($perms & 0x0002) ? 'w' : '-');
    $symbolic .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-'));
    
    return $symbolic;
}

// Get system users and groups
function getSystemUsers() {
    $users = [];
    
    if (function_exists('posix_getpwnam')) {
        // Common system users
        $commonUsers = ['www-data', 'apache', 'nginx', 'root', 'nobody'];
        
        foreach ($commonUsers as $user) {
            $userInfo = posix_getpwnam($user);
            if ($userInfo) {
                $users[] = [
                    'name' => $userInfo['name'],
                    'uid' => $userInfo['uid']
                ];
            }
        }
    }
    
    return $users;
}

function getSystemGroups() {
    $groups = [];
    
    if (function_exists('posix_getgrnam')) {
        // Common system groups
        $commonGroups = ['www-data', 'apache', 'nginx', 'root', 'users'];
        
        foreach ($commonGroups as $group) {
            $groupInfo = posix_getgrnam($group);
            if ($groupInfo) {
                $groups[] = [
                    'name' => $groupInfo['name'],
                    'gid' => $groupInfo['gid']
                ];
            }
        }
    }
    
    return $groups;
}

// Analyze permission security issues
function analyzePermissionSecurity($items) {
    $issues = [];
    
    foreach ($items as $item) {
        $perms = $item['permissions'];
        
        // Check for overly permissive files
        if ($item['type'] === 'file') {
            if (in_array($perms, ['777', '776', '775', '774'])) {
                $issues[] = [
                    'type' => 'warning',
                    'severity' => 'high',
                    'item' => $item['name'],
                    'issue' => 'File has overly permissive permissions (' . $perms . ')',
                    'recommendation' => 'Change to 644 for regular files or 600 for sensitive files'
                ];
            }
            
            if (substr($perms, -1) === '7' && !in_array(pathinfo($item['name'], PATHINFO_EXTENSION), ['sh', 'pl', 'py'])) {
                $issues[] = [
                    'type' => 'warning',
                    'severity' => 'medium',
                    'item' => $item['name'],
                    'issue' => 'Non-executable file has execute permissions',
                    'recommendation' => 'Remove execute permissions if not needed'
                ];
            }
        }
        
        // Check for world-writable directories without sticky bit
        if ($item['type'] === 'directory' && substr($perms, -1) >= '6') {
            if (substr($item['permissions_octal'], 0, 1) !== '1') {
                $issues[] = [
                    'type' => 'warning',
                    'severity' => 'high',
                    'item' => $item['name'],
                    'issue' => 'Directory is world-writable without sticky bit',
                    'recommendation' => 'Add sticky bit (chmod +t) or reduce permissions'
                ];
            }
        }
        
        // Check for sensitive files with wrong permissions
        if (preg_match('/\.(conf|config|key|pem|p12)$/i', $item['name'])) {
            if ($perms !== '600' && $perms !== '400') {
                $issues[] = [
                    'type' => 'error',
                    'severity' => 'critical',
                    'item' => $item['name'],
                    'issue' => 'Sensitive file has incorrect permissions (' . $perms . ')',
                    'recommendation' => 'Change to 600 or 400 for sensitive files'
                ];
            }
        }
    }
    
    return $issues;
}

$directoryContents = getDirectoryContents($currentPath);
$systemUsers = getSystemUsers();
$systemGroups = getSystemGroups();
$securityIssues = analyzePermissionSecurity($directoryContents);
$pathParts = explode('/', trim($currentPath, '/'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Permissions Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-shield-alt"></i> File Permissions Manager</h1>
        
        <?= $message ?>
        
        <!-- Security Issues Alert -->
        <?php if (!empty($securityIssues)): ?>
        <div class="security-alerts">
            <h3><i class="fas fa-exclamation-triangle"></i> Security Issues Found</h3>
            <div class="alerts-list">
                <?php foreach ($securityIssues as $issue): ?>
                <div class="alert-item <?= $issue['severity'] ?>">
                    <div class="alert-icon">
                        <i class="fas <?= $issue['type'] === 'error' ? 'fa-times-circle' : 'fa-exclamation-triangle' ?>"></i>
                    </div>
                    <div class="alert-content">
                        <h4><?= htmlspecialchars($issue['item']) ?></h4>
                        <p><?= htmlspecialchars($issue['issue']) ?></p>
                        <small><strong>Recommendation:</strong> <?= htmlspecialchars($issue['recommendation']) ?></small>
                    </div>
                    <div class="alert-severity">
                        <?= ucfirst($issue['severity']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="action-group">
                <h3>Quick Fixes</h3>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" name="fix_permissions" class="btn btn-warning"
                            onclick="return confirm('This will apply recommended permissions to common file types. Continue?')">
                        <i class="fas fa-magic"></i> Auto-Fix Permissions
                    </button>
                </form>
                <button onclick="showPresetModal()" class="btn btn-info">
                    <i class="fas fa-cogs"></i> Apply Preset
                </button>
            </div>
            
            <div class="action-group">
                <h3>Bulk Operations</h3>
                <button onclick="showBulkPermissionsModal()" class="btn btn-primary" id="bulkPermBtn" disabled>
                    <i class="fas fa-edit"></i> Change Permissions
                </button>
                <button onclick="showBulkOwnershipModal()" class="btn btn-secondary" id="bulkOwnerBtn" disabled>
                    <i class="fas fa-user-cog"></i> Change Ownership
                </button>
            </div>
        </div>

        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-nav">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="?path=/"><i class="fas fa-home"></i> Root</a>
                    </li>
                    <?php 
                    $breadcrumbPath = '';
                    foreach ($pathParts as $part):
                        if (empty($part)) continue;
                        $breadcrumbPath .= '/' . $part;
                    ?>
                        <li class="breadcrumb-item">
                            <a href="?path=<?= urlencode($breadcrumbPath) ?>">
                                <?= htmlspecialchars($part) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        </div>

        <!-- File Permissions Table -->
        <div class="card">
            <div class="table-header">
                <h3>Files & Directories</h3>
                <div class="header-controls">
                    <input type="checkbox" id="selectAll" onchange="toggleAllSelection()">
                    <label for="selectAll">Select All</label>
                </div>
            </div>
            
            <?php if (empty($directoryContents)): ?>
                <div class="no-files">
                    <i class="fas fa-folder-open"></i>
                    <p>No files or directories found.</p>
                </div>
            <?php else: ?>
                <div class="permissions-table-container">
                    <table class="permissions-table">
                        <thead>
                            <tr>
                                <th width="40"></th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Permissions</th>
                                <th>Owner</th>
                                <th>Group</th>
                                <th>Size</th>
                                <th>Modified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($directoryContents as $item): ?>
                            <tr data-path="<?= htmlspecialchars($item['path']) ?>" 
                                class="<?= !$item['readable'] ? 'no-read' : '' ?> <?= !$item['writable'] ? 'no-write' : '' ?>">
                                <td>
                                    <input type="checkbox" class="item-checkbox" 
                                           value="<?= htmlspecialchars($item['path']) ?>" 
                                           onchange="updateBulkButtons()">
                                </td>
                                <td>
                                    <div class="file-info">
                                        <i class="fas <?= $item['type'] === 'directory' ? 'fa-folder' : 'fa-file' ?>"></i>
                                        <?php if ($item['type'] === 'directory'): ?>
                                            <a href="?path=<?= urlencode($item['path']) ?>" class="file-link">
                                                <?= htmlspecialchars($item['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="file-name"><?= htmlspecialchars($item['name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="file-type <?= $item['type'] ?>">
                                        <?= ucfirst($item['type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="permissions-cell">
                                        <div class="permissions-octal"><?= $item['permissions'] ?></div>
                                        <div class="permissions-symbolic"><?= $item['permissions_symbolic'] ?></div>
                                        <div class="permissions-visual">
                                            <span class="perm-group owner">
                                                <span class="perm-bit <?= substr($item['permissions'], 0, 1) >= 4 ? 'active' : '' ?>">r</span>
                                                <span class="perm-bit <?= substr($item['permissions'], 0, 1) >= 2 && substr($item['permissions'], 0, 1) != 4 && substr($item['permissions'], 0, 1) != 5 ? 'active' : '' ?>">w</span>
                                                <span class="perm-bit <?= substr($item['permissions'], 0, 1) % 2 == 1 ? 'active' : '' ?>">x</span>
                                            </span>
                                            <span class="perm-group group">
                                                <span class="perm-bit <?= substr($item['permissions'], 1, 1) >= 4 ? 'active' : '' ?>">r</span>
                                                <span class="perm-bit <?= substr($item['permissions'], 1, 1) >= 2 && substr($item['permissions'], 1, 1) != 4 && substr($item['permissions'], 1, 1) != 5 ? 'active' : '' ?>">w</span>
                                                <span class="perm-bit <?= substr($item['permissions'], 1, 1) % 2 == 1 ? 'active' : '' ?>">x</span>
                                            </span>
                                            <span class="perm-group other">
                                                <span class="perm-bit <?= substr($item['permissions'], 2, 1) >= 4 ? 'active' : '' ?>">r</span>
                                                <span class="perm-bit <?= substr($item['permissions'], 2, 1) >= 2 && substr($item['permissions'], 2, 1) != 4 && substr($item['permissions'], 2, 1) != 5 ? 'active' : '' ?>">w</span>
                                                <span class="perm-bit <?= substr($item['permissions'], 2, 1) % 2 == 1 ? 'active' : '' ?>">x</span>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($item['owner']) ?></td>
                                <td><?= htmlspecialchars($item['group']) ?></td>
                                <td><?= $item['type'] === 'file' ? formatFileSize($item['size']) : '-' ?></td>
                                <td><?= date('M j, Y H:i', $item['modified']) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="editPermissions('<?= htmlspecialchars($item['path']) ?>', '<?= $item['permissions'] ?>')" 
                                                class="btn btn-sm btn-primary" title="Edit Permissions">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="editOwnership('<?= htmlspecialchars($item['path']) ?>', '<?= htmlspecialchars($item['owner']) ?>', '<?= htmlspecialchars($item['group']) ?>')" 
                                                class="btn btn-sm btn-info" title="Edit Ownership">
                                            <i class="fas fa-user-cog"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Permission Guidelines -->
        <div class="card">
            <h3>Permission Guidelines</h3>
            <div class="guidelines-grid">
                <div class="guideline-item">
                    <h4>Web Files (644)</h4>
                    <div class="permission-example">
                        <span class="perm-octal">644</span>
                        <span class="perm-description">rw-r--r--</span>
                    </div>
                    <p>Standard web files (.html, .css, .js). Owner can read/write, others can only read.</p>
                </div>
                
                <div class="guideline-item">
                    <h4>Directories (755)</h4>
                    <div class="permission-example">
                        <span class="perm-octal">755</span>
                        <span class="perm-description">rwxr-xr-x</span>
                    </div>
                    <p>Standard directories. Owner can read/write/execute, others can read/execute.</p>
                </div>
                
                <div class="guideline-item">
                    <h4>Executables (755)</h4>
                    <div class="permission-example">
                        <span class="perm-octal">755</span>
                        <span class="perm-description">rwxr-xr-x</span>
                    </div>
                    <p>Executable files (.sh, .pl, .py). Owner can read/write/execute, others can read/execute.</p>
                </div>
                
                <div class="guideline-item">
                    <h4>Sensitive Files (600)</h4>
                    <div class="permission-example">
                        <span class="perm-octal">600</span>
                        <span class="perm-description">rw-------</span>
                    </div>
                    <p>Configuration files, keys, passwords. Only owner can read/write.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Permissions Modal -->
    <div id="editPermissionsModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editPermissionsModal')">&times;</span>
            <h3>Edit Permissions</h3>
            <form method="POST" id="editPermissionsForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="paths[]" id="editPermPath">
                
                <div class="current-permissions">
                    <h4>Current Permissions</h4>
                    <div class="perm-display" id="currentPermDisplay"></div>
                </div>
                
                <div class="permission-editor">
                    <h4>New Permissions</h4>
                    
                    <div class="octal-input">
                        <label>Octal Mode:</label>
                        <input type="text" name="mode" class="form-control" pattern="[0-7]{3}" 
                               maxlength="3" id="permissionMode" onkeyup="updatePermissionDisplay()">
                    </div>
                    
                    <div class="visual-editor">
                        <div class="perm-section">
                            <h5>Owner</h5>
                            <label><input type="checkbox" id="owner_r" onchange="updateOctalFromCheckboxes()"> Read (4)</label>
                            <label><input type="checkbox" id="owner_w" onchange="updateOctalFromCheckboxes()"> Write (2)</label>
                            <label><input type="checkbox" id="owner_x" onchange="updateOctalFromCheckboxes()"> Execute (1)</label>
                        </div>
                        <div class="perm-section">
                            <h5>Group</h5>
                            <label><input type="checkbox" id="group_r" onchange="updateOctalFromCheckboxes()"> Read (4)</label>
                            <label><input type="checkbox" id="group_w" onchange="updateOctalFromCheckboxes()"> Write (2)</label>
                            <label><input type="checkbox" id="group_x" onchange="updateOctalFromCheckboxes()"> Execute (1)</label>
                        </div>
                        <div class="perm-section">
                            <h5>Other</h5>
                            <label><input type="checkbox" id="other_r" onchange="updateOctalFromCheckboxes()"> Read (4)</label>
                            <label><input type="checkbox" id="other_w" onchange="updateOctalFromCheckboxes()"> Write (2)</label>
                            <label><input type="checkbox" id="other_x" onchange="updateOctalFromCheckboxes()"> Execute (1)</label>
                        </div>
                    </div>
                    
                    <div class="permission-preview">
                        <h5>Preview:</h5>
                        <span id="permissionPreview">---</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="recursive">
                        Apply recursively to all files and subdirectories
                    </label>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="change_permissions" class="btn btn-primary">
                        <i class="fas fa-save"></i> Apply Permissions
                    </button>
                    <button type="button" onclick="closeModal('editPermissionsModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Ownership Modal -->
    <div id="editOwnershipModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editOwnershipModal')">&times;</span>
            <h3>Edit Ownership</h3>
            <form method="POST" id="editOwnershipForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="paths[]" id="editOwnerPath">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Owner:</label>
                        <select name="owner" class="form-control" id="ownerSelect">
                            <option value="">Keep current</option>
                            <?php foreach ($systemUsers as $user): ?>
                                <option value="<?= htmlspecialchars($user['name']) ?>">
                                    <?= htmlspecialchars($user['name']) ?> (<?= $user['uid'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Group:</label>
                        <select name="group" class="form-control" id="groupSelect">
                            <option value="">Keep current</option>
                            <?php foreach ($systemGroups as $group): ?>
                                <option value="<?= htmlspecialchars($group['name']) ?>">
                                    <?= htmlspecialchars($group['name']) ?> (<?= $group['gid'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="recursive">
                        Apply recursively to all files and subdirectories
                    </label>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="change_ownership" class="btn btn-primary">
                        <i class="fas fa-save"></i> Apply Ownership
                    </button>
                    <button type="button" onclick="closeModal('editOwnershipModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Apply Preset Modal -->
    <div id="presetModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('presetModal')">&times;</span>
            <h3>Apply Permission Preset</h3>
            <form method="POST" id="presetForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="path" value="<?= htmlspecialchars($currentPath) ?>">
                
                <div class="preset-options">
                    <div class="preset-item">
                        <input type="radio" name="preset" value="web_files" id="preset_web">
                        <label for="preset_web">
                            <h4>Web Files</h4>
                            <p>Files: 644, Directories: 755 - Standard web content</p>
                        </label>
                    </div>
                    
                    <div class="preset-item">
                        <input type="radio" name="preset" value="web_secure" id="preset_secure">
                        <label for="preset_secure">
                            <h4>Secure Web Files</h4>
                            <p>Files: 600, Directories: 700 - Restricted access</p>
                        </label>
                    </div>
                    
                    <div class="preset-item">
                        <input type="radio" name="preset" value="public_read" id="preset_public">
                        <label for="preset_public">
                            <h4>Public Read</h4>
                            <p>Files: 644, Directories: 755 - Public readable content</p>
                        </label>
                    </div>
                    
                    <div class="preset-item">
                        <input type="radio" name="preset" value="executable" id="preset_exec">
                        <label for="preset_exec">
                            <h4>Executable Files</h4>
                            <p>Files: 755, Directories: 755 - Executable scripts</p>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="recursive" checked>
                        Apply recursively to all files and subdirectories
                    </label>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" name="apply_preset" class="btn btn-primary">
                        <i class="fas fa-magic"></i> Apply Preset
                    </button>
                    <button type="button" onclick="closeModal('presetModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .security-alerts {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid #dc3545;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .security-alerts h3 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        
        .alerts-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .alert-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid;
        }
        
        .alert-item.critical {
            background: rgba(220, 53, 69, 0.1);
            border-left-color: #dc3545;
        }
        
        .alert-item.high {
            background: rgba(255, 193, 7, 0.1);
            border-left-color: #ffc107;
        }
        
        .alert-item.medium {
            background: rgba(23, 162, 184, 0.1);
            border-left-color: #17a2b8;
        }
        
        .alert-icon {
            font-size: 1.5em;
            margin-right: 15px;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-content h4 {
            margin: 0 0 5px 0;
            font-family: monospace;
        }
        
        .alert-content p {
            margin: 0 0 5px 0;
        }
        
        .alert-severity {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.8em;
        }
        
        .quick-actions {
            display: flex;
            gap: 30px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .action-group {
            flex: 1;
            min-width: 300px;
        }
        
        .action-group h3 {
            margin-bottom: 15px;
            color: var(--text-color);
        }
        
        .action-group .btn {
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .breadcrumb-nav {
            margin-bottom: 20px;
        }
        
        .breadcrumb {
            background: var(--section-bg);
            padding: 10px 15px;
            border-radius: 6px;
            margin: 0;
            display: flex;
            list-style: none;
        }
        
        .breadcrumb-item {
            display: flex;
            align-items: center;
        }
        
        .breadcrumb-item:not(:last-child)::after {
            content: '/';
            margin: 0 10px;
            color: var(--text-muted);
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .header-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .no-files {
            text-align: center;
            padding: 60px;
            color: var(--text-muted);
        }
        
        .no-files i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .permissions-table-container {
            overflow-x: auto;
        }
        
        .permissions-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .permissions-table th,
        .permissions-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .permissions-table th {
            background: var(--section-bg);
            font-weight: 500;
        }
        
        .permissions-table tr.no-read {
            background: rgba(220, 53, 69, 0.05);
        }
        
        .permissions-table tr.no-write {
            background: rgba(255, 193, 7, 0.05);
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-link {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .file-link:hover {
            text-decoration: underline;
        }
        
        .file-type {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            text-transform: uppercase;
        }
        
        .file-type.directory {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .file-type.file {
            background: rgba(0, 123, 255, 0.2);
            color: #007bff;
        }
        
        .permissions-cell {
            font-family: monospace;
        }
        
        .permissions-octal {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .permissions-symbolic {
            font-size: 0.9em;
            color: var(--text-muted);
        }
        
        .permissions-visual {
            display: flex;
            gap: 4px;
            margin-top: 4px;
        }
        
        .perm-group {
            display: flex;
            gap: 2px;
        }
        
        .perm-bit {
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            border-radius: 2px;
            background: var(--section-bg);
            color: var(--text-muted);
        }
        
        .perm-bit.active {
            background: var(--primary-color);
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .guidelines-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .guideline-item {
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .permission-example {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            font-family: monospace;
        }
        
        .perm-octal {
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .perm-description {
            color: var(--text-muted);
        }
        
        .current-permissions, .permission-editor {
            margin-bottom: 20px;
        }
        
        .perm-display {
            font-family: monospace;
            font-size: 1.2em;
            padding: 15px;
            background: var(--section-bg);
            border-radius: 6px;
        }
        
        .octal-input {
            margin-bottom: 20px;
        }
        
        .octal-input input {
            width: 100px;
            text-align: center;
            font-family: monospace;
            font-size: 1.2em;
        }
        
        .visual-editor {
            display: flex;
            gap: 30px;
            margin-bottom: 20px;
        }
        
        .perm-section {
            flex: 1;
        }
        
        .perm-section h5 {
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .perm-section label {
            display: block;
            margin-bottom: 8px;
            cursor: pointer;
        }
        
        .permission-preview {
            font-family: monospace;
            font-size: 1.1em;
            padding: 10px;
            background: var(--section-bg);
            border-radius: 4px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
        }
        
        .preset-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .preset-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .preset-item input[type="radio"] {
            margin-top: 5px;
        }
        
        .preset-item label {
            flex: 1;
            cursor: pointer;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .preset-item input[type="radio"]:checked + label {
            background: rgba(var(--primary-color-rgb), 0.1);
            border-color: var(--primary-color);
        }
        
        .preset-item h4 {
            margin: 0 0 5px 0;
        }
        
        .preset-item p {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.9em;
        }
    </style>

    <script>
        function toggleAllSelection() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.item-checkbox');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            
            updateBulkButtons();
        }
        
        function updateBulkButtons() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            const hasSelection = checkboxes.length > 0;
            
            document.getElementById('bulkPermBtn').disabled = !hasSelection;
            document.getElementById('bulkOwnerBtn').disabled = !hasSelection;
        }
        
        function editPermissions(path, currentPerms) {
            document.getElementById('editPermPath').value = path;
            document.getElementById('permissionMode').value = currentPerms;
            document.getElementById('currentPermDisplay').textContent = currentPerms + ' (' + octalToSymbolic(currentPerms) + ')';
            
            updateCheckboxesFromOctal(currentPerms);
            updatePermissionDisplay();
            
            document.getElementById('editPermissionsModal').style.display = 'block';
        }
        
        function editOwnership(path, owner, group) {
            document.getElementById('editOwnerPath').value = path;
            document.getElementById('ownerSelect').value = owner;
            document.getElementById('groupSelect').value = group;
            
            document.getElementById('editOwnershipModal').style.display = 'block';
        }
        
        function showPresetModal() {
            document.getElementById('presetModal').style.display = 'block';
        }
        
        function showBulkPermissionsModal() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select files or directories first.');
                return;
            }
            
            // Clear previous paths and add selected ones
            const pathInputs = document.querySelectorAll('#editPermissionsForm input[name="paths[]"]:not(#editPermPath)');
            pathInputs.forEach(input => input.remove());
            
            checkboxes.forEach(cb => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'paths[]';
                hiddenInput.value = cb.value;
                document.getElementById('editPermissionsForm').appendChild(hiddenInput);
            });
            
            // Clear the single path input since we're doing bulk
            document.getElementById('editPermPath').value = '';
            document.getElementById('currentPermDisplay').textContent = `${checkboxes.length} items selected`;
            
            document.getElementById('editPermissionsModal').style.display = 'block';
        }
        
        function showBulkOwnershipModal() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select files or directories first.');
                return;
            }
            
            // Clear previous paths and add selected ones
            const pathInputs = document.querySelectorAll('#editOwnershipForm input[name="paths[]"]:not(#editOwnerPath)');
            pathInputs.forEach(input => input.remove());
            
            checkboxes.forEach(cb => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'paths[]';
                hiddenInput.value = cb.value;
                document.getElementById('editOwnershipForm').appendChild(hiddenInput);
            });
            
            // Clear the single path input since we're doing bulk
            document.getElementById('editOwnerPath').value = '';
            
            document.getElementById('editOwnershipModal').style.display = 'block';
        }
        
        function updatePermissionDisplay() {
            const mode = document.getElementById('permissionMode').value;
            
            if (mode.length === 3 && /^[0-7]{3}$/.test(mode)) {
                updateCheckboxesFromOctal(mode);
                document.getElementById('permissionPreview').textContent = octalToSymbolic(mode);
            } else {
                document.getElementById('permissionPreview').textContent = '---';
            }
        }
        
        function updateOctalFromCheckboxes() {
            const owner = (document.getElementById('owner_r').checked ? 4 : 0) +
                         (document.getElementById('owner_w').checked ? 2 : 0) +
                         (document.getElementById('owner_x').checked ? 1 : 0);
                         
            const group = (document.getElementById('group_r').checked ? 4 : 0) +
                         (document.getElementById('group_w').checked ? 2 : 0) +
                         (document.getElementById('group_x').checked ? 1 : 0);
                         
            const other = (document.getElementById('other_r').checked ? 4 : 0) +
                         (document.getElementById('other_w').checked ? 2 : 0) +
                         (document.getElementById('other_x').checked ? 1 : 0);
            
            const octal = owner.toString() + group.toString() + other.toString();
            document.getElementById('permissionMode').value = octal;
            document.getElementById('permissionPreview').textContent = octalToSymbolic(octal);
        }
        
        function updateCheckboxesFromOctal(octal) {
            const digits = octal.split('');
            
            // Owner permissions
            document.getElementById('owner_r').checked = (parseInt(digits[0]) & 4) !== 0;
            document.getElementById('owner_w').checked = (parseInt(digits[0]) & 2) !== 0;
            document.getElementById('owner_x').checked = (parseInt(digits[0]) & 1) !== 0;
            
            // Group permissions
            document.getElementById('group_r').checked = (parseInt(digits[1]) & 4) !== 0;
            document.getElementById('group_w').checked = (parseInt(digits[1]) & 2) !== 0;
            document.getElementById('group_x').checked = (parseInt(digits[1]) & 1) !== 0;
            
            // Other permissions
            document.getElementById('other_r').checked = (parseInt(digits[2]) & 4) !== 0;
            document.getElementById('other_w').checked = (parseInt(digits[2]) & 2) !== 0;
            document.getElementById('other_x').checked = (parseInt(digits[2]) & 1) !== 0;
        }
        
        function octalToSymbolic(octal) {
            const perms = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];
            return perms[parseInt(octal[0])] + perms[parseInt(octal[1])] + perms[parseInt(octal[2])];
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Format file size function
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>

<?php
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', '') . ' ' . $units[$power];
}
?>