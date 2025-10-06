<?php
// Get ALL MySQL users with their privileges
$users_query = "SELECT User, Host, authentication_string, plugin, Super_priv, Select_priv, Insert_priv, Update_priv, Delete_priv, Create_priv, Drop_priv, Grant_priv FROM mysql.user ORDER BY User, Host";

$users_result = $conn->query($users_query);
$users = [];

if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        // Check if user has password
        $has_password = !empty($row['authentication_string']) ? 'Yes' : 'No';

        // Determine global privileges
        $global_privileges = [];
        if ($row['Super_priv'] === 'Y') {
            $global_privileges[] = 'SUPER';
        }

        if ($row['Select_priv'] === 'Y' && $row['Insert_priv'] === 'Y' && $row['Update_priv'] === 'Y' && $row['Delete_priv'] === 'Y' && $row['Create_priv'] === 'Y' && $row['Drop_priv'] === 'Y' && $row['Grant_priv'] === 'Y') {
            $global_privileges[] = 'ALL PRIVILEGES';
        } else {
            if ($row['Select_priv'] === 'Y') $global_privileges[] = 'SELECT';
            if ($row['Insert_priv'] === 'Y') $global_privileges[] = 'INSERT';
            if ($row['Update_priv'] === 'Y') $global_privileges[] = 'UPDATE';
            if ($row['Delete_priv'] === 'Y') $global_privileges[] = 'DELETE';
            if ($row['Create_priv'] === 'Y') $global_privileges[] = 'CREATE';
            if ($row['Drop_priv'] === 'Y') $global_privileges[] = 'DROP';
        }

        if (empty($global_privileges)) {
            $global_privileges[] = 'USAGE';
        }

        // Determine user group
        $user_group = 'Standard User';
        if ($row['Super_priv'] === 'Y') $user_group = 'Super Admin';
        elseif ($row['Grant_priv'] === 'Y') $user_group = 'Administrator';
        elseif (empty($global_privileges) || $global_privileges === ['USAGE']) $user_group = 'Read Only';

        $users[] = [
            'username' => $row['User'],
            'hostname' => $row['Host'],
            'password' => $has_password,
            'privileges' => implode(', ', $global_privileges),
            'user_group' => $user_group,
            'grant' => $row['Grant_priv'] === 'Y' ? 'Yes' : 'No'
        ];
    }
}

// Handle user creation
if (isset($_POST['create_user'])) {
    $username = $_POST['username'];
    $hostname = $_POST['hostname'] ?? '%';
    $password = $_POST['password'];
    $auth_plugin = $_POST['auth_plugin'];
    $privileges = $_POST['privileges'] ?? [];

    try {
        // Create user
        $create_sql = "CREATE USER '$username'@'$hostname' IDENTIFIED BY '$password'";
        $conn->query($create_sql);

        // Grant privileges if any selected
        $valid_privileges = array_filter($privileges, fn($privilege) => !empty(trim($privilege)));

        if (!empty($valid_privileges)) {
            $has_grant = in_array('GRANT', $valid_privileges);

            if ($has_grant) {
                // If GRANT is selected, use GRANT ALL PRIVILEGES
                $grant_sql = "GRANT ALL PRIVILEGES ON *.* TO '$username'@'$hostname' WITH GRANT OPTION";
                $conn->query($grant_sql);
            } else {
                //  Grant individual privileges without GRANT option
                foreach ($valid_privileges as $privilege) {
                    $privilege = trim($privilege);
                    try {
                        $grant_sql = "GRANT $privilege ON *.* TO '$username'@'$hostname'";
                        $conn->query($grant_sql);
                    } catch (Exception $e) {
                        $error_message = "ERROR: {$e->getMessage()}";
                        continue; // Skip invalid privileges
                    }
                }
            }
        }

        // Create database if requested
        if (isset($_POST['create_db'])) {
            $create_db_sql = "CREATE DATABASE IF NOT EXISTS `$username`";
            $conn->query($create_db_sql);

            $grant_db_sql = "GRANT ALL PRIVILEGES ON `$username`.* TO '$username'@'$hostname'";
            $conn->query($grant_db_sql);
        }

        // Grant wildcard privileges if requested
        if (isset($_POST['grant_wildcard'])) {
            $wildcard_sql = "GRANT ALL PRIVILEGES ON `{$username}_%`.* TO '$username'@'$hostname'";
            $conn->query($wildcard_sql);
        }

        $conn->query("FLUSH PRIVILEGES");
        $success_message = "User '$username'@'$hostname' created successfully!";

        // Use JavaScript to refresh the page to avoid resend warning
        echo "<script>
        setTimeout(function() {
        window.location.replace('?page=users'); // Redirect to the same page with success message
        }, 2000);
        </script>";
    } catch (Exception $e) {
        $error_message = "ERROR! " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Accounts Overview</title>
    <link rel="stylesheet" href="includes/css/users.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="content-header">
        <h2>User Accounts Overview</h2>
        <div class="breadcrumb">
            <?= functions::getServerInfo($conn)['connection_info']; ?>
            <i class="fa fa-angle-right"></i>
            <span class="breadcrumb_text"><i class="fas fa-users"></i> User Accounts</span>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="message success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="message error">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="userForm" autocomplete="off">
        <div class="card-row">
            <div class="card-column">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user"></i> Login Information</h3>
                    </div>
                    <div class="card-body">
                        <table class="form-table">
                            <tr>
                                <td>Host Name:</td>
                                <td>
                                    <div class="host-input-group">
                                        <select name="host_type" onchange="updateHostInput(this.value)">
                                            <option value="any">Any Host (%)</option>
                                            <option value="localhost">Localhost</option>
                                            <option value="this_host">This Host</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                        <input type="text" name="hostname" id="host_input" value="%">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Username:</td>
                                <td><input type="text" name="username" required></td>
                            </tr>
                            <tr>
                                <td>Password:</td>
                                <td>
                                    <div class="password-input-wrapper">
                                        <input type="password" name="password" id="password" oninput="checkPasswordStrength(this.value); checkPasswordMatch();">
                                        <i class="fas fa-eye password-toggle" onclick="togglePassword('password')"></i>
                                    </div>
                                    <div class="password-strength">
                                        <div class="strength-bar"></div>
                                        <span class="strength-text"></span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Re-Type:</td>
                                <td>
                                    <div class="password-input-wrapper">
                                        <input type="password" name="password_confirm" id="password_confirm" oninput="checkPasswordMatch();">
                                        <i class="fas fa-eye password-toggle" onclick="togglePassword('password_confirm')"></i>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Authentication:</td>
                                <td>
                                    <select name="auth_plugin">
                                        <option value="caching_sha2_password">caching_sha2_password</option>
                                        <option value="sha256_password">sha256_password</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td>Generate Password:</td>
                                <td><button type="button" onclick="generatePassword()" class="btn generate-btn">
                                    <span class="btn-text">Generate Password</span>
                                    <span class="spinner"></span>
                                    </button></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="card small">
                    <div class="card-header">
                        <h3><i class="fas fa-database"></i> Database Options</h3>
                    </div>
                    <div class="card-body">
                        <!-- <div class="options-list"> -->
                        <table class="form-table">
                            <tr>
                                <td>
                                    <label class="checkbox-wrap">
                                    <input type="checkbox" name="create_db" id="create_db">
                                        <span>Create database with same name</span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label class="checkbox-wrap">
                                    <input type="checkbox" name="grant_wildcard" id="grant_wildcard">
                                        <span>Grant ALL on wildcard (username_%)</span>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    
                        <div class="form-actions">
                            <div id="grantConfirmation" class="grant-confirmation" style="display: none;">
                                <label class="checkbox-wrap warning">
                                    <input type="checkbox" name="confirm_grant">
                                    <span>Yes, grant SUPER privileges</span>
                                </label>
                            </div>
                            <button type="submit" name="create_user" class="btn primary">
                                <i class="fas fa-user-plus"></i> Create User
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card large">
                    <div class="card-header">
                        <h3><i class="fas fa-users"></i> Existing Users</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Host</th>
                                        <th>Password</th>
                                        <th>Privileges</th>
                                        <th>Group</th>
                                        <th>Grant</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td class="username"><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= htmlspecialchars($user['hostname']) ?></td>
                                        <td>
                                            <span class="badge <?= $user['password'] === 'Yes' ? 'success' : 'warning' ?>">
                                                <?= htmlspecialchars($user['password']) ?>
                                            </span>
                                        </td>
                                        <td class="privileges"><?= htmlspecialchars($user['privileges']) ?></td>
                                        <td><?= htmlspecialchars($user['user_group']) ?></td>
                                        <td>
                                            <span class="badge <?= $user['grant'] === 'Yes' ? 'success' : 'muted' ?>">
                                                <?= htmlspecialchars($user['grant']) ?>
                                            </span>
                                        </td>
                                        <td class="actions">
                                            <div class="action-buttons">
                                            <a href="?page=edit_user&user=<?= urlencode($user['username']) ?>&host=<?= urlencode($user['hostname']) ?>" class="btn-action edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?page=export_users&user=<?= urlencode($user['username']) ?>&host=<?= urlencode($user['hostname']) ?>"  class="btn-action export">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-shield-alt"></i> Global Privileges
                        <label class="check-all">
                            <input type="checkbox" id="checkAll" onchange="toggleAllPrivileges()"> Check All
                        </label>
                    </h3>
                </div>
            
                <div class="card-body">
                    <div id="grantWarning" class="warning-message" style="display: none;">
                        WARNING: GRANT permission not selected automatically
                    </div>
                    <div class="privileges-grid">
                        <div class="privilege-section">
                            <h4>Data</h4>
                            <label for="SELECT"><input type="checkbox" name="privileges[]" value="SELECT"> SELECT</label>
                            <label for="INSERT"><input type="checkbox" name="privileges[]" value="INSERT"> INSERT</label>
                            <label for="UPDATE"><input type="checkbox" name="privileges[]" value="UPDATE"> UPDATE</label>
                            <label for="DELETE"><input type="checkbox" name="privileges[]" value="DELETE"> DELETE</label>
                            <label for="FILE"><input type="checkbox" name="privileges[]" value="FILE"> FILE</label>
                        </div>

                        <div class="privilege-section">
                            <h4>Structure</h4>
                            <label for="CREATE"><input type="checkbox" name="privileges[]" value="CREATE"> CREATE</label>
                            <label for="ALTER"><input type="checkbox" name="privileges[]" value="ALTER"> ALTER</label>
                            <label for="INDEX"><input type="checkbox" name="privileges[]" value="INDEX"> INDEX</label>
                            <label for="DROP"><input type="checkbox" name="privileges[]" value="DROP"> DROP</label>
                            <label for="CREATE_TEMP_TBL"><input type="checkbox" name="privileges[]" value="CREATE TEMPORARY TABLES"> CREATE TEMPORARY TABLES</label>
                            <label for="SHOW_VIEW"><input type="checkbox" name="privileges[]" value="SHOW VIEW"> SHOW VIEW</label>
                            <label for="CREATE_ROUTINE"><input type="checkbox" name="privileges[]" value="CREATE ROUTINE"> CREATE ROUTINE</label>
                            <label for="ALTER_ROUTINE"><input type="checkbox" name="privileges[]" value="ALTER ROUTINE"> ALTER ROUTINE</label>
                            <label for="EXECUTE"><input type="checkbox" name="privileges[]" value="EXECUTE"> EXECUTE</label>
                            <label for="CREATE_VIEW"><input type="checkbox" name="privileges[]" value="CREATE VIEW"> CREATE VIEW</label>
                            <label for="EVENT"><input type="checkbox" name="privileges[]" value="EVENT"> EVENT</label>
                            <label for="TRIGGER"><input type="checkbox" name="privileges[]" value="TRIGGER"> TRIGGER</label>
                        </div>

                        <div class="privilege-section">
                            <h4>Administration</h4>
                            <label for="GRANT"><input type="checkbox" name="privileges[]" value="GRANT" id="grantCheckbox" onchange="toggleGrantWarning()"> GRANT</label>
                            <label for="SUPER"><input type="checkbox" name="privileges[]" value="SUPER"> SUPER</label>
                            <label for="PROCESS"><input type="checkbox" name="privileges[]" value="PROCESS"> PROCESS</label>
                            <label for="RELOAD"><input type="checkbox" name="privileges[]" value="RELOAD"> RELOAD</label>
                            <label for="SHUTDOWN"><input type="checkbox" name="privileges[]" value="SHUTDOWN"> SHUTDOWN</label>
                            <label for="SHOW_DB"><input type="checkbox" name="privileges[]" value="SHOW DATABASES"> SHOW DATABASES</label>
                            <label for="LOCK_TBL"><input type="checkbox" name="privileges[]" value="LOCK TABLES"> LOCK TABLES</label>
                            <label for="REFERENCES"><input type="checkbox" name="privileges[]" value="REFERENCES"> REFERENCES</label>
                            <label for="REPLICATION_CLIENT"><input type="checkbox" name="privileges[]" value="REPLICATION CLIENT"> REPLICATION CLIENT</label>
                            <label for="REPLICATION_SAVE"><input type="checkbox" name="privileges[]" value="REPLICATION SLAVE"> REPLICATION SLAVE</label>
                            <label for="CREATE_USER"><input type="checkbox" name="privileges[]" value="CREATE USER"> CREATE USER</label>
                        </div>

                        <div class="privilege-section">
                            <h4>Resource Limits</h4>
                            <div class="resource-warning">
                                <b><u>WARNING</u></b>: <i>Setting these options to 0 (zero) removes the limit.</i>
                            </div>
                            <div class="resource-wrapper">
                            <label>
                                <span>MAX QUERIES PER HOUR:</span>
                                <input type="number" name="max_queries" value="0" min="0">
                            </label>
                            <label>
                                <span>MAX UPDATES PER HOUR:</span>
                                <input type="number" name="max_updates" value="0" min="0">
                            </label>
                            <label>
                                <span>MAX CONNECTIONS PER HOUR:</span>
                                <input type="number" name="max_connections" value="0" min="0">
                            </label>
                            <label>
                                <span>MAX USER_CONNECTIONS:</span>
                                <input type="number" name="max_user_connections" value="0" min="0">
                            </label>
                            </div>
                        </div>

                        <div class="privilege-section">
                        <h4>SSL</h4>
                            <label for="NOSSL"><input type="checkbox" name="ssl_type" value="NONE" checked> REQUIRE NO SSL</label>
                            <label for="REQUIRE_SSL"><input type="checkbox" name="ssl_type" value="SSL"> REQUIRE SSL</label>
                            <label for="REQUIRE_X509"><input type="checkbox" name="ssl_type" value="X509"> REQUIRE X509</label>
                            <label for="SPECIFIED"><input type="checkbox" name="ssl_type" value="SPECIFIED" onchange="toggleSSLOptions()"> SPECIFIED</label>
                            
                            <div class="sslOptions" style="display: none;">
                                <label for="SSL_CIPHER">REQUIRE CIPHER<input type="text" name="ssl_cipher"></label>
                                <label for="SSL_ISSUER">REQUIRE ISSUER<input type="text" name="ssl_issuer"></label>
                                <label for="SSL_SUBJECT">REQUIRE SUBJECT<input type="text" name="ssl_subject"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <script src="includes/js/users.js"></script>
</body>
</html>