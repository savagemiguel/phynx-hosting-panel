<?php
require_once 'config.php';
require_once 'includes/functions.php';

echo "WEB_ROOT: " . WEB_ROOT . "\n";
echo "WEB_ROOT exists: " . (is_dir(WEB_ROOT) ? 'YES' : 'NO') . "\n\n";

// Test uptime function directly
echo "Testing uptime PowerShell command:\n";
$output = [];
exec('powershell "Get-CimInstance -ClassName Win32_OperatingSystem | Select-Object -ExpandProperty LastBootUpTime" 2>nul', $output);
echo "PowerShell raw output:\n";
var_dump($output);

if (!empty($output) && isset($output[0])) {
    $bootTimeStr = trim($output[0]);
    echo "\nBootTime string: '$bootTimeStr'\n";
    $bootTime = strtotime($bootTimeStr);
    echo "Parsed bootTime: $bootTime\n";
    echo "Current time: " . time() . "\n";
    
    if ($bootTime !== false) {
        $uptimeSeconds = time() - $bootTime;
        echo "Uptime seconds: $uptimeSeconds\n";
        echo "Formatted uptime: " . formatUptime($uptimeSeconds) . "\n";
    } else {
        echo "Failed to parse boot time\n";
    }
}

echo "\n\nTesting disk stats:\n";
$stats = getSystemStats();
echo "Disk stats: " . print_r($stats['disk'], true);
?>