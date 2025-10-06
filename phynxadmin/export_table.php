<?php
require_once 'config.php';
require_once 'includes/config/funcs.api.php';

if ($_POST && isset($_POST['export'])) {
    $db = $_POST['db'] ?? '';
    $table = $_POST['table'] ?? '';
    $format = $_POST['format'] ?? 'sql';
    $filename = $_POST['filename'] ?? $table;
    
    if (!$db || !$table) {
        die('Database and table are required');
    }

    // Set content type and filename based on format
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            exportCSV($conn, $db, $table);
            break;
        case 'json':
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            exportJSON($conn, $db, $table);
            break;
        case 'sql':
        default:
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="' . $filename . '.sql"');
            exportSQL($conn, $db, $table, $_POST);
            break;
    }
}

function exportSQL($conn, $db, $table, $options) {
    $conn->select_db($db);
    
    if (isset($options['drop_table'])) {
        echo "DROP TABLE IF EXISTS `$table`;\n\n";
    }
    
    if (isset($options['structure'])) {
        $result = $conn->query("SHOW CREATE TABLE `$table`");
        if ($result && $row = $result->fetch_array()) {
            echo $row[1] . ";\n\n";
        }
    }
    
    if (isset($options['data'])) {
        $result = $conn->query("SELECT * FROM `$table`");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $values = array_map(function($val) use ($conn) {
                    return is_null($val) ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
                }, array_values($row));
                
                echo "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
        }
    }
}

function exportCSV($conn, $db, $table) {
    $conn->select_db($db);
    $result = $conn->query("SELECT * FROM `$table`");
    
    if ($result && $result->num_rows > 0) {
        // Output headers
        $headers = array_keys($result->fetch_assoc());
        echo implode(',', $headers) . "\n";
        
        // Reset result pointer
        $result->data_seek(0);
        
        // Output data
        while ($row = $result->fetch_assoc()) {
            echo implode(',', array_map(function($val) { 
                return '"' . str_replace('"', '""', $val ?? '') . '"'; 
            }, $row)) . "\n";
        }
    }
}

function exportJSON($conn, $db, $table) {
    $conn->select_db($db);
    $result = $conn->query("SELECT * FROM `$table`");
    
    $data = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT);
}