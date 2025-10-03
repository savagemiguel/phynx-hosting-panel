<?php

/**
 * Gets current and latest PHP version information.
 * Fetches the latest version from php.net and caches it for 24 hours.
 *
 * @return array An array with 'current', 'latest', and 'outdated' status.
 *               'latest' will be null if the check fails.
 *               'outdated' will be null if 'latest' is null, otherwise boolean.
 */
function getPHPVersionInfo() {
    $current = phpversion();
    $latest = null;
    $outdated = null;

    // Use a simple file-based cache in the system's temp directory to avoid frequent external requests
    $cache_file = sys_get_temp_dir() . '/phynx_admin_php_version.cache';
    $cache_time = 3600 * 24; // Cache for 24 hours

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
        $latest = file_get_contents($cache_file);
    } else {
        // Fetch latest version from php.net JSON feed
        $context = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'PHYNX-Admin-Version-Checker/1.0']]);
        $json = @file_get_contents('https://www.php.net/releases/index.php?json', false, $context);
        if ($json) {
            $releases = json_decode($json, true);
            if (is_array($releases)) {
                $versions = array_keys($releases);
                usort($versions, 'version_compare');
                $latest_major = end($versions);
                if (isset($releases[$latest_major]['version'])) {
                    $latest = $releases[$latest_major]['version'];
                    @file_put_contents($cache_file, $latest);
                }
            }
        }
    }

    if ($latest) {
        $outdated = version_compare($current, $latest, '<');
    }

    return [
        'current' => $current,
        'latest' => $latest,
        'outdated' => $outdated,
        'download_url' => 'https://www.php.net/downloads.php',
    ];
}

/**
 * Gets current and latest MySQL version information.
 *
 * @param mysqli $conn The database connection object.
 * @return array An array with 'current', 'latest', and 'outdated' status.
 */
function getMySQLVersionInfo($conn) {
    $version_string = $conn->server_info;
    preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/', $version_string, $matches);
    $current = $matches[1] ?? $version_string;

    // Fetching the latest MySQL version automatically is complex without a reliable API.
    // For stability, this uses a known recent version. This should be updated periodically.
    $latest = '8.4.0'; // As of Aug 2024.
    $outdated = version_compare($current, $latest, '<');

    return [
        'current' => $current,
        'latest' => $latest,
        'outdated' => $outdated,
        'download_url' => 'https://dev.mysql.com/downloads/mysql/',
    ];
}

include_once 'config.php';
// Calculate total tables and rows across ALL databases
$total_tables = 0;
$total_rows = 0;

foreach ($databases as $db) {
    $result = $conn->query("
        SELECT COUNT(*) as table_count, 
               COALESCE(SUM(TABLE_ROWS), 0) as row_count
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = '$db'
    ");

    if ($result) {
        $stats = $result->fetch_assoc();
        $total_tables += $stats['table_count'];
        $total_rows += $stats['row_count'];
    }
}

// Get collation and encoding information
$collation_result = $conn->query("SELECT @@collation_connection as collation");
$collation = $collation_result ? $collation_result->fetch_assoc()['collation'] : 'Unknown';

$charset_result = $conn->query("SELECT @@character_set_client as client_charset, @@character_set_server as server_charset");
$charset_info = $charset_result ? $charset_result->fetch_assoc() : ['client_charset' => 'Unknown', 'server_charset' => 'Unknown'];

// Get current user
$current_user = $conn->query("SELECT CURRENT_USER()")->fetch_assoc();

// Parse 'user'@'host' format
[$user, $host] = explode('@', $current_user['CURRENT_USER()']);
$display_user = "$user@$host";
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
    </head>
    <body>
<div class="content-header stats-header">
    <div class="header-left">
        <h2>Welcome to PHYNX Admin</h2>
        <?php echo functions::generateBreadcrumbs(); ?>
    </div>
    <div class="header-stats">
        <div class="mini-stat-card">
            <i class="fas fa-microchip"></i>
            <div class="mini-stat-details">
                <div class="mini-stat-value" id="cpu-usage">--</div>
                <div class="mini-stat-label">CPU Usage</div>
            </div>
        </div>
        <div class="mini-stat-card">
            <i class="fas fa-memory"></i>
            <div class="mini-stat-details">
                <div class="mini-stat-value" id="ram-usage">--</div>
                <div class="mini-stat-label">RAM Usage</div>
            </div>
        </div>
        <div class="mini-stat-card">
            <i class="fas fa-hdd"></i>
            <div class="mini-stat-details">
                <div class="mini-stat-value" id="disk-usage">--</div>
                <div class="mini-stat-label">Disk Usage</div>
            </div>
        </div>
        <div class="mini-stat-card">
            <i class="fas fa-clock"></i>
            <div class="mini-stat-details">
                <div class="mini-stat-value" id="uptime">--</div>
                <div class="mini-stat-label">Uptime</div>
            </div>
        </div>
    </div>
</div>

<div class="server-info">
    <h4><i class="fas fa-server"></i>Server Information</h4>
    <div class="server-grid">
        <div class="server-item">
            <i class="fas fa-network-wired"></i>
            <div class="server-details">
                <div class="server-label">Connection:</div>
                <div class="server-value"><?php echo functions::getServerInfo($conn)['server_status']; ?></div>
            </div>
        </div>

        <div class="server-item">
            <i class="fas fa-database"></i>
            <div class="server-details">
                <div class="server-label">Server Type:</div>
                <div class="server-value"><?php echo functions::getServerInfo($conn)['server_type']; ?></div>
            </div>
        </div>

        <div class="server-item">
            <i class="fas fa-shield-alt"></i>
            <div class="server-details">
                <div class="server-label">SSL Status:</div>
                <div class="server-value"><?php echo functions::getServerInfo($conn)['ssl_enabled']; ?></div>
            </div>
        </div>

        <div class="server-item">
            <i class="fas fa-tag"></i>
            <div class="server-details">
                <div class="server-label">MySQL Version:</div>
                <div class="server-value"><?php echo functions::getServerInfo($conn)['server_version']; ?></div>
            </div>
        </div>

        <div class="server-item">
            <i class="fab fa-php"></i>
            <div class="server-details">
                <div class="server-label">PHP Version:</div>
                <div class="server-value"><?= phpversion(); ?></div>
            </div>
        </div>

        <div class="server-item">
            <i class="fas fa-code"></i>
            <div class="server-details">
                <div class="server-label">Protocol Version:</div>
                <div class="server-value"><?php echo functions::getServerInfo($conn)['protocol_version']; ?></div>
            </div>
        </div>

        <div class="server-item">
            <i class="fas fa-user"></i>
            <div class="server-details">
                <div class="server-label">Current User:</div>
                <div class="server-value"><?= $display_user; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="stats">
    <div class="stat-card">
        <div class="stat-number"><?= count($databases) ?></div>
        <div class="stat-label">Databases</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $total_tables ?></div>
        <div class="stat-label">Tables</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= number_format($total_rows) ?></div>
        <div class="stat-label">Rows</div>
    </div>
</div>

<div class="settings-updates-container">
    <div class="settings-section">
        <h3><i class="fas fa-cogs"></i>General Settings</h3>
        <table class="settings-table">
            <thead>
                <tr>
                    <th>Setting</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>MySQL Connection Collation</td>
                    <td><?= $collation; ?></td>
                </tr>
                <tr>
                    <td>MySQL Connection Character Set</td>
                    <td><?= $conn->character_set_name(); ?></td>
                </tr>
            
                <tr>
                    <td>MySQL Connection Server Version</td>
                    <td><?php echo functions::getServerInfo($conn)['server_version']; ?></td>
                </tr>
                <tr>
                    <td>MySQL Connection Protocol Version</td>
                    <td><?php echo functions::getServerInfo($conn)['protocol_version']; ?></td>
                </tr>
                <tr>
                    <td>MySQL Connection Status</td>
                    <td><?php echo functions::getServerInfo($conn)['server_status']; ?></td>
                </tr>
                <tr>
                    <td>PHP Extensions</td>
                    <td>
                        <?php
                        $extensions = get_loaded_extensions();
                        $extension_list = implode(', ', $extensions);
                        echo $extension_list;
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Memory Limit</td>
                    <td><?= ini_get('memory_limit'); ?></td>
                </tr>
                <tr>
                    <td>Max Execution Time</td>
                    <td><?= ini_get('max_execution_time'); ?>s</td>
                </tr>
                <tr>
                    <td>Upload Max Filesize</td>
                    <td><?= ini_get('upload_max_filesize'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="update-section">
        <h3><i class="fas fa-sync-alt"></i> System Updates</h3>
        <?php
        // Version update database logic
        $current_version = '';
        $config = new Config();
        $config = $config->get();
        $version_conn = new mysqli($config['Server'][1]['host'], $config['Server'][1]['user'], $config['Server'][1]['pass'], 'version_check');

        // Get all variables for array
        $version_sql = $version_conn->query("SELECT * FROM versions");
        $version_data = $version_sql->fetch_assoc();
        $version = [
            'id' => $version_data['id'],
            'product_name' => $version_data['product_name'],
            'product_key' => $version_data['product_key'],
            'latest_version' => $version_data['latest_version'],
            'download_url' => $version_data['download_url'],
            'filename' => $version_data['filename'],
            'release_notes' => $version_data['release_notes'],
            'release_date' => $version_data['release_date'],
            'is_active' => $version_data['is_active'],
            'created_at' => $version_data['created_at'],
            'updated_at' => $version_data['updated_at'],
            'update_available' => version_compare($version_data['latest_version'], $version_data['current_version'], '>') ? true : false,
            'current_version' => $version_data['current_version'],
            'is_latest' => version_compare($version_data['current_version'], $version_data['latest_version'], '=') ? true : false,
        ];
        
        // Display update info
        if ($version['update_available']) {
            echo '
            <div class="update-available">
                <div class="update-header">
                    <i class="fas fa-download"></i>
                    <span>Update Available!</span>
                </div>
                <div class="update-details">
                    <p><strong>Current:</strong> v' . $version['current_version'] . '</p>
                    <p><strong>Latest:</strong> v' . $version['latest_version'] . '</p>';
            
            if ($version['download_url']) {
                echo '<a href="' . htmlspecialchars($version['download_url']) . '" class="update-download-btn" target="_blank">
                        <i class="fas fa-download"></i> Download Update
                      </a>';
            }
            
            if ($version['release_notes']) {
                $formatted_notes = str_replace('\\n', "\n", $version['release_notes']);
                $formatted_notes = preg_replace('/^([A-Za-z\s]+:)$/m', '<strong>$1</strong>', $formatted_notes);
                $formatted_notes = preg_replace('/^- (.+)$/m', '• $1', $formatted_notes);
                echo '<div class="update-notes">
                        <h2><u>What\'s New</u>:</h2>
                        <div class="release-content">' . nl2br($formatted_notes) . '</div>
                      </div>';
            }
            
            echo '</div></div>';
        } else if ($version['is_latest'] === true){
            echo '
            <div class="update-current">
                <i class="fas fa-check-circle"></i>
                <span>You are running the latest version (v' . $version['latest_version'] . ')</span>
            </div>';
        }
        ?>
        
        <div class="system-components">
            <h3><i class="fas fa-server"></i> System Components</h3>

            <?php
            // Check PHP version
            $php_info = getPHPVersionInfo();
            ?>

            <div class="component-checker">
                <div class="component-info">
                    <i class="fab fa-php" style="color: <?= $php_info['outdated'] ? 'var(--warning-color)' : 'var(--success-color)'; ?>"></i>
                    <div class="version-details">
                        <strong>PHP Version:</strong>
                        <span><?= htmlspecialchars($php_info['current']); ?></span>
                        <?php if ($php_info['latest'] === null): ?>
                            <span class="text-muted">⚠ Unable to check for updates</span>
                        <?php elseif ($php_info['outdated']): ?>
                            <span class="warning">Update available: <?= htmlspecialchars($php_info['latest']); ?></span>
                        <?php else: ?>
                            <span class="success">✓ Up to Date</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($php_info['outdated']): ?>
                    <a href="<?= htmlspecialchars($php_info['download_url']); ?>" target="_blank" class="btn-action download">
                        <i class="fas fa-download"></i> Download
                    </a>
                <?php endif; ?>
            </div>

            <?php
            // Check MySQL version
            $mysql_info = getMySQLVersionInfo($conn);
            ?>

            <div class="component-checker">
                <div class="component-info">
                    <i class="fas fa-database" style="color: <?= $mysql_info['outdated'] ? 'var(--warning-color)' : 'var(--success-color)'; ?>"></i>
                    <div class="version-details">
                        <strong>MySQL Version:</strong>
                        <span><?= htmlspecialchars($mysql_info['current']); ?></span>
                        <?php if ($mysql_info['outdated']): ?>
                            <span class="warning">Update recommended: <?= htmlspecialchars($mysql_info['latest']); ?></span>
                        <?php else: ?>
                            <span class="success">✓ Up to Date</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($mysql_info['outdated']): ?>
                    <a href="<?= htmlspecialchars($mysql_info['download_url']); ?>" target="_blank" class="btn-action download">
                        <i class="fas fa-download"></i> Download
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="update-manual-notice">
            <i class="fas fa-info-circle"></i>
            <p><strong>Manual Updates Required:</strong> For security and stability, updates to core components like PHP and MySQL must be performed manually. Download the latest version and follow the official documentation for your server environment.</p>
        </div>
    </div>
<div class="info-box">
    <div class="additional-section">
    <h3><i class="fas fa-tools"></i> Quick Actions</h3>
    <div class="operations grid">
        <div class="operations card">
            <h4><i class="fas fa-database"></i> Database Tools</h4>
            <a href="?page=sql" class="btn">
                <i class="fas fa-code"></i> SQL Editor
            </a>
            <a href="?page=search" class="btn">
                <i class="fas fa-search"></i> Global Search
            </a>
            <a href="?page=create_database" class="btn">
                <i class="fas fa-plus"></i> Create Database
            </a>
        </div>
        
        <div class="operations card">
            <h4><i class="fas fa-download"></i> Import/Export</h4>
            <a href="?page=export" class="btn">
                <i class="fas fa-file-export"></i> Export Data
            </a>
            <a href="?page=import" class="btn">
                <i class="fas fa-file-import"></i> Import Data
            </a>
            <a href="?page=backup" class="btn">
                <i class="fas fa-shield-alt"></i> Backup
            </a>
        </div>
        
        <div class="operations card">
            <h4><i class="fas fa-users"></i> Administration</h4>
            <a href="?page=users" class="btn">
                <i class="fas fa-user-cog"></i> Manage Users
            </a>
            <a href="?page=config" class="btn">
                <i class="fas fa-cogs"></i> Configuration
            </a>
            <a href="?page=logs" class="btn">
                <i class="fas fa-file-alt"></i> View Logs
            </a>
        </div>
    </div>
</div>
</body>
</html>