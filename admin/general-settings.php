<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$notices = [];

function env_file_path(): string {
    $p = realpath(__DIR__ . '/../.env');
    return $p ?: (__DIR__ . '/../.env');
}

function env_load_pairs(): array {
    $file = env_file_path();
    $pairs = [];
    if (is_file($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        foreach ((array)$lines as $line) {
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k,$v] = explode('=', $line, 2);
            $pairs[trim($k)] = trim($v);
        }
    }
    return $pairs;
}

function env_set_many(array $updates, array &$log): bool {
    $file = env_file_path();
    $buf = is_file($file) ? file_get_contents($file) : '';
    if ($buf === false) { $log[] = 'Failed to read .env'; return false; }
    $lines = explode("\n", (string)$buf);
    $map = [];
    foreach ($lines as $i => $line) {
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos !== false) {
            $k = substr($line, 0, $pos);
            $map[$k] = $i;
        }
    }
    foreach ($updates as $k => $v) {
        $v = (string)$v;
        // Escape values containing spaces or special chars by leaving raw; .env parser expects raw
        $pair = $k . '=' . $v;
        if (isset($map[$k])) {
            $lines[$map[$k]] = $pair;
        } else {
            $lines[] = $pair;
        }
    }
    $new = implode("\n", $lines);
    if (file_put_contents($file, $new) === false) { $log[] = 'Failed to write .env'; return false; }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
    $updates = [
        'SITE_URL' => trim($_POST['SITE_URL'] ?? ''),
        'ADMIN_EMAIL' => trim($_POST['ADMIN_EMAIL'] ?? ''),
        'APACHE_VHOST_PATH' => trim($_POST['APACHE_VHOST_PATH'] ?? ''),
        'DNS_ZONE_PATH' => trim($_POST['DNS_ZONE_PATH'] ?? ''),
        'WEB_ROOT' => trim($_POST['WEB_ROOT'] ?? ''),
        'CERTBOT_BIN' => trim($_POST['CERTBOT_BIN'] ?? ''),
        'APACHE_RELOAD_CMD' => trim($_POST['APACHE_RELOAD_CMD'] ?? ''),
        'DOCKER_CLI_PATH' => trim($_POST['DOCKER_CLI_PATH'] ?? ''),
        'DOCKER_TEMPLATES_DIR' => trim($_POST['DOCKER_TEMPLATES_DIR'] ?? ''),
        'DOCKER_STACKS_DIR' => trim($_POST['DOCKER_STACKS_DIR'] ?? ''),
    ];
    $ok = env_set_many($updates, $notices);
    if ($ok) {
        $message = '<div class="alert alert-success">Settings saved to .env. Reload the app to ensure changes take effect.</div>';
    } else {
        $message = '<div class="alert alert-error">Failed to save settings. ' . htmlspecialchars(implode('; ', $notices)) . '</div>';
    }
}

$pairs = env_load_pairs();

$defaults = [
    'SITE_URL' => env('SITE_URL', ''),
    'ADMIN_EMAIL' => env('ADMIN_EMAIL', ''),
    'APACHE_VHOST_PATH' => env('APACHE_VHOST_PATH', ''),
    'DNS_ZONE_PATH' => env('DNS_ZONE_PATH', ''),
    'WEB_ROOT' => env('WEB_ROOT', ''),
    'CERTBOT_BIN' => env('CERTBOT_BIN', ''),
    'APACHE_RELOAD_CMD' => env('APACHE_RELOAD_CMD', ''),
    'DOCKER_CLI_PATH' => env('DOCKER_CLI_PATH', ''),
    'DOCKER_TEMPLATES_DIR' => env('DOCKER_TEMPLATES_DIR', ''),
    'DOCKER_STACKS_DIR' => env('DOCKER_STACKS_DIR', ''),
];

function fv($key, $pairs, $defaults) { return htmlspecialchars($pairs[$key] ?? $defaults[$key] ?? ''); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>General Settings - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1>General Settings</h1>

    <?= $message ?>

    <div class="card">
        <h3>Application</h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>SITE_URL</label>
                    <input type="text" name="SITE_URL" class="form-control" value="<?= fv('SITE_URL', $pairs, $defaults) ?>" placeholder="https://panel.example.com">
                </div>
                <div class="form-group">
                    <label>ADMIN_EMAIL</label>
                    <input type="email" name="ADMIN_EMAIL" class="form-control" value="<?= fv('ADMIN_EMAIL', $pairs, $defaults) ?>" placeholder="admin@example.com">
                </div>
            </div>

            <h3 style="margin-top:16px;">Paths</h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>APACHE_VHOST_PATH</label>
                    <input type="text" name="APACHE_VHOST_PATH" class="form-control" value="<?= fv('APACHE_VHOST_PATH', $pairs, $defaults) ?>" placeholder="/etc/apache2/sites-available">
                </div>
                <div class="form-group">
                    <label>DNS_ZONE_PATH</label>
                    <input type="text" name="DNS_ZONE_PATH" class="form-control" value="<?= fv('DNS_ZONE_PATH', $pairs, $defaults) ?>" placeholder="/etc/bind/zones">
                </div>
                <div class="form-group">
                    <label>WEB_ROOT</label>
                    <input type="text" name="WEB_ROOT" class="form-control" value="<?= fv('WEB_ROOT', $pairs, $defaults) ?>" placeholder="/var/www/">
                </div>
            </div>

            <h3 style="margin-top:16px;">SSL / Web Server</h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>CERTBOT_BIN</label>
                    <input type="text" name="CERTBOT_BIN" class="form-control" value="<?= fv('CERTBOT_BIN', $pairs, $defaults) ?>" placeholder="/usr/bin/certbot">
                </div>
                <div class="form-group">
                    <label>APACHE_RELOAD_CMD</label>
                    <input type="text" name="APACHE_RELOAD_CMD" class="form-control" value="<?= fv('APACHE_RELOAD_CMD', $pairs, $defaults) ?>" placeholder="systemctl reload apache2">
                </div>
            </div>

            <h3 style="margin-top:16px;">Docker</h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>DOCKER_CLI_PATH</label>
                    <input type="text" name="DOCKER_CLI_PATH" class="form-control" value="<?= fv('DOCKER_CLI_PATH', $pairs, $defaults) ?>" placeholder="/usr/bin/docker">
                </div>
                <div class="form-group">
                    <label>DOCKER_TEMPLATES_DIR</label>
                    <input type="text" name="DOCKER_TEMPLATES_DIR" class="form-control" value="<?= fv('DOCKER_TEMPLATES_DIR', $pairs, $defaults) ?>" placeholder="/var/www/phynx/hosting-panel/docker-templates">
                </div>
                <div class="form-group">
                    <label>DOCKER_STACKS_DIR</label>
                    <input type="text" name="DOCKER_STACKS_DIR" class="form-control" value="<?= fv('DOCKER_STACKS_DIR', $pairs, $defaults) ?>" placeholder="/var/www/phynx/hosting-panel/docker-stacks">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>

    <div class="card">
        <h3>Environment File</h3>
        <div class="card-body">
            <div>.env path: <code><?= htmlspecialchars(env_file_path()) ?></code></div>
            <p class="help-text" style="margin-top:8px;">These settings are saved to the .env file. Reload the application or relevant services to apply changes where needed.</p>
        </div>
    </div>
</div>
</body>
</html>
