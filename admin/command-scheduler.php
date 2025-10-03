<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle cron job actions
if ($_POST) {
    if (isset($_POST['add_cron'])) {
        $user_id = (int)$_POST['user_id'];
        $command = sanitize($_POST['command']);
        $schedule = sanitize($_POST['schedule']);
        $description = sanitize($_POST['description']);
        
        // Validate cron schedule format
        $cronParts = explode(' ', $schedule);
        if (count($cronParts) !== 5) {
            $message = '<div class="alert alert-error">Invalid cron schedule format. Use: minute hour day month weekday</div>';
        } else {
            // Calculate next run time
            $nextRun = calculateNextRun($schedule);
            
            // Store in database
            $query = "INSERT INTO cron_jobs (user_id, command, schedule, description, next_run, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "issss", $user_id, $command, $schedule, $description, $nextRun);
            
            if (mysqli_stmt_execute($stmt)) {
                $cron_id = mysqli_insert_id($conn);
                
                // Add to system crontab
                addToSystemCrontab($cron_id, $schedule, $command, $user_id);
                
                $message = '<div class="alert alert-success">Cron job added successfully.</div>';
            } else {
                $message = '<div class="alert alert-error">Failed to add cron job.</div>';
            }
        }
    }
    
    if (isset($_POST['toggle_cron'])) {
        $cron_id = (int)$_POST['cron_id'];
        $new_status = $_POST['new_status'];
        
        $query = "UPDATE cron_jobs SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $new_status, $cron_id);
        
        if (mysqli_stmt_execute($stmt)) {
            if ($new_status === 'active') {
                // Add to system crontab
                $job_query = "SELECT * FROM cron_jobs WHERE id = ?";
                $job_stmt = mysqli_prepare($conn, $job_query);
                mysqli_stmt_bind_param($job_stmt, "i", $cron_id);
                mysqli_stmt_execute($job_stmt);
                $job_result = mysqli_stmt_get_result($job_stmt);
                $job = mysqli_fetch_assoc($job_result);
                
                if ($job) {
                    addToSystemCrontab($cron_id, $job['schedule'], $job['command'], $job['user_id']);
                }
            } else {
                // Remove from system crontab
                removeFromSystemCrontab($cron_id);
            }
            
            $message = '<div class="alert alert-success">Cron job ' . ($new_status === 'active' ? 'enabled' : 'disabled') . ' successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Failed to update cron job status.</div>';
        }
    }
    
    if (isset($_POST['delete_cron'])) {
        $cron_id = (int)$_POST['cron_id'];
        
        // Remove from system crontab first
        removeFromSystemCrontab($cron_id);
        
        // Delete from database
        $query = "DELETE FROM cron_jobs WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $cron_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Cron job deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-error">Failed to delete cron job.</div>';
        }
    }
    
    if (isset($_POST['run_now'])) {
        $cron_id = (int)$_POST['cron_id'];
        
        // Get job details
        $job_query = "SELECT cj.*, u.username FROM cron_jobs cj JOIN users u ON cj.user_id = u.id WHERE cj.id = ?";
        $job_stmt = mysqli_prepare($conn, $job_query);
        mysqli_stmt_bind_param($job_stmt, "i", $cron_id);
        mysqli_stmt_execute($job_stmt);
        $job_result = mysqli_stmt_get_result($job_stmt);
        $job = mysqli_fetch_assoc($job_result);
        
        if ($job) {
            // Execute the command
            $output = [];
            $return_code = 0;
            exec("sudo -u {$job['username']} {$job['command']} 2>&1", $output, $return_code);
            
            // Update last run time
            $update_query = "UPDATE cron_jobs SET last_run = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $cron_id);
            mysqli_stmt_execute($update_stmt);
            
            if ($return_code === 0) {
                $message = '<div class="alert alert-success">Cron job executed successfully. Output: ' . implode('<br>', $output) . '</div>';
            } else {
                $message = '<div class="alert alert-error">Cron job failed. Error: ' . implode('<br>', $output) . '</div>';
            }
        }
    }
}

// Helper functions
function calculateNextRun($schedule) {
    // Basic implementation - in production, use a proper cron parser
    return date('Y-m-d H:i:s', strtotime('+1 hour'));
}

function addToSystemCrontab($cron_id, $schedule, $command, $user_id) {
    // Get username
    global $conn;
    $user_query = "SELECT username FROM users WHERE id = ?";
    $user_stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($user_stmt, "i", $user_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user = mysqli_fetch_assoc($user_result);
    
    if ($user) {
        // Create crontab entry
        $cronEntry = "{$schedule} {$command} # HOSTING_PANEL_JOB_{$cron_id}\n";
        
        // Add to user's crontab
        $tempFile = tempnam(sys_get_temp_dir(), 'cron');
        exec("crontab -u {$user['username']} -l 2>/dev/null", $currentCron);
        $currentCron[] = $cronEntry;
        file_put_contents($tempFile, implode("\n", $currentCron));
        exec("crontab -u {$user['username']} {$tempFile}");
        unlink($tempFile);
    }
}

function removeFromSystemCrontab($cron_id) {
    // Get all users and remove the specific job
    global $conn;
    $users_query = "SELECT DISTINCT u.username FROM users u JOIN cron_jobs cj ON u.id = cj.user_id WHERE cj.id = ?";
    $users_stmt = mysqli_prepare($conn, $users_query);
    mysqli_stmt_bind_param($users_stmt, "i", $cron_id);
    mysqli_stmt_execute($users_stmt);
    $users_result = mysqli_stmt_get_result($users_stmt);
    
    while ($user = mysqli_fetch_assoc($users_result)) {
        $tempFile = tempnam(sys_get_temp_dir(), 'cron');
        exec("crontab -u {$user['username']} -l 2>/dev/null", $currentCron);
        
        // Filter out the specific job
        $filteredCron = array_filter($currentCron, function($line) use ($cron_id) {
            return strpos($line, "# HOSTING_PANEL_JOB_{$cron_id}") === false;
        });
        
        file_put_contents($tempFile, implode("\n", $filteredCron));
        exec("crontab -u {$user['username']} {$tempFile}");
        unlink($tempFile);
    }
}

// Get all users for dropdown
$users_query = "SELECT id, username FROM users WHERE role = 'user' ORDER BY username";
$users_result = mysqli_query($conn, $users_query);
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}

// Get all cron jobs
$crons_query = "SELECT cj.*, u.username, u.email FROM cron_jobs cj 
                JOIN users u ON cj.user_id = u.id 
                ORDER BY cj.created_at DESC";
$crons_result = mysqli_query($conn, $crons_query);
$cron_jobs = [];
while ($row = mysqli_fetch_assoc($crons_result)) {
    $cron_jobs[] = $row;
}

// Common cron schedules
$commonSchedules = [
    'Every minute' => '* * * * *',
    'Every 5 minutes' => '*/5 * * * *',
    'Every 15 minutes' => '*/15 * * * *',
    'Every 30 minutes' => '*/30 * * * *',
    'Every hour' => '0 * * * *',
    'Every 2 hours' => '0 */2 * * *',
    'Every 6 hours' => '0 */6 * * *',
    'Every 12 hours' => '0 */12 * * *',
    'Daily at midnight' => '0 0 * * *',
    'Daily at 6 AM' => '0 6 * * *',
    'Weekly (Sunday midnight)' => '0 0 * * 0',
    'Monthly (1st at midnight)' => '0 0 1 * *',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cron Job Scheduler - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-clock"></i> Cron Job Scheduler</h1>
        
        <?= $message ?>
        
        <!-- Cron Overview -->
        <div class="card">
            <h3>Scheduler Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= count($cron_jobs) ?></div>
                    <div class="stat-label">Total Jobs</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($cron_jobs, function($c) { return $c['status'] === 'active'; })) ?></div>
                    <div class="stat-label">Active Jobs</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($cron_jobs, function($c) { return $c['last_run'] !== null && strtotime($c['last_run']) > strtotime('-24 hours'); })) ?></div>
                    <div class="stat-label">Ran Today</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_unique(array_column($cron_jobs, 'user_id'))) ?></div>
                    <div class="stat-label">Users with Jobs</div>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <!-- Add New Cron Job -->
            <div class="card">
                <h3>Add New Cron Job</h3>
                <form method="POST" id="cronForm">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label>User</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">Select User</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" placeholder="Daily backup job" required>
                    </div>
                    <div class="form-group">
                        <label>Command</label>
                        <input type="text" name="command" class="form-control" placeholder="/usr/bin/php /path/to/script.php" required>
                        <small class="help-text">Full path to the command to execute</small>
                    </div>
                    <div class="form-group">
                        <label>Schedule</label>
                        <select class="form-control" onchange="setSchedule(this.value)" style="margin-bottom: 10px;">
                            <option value="">Choose common schedule...</option>
                            <?php foreach ($commonSchedules as $name => $schedule): ?>
                                <option value="<?= $schedule ?>"><?= $name ?> (<?= $schedule ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="schedule" class="form-control" placeholder="* * * * *" required>
                        <small class="help-text">Cron format: minute hour day month weekday</small>
                    </div>
                    <button type="submit" name="add_cron" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Cron Job
                    </button>
                </form>
            </div>

            <!-- Cron Format Helper -->
            <div class="card">
                <h3>Cron Schedule Format</h3>
                <div class="cron-format">
                    <div class="cron-field">
                        <div class="field-name">Minute</div>
                        <div class="field-range">0-59</div>
                        <div class="field-example">0, 15, 30, 45</div>
                    </div>
                    <div class="cron-field">
                        <div class="field-name">Hour</div>
                        <div class="field-range">0-23</div>
                        <div class="field-example">0, 6, 12, 18</div>
                    </div>
                    <div class="cron-field">
                        <div class="field-name">Day</div>
                        <div class="field-range">1-31</div>
                        <div class="field-example">1, 15, 30</div>
                    </div>
                    <div class="cron-field">
                        <div class="field-name">Month</div>
                        <div class="field-range">1-12</div>
                        <div class="field-example">1, 6, 12</div>
                    </div>
                    <div class="cron-field">
                        <div class="field-name">Weekday</div>
                        <div class="field-range">0-7</div>
                        <div class="field-example">0=Sun, 1=Mon</div>
                    </div>
                </div>
                
                <h4>Special Characters</h4>
                <ul class="cron-symbols">
                    <li><code>*</code> - Any value</li>
                    <li><code>,</code> - List separator</li>
                    <li><code>-</code> - Range of values</li>
                    <li><code>/</code> - Step values</li>
                    <li><code>?</code> - No specific value</li>
                </ul>
            </div>
        </div>

        <!-- Cron Jobs List -->
        <div class="card">
            <h3>Scheduled Jobs</h3>
            <?php if (empty($cron_jobs)): ?>
                <div class="alert alert-info">No cron jobs scheduled. Add your first job above.</div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Description</th>
                                <th>Command</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Last Run</th>
                                <th>Next Run</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cron_jobs as $job): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($job['username']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($job['description']) ?></td>
                                <td><code><?= htmlspecialchars(substr($job['command'], 0, 40)) ?>...</code></td>
                                <td><code><?= htmlspecialchars($job['schedule']) ?></code></td>
                                <td>
                                    <span class="status-badge status-<?= $job['status'] ?>">
                                        <?= ucfirst($job['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $job['last_run'] ? date('M j, H:i', strtotime($job['last_run'])) : 'Never' ?>
                                </td>
                                <td>
                                    <?= $job['next_run'] ? date('M j, H:i', strtotime($job['next_run'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <!-- Toggle Status -->
                                        <form method="POST" style="display: inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="cron_id" value="<?= $job['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $job['status'] === 'active' ? 'inactive' : 'active' ?>">
                                            <button type="submit" name="toggle_cron" class="btn btn-sm <?= $job['status'] === 'active' ? 'btn-warning' : 'btn-success' ?>" title="<?= $job['status'] === 'active' ? 'Disable' : 'Enable' ?>">
                                                <i class="fas fa-<?= $job['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                                            </button>
                                        </form>
                                        
                                        <!-- Run Now -->
                                        <form method="POST" style="display: inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="cron_id" value="<?= $job['id'] ?>">
                                            <button type="submit" name="run_now" class="btn btn-info btn-sm" title="Run Now" onclick="return confirm('Run this job now?')">
                                                <i class="fas fa-play-circle"></i>
                                            </button>
                                        </form>
                                        
                                        <!-- Delete -->
                                        <form method="POST" style="display: inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="cron_id" value="<?= $job['id'] ?>">
                                            <button type="submit" name="delete_cron" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Delete this cron job?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .cron-format {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .cron-field {
            text-align: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }
        
        .field-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .field-range {
            color: var(--text-muted);
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        .field-example {
            font-size: 0.8em;
            color: var(--primary-color);
        }
        
        .cron-symbols {
            list-style: none;
            padding: 0;
        }
        
        .cron-symbols li {
            margin: 5px 0;
        }
        
        .cron-symbols code {
            background: var(--code-bg);
            padding: 2px 6px;
            border-radius: 3px;
            margin-right: 10px;
            font-weight: bold;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .help-text {
            font-size: 0.8em;
            color: var(--text-muted);
            margin-top: 4px;
        }
    </style>

    <script>
        function setSchedule(schedule) {
            if (schedule) {
                document.querySelector('input[name="schedule"]').value = schedule;
            }
        }
    </script>
</body>
</html>