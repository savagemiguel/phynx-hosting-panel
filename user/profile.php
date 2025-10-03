<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

// CSRF verification for POST requests
if ($_POST && !csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }

// Handle profile update
if ($_POST && isset($_POST['update_profile'])) {
    $email = sanitize($_POST['email']);

    $stmt = mysqli_prepare($conn, "UPDATE users SET email = ? WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "si", $email, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Profile updated successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error updating profile</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Error updating profile</div>';
    }
}

// Handle password change
if ($_POST && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Get current password hash
    $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $userRow = mysqli_fetch_assoc($result);
    } else {
        $userRow = null;
    }

    if (!$userRow || !(password_verify($current_password, $userRow['password']) || $current_password === $userRow['password'])) {
        $message = '<div class="alert alert-error">Current password is incorrect</div>';
    } elseif ($new_password !== $confirm_password) {
        $message = '<div class="alert alert-error">New passwords do not match</div>';
    } elseif (strlen($new_password) < 6) {
        $message = '<div class="alert alert-error">Password must be at least 6 characters</div>';
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $hashed_password, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $message = '<div class="alert alert-success">Password changed successfully</div>';
            } else {
                $message = '<div class="alert alert-error">Error changing password</div>';
            }
        } else {
            $message = '<div class="alert alert-error">Error changing password</div>';
        }
    }
}

// Get user info
$stmt = mysqli_prepare($conn, "SELECT u.*, p.name as package_name FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Profile - Hosting Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color);">
            <h3 style="color: var(--primary-color);">User Panel</h3>
            <p style="color: var(--text-secondary); font-size: 14px;"><?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <ul class="sidebar-nav">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="domains.php">My Domains</a></li>
            <li><a href="profile.php" class="active">Profile</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <h1>Profile</h1>
        
        <?= $message ?>
        
        <div class="card">
            <h3>Account Information</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Package</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['package_name'] ?? 'None') ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user['status']) ?>" disabled>
                    </div>
                </div>
                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
            </form>
        </div>
        
        <div class="card">
            <h3>Change Password</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
            </form>
        </div>
        
        <div class="card">
            <h3>Account Details</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div>
                    <strong>Member Since:</strong><br>
                    <?= date('F j, Y', strtotime($user['created_at'])) ?>
                </div>
                <div>
                    <strong>Account ID:</strong><br>
                    #<?= str_pad($user['id'], 6, '0', STR_PAD_LEFT) ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>