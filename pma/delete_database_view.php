<?php
// Get connection info for breadcrumb
$database = $_GET['database'] ?? '';

// Handle database deletion
if (isset($_POST['delete_database'])) {
    $drop_tables = isset($_POST['drop_tables']);
    
    try {
        if ($drop_tables) {
            // Get all tables in database
            $tables_query = "SHOW TABLES FROM `$database`";
            $tables_result = $conn->query($tables_query);
            
            // Drop each table
            while ($table = $tables_result->fetch_array()) {
                $table_name = $table[0];
                $conn->query("DROP TABLE `$database`.`$table_name`");
            }
        }
        
        // Drop the database
        $conn->query("DROP DATABASE `$database`");
        $success_message = "Database '$database' deleted successfully!";
        
        // Redirect to home after 2 seconds
        echo "<script>setTimeout(function(){ window.location.href='?page=users'; }, 1500);</script>";
        
    } catch (Exception $e) {
        $error_message = "Failed to delete database: " . $e->getMessage();
    }
}
?>

<div class="content-header">
    <h2>Delete Database</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <i class="fas fa-trash"></i> <span class="breadcrumb_text">Delete: <?= $database; ?></span>
    </div>
</div>

<?php if (isset($sucess_message)): ?>
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
            <h5><i class="fas fa-trash"></i> Delete <?= $database; ?></h5>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="drop_tables">
                    <span class="checkbox-custom"></span>
                    Drop all tables from this database?
                </label>
            </div>

            <div class="warning-message">
                <i class="fas fa-exclamation-triangle"></i> <strong>WARNING: This action cannot be undone.</strong>
            </div>
        </div>

        <div class="button-section">
            <button type="submit" name="delete_database" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this database?');">
                <i class="fas fa-trash"></i> Delete Database
            </button>
            <a href="?db=<?= urlencode($database); ?>" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Database
            </a>
        </div>
    </form>
</div>