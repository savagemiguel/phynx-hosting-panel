<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';
$notices = [];

// Ensure docker_templates table exists if migrations not yet applied
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `docker_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `type` ENUM('single','compose') NOT NULL DEFAULT 'compose',
  `yaml` MEDIUMTEXT NULL,
  `defaults` MEDIUMTEXT NULL,
  `allowed` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ux_template_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

// Helpers
function tmpl_sanitize_slug($s) {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9-_]/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create template
    if (isset($_POST['create_template'])) {
        $name = sanitize($_POST['name'] ?? '');
        $slug = tmpl_sanitize_slug($_POST['slug'] ?? '');
        $type = $_POST['type'] === 'single' ? 'single' : 'compose';
        $yaml = $_POST['yaml'] ?? '';
        $defaults = $_POST['defaults'] ?? '';
        $allowed = isset($_POST['allowed']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            $notices[] = '[ERR] Name and slug are required.';
        } else {
            $q = "INSERT INTO docker_templates (name, slug, type, yaml, defaults, allowed) VALUES (?, ?, ?, ?, ?, ?)";
            $st = mysqli_prepare($conn, $q);
            mysqli_stmt_bind_param($st, 'sssssi', $name, $slug, $type, $yaml, $defaults, $allowed);
            if (mysqli_stmt_execute($st)) {
                $message = '<div class="alert alert-success">Template created.</div>';
            } else {
                $message = '<div class="alert alert-error">Error creating template: ' . htmlspecialchars(mysqli_error($conn)) . '</div>';
            }
        }
    }

    // Update template
    if (isset($_POST['update_template'])) {
        $id = (int)($_POST['template_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $slug = tmpl_sanitize_slug($_POST['slug'] ?? '');
        $type = $_POST['type'] === 'single' ? 'single' : 'compose';
        $yaml = $_POST['yaml'] ?? '';
        $defaults = $_POST['defaults'] ?? '';
        $allowed = isset($_POST['allowed']) ? 1 : 0;

        if ($id <= 0 || $name === '' || $slug === '') {
            $notices[] = '[ERR] Invalid update request.';
        } else {
            $q = "UPDATE docker_templates SET name = ?, slug = ?, type = ?, yaml = ?, defaults = ?, allowed = ? WHERE id = ?";
            $st = mysqli_prepare($conn, $q);
            mysqli_stmt_bind_param($st, 'ssssssi', $name, $slug, $type, $yaml, $defaults, $allowed, $id);
            if (mysqli_stmt_execute($st)) {
                $message = '<div class="alert alert-success">Template updated.</div>';
            } else {
                $message = '<div class="alert alert-error">Error updating template: ' . htmlspecialchars(mysqli_error($conn)) . '</div>';
            }
        }
    }

    // Delete template
    if (isset($_POST['delete_template'])) {
        $id = (int)($_POST['template_id'] ?? 0);
        if ($id > 0) {
            $st = mysqli_prepare($conn, 'DELETE FROM docker_templates WHERE id = ?');
            mysqli_stmt_bind_param($st, 'i', $id);
            if (mysqli_stmt_execute($st)) {
                $message = '<div class="alert alert-success">Template deleted.</div>';
            } else {
                $message = '<div class="alert alert-error">Error deleting template.</div>';
            }
        }
    }
}

// Get edit target if any
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $st = mysqli_prepare($conn, 'SELECT * FROM docker_templates WHERE id = ?');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $edit = mysqli_fetch_assoc($rs) ?: null;
}

// List templates
$templates = [];
$rs = mysqli_query($conn, 'SELECT * FROM docker_templates ORDER BY created_at DESC');
if ($rs) {
    while ($row = mysqli_fetch_assoc($rs)) { $templates[] = $row; }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Docker Templates - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1>Docker Templates</h1>

    <?= $message ?>
    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="card">
        <h3><?= $edit ? 'Edit Template' : 'Create Template' ?></h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <?php if ($edit): ?>
                <input type="hidden" name="template_id" value="<?= (int)$edit['id'] ?>">
            <?php endif; ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($edit['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Slug</label>
                    <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($edit['slug'] ?? '') ?>" placeholder="lowercase-with-dashes" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="compose" <?= ($edit['type'] ?? '') === 'compose' ? 'selected' : '' ?>>Compose</option>
                        <option value="single" <?= ($edit['type'] ?? '') === 'single' ? 'selected' : '' ?>>Single Container</option>
                    </select>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="allowed" id="allowed" <?= (int)($edit['allowed'] ?? 1) ? 'checked' : '' ?>>
                        <span class="checkbox-custom"></span>
                        <label for="allowed" class="checkbox-label">
                            <span class="checkbox-text">Allowed</span>
                            <span class="checkbox-subtext">Allow users to use this template</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label><?= ($edit['type'] ?? 'compose') === 'single' ? 'Config (JSON)' : 'Compose YAML' ?></label>
                <textarea name="yaml" class="form-control" rows="10" placeholder="<?= ($edit['type'] ?? 'compose') === 'single' ? '{\n  "image": "nginx:latest",\n  "env": {"KEY": "VALUE"},\n  "ports": [["8080","80","tcp"]],\n  "mounts": [["/host/path","/container/path",false]]\n}' : 'version: "3.8"\nservices:\n  app:\n    image: nginx:latest\n    ports:\n      - "8080:80"\n    volumes:\n      - ${STACK_PATH}/public:/usr/share/nginx/html' ?>"><?= htmlspecialchars($edit['yaml'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Defaults (JSON variables)</label>
                <textarea name="defaults" class="form-control" rows="4" placeholder='{"HOST_PORT": "8080", "DB_PASS": "random"}'><?= htmlspecialchars($edit['defaults'] ?? '') ?></textarea>
            </div>
            <button class="btn btn-primary" name="<?= $edit ? 'update_template' : 'create_template' ?>" value="1" type="submit"><?= $edit ? 'Update' : 'Create' ?></button>
            <?php if ($edit): ?>
                <a href="docker-templates.php" class="btn btn-secondary" style="margin-left: 8px; background: var(--bg-tertiary); color: var(--text-primary);">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Templates</h3>
        <?php if ($templates): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Type</th>
                        <th>Allowed</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['name']) ?></td>
                        <td><?= htmlspecialchars($t['slug']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($t['type'])) ?></td>
                        <td><?= (int)$t['allowed'] ? 'Yes' : 'No' ?></td>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($t['created_at']))) ?></td>
                        <td class="actions-cell">
                            <a class="btn btn-primary" href="docker-templates.php?edit=<?= (int)$t['id'] ?>" style="padding: 6px 12px; font-size: 12px;">Edit</a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this template?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-danger" name="delete_template" value="1" type="submit" style="padding: 6px 12px; font-size: 12px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-secondary);">No templates defined.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
