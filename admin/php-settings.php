<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$notices = [];

// Fetch domains list
$domains = [];
$res = mysqli_query($conn, "SELECT id, domain_name, document_root FROM domains ORDER BY domain_name");
if ($res) { while ($row = mysqli_fetch_assoc($res)) { $domains[] = $row; } }

// Helpers
function normalize_path($p) { return rtrim(str_replace('\\\\', '/', $p), '/'); }
function user_ini_path($docroot) { return normalize_path($docroot) . '/.user.ini'; }
function parse_user_ini($filepath) {
    $data = [
        'memory_limit' => '',
        'upload_max_filesize' => '',
        'post_max_size' => '',
        'max_execution_time' => '',
        'display_errors' => ''
    ];
    if (!file_exists($filepath)) return $data;
    $lines = @file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if (array_key_exists($k, $data)) $data[$k] = $v;
        }
    }
    return $data;
}
function render_user_ini(array $vals): string {
    $allowed = ['memory_limit','upload_max_filesize','post_max_size','max_execution_time','display_errors'];
    $out = [];
    foreach ($allowed as $k) {
        $v = isset($vals[$k]) ? trim((string)$vals[$k]) : '';
        if ($v !== '') { $out[] = $k . ' = ' . $v; }
    }
    if (empty($out)) { $out[] = '; empty overrides'; }
    return implode("\n", $out) . "\n";
}

// Handle actions
$selected_domain_id = (int)($_POST['domain_id'] ?? ($_GET['domain_id'] ?? 0));
$selected = null;
if ($selected_domain_id > 0) {
    $st = mysqli_prepare($conn, 'SELECT id, domain_name, document_root FROM domains WHERE id = ?');
    mysqli_stmt_bind_param($st, 'i', $selected_domain_id);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $selected = mysqli_fetch_assoc($rs) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
    if (!$selected) {
        $message = '<div class="alert alert-error">Invalid domain selection.</div>';
    } else {
        $docroot = normalize_path($selected['document_root']);
        // Ensure path is within WEB_ROOT
        $base = normalize_path(WEB_ROOT);
        if (strpos($docroot, normalize_path($base)) !== 0) {
            $message = '<div class="alert alert-error">Document root outside of WEB_ROOT; cannot write overrides.</div>';
        } else {
            $iniFile = user_ini_path($docroot);
            if (isset($_POST['save_overrides'])) {
                $vals = [
                    'memory_limit' => trim($_POST['memory_limit'] ?? ''),
                    'upload_max_filesize' => trim($_POST['upload_max_filesize'] ?? ''),
                    'post_max_size' => trim($_POST['post_max_size'] ?? ''),
                    'max_execution_time' => trim($_POST['max_execution_time'] ?? ''),
                    'display_errors' => trim($_POST['display_errors'] ?? '')
                ];
                $content = render_user_ini($vals);
                if (!is_dir($docroot)) { @mkdir($docroot, 0755, true); }
                if (@file_put_contents($iniFile, $content) !== false) {
                    $message = '<div class="alert alert-success">Overrides saved to .user.ini. Note: PHP may cache .user.ini for up to 5 minutes (user_ini.cache_ttl).</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to write .user.ini.</div>';
                }
            }
            if (isset($_POST['reset_overrides'])) {
                if (file_exists($iniFile)) {
                    if (@unlink($iniFile)) {
                        $message = '<div class="alert alert-success">Overrides reset (removed .user.ini).</div>';
                    } else {
                        $message = '<div class="alert alert-error">Failed to remove .user.ini.</div>';
                    }
                } else {
                    $message = '<div class="alert alert-success">No overrides present.</div>';
                }
            }
        }
    }
}

// Inspect runtime
$php_version = phpversion();
$loaded_ini = php_ini_loaded_file() ?: '(none)';
$scanned = php_ini_scanned_files() ?: '';
$scanned_list = array_filter(array_map('trim', explode(',', $scanned)));

// Current domain overrides to show in form
$current_overrides = ['memory_limit'=>'','upload_max_filesize'=>'','post_max_size'=>'','max_execution_time'=>'','display_errors'=>''];
$iniFileView = '';
if ($selected) {
    $iniFileView = user_ini_path($selected['document_root']);
    $current_overrides = parse_user_ini($iniFileView);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>PHP Settings - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1><i class="fab fa-php"></i> PHP Settings</h1>

    <div class="card">
        <h3>Runtime</h3>
        <div class="card-body">
            <div>PHP Version: <strong><?= htmlspecialchars($php_version) ?></strong></div>
            <div>Loaded php.ini: <code><?= htmlspecialchars($loaded_ini) ?></code></div>
            <?php if (!empty($scanned_list)): ?>
                <div>Additional INI files scanned:</div>
                <ul style="margin-top: 6px;">
                    <?php foreach ($scanned_list as $f): ?>
                        <li><code><?= htmlspecialchars($f) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="help-text" style="margin-top:8px;">Per-directory overrides use .user.ini (read periodically; see user_ini.cache_ttl). For immediate effect, you may need to reload PHP-FPM/Apache depending on your setup.</p>
        </div>
    </div>

    <?= $message ?>

    <div class="card">
        <h3>Per-domain Overrides (.user.ini)</h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Domain</label>
                    <select name="domain_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Select a domain</option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $selected && (int)$selected['id'] === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['domain_name']) ?> (root: <?= htmlspecialchars($d['document_root']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>.user.ini path</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($selected ? $iniFileView : '') ?>" readonly>
                </div>
            </div>

            <?php if ($selected): ?>
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
                <div class="form-group">
                    <label>memory_limit</label>
                    <input type="text" name="memory_limit" class="form-control" placeholder="e.g., 256M" value="<?= htmlspecialchars($current_overrides['memory_limit']) ?>">
                </div>
                <div class="form-group">
                    <label>upload_max_filesize</label>
                    <input type="text" name="upload_max_filesize" class="form-control" placeholder="e.g., 50M" value="<?= htmlspecialchars($current_overrides['upload_max_filesize']) ?>">
                </div>
                <div class="form-group">
                    <label>post_max_size</label>
                    <input type="text" name="post_max_size" class="form-control" placeholder="e.g., 60M" value="<?= htmlspecialchars($current_overrides['post_max_size']) ?>">
                </div>
                <div class="form-group">
                    <label>max_execution_time</label>
                    <input type="number" name="max_execution_time" class="form-control" placeholder="e.g., 120" value="<?= htmlspecialchars($current_overrides['max_execution_time']) ?>">
                </div>
                <div class="form-group">
                    <label>display_errors</label>
                    <select name="display_errors" class="form-control">
                        <option value="">(inherit)</option>
                        <option value="On" <?= $current_overrides['display_errors'] === 'On' ? 'selected' : '' ?>>On</option>
                        <option value="Off" <?= $current_overrides['display_errors'] === 'Off' ? 'selected' : '' ?>>Off</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 12px; display:flex; gap:12px;">
                <button type="submit" name="save_overrides" class="btn btn-primary"><i class="fas fa-save"></i> Save Overrides</button>
                <button type="submit" name="reset_overrides" class="btn btn-danger" onclick="return confirm('Remove .user.ini and reset overrides?')"><i class="fas fa-undo"></i> Reset Overrides</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
</body>
</html>
