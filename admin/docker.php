<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/docker.php';
requireAdmin();

$message = '';
$notices = [];

// CSRF verify for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

// Handle container actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['container_action'] ?? '';
    $name = trim($_POST['container_name'] ?? '');
    if ($action && $name !== '') {
        switch ($action) {
            case 'start':
                [$rc, $out, $cmd] = docker_cmd(['start', $name]);
                $notices[] = ($rc === 0 ? '[OK] Started ' : '[ERR] Failed to start ') . htmlspecialchars($name);
                break;
            case 'stop':
                [$rc, $out, $cmd] = docker_cmd(['stop', $name]);
                $notices[] = ($rc === 0 ? '[OK] Stopped ' : '[ERR] Failed to stop ') . htmlspecialchars($name);
                break;
            case 'restart':
                [$rc, $out, $cmd] = docker_cmd(['restart', $name]);
                $notices[] = ($rc === 0 ? '[OK] Restarted ' : '[ERR] Failed to restart ') . htmlspecialchars($name);
                break;
            case 'remove':
                [$rc, $out, $cmd] = docker_cmd(['rm', '-f', $name]);
                $notices[] = ($rc === 0 ? '[OK] Removed ' : '[ERR] Failed to remove ') . htmlspecialchars($name);
                break;
        }
    }
}

// Gather Docker info
$info = docker_info();
$ps = docker_ps(true);
$images = docker_images();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Docker - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1>Docker</h1>

    <?php if (!empty($message)): ?>
        <?= $message ?>
    <?php endif; ?>

    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <div class="card-header"><h3>Engine Info</h3></div>
            <div class="card-body">
                <?php if ($info['ok']): ?>
                    <pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $info['output'])) ?></pre>
                <?php else: ?>
                    <div class="alert alert-error">Unable to retrieve Docker info. Check DOCKER_CLI_PATH and permissions.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>Images</h3></div>
            <div class="card-body">
                <?php if ($images['ok'] && !empty($images['images'])): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Repository</th>
                                <th>Tag</th>
                                <th>Image ID</th>
                                <th>Size</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($images['images'] as $img): ?>
                            <tr>
                                <td><?= htmlspecialchars($img['Repository'] ?? '') ?></td>
                                <td><?= htmlspecialchars($img['Tag'] ?? '') ?></td>
                                <td><?= htmlspecialchars($img['ID'] ?? '') ?></td>
                                <td><?= htmlspecialchars($img['Size'] ?? '') ?></td>
                                <td><?= htmlspecialchars($img['CreatedSince'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: var(--text-secondary);">No images found or cannot access Docker.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Containers</h3></div>
        <div class="card-body">
            <?php if ($ps['ok'] && !empty($ps['containers'])): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Image</th>
                            <th>Status</th>
                            <th>Ports</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ps['containers'] as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['Names'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['Image'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['Status'] ?? ($c['State'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($c['Ports'] ?? '') ?></td>
                            <td class="actions-cell">
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="container_name" value="<?= htmlspecialchars($c['Names'] ?? '') ?>">
                                    <button class="btn btn-success" name="container_action" value="start">Start</button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="container_name" value="<?= htmlspecialchars($c['Names'] ?? '') ?>">
                                    <button class="btn btn-warning" name="container_action" value="restart">Restart</button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="container_name" value="<?= htmlspecialchars($c['Names'] ?? '') ?>">
                                    <button class="btn btn-danger" name="container_action" value="stop">Stop</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this container?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="container_name" value="<?= htmlspecialchars($c['Names'] ?? '') ?>">
                                    <button class="btn btn-danger" name="container_action" value="remove">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: var(--text-secondary);">No containers found or cannot access Docker.</p>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>
