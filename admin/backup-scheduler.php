<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin(true);

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle backup schedule actions
if ($_POST) {
    if (isset($_POST['create_schedule'])) {
        $result = createBackupSchedule($_POST);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup schedule created successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to create backup schedule: ' . htmlspecialchars($result['error']) . '</div>';
        }
    } elseif (isset($_POST['toggle_schedule'])) {
        $scheduleId = (int)$_POST['schedule_id'];
        $result = toggleBackupSchedule($scheduleId);
        
        if ($result['success']) {
            $status = $result['enabled'] ? 'enabled' : 'disabled';
            $message = '<div class="alert alert-success">Backup schedule ' . $status . ' successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to toggle backup schedule.</div>';
        }
    } elseif (isset($_POST['delete_schedule'])) {
        $scheduleId = (int)$_POST['schedule_id'];
        $result = deleteBackupSchedule($scheduleId);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup schedule deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete backup schedule.</div>';
        }
    } elseif (isset($_POST['run_now'])) {
        $scheduleId = (int)$_POST['schedule_id'];
        $result = runBackupScheduleNow($scheduleId);
        
        if ($result['success']) {
            $message = '<div class="alert alert-success">Backup job queued for immediate execution.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to queue backup job: ' . htmlspecialchars($result['error']) . '</div>';
        }
    }
}

// Create backup_schedules table if not exists
function initializeBackupTable() {
    global $conn;
    
    // First create the table with basic structure
    $createTable = "CREATE TABLE IF NOT EXISTS backup_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        backup_type ENUM('files', 'database', 'full', 'config') NOT NULL,
        schedule_cron VARCHAR(100) NOT NULL,
        retention_days INT DEFAULT 30,
        compression BOOLEAN DEFAULT TRUE,
        include_paths TEXT,
        exclude_patterns TEXT,
        enabled BOOLEAN DEFAULT TRUE,
        last_run TIMESTAMP NULL,
        next_run TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    mysqli_query($conn, $createTable);
    
    // Ensure all required columns exist by checking and adding them if missing
    $requiredColumns = [
        'enabled' => 'BOOLEAN DEFAULT TRUE',
        'last_run' => 'TIMESTAMP NULL',
        'next_run' => 'TIMESTAMP NULL',
        'retention_days' => 'INT DEFAULT 30',
        'compression' => 'BOOLEAN DEFAULT TRUE',
        'include_paths' => 'TEXT',
        'exclude_patterns' => 'TEXT'
    ];
    
    foreach ($requiredColumns as $columnName => $columnDef) {
        // Check if column exists
        $checkColumn = "SHOW COLUMNS FROM backup_schedules LIKE '$columnName'";
        $result = mysqli_query($conn, $checkColumn);
        
        if (mysqli_num_rows($result) == 0) {
            // Column doesn't exist, add it
            $alterQuery = "ALTER TABLE backup_schedules ADD COLUMN $columnName $columnDef";
            mysqli_query($conn, $alterQuery);
        }
    }
}

// Create backup schedule
function createBackupSchedule($data) {
    global $conn;
    
    initializeBackupTable();
        
    $stmt = mysqli_prepare($conn, "INSERT INTO backup_schedules 
        (name, backup_type, schedule_cron, retention_days, compression, include_paths, exclude_patterns, enabled, next_run) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $name = $data['schedule_name'];
    $backupType = $data['backup_type'];
    $scheduleCron = buildCronExpression($data);
    $retentionDays = (int)$data['retention_days'];
    $compression = isset($data['compression']) ? 1 : 0;
    $includePaths = isset($data['include_paths']) ? implode("\n", $data['include_paths']) : '';
    $excludePatterns = $data['exclude_patterns'] ?? '';
    $enabled = isset($data['enabled']) ? 1 : 0;
    $nextRun = calculateNextRun($scheduleCron);
    
    mysqli_stmt_bind_param($stmt, "ssssissis", $name, $backupType, $scheduleCron, $retentionDays, $compression, $includePaths, $excludePatterns, $enabled, $nextRun);
    
    if (mysqli_stmt_execute($stmt)) {
        $scheduleId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        // Add to system crontab if enabled
        if ($enabled) {
            addToCrontab($scheduleId, $scheduleCron);
        }
        
        return ['success' => true];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Build cron expression from form data
function buildCronExpression($data) {
    $frequency = $data['frequency'];
    
    switch ($frequency) {
        case 'hourly':
            return '0 * * * *';
        case 'daily':
            $hour = $data['daily_hour'] ?? 2;
            return "0 $hour * * *";
        case 'weekly':
            $day = $data['weekly_day'] ?? 0; // Sunday
            $hour = $data['weekly_hour'] ?? 2;
            return "0 $hour * * $day";
        case 'monthly':
            $day = $data['monthly_day'] ?? 1;
            $hour = $data['monthly_hour'] ?? 2;
            return "0 $hour $day * *";
        case 'custom':
            return $data['custom_cron'] ?? '0 2 * * *';
        default:
            return '0 2 * * *'; // Default: daily at 2 AM
    }
}

// Calculate next run time based on cron expression
function calculateNextRun($cronExpression) {
    // Simple next run calculation - in production you'd use a proper cron parser
    $parts = explode(' ', $cronExpression);
    
    if (count($parts) !== 5) {
        return date('Y-m-d H:i:s', strtotime('+1 day'));
    }
    
    $minute = $parts[0];
    $hour = $parts[1];
    $day = $parts[2];
    $month = $parts[3];
    $weekday = $parts[4];
    
    // Simple daily calculation
    if ($day === '*' && $month === '*' && $weekday === '*') {
        $nextRun = strtotime("today {$hour}:{$minute}");
        if ($nextRun <= time()) {
            $nextRun = strtotime("tomorrow {$hour}:{$minute}");
        }
        return date('Y-m-d H:i:s', $nextRun);
    }
    
    return date('Y-m-d H:i:s', strtotime('+1 day'));
}

// Add backup job to system crontab
function addToCrontab($scheduleId, $cronExpression) {
    $scriptPath = dirname(__DIR__) . '/cli/run_backup.php';
    $cronJob = "$cronExpression /usr/bin/php $scriptPath $scheduleId >> /var/log/backup-scheduler.log 2>&1";
    
    // Add to crontab (in production, implement proper crontab management)
    // exec("(crontab -l 2>/dev/null; echo '$cronJob') | crontab -");
}

// Get all backup schedules
function getBackupSchedules() {
    global $conn;
    
    initializeBackupTable();
    
    $result = mysqli_query($conn, "SELECT * FROM backup_schedules ORDER BY created_at DESC");
    if ($result) {
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }
    return [];
}

// Toggle backup schedule
function toggleBackupSchedule($scheduleId) {
    global $conn;
    
    // Get current status
    $stmt = mysqli_prepare($conn, "SELECT enabled, schedule_cron FROM backup_schedules WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $scheduleId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $schedule = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$schedule) {
        return ['success' => false, 'error' => 'Schedule not found'];
    }
        
    $newStatus = !$schedule['enabled'];
    
    // Update status
    $stmt = mysqli_prepare($conn, "UPDATE backup_schedules SET enabled = ?, next_run = ? WHERE id = ?");
    $nextRun = $newStatus ? calculateNextRun($schedule['schedule_cron']) : null;
    mysqli_stmt_bind_param($stmt, "isi", $newStatus, $nextRun, $scheduleId);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['success' => true, 'enabled' => $newStatus];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Delete backup schedule
function deleteBackupSchedule($scheduleId) {
    global $conn;
    
    $stmt = mysqli_prepare($conn, "DELETE FROM backup_schedules WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $scheduleId);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Run backup schedule immediately
function runBackupScheduleNow($scheduleId) {
    global $conn;
    
    // Get schedule details
    $stmt = mysqli_prepare($conn, "SELECT * FROM backup_schedules WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $scheduleId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $schedule = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$schedule) {
        return ['success' => false, 'error' => 'Schedule not found'];
    }
    
    // Create immediate backup job
    $backupName = $schedule['name'] . '_manual_' . date('Y-m-d_H-i-s');
        
    // Update last run time
    $stmt = mysqli_prepare($conn, "UPDATE backup_schedules SET last_run = NOW(), next_run = ? WHERE id = ?");
    $nextRun = calculateNextRun($schedule['schedule_cron']);
    mysqli_stmt_bind_param($stmt, "si", $nextRun, $scheduleId);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'error' => mysqli_error($conn)];
    }
}

// Get backup schedule statistics
function getScheduleStats() {
    global $conn;
    
    initializeBackupTable();
    
    $stats = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'recent_runs' => 0
    ];
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as total, SUM(enabled) as active FROM backup_schedules");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        
        $stats['total'] = (int)$row['total'];
        $stats['active'] = (int)$row['active'];
        $stats['inactive'] = $stats['total'] - $stats['active'];
    }
    
    // Count recent runs (last 24 hours)
    $result = mysqli_query($conn, "SELECT COUNT(*) as recent FROM backup_schedules WHERE last_run >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $stats['recent_runs'] = (int)$row['recent'];
    }
    
    return $stats;
}

$schedules = getBackupSchedules();
$stats = getScheduleStats();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Scheduler - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-clock"></i> Backup Scheduler</h1>
        
        <?= $message ?>
        
        <!-- Schedule Statistics -->
        <div class="card">
            <h3>Schedule Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-calendar"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total Schedules</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-play"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['active'] ?></div>
                        <div class="stat-label">Active Schedules</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-pause"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['inactive'] ?></div>
                        <div class="stat-label">Inactive Schedules</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $stats['recent_runs'] ?></div>
                        <div class="stat-label">Recent Runs (24h)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create New Schedule -->
        <div class="card">
            <h3>Create New Backup Schedule</h3>
            <form method="POST" class="schedule-form" id="scheduleForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Schedule Name:</label>
                        <input type="text" name="schedule_name" class="form-control" placeholder="e.g., Daily Database Backup" required>
                    </div>
                    <div class="form-group">
                        <label>Backup Type:</label>
                        <select name="backup_type" class="form-control" id="backupType" onchange="updateScheduleOptions()" required>
                            <option value="files">Files Only</option>
                            <option value="database">Database Only</option>
                            <option value="config">Configuration Only</option>
                            <option value="full">Full System Backup</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Retention Period:</label>
                        <select name="retention_days" class="form-control">
                            <option value="7">7 Days</option>
                            <option value="14">14 Days</option>
                            <option value="30" selected>30 Days</option>
                            <option value="60">60 Days</option>
                            <option value="90">90 Days</option>
                            <option value="365">1 Year</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Frequency:</label>
                        <select name="frequency" class="form-control" id="frequency" onchange="updateFrequencyOptions()" required>
                            <option value="hourly">Hourly</option>
                            <option value="daily" selected>Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom">Custom Cron</option>
                        </select>
                    </div>
                    <div class="form-group" id="dailyOptions">
                        <label>Time:</label>
                        <select name="daily_hour" class="form-control">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?= $h ?>" <?= $h === 2 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group" id="weeklyOptions" style="display: none;">
                        <label>Day & Time:</label>
                        <div class="weekly-controls">
                            <select name="weekly_day" class="form-control">
                                <option value="0">Sunday</option>
                                <option value="1">Monday</option>
                                <option value="2">Tuesday</option>
                                <option value="3">Wednesday</option>
                                <option value="4">Thursday</option>
                                <option value="5">Friday</option>
                                <option value="6">Saturday</option>
                            </select>
                            <select name="weekly_hour" class="form-control">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?= $h ?>" <?= $h === 2 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="monthlyOptions" style="display: none;">
                        <label>Day & Time:</label>
                        <div class="monthly-controls">
                            <select name="monthly_day" class="form-control">
                                <?php for ($d = 1; $d <= 28; $d++): ?>
                                    <option value="<?= $d ?>"><?= $d ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="monthly_hour" class="form-control">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?= $h ?>" <?= $h === 2 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="customOptions" style="display: none;">
                        <label>Cron Expression:</label>
                        <input type="text" name="custom_cron" class="form-control" placeholder="0 2 * * *">
                        <small class="form-help">Format: minute hour day month weekday</small>
                    </div>
                </div>
                
                <div id="fileOptions" class="backup-options">
                    <div class="form-group">
                        <label>Include Paths:</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="sched_path_www" value="/var/www" checked>
                                <span class="checkbox-custom"></span>
                                <label for="sched_path_www" class="checkbox-label">
                                    <span class="checkbox-text">Web Files</span>
                                    <span class="checkbox-subtext">/var/www directory</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="sched_path_home" value="/home">
                                <span class="checkbox-custom"></span>
                                <label for="sched_path_home" class="checkbox-label">
                                    <span class="checkbox-text">User Home Directories</span>
                                    <span class="checkbox-subtext">/home directory</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="sched_path_etc" value="/etc">
                                <span class="checkbox-custom"></span>
                                <label for="sched_path_etc" class="checkbox-label">
                                    <span class="checkbox-text">System Configuration</span>
                                    <span class="checkbox-subtext">/etc directory</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="include_paths[]" id="sched_path_opt" value="/opt">
                                <span class="checkbox-custom"></span>
                                <label for="sched_path_opt" class="checkbox-label">
                                    <span class="checkbox-text">Optional Software</span>
                                    <span class="checkbox-subtext">/opt directory</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Exclude Patterns:</label>
                        <textarea name="exclude_patterns" class="form-control" rows="3" placeholder="*.log&#10;*.tmp&#10;cache/*&#10;node_modules/*"></textarea>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="compression" id="compression" checked>
                            <span class="checkbox-custom"></span>
                            <label for="compression" class="checkbox-label">
                                <span class="checkbox-text">Enable compression</span>
                                <span class="checkbox-subtext">Recommended for storage efficiency</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="enabled" id="enabled" checked>
                            <span class="checkbox-custom"></span>
                            <label for="enabled" class="checkbox-label">
                                <span class="checkbox-text">Enable schedule immediately</span>
                                <span class="checkbox-subtext">Start running backups on this schedule</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="create_schedule" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Schedule
                    </button>
                    <button type="button" onclick="resetForm()" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing Schedules -->
        <div class="card">
            <h3>Backup Schedules</h3>
            
            <?php if (empty($schedules)): ?>
                <div class="alert alert-info">No backup schedules created yet. Create your first schedule above.</div>
            <?php else: ?>
                <div class="schedule-list">
                    <div class="schedule-header">
                        <div>Schedule Name</div>
                        <div>Type</div>
                        <div>Frequency</div>
                        <div>Last Run</div>
                        <div>Next Run</div>
                        <div>Status</div>
                        <div>Actions</div>
                    </div>
                    <?php foreach ($schedules as $schedule): ?>
                    <div class="schedule-item">
                        <div class="schedule-name">
                            <i class="fas fa-<?= getScheduleIcon($schedule['backup_type']) ?>"></i>
                            <?= htmlspecialchars($schedule['name']) ?>
                            <?php if ($schedule['compression']): ?>
                                <span class="compression-badge">GZIP</span>
                            <?php endif; ?>
                        </div>
                        <div class="schedule-type">
                            <span class="type-badge type-<?= $schedule['backup_type'] ?>">
                                <?= strtoupper($schedule['backup_type']) ?>
                            </span>
                        </div>
                        <div class="schedule-frequency">
                            <?= formatCronExpression($schedule['schedule_cron']) ?>
                        </div>
                        <div class="schedule-last-run">
                            <?= $schedule['last_run'] ? date('M j, H:i', strtotime($schedule['last_run'])) : 'Never' ?>
                        </div>
                        <div class="schedule-next-run">
                            <?= $schedule['next_run'] ? date('M j, H:i', strtotime($schedule['next_run'])) : '-' ?>
                        </div>
                        <div class="schedule-status">
                            <span class="status-badge status-<?= $schedule['enabled'] ? 'active' : 'inactive' ?>">
                                <?= $schedule['enabled'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                        <div class="schedule-actions">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="schedule_id" value="<?= $schedule['id'] ?>">
                                <button type="submit" name="run_now" class="btn btn-sm btn-info" title="Run Now">
                                    <i class="fas fa-play"></i>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="schedule_id" value="<?= $schedule['id'] ?>">
                                <button type="submit" name="toggle_schedule" class="btn btn-sm <?= $schedule['enabled'] ? 'btn-warning' : 'btn-success' ?>" title="<?= $schedule['enabled'] ? 'Disable' : 'Enable' ?>">
                                    <i class="fas fa-<?= $schedule['enabled'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <button onclick="showScheduleDetails(<?= $schedule['id'] ?>)" class="btn btn-sm btn-secondary" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this schedule?')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="schedule_id" value="<?= $schedule['id'] ?>">
                                <button type="submit" name="delete_schedule" class="btn btn-sm btn-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
        }
        
        .stat-icon {
            font-size: 2.5em;
            margin-right: 20px;
            color: var(--primary-color);
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9em;
        }
        
        .schedule-form .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .form-help {
            font-size: 0.8em;
            color: var(--text-muted);
            margin-top: 5px;
        }
        
        .checkbox-label {
            flex-direction: row;
            align-items: center;
            font-weight: normal;
        }
        
        .checkbox-label input {
            margin-right: 8px;
        }
        
        .weekly-controls,
        .monthly-controls {
            display: flex;
            gap: 10px;
        }
        
        .weekly-controls select,
        .monthly-controls select {
            flex: 1;
        }
        
        .backup-options {
            border-top: 1px solid var(--border-color);
            padding-top: 20px;
            margin-top: 20px;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            font-weight: normal;
            margin-bottom: 0;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-start;
        }
        
        .schedule-list {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .schedule-header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 2fr;
            gap: 15px;
            padding: 15px;
            background: var(--section-bg);
            font-weight: bold;
            border-bottom: 1px solid var(--border-color);
        }
        
        .schedule-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 2fr;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            align-items: center;
        }
        
        .schedule-item:last-child {
            border-bottom: none;
        }
        
        .schedule-item:hover {
            background: var(--hover-bg);
        }
        
        .schedule-name {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: bold;
        }
        
        .compression-badge {
            background: #4caf50;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7em;
        }
        
        .type-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7em;
            font-weight: bold;
        }
        
        .type-database { background: #2196f3; color: white; }
        .type-files { background: #ff9800; color: white; }
        .type-full { background: #9c27b0; color: white; }
        .type-config { background: #4caf50; color: white; }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
        }
        
        .status-active {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
        }
        
        .status-inactive {
            background: rgba(158, 158, 158, 0.2);
            color: #9e9e9e;
        }
        
        .schedule-actions {
            display: flex;
            gap: 5px;
        }
        
        .schedule-frequency {
            font-family: monospace;
            font-size: 0.9em;
        }
        
        .schedule-last-run,
        .schedule-next-run {
            font-size: 0.9em;
            color: var(--text-muted);
        }
    </style>

    <script>
        function updateFrequencyOptions() {
            const frequency = document.getElementById('frequency').value;
            
            // Hide all options
            document.getElementById('dailyOptions').style.display = 'none';
            document.getElementById('weeklyOptions').style.display = 'none';
            document.getElementById('monthlyOptions').style.display = 'none';
            document.getElementById('customOptions').style.display = 'none';
            
            // Show relevant options
            switch (frequency) {
                case 'daily':
                    document.getElementById('dailyOptions').style.display = 'block';
                    break;
                case 'weekly':
                    document.getElementById('weeklyOptions').style.display = 'block';
                    break;
                case 'monthly':
                    document.getElementById('monthlyOptions').style.display = 'block';
                    break;
                case 'custom':
                    document.getElementById('customOptions').style.display = 'block';
                    break;
            }
        }
        
        function updateScheduleOptions() {
            const backupType = document.getElementById('backupType').value;
            const fileOptions = document.getElementById('fileOptions');
            
            if (backupType === 'files' || backupType === 'full') {
                fileOptions.style.display = 'block';
            } else {
                fileOptions.style.display = 'none';
            }
        }
        
        function resetForm() {
            document.getElementById('scheduleForm').reset();
            updateFrequencyOptions();
            updateScheduleOptions();
        }
        
        function showScheduleDetails(scheduleId) {
            alert('Schedule details view will be implemented.');
        }
        
        // Initialize form
        updateFrequencyOptions();
        updateScheduleOptions();
    </script>
</body>
</html>

<?php
function getScheduleIcon($type) {
    switch ($type) {
        case 'database': return 'database';
        case 'files': return 'folder';
        case 'full': return 'archive';
        case 'config': return 'cog';
        default: return 'file';
    }
}

function formatCronExpression($cron) {
    $parts = explode(' ', $cron);
    
    if (count($parts) !== 5) {
        return $cron;
    }
    
    $minute = $parts[0];
    $hour = $parts[1];
    $day = $parts[2];
    $month = $parts[3];
    $weekday = $parts[4];
    
    // Common patterns
    if ($cron === '0 * * * *') return 'Hourly';
    if ($day === '*' && $month === '*' && $weekday === '*' && $minute === '0') {
        return "Daily at {$hour}:00";
    }
    if ($day === '*' && $month === '*' && $weekday !== '*' && $minute === '0') {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        return "Weekly on {$days[$weekday]} at {$hour}:00";
    }
    if ($day !== '*' && $month === '*' && $weekday === '*' && $minute === '0') {
        return "Monthly on day {$day} at {$hour}:00";
    }
    
    return $cron;
}
?>