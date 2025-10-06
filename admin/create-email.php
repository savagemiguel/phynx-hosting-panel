<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

// Handle email creation
if ($_POST && isset($_POST['create_email'])) {
    $user_id = (int)$_POST['user_id'];
    $domain_id = (int)$_POST['domain_id'];
    $email_prefix = sanitize($_POST['email_prefix']);
    $password = $_POST['password'];
    $quota = (int)$_POST['quota'];
    
    // Get domain name
    $query = "SELECT domain_name FROM domains WHERE id = ? AND user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $domain_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $domain = mysqli_fetch_assoc($result);
    
    if ($domain) {
        $full_email = $email_prefix . '@' . $domain['domain_name'];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO email_accounts (domain_id, email, password, quota) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "issi", $domain_id, $full_email, $hashed_password, $quota);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Email account created successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error creating email account</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Invalid domain selection</div>';
    }
}

// Get users and their domains
$users_query = "
    SELECT u.id, u.username, d.id as domain_id, d.domain_name 
    FROM users u 
    JOIN domains d ON u.id = d.user_id 
    WHERE u.role = 'user' AND u.status = 'active' AND d.status = 'active'
    ORDER BY u.username, d.domain_name
";
$users_result = mysqli_query($conn, $users_query);
$user_domains = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $user_domains[$row['id']]['username'] = $row['username'];
    $user_domains[$row['id']]['domains'][] = [
        'id' => $row['domain_id'],
        'name' => $row['domain_name']
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Email Account - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1>Create Email Account</h1>
        <a href="email-manager.php" class="btn btn-primary" style="margin-bottom: 24px;">← Back to Email Manager</a>
        
        <?= $message ?>
        
        <div class="card">
            <h3>Create New Email Account</h3>
            <form method="POST" id="emailForm">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Select User</label>
                        <select name="user_id" class="form-control" onchange="updateDomains()" required>
                            <option value="">Select User</option>
                            <?php foreach ($user_domains as $user_id => $user_data): ?>
                                <option value="<?= $user_id ?>"><?= htmlspecialchars($user_data['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select Domain</label>
                        <select name="domain_id" class="form-control" required>
                            <option value="">Select Domain</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Email Address</label>
                        <div style="display: flex; align-items: center;">
                            <input type="text" name="email_prefix" class="form-control" placeholder="info" required>
                            <span style="margin: 0 8px; color: var(--text-muted);">@</span>
                            <span id="domainDisplay" style="color: var(--text-secondary);">domain.com</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                        <button type="button" onclick="generatePassword()" class="btn btn-success" style="margin-top: 8px; padding: 4px 8px; font-size: 12px;">Generate</button>
                    </div>
                    <div class="form-group">
                        <label>Quota (MB)</label>
                        <input type="number" name="quota" class="form-control" value="100" min="0" max="5000">
                        <small style="color: var(--text-muted);">Enter 0 for unlimited</small>
                    </div>
                </div>
                
                <button type="submit" name="create_email" class="btn btn-primary">Create Email Account</button>
            </form>
        </div>
    </div>
    
    <script>
    const userDomains = <?= json_encode($user_domains) ?>;
    
    function updateDomains() {
        const userSelect = document.querySelector('select[name="user_id"]');
        const domainSelect = document.querySelector('select[name="domain_id"]');
        const domainDisplay = document.getElementById('domainDisplay');
        
        const userId = userSelect.value;
        domainSelect.innerHTML = '<option value="">Select Domain</option>';
        domainDisplay.textContent = 'domain.com';
        
        if (userId && userDomains[userId]) {
            userDomains[userId].domains.forEach(domain => {
                const option = document.createElement('option');
                option.value = domain.id;
                option.textContent = domain.name;
                domainSelect.appendChild(option);
            });
        }
        
        domainSelect.onchange = function() {
            const selectedOption = this.options[this.selectedIndex];
            domainDisplay.textContent = selectedOption.textContent || 'domain.com';
        };
    }
    
    function generatePassword() {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.querySelector('input[name="password"]').value = password;
    }
    </script>
</body>
</html>