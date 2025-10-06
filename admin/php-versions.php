<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$notices = [];

// Load domains
$domains = [];
$res = mysqli_query($conn, "SELECT id, domain_name, document_root FROM domains ORDER BY domain_name");
if ($res) { while ($row = mysqli_fetch_assoc($res)) { $domains[] = $row; } }

// Detect PHP-FPM sockets on Ubuntu
function detect_php_fpm_sockets(): array {
    $candidates = [
        '/run/php/php*-fpm.sock',
        '/var/run/php/php*-fpm.sock',
        '/run/php/*fpm*.sock'
    ];
    $list = [];
    foreach ($candidates as $pat) {
        foreach (glob($pat) ?: [] as $sock) {
            if (is_file($sock)) $list[$sock] = true;
        }
    }
    $socks = array_keys($list);
    sort($socks, SORT_STRING);
    return $socks;
}

// Mapping storage
function versions_map_path(): string {
    $p = realpath(__DIR__ . '/../config');
    if ($p === false) { $p = __DIR__ . '/../config'; @mkdir($p, 0755, true); }
    return rtrim($p, '/\\') . '/php-versions.json';
}
function load_versions_map(): array {
    $f = versions_map_path();
    if (is_file($f)) {
        $data = json_decode((string)@file_get_contents($f), true);
        if (is_array($data)) return $data;
    }
    return [];
}
function save_versions_map(array $map): bool {
    $f = versions_map_path();
    return @file_put_contents($f, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

// Vhost patching
function build_php_handler_block(string $socket): string {
    $socket = trim($socket);
    $block = "# BEGIN PHP_HANDLER\n";
    $block .= "<FilesMatch \\\"\\\\.php$\\\">\n";
    $block .= "    SetHandler \"proxy:unix:$socket|fcgi://localhost/\"\n";
    $block .= "</FilesMatch>\n";
    $block .= "# END PHP_HANDLER\n";
    return $block;
}
function patch_vhost_file(string $vhostPath, string $socket): array {
    $out = ['ok' => false, 'msg' => '', 'path' => $vhostPath];
    if (!is_file($vhostPath)) { $out['msg'] = 'VHost file not found'; return $out; }
    $conf = @file_get_contents($vhostPath);
    if ($conf === false) { $out['msg'] = 'Failed to read vhost file'; return $out; }

    $block = build_php_handler_block($socket);
    if (preg_match('/# BEGIN PHP_HANDLER.*?# END PHP_HANDLER/s', $conf)) {
        $conf = preg_replace('/# BEGIN PHP_HANDLER.*?# END PHP_HANDLER/s', $block, $conf);
    } else {
        // Insert before each </VirtualHost>
        $conf = preg_replace('/<\/VirtualHost>/', $block . "\n</VirtualHost>", $conf, -1);
    }
    if (@file_put_contents($vhostPath, $conf) === false) { $out['msg'] = 'Failed to write vhost file'; return $out; }
    $out['ok'] = true; $out['msg'] = 'VHost updated';
    return $out;
}

// Handle POST
$selected_domain_id = (int)($_POST['domain_id'] ?? ($_GET['domain_id'] ?? 0));
$selected = null;
if ($selected_domain_id > 0) {
    $st = mysqli_prepare($conn, 'SELECT id, domain_name, document_root FROM domains WHERE id = ?');
    mysqli_stmt_bind_param($st, 'i', $selected_domain_id);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $selected = mysqli_fetch_assoc($rs) ?: null;
}

$map = load_versions_map();
$sockets = detect_php_fpm_sockets();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
    if (!$selected) {
        $message = '<div class="alert alert-error">Select a valid domain.</div>';
    } else {
        $socket = trim($_POST['socket'] ?? '');
        if ($socket === '') {
            $message = '<div class="alert alert-error">Select or enter a PHP-FPM socket path.</div>';
        } else {
            $domain = $selected['domain_name'];
            $vhost = rtrim(APACHE_VHOST_PATH, '/\\') . '/vhost-' . $domain . '.conf';
            $patch = patch_vhost_file($vhost, $socket);
            if ($patch['ok']) {
                // Save mapping
                $map[(string)$selected['id']] = ['domain' => $domain, 'socket' => $socket];
                save_versions_map($map);
                // Reload Apache
                $reload = env('APACHE_RELOAD_CMD', 'systemctl reload apache2');
                $out = [];$rc = 0; @exec($reload . ' 2>&1', $out, $rc);
                $notices[] = '[INFO] Reload command: ' . $reload;
                $notices = array_merge($notices, $out);
                if ($rc === 0) {
                    $message = '<div class="alert alert-success">PHP handler updated and Apache reloaded.</div>';
                } else {
                    $message = '<div class="alert alert-error">VHost updated, but Apache reload failed. Review output below and run config test.</div>';
                }
            } else {
                $message = '<div class="alert alert-error">' . htmlspecialchars($patch['msg']) . '</div>';
            }
        }
    }
}

$current_socket = '';
if ($selected) {
    $cur = $map[(string)$selected['id']] ?? null;
    if ($cur && isset($cur['socket'])) $current_socket = $cur['socket'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>PHP Versions - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <h1>PHP Versions (PHP-FPM)</h1>

    <div class="card">
        <div class="card-body">
            <p class="help-text">This configures Apache to forward .php requests to a PHP-FPM socket using proxy_fcgi. Ensure Apache modules proxy and proxy_fcgi are enabled and PHP-FPM is installed for the selected version.</p>
            <ul class="help-text" style="margin-top:8px;">
                <li>Enable modules: <code>a2enmod proxy proxy_fcgi setenvif rewrite</code></li>
                <li>Reload Apache: <code><?= htmlspecialchars(env('APACHE_RELOAD_CMD', 'systemctl reload apache2')) ?></code></li>
                <li>Detected sockets: <?= $sockets ? '<code>' . htmlspecialchars(implode(', ', $sockets)) . '</code>' : '<em>None detected</em>' ?></li>
            </ul>
        </div>
    </div>

    <?= $message ?>
    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="card">
        <h3>Per-domain PHP-FPM Socket</h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Domain</label>
                    <select name="domain_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Select Domain</option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $selected && (int)$selected['id'] === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['domain_name']) ?> (root: <?= htmlspecialchars($d['document_root']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Current Socket</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($current_socket) ?>" readonly>
                </div>
            </div>

            <?php if ($selected): ?>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Select Detected Socket</label>
                    <select name="socket" class="form-control">
                        <option value="">Select or enter custom path</option>
                        <?php foreach ($sockets as $sock): ?>
                            <option value="<?= htmlspecialchars($sock) ?>" <?= $sock === $current_socket ? 'selected' : '' ?>><?= htmlspecialchars($sock) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Or Enter Custom Socket Path</label>
                    <input type="text" name="socket_custom" class="form-control" placeholder="/run/php/php8.2-fpm.sock" oninput="if(this.value){document.querySelector('select[name=socket]').value='';}" value="">
                </div>
            </div>
            <script>
            // Merge custom input into the submitted socket value
            document.addEventListener('submit', function(e){
                var sel = document.querySelector('select[name=socket]');
                var custom = document.querySelector('input[name=socket_custom]');
                if (custom && custom.value.trim() !== '') {
                    // Create hidden socket field with custom value
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = 'socket'; hidden.value = custom.value.trim();
                    e.target.appendChild(hidden);
                }
            });
            </script>
            <div style="margin-top: 12px;">
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>What this changes</h3>
        <p class="help-text">In the domain's vhost file (<?= htmlspecialchars(rtrim(APACHE_VHOST_PATH, '/\\')) ?>/vhost-&lt;domain&gt;.conf) we insert this block inside each &lt;VirtualHost&gt;:</p>
        <pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"># BEGIN PHP_HANDLER
&lt;FilesMatch "\.php$"&gt;
    SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost/"
&lt;/FilesMatch&gt;
# END PHP_HANDLER</pre>
    </div>
</div>
</body>
</html>
