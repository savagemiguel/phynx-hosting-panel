<?php
// Get server configuration
require_once __DIR__.'/../../config.php';

// Get selected server from session or use default
$server_id = $_SESSION['server_id'] ?? Config::get('DefaultServer');
$server_config = Config::getServer($server_id);

if (!$server_config) {
    // Fallback to default server
    $server_id = Config::get('DefaultServer');
    $server_config = Config::getServer($server_id);
}

// Connection parameters
$host = $server_config['host'];
$username = $_SESSION['db_user'] ?? $server_config['user'];
$password = $_SESSION['db_pass'] ?? $server_config['pass'];
$port = $server_config['port'];
$db_name = 'mysql'; // Don't connect to a specific database initially

const DB_HOST = 'localhost';
const DB_USERNAME = 'root';
const DB_PASSWORD = '';
const DB_NAME = '';

// Connect to the selected server
$conn = new mysqli($host, $username, $password, null, $port);
if ($conn->connect_error) {
    die("Connection failed: {$conn->connect_error}");
}

// Get all databases (including your custom ones)
$databases = [];
$result = $conn->query("SHOW DATABASES");
if ($result) {
    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        // Optionally filter out system databases if you want
        // if (!in_array($row[0], ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
            $databases[] = $row[0];
        // }
    }
}

$selected_db = $_GET['db'] ?? '';
$selected_table = $_GET['table'] ?? '';
$tables = [];

// Handle database selection
if (!empty($_POST['db'])) {
    $selected_db = $_POST['db'];
    $conn->select_db($selected_db);
} elseif ($selected_db) {
    $conn->select_db($selected_db);
}

// Get tables for the selected database
if ($selected_db) {
    $conn->select_db($selected_db);
    $result = $conn->query("SHOW TABLES");
    if ($result) {
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tables[] = $row[0];
        }
    }
}