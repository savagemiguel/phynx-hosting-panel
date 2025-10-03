<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$currentPath = $_GET['path'] ?? '/var/www';
$currentPath = realpath($currentPath) ?: '/var/www';

// Security: Ensure path is within allowed directories
$allowedPaths = ['/var/www', '/home', '/tmp'];
$isAllowed = false;
foreach ($allowedPaths as $allowed) {
    if (strpos($currentPath, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    $currentPath = '/var/www';
}

// Handle file operations
$message = '';
if ($_POST) {
    if (!csrf_verify()) {
        $message = '<div class="alert alert-error">Invalid security token</div>';
    } else {
        if (isset($_POST['create_folder'])) {
            $folderName = sanitize($_POST['folder_name']);
            $newPath = $currentPath . '/' . $folderName;
            if (mkdir($newPath, 0755, true)) {
                $message = '<div class="alert alert-success">Folder created successfully</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to create folder</div>';
            }
        }
        
        if (isset($_POST['upload_file']) && isset($_FILES['file'])) {
            $uploadPath = $currentPath . '/' . basename($_FILES['file']['name']);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath)) {
                chmod($uploadPath, 0644);
                $message = '<div class="alert alert-success">File uploaded successfully</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to upload file</div>';
            }
        }
        
        if (isset($_POST['delete_item'])) {
            $itemPath = $currentPath . '/' . basename($_POST['item_name']);
            if (is_file($itemPath)) {
                if (unlink($itemPath)) {
                    $message = '<div class="alert alert-success">File deleted successfully</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to delete file</div>';
                }
            } elseif (is_dir($itemPath)) {
                if (rmdir($itemPath)) {
                    $message = '<div class="alert alert-success">Folder deleted successfully</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to delete folder (must be empty)</div>';
                }
            }
        }
    }
}

// Get directory contents
$files = [];
$folders = [];
if (is_dir($currentPath)) {
    $items = scandir($currentPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $fullPath = $currentPath . '/' . $item;
        $stat = stat($fullPath);
        
        $itemData = [
            'name' => $item,
            'size' => is_file($fullPath) ? filesize($fullPath) : 0,
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
            'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4)
        ];
        
        if (is_dir($fullPath)) {
            $folders[] = $itemData;
        } else {
            $files[] = $itemData;
        }
    }
}

// Get parent directory
$parentPath = dirname($currentPath);
?>
<!DOCTYPE html>
<html>
<head>
    <title>File Manager - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
    <style>
        .file-manager {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 100px);
        }
        
        .breadcrumb {
            padding: 16px 0;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
            margin-right: 8px;
        }
        
        .file-actions {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .file-table {
            flex: 1;
            overflow-y: auto;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .file-item:hover {
            background: var(--card-bg);
        }
        
        .file-icon {
            width: 40px;
            text-align: center;
            margin-right: 12px;
            font-size: 18px;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .file-details {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .file-actions-btn {
            display: flex;
            gap: 8px;
        }
        
        .folder-icon {
            color: var(--warning-color);
        }
        
        .file-icon.file {
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="content-header">
            <h1><i class="fas fa-folder-open"></i> File Manager</h1>
        </div>
        
        <?= $message ?>
        
        <div class="card file-manager">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb">
                <i class="fas fa-folder"></i> 
                <a href="?path=/">Root</a> / 
                <?php 
                $pathParts = explode('/', trim($currentPath, '/'));
                $buildPath = '';
                foreach ($pathParts as $part) {
                    if (empty($part)) continue;
                    $buildPath .= '/' . $part;
                    echo '<a href="?path=' . urlencode($buildPath) . '">' . htmlspecialchars($part) . '</a> / ';
                }
                ?>
            </div>
            
            <!-- File Actions -->
            <div class="file-actions">
                <form method="POST" style="display: inline-flex; align-items: center; gap: 8px;">
                    <?php csrf_field(); ?>
                    <input type="text" name="folder_name" placeholder="Folder name" required style="padding: 8px;">
                    <button type="submit" name="create_folder" class="btn btn-primary">
                        <i class="fas fa-folder-plus"></i> Create Folder
                    </button>
                </form>
                
                <form method="POST" enctype="multipart/form-data" style="display: inline-flex; align-items: center; gap: 8px;">
                    <?php csrf_field(); ?>
                    <input type="file" name="file" required>
                    <button type="submit" name="upload_file" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload File
                    </button>
                </form>
                
                <?php if ($currentPath !== '/' && $parentPath !== $currentPath): ?>
                <a href="?path=<?= urlencode($parentPath) ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-up"></i> Parent Directory
                </a>
                <?php endif; ?>
            </div>
            
            <!-- File Listing -->
            <div class="file-table">
                <!-- Folders First -->
                <?php foreach ($folders as $folder): ?>
                <div class="file-item">
                    <div class="file-icon folder-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name">
                            <a href="?path=<?= urlencode($currentPath . '/' . $folder['name']) ?>" style="color: var(--text-primary); text-decoration: none;">
                                <?= htmlspecialchars($folder['name']) ?>
                            </a>
                        </div>
                        <div class="file-details">
                            Modified: <?= $folder['modified'] ?> | Permissions: <?= $folder['permissions'] ?>
                        </div>
                    </div>
                    <div class="file-actions-btn">
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this folder? It must be empty.')">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="item_name" value="<?= htmlspecialchars($folder['name']) ?>">
                            <button type="submit" name="delete_item" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Files -->
                <?php foreach ($files as $file): ?>
                <div class="file-item">
                    <div class="file-icon file">
                        <?php
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        switch ($ext) {
                            case 'php':
                            case 'html':
                            case 'css':
                            case 'js':
                                echo '<i class="fas fa-code"></i>';
                                break;
                            case 'jpg':
                            case 'jpeg':
                            case 'png':
                            case 'gif':
                                echo '<i class="fas fa-image"></i>';
                                break;
                            case 'pdf':
                                echo '<i class="fas fa-file-pdf"></i>';
                                break;
                            case 'zip':
                            case 'tar':
                            case 'gz':
                                echo '<i class="fas fa-file-archive"></i>';
                                break;
                            default:
                                echo '<i class="fas fa-file"></i>';
                        }
                        ?>
                    </div>
                    <div class="file-info">
                        <div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
                        <div class="file-details">
                            Size: <?= formatBytes($file['size']) ?> | Modified: <?= $file['modified'] ?> | Permissions: <?= $file['permissions'] ?>
                        </div>
                    </div>
                    <div class="file-actions-btn">
                        <a href="?path=<?= urlencode($currentPath) ?>&download=<?= urlencode($file['name']) ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-download"></i>
                        </a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this file?')">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="item_name" value="<?= htmlspecialchars($file['name']) ?>">
                            <button type="submit" name="delete_item" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($folders) && empty($files)): ?>
                <div class="file-item">
                    <div style="text-align: center; width: 100%; color: var(--text-muted); padding: 40px;">
                        <i class="fas fa-folder-open" style="font-size: 48px; opacity: 0.5;"></i>
                        <p>This directory is empty</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Handle file downloads
if (isset($_GET['download'])) {
    $downloadFile = $currentPath . '/' . basename($_GET['download']);
    if (file_exists($downloadFile) && is_file($downloadFile)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($downloadFile) . '"');
        header('Content-Length: ' . filesize($downloadFile));
        readfile($downloadFile);
        exit;
    }
}
?>