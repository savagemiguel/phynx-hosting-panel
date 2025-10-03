<?php
// Handle export
if ($_POST['export_db'] ?? '') {
    $export_db = $_POST['export_db'];
    $export_type = $_POST['export_type'] ?? 'both';
    $filename = $export_db . '_export_' . date('m-d-Y_h-i-sA') . '.sql';
    $export_path = 'exports/' . $filename;

    // Create exports directory if it doesn't exist
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }

    $conn->select_db($export_db);
    $tables_result = $conn->query("SHOW TABLES");
    $total_tables = $tables_result->num_rows;

    // Start output buffering for progress
    ob_start();
    ?>

    <div class="info-box">
        <h4>Export in Progress...</h4>
        <div class="progress-container">
            <div class="progress-bar" id="progressBar">
                <div class="progress-text" id="progressText">0%</div>
            </div>
        </div>
        <div id="status">Starting export...</div>
    </div>

    <script>
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

    // Create export content
    $export_content = "-- Export of Database: $export_db\n";
    $export_content .= "-- Generated on: " . date('m-d-Y_h-i-sA')."\n";
    $export_content .= "-- Export type: $export_type\n\n";

    $current_table = 0;
    $tables_result->data_seek(0);

    while ($table = $tables_result->fetch_array(MYSQLI_NUM)) {
        $table_name = $table[0];
        $current_table++;

        // Update progress
        echo "<script>updateProgress($current_table, $total_tables, 'Processing table $current_table of $total_tables');</script>";
        ob_flush();
        flush();

        if ($export_type === 'structure' || $export_type === 'both') {
            $create = $conn->query("SHOW CREATE TABLE `$table_name`");
            $create_sql = $create->fetch_assoc();
            $create_key = array_keys($create_sql)[1];
            $export_content .= $create_sql[$create_key] . ";\n\n";
        }

        if ($export_type === 'data' || $export_type === 'both') {
            $data = $conn->query("SELECT * FROM `$table_name`");
            while ($row = $data->fetch_assoc()) {
                $escaped_values = [];
                foreach ($row as $value) {
                    $escaped_values[] = $conn->real_escape_string($value ?? '');
                }
                $export_content .= "INSERT INTO `$table_name` VALUES ('".implode("', '", $escaped_values)."');\n";
            }
            $export_content .= "\n";
        }

        usleep(100000); // Simulate processing time
    }
    
    // Save export file
    file_put_contents($export_path, $export_content);

    echo "<script>updateProgress(100, 100, 'Export completed!');</script>";
    echo "<div class='info-box'>";
    echo "<h4>Export Completed!</h4>";
    echo "<p>File: $filename</p>";
    echo "<p>Type: " . ucfirst($export_type) . "</p>";
    echo "<p>Size: " . number_format(filesize($export_path) / 1024, 2) . " KB</p>";
    echo "<a href='$export_path' class='download-link' download><i class='fas fa-download'></i> Download</a>";
    echo "</div>";
    ob_end_flush();
    exit();
}
?>

<div class="content-header">
    <h2>Export Database</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-database"></i> Export Database</span>
    </div>
</div>

<form method="POST">
    <div class="info-box">
        <h4>Export Database</h4>
        <table class="data-table">
            <tr>
                <td><label>Select Database:</label></td>
                <td>
                    <select name="export_db" required>
                        <option value="">Select Database</option>
                        <?php foreach ($databases as $database): ?>
                            <option value="<?= htmlspecialchars($database); ?>"><?= htmlspecialchars($database); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td><label>Export Type:</label></td>
                <td>
                    <label><input type="radio" name="export_type" value="structure" checked> Structure Only</label><br>
                    <label><input type="radio" name="export_type" value="data"> Data Only</label><br>
                    <label><input type="radio" name="export_type" value="both"> Structure & Data</label>
                </td>
            </tr>
        </table><br>
        <button type="submit" class="btn">
            <i class="fas fa-download"></i> Export Database
        </button>
    </div>
</form>