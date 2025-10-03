<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();
set_time_limit(300);

$user_id = $_SESSION['user_id'];
$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Helpers
function normalize_path($path) {
    return str_replace('\\', '/', $path);
}

function addDirToZip(ZipArchive $zip, string $dir, string $basePath) {
    $dir = rtrim($dir, "/\\");
    if (!is_dir($dir)) return;
    $basePath = rtrim($basePath, '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $filePath = normalize_path($file->getPathname());
        $localPath = ltrim(substr($filePath, strlen($basePath)), '/');
        if ($file->isDir()) {
            $zip->addEmptyDir($localPath . '/');
        } else {
            $zip->addFile($filePath, $localPath);
        }
    }
}

function dumpDatabaseToString(string $dbName): string {
    $mysqli = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $dbName);
    if (!$mysqli) {
        return "-- Failed to connect to database $dbName: " . mysqli_connect_error() . "\n";
    }
    $dump = "-- Backup for database `$dbName`\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesRes = mysqli_query($mysqli, 'SHOW TABLES');
    if ($tablesRes) {
        while ($row = mysqli_fetch_row($tablesRes)) {
            $table = $row[0];
            // Drop & create
            $createRes = mysqli_query($mysqli, 'SHOW CREATE TABLE `'.$table.'`');
            if ($createRes) {
                $createRow = mysqli_fetch_assoc($createRes);
                $createSql = $createRow['Create Table'] ?? '';
                $dump .= "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n";
                mysqli_free_result($createRes);
            }
            // Data
            $dataRes = mysqli_query($mysqli, 'SELECT * FROM `'.$table.'`');
            if ($dataRes) {
                while ($data = mysqli_fetch_assoc($dataRes)) {
                    $cols = array_map(fn($c) => '`'.str_replace('`','``',$c).'`', array_keys($data));
                    $vals = array_map(function($v) use ($mysqli) {
                        if ($v === null) return 'NULL';
                        return "'".mysqli_real_escape_string($mysqli, (string)$v)."'";
                    }, array_values($data));
                    $dump .= 'INSERT INTO `'.$table.'` ('.implode(',', $cols).') VALUES ('.implode(',', $vals).");\n";
                }
                mysqli_free_result($dataRes);
                $dump .= "\n";
            }
        }
        mysqli_free_result($tablesRes);
    }

    $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    mysqli_close($mysqli);
    return $dump;
}

// Gather user domains and databases
$domains = [];
$stmt = mysqli_prepare($conn, "SELECT domain_name, document_root FROM domains WHERE user_id = ? ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$domains = mysqli_fetch_all($res, MYSQLI_ASSOC);

$userDbs = [];
$stmt = mysqli_prepare($conn, "SELECT database_name FROM `databases` WHERE user_id = ? ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) { $userDbs[] = $row['database_name']; }

// Handle backup creation
if ($_POST && isset($_POST['create_backup'])) {
    $type = $_POST['backup_type'] ?? 'files';

    if (!class_exists('ZipArchive')) {
        $message = '<div class="alert alert-error">ZipArchive is not available on this server.</div>';
    } else {
        $timestamp = date('Ymd_His');
        $backupDir = rtrim(WEB_ROOT, '/\\') . '/_backups/' . $_SESSION['username'];
        createDirectory($backupDir);
        $zipPath = $backupDir . "/{$type}_backup_{$timestamp}.zip";

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $message = '<div class="alert alert-error">Unable to create backup archive.</div>';
        } else {
            $webrootNorm = normalize_path(rtrim(WEB_ROOT, '/\\'));
            // Files
            if ($type === 'files' || $type === 'full') {
                foreach ($domains as $d) {
                    $docroot = normalize_path($d['document_root']);
                    if (is_dir($docroot)) {
                        $base = rtrim($docroot, '/');
                        // add directory under domains/<domain_name>
                        $zip->addEmptyDir('domains/'.$d['domain_name']);
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iterator as $file) {
                            $filePath = normalize_path($file->getPathname());
                            $localPath = 'domains/'.$d['domain_name'] . substr($filePath, strlen($base));
                            $localPath = ltrim(str_replace('\\', '/', $localPath), '/');
                            if ($file->isDir()) {
                                $zip->addEmptyDir($localPath . '/');
                            } else {
                                $zip->addFile($filePath, $localPath);
                            }
                        }
                    }
                }
            }
            // Databases
            if ($type === 'databases' || $type === 'full') {
                $zip->addEmptyDir('databases');
                foreach ($userDbs as $dbName) {
                    $sql = dumpDatabaseToString($dbName);
                    $zip->addFromString('databases/'.$dbName.'.sql', $sql);
                }
            }

            $zip->close();

            if (file_exists($zipPath)) {
                $size = filesize($zipPath) ?: 0;
                $insert = "INSERT INTO backups (user_id, backup_type, file_path, file_size, status) VALUES (?, ?, ?, ?, 'completed')";
                $stmt = mysqli_prepare($conn, $insert);
                mysqli_stmt_bind_param($stmt, 'issi', $user_id, $type, $zipPath, $size);
                mysqli_stmt_execute($stmt);
                $message = '<div class="alert alert-success">Backup created successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Backup file not found after creation.</div>';
            }
        }
    }
}

// Fetch existing backups
$stmt = mysqli_prepare($conn, "SELECT * FROM backups WHERE user_id = ? ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$backups = mysqli_fetch_all($res, MYSQLI_ASSOC);

// Helper to make download URL if file under WEB_ROOT
function toPublicUrl(string $path): string {
    $path = normalize_path($path);
    $root = normalize_path(rtrim(WEB_ROOT, '/\\'));
    if (strpos($path, $root) === 0) {
        $rel = ltrim(substr($path, strlen($root)), '/');
        return '/' . $rel;
    }
    return '#';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Backups - Hosting Panel</title>
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
            <li><a href="backups.php" class="active">Backups</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <h1>Backups</h1>

        <?= $message ?>

        <div class="card">
            <h3>Create Backup</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Backup Type</label>
                    <select name="backup_type" class="form-control" required>
                        <option value="files">Files Only</option>
                        <option value="databases">Databases Only</option>
                        <option value="full">Full (Files + Databases)</option>
                    </select>
                </div>
                <button type="submit" name="create_backup" class="btn btn-primary">Create Backup</button>
            </form>
        </div>

        <div class="card">
            <h3>My Backups (<?= count($backups) ?>)</h3>
            <?php if ($backups): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars(ucfirst($b['backup_type'])) ?></td>
                        <td style="max-width: 380px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= htmlspecialchars($b['file_path']) ?>
                        </td>
                        <td><?= $b['file_size'] == 0 ? '0 B' : formatBytes((int)$b['file_size']) ?></td>
                        <td>
                            <span style="color: <?= $b['status'] === 'completed' ? 'var(--success-color)' : ($b['status'] === 'failed' ? 'var(--error-color)' : 'var(--warning-color)') ?>;">
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $b['status']))) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($b['created_at']))) ?></td>
                        <td>
                            <?php $url = toPublicUrl($b['file_path']); ?>
                            <?php if ($b['status'] === 'completed' && $url !== '#'): ?>
                                <a href="<?= $url ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;" download>Download</a>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">Not available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No backups found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
