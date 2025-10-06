<?php
// Start the session
session_start();
require_once 'config.php';

if (isset($_POST['server_id'])) {
    $server_id = (int)$_POST['server_id'];
    
    $servers = Config::get('Server');
    if (isset($servers[$server_id])) {
        $server = $servers[$server_id];

        // Update session with server credentials
        $_SESSION['current_server'] = $server_id;
        $_SESSION['db_host'] = $server['host'];
        $_SESSION['db_user'] = $server['user'];
        $_SESSION['db_pass'] = $server['pass'];

        echo 'success';
    } else {
        echo 'error: server not found';
    }
} else {
    echo 'error: no server specified';
}