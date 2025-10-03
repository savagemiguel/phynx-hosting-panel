<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Get user package
$package = getUserPackage($conn, $user_id);

// Get active user domains for selection
$domains = [];
$query = "SELECT id, domain_name FROM domains WHERE user_id = ? AND status = 'active' ORDER BY domain_name";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$domains = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Count user's SSL certificates (via domains join)
$query = "SELECT COUNT(*) as count FROM ssl_certificates sc JOIN domains d ON sc.domain_id = d.id WHERE d.user_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$ssl_count = (int) (mysqli_fetch_assoc($result)['count'] ?? 0);

if ($_POST && isset($_POST['create_ssl'])) {
    if (!$package) {
        $message = '<div class="alert alert-error">No package assigned.</div>';
    } elseif ($package['ssl_certificates'] > 0 && $ssl_count >= $package['ssl_certificates']) {
        $message = '<div class="alert alert-error">SSL certificate limit reached for your package.</div>';
    } else {
        $domain_id = isset($_POST['domain_id']) ? (int) $_POST['domain_id'] : 0;
        $certificate = $_POST['certificate'] ?? '';
        $private_key = $_POST['private_key'] ?? '';
        $ca_bundle = $_POST['ca_bundle'] ?? '';

        // Validate domain ownership
        $q = "SELECT id FROM domains WHERE id = ? AND user_id = ? AND status = 'active'";
        $stmt = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($stmt, "ii", $domain_id, $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $owns_domain = mysqli_fetch_assoc($res);

        if (!$owns_domain) {
            $message = '<div class="alert alert-error">Invalid domain selection.</div>';
        } elseif (stripos($certificate, 'BEGIN CERTIFICATE') === false || stripos($private_key, 'PRIVATE KEY') === false) {
            $message = '<div class="alert alert-error">Please provide a valid certificate and private key in PEM format.</div>';
        } else {
            // Attempt to parse expiry from certificate
            $expires_at = null;
            $status = 'active';
            if (function_exists('openssl_x509_read') && function_exists('openssl_x509_parse')) {
                $cert_res = @openssl_x509_read($certificate);
                if ($cert_res) {
                    $parsed = @openssl_x509_parse($cert_res);
                    if ($parsed && isset($parsed['validTo_time_t'])) {
                        $expires_at = date('Y-m-d', (int)$parsed['validTo_time_t']);
                        if ($parsed['validTo_time_t'] < time()) {
                            $status = 'expired';
                        }
                    }
                    // openssl_x509_free($cert_res);
                }
            }

            // Check if an SSL record already exists for this domain (one per domain)
            $q = "SELECT id FROM ssl_certificates WHERE domain_id = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $q);
            mysqli_stmt_bind_param($stmt, "i", $domain_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $existing = mysqli_fetch_assoc($res);

            if ($existing) {
                $ssl_id = (int)$existing['id'];
                $q = "UPDATE ssl_certificates SET certificate = ?, private_key = ?, ca_bundle = ?, expires_at = ?, status = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $q);
                mysqli_stmt_bind_param($stmt, "sssssi", $certificate, $private_key, $ca_bundle, $expires_at, $status, $ssl_id);
                $ok = mysqli_stmt_execute($stmt);
            } else {
                $q = "INSERT INTO ssl_certificates (domain_id, certificate, private_key, ca_bundle, expires_at, status) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $q);
                mysqli_stmt_bind_param($stmt, "isssss", $domain_id, $certificate, $private_key, $ca_bundle, $expires_at, $status);
                $ok = mysqli_stmt_execute($stmt);
                if ($ok) {
                    $ssl_count++;
                }
            }

            if (!empty($ok)) {
                // Enable SSL flag on domain
                $q = "UPDATE domains SET ssl_enabled = 1 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $q);
                mysqli_stmt_bind_param($stmt, "i", $domain_id);
                mysqli_stmt_execute($stmt);

                $message = '<div class="alert alert-success">SSL certificate saved successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to save SSL certificate.</div>';
            }
        }
    }
}

// Fetch user's SSL certificates list
$query = "SELECT sc.*, d.domain_name FROM ssl_certificates sc JOIN domains d ON sc.domain_id = d.id WHERE d.user_id = ? ORDER BY sc.created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$ssl_list = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>SSL Certificates - Hosting Panel</title>
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
            <li><a href="ssl.php" class="active">SSL Certificates</a></li>
            <li><a href="backups.php">Backups</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <h1>SSL Certificates</h1>

        <?= $message ?>

        <?php if ($domains && $package && ($package['ssl_certificates'] == 0 || $ssl_count < $package['ssl_certificates'])): ?>
        <div class="card">
            <h3>Add / Update SSL Certificate</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Domain</label>
                    <select name="domain_id" class="form-control" required>
                        <option value="">Select Domain</option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['domain_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Certificate (PEM)</label>
                        <textarea name="certificate" class="form-control" rows="10" placeholder="-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Private Key (PEM)</label>
                        <textarea name="private_key" class="form-control" rows="10" placeholder="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----" required></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label>CA Bundle (optional)</label>
                    <textarea name="ca_bundle" class="form-control" rows="6" placeholder="Intermediate certificates in PEM format (optional)"></textarea>
                </div>
                <button type="submit" name="create_ssl" class="btn btn-primary">Save Certificate</button>
            </form>
        </div>
        <?php elseif (!$domains): ?>
        <div class="alert alert-error">You need an active domain before adding an SSL certificate.</div>
        <?php elseif ($package): ?>
        <div class="alert alert-error">You have reached your SSL certificate limit (<?= $package['ssl_certificates'] == 0 ? 'Unlimited' : (int)$package['ssl_certificates'] ?>).</div>
        <?php else: ?>
        <div class="alert alert-error">No package assigned. Contact administrator to assign a hosting package.</div>
        <?php endif; ?>

        <div class="card">
            <h3>My SSL Certificates (<?= count($ssl_list) ?><?= $package ? '/' . ($package['ssl_certificates'] == 0 ? 'Unlimited' : (int)$package['ssl_certificates']) : '' ?>)</h3>
            <?php if ($ssl_list): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ssl_list as $ssl): ?>
                    <tr>
                        <td><?= htmlspecialchars($ssl['domain_name']) ?></td>
                        <td><?= $ssl['expires_at'] ? htmlspecialchars(date('M j, Y', strtotime($ssl['expires_at']))) : 'Unknown' ?></td>
                        <td>
                            <span style="color: <?= ($ssl['status'] === 'active') ? 'var(--success-color)' : 'var(--error-color)' ?>;">
                                <?= htmlspecialchars(ucfirst($ssl['status'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($ssl['created_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 32px;">No SSL certificates found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
