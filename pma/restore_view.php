<div class="content-header">
    <h2>Restore Database</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-window-restore"></i> Restore Database</span>
    </div>
</div>

<form method="POST" enctype="multipart/form-data">
    <div class="info-box">
        <h4>Select Backup File</h4>
        <div class="file-input-wrapper">
            <input type="file" id="restore_file" name="restore_file"accept=".sql" required>
            <label for="restore_file" class="file-input-label">
                <i class="fas fa-upload"></i>
                <span>Choose SQL file or drag and drop</span>
            </label>
        </div>
    </div>

    <div class="info-box">
        <h4>Target Database</h4>
        <select name="target_db" required>
            <option value="">Select Database...</option>
            <?php foreach ($databases as $database): ?>
                <option value="<?= $database; ?>"><?= $database; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn" onclick="return confirm('This will override existing data. Continue?')">Restore</button>
</form>

<?php if ($_FILES['backup_file'] ?? ''): ?>
    <?php
    $taret_db = $_POST['target_db'];
    $conn->select_db($taret_db);

    $sql_content = file_get_contents($_FILES['backup_file']['tmp_name']);
    $queries = explode(';', $sql_content);

    $success = 0;
    $errors = 0;
    $error_messages = [];

    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query) && !preg_match('/^--/', $query)) {
            if ($conn->query($query)) {
                $success++;
            } else {
                $errors++;
                $error_messages[] = $conn->error;
            }
        }
    }
    ?>

    <div class="info-box">
        <h4>Restore Results</h4>
        <strong>Database: </strong><?= $taret_db; ?><br />
        <strong>File: </strong><?= $_FILES['backup_file']['name']; ?><br />
        <strong>Successful Queries: </strong><?= $success; ?><br />
        <strong>Failed Queries: </strong><?= $errors; ?><br />
        
        <?php if ($errors > 0): ?>
            <div class="error-message" style="margin-top: 10px;">
                <strong>Errors:</strong><br />
                <?php foreach (array_slice($error_messages, 0, 5) as $error): ?>
                    • <?= $error; ?><br />
                <?php endforeach; ?>
                <?php if (count($error_messages) > 5): ?>
                    <em>... and <?= count($error_messages) - 5; ?> more errors</em>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>