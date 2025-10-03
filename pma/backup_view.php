<?php
// Handle Backup Request
if ($_POST['backup_db'] ?? '') {
    $backup_db = $_POST['backup_db'];
    $filename = $backup_db.'_backup_'.date('m-d-Y_h-i-sA').'.sql';
    $backup_path = 'backups/' . $filename;

    // Create backups directory if it doesn't exist
    if (!is_dir('backups')) {
        mkdir('backups', 0755, true);
    }
    
    // Create a backup file
    $conn->select_db($backup_db);
    $tables_result = $conn->query("SHOW TABLES");
    $total_tables = $tables_result->num_rows;

    // Start output buffering for progress
    ob_start();
    ?>
    <div class="info-box">
        <h4>Creating Backup...</h4>
        <div class="progress-container">
            <div class="progress-bar" id="progressBar">
                <div class="progress-text" id="progressText">0%</div>
            </div>
        </div>
        <div id="status">Starting Backup...</div>
    </div>
    
    <script>
    // Function to update progress
    function updateProgress(current, total, status) {
        const percent = Math.round((current / total) * 100);
        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressText').textContent = percent + '%';
        document.getElementById('status').textContent = status;
    }
    </script>

    <?php
    ob_flush();
    flush();

    // Create backup file
    $backup_content = "-- Backup of Database: $backup_db\n";
    $backup_content .= "-- Generated on: " . date('m-d-Y h:i:sA') . "\n\n";

    $current_table = 0;
    $tables_result->data_seek(0); // Reset the result pointer to the first row

    while ($table = $tables_result->fetch_array(MYSQLI_NUM)) {
        $table_name = $table[0];
        $current_table++;

        // Update progress
        echo "<script>updateProgress($current_table, $total_tables, 'Creating backup for $table_name');</script>";
        ob_flush();
        flush();

        $create = $conn->query("SHOW CREATE TABLE `$table_name`");
        $create_sql = $create->fetch_assoc();
        $create_key = array_keys($create_sql)[1];
        $backup_content .= $create_sql[$create_key] . ";\n\n";

        $data = $conn->query("SELECT * FROM `$table_name`");
        while ($row = $data->fetch_assoc()) {
            $escaped_values = [];
            foreach ($row as $value) {
                $escaped_values[] = $conn->real_escape_string($value ?? '');
            }
            $backup_content .= "INSERT INTO `$table_name` VALUES ('" . implode("', '", $escaped_values) . "');\n";
        }
        $backup_content .= "\n";

        usleep(100000); // Small delay to avoid excessive server load
    }

    // Save backup file
    file_put_contents($backup_path, $backup_content);

    echo "<script>updateProgress(100, 100, 'Backup completed!');</script>";
    echo "<div class='info-box'>";
    echo "<h4>Backup Completed!</h4>";
    echo "<p>File: $filename</p>";
    echo "<p>Size: " . number_format(filesize($backup_path) / 1024, 2) . " KB</p>";
    echo "<a href='$backup_path' class='download-link' download><i class='fas fa-download'></i> Download Backup</a>";
    echo "</div>";

    ob_end_flush();
    exit;
}
?>

<div class="content-header">
    <h2>Database Backup</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fas fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-floppy-disk"></i>
        Backup</span>
    </div>
</div>

<form method="POST">
    <div class="info-box">
        <h4>Select Database to Backup</h4>
        <select name="backup_db" required>
            <option value="">Select Database</option>
            <?php foreach ($databases as $database): ?>
                <option value="<?= $database; ?>"><?= $database; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Create Backup</button>
    </div>
</form>