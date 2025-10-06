<?php
session_start();

// Debug: Check what's in the session
error_log("Session data: ".print_r($_SESSION, true));

require_once 'config.php';
require_once 'includes/config/conf.php';

/** DEBUG: UNCOMMENT THIS FOR DEBUGGING
echo '<pre style="background: black; color: white; padding: 10px; margin: 10px;">';
echo "Config data:\n";
var_dump(Config::get());
echo "\nServers:\n";
var_dump(Config::getServers());
echo "\nDefault Server:\n";
var_dump(Config::get('DefaultServer'));
echo '</pre>';
*/

$error_message = '';
$success_message = '';

// Get available servers
$servers = Config::getServers();
$default_server = Config::get('DefaultServer');

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

// Handle login form submission
if ($_POST && isset($_POST['login'])) {
    $server_id = intval($_POST['server'] ?? $default_server);
    $username = $_POST['db_user'] ?? '';
    $password = $_POST['db_pass'] ?? '';

    if (empty($username)) {
        $error_message = 'Please enter a username.';
    } else {
        // Get selected server's config
        $server_config = Config::getServer($server_id);

        if (!$server_config) {
            $error_message = 'Invalid server selection.';
        } else {
            // Try to connect to the selected server
            $test_conn = @new mysqli($server_config['host'], $username, $password, null, $server_config['port']);

            if (!$test_conn->connect_error) {
                // Connection successful
                $_SESSION['user_id'] = 1;
                $_SESSION['username'] = $username; // Keep for backwards compatibility
                $_SESSION['db_user'] = $username;
                $_SESSION['db_pass'] = $password; // Store for conf.php
                $_SESSION['server_id'] = $server_id;
                $_SESSION['current_server'] = $server_id; // Add this for consistency
                $_SESSION['server_name'] = $server_config['name'];
                $_SESSION['server_host'] = $server_config['host'];
                $_SESSION['logged_in'] = true;

                $test_conn->close();
                header('Location: index.php');
                exit();
            } else {
                $error_message = 'Connection failed: '.$test_conn->connect_error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - phpMyAdmin</title>
    <link rel="stylesheet" href="includes/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-database"></i>
                    <h1>PHYNX</h1>
                </div>
                <p>Welcome to your database management system</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="db_user">
                        <i class="fas fa-user"></i>
                        Username
                    </label>
                    <input type="text" 
                           id="username" 
                           name="db_user" 
                           value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" 
                           required 
                           autocomplete="username"
                           placeholder="Enter your username">
                </div>

                <div class="form-group">
                    <label for="db_pass">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <div class="password-input-wrapper">
                        <input type="password" 
                               id="password" 
                               name="db_pass" 
                               autocomplete="current-password"
                               placeholder="Enter your password">
                        <button type="button" class="password-toggle" onclick="togglePassword('password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="server">
                        <i class="fas fa-server"></i>
                        Server
                    </label>
                    <select id="server" name="server" required>
                        <?php foreach ($servers as $id => $server): ?>
                            <option value="<?= $id; ?>" <?= ($id == $default_server) ? 'selected' : ''; ?> data-host="<?= htmlspecialchars($server['host']); ?>" data-port="<?= htmlspecialchars($server['port']); ?>">
                            <?= htmlspecialchars($server['name']); ?>(<?= htmlspecialchars($server['host']); ?>:<?= htmlspecialchars($server['port']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember_me">
                        <span class="checkmark"></span>
                        Remember me
                    </label>
                </div>

                <button type="submit" name="login" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
            </form>

            <div class="login-footer">
                <div class="server-info">
                    <p><strong>Current Server:</strong> <?= htmlspecialchars($conn->host_info ?? 'localhost') ?></p>
                    <p><strong>Default Server:</strong> <?= htmlspecialchars($servers[$default_server]['name'] ?? 'None'); ?></p>
                    <p><strong>Available Servers:</strong> <?= count($servers); ?></p>
                    <p><strong>Version:</strong> <?= htmlspecialchars($conn->server_info ?? 'Unknown') ?></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // Auto-focus username field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });

        // Update username placeholder based on server selection
        document.getElementById('server').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const host = selectedOption.dataset.host;

            // You can set different default usernames based on server
            if (host === 'localhost') {
                document.getElementById('username').value = 'root';
            } else {
                document.getElementById('username').value = '';
            }
        });
    </script>
</body>
</html>