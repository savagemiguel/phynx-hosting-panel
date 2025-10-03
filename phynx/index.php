<?php
// Start a session
session_start();

// --- Installation Check ---
// If the config file doesn't exist, the application hasn't been installed yet.
// Redirect the user to the installation wizard.
if (!file_exists('config.php')) {
    header('Location: install.php');
    exit;
}

require_once 'config.php';
include 'includes/config/funcs.api.php';

// Get current server from session or use default
$current_server = $_SESSION['current_server'] ?? Config::get('DefaultServer');
$server_config = Config::get('Server')[$current_server];

// Use config servers credentials, fallback to session if not specified
$host = $server_config['host'] ?? 'localhost';
$user = $server_config['user'] ?? $_SESSION['db_user'];
$pass = $server_config['pass'] ?? $_SESSION['db_pass'];
$port = $server_config['port'] ?? 3306;

// Check if user is logged in
if (!isset($_SESSION['db_user']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Connect using config server settings
$conn = new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    die("Connection failed to {$server_config['host']}: " . $conn->connect_error);
}

// Fetch databases
$databases = [];
$result = $conn->query("SHOW DATABASES");
if ($result) {
    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $databases[] = $row[0];
    }
}

$selected_db = $_GET['db'] ?? '';
$selected_table = $_GET['table'] ?? '';
$tables = [];

if ($selected_db) {
    $conn->select_db($selected_db);
    $result = $conn->query("SHOW TABLES");
    if ($result) {
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tables[] = $row[0];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<!--
<meta name="google-site-verification" content="4t8Z88z1v5883K8w7y363v351Q263z51i11d0VwV79o">
<meta name="yandex-verification" content="65875318288b868a">
<meta name="msvalidate.01" content="532c0608663653975832d2592153391f">
<meta name="msvalidate.2.0" content="532c0608663653975832d2592153391f">
-->
<head>
    <title>P H Y N X Admin</title>
    <link rel="stylesheet" href="includes/css/fa/all.min.css">
    <link rel="stylesheet" href="includes/css/styles.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><path fill='%23f57c00' d='M8 0C3.6 0 0 3.6 0 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 14c-3.3 0-6-2.7-6-6s2.7-6 6-6 6 2.7 6 6-2.7 6-6 6z'/></svg>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="keywords" content="">
    <meta name="author" content="">
    <meta name="robots" content="index, follow">
    <meta name="googlebot" content="index, follow">
    <meta name="google" content="nositelinkssearchbox">
</head>
<body>
    <header id="header">
        <button class="mobile-nav-toggle" id="mobileNavToggle">
            <i class="fas fa-bars"></i> Navigation
        </button>
        
        <div class="logo">
            <i class="fas fa-database" style="color: var(--primary-color); margin-right: 12px;"></i>
            <span>P H Y N X Admin</span>
        </div>
        <div class="header-links">
            <a href="?">Home</a>
            <a href="?page=sql">SQL</a>
            <a href="?page=export">Export</a>
            <a href="?page=import">Import</a>
            <a href="?page=search">Search</a>
            <a href="?page=backup">Backup</a>
            <a href="?page=restore">Restore</a>
            <a href="?page=users">Users</a>
            <a href="?page=settings">Settings</a>
            <a href="?page=logout">Logout</a>
        </div>
    </header>
    <div class="mobile-nav-backdrop" id="mobileNavBackdrop"></div>
    <div id="main">
        <nav id="navigation">
            <div class="nav-header">
                <div class="nav-controls">
                    <div class="nav-buttons">
                        <a href="?" class="nav-btn">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="?page=config" class="nav-btn">
                            <i class="fas fa-cog"></i> Config
                        </a>

                        <a href="?page=php_ini" class="nav-btn">
                            <i class="fas fa-php"></i> PHP.ini
                        </a>
                    </div>

                    <div class="server-selector">
                        <label for="server-select">Server Selector:</label>
                        <select id="server-select" onchange="changeServer(this.value)">
                            <?php
                            include_once 'config.php';
                            $current_server = $_SESSION['current_server'] ?? Config::get('DefaultServer');

                            foreach (Config::get('Server') as $id => $server):
                            ?>
                                <option value="<?= $id; ?>" <?= $id == $current_server ? 'selected' : '' ?>>
                                    <?= $server['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!--<div class="theme-selector">
                        <?= functions::themeSelector(); ?>
                    </div>-->
                </div>
                <h3>Databases</h3>
                <div class="create-db-section">
                    <a href="?page=create_database" class="create-db-btn">
                        <i class="fas fa-plus"></i> Create Database
                    </a>
                </div>
            </div>
            <div class="db-tree">
                <?php foreach ($databases as $db): ?>
                    <div class="db-item">
                        <a href="#" class="db-header" data-db="<?= htmlspecialchars($db); ?>">
                            <i class="fas fa-plus toggle-icon"></i>
                            <i class="fas fa-database db-icon"></i>
                            <span class="db-name"><?= htmlspecialchars($db); ?></span>
                        </a>
                        <div class="tables">
                            
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </nav>
        <div class="nav-toggle-tab" id="navToggleTab">
            <span>&gt;</span>
        </div>
        <div id="content">
            <?php $page = $_GET['page'] ?? '';
            if ($selected_table): ?>
                <?php include 'table_view.php'; ?>
            <?php elseif ($selected_db): ?>
                <?php include 'database_view.php'; ?>
            <?php elseif ($page === 'sql'): ?>
                <?php include 'sql_view.php'; ?>
            <?php elseif ($page === 'search'): ?>
                <?php include 'search_view.php'; ?>
            <?php elseif ($page === 'export'): ?>
                <?php include 'export_view.php'; ?>
            <?php elseif ($page === 'import'): ?>
                <?php include 'import_view.php'; ?>
            <?php elseif ($page === 'backup'): ?>
                <?php include 'backup_view.php'; ?>
            <?php elseif ($page === 'restore'): ?>
                <?php include 'restore_view.php'; ?>
            <?php elseif ($page === 'edit_user'): ?>
                <?php include 'edit_privileges.php'; ?>
            <?php elseif ($page === 'users'): ?>
                <!-- Include the users_view.php file -->
                <?php include 'user_accounts_view.php'; ?>
            <?php elseif ($page === 'export_users'): ?>
                <?php include 'export_users_view.php'; ?>
            <?php elseif ($page === 'delete_user'): ?>
                <?php include 'delete_user.php'; ?>
            <?php elseif ($page === 'create_database'): ?>
                <?php include 'create_database_view.php'; ?>
            <?php elseif ($page === 'delete_database'): ?>
                <?php include 'delete_database_view.php'; ?>
            <?php elseif ($page === 'settings'): ?>
                <?php include 'settings_view.php'; ?>
            <?php elseif ($page === 'config'): ?>
                <?php include 'config_view.php'; ?>
            <?php elseif ($page === 'logs'): ?>
                <?php include 'logs_view.php'; ?>
            <?php elseif ($page === 'php_ini'): ?>
                <?php include 'php_ini_view.php'; ?>
            <?php elseif ($page === 'logout'): ?>
                <?php include 'logout_view.php'; ?>
            <?php else: ?>
                <?php include 'home_view.php'; ?>
            <?php endif; ?>
        </div>
    </div>    
    <script src="includes/js/script.js"></script>
</body>
</html>