<?php
// Start the session
session_start();
include_once 'config.php';

if (isset($_POST['server_id'])) {
    $server_id = (int)$_POST['server_id'];

    if (isset($config['Server'][$server_id])) {
        $server = $config['Server'][$server_id];

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