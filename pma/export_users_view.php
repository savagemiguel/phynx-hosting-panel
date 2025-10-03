<?php
// Get connection info for breadcrumb
include 'includes/var.funcs.php';

// Handle export functionality
if (isset($_POST['export_users'])) {
    $format = $_POST['format'] ?? 'sql';
    $include_privileges = isset($_POST['include_privileges']);
    
    try {
        // Get all users
        $users_query = "SELECT User, Host FROM mysql.user ORDER BY User, Host";
        $users_result = $conn->query($users_query);
        
        $filename = "users_export_" . date('Y-m-d_H-i-s');
        $content = "";
        
        if ($format === 'sql') {
            $filename .= ".sql";
            $content = "-- MySQL User Export\n";
            $content .= "-- Generated on " . date('Y-m-d H:i:s') . "\n\n";
            
            while ($user = $users_result->fetch_assoc()) {
                $username = $user['User'];
                $hostname = $user['Host'];
                
                // Get user creation statement
                $show_create = $conn->query("SHOW CREATE USER `$username`@`$hostname`");
                if ($show_create && $row = $show_create->fetch_assoc()) {
                    $content .= $row['CREATE USER'] . ";\n";
                }
                
                if ($include_privileges) {
                    // Get user grants
                    $grants_result = $conn->query("SHOW GRANTS FOR `$username`@`$hostname`");
                    if ($grants_result) {
                        while ($grant = $grants_result->fetch_assoc()) {
                            $grant_statement = array_values($grant)[0];
                            $content .= $grant_statement . ";\n";
                        }
                    }
                }
                $content .= "\n";
            }
        } else {
            $filename .= ".csv";
            $content = "Username,Hostname,SSL Type,Resource Limits\n";
            
            while ($user = $users_result->fetch_assoc()) {
                $username = $user['User'];
                $hostname = $user['Host'];
                
                // Get additional user info
                $user_info = $conn->query("SELECT ssl_type, max_questions, max_updates, max_connections FROM mysql.user WHERE User='$username' AND Host='$hostname'");
                $info = $user_info->fetch_assoc();
                
                $content .= "\"$username\",\"$hostname\",\"" . ($info['ssl_type'] ?: 'NONE') . "\",\"Q:" . $info['max_questions'] . " U:" . $info['max_updates'] . " C:" . $info['max_connections'] . "\"\n";
            }
        }
        
        // Save file
        $filepath = "exports/" . $filename;
        if (!is_dir('exports')) {
            mkdir('exports', 0755, true);
        }
        file_put_contents($filepath, $content);
        
        $success_message = "Users exported successfully! <a href='$filepath' download>Download $filename</a>";
        
    } catch (Exception $e) {
        $error_message = "Export failed: " . $e->getMessage();
    }
}
?>

<div class="content-header">
    <h2>Export Users</h2>
    <div class="breadcrumb">
        <?= $connection_info; ?>
        <i class="fa fa-angle-right"></i>
        <i class="fas fa-download"></i> <span class="breadcrumb_text">Export Users</span>
    </div>
</div>

<?php if (isset($success_message)): ?>
    <div class="success-message">
        <i class="fas fa-check-circle"></i> <?= $success_message; ?>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="error-message">
        <i class="fas fa-exclamation-triangle"></i> <?= $error_message; ?>
    </div>
<?php endif; ?>

<div class="form-box">
    <form method="POST">
        <div class="form-section">
            <h5><i class="fas fa-cog"></i> Export Options</h5>

            <div class="form-grid">
                <div class="form-group">
                    <label for="format" class="format">Export Format:</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="format" value="sql" checked>
                            <span class="radio-custom"></span>
                            SQL Format
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="format" value="csv">
                            <span class="radio-custom"></span>
                            CSV Format
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="include_privileges" class="include_privileges">Include Options:</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="include_privileges" checked>
                            <span class="checkbox-custom"></span>
                            Include user privileges
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="button-section">
            <button type="submit" name="export_users" class="btn">
                <i class="fas fa-download"></i> Export Users
            </button>
            <a href="?page=users" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
    </form>
</div>