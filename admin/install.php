<?php
// --- AJAX Installer Deletion ---
if (isset($_GET['installer_delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (file_exists('../config.php')) {
        // Attempt to delete the installer file
        if (@unlink(__FILE__)) {
            // On success, destroy the session and confirm
            session_start();
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Hosting panel installer removed successfully.']);
            exit;
        } else {
            // On failure, report error
            echo json_encode(['success' => false, 'message' => 'Could not delete install.php. Please check file permissions and remove it manually.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Hosting panel configuration not found. Cannot delete installer.']);
        exit;
    }
}

$version = '1.0.0'; // Phynx Hosting Panel version

// Start the session
session_start();

// Installation steps
$steps = [
    1 => 'Welcome',
    2 => 'Requirements Check',
    3 => 'Database Configuration',
    4 => 'Create Admin User',
    5 => 'Complete Installation'
];

$current_step = $_GET['step'] ?? 1;
$force_reinstall = isset($_GET['force']) && $_GET['force'] === 'true';

// Handle fresh installation request
if ($force_reinstall && file_exists('../config.php')) {
    // Backup existing hosting panel config before deletion
    $backup_name = '../config.backup.' . date('Y-m-d_H-i-s') . '.php';
    @copy('../config.php', $backup_name);
    @unlink('../config.php');
    
    // Clear session data for fresh start
    session_destroy();
    session_start();
    
    // Set flag to allow fresh installation
    $_SESSION['fresh_install'] = true;
    
    // Redirect to step 1 for fresh installation
    header('Location: install.php?step=1');
    exit;
}

// --- Post-Installation Security Check ---
// If hosting panel config.php already exists, it means the installation is complete.
// Allow fresh installation if session flag is set or force parameter is used
$is_fresh_install = isset($_SESSION['fresh_install']) && $_SESSION['fresh_install'] === true;
if (file_exists('../config.php') && !isset($_GET['installer_delete']) && !$force_reinstall && !$is_fresh_install) {
    if ($current_step != 5) {
        // Redirect to completion screen to allow installer removal or fresh install
        header('Location: install.php?step=5');
        exit;
    }
}

$error = null;
$success_messages = [];

// Database setup function
function runSqlFile(mysqli $conn, string $filepath): array {
    $messages = [];
    if (!file_exists($filepath)) {
        return [false, ["SQL file not found: $filepath"]];
    }

    $sql = file_get_contents($filepath);
    if ($sql === false) {
        return [false, ["Failed to read SQL file: $filepath"]];
    }

    // Normalize line endings and split into statements
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
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
                    $messages[] = "Table '$table' already exists - skipped";
                    continue;
                }

                $success = false;
                $messages[] = "Error in statement " . ($i + 1) . ": $error";
            } else {
                // Log successful table creation
                if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?([\w]+)`?\.)?`?([\w]+)`?/i', $statement, $m)) {
                    $table = $m[2] ?? $m[1] ?? 'unknown';
                    $messages[] = "Created table '$table'";
                }
            }
        } catch (mysqli_sql_exception $ex) {
            $errno = (int) $ex->getCode();
            $error = $ex->getMessage();

            $isCreateTable = preg_match('/^\s*CREATE\s+TABLE/i', $statement) === 1;
            if ($isCreateTable && ($errno === 1050 || stripos($error, 'already exists') !== false)) {
                $table = 'unknown';
                if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?([\w]+)`?\.)?`?([\w]+)`?/i', $statement, $m)) {
                    $table = $m[2] ?? $m[1] ?? 'unknown';
                }
                $messages[] = "Table '$table' already exists - skipped";
                continue;
            }

            $success = false;
            $messages[] = "Exception in statement " . ($i + 1) . ": $error";
        }
    }

    return [$success, $messages];
}

// Handle step 4 submission (create admin user)
if ($current_step == 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['db_config'])) {
        $config = $_SESSION['db_config'];
        
        // Get admin user details
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        
        // Validate inputs
        if (empty($username) || empty($password) || empty($email)) {
            $error = "All fields are required.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email address.";
        } else {
            try {
                // Connect to database
                $host = $config['host'];
                $port = $config['port'];
                $db_user = $config['username'];
                $db_pass = $config['password'];
                
                $conn = new mysqli($host, $db_user, $db_pass, '', (int)$port);
                if ($conn->connect_error) {
                    throw new Exception("Database connection failed: " . $conn->connect_error);
                }
                
                // First, run the database setup
                [$db_success, $db_messages] = runSqlFile($conn, realpath(__DIR__ . 'database.sql'));
                
                if ($db_success) {
                    // Create admin user
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password, email, role, status, created_at) VALUES (?, ?, ?, 'admin', 'active', NOW())");
                    
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "sss", $username, $hashedPassword, $email);
                        if (mysqli_stmt_execute($stmt)) {
                            $_SESSION['admin_user'] = [
                                'username' => $username,
                                'email' => $email
                            ];
                            $_SESSION['db_messages'] = $db_messages;
                            mysqli_stmt_close($stmt);
                            $conn->close();
                            
                            // Proceed to step 5
                            header('Location: install.php?step=5');
                            exit;
                        } else {
                            $error = "Failed to create admin user: " . mysqli_stmt_error($stmt);
                            mysqli_stmt_close($stmt);
                        }
                    } else {
                        $error = "Failed to prepare user creation statement: " . mysqli_error($conn);
                    }
                } else {
                    $error = "Database setup failed. Check the messages below.";
                    $_SESSION['db_messages'] = $db_messages;
                }
                
                $conn->close();
            } catch (Exception $e) {
                $error = "Installation error: " . $e->getMessage();
            }
        }
    } else {
        // Session lost, redirect to db config step
        header('Location: install.php?step=3');
        exit;
    }
}

// Handle step 5 submission (create config file)
if ($current_step == 5 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['db_config']) && isset($_SESSION['admin_user'])) {
        $config = $_SESSION['db_config'];
        
        $host = addslashes($config['host']);
        $port = (int)($config['port']);
        $user = addslashes($config['username']);
        $pass = addslashes($config['password']);
        $database = addslashes($config['database'] ?? '');

        $config_content = <<<EOT
<?php
/**
 * Phynx Hosting Panel Configuration File
 * Generated by installation wizard on <?= date('Y-m-d H:i:s') ?>
 * 
 * This file is separate from phynxadmin configuration
 */

// Database configuration for hosting panel
\$db_config = [
    'host' => '$host',
    'port' => $port,
    'username' => '$user',
    'password' => '$pass',
    'database' => '$database'
];

// Extract for backward compatibility
\$db_host = \$db_config['host'];
\$db_port = \$db_config['port'];
\$db_username = \$db_config['username'];
\$db_password = \$db_config['password'];
\$db_database = \$db_config['database'];

// Establish database connection for hosting panel
try {
    \$conn = new mysqli(\$db_host, \$db_username, \$db_password, \$db_database, \$db_port);
    
    if (\$conn->connect_error) {
        throw new Exception('Hosting Panel DB Connection failed: ' . \$conn->connect_error);
    }
    
    // Set charset for hosting panel database
    \$conn->set_charset('utf8mb4');
    
} catch (Exception \$e) {
    error_log('Hosting Panel Database Error: ' . \$e->getMessage());
    die('Database connection failed. Please check your configuration.');
}

// Hosting Panel Configuration
\$panel_config = [
    'name' => 'Phynx Hosting Panel',
    'version' => '$version',
    'installed' => true,
    'install_date' => '" . date('Y-m-d H:i:s') . "',
    'base_url' => 'http' . (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . \$_SERVER['HTTP_HOST'] . str_replace('/admin', '', dirname(\$_SERVER['SCRIPT_NAME'])),
    'admin_path' => '/admin',
    'timezone' => 'UTC'
];

// Session configuration for hosting panel
if (session_status() === PHP_SESSION_NONE) {
    session_name('PHYNX_HOSTING_SESSION');
    session_start();
}

// Security functions for hosting panel
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (!isset(\$_SESSION['csrf_token'])) {
            \$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return \$_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        return isset(\$_POST['csrf_token']) && 
               isset(\$_SESSION['csrf_token']) && 
               hash_equals(\$_SESSION['csrf_token'], \$_POST['csrf_token']);
    }
}

// Utility functions
if (!function_exists('redirect')) {
    function redirect(\$url) {
        header('Location: ' . \$url);
        exit;
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset(\$_SESSION['user_id']) && !empty(\$_SESSION['user_id']);
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        if (!is_logged_in()) {
            redirect('login.php');
        }
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return isset(\$_SESSION['user_role']) && \$_SESSION['user_role'] === 'admin';
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        require_login();
        if (!is_admin()) {
            redirect('index.php');
        }
    }
}

// Set global variables for easy access
\$GLOBALS['panel_config'] = \$panel_config;
\$GLOBALS['db_config'] = \$db_config;
EOT;

        if (!file_put_contents('../config.php', $config_content)) {
            $error = "ERROR: Failed to create config.php. Check file permissions for the web root directory.";
        } else {
            // Clear session data
            unset($_SESSION['db_config']);
            unset($_SESSION['fresh_install']);
            $success_messages[] = "Configuration file created successfully!";
            $success_messages[] = "Admin user '" . $_SESSION['admin_user']['username'] . "' created successfully!";
        }
    } else {
        // Session lost, redirect to db config step
        header('Location: install.php?step=3');
        exit;
    }
}

// Handle AJAX for step 3
if ($current_step == 3 && isset($_POST['test_connection'])) {
    header('Content-Type: application/json');

    $host = $_POST['host'] ?? 'localhost';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $port = $_POST['port'] ?? 3306;
    $database = $_POST['database'] ?? '';

    try {
        // Test connection with database if provided, otherwise just server connection
        $test_conn = @new mysqli($host, $username, $password, $database, (int)$port);
        if ($test_conn->connect_error) {
            throw new Exception($test_conn->connect_error);
        }
        $test_conn->close();

        $_SESSION['db_config'] = $_POST;
        echo json_encode(['success' => true, 'host' => $host, 'port' => $port, 'database' => $database]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'host' => $host, 'port' => $port]);
    }
    exit;
}

// Check requirements
function checkRequirements() {
    $requirements = [
        'PHP Version >= 8.0' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'MySQLi Extension' => extension_loaded('mysqli'),
        'Password Hash Support' => function_exists('password_hash'),
        'Session Support' => function_exists('session_start'),
        'File Write Permission' => is_writable('..'),
        'Database SQL File' => file_exists('../database.sql')
    ];
    return $requirements;
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Phynx Hosting Panel - Installation</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/style.css">
    </head>
    <body>
        <div class="top-progress-bar">
            <div class="top-progress-fill" style="width: <?= ($current_step / count($steps)) * 100 ?>%"></div>
        </div>
        
        <div class="install-wrapper">
            <div class="install-sidebar">
                <div class="install-logo">
                    <i class="fas fa-server"></i>
                    <h1>PHYNX</h1>
                    <p>Hosting Panel Installation</p>
                    <div class="install-version">v<?= $version; ?></div>
                </div>
                
                <div class="progress-section">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= ($current_step / count($steps)) * 100 ?>%"></div>
                    </div>
                </div>
                
                <div class="steps-sidebar">
                    <?php foreach ($steps as $num => $name): ?>
                        <div class="step-item <?= $num == $current_step ? 'active' : ($num < $current_step ? 'completed' : 'pending') ?>">
                            <div class="step-number">
                                <?php if ($num < $current_step): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    <?= $num ?>
                                <?php endif; ?>
                            </div>
                            <div class="step-info">
                                <div class="step-title"><?= $name ?></div>
                                <div class="step-status">
                                    <?= $num < $current_step ? 'Completed' : ($num == $current_step ? 'In Progress' : 'Pending') ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="install-main">
                <div class="install-content">
                    
                    <?php if ($current_step == 1): ?>
                        <!-- Step 1: Welcome -->
                        <div class="install-header">
                            <h2><i class="fas fa-rocket"></i> Welcome to Phynx Hosting Panel</h2>
                            <p>Let's get your hosting control panel up and running in just a few steps.</p>
                        </div>
                        
                        <div class="text-center">
                            <div class="install-welcome-content">
                                <h3>What we'll set up:</h3>
                                <ul class="install-welcome-list">
                                    <li><i class="fas fa-check text-success"></i> Database tables and schema</li>
                                    <li><i class="fas fa-check text-success"></i> Administrator account</li>
                                    <li><i class="fas fa-check text-success"></i> Configuration files</li>
                                    <li><i class="fas fa-check text-success"></i> Security settings</li>
                                </ul>
                            </div>
                            <a href="install.php?step=2" class="btn">
                                <i class="fas fa-arrow-right"></i> Start Installation
                            </a>
                        </div>
                        
                    <?php elseif ($current_step == 2): ?>
                        <!-- Step 2: Requirements Check -->
                        <div class="install-header">
                            <h2><i class="fas fa-clipboard-check"></i> System Requirements</h2>
                            <p>Checking if your server meets the requirements...</p>
                        </div>
                        
                        <?php
                        $requirements = checkRequirements();
                        $all_passed = array_reduce($requirements, function($carry, $item) { return $carry && $item; }, true);
                        ?>
                        
                        <div class="requirements-list">
                            <?php foreach ($requirements as $name => $passed): ?>
                                <div class="requirement <?= $passed ? 'pass' : 'fail' ?>">
                                    <span><?= $name ?></span>
                                    <span class="requirement-status">
                                        <?php if ($passed): ?>
                                            <i class="fas fa-check"></i> Pass
                                        <?php else: ?>
                                            <i class="fas fa-times"></i> Fail
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($all_passed): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> All requirements met! You can proceed with the installation.
                            </div>
                            <div class="text-center">
                                <a href="install.php?step=3" class="btn">
                                    <i class="fas fa-arrow-right"></i> Continue to Database Setup
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-triangle"></i> Some requirements are not met. Please fix the issues above before continuing.
                            </div>
                            <div class="text-center">
                                <a href="install.php?step=2" class="btn btn-secondary">
                                    <i class="fas fa-sync"></i> Check Again
                                </a>
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($current_step == 3): ?>
                        <!-- Step 3: Database Configuration -->
                        <div class="install-header">
                            <h2><i class="fas fa-database"></i> Database Configuration</h2>
                            <p>Configure your MySQL/MariaDB connection settings.</p>
                        </div>
                        
                        <form method="post" id="dbConfigForm">
                            <div class="form-group">
                                <label for="host">Database Host:</label>
                                <input type="text" id="host" name="host" value="<?= $_SESSION['db_config']['host'] ?? 'localhost' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="port">Port:</label>
                                <input type="number" id="port" name="port" value="<?= $_SESSION['db_config']['port'] ?? '3306' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="username">Username:</label>
                                <input type="text" id="username" name="username" value="<?= $_SESSION['db_config']['username'] ?? '' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password:</label>
                                <input type="password" id="password" name="password" value="<?= $_SESSION['db_config']['password'] ?? '' ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="database">Database Name (optional):</label>
                                <input type="text" id="database" name="database" value="<?= $_SESSION['db_config']['database'] ?? 'phynx_hosting' ?>" placeholder="Leave empty to create/select later">
                            </div>
                            
                            <div class="text-center">
                                <button type="button" class="btn btn-secondary" onclick="testConnection()">
                                    <i class="fas fa-plug"></i> Test Connection
                                </button>
                                <button type="button" class="btn hidden" onclick="proceedToNextStep()" id="continueBtn">
                                    <i class="fas fa-arrow-right"></i> Continue
                                </button>
                            </div>
                        </form>
                        
                        <div id="dbTestResult"></div>
                        
                        <script>
                        function testConnection() {
                            const formData = new FormData(document.getElementById('dbConfigForm'));
                            formData.append('test_connection', '1');
                            
                            fetch('install.php?step=3', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                const resultDiv = document.getElementById('dbTestResult');
                                if (data.success) {
                                    resultDiv.innerHTML = '<div class="db-test-result success"><i class="fas fa-check-circle"></i> Connection successful! Connected to ' + data.host + ':' + data.port + (data.database ? ' (Database: ' + data.database + ')' : '') + '</div>';
                                    document.getElementById('continueBtn').classList.remove('hidden');
                                } else {
                                    resultDiv.innerHTML = '<div class="db-test-result error"><i class="fas fa-times-circle"></i> Connection failed: ' + data.error + '</div>';
                                    document.getElementById('continueBtn').classList.add('hidden');
                                }
                            })
                            .catch(error => {
                                document.getElementById('dbTestResult').innerHTML = '<div class="db-test-result error"><i class="fas fa-times-circle"></i> Test failed: ' + error.message + '</div>';
                            });
                        }
                        
                        function proceedToNextStep() {
                            window.location.href = 'install.php?step=4';
                        }
                        </script>
                        
                    <?php elseif ($current_step == 4): ?>
                        <!-- Step 4: Create Admin User -->
                        <div class="install-header">
                            <h2><i class="fas fa-user-shield"></i> Create Admin Account</h2>
                            <p>Set up your administrator account for the hosting panel.</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['db_messages']) && !empty($_SESSION['db_messages'])): ?>
                            <div class="db-messages">
                                <h4>Database Setup Messages:</h4>
                                <ul>
                                    <?php foreach ($_SESSION['db_messages'] as $msg): ?>
                                        <li><?= htmlspecialchars($msg) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post">
                            <div class="form-group">
                                <label for="username">Admin Username:</label>
                                <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Admin Email:</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password:</label>
                                <input type="password" id="password" name="password" required>
                                <small class="form-help-text">Minimum 6 characters</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password:</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn">
                                    <i class="fas fa-user-plus"></i> Create Admin Account & Setup Database
                                </button>
                            </div>
                        </form>
                        
                    <?php elseif ($current_step == 5): ?>
                        <!-- Step 5: Complete Installation -->
                        <div class="install-header">
                            <h2><i class="fas fa-check-circle"></i> Complete Installation</h2>
                            <p>Finalize your Phynx Hosting Panel installation.</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success_messages)): ?>
                            <?php foreach ($success_messages as $msg): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['db_messages']) && !empty($_SESSION['db_messages'])): ?>
                            <div class="db-messages">
                                <h4>Database Setup Results:</h4>
                                <ul>
                                    <?php foreach ($_SESSION['db_messages'] as $msg): ?>
                                        <li><?= htmlspecialchars($msg) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (file_exists('../config.php')): ?>
                            <!-- Installation completed successfully -->
                            <div class="alert alert-success">
                                <i class="fas fa-trophy"></i> <strong>Hosting Panel Installation completed successfully!</strong><br>
                                Your Phynx Hosting Panel is ready to use.<br>
                                <small><em>Note: This is separate from your phynxadmin configuration.</em></small>
                            </div>
                            
                            <div class="install-summary-box">
                                <h4><i class="fas fa-info-circle"></i> Installation Summary</h4>
                                <ul class="install-summary-list">
                                    <li><strong>Hosting Panel Config:</strong> <code>/config.php</code> ✅</li>
                                    <li><strong>PhynxAdmin Config:</strong> <code>/phynxadmin/config.php</code> (separate) ✅</li>
                                    <li><strong>Database Schema:</strong> Hosting panel tables created ✅</li>
                                    <li><strong>Admin User:</strong> <?= htmlspecialchars($_SESSION['admin_user']['username'] ?? 'Created') ?> ✅</li>
                                </ul>
                            </div>
                            
                            <div class="completion-actions">
                                <a href="login.php" class="btn">
                                    <i class="fas fa-sign-in-alt"></i> Login to Hosting Panel
                                </a>
                                <a href="../phynxadmin/" class="btn btn-secondary">
                                    <i class="fas fa-database"></i> Access PhynxAdmin
                                </a>
                                <button type="button" class="btn btn-secondary" onclick="deleteInstaller()">
                                    <i class="fas fa-trash"></i> Remove Installer
                                </button>
                                <a href="install.php?force=true" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Fresh Install
                                </a>
                            </div>
                            
                            <script>
                            function deleteInstaller() {
                                if (confirm('Are you sure you want to delete the installer? This action cannot be undone.')) {
                                    fetch('install.php?installer_delete=1', { method: 'POST' })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            alert('Installer removed successfully. Redirecting to login...');
                                            window.location.href = '../login.php';
                                        } else {
                                            alert('Error: ' + data.message);
                                        }
                                    })
                                    .catch(error => {
                                        alert('Error removing installer: ' + error.message);
                                    });
                                }
                            }
                            </script>
                            
                        <?php else: ?>
                            <!-- Create config file -->
                            <div class="install-config-info">
                                <h4>Final Step: Create Hosting Panel Configuration</h4>
                                <p>Click the button below to create the hosting panel configuration file and complete the installation.</p>
                                <div class="install-warning-box">
                                    <small><strong>Note:</strong> This will create <code>/config.php</code> for the hosting panel, which is separate from your <code>/phynxadmin/config.php</code> file.</small>
                                </div>
                            </div>
                            
                            <form method="post">
                                <div class="text-center">
                                    <button type="submit" class="btn">
                                        <i class="fas fa-cog"></i> Create Hosting Panel Configuration & Complete Setup
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                    
                </div>
            </div>
        </div>
    </body>
</html>