<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$output_lines = [];

// Load domains for selection
$domains = [];
$res = mysqli_query($conn, "SELECT id, domain_name, document_root FROM domains ORDER BY domain_name");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) { $domains[] = $row; }
}

// Defaults from env with fallbacks
$wacs_path = env('WACS_PATH', '');
$certs_root = rtrim(env('WACS_CERTS_PATH', 'C:/wamp64/bin/apache/certs'), "/\\");
if (!is_dir($certs_root)) { @mkdir($certs_root, 0755, true); }

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

if ($_POST && isset($_POST['issue_ssl'])) {
    $domain_id = (int)($_POST['domain_id'] ?? 0);
    $include_www = !empty($_POST['include_www']);
    $email = trim($_POST['email'] ?? '');
    $write_vhost = !empty($_POST['write_vhost']);
    $restart_apache = !empty($_POST['restart_apache']);
    $update_db = !empty($_POST['update_db']);
    $create_task = !empty($_POST['create_task']);

    // Validate inputs
    $stmt = mysqli_prepare($conn, 'SELECT domain_name, document_root FROM domains WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $domain_id);
    mysqli_stmt_execute($stmt);
    $rs = mysqli_stmt_get_result($stmt);
    $dom = mysqli_fetch_assoc($rs);

    if (!$dom) {
        $message = '<div class="alert alert-error">Invalid domain selection.</div>';
    } elseif ($wacs_path === '' || !file_exists($wacs_path)) {
        $message = '<div class="alert alert-error">WACS_PATH not set or invalid. Define WACS_PATH in your .env (e.g., C:/tools/wacs/wacs.exe).</div>';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-error">Provide a valid contact email for Let\'s Encrypt.</div>';
    } else {
        $domain = $dom['domain_name'];
        $webroot = rtrim(str_replace('\\\\', '/', $dom['document_root']), '/');
        $hosts = $include_www ? ($domain . ',www.' . $domain) : $domain;
        $outdir = $certs_root . '/' . $domain;
        // If certbot is available, use standard Let's Encrypt live path on Linux
        $certbot_path = env('CERTBOT_BIN', '');
        $using_certbot = ($certbot_path !== '' && file_exists($certbot_path));
        if ($using_certbot) {
            $outdir = '/etc/letsencrypt/live/' . $domain;
        } else {
            if (!is_dir($outdir)) { @mkdir($outdir, 0755, true); }
        }

        // Build issuance command for Linux (certbot) or Windows (win-acme)
        if ($using_certbot) {
            // certbot certonly --webroot -w <webroot> -d domain [-d www.domain] --email <email> --agree-tos --non-interactive
            $cmd = '"' . $certbot_path . '" certonly --webroot -w ' . '"' . $webroot . '"' . ' -d ' . escapeshellarg($domain);
            if ($include_www) { $cmd .= ' -d ' . escapeshellarg('www.' . $domain); }
            $cmd .= ' --email ' . escapeshellarg($email) . ' --agree-tos --non-interactive 2>&1';
        } else {
            // Windows win-acme fallback
            $args = [
                '--accepttos',
                '--emailaddress', $email,
                '--target', 'manual',
                '--host', $hosts,
                '--validation', 'filesystem',
                '--webroot', $webroot,
                '--store', 'pemfiles',
                '--pemfilespath', $outdir,
                '--installation', 'none'
            ];
            $cmd = '"' . $wacs_path . '"';
            foreach ($args as $a) {
                if (preg_match('/[\s]/', $a)) { $cmd .= ' "' . str_replace('"', '\\"', $a) . '"'; }
                else { $cmd .= ' ' . $a; }
            }
            $cmd .= ' 2>&1';
        }

        $ret = 0;
        $output = [];
        @exec($cmd, $output, $ret);
        $output_lines = $output;

        if ($ret === 0) {
            $message = '<div class="alert alert-success">Certificate issuance command executed.</div>';

            // Optionally update database with the issued cert files
            if ($update_db) {
                $fullchain = $outdir . '/fullchain.pem';
                $privkey = $outdir . '/privkey.pem';
                $cabundle = $outdir . '/chain.pem';
                if (file_exists($fullchain) && file_exists($privkey)) {
                    $certificate = @file_get_contents($fullchain) ?: '';
                    $private_key = @file_get_contents($privkey) ?: '';
                    $ca_bundle = file_exists($cabundle) ? (@file_get_contents($cabundle) ?: '') : '';

                    $expires_at = null;
                    $status = 'active';
                    if (function_exists('openssl_x509_read') && function_exists('openssl_x509_parse')) {
                        $cert_res = @openssl_x509_read($certificate);
                        if ($cert_res) {
                            $parsed = @openssl_x509_parse($cert_res);
                            if ($parsed && isset($parsed['validTo_time_t'])) {
                                $expires_at = date('Y-m-d', (int)$parsed['validTo_time_t']);
                                if ($parsed['validTo_time_t'] < time()) { $status = 'expired'; }
                            }
                            // openssl_x509_free deprecated in PHP 8+: no manual free required
                        }
                    }

                    // Upsert into ssl_certificates
                    $q = "SELECT id FROM ssl_certificates WHERE domain_id = ? LIMIT 1";
                    $st = mysqli_prepare($conn, $q);
                    mysqli_stmt_bind_param($st, 'i', $domain_id);
                    mysqli_stmt_execute($st);
                    $rr = mysqli_stmt_get_result($st);
                    $existing = mysqli_fetch_assoc($rr);

                    if ($existing) {
                        $ssl_id = (int)$existing['id'];
                        $q = "UPDATE ssl_certificates SET certificate = ?, private_key = ?, ca_bundle = ?, expires_at = ?, status = ? WHERE id = ?";
                        $st = mysqli_prepare($conn, $q);
                        mysqli_stmt_bind_param($st, 'sssssi', $certificate, $private_key, $ca_bundle, $expires_at, $status, $ssl_id);
                        mysqli_stmt_execute($st);
                    } else {
                        $q = "INSERT INTO ssl_certificates (domain_id, certificate, private_key, ca_bundle, expires_at, status) VALUES (?, ?, ?, ?, ?, ?)";
                        $st = mysqli_prepare($conn, $q);
                        mysqli_stmt_bind_param($st, 'isssss', $domain_id, $certificate, $private_key, $ca_bundle, $expires_at, $status);
                        mysqli_stmt_execute($st);
                    }
                    $output_lines[] = '[INFO] Database updated with issued certificate.';
                } else {
                    $output_lines[] = '[WARN] Expected PEM files not found in ' . $outdir;
                }
            }

            // Optionally write Apache SSL vhost file
            if ($write_vhost) {
                $confFile = rtrim(APACHE_VHOST_PATH, '/\\') . '/vhost-ssl-' . $domain . '.conf';
                $aliases = $include_www ? ('\n    ServerAlias www.' . $domain) : '';
                $vhost = "\n<VirtualHost *:443>\n" .
                         "    ServerName $domain$aliases\n" .
                         "    DocumentRoot \"$webroot\"\n\n" .
                         "    SSLEngine on\n" .
                         "    SSLCertificateFile    \"$outdir/fullchain.pem\"\n" .
                         "    SSLCertificateKeyFile \"$outdir/privkey.pem\"\n\n" .
                         "    ErrorLog  \"logs/$domain-ssl-error.log\"\n" .
                         "    CustomLog \"logs/$domain-ssl-access.log\" common\n" .
                         "</VirtualHost>\n";
                @file_put_contents($confFile, $vhost);
                $output_lines[] = '[INFO] Wrote Apache SSL vhost file: ' . $confFile;

                if ($restart_apache) {
                    $restart_cmd = env('APACHE_RELOAD_CMD', '');
                    if ($restart_cmd === '') {
                        // Default to Linux systemctl, fallback to Windows service if not Linux
                        if (stripos(PHP_OS, 'WIN') === 0) {
                            $restart_cmd = 'net stop wampapache64 && net start wampapache64';
                        } else {
                            $restart_cmd = 'systemctl reload apache2';
                        }
                    }
                    $out = [];$rc = 0; @exec($restart_cmd . ' 2>&1', $out, $rc);
                    $output_lines[] = '[INFO] Apache reload command: ' . $restart_cmd;
                    $output_lines = array_merge($output_lines, $out);
                    if ($rc === 0) { $output_lines[] = '[OK] Apache reloaded successfully.'; }
                }
            }

            // Optionally create renewal task
            if ($create_task) {
                $taskName = 'Phynx-SSL-Renew';
                $renewCmd = '"' . $wacs_path . '" renew';
                $schtasks = 'schtasks /Create /TN "' . $taskName . '" /SC DAILY /RU SYSTEM /TR ' . escapeshellarg($renewCmd) . ' /F';
                $tout = [];$trc = 0; @exec($schtasks . ' 2>&1', $tout, $trc);
                $output_lines[] = '[INFO] Create task command: ' . $schtasks;
                $output_lines = array_merge($output_lines, $tout);
                if ($trc === 0) { $output_lines[] = '[OK] Renewal task created/updated.'; }
            }
        } else {
            $message = '<div class="alert alert-error">Certificate issuance reported errors. Review command output below.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SSL Automation - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1>SSL Automation (Let\'s Encrypt via win-acme)</h1>

    <div class="card">
        <div class="card-body">
            <div style="margin-bottom: 12px;">
                <strong>WACS Path:</strong> <?= htmlspecialchars($wacs_path ?: '(not set)') ?><br>
                <strong>Certificates Directory:</strong> <?= htmlspecialchars($certs_root) ?>
            </div>
            <?php if ($wacs_path === '' || !file_exists($wacs_path)): ?>
            <div class="alert alert-error">Set WACS_PATH in your .env to the full path of wacs.exe (e.g., C:/tools/wacs/wacs.exe).</div>
            <?php endif; ?>
        </div>
    </div>

    <?= $message ?>

    <div class="card">
        <h3>Issue Certificate</h3>
        <form method="post">
            <?php csrf_field(); ?>
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Domain</label>
                    <select name="domain_id" class="form-control" required>
                        <option value="">Select Domain</option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['domain_name']) ?> (root: <?= htmlspecialchars($d['document_root']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Include www</label>
                    <select name="include_www" class="form-control">
                        <option value="1">Yes</option>
                        <option value="0" selected>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars(env('ADMIN_EMAIL', 'admin@example.com')) ?>" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 12px;">
                <label class="checkbox-group">
                    <input type="checkbox" name="update_db" value="1" checked>
                    <span class="checkbox-custom"></span>
                    <span class="checkbox-label">
                        <span class="checkbox-text">Update database record</span>
                        <span class="checkbox-subtext">Update SSL status in database</span>
                    </span>
                </label>
                <label class="checkbox-group">
                    <input type="checkbox" name="write_vhost" value="1" checked>
                    <span class="checkbox-custom"></span>
                    <span class="checkbox-label">
                        <span class="checkbox-text">Write Apache SSL vhost</span>
                        <span class="checkbox-subtext">Create SSL virtual host configuration</span>
                    </span>
                </label>
                <label class="checkbox-group">
                    <input type="checkbox" name="restart_apache" value="1">
                    <span class="checkbox-custom"></span>
                    <span class="checkbox-label">
                        <span class="checkbox-text">Restart Apache</span>
                        <span class="checkbox-subtext">Restart Apache to apply changes</span>
                    </span>
                </label>
                <label class="checkbox-group">
                    <input type="checkbox" name="create_task" value="1" checked>
                    <span class="checkbox-custom"></span>
                    <span class="checkbox-label">
                        <span class="checkbox-text">Create renewal task</span>
                        <span class="checkbox-subtext">Schedule automatic certificate renewal</span>
                    </span>
                </label>
            </div>
            <button type="submit" name="issue_ssl" class="btn btn-primary">Issue Certificate</button>
        </form>
    </div>

    <div class="card">
        <h3>Renewal Task Status</h3>
        <div class="card-body">
            <?php
            $taskName = 'Phynx-SSL-Renew';
            $status = [];$src = 0; @exec('schtasks /Query /TN "' . $taskName . '" 2>&1', $status, $src);
            if ($src === 0) {
                echo '<pre style="white-space: pre-wrap;">' . htmlspecialchars(implode("\n", $status)) . '</pre>';
            } else {
                echo '<p class="help-text">No renewal task found. You can create it by checking "Create renewal task" above and issuing a certificate.</p>';
            }
            ?>
        </div>
    </div>

    <?php if (!empty($output_lines)): ?>
    <div class="card">
        <h3>Command Output</h3>
        <pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $output_lines)) ?></pre>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Apache SSL VHost Snippet</h3>
        <p class="help-text">After issuance, PEM files are stored under the Certificates Directory in a folder named after the domain. Update your Apache vhost configuration and restart Apache. Example:</p>
        <pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;">&lt;VirtualHost *:443&gt;
    ServerName example.com
    ServerAlias www.example.com
    DocumentRoot "C:/wamp64/www/example.com"

    SSLEngine on
    SSLCertificateFile    "C:/wamp64/bin/apache/certs/example.com/fullchain.pem"
    SSLCertificateKeyFile "C:/wamp64/bin/apache/certs/example.com/privkey.pem"

    ErrorLog  "logs/example.com-ssl-error.log"
    CustomLog "logs/example.com-ssl-access.log" common
&lt;/VirtualHost&gt;</pre>
        <p class="help-text">Ensure mod_ssl is enabled and Apache listens on port 443.</p>
    </div>
</div>
</body>
</html>
