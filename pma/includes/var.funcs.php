<?php
// Get server information
$server_version = $conn->get_server_info();
$protocol_version = $conn->protocol_version;
$server_status = $conn ? 'Alive' : 'Dead';
$server_type = $conn->get_server_info() ? 'MySQL' : 'Unknown';

// Get connection information
$connection_info = $conn->host_info;
$ssl_status = $conn->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch_assoc()['Value'];
$ssl_enabled = $ssl_status ? 'Enabled' : 'Disabled';