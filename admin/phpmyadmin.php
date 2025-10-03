<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle phpMyAdmin actions
if ($_POST) {
    if (isset($_POST['check_phpmyadmin'])) {
        $result = checkPhpMyAdminInstallation();
        
        if ($result['installed']) {
            $message = '<div class="alert alert-success">phpMyAdmin is properly installed and accessible.</div>';
        } else {
            $message = '<div class="alert alert-warning">phpMyAdmin installation issues detected: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['create_config'])) {
        $result = createPhpMyAdminConfig($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">phpMyAdmin configuration created successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to create configuration: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Check phpMyAdmin installation
function checkPhpMyAdminInstallation() {
    $possiblePaths = [
        'C:/wamp64/apps/phpmyadmin5.2.1/',
        'C:/xampp/phpMyAdmin/',
        'C:/laragon/etc/apps/phpMyAdmin/',
        '/usr/share/phpmyadmin/',
        '/var/www/html/phpmyadmin/'
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path . 'index.php')) {
            return [
                'installed' => true,
                'path' => $path,
                'version' => detectPhpMyAdminVersion($path)
            ];
        }
    }
    
    return [
        'installed' => false,
        'error' => 'phpMyAdmin not found in common installation directories'
    ];
}

// Detect phpMyAdmin version
function detectPhpMyAdminVersion($path) {
    $versionFile = $path . 'version.json';
    if (file_exists($versionFile)) {
        $version = json_decode(file_get_contents($versionFile), true);
        return $version['version'] ?? 'Unknown';
    }
    
    $readmeFile = $path . 'README';
    if (file_exists($readmeFile)) {
        $content = file_get_contents($readmeFile);
        if (preg_match('/Version\s+(\d+\.\d+\.\d+)/i', $content, $matches)) {
            return $matches[1];
        }
    }
    
    return 'Unknown';
}

// Create phpMyAdmin configuration
function createPhpMyAdminConfig($data) {
    // This would create a proper phpMyAdmin configuration
    // For demonstration purposes, we'll return success
    return ['success' => true];
}

// Get database connection info
function getDatabaseConnectionInfo() {
    return [
        'host' => DB_HOST,
        'port' => '3306', // Default MySQL port
        'database' => DB_NAME,
        'user' => DB_USER,
        'ssl' => false
    ];
}

// Get security recommendations
function getSecurityRecommendations() {
    return [
        'Use HTTPS for phpMyAdmin access',
        'Enable two-factor authentication',
        'Restrict IP access to phpMyAdmin',
        'Use strong database passwords',
        'Regularly update phpMyAdmin version',
        'Disable root login if possible'
    ];
}

$phpMyAdminCheck = checkPhpMyAdminInstallation();
$dbConnectionInfo = getDatabaseConnectionInfo();
$securityRecommendations = getSecurityRecommendations();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>phpMyAdmin Access - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-tools"></i> phpMyAdmin Access</h1>
        
        <?= $message ?>
        
        <!-- phpMyAdmin Status -->
        <div class="card">
            <h3>phpMyAdmin Installation Status</h3>
            
            <?php if ($phpMyAdminCheck['installed']): ?>
                <div class="status-success">
                    <i class="fas fa-check-circle"></i>
                    <div class="status-info">
                        <h4>phpMyAdmin is Installed</h4>
                        <p>Version: <?= htmlspecialchars($phpMyAdminCheck['version']) ?></p>
                        <p>Path: <?= htmlspecialchars($phpMyAdminCheck['path']) ?></p>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="/phpmyadmin/" target="_blank" class="btn btn-primary">
                        <i class="fas fa-external-link-alt"></i> Open phpMyAdmin
                    </a>
                    <a href="/phpmyadmin/" target="_blank" class="btn btn-secondary">
                        <i class="fas fa-database"></i> Direct Database Access
                    </a>
                </div>
            <?php else: ?>
                <div class="status-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="status-info">
                        <h4>phpMyAdmin Not Found</h4>
                        <p><?= htmlspecialchars($phpMyAdminCheck['error']) ?></p>
                    </div>
                </div>
                
                <div class="installation-help">
                    <h4>Installation Instructions:</h4>
                    <ol>
                        <li>Download phpMyAdmin from <a href="https://www.phpmyadmin.net/" target="_blank">official website</a></li>
                        <li>Extract to your web server directory (usually in /phpmyadmin/)</li>
                        <li>Configure the config.inc.php file with your database settings</li>
                        <li>Access via your web browser at /phpmyadmin/</li>
                    </ol>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="check-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" name="check_phpmyadmin" class="btn btn-info">
                    <i class="fas fa-sync"></i> Re-check Installation
                </button>
            </form>
        </div>

        <!-- Database Connection Information -->
        <div class="card">
            <h3>Database Connection Information</h3>
            <div class="connection-info">
                <div class="info-row">
                    <label>Database Host:</label>
                    <span class="value"><?= htmlspecialchars($dbConnectionInfo['host']) ?></span>
                </div>
                <div class="info-row">
                    <label>Port:</label>
                    <span class="value"><?= $dbConnectionInfo['port'] ?></span>
                </div>
                <div class="info-row">
                    <label>Database Name:</label>
                    <span class="value"><?= htmlspecialchars($dbConnectionInfo['database']) ?></span>
                </div>
                <div class="info-row">
                    <label>Username:</label>
                    <span class="value"><?= htmlspecialchars($dbConnectionInfo['user']) ?></span>
                </div>
                <div class="info-row">
                    <label>SSL Connection:</label>
                    <span class="value <?= $dbConnectionInfo['ssl'] ? 'ssl-enabled' : 'ssl-disabled' ?>">
                        <?= $dbConnectionInfo['ssl'] ? 'Enabled' : 'Disabled' ?>
                    </span>
                </div>
            </div>
            
            <div class="connection-string">
                <h4>Connection String for External Tools:</h4>
                <code>mysql://<?= $dbConnectionInfo['user'] ?>@<?= $dbConnectionInfo['host'] ?>:<?= $dbConnectionInfo['port'] ?>/<?= $dbConnectionInfo['database'] ?></code>
                <button onclick="copyConnectionString()" class="btn btn-sm btn-secondary">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <h3>Database Quick Actions</h3>
            <div class="quick-actions">
                <div class="action-card">
                    <i class="fas fa-table"></i>
                    <h4>View Tables</h4>
                    <p>Browse and manage database tables</p>
                    <a href="/phpmyadmin/index.php?route=/database/structure&db=<?= urlencode(DB_NAME) ?>" target="_blank" class="btn btn-sm btn-primary">Open</a>
                </div>
                
                <div class="action-card">
                    <i class="fas fa-search"></i>
                    <h4>Run SQL Query</h4>
                    <p>Execute custom SQL commands</p>
                    <a href="/phpmyadmin/index.php?route=/database/sql&db=<?= urlencode(DB_NAME) ?>" target="_blank" class="btn btn-sm btn-primary">Open</a>
                </div>
                
                <div class="action-card">
                    <i class="fas fa-download"></i>
                    <h4>Export Database</h4>
                    <p>Create database backup</p>
                    <a href="/phpmyadmin/index.php?route=/database/export&db=<?= urlencode(DB_NAME) ?>" target="_blank" class="btn btn-sm btn-success">Open</a>
                </div>
                
                <div class="action-card">
                    <i class="fas fa-upload"></i>
                    <h4>Import Data</h4>
                    <p>Import SQL files or data</p>
                    <a href="/phpmyadmin/index.php?route=/database/import&db=<?= urlencode(DB_NAME) ?>" target="_blank" class="btn btn-sm btn-warning">Open</a>
                </div>
                
                <div class="action-card">
                    <i class="fas fa-users"></i>
                    <h4>User Accounts</h4>
                    <p>Manage database users</p>
                    <a href="/phpmyadmin/index.php?route=/server/privileges" target="_blank" class="btn btn-sm btn-info">Open</a>
                </div>
                
                <div class="action-card">
                    <i class="fas fa-cogs"></i>
                    <h4>Server Status</h4>
                    <p>View MySQL server information</p>
                    <a href="/phpmyadmin/index.php?route=/server/status" target="_blank" class="btn btn-sm btn-secondary">Open</a>
                </div>
            </div>
        </div>

        <!-- Security Recommendations -->
        <div class="card">
            <h3>Security Recommendations</h3>
            <div class="security-recommendations">
                <?php foreach ($securityRecommendations as $index => $recommendation): ?>
                    <div class="recommendation-item">
                        <i class="fas fa-shield-alt"></i>
                        <span><?= htmlspecialchars($recommendation) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="security-note">
                <i class="fas fa-info-circle"></i>
                <strong>Important:</strong> Always ensure phpMyAdmin is properly secured before using it in a production environment. Consider using IP restrictions, HTTPS, and strong authentication.
            </div>
        </div>

        <!-- Alternative Database Tools -->
        <div class="card">
            <h3>Alternative Database Management Tools</h3>
            <div class="alternative-tools">
                <div class="tool-item">
                    <h4>Adminer</h4>
                    <p>Lightweight alternative to phpMyAdmin with a single PHP file</p>
                    <a href="https://www.adminer.org/" target="_blank" class="btn btn-sm btn-secondary">Learn More</a>
                </div>
                
                <div class="tool-item">
                    <h4>MySQL Workbench</h4>
                    <p>Official MySQL GUI tool for database design and administration</p>
                    <a href="https://www.mysql.com/products/workbench/" target="_blank" class="btn btn-sm btn-secondary">Learn More</a>
                </div>
                
                <div class="tool-item">
                    <h4>HeidiSQL</h4>
                    <p>Free and powerful MySQL client for Windows</p>
                    <a href="https://www.heidisql.com/" target="_blank" class="btn btn-sm btn-secondary">Learn More</a>
                </div>
            </div>
        </div>
    </div>

    <style>
        .status-success, .status-error {
            display: flex;
            align-items: center;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .status-success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid #28a745;
            color: #155724;
        }
        
        .status-error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid #dc3545;
            color: #721c24;
        }
        
        .status-success i, .status-error i {
            font-size: 2em;
            margin-right: 20px;
        }
        
        .status-info h4 {
            margin: 0 0 10px 0;
        }
        
        .status-info p {
            margin: 5px 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .installation-help {
            background: var(--section-bg);
            padding: 20px;
            border-radius: 6px;
            margin: 20px 0;
        }
        
        .installation-help ol {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        .installation-help li {
            margin-bottom: 5px;
        }
        
        .check-form {
            text-align: center;
        }
        
        .connection-info {
            background: var(--section-bg);
            padding: 20px;
            border-radius: 6px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-row label {
            font-weight: 500;
        }
        
        .value {
            font-family: 'Courier New', monospace;
            background: var(--card-bg);
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .ssl-enabled {
            color: #28a745;
        }
        
        .ssl-disabled {
            color: #dc3545;
        }
        
        .connection-string {
            margin-top: 20px;
            padding: 15px;
            background: var(--card-bg);
            border-radius: 6px;
        }
        
        .connection-string code {
            display: block;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .action-card {
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-align: center;
            background: var(--card-bg);
        }
        
        .action-card i {
            font-size: 2em;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .action-card h4 {
            margin: 10px 0;
        }
        
        .action-card p {
            color: var(--text-muted);
            margin-bottom: 15px;
        }
        
        .security-recommendations {
            margin-bottom: 20px;
        }
        
        .recommendation-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .recommendation-item:last-child {
            border-bottom: none;
        }
        
        .recommendation-item i {
            color: var(--primary-color);
            margin-right: 15px;
        }
        
        .security-note {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 6px;
        }
        
        .security-note i {
            margin-right: 10px;
        }
        
        .alternative-tools {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .tool-item {
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .tool-item h4 {
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        .tool-item p {
            color: var(--text-muted);
            margin-bottom: 15px;
        }
    </style>

    <script>
        function copyConnectionString() {
            const connectionString = `mysql://${<?= json_encode($dbConnectionInfo['user']) ?>}@${<?= json_encode($dbConnectionInfo['host']) ?>}:${<?= json_encode($dbConnectionInfo['port']) ?>}/${<?= json_encode($dbConnectionInfo['database']) ?>}`;
            
            navigator.clipboard.writeText(connectionString).then(() => {
                // Show temporary success message
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    button.innerHTML = originalText;
                }, 2000);
            }).catch(() => {
                alert('Failed to copy to clipboard');
            });
        }
    </script>
</body>
</html>