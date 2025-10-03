<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/docker.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$message = '';
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

function slugify($s) {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9-_]/', '-', $s);
    return trim(preg_replace('/-+/', '-', $s), '-');
}

function npath($p) { return str_replace('\\', '/', $p); }

function stacks_base_dir(): string {
    $base = env('DOCKER_STACKS_DIR', __DIR__ . '/../docker-stacks');
    return rtrim(npath($base), '/');
}

function json_decode_assoc($text) {
    $text = trim((string)$text);
    if ($text === '') return [];
    $dec = json_decode($text, true);
    return is_array($dec) ? $dec : [];
}

function substitute_vars($yaml, array $vars): string {
    // Provide STACK_PATH convenience
    if (!isset($vars['STACK_PATH'])) $vars['STACK_PATH'] = '';
    foreach ($vars as $k => $v) {
        $yaml = str_replace('${' . $k . '}', $v, $yaml);
    }
    return $yaml;
}

// Load templates (allowed only)
$templates = [];
$rs = mysqli_query($conn, "SELECT * FROM docker_templates WHERE allowed = 1 ORDER BY name");
if ($rs) { while ($row = mysqli_fetch_assoc($rs)) { $templates[] = $row; } }

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create stack
    if (isset($_POST['create_stack'])) {
        $template_id = (int)($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = slugify($_POST['slug'] ?? '');
        $vars_text = $_POST['vars'] ?? '';

        if ($template_id <= 0 || $name === '' || $slug === '') {
            $message = '<div class="alert alert-error">Template, name and slug are required.</div>';
        } else {
            // Fetch template
            $st = mysqli_prepare($conn, 'SELECT * FROM docker_templates WHERE id = ? AND allowed = 1');
            mysqli_stmt_bind_param($st, 'i', $template_id);
            mysqli_stmt_execute($st);
            $rs = mysqli_stmt_get_result($st);
            $tpl = mysqli_fetch_assoc($rs);
            if (!$tpl) {
                $message = '<div class="alert alert-error">Template not found or not allowed.</div>';
            } else if (($tpl['type'] ?? 'compose') !== 'compose') {
                $message = '<div class="alert alert-error">Selected template is not a compose type.</div>';
            } else {
                $yaml = (string)($tpl['yaml'] ?? '');
                $defaults = json_decode_assoc($tpl['defaults'] ?? '');
                $vars = json_decode_assoc($vars_text);
                if (empty($vars)) $vars = $defaults; // if user left empty, use defaults

                // Build stack path and file
                $base = stacks_base_dir();
                $stack_dir = $base . '/' . $username . '/' . $slug;
                if (!is_dir($stack_dir)) @mkdir($stack_dir, 0755, true);
                $vars['STACK_PATH'] = $stack_dir;

                $final_yaml = substitute_vars($yaml, $vars);
                $compose_file = $stack_dir . '/docker-compose.yml';
                if (@file_put_contents($compose_file, $final_yaml) === false) {
                    $message = '<div class="alert alert-error">Failed to write compose file.</div>';
                } else {
                    // Save stack record
                    $vars_json = json_encode($vars, JSON_UNESCAPED_SLASHES);
                    $status = 'created';
                    $st = mysqli_prepare($conn, 'INSERT INTO docker_stacks (user_id, name, slug, compose_file_path, env, status) VALUES (?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($st, 'isssss', $user_id, $name, $slug, $compose_file, $vars_json, $status);
                    if (mysqli_stmt_execute($st)) {
                        $message = '<div class="alert alert-success">Stack created. You can bring it up now.</div>';
                    } else {
                        $message = '<div class="alert alert-error">Failed to store stack record.</div>';
                    }
                }
            }
        }
    }

    // Bring up / down
    if (isset($_POST['action']) && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $st = mysqli_prepare($conn, 'SELECT * FROM docker_stacks WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($st, 'ii', $id, $user_id);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $stack = mysqli_fetch_assoc($rs);
        if ($stack) {
            $file = $stack['compose_file_path'];
            $workdir = dirname($file);
            $act = $_POST['action'];
            if ($act === 'up') {
                [$rc,$out,$cmd] = docker_compose_up($file, $workdir);
                $notices[] = implode("\n", $out);
                if ($rc === 0) {
                    $st2 = mysqli_prepare($conn, 'UPDATE docker_stacks SET status = ? WHERE id = ? AND user_id = ?');
                    $status = 'up';
                    mysqli_stmt_bind_param($st2, 'sii', $status, $id, $user_id);
                    mysqli_stmt_execute($st2);
                    $message = '<div class="alert alert-success">Stack is up.</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to bring up the stack.</div>';
                }
            }
            if ($act === 'down') {
                [$rc,$out,$cmd] = docker_compose_down($file, $workdir);
                $notices[] = implode("\n", $out);
                if ($rc === 0) {
                    $st2 = mysqli_prepare($conn, 'UPDATE docker_stacks SET status = ? WHERE id = ? AND user_id = ?');
                    $status = 'down';
                    mysqli_stmt_bind_param($st2, 'sii', $status, $id, $user_id);
                    mysqli_stmt_execute($st2);
                    $message = '<div class="alert alert-success">Stack is down.</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to bring down the stack.</div>';
                }
            }
            if ($act === 'logs') {
                // docker compose -f file logs --no-color --tail 200
                [$rc,$out,$cmd] = docker_compose_cmd($file, ['logs','--no-color','--tail','200'], $workdir);
                $notices[] = '--- Logs for ' . htmlspecialchars($stack['name']) . ' ---';
                $notices = array_merge($notices, $out);
            }
            if ($act === 'delete') {
                // ensure down, then remove directory and DB record
                docker_compose_cmd($file, ['down'], $workdir);
                // Remove directory
                $dir = $workdir;
                if (is_dir($dir)) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
                    @rmdir($dir);
                }
                $st2 = mysqli_prepare($conn, 'DELETE FROM docker_stacks WHERE id = ? AND user_id = ?');
                mysqli_stmt_bind_param($st2, 'ii', $id, $user_id);
                mysqli_stmt_execute($st2);
                $message = '<div class="alert alert-success">Stack deleted.</div>';
            }
        }
    }
}

// Load stacks
$st = mysqli_prepare($conn, 'SELECT * FROM docker_stacks WHERE user_id = ? ORDER BY created_at DESC');
mysqli_stmt_bind_param($st, 'i', $user_id);
mysqli_stmt_execute($st);
$rs = mysqli_stmt_get_result($st);
$stacks = [];
while ($row = mysqli_fetch_assoc($rs)) { $stacks[] = $row; }

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Stacks - User Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<div class="sidebar">
    <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
        <h3 style="color: var(--primary-color);">Control Panel</h3>
        <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($username) ?></p>
    </div>
    <div class="sidebar-nav">
        <div class="sidebar-group" data-group-key="user-overview">
            <div class="group-header" role="button" aria-expanded="false">
                <span class="group-label">Overview</span>
                <span class="group-arrow">▶</span>
            </div>
            <div class="group-items">
                <ul class="sidebar-nav">
                    <li><a href="index.php">Dashboard</a></li>
                    <li><a href="profile.php">Profile</a></li>
                </ul>
            </div>
        </div>
        <div class="sidebar-group open" data-group-key="user-hosting">
            <div class="group-header" role="button" aria-expanded="true">
                <span class="group-label">Hosting</span>
                <span class="group-arrow">▶</span>
            </div>
            <div class="group-items">
                <ul class="sidebar-nav">
                    <li><a href="domains.php">Domains</a></li>
                    <li><a href="subdomains.php">Subdomains</a></li>
                    <li><a href="ssl.php">SSL Certificates</a></li>
                    <li><a href="dns.php">DNS</a></li>
                    <li><a href="file-manager.php">File Manager</a></li>
                    <li><a href="containers.php">Containers</a></li>
                    <li><a href="stacks.php" class="active">Stacks</a></li>
                </ul>
            </div>
        </div>
        <div class="sidebar-group" data-group-key="user-services">
            <div class="group-header" role="button" aria-expanded="false">
                <span class="group-label">Services</span>
                <span class="group-arrow">▶</span>
            </div>
            <div class="group-items">
                <ul class="sidebar-nav">
                    <li><a href="email.php">Email Accounts</a></li>
                    <li><a href="databases.php">Databases</a></li>
                    <li><a href="ftp.php">FTP Accounts</a></li>
                    <li><a href="backups.php">Backups</a></li>
                </ul>
            </div>
        </div>
        <div class="sidebar-group" data-group-key="user-session">
            <div class="group-header" role="button" aria-expanded="false">
                <span class="group-label">Session</span>
                <span class="group-arrow">▶</span>
            </div>
            <div class="group-items">
                <ul class="sidebar-nav">
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="main-content">
    <h1>Docker Stacks</h1>

    <?= $message ?>
    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="card">
        <h3>Create Stack</h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Template</label>
                    <select name="template_id" class="form-control" required>
                        <option value="">Select Template</option>
                        <?php foreach ($templates as $t): ?>
                            <?php if (($t['type'] ?? 'compose') === 'compose'): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= htmlspecialchars($t['slug']) ?>)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Slug</label>
                    <input type="text" name="slug" class="form-control" placeholder="lowercase-with-dashes" required>
                </div>
            </div>
            <div class="form-group">
                <label>Variables (JSON). Include values for variables used in template YAML. STACK_PATH is auto-set.</label>
                <textarea name="vars" class="form-control" rows="6" placeholder='{"HOST_PORT": "8080", "DB_PASS": "changeme"}'></textarea>
            </div>
            <button class="btn btn-primary" name="create_stack" value="1" type="submit">Create</button>
        </form>
    </div>

    <div class="card">
        <h3>My Stacks</h3>
        <?php if ($stacks): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Status</th>
                        <th>Compose Path</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($stacks as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= htmlspecialchars($s['slug']) ?></td>
                        <td><?= htmlspecialchars($s['status']) ?></td>
                        <td style="max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"> <?= htmlspecialchars($s['compose_file_path']) ?></td>
                        <td class="actions-cell">
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-success" name="action" value="up">Up</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-warning" name="action" value="down">Down</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-primary" name="action" value="logs">Logs</button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this stack? It will run docker compose down and remove files.')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-danger" name="action" value="delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-secondary);">No stacks found.</p>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
