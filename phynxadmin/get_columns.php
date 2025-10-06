<?php
$host = 'localhost';
$username = 'root';
$password = '';

$db = isset($_POST['db']) ? trim($_POST['db']) : '';
$table = isset($_POST['table']) ? trim($_POST['table']) : '';
$columns = [];

if ($db !== '' && $table !== '') {
    $conn = @new mysqli($host, $username, $password, $db);
    if ($conn && !$conn->connect_error) {
        $safe_table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW COLUMNS FROM `{$safe_table}`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            $result->free();
        }
        $conn->close();
    }
}

header('Content-Type: application/json');
echo json_encode($columns);