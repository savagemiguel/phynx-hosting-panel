<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

// Ensure migrations table exists
$createMigrationsTable = "CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createMigrationsTable);

$message = '';
$details = [];

function list_migration_files(string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
    // Sort by filename to preserve order (timestamped filenames recommended)
    sort($files, SORT_STRING);
    return $files;
}

function load_applied_migrations(mysqli $conn): array {
    $applied = [];
    $res = mysqli_query($conn, 'SELECT filename FROM migrations ORDER BY applied_at');
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $applied[$row['filename']] = true;
        }
    }
    return $applied;
}

function apply_migration_file(mysqli $conn, string $filepath, array &$details): bool {
    $sql = file_get_contents($filepath);
    if ($sql === false) {
        $details[] = "[ERROR] Failed to read file: $filepath";
        return false;
    }

    // Normalize
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);

    // Split statements by semicolon followed by newline or end
    $statements = preg_split('/;\s*(?:\n|$)/', $sql);
    $allOk = true;

    foreach ($statements as $i => $statement) {
        $statement = trim($statement);
        if ($statement === '' || strpos($statement, '--') === 0 || strpos($statement, '/*') === 0) {
            continue;
        }
        try {
            $ok = mysqli_query($conn, $statement);
            if ($ok === false) {
                $errno = mysqli_errno($conn);
                $error = mysqli_error($conn);

                $isCreateTable = preg_match('/^\s*CREATE\s+TABLE/i', $statement) === 1;
                if ($isCreateTable && ($errno === 1050 || stripos($error, 'already exists') !== false)) {
                    $details[] = "[SKIP] Table exists during migration: " . substr($statement, 0, 80) . '...';
                    continue;
                }

                $allOk = false;
                $details[] = '[ERROR] Statement ' . ($i + 1) . ' failed: ' . $error;
                break;
            }
        } catch (mysqli_sql_exception $ex) {
            $errno = (int)$ex->getCode();
            $error = $ex->getMessage();
            $isCreateTable = preg_match('/^\s*CREATE\s+TABLE/i', $statement) === 1;
            if ($isCreateTable && ($errno === 1050 || stripos($error, 'already exists') !== false)) {
                $details[] = "[SKIP] Table exists during migration: " . substr($statement, 0, 80) . '...';
                continue;
            }
            $allOk = false;
            $details[] = '[ERROR] Statement ' . ($i + 1) . ' exception: ' . $error;
            break;
        }
    }

    return $allOk;
}

// Migrations directory
$migrationsDir = realpath(__DIR__ . '/../migrations');
if ($migrationsDir === false) {
    // Attempt to create the migrations directory
    $tryDir = __DIR__ . '/../migrations';
    if (!is_dir($tryDir)) {
        @mkdir($tryDir, 0755, true);
    }
    $migrationsDir = realpath($tryDir) ?: $tryDir;
}

// Handle apply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
    $files = list_migration_files($migrationsDir);
    $applied = load_applied_migrations($conn);

    $pending = array_values(array_filter(array_map('basename', $files), function($f) use ($applied) {
        return !isset($applied[$f]);
    }));

    if (empty($pending)) {
        $message = '<div class="alert alert-success">No pending migrations. Database is up-to-date.</div>';
    } else {
        $allOk = true;
        foreach ($pending as $fname) {
            $full = rtrim($migrationsDir, '/\\') . DIRECTORY_SEPARATOR . $fname;
            $details[] = "[RUN] Applying $fname";
            $ok = apply_migration_file($conn, $full, $details);
            if ($ok) {
                $ins = mysqli_prepare($conn, 'INSERT INTO migrations (filename) VALUES (?)');
                mysqli_stmt_bind_param($ins, 's', $fname);
                mysqli_stmt_execute($ins);
                $details[] = "[OK] Applied $fname";
            } else {
                $allOk = false;
                $details[] = "[STOP] Halting on $fname due to errors.";
                break;
            }
        }
        $message = $allOk
            ? '<div class="alert alert-success">Migrations applied successfully.</div>'
            : '<div class="alert alert-error">Some migrations failed. Review messages below.</div>';
    }
}

// Build lists for UI
$files = list_migration_files($migrationsDir);
$applied = load_applied_migrations($conn);
$all = array_map('basename', $files);
$pending = array_values(array_filter($all, function($f) use ($applied) { return !isset($applied[$f]); }));
$appliedList = array_values(array_filter($all, function($f) use ($applied) { return isset($applied[$f]); }));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Migrations - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <h1>Database Migrations</h1>

    <?= $message ?>

    <div class="grid">
        <div class="card">
            <div class="card-header">
                <h3>Pending Migrations (<?= count($pending) ?>)</h3>
            </div>
            <div class="card-body">
                <?php if ($pending): ?>
                    <ul>
                        <?php foreach ($pending as $f): ?>
                            <li><?= htmlspecialchars($f) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <button type="submit" class="btn btn-primary">Apply Pending</button>
                    </form>
                <?php else: ?>
                    <p style="color: var(--text-secondary);">No pending migrations.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Applied Migrations (<?= count($appliedList) ?>)</h3>
            </div>
            <div class="card-body">
                <?php if ($appliedList): ?>
                    <ul>
                        <?php foreach ($appliedList as $f): ?>
                            <li><?= htmlspecialchars($f) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="color: var(--text-secondary);">None applied yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($details)): ?>
        <div class="card">
            <div class="card-header">
                <h3>Messages</h3>
            </div>
            <div class="card-body">
                <pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; background: var(--bg-tertiary); padding: 12px; border-radius: 6px;"><?= htmlspecialchars(implode("\n", $details)) ?></pre>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
