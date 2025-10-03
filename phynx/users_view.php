<?php
include 'includes/var.funcs.php';

$message = '';
$edit_user = '';
$edit_host = '';
$edit_password = '';
$edit_privileges = '';

// Handle form submission
if ($_POST) {
    if (isset($_POST['delete_user']) && isset($_POST['delete_host'])) {
        // Delete user
        $user = $conn->real_escape_string($_POST['delete_user']);
        $host = $conn->real_escape_string($_POST['delete_host']);

        if ($conn->query("DROP USER '$user'@'$host'")) {
            $message = "User '$user'@'$host' deleted successfully.";
        } else {
            $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['update_user'])) {
        // Update user password
        $user = $conn->real_escape_string($_POST['user']);
        $host = $conn->real_escape_string($_POST['host']);
        $password = $_POST['password'];

        if ($password) {
            if ($conn->query("ALTER USER '$user'@'$host' IDENTIFIED BY '$password'")) {
                $message = "Password updated successfully.";
                $edit_user = '';
                $edit_host = '';
            } else {
                $message = "Error: " . $conn->error;
            }
        }
    }
}

// Get edit parameters
if (isset($_GET['edit_user']) && isset($_GET['edit_host'])) {
    $edit_user = $_GET['edit_user'];
    $edit_host = $_GET['edit_host'];
}

$users = $conn->query("SELECT User, Host FROM mysql.user ORDER BY User");
?>

<div class="content-header">
    <h2>MySQL Users</h2>
    <div class="breadcrumb">
        <?php echo $connection_info; ?>
        <i class="fa fa-angle-right"></i>
        Users
    </div>
</div>

<?php if ($message): ?>
    <div class="info-box success-message"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($edit_user && $edit_host): ?>
    <div class="info-box">
        <h4> Edit User: <?= $edit_user ?>@<?= $edit_host ?></h4>
        <form method="POST">
            <input type="hidden" name="user" value="<?= $edit_user ?>">
            <input type="hidden" name="host" value="<?= $edit_host ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" name="password" placeholder="Enter New Password" required>
                </div>
                <button type="submit" name="update_user" class="btn">Update Password</button>
                <a href="?page=users" class="btn" style="background: var(--text-muted);">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>User</th>
            <th>Host</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($user = $users->fetch_assoc()) { ?>
            <tr>
                <td><?php echo $user['User']; ?></td>
                <td><?php echo $user['Host']; ?></td>
                <td>
                    <a href="?page=users&edit_user=<?= urlencode($user['User']) ?>&edit_host=<?= urlencode($user['Host']) ?>" class="btn" style="font-size: 12px;">Edit</a>

                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete user <?= $user['User'] ?>@<?= $user['Host'] ?>?')">
                        <input type="hidden" name="delete_user" value="<?= $user['User'] ?>">
                        <input type="hidden" name="delete_host" value="<?= $user['Host'] ?>">
                        <button type="submit" name="delete_user" class="btn" style="font-size: 12px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php } ?>
    </tbody>
</table>