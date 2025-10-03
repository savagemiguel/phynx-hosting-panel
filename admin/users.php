<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Handle user actions
if ($_POST) {
    if (isset($_POST['create_user'])) {
        $username = sanitize($_POST['username']);
        $email = sanitize($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $package_id = (int)$_POST['package_id'];
        
        $query = "INSERT INTO users (username, email, password, package_id, status) VALUES (?, ?, ?, ?, 'active')";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "sssi", $username, $email, $password, $package_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">User created successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error: ' . mysqli_error($conn) . '</div>';
        }
    }
    
    if (isset($_POST['edit_user'])) {
        $user_id = (int)$_POST['user_id'];
        $username = sanitize($_POST['username']);
        $email = sanitize($_POST['email']);
        $package_id = (int)$_POST['package_id'];
        
        $query = "UPDATE users SET username = ?, email = ?, package_id = ? WHERE id = ? AND role = 'user'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssii", $username, $email, $package_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">User updated successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error updating user</div>';
        }
    }
    
    if (isset($_POST['update_status'])) {
        $user_id = (int)$_POST['user_id'];
        $status = $_POST['status'];
        
        $query = "UPDATE users SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $status, $user_id);
        mysqli_stmt_execute($stmt);
        $message = '<div class="alert alert-success">User status updated</div>';
    }
    
    if (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        
        $query = "DELETE FROM users WHERE id = ? AND role = 'user'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">User deleted successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error deleting user</div>';
        }
    }
}

// Get user for editing if edit parameter is set
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $query = "SELECT * FROM users WHERE id = ? AND role = 'user'";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $edit_user = mysqli_fetch_assoc($result);
}

// Get packages for dropdown
$packages_result = mysqli_query($conn, "SELECT id, name FROM packages WHERE status = 'active'");
$packages = mysqli_fetch_all($packages_result, MYSQLI_ASSOC);

// Get users
$users_result = mysqli_query($conn, "SELECT u.*, p.name as package_name FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.role = 'user' ORDER BY u.created_at DESC");
$users = mysqli_fetch_all($users_result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Users - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-users"></i> User Management</h1>
        
        <?= $message ?>
        
        <div class="card">
            <h3><?= $edit_user ? 'Edit User' : 'Create New User' ?></h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <?php if ($edit_user): ?>
                    <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
                <?php endif; ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" value="<?= $edit_user ? htmlspecialchars($edit_user['username']) : '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" value="<?= $edit_user ? htmlspecialchars($edit_user['email']) : '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" value="<?= $edit_user ? htmlspecialchars($edit_user['password']) : '' ?>"required>
                    </div>
                    <div class="form-group">
                        <label>Package</label>
                        <select name="package_id" class="form-control" required>
                            <option value="">Select Package</option>
                            <?php foreach ($packages as $package): ?>
                                <option value="<?= $package['id'] ?>" <?= $edit_user && $edit_user['package_id'] == $package['id'] ? 'selected' : '' ?>><?= htmlspecialchars($package['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="<?= $edit_user ? 'edit_user' : 'create_user' ?>" class="btn btn-primary"><?= $edit_user ? 'Update User' : 'Create User' ?></button>
                <?php if ($edit_user): ?>
                    <a href="users.php" class="btn btn-secondary" style="margin-left: 12px; background: var(--bg-tertiary); color: var(--text-primary);">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="card">
            <h3>All Users</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars($user['package_name'] ?? 'None') ?></td>
                        <td><?= ucfirst($user['status']) ?></td>
                        <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                        <td class="actions-cell">
                            <div class="actions-dropdown">
                                <a href="users.php?edit=<?= $user['id'] ?>" class="btn btn-primary">Edit</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <select name="status" class="form-control">
                                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                        <option value="pending" <?= $user['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    </select>
                                    <button type="submit" name="update_status" class="btn btn-success">Update</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>