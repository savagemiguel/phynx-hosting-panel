<?php
$username = $_GET['user'] ?? '';
$hostname = $_GET['host'] ?? '';

if (empty($user) || empty($hostname)) {
    header('Location: ?page=users');
    exit;
}

// Get current user privileges
$user_query = "SELECT * FROM mysql.user WHERE User = '$username' AND Host = '$hostname'";
$user_result = $conn->query($user_query);
$user_privs = $user_result->fetch_assoc();

// Handle privileges update
if (isset($_POST['update_privileges'])) {
    $privileges = $_POST['privileges'] ?? [];

    try {
        // Revoke all privileges first
        $conn->query("REVOKE ALL PRIVILEGES ON *.* FROM '$username'@'$hostname'");

        // Grant selected privileges
        if (!empty($privileges)) {
            $has_grant = in_array('GRANT', $privileges);

            if ($has_grant) {
                $grant_sql = "GRANT ALL PRIVILEGES ON *.* TO '$username'@'$hostname' WITH GRANT OPTION";
                $conn->query($grant_sql);
            } else {
                foreach ($privileges as $privilege) {
                    $privilege = trim($privilege);
                    if (!empty($privilege)) {
                        $grant_sql = "GRANT $privilege ON *.* TO '$username'@'$hostname'";
                        $conn->query($grant_sql);
                    }
                }
            }
        }

        $conn->query("FLUSH PRIVILEGES");
        $success_message = "Privileges updated for '$username'@'$hostname'";

    } catch (Exception $e) {
        $error_message = "ERROR! {$e->getMessage()}";
    }
}

// Handle user deletion
if (isset($_POST['delete_user'])) {
    try {
        // Get all hosts for this user
        $hosts_query = "SELECT Host FROM mysql.user WHERE User = '$username'";
        $hosts_result = $conn->query($hosts_query);

        // Delete user from all hosts
        while ($host_row = $hosts_result->fetch_assoc()) {
            $hostname = $host_row['Host'];
            $conn->query("DROP USER `$username`@`$hostname`");
        }

        $conn->query("FLUSH PRIVILEGES");
        $success_message = "User '$username'@'$hostname' deleted successfully.";

        // Redirect to users page
        echo "<script>setTimeout(function() { window.location.href = '?page=users'; }, 1500);</script>";
    } catch (Exception $e) {
        $error_message = "ERROR: {$e->getMessage()}";
    }
}
?>

<div class="content-header">
    <h2>Edit User Privileges</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <i class="fas fa-user-edit"></i> <span class="breadcrumb_text">Edit: <?= $username ?>@<?= $hostname ?></span>
    </div>
</div>

<?php if (isset($success_message)): ?>
    <div class="success-message">
        <i class="fas fa-check-circle"></i> <?= $success_message ?>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="error-message">
        <i class="fas fa-exclamation-triangle"></i> <?= $error_message ?>
    </div>
<?php endif; ?>

<div class="edit-privileges-box">
    <form method="POST">
        <div class="edit-privileges-section">
            <h5><i class="fas fa-shield-alt"></i> Global Privileges</h5>

            <table class="edit-privileges-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Structure</th>
                        <th>Administration</th>
                        <th>Resource Limits</th>
                        <th>SSL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="edit-privileges-column">
                            <label><input type="checkbox" name="privileges[]" value="SELECT" <?= $user_privs['Select_priv'] === 'Y' ? 'checked' : '' ?>> SELECT</label>
                            <label><input type="checkbox" name="privileges[]" value="INSERT" <?= $user_privs['Insert_priv'] === 'Y' ? 'checked' : '' ?>> INSERT</label>
                            <label><input type="checkbox" name="privileges[]" value="UPDATE" <?= $user_privs['Update_priv'] === 'Y' ? 'checked' : '' ?>> UPDATE</label>
                            <label><input type="checkbox" name="privileges[]" value="DELETE" <?= $user_privs['Delete_priv'] === 'Y' ? 'checked' : '' ?>> DELETE</label>
                            <label><input type="checkbox" name="privileges[]" value="FILE" <?= $user_privs['File_priv'] === 'Y' ? 'checked' : '' ?>> FILE</label>
                        </td>
                        <td class="edit-privileges-column">
                            <label><input type="checkbox" name="privileges[]" value="CREATE" <?= $user_privs['Create_priv'] === 'Y' ? 'checked' : '' ?>> CREATE</label>
                            <label><input type="checkbox" name="privileges[]" value="ALTER" <?= $user_privs['Alter_priv'] === 'Y' ? 'checked' : '' ?>> ALTER</label>
                            <label><input type="checkbox" name="privileges[]" value="INDEX" <?= $user_privs['Index_priv'] === 'Y' ? 'checked' : '' ?>> INDEX</label>
                            <label><input type="checkbox" name="privileges[]" value="DROP" <?= $user_privs['Drop_priv'] === 'Y' ? 'checked' : '' ?>> DROP</label>
                            <label><input type="checkbox" name="privileges[]" value="CREATE TEMPORARY TABLES" <?= $user_privs['Create_tmp_table_priv'] === 'Y' ? 'checked' : '' ?>> CREATE TEMPORARY TABLES</label>
                            <label><input type="checkbox" name="privileges[]" value="SHOW VIEW" <?= $user_privs['Show_view_priv'] === 'Y' ? 'checked' : '' ?>> SHOW VIEW</label>
                            <label><input type="checkbox" name="privileges[]" value="CREATE ROUTINE" <?= $user_privs['Create_routine_priv'] === 'Y' ? 'checked' : '' ?>> CREATE ROUTINE</label>
                            <label><input type="checkbox" name="privileges[]" value="ALTER ROUTINE" <?= $user_privs['Alter_routine_priv'] === 'Y' ? 'checked' : '' ?>> ALTER ROUTINE</label>
                            <label><input type="checkbox" name="privileges[]" value="EXECUTE" <?= $user_privs['Execute_priv'] === 'Y' ? 'checked' : '' ?>> EXECUTE</label>
                            <label><input type="checkbox" name="privileges[]" value="CREATE VIEW" <?= $user_privs['Create_view_priv'] === 'Y' ? 'checked' : '' ?>> CREATE VIEW</label>
                            <label><input type="checkbox" name="privileges[]" value="EVENT" <?= $user_privs['Event_priv'] === 'Y' ? 'checked' : '' ?>> EVENT</label>
                            <label><input type="checkbox" name="privileges[]" value="TRIGGER" <?= $user_privs['Trigger_priv'] === 'Y' ? 'checked' : '' ?>> TRIGGER</label>
                        </td>
                        <td class="edit-privileges-column">
                            <label><input type="checkbox" name="privileges[]" value="GRANT" <?= $user_privs['Grant_priv'] === 'Y' ? 'checked' : '' ?>> GRANT</label>
                            <label><input type="checkbox" name="privileges[]" value="SUPER" <?= $user_privs['Super_priv'] === 'Y' ? 'checked' : '' ?>> SUPER</label>
                            <label><input type="checkbox" name="privileges[]" value="PROCESS" <?= $user_privs['Process_priv'] === 'Y' ? 'checked' : '' ?>> PROCESS</label>
                            <label><input type="checkbox" name="privileges[]" value="RELOAD" <?= $user_privs['Reload_priv'] === 'Y' ? 'checked' : '' ?>> RELOAD</label>
                            <label><input type="checkbox" name="privileges[]" value="SHUTDOWN" <?= $user_privs['Shutdown_priv'] === 'Y' ? 'checked' : '' ?>> SHUTDOWN</label>
                            <label><input type="checkbox" name="privileges[]" value="SHOW DATABASES" <?= $user_privs['Show_db_priv'] === 'Y' ? 'checked' : '' ?>> SHOW DATABASES</label>
                            <label><input type="checkbox" name="privileges[]" value="LOCK TABLES" <?= $user_privs['Lock_tables_priv'] === 'Y' ? 'checked' : '' ?>> LOCK TABLES</label>
                            <label><input type="checkbox" name="privileges[]" value="REFERENCES" <?= $user_privs['References_priv'] === 'Y' ? 'checked' : '' ?>> REFERENCES</label>
                            <label><input type="checkbox" name="privileges[]" value="REPLICATION CLIENT" <?= $user_privs['Repl_client_priv'] === 'Y' ? 'checked' : '' ?>> REPLICATION CLIENT</label>
                            <label><input type="checkbox" name="privileges[]" value="REPLICATION SLAVE" <?= $user_privs['Repl_slave_priv'] === 'Y' ? 'checked' : '' ?>> REPLICATION SLAVE</label>
                            <label><input type="checkbox" name="privileges[]" value="CREATE USER" <?= $user_privs['Create_user_priv'] === 'Y' ? 'checked' : '' ?>> CREATE USER</label>
                        </td>
                        <td class="edit-privileges-column">
                            <div class="resource-warning"><b><u>INFO</u></b>: <i>Current resource limits (0 = unlimited)</i></div>
                                <label>MAX QUERIES PER HOUR: <input type="number" name="max_questions" value="<?= $user_privs['max_questions']; ?>" min="0" max="999" step="1"></label>
                                <label>MAX UPDATES PER HOUR: <input type="number" name="max_updates" value="<?= $user_privs['max_updates']; ?>" min="0" max="999" step="1"></label>
                                <label>MAX CONNECTIONS PER HOUR: <input type="number" name="max_connections" value="<?= $user_privs['max_connections']; ?>" min="0" max="999" step="1"></label>
                                <label>MAX USER_CONNECTIONS: <input type="number" name="max_user_connections" value="<?= $user_privs['max_user_connections']; ?>" min="0" max="999" step="1"></label>
                        </td>
                        <td class="edit-privileges-column">
                            <label>SSL TYPE: <input type="text" name="ssl_type" value="<?= $user_privs['ssl_type']; ?>" placeholder="NONE"></label>
                            <label>SSL CIPHER: <input type="text" name="ssl_cipher" value="<?= $user_privs['ssl_cipher']; ?>" placeholder="N/A"></label>
                            <label>X509 ISSUER: <input type="text" name="x509_issuer" value="<?= $user_privs['x509_issuer']; ?>" placeholder="N/A"</label>
                            <label>X509 SUBJECT: <input type="text" name="x509_subject" value="<?= $user_privs['x509_subject']; ?>" placeholder="N/A"</label>
                            capitalize edit privileges, export, add delete button and make the buttons evenly gapped and sized when smaller resolution is used, fix breadcrumb on every page
                        </td>
                    </tr>
                </tbody>
            </table>
        

        <div class="edit-privileges-button-section">
            <button type="submit" name="update_privileges" class="btn">
                <i class="fas fa-save"></i> Update Privileges
            </button>
            <button type="submit" name="delete_user" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete <?= $username ?>?')">
                <i class="fas fa-trash"></i> Delete User
            </button>
            <a href="?page=users" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
</div>
    </form>
</div>