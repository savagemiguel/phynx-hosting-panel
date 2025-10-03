<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

// CSRF for all POST operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$username = $_SESSION['username'];
$base = rtrim(WEB_ROOT, "/\\") . '/' . $username;
createDirectory($base);

function npath($p) { return str_replace('\\', '/', $p); }
function within_base($path, $base) {
    $p = npath($path);
    $b = rtrim(npath($base), '/');
    return strpos($p, $b) === 0;
}

// Resolve current directory path from query param
$reqRel = isset($_GET['path']) ? trim($_GET['path'], "/\\") : '';
$current = $base . ($reqRel !== '' ? '/' . $reqRel : '');
$current = npath($current);

// Ensure current path is valid and within base
if (!within_base($current, $base) || !is_dir($current)) {
    $current = npath($base);
    $reqRel = '';
}

// Handle download (GET)
if (isset($_GET['download'])) {
    $file = $_GET['download'];
    $target = npath($current . '/' . $file);
    if (within_base($target, $base) && is_file($target)) {
        $name = basename($target);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($target));
        readfile($target);
        exit;
    } else {
        http_response_code(404);
        exit('File not found');
    }
}

$message = '';

// Helpers
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($dir);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create folder
    if (isset($_POST['create_dir'])) {
        $name = trim($_POST['dirname'] ?? '');
        if ($name === '' || preg_match('/[\\\/:*?"<>|]/', $name)) {
            $message = '<div class="alert alert-error">Invalid folder name.</div>';
        } else {
            $target = npath($current . '/' . $name);
            if (within_base($target, $base) && !file_exists($target)) {
                if (@mkdir($target, 0755, true)) {
                    $message = '<div class="alert alert-success">Folder created.</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to create folder.</div>';
                }
            } else {
                $message = '<div class="alert alert-error">Folder already exists or path invalid.</div>';
            }
        }
    }

    // Upload file
    if (isset($_POST['upload']) && isset($_FILES['upload_file'])) {
        $f = $_FILES['upload_file'];
        if ($f['error'] === UPLOAD_ERR_OK) {
            $dest = npath($current . '/' . basename($f['name']));
            if (within_base($dest, $base)) {
                if (@move_uploaded_file($f['tmp_name'], $dest)) {
                    $message = '<div class="alert alert-success">File uploaded.</div>';
                } else {
                    $message = '<div class="alert alert-error">Upload failed.</div>';
                }
            } else {
                $message = '<div class="alert alert-error">Invalid destination.</div>';
            }
        } else {
            $message = '<div class="alert alert-error">Upload error code: ' . (int)$f['error'] . '</div>';
        }
    }

    // Delete item
    if (isset($_POST['delete'])) {
        $item = $_POST['item'] ?? '';
        $target = npath($current . '/' . $item);
        if (within_base($target, $base) && file_exists($target)) {
            if (is_dir($target)) rrmdir($target);
            else @unlink($target);
            $message = '<div class="alert alert-success">Deleted.</div>';
        } else {
            $message = '<div class="alert alert-error">Invalid item.</div>';
        }
    }

    // Rename item
    if (isset($_POST['rename'])) {
        $old = $_POST['old'] ?? '';
        $new = trim($_POST['new'] ?? '');
        if ($new === '' || preg_match('/[\\\/:*?"<>|]/', $new)) {
            $message = '<div class="alert alert-error">Invalid new name.</div>';
        } else {
            $src = npath($current . '/' . $old);
            $dst = npath($current . '/' . $new);
            if (within_base($src, $base) && within_base($dst, $base) && file_exists($src) && !file_exists($dst)) {
                if (@rename($src, $dst)) {
                    $message = '<div class="alert alert-success">Renamed.</div>';
                } else {
                    $message = '<div class="alert alert-error">Rename failed.</div>';
                }
            } else {
                $message = '<div class="alert alert-error">Invalid rename operation.</div>';
            }
        }
    }
}

// Build breadcrumb
$breadcrumbs = [];
$acc = '';
if ($reqRel !== '') {
    $parts = explode('/', $reqRel);
    foreach ($parts as $i => $p) {
        $acc = $acc === '' ? $p : ($acc . '/' . $p);
        $breadcrumbs[] = ['name' => $p, 'path' => $acc];
    }
}

// List directory contents
$items = [];
$scan = @scandir($current) ?: [];
foreach ($scan as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $full = npath($current . '/' . $entry);
    $isDir = is_dir($full);
    $size = $isDir ? '-' : (string)filesize($full);
    $mtime = @filemtime($full) ?: time();
    $items[] = [
        'name' => $entry,
        'isDir' => $isDir,
        'size' => $size,
        'mtime' => $mtime,
    ];
}

usort($items, function($a, $b) {
    if ($a['isDir'] && !$b['isDir']) return -1;
    if (!$a['isDir'] && $b['isDir']) return 1;
    return strcasecmp($a['name'], $b['name']);
});
?>
<!DOCTYPE html>
<html>
<head>
    <title>File Manager - Hosting Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
            <h3 style="color: var(--primary-color);">Control Panel</h3>
            <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <ul class="sidebar-nav">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="domains.php">Domains</a></li>
            <li><a href="subdomains.php">Subdomains</a></li>
            <li><a href="email.php">Email Accounts</a></li>
            <li><a href="databases.php">Databases</a></li>
            <li><a href="ftp.php">FTP Accounts</a></li>
            <li><a href="ssl.php">SSL Certificates</a></li>
            <li><a href="backups.php">Backups</a></li>
            <li><a href="file-manager.php" class="active">File Manager</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <h1>File Manager</h1>
        <?= $message ?>

        <div class="card">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div>
                    <strong>Path:</strong>
                    <a href="file-manager.php">/<?= htmlspecialchars($username) ?></a>
                    <?php foreach ($breadcrumbs as $bc): ?>
                        / <a href="file-manager.php?path=<?= urlencode($bc['path']) ?>"><?= htmlspecialchars($bc['name']) ?></a>
                    <?php endforeach; ?>
                </div>
                <div style="display: flex; gap: 8px;">
                    <form method="POST" enctype="multipart/form-data" style="display: inline;">
                        <?php csrf_field(); ?>
                        <input type="file" name="upload_file" required>
                        <button type="submit" name="upload" class="btn btn-primary">Upload</button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <?php csrf_field(); ?>
                        <input type="text" name="dirname" class="form-control" placeholder="New folder name" style="width: 200px;">
                        <button type="submit" name="create_dir" class="btn btn-success">Create Folder</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Modified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reqRel !== ''): ?>
                    <tr>
                        <td colspan="4">
                            <?php
                            $up = explode('/', $reqRel);
                            array_pop($up);
                            $upPath = implode('/', $up);
                            ?>
                            <a href="file-manager.php?path=<?= urlencode($upPath) ?>">⬅ Up one level</a>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($items): ?>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td>
                                <?php if ($it['isDir']): ?>
                                    <a href="file-manager.php?path=<?= urlencode(trim($reqRel . '/' . $it['name'], '/')) ?>">
                                        📁 <?= htmlspecialchars($it['name']) ?>
                                    </a>
                                <?php else: ?>
                                    📄 <?= htmlspecialchars($it['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $it['isDir'] ? '-' : formatBytes((int)$it['size']) ?></td>
                            <td><?= date('Y-m-d H:i', $it['mtime']) ?></td>
                            <td>
                                <?php if (!$it['isDir']): ?>
                                    <a class="btn btn-primary" style="padding: 4px 8px; font-size: 12px;" href="file-manager.php?path=<?= urlencode($reqRel) ?>&download=<?= urlencode($it['name']); ?>">Download</a>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="old" value="<?= htmlspecialchars($it['name']) ?>">
                                    <input type="text" name="new" class="form-control" value="<?= htmlspecialchars($it['name']) ?>" style="width: 180px; display: inline-block;">
                                    <button type="submit" name="rename" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Rename</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this item?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="item" value="<?= htmlspecialchars($it['name']) ?>">
                                    <button type="submit" name="delete" class="btn btn-danger" style="padding: 4px 8px; font-size: 12px;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="color: var(--text-secondary); text-align: center;">Empty folder.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
