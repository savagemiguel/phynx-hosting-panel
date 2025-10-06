<?php
require_once 'config.php';
require_once 'includes/functions.php';

$error = '';
$success_message = isset($_GET['message']) ? sanitize($_GET['message']) : '';

if ($_POST) {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid CSRF token'); }
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];
    
    $query = "SELECT id, username, password, role FROM users WHERE username = ? AND status = 'active'";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    
    if ($user && ($password === $user['password'] || password_verify($password, $user['password']))) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        
        header('Location: ' . ($user['role'] === 'admin' ? 'admin/' : 'user/'));
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Hosting Control Panel</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card card">
            <h2 style="text-align: center; margin-bottom: 32px; color: var(--primary-color);">Hosting Control Panel</h2>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= $success_message ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= $error ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Login</button>
            </form>
        </div>
    </div>
</body>
</html>