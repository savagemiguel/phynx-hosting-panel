<?php
// Configure session for cross-site usage
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1'); // Only if using HTTPS
ini_set('session.cookie_httponly', '1');

// Start a session
session_start();


// Cross-site cookie policy headers
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Origin: " . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

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
$user = $_SESSION['db_user'] ?? $server_config['user'];
$pass = $_SESSION['db_pass'] ?? $server_config['pass'];
$port = $server_config['port'] ?? 3306;
$db_name = $server_config['db_name'];

// Check if user is logged in
if (!isset($_SESSION['db_user']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Connect using config server settings
$conn = new mysqli($host, $user, $pass, $db_name, $port);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
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
    <script>
        console.log('PhynxAdmin: Starting navigation initialization');
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('PhynxAdmin: DOM loaded, initializing navigation');
            
            const mobileToggle = document.getElementById('mobileNavToggle');
            const navigation = document.getElementById('navigation');
            const navBackdrop = document.getElementById('mobileNavBackdrop');
            const navToggleTab = document.getElementById('navToggleTab');
            const main = document.getElementById('main');
            
            console.log('PhynxAdmin: Elements found:', {
                mobileToggle: !!mobileToggle,
                navigation: !!navigation,
                navBackdrop: !!navBackdrop,
                navToggleTab: !!navToggleTab,
                main: !!main
            });
            
            // Mobile navigation toggle
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    console.log('PhynxAdmin: Mobile nav clicked');
                    navigation.classList.toggle('mobile-open');
                    navBackdrop.classList.toggle('show');
                    document.body.classList.toggle('mobile-nav-active');
                    document.documentElement.classList.toggle('no-scroll');
                });
            }
            
            // Mobile nav backdrop click to close
            if (navBackdrop) {
                navBackdrop.addEventListener('click', function() {
                    console.log('PhynxAdmin: Nav backdrop clicked');
                    navigation.classList.remove('mobile-open');
                    navBackdrop.classList.remove('show');
                    document.body.classList.remove('mobile-nav-active');
                    document.documentElement.classList.remove('no-scroll');
                });
            }
            
            // Desktop navigation toggle
            if (navToggleTab) {
                navToggleTab.addEventListener('click', function() {
                    console.log('PhynxAdmin: Desktop nav toggle clicked');
                    navigation.classList.toggle('closed');
                    main.classList.toggle('sidebar-closed', navigation.classList.contains('closed'));
                    navToggleTab.querySelector('span').textContent = navigation.classList.contains('closed') ? '>' : '<';
                });
            }
            
            // Database tree functionality
            const dbHeaders = document.querySelectorAll('.db-header');
            console.log('PhynxAdmin: Found', dbHeaders.length, 'database headers');
            
            // Add click debugging for all db headers
            dbHeaders.forEach(function(header, index) {
                console.log('PhynxAdmin: Setting up header', index, '- data-db:', header.getAttribute('data-db'));
                
                header.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dbName = this.getAttribute('data-db');
                    console.log('PhynxAdmin: Database clicked:', dbName);
                    console.log('PhynxAdmin: Event target:', e.target);
                    console.log('PhynxAdmin: This element:', this);
                    
                    const dbItem = this.closest('.db-item');
                    const toggleIcon = this.querySelector('.toggle-icon');
                    const tablesContainer = dbItem.querySelector('.tables');
                    
                    if (dbItem.classList.contains('expanded')) {
                        // Collapse
                        console.log('PhynxAdmin: Collapsing database');
                        tablesContainer.style.maxHeight = '0px';
                        dbItem.classList.remove('expanded');
                        toggleIcon.classList.remove('fa-minus');
                        toggleIcon.classList.add('fa-plus');
                        
                        setTimeout(function() {
                            tablesContainer.innerHTML = '';
                        }, 300);
                    } else {
                        // Expand
                        console.log('PhynxAdmin: Expanding database');
                        dbItem.classList.add('expanded');
                        toggleIcon.classList.remove('fa-plus');
                        toggleIcon.classList.add('fa-minus');
                        
                        // Load tables via AJAX
                        tablesContainer.innerHTML = '<div style="padding: 12px; color: var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Loading tables...</div>';
                        
                        fetch('get_tables.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'db=' + encodeURIComponent(dbName)
                        })
                        .then(response => {
                            console.log('PhynxAdmin: Got response from get_tables.php');
                            return response.json();
                        })
                        .then(tables => {
                            console.log('PhynxAdmin: Tables received:', tables);
                            let tableLinks = '';

                            // Add "View Database" link first
                            tableLinks += `<a href="?db=${encodeURIComponent(dbName)}" class="table-link">
                                <i class="fas fa-database table-icon"></i> View Database
                            </a>`;

                            // Add individual table links
                            if (tables.length) {
                                tableLinks += tables.map(table =>
                                    `<a href="?db=${encodeURIComponent(dbName)}&table=${encodeURIComponent(table)}" class="table-link">
                                        <i class="fas fa-table table-icon"></i> ${table}
                                    </a>`
                                ).join('');
                            } else {
                                tableLinks += '<div style="padding: 12px; color: var(--text-muted);">No tables found</div>';
                            }

                            tablesContainer.innerHTML = tableLinks;
                            tablesContainer.style.maxHeight = tablesContainer.scrollHeight + "px";
                        })
                        .catch((error) => {
                            console.error('PhynxAdmin: Error loading tables:', error);
                            tablesContainer.innerHTML = '<div style="padding: 12px; color: var(--error-color);">Error loading tables</div>';
                            tablesContainer.style.maxHeight = '50px';
                        });
                    }
                });
            });
            
            console.log('PhynxAdmin: Navigation initialization complete');
        });
        
        // Additional debugging - test if elements exist after page load
        window.addEventListener('load', function() {
            console.log('PhynxAdmin: Window loaded - running additional checks');
            
            const dbHeaders = document.querySelectorAll('.db-header');
            console.log('PhynxAdmin: Post-load db headers found:', dbHeaders.length);
            
            dbHeaders.forEach(function(header, i) {
                console.log(`PhynxAdmin: Header ${i}:`, {
                    element: header,
                    dataDb: header.getAttribute('data-db'),
                    href: header.href,
                    clickable: header.style.pointerEvents !== 'none'
                });
            });
            
            // Test clicking first database if exists
            if (dbHeaders.length > 0) {
                console.log('PhynxAdmin: Adding manual test click handler to first database');
                const firstDb = dbHeaders[0];
                firstDb.style.border = '2px solid red'; // Visual indicator
                firstDb.title = 'Test: Click me to expand database';
            }
        });
    </script>
</body>
</html>