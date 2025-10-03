<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/docker.php';
requireAdmin();

$message = '';
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pull image
    if (isset($_POST['pull_image'])) {
        $name = trim($_POST['image_name'] ?? '');
        if ($name === '') {
            $notices[] = '[ERR] Image name cannot be empty';
        } else {
            [$rc, $out, $cmd] = docker_cmd(['pull', $name]);
            $notices[] = ($rc === 0 ? '[OK] Pulled: ' : '[ERR] Pull failed: ') . htmlspecialchars($name);
            foreach ($out as $line) { $notices[] = '  ' . $line; }
        }
    }
    // Delete image by ID
    if (isset($_POST['delete_image'])) {
        $id = trim($_POST['image_id'] ?? '');
        if ($id === '') {
            $notices[] = '[ERR] Missing image id';
        } else {
            [$rc, $out, $cmd] = docker_cmd(['image', 'rm', $id]);
            $notices[] = ($rc === 0 ? '[OK] Removed image: ' : '[ERR] Remove failed: ') . htmlspecialchars($id);
            foreach ($out as $line) { $notices[] = '  ' . $line; }
        }
    }
}

$images = docker_images();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Docker Images - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1>Docker Images</h1>

    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="card">
        <h3>Pull Image</h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <div style="display: flex; gap: 12px; align-items: center;">
                <input type="text" class="form-control" name="image_name" placeholder="e.g., nginx:latest" style="max-width: 320px;">
                <button class="btn btn-primary" name="pull_image" value="1" type="submit">Pull</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Local Images</h3>
        <?php if ($images['ok'] && !empty($images['images'])): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Repository</th>
                        <th>Tag</th>
                        <th>Image ID</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Actions</th>
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
                        <td>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this image?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="image_id" value="<?= htmlspecialchars($img['ID'] ?? '') ?>">
                                <button class="btn btn-danger" name="delete_image" value="1" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-secondary);">No images found or cannot access Docker.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
