<?php
// Get all available collations
$collations = [];
$result = $conn->query("SHOW COLLATION");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $collations[] = $row['Collation'];
    }
}

if (isset($_POST['create_database'])) {
    $db_name = $_POST['db_name'];
    $collation = $_POST['collation'] ?? 'utf8mb4_general_ci';

    try {
        $sql = "CREATE DATABASE `$db_name` COLLATE $collation";
        $conn->query($sql);

        // If user wants to create a table
        if (isset($_POST['create_table']) && $_POST['table_name']) {
            $table_name = $_POST['table_name'];
            $num_columns = $_POST['num_columns'];

            $success_message = "Database '$db_name' created successfully.";
            echo "<script>setTimeout(function(){ window.location.href='?db=".urlencode($db_name)."&page=create_table&table=".urlencode($table_name)."&columns=".$num_columns."'; }, 2000);</script>";
        } else {
            $success_message = "Database '$db_name' created successfully.";
            echo "<script>setTimeout(function(){ window.location.href='?db=".urlencode($db_name)."'; }, 2000);</script>";
        }

    } catch (Exception $e) {
        $error_message = "ERROR: Could not create database '$db_name': " . $e->getMessage();
    }
}
?>

<div class="content-header">
    <h2>Create Database</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-plus"></i> Create Database</span>
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
    <form method="POST" action="">
        <div class="form-section">
            <h5><i class="fas fa-database"></i> Database Information</h5>

            <div class="form-group">
                <label for="db_name">Database Name:</label>
                <input type="text" name="db_name" id="db_name" required>
            </div>

            <div class="form-group">
                <label for="collation">Collation:</label>
                <select name="collation" id="collation">
                    <option value="">-- Select Collation --</option>
                    <?php foreach ($collations as $collation): ?>
                        <option value="<?= $collation; ?>" <?= $collation === 'utf8mb4_general_ci' ? 'selected' : ''; ?>><?= $collation; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-section">
            <h5><i class="fas fa-table"></i> Create Table (optional)</h5>
            <p>This is an optional step to create a table within the database.</p>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="create_table" id="create_table" onchange="toggleTableOptions()">
                    Create Table
                </label>
            </div>

            <div id="table_options" style="display: none;">
                <div class="form-group">
                    <label for="table_name">Table Name:</label>
                    <input type="text" name="table_name" id="table_name">
                </div>

                <div class="form-group">
                    <label for="num_columns">Number of Columns:</label>
                    <input type="number" name="num_columns" id="num_columns" value="4" min="1" max="50">
                </div>
            </div>
        </div>

        <div class="button-section">
            <button type="submit" name="create_database" class="btn">
                <i class="fas fa-plus"></i> Create Database
            </button>
            <button type="reset" name="cancel_create_database" class="btn">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </form>
</div>