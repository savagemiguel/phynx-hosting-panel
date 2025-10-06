<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$notices = [];

$tpl_dir = realpath(__DIR__ . '/../config/vhost-templates');
if ($tpl_dir === false) {
    $tpl_dir = __DIR__ . '/../config/vhost-templates';
    @mkdir($tpl_dir, 0755, true);
}

function list_templates($dir): array {
    $out = [];
    foreach (glob(rtrim($dir, '/\\') . '/*.conf') ?: [] as $file) {
        $out[] = [
            'name' => basename($file, '.conf'),
            'path' => $file,
            'mtime' => filemtime($file)
        ];
    }
    usort($out, function($a,$b){return $a['name'] <=> $b['name'];});
    return $out;
}

function sanitize_slug($s) {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9-_]/', '-', $s);
    return trim(preg_replace('/-+/', '-', $s), '-');
}

function replace_vars($tpl, $vars) {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . strtoupper($k) . '}', $v, $tpl);
    }
    return $tpl;
}

// Load domains for application
$domains = [];
$res = mysqli_query($conn, "SELECT id, domain_name, document_root FROM domains ORDER BY domain_name");
if ($res) { while ($row = mysqli_fetch_assoc($res)) { $domains[] = $row; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Handle create/update/delete/apply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create or update template
    if (isset($_POST['save_template'])) {
        $name = sanitize_slug($_POST['name'] ?? '');
        $content = (string)($_POST['content'] ?? '');
        if ($name === '') {
            $message = '<div class="alert alert-error">Template name is required.</div>';
        } else {
            $file = rtrim($tpl_dir, '/\\') . '/' . $name . '.conf';
            if (@file_put_contents($file, $content) !== false) {
                $message = '<div class="alert alert-success">Template saved.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to save template file.</div>';
            }
        }
    }

    if (isset($_POST['delete_template'])) {
        $name = sanitize_slug($_POST['name'] ?? '');
        if ($name !== '') {
            $file = rtrim($tpl_dir, '/\\') . '/' . $name . '.conf';
            if (is_file($file) && @unlink($file)) {
                $message = '<div class="alert alert-success">Template deleted.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to delete template.</div>';
            }
        }
    }

    if (isset($_POST['apply_template'])) {
        $tpl_name = sanitize_slug($_POST['tpl_name'] ?? '');
        $domain_id = (int)($_POST['domain_id'] ?? 0);
        if ($tpl_name === '' || $domain_id <= 0) {
            $message = '<div class="alert alert-error">Select a template and a domain.</div>';
        } else {
            // Load template
            $file = rtrim($tpl_dir, '/\\') . '/' . $tpl_name . '.conf';
            if (!is_file($file)) {
                $message = '<div class="alert alert-error">Template file not found.</div>';
            } else {
                $tpl = file_get_contents($file);
                // Get domain
                $st = mysqli_prepare($conn, 'SELECT domain_name, document_root FROM domains WHERE id = ?');
                mysqli_stmt_bind_param($st, 'i', $domain_id);
                mysqli_stmt_execute($st);
                $rs = mysqli_stmt_get_result($st);
                $dom = mysqli_fetch_assoc($rs);
                if (!$dom) {
                    $message = '<div class="alert alert-error">Domain not found.</div>';
                } else {
                    $domain = $dom['domain_name'];
                    $docroot = rtrim(str_replace('\\\\','/',$dom['document_root']), '/');
                    // Vars
                    $vars = [
                        'domain' => $domain,
                        'docroot' => $docroot,
                        'cert_path' => '/etc/letsencrypt/live/' . $domain,
                    ];
                    $rendered = replace_vars($tpl, $vars);
                    $vhost_dir = rtrim(APACHE_VHOST_PATH, '/\\');
                    $out_file = $vhost_dir . '/vhost-' . $domain . '.conf';
                    if (@file_put_contents($out_file, $rendered) !== false) {
                        $message = '<div class="alert alert-success">VHost file written: ' . htmlspecialchars($out_file) . '</div>';
                        // Reload Apache
                        $reload = env('APACHE_RELOAD_CMD', 'systemctl reload apache2');
                        $out = [];$rc = 0; @exec($reload . ' 2>&1', $out, $rc);
                        $notices[] = '[INFO] Reload command: ' . $reload;
                        $notices = array_merge($notices, $out);
                        if ($rc === 0) { $notices[] = '[OK] Apache reloaded.'; }
                    } else {
                        $message = '<div class="alert alert-error">Failed to write vhost file. Check permissions.</div>';
                    }
                }
            }
        }
    }
}

// Editing existing template
$edit_name = isset($_GET['edit']) ? sanitize_slug($_GET['edit']) : '';
$edit_content = '';
if ($edit_name !== '') {
    $f = rtrim($tpl_dir, '/\\') . '/' . $edit_name . '.conf';
    if (is_file($f)) { $edit_content = file_get_contents($f) ?: ''; }
}

$templates = list_templates($tpl_dir);

$placeholder_example = <<<CONF
# Available placeholders: {DOMAIN}, {DOCROOT}, {CERT_PATH}
<VirtualHost *:80>
    ServerName {DOMAIN}
    ServerAlias www.{DOMAIN}
    DocumentRoot {DOCROOT}

    ErrorLog  "/var/log/apache2/{DOMAIN}-error.log"
    CustomLog "/var/log/apache2/{DOMAIN}-access.log" combined

    <Directory {DOCROOT}>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName {DOMAIN}
    ServerAlias www.{DOMAIN}
    DocumentRoot {DOCROOT}

    SSLEngine on
    SSLCertificateFile    "{CERT_PATH}/fullchain.pem"
    SSLCertificateKeyFile "{CERT_PATH}/privkey.pem"

    ErrorLog  "/var/log/apache2/{DOMAIN}-ssl-error.log"
    CustomLog "/var/log/apache2/{DOMAIN}-ssl-access.log" combined

    <Directory {DOCROOT}>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
</IfModule>
CONF;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>VHost Templates - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <h1>Apache VHost Templates</h1>

    <?= $message ?>
    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h3><?= $edit_name ? ('Edit Template: ' . htmlspecialchars($edit_name)) : 'Create Template' ?></h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Template Name (slug)</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($edit_name) ?>" placeholder="e.g., wordpress" required>
                </div>
                <div class="form-group">
                    <label>Template Content (.conf)</label>
                    <textarea name="content" class="form-control" rows="16" placeholder="<?= htmlspecialchars($placeholder_example) ?>"><?= htmlspecialchars($edit_content) ?></textarea>
                </div>
                <div style="display:flex; gap:12px;">
                    <button class="btn btn-primary" name="save_template" value="1" type="submit">Save Template</button>
                    <?php if ($edit_name): ?>
                    <button class="btn btn-danger" name="delete_template" value="1" type="submit" onclick="return confirm('Delete this template?')">Delete</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Apply Template to Domain</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label>Template</label>
                        <select name="tpl_name" class="form-control" required>
                            <option value="">Select Template</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= htmlspecialchars($t['name']) ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Domain</label>
                        <select name="domain_id" class="form-control" required>
                            <option value="">Select Domain</option>
                            <?php foreach ($domains as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['domain_name']) ?> (root: <?= htmlspecialchars($d['document_root']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary" name="apply_template" value="1" type="submit">Apply and Reload Apache</button>
            </form>
            <p class="help-text" style="margin-top:8px;">VHost files are written to <?= htmlspecialchars(rtrim(APACHE_VHOST_PATH, '/\\')) ?> as vhost-&lt;domain&gt;.conf. Placeholders: {DOMAIN}, {DOCROOT}, {CERT_PATH}.</p>
        </div>

        <div class="card">
            <h3>Existing Templates (<?= count($templates) ?>)</h3>
            <?php if ($templates): ?>
                <table class="table">
                    <thead><tr><th>Name</th><th>Modified</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($templates as $t): ?>
                            <tr>
                                <td><?= htmlspecialchars($t['name']) ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', $t['mtime'])) ?></td>
                                <td class="actions-cell">
                                    <a class="btn btn-primary" href="vhost-templates.php?edit=<?= urlencode($t['name']) ?>" style="padding:6px 12px; font-size:12px;">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: var(--text-secondary);">No templates yet. Create one on the left.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
