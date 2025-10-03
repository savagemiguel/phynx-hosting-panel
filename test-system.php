<?php
echo "\n1. Testing getSystemUptime():\n";
try {
    // Debug the PowerShell command
    $output = [];
    exec('powershell "Get-CimInstance -ClassName Win32_OperatingSystem | Select-Object -ExpandProperty LastBootUpTime" 2>nul', $output);
    echo "PowerShell output: " . print_r($output, true) . "\n";
    
    $uptime = getSystemUptime();
    echo "Uptime: " . print_r($uptime, true) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}le test to check if system functions work
require_once 'config.php';
require_once 'includes/functions.php';

echo "Testing System Functions:\n\n";

echo "1. Testing getSystemUptime():\n";
try {
    $uptime = getSystemUptime();
    echo "Uptime: " . print_r($uptime, true) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n2. Testing getSystemStats():\n";
try {
    $stats = getSystemStats();
    echo "Stats: " . print_r($stats, true) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n3. Testing WEB_ROOT:\n";
echo "WEB_ROOT = " . WEB_ROOT . "\n";
echo "WEB_ROOT exists: " . (is_dir(WEB_ROOT) ? 'YES' : 'NO') . "\n";

echo "\n4. Testing formatBytes():\n";
try {
    echo "1024 bytes = " . formatBytes(1024) . "\n";
    echo "1048576 bytes = " . formatBytes(1048576) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>