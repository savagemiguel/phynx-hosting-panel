<?php
// Quick test of live system stats
require_once 'config.php';
require_once 'includes/functions.php';

echo "System Stats Test\n";
echo "================\n\n";

$stats = getSystemStats();
$uptime = getSystemUptime();

echo "Memory: " . formatBytes($stats['memory']['used']) . " / " . formatBytes($stats['memory']['total']) . " (" . $stats['memory']['percent'] . "%)\n";
echo "Disk: " . formatBytes($stats['disk']['used']) . " / " . formatBytes($stats['disk']['total']) . " (" . $stats['disk']['percent'] . "%)\n";
echo "CPU Load: " . $stats['cpu']['load_1min'] . "\n";
echo "Processes: " . $stats['processes'] . "\n";
echo "Uptime: " . $uptime['formatted'] . " (" . $uptime['seconds'] . " seconds)\n";

echo "\n\nJSON format for AJAX:\n";
echo "====================\n";
echo "Uptime: " . json_encode(['uptime' => $uptime['formatted']]) . "\n";
echo "Stats: " . json_encode([
    'memory' => ['percent' => $stats['memory']['percent'], 'detail' => formatBytes($stats['memory']['used']) . '/' . formatBytes($stats['memory']['total'])],
    'disk' => ['percent' => $stats['disk']['percent'], 'detail' => formatBytes($stats['disk']['used']) . '/' . formatBytes($stats['disk']['total'])],
    'cpu' => ['percent' => min(($stats['cpu']['load_1min'] / 4) * 100, 100), 'detail' => $stats['cpu']['load_1min']]
]) . "\n";
?>