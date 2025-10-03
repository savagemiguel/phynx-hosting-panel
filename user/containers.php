<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/docker.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$message = '';
$notices = [];

// CSRF protect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

// Helpers
function json_pretty($data) { return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); }
function npath($p) { return str_replace('\\', '/', $p); }
function within_user_base($path, $username) {
    $base = rtrim(npath(WEB_ROOT), '/') . '/' . $username;
    $p = npath($path);
    return strpos($p, $base) === 0;
}
function parse_env_lines($text) {
    $env = [];
    $lines = preg_split('/\r?\n/', trim((string)$text));
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        if (strpos($ln, '=') !== false) {
            [$k, $v] = explode('=', $ln, 2);
            $k = trim($k); $v = trim($v);
            if ($k !== '') $env[$k] = $v;
        }
    }
    return $env;
}
function allocate_port(mysqli $conn, int $user_id, int $start, int $end, string $proto = 'tcp'): ?int {
    // Simple allocator: scan from start to end; pick first not in docker_ports
    for ($p = $start; $p <= $end; $p++) {
        $st = mysqli_prepare($conn, 'SELECT 1 FROM docker_ports WHERE user_id = ? AND host_port = ? AND proto = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 'iis', $user_id, $p, $proto);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        if (mysqli_num_rows($rs) === 0) return $p;
    }
    return null;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create container
    if (isset($_POST['create_container'])) {
        $c_name = trim($_POST['c_name'] ?? '');
        $image = trim($_POST['image'] ?? '');
        $env_text = $_POST['env'] ?? '';
        $cport = (int)($_POST['container_port'] ?? 0);
        $hport = trim($_POST['host_port'] ?? '');
        $proto = ($_POST['proto'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp';
        $host_path = trim($_POST['host_path'] ?? '');
        $cont_path = trim($_POST['cont_path'] ?? '');
        $ro = !empty($_POST['ro']);
        $cpus = trim($_POST['cpus'] ?? '');
        $mem = trim($_POST['mem'] ?? '');
        $net = trim($_POST['network'] ?? '');

        if ($c_name === '' || $image === '') {
            $message = '<div class="alert alert-error">Container name and image are required.</div>';
        } else {
            $env = parse_env_lines($env_text);
            $ports = [];
            $ports_allocated = [];
            if ($cport > 0) {
                if ($hport === '') {
                    $start = (int)env('PORT_RANGE_START', '20000');
                    $end = (int)env('PORT_RANGE_END', '30000');
                    $alloc = allocate_port($conn, $user_id, $start, $end, $proto);
                    if ($alloc === null) {
                        $message = '<div class="alert alert-error">No available host ports to allocate.</div>';
                    } else {
                        $ports[] = [$alloc, $cport, $proto];
                        $ports_allocated[] = $alloc;
                    }
                } else {
                    $hp = (int)$hport;
                    // Check conflicts
                    $st = mysqli_prepare($conn, 'SELECT 1 FROM docker_ports WHERE user_id = ? AND host_port = ? AND proto = ? LIMIT 1');
                    mysqli_stmt_bind_param($st, 'iis', $user_id, $hp, $proto);
                    mysqli_stmt_execute($st);
                    $rs = mysqli_stmt_get_result($st);
                    if (mysqli_num_rows($rs) > 0) {
                        $message = '<div class="alert alert-error">Host port already allocated.</div>';
                    } else {
                        $ports[] = [$hp, $cport, $proto];
                        $ports_allocated[] = $hp;
                    }
                }
            }

            $mounts = [];
            if ($host_path !== '' && $cont_path !== '') {
                // Normalize and ensure within user base
                $host_path_n = npath($host_path);
                if (!within_user_base($host_path_n, $username)) {
                    $message = '<div class="alert alert-error">Host path must be within your home: ' . htmlspecialchars(rtrim(npath(WEB_ROOT), '/') . '/' . $username) . '</div>';
                } else {
                    if (!is_dir($host_path_n)) @mkdir($host_path_n, 0755, true);
                    $mounts[] = [$host_path_n, $cont_path, $ro];
                }
            }

            if ($message === '') {
                $opts = [
                    'image' => $image,
                    'name' => $c_name,
                    'env' => $env,
                    'ports' => $ports,
                    'mounts' => $mounts,
                    'cpu' => $cpus !== '' ? $cpus : null,
                    'mem' => $mem !== '' ? $mem : null,
                    'network' => $net !== '' ? $net : null,
                ];
                $res = docker_run($opts);
                if ($res['ok']) {
                    $container_id = '';
                    if (!empty($res['output'])) { $container_id = trim($res['output'][0]); }
                    // Save to DB
                    $ports_json = json_pretty($ports);
                    $env_json = json_pretty($env);
                    $mounts_json = json_pretty($mounts);
                    $status = 'running';
                    $st = mysqli_prepare($conn, 'INSERT INTO docker_containers (user_id, name, image, container_id, status, ports, env, mounts, cpu_limit, mem_limit, network) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($st, 'issssssssss', $user_id, $c_name, $image, $container_id, $status, $ports_json, $env_json, $mounts_json, $cpus, $mem, $net);
                    mysqli_stmt_execute($st);
                    $cid = mysqli_insert_id($conn);
                    foreach ($ports_allocated as $hp) {
                        $st = mysqli_prepare($conn, 'INSERT INTO docker_ports (user_id, container_id_ref, host_port, container_port, proto) VALUES (?, ?, ?, ?, ?)');
                        mysqli_stmt_bind_param($st, 'iiiss', $user_id, $cid, $hp, $cport, $proto);
                        mysqli_stmt_execute($st);
                    }
                    $message = '<div class="alert alert-success">Container created successfully.</div>';
                } else {
                    $message = '<div class="alert alert-error">Failed to create container.</div>';
                    foreach (($res['output'] ?? []) as $line) { $notices[] = $line; }
                }
            }
        }
    }

    // Start/stop/restart/remove container actions
    if (isset($_POST['action']) && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $st = mysqli_prepare($conn, 'SELECT * FROM docker_containers WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($st, 'ii', $id, $user_id);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($rs);
        if ($row) {
            $name = $row['name'];
            $act = $_POST['action'];
            if ($act === 'start') { [$rc,$out,$cmd]=docker_cmd(['start',$name]); }
            if ($act === 'stop') { [$rc,$out,$cmd]=docker_cmd(['stop',$name]); }
            if ($act === 'restart') { [$rc,$out,$cmd]=docker_cmd(['restart',$name]); }
            if ($act === 'remove') {
                // Remove docker and db and ports
                [$rc,$out,$cmd]=docker_cmd(['rm','-f',$name]);
                $st = mysqli_prepare($conn, 'DELETE FROM docker_ports WHERE container_id_ref = ? AND user_id = ?');
                mysqli_stmt_bind_param($st, 'ii', $id, $user_id);
                mysqli_stmt_execute($st);
                $st = mysqli_prepare($conn, 'DELETE FROM docker_containers WHERE id = ? AND user_id = ?');
                mysqli_stmt_bind_param($st, 'ii', $id, $user_id);
                mysqli_stmt_execute($st);
                $message = '<div class="alert alert-success">Container removed.</div>';
            } else {
                // Update status
                $new_status = ($act === 'start' || $act === 'restart') ? 'running' : 'stopped';
                $st = mysqli_prepare($conn, 'UPDATE docker_containers SET status = ? WHERE id = ? AND user_id = ?');
                mysqli_stmt_bind_param($st, 'sii', $new_status, $id, $user_id);
                mysqli_stmt_execute($st);
                $message = '<div class="alert alert-success">Action ' . htmlspecialchars($act) . ' executed.</div>';
            }
        }
    }

    // View logs
    if (isset($_POST['show_logs']) && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $st = mysqli_prepare($conn, 'SELECT name FROM docker_containers WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($st, 'ii', $id, $user_id);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($rs);
        if ($row) {
            $lr = docker_logs($row['name'], 200);
            if ($lr['ok']) {
                $notices[] = '--- Logs for ' . $row['name'] . ' ---';
                foreach ($lr['lines'] as $ln) { $notices[] = $ln; }
            } else {
                $notices[] = '[ERR] Could not load logs.';
            }
        }
    }
}

// Fetch user's containers
$containers = [];
$st = mysqli_prepare($conn, 'SELECT * FROM docker_containers WHERE user_id = ? ORDER BY created_at DESC');
mysqli_stmt_bind_param($st, 'i', $user_id);
mysqli_stmt_execute($st);
$rs = mysqli_stmt_get_result($st);
while ($row = mysqli_fetch_assoc($rs)) { $containers[] = $row; }

// Suggest images list
$imgList = docker_images();
$images = [];
if ($imgList['ok']) {
    foreach ($imgList['images'] as $im) {
        $repo = $im['Repository'] ?? '';
        $tag = $im['Tag'] ?? '';
        if ($repo !== '<none>' && $repo !== '') $images[] = $repo . ($tag ? (':' . $tag) : '');
    }
}
$images = array_unique($images);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Containers - User Panel</title>
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
                    <li><a href="containers.php" class="active">Containers</a></li>
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
    <h1>Docker Containers</h1>

    <?= $message ?>
    <?php if (!empty($notices)): ?>
        <div class="card"><pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $notices)) ?></pre></div>
    <?php endif; ?>

    <div class="card">
        <h3>Create Container</h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Container Name</label>
                    <input type="text" name="c_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Image</label>
                    <input list="images" name="image" class="form-control" placeholder="e.g., nginx:latest" required>
                    <datalist id="images">
                        <?php foreach ($images as $im): ?>
                            <option value="<?= htmlspecialchars($im) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>CPU Limit (cpus)</label>
                    <input type="text" name="cpus" class="form-control" placeholder="e.g., 1.0">
                </div>
                <div class="form-group">
                    <label>Memory Limit</label>
                    <input type="text" name="mem" class="form-control" placeholder="e.g., 512m">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Container Port</label>
                    <input type="number" name="container_port" class="form-control" placeholder="80">
                </div>
                <div class="form-group">
                    <label>Host Port (blank = auto)</label>
                    <input type="number" name="host_port" class="form-control" placeholder="auto">
                </div>
                <div class="form-group">
                    <label>Protocol</label>
                    <select name="proto" class="form-control"><option value="tcp">TCP</option><option value="udp">UDP</option></select>
                </div>
                <div class="form-group">
                    <label>Network (optional)</label>
                    <input type="text" name="network" class="form-control" placeholder="bridge or custom">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Host Path (under your home)</label>
                    <input type="text" name="host_path" class="form-control" placeholder="<?= htmlspecialchars(rtrim(npath(WEB_ROOT), '/') . '/' . $username) ?>/app">
                </div>
                <div class="form-group">
                    <label>Container Path</label>
                    <input type="text" name="cont_path" class="form-control" placeholder="/app">
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="ro" id="ro">
                        <span class="checkbox-custom"></span>
                        <label for="ro" class="checkbox-label">
                            <span class="checkbox-text">Read-only</span>
                            <span class="checkbox-subtext">Mount container volume as read-only</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Environment (KEY=VALUE per line)</label>
                <textarea name="env" class="form-control" rows="4" placeholder="ENV=prod
DEBUG=false"></textarea>
            </div>
            <button class="btn btn-primary" name="create_container" value="1" type="submit">Create</button>
        </form>
    </div>

    <div class="card">
        <h3>My Containers</h3>
        <?php if ($containers): ?>
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
                <?php foreach ($containers as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['name']) ?></td>
                        <td><?= htmlspecialchars($c['image']) ?></td>
                        <td><?= htmlspecialchars($c['status']) ?></td>
                        <td><?= htmlspecialchars($c['ports']) ?></td>
                        <td class="actions-cell">
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-success" name="action" value="start">Start</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-warning" name="action" value="restart">Restart</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-danger" name="action" value="stop">Stop</button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this container? This will stop and remove it.')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-danger" name="action" value="remove">Remove</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-primary" name="show_logs" value="1">Logs</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-secondary);">No containers found.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
