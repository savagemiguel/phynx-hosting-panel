<?php
session_start();
require_once 'config.php';

$db = $_POST['db'] ?? '';
$tables = [];

if ($db) {
    $current_server = $_SESSION['current_server'] ?? Config::get('DefaultServer');
    $server_config = Config::get('Server')[$current_server];
    $host = $server_config['host'] ?? 'localhost';
    $user = $server_config['user'] ?? $_SESSION['db_user'];
    $pass = $server_config['pass'] ?? $_SESSION['db_pass'];
    $port = $server_config['port'] ?? 3306;

    $conn = new mysqli($host, $user, $pass, $db, $port);
    if (!$conn->connect_error) {
        $result = $conn->query("SHOW TABLES");
        if ($result) {
            while ($row = $result->fetch_array(MYSQLI_NUM)) {
                $tables[] = $row[0];
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode($tables);