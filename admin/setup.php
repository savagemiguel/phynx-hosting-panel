<?php
require_once '../config.php';
require_once '../includes/functions.php';

requireLogin();
if (!isAdmin()) {
    header('Location: index.php');
    exit;
}

function runSqlFile(mysqli $conn, string $filepath): array {
    $messages = [];
    if (!file_exists($filepath)) {
        return [false, ["SQL file not found: $filepath"]];
    }

    $sql = file_get_contents($filepath);
    if ($sql === false) {
        return [false, ["Failed to read SQL file: $filepath"]];
    }

    // Normalize line endings
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);

    // Split into individual statements by semicolon followed by newline or end of string
    $statements = preg_split('/;\s*(?:\n|$)/', $sql);
    $success = true;

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
                    // Extract table name if possible
                    $table = 'unknown';
                    if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?([\w]+)`?\.)?`?([\w]+)`?/i', $statement, $m)) {
                        $table = $m[2] ?? $m[1] ?? 'unknown';
                    }
                    $messages[] = "[SKIP] Table '" . $table . "' already exists. Skipping CREATE TABLE.";
                    continue;
                }

                $success = false;
                $messages[] = '[ERROR] Error executing statement ' . ($i + 1) . ': ' . $error;
            }
        } catch (mysqli_sql_exception $ex) {
            $errno = (int) $ex->getCode();
            $error = $ex->getMessage();

            $isCreateTable = preg_match('/^\s*CREATE\s+TABLE/i', $statement) === 1;
            if ($isCreateTable && ($errno === 1050 || stripos($error, 'already exists') !== false)) {
                // Extract table name if possible
                $table = 'unknown';
                if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?([\w]+)`?\.)?`?([\w]+)`?/i', $statement, $m)) {
                    $table = $m[2] ?? $m[1] ?? 'unknown';
                }
                $messages[] = "[SKIP] Table '" . $table . "' already exists. Skipping CREATE TABLE.";
                continue;
            }

            $success = false;
            $messages[] = '[ERROR] Exception executing statement ' . ($i + 1) . ': ' . $error;
        }
    }

    return [$success, $messages];
}

$ran = false;
$result_ok = null;
$messages = [];
$tables = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
    $ran = true;
    [$result_ok, $messages] = runSqlFile($conn, realpath(__DIR__ . '/../database.sql'));

    // Ensure critical tables exist explicitly (handles cases where some statements were skipped)
    $check = mysqli_query($conn, "SHOW TABLES LIKE 'databases'");
    if ($check && mysqli_num_rows($check) === 0) {
        try {
            $createSql = "CREATE TABLE IF NOT EXISTS `databases` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                database_name VARCHAR(100) NOT NULL,
                database_user VARCHAR(100) NOT NULL,
                database_password VARCHAR(255) NOT NULL,
                status ENUM('active', 'suspended') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            mysqli_query($conn, $createSql);
            $messages[] = "[CREATE] Created missing table 'databases'.";
        } catch (mysqli_sql_exception $ex) {
            $result_ok = false;
            $messages[] = "[ERROR] Failed to create missing table 'databases': " . $ex->getMessage();
        }
    }

    // Fetch tables present after running
    $res = mysqli_query($conn, 'SHOW TABLES');
    if ($res) {
        while ($row = mysqli_fetch_row($res)) {
            $tables[] = $row[0];
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Database Setup - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <h1>Initialize Database Schema</h1>

    <?php if ($ran): ?>
        <?php if ($result_ok): ?>
            <div class="alert alert-success">Database initialized successfully.</div>
        <?php else: ?>
            <div class="alert alert-error">Completed with some errors. Review messages below.</div>
        <?php endif; ?>

        <?php if (!empty($messages)): ?>
            <div class="card">
                <h3>Messages</h3>
                <ul>
                    <?php foreach ($messages as $msg): ?>
                        <li><?= htmlspecialchars($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Existing Tables</h3>
            <?php if ($tables): ?>
                <ul>
                    <?php foreach ($tables as $t): ?>
                        <li><?= htmlspecialchars($t) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No tables found.</p>
            <?php endif; ?>
        </div>

        <a href="../user/subdomains.php" class="btn btn-primary">Go to Subdomains</a>
    <?php else: ?>
        <div class="card">
            <p>This will run the SQL statements in <code>database.sql</code> to create the required tables (including <code>subdomains</code>).</p>
            <form method="post">
                <?php csrf_field(); ?>
                <button type="submit" class="btn btn-primary">Run Database Initialization</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
