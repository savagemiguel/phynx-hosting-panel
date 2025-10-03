<div class="content-header">
    <h2>Import Database</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-upload"></i> Import Database</span>
    </div>
</div>

<form method="POST" enctype="multipart/form-data">
    <div class="info-box">
        <h4>Select SQL File</h4>
        <div class="file-input-wrapper">
            <input type="file" id="backup_file" name="backup_file" accept=".sql" required>
            <label for="backup_file" class="file-input-label">
                <i class="fas fa-upload"></i>
                <span>Choose SQL file or drag and drop</span>
            </label>
        </div>
    </div>

    <div class="info-box">
        <h4>Target Database</h4>
        <select name="target_db">
            <option value="">Select Database...</option>
            <?php foreach ($databases as $database): ?>
                <option value="<?= htmlspecialchars($database); ?>"><?= htmlspecialchars($database); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn">Import</button>
</form>

<?php if ($_FILES['sql_file'] ?? ''): ?>
    <?php
    $target_db = $_POST['target_db'];
    if ($target_db) {
        $conn->select_db($target_db);
    }

    $sql_content = file_get_contents($_FILES['sql_file']['tmp_name']);
    $queries = explode(';', $sql_content);

    $success = 0;
    $errors = 0;

    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            if ($conn->query($query)) {
                $success++;
            } else {
                $errors++;
            }
        }
    }
    ?>

    <div class="info-box">
        <h4>Import Results</h4>
        <p>Queries: <?= count($queries); ?></p>
        <p>Success: <?= $success; ?></p>
        <p>Errors: <?= $errors; ?></p>
    </div>
<?php endif; ?>