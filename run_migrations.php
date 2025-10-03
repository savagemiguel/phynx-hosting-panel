<?php
require_once 'config.php';

echo "Running database migrations...\n\n";

// Read and execute the DNS templates migration
$migrationSQL = file_get_contents('migrations/dns_templates_migration.sql');

// Split by semicolon and execute each statement
$statements = explode(';', $migrationSQL);

foreach ($statements as $statement) {
    $statement = trim($statement);
    if (empty($statement)) continue;
    
    echo "Executing: " . substr($statement, 0, 50) . "...\n";
    
    try {
        mysqli_query($conn, $statement);
        echo "✓ Success\n";
    } catch (mysqli_sql_exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false || 
            strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "✓ Already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

echo "Migration complete!\n";

// Verify tables exist
$tables = ['dns_templates', 'system_stats', 'cron_jobs', 'backup_schedules'];
echo "\nVerifying tables:\n";
foreach ($tables as $table) {
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    if (mysqli_num_rows($result) > 0) {
        echo "✓ $table exists\n";
    } else {
        echo "✗ $table missing\n";
    }
}

mysqli_close($conn);
?>