<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin($from_admin = false) {
    if (!isLoggedIn()) {
        $login_url = $from_admin ? '../login.php' : 'login.php';
        header('Location: ' . $login_url);
        exit;
    }
}

function requireAdmin($from_admin = true) {
    requireLogin($from_admin);
    if (!isAdmin()) {
        if ($from_admin) {
            header('Location: ../index.php');
        } else {
            header('Location: index.php');
        }
        exit;
    }
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// CSRF protection helpers
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function createVirtualHost($domain, $document_root) {
    $vhost_config = "\n<VirtualHost *:80>\n";
    $vhost_config .= "    ServerName $domain\n";
    $vhost_config .= "    ServerAlias www.$domain\n";
    $vhost_config .= "    DocumentRoot \"$document_root\"\n";
    $vhost_config .= "    ErrorLog \"logs/$domain-error.log\"\n";
    $vhost_config .= "    CustomLog \"logs/$domain-access.log\" common\n";
    $vhost_config .= "</VirtualHost>\n";
    
    file_put_contents(APACHE_VHOST_PATH, $vhost_config, FILE_APPEND);
    return true;
}

function getUserPackage($conn, $userId) {
    // Allow accidental reversed arguments: getUserPackage($userId, $conn)
    if (is_int($conn) && $userId instanceof mysqli) {
        [$conn, $userId] = [$userId, $conn];
    }

    // Basic validation/cast
    $userId = (int) $userId;
    if (!($conn instanceof mysqli)) {
        return null;
    }

    $query = "SELECT p.* FROM packages p JOIN users u ON p.id = u.package_id WHERE u.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return $result ? mysqli_fetch_assoc($result) : null;
}

function getUserDomainCount($conn, $userId) {
    // Allow accidental reversed arguments: getUserDomainCount($userId, $conn)
    if (is_int($conn) && $userId instanceof mysqli) {
        [$conn, $userId] = [$userId, $conn];
    }

    $userId = (int) $userId;
    if (!($conn instanceof mysqli)) {
        return 0;
    }

    $query = "SELECT COUNT(*) as count FROM domains WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ['count' => 0];
    return (int) $row['count'];
}

function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

function createDirectory($path) {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        return true;
    }
    return false;
}

function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($chars), 0, $length);
}

function createDatabase($dbName, $dbUser, $dbPass, $conn) {
    $query = "CREATE DATABASE `$dbName`";
    if (mysqli_query($conn, $query)) {
        $query = "CREATE USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'";
        mysqli_query($conn, $query);
        $query = "GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'localhost'";
        mysqli_query($conn, $query);
        mysqli_query($conn, "FLUSH PRIVILEGES");
        return true;
    }
    return false;
}

// System monitoring functions with Windows compatibility
function getSystemUptime() {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows implementation using PowerShell
        $output = [];
        exec('powershell "Get-CimInstance -ClassName Win32_OperatingSystem | Select-Object -ExpandProperty LastBootUpTime" 2>nul', $output);
        
        if (!empty($output)) {
            // Find the first non-empty line with the boot time
            foreach ($output as $line) {
                $bootTimeStr = trim($line);
                if (!empty($bootTimeStr)) {
                    // Parse Windows DateTime format
                    $bootTime = strtotime($bootTimeStr);
                    if ($bootTime !== false) {
                        $uptimeSeconds = time() - $bootTime;
                        return [
                            'seconds' => $uptimeSeconds,
                            'formatted' => formatUptime($uptimeSeconds)
                        ];
                    }
                }
            }
        }
        
        // Fallback for Windows
        return ['seconds' => 0, 'formatted' => 'Unknown'];
    } else {
        // Linux implementation
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime) {
            $uptimeSeconds = floatval(explode(' ', trim($uptime))[0]);
            return [
                'seconds' => $uptimeSeconds,
                'formatted' => formatUptime($uptimeSeconds)
            ];
        }
        return ['seconds' => 0, 'formatted' => 'Unknown'];
    }
}

function getSystemStats() {
    $stats = [];
    
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows implementation using PowerShell
        
        // Memory information
        $output = [];
        exec('powershell "Get-CimInstance -ClassName Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | Format-List" 2>nul', $output);
        
        $totalMem = 0;
        $freeMem = 0;
        
        if (!empty($output)) {
            foreach ($output as $line) {
                $line = trim($line);
                if (preg_match('/TotalVisibleMemorySize\s*:\s*(\d+)/', $line, $matches)) {
                    $totalMem = intval($matches[1]) * 1024; // Convert from KB to bytes
                }
                if (preg_match('/FreePhysicalMemory\s*:\s*(\d+)/', $line, $matches)) {
                    $freeMem = intval($matches[1]) * 1024; // Convert from KB to bytes
                }
            }
        }
        
        $usedMem = $totalMem - $freeMem;
        $memUsagePercent = $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 2) : 0;
        
        $stats['memory'] = [
            'total' => $totalMem,
            'used' => $usedMem,
            'free' => $freeMem,
            'percent' => $memUsagePercent
        ];
        
        // CPU usage simulation (Windows doesn't have load average like Linux)
        $output = [];
        exec('powershell "Get-Counter \\"\\Processor(_Total)\\% Processor Time\\" | Select-Object -ExpandProperty CounterSamples | Select-Object -ExpandProperty CookedValue" 2>nul', $output);
        
        $cpuUsage = 0;
        if (!empty($output) && is_numeric($output[0])) {
            $cpuUsage = round(floatval($output[0]), 2);
        }
        
        // Convert CPU usage to load average equivalent (rough approximation)
        $loadAvg = $cpuUsage / 25; // Rough conversion
        
        $stats['cpu'] = [
            'load_1min' => $loadAvg,
            'load_5min' => $loadAvg,
            'load_15min' => $loadAvg
        ];
        
        // Process count
        $output = [];
        exec('powershell "Get-Process | Measure-Object | Select-Object -ExpandProperty Count" 2>nul', $output);
        $procCount = !empty($output) && is_numeric($output[0]) ? intval($output[0]) : 0;
        $stats['processes'] = $procCount;
        
    } else {
        // Linux implementation
        
        // Memory information
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matches);
            $totalMem = isset($matches[1]) ? intval($matches[1]) * 1024 : 0;
            
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $matches);
            $availableMem = isset($matches[1]) ? intval($matches[1]) * 1024 : 0;
            
            $usedMem = $totalMem - $availableMem;
            $memUsagePercent = $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 2) : 0;
            
            $stats['memory'] = [
                'total' => $totalMem,
                'used' => $usedMem,
                'free' => $availableMem,
                'percent' => $memUsagePercent
            ];
        } else {
            $stats['memory'] = ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0];
        }
        
        // CPU load average
        $loadavg = @file_get_contents('/proc/loadavg');
        if ($loadavg) {
            $loads = explode(' ', trim($loadavg));
            $stats['cpu'] = [
                'load_1min' => floatval($loads[0] ?? 0),
                'load_5min' => floatval($loads[1] ?? 0),
                'load_15min' => floatval($loads[2] ?? 0)
            ];
        } else {
            $stats['cpu'] = ['load_1min' => 0, 'load_5min' => 0, 'load_15min' => 0];
        }
        
        // Process count
        $procCount = 0;
        if (is_dir('/proc')) {
            $procs = glob('/proc/[0-9]*', GLOB_ONLYDIR);
            $procCount = count($procs);
        }
        $stats['processes'] = $procCount;
    }
    
    // System uptime (common for both platforms)
    $stats['uptime'] = getSystemUptime();
    
    // Disk usage (works on both platforms)
    $webRoot = rtrim(WEB_ROOT, '/');
    if (is_dir($webRoot)) {
        $totalBytes = disk_total_space($webRoot);
        $freeBytes = disk_free_space($webRoot);
        $usedBytes = $totalBytes - $freeBytes;
        $diskUsagePercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0;
        
        $stats['disk'] = [
            'total' => $totalBytes,
            'used' => $usedBytes,
            'free' => $freeBytes,
            'percent' => $diskUsagePercent
        ];
    } else {
        $stats['disk'] = ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0];
    }
    
    // Network statistics
    $stats['network'] = getAggregatedNetworkStats();
    
    return $stats;
}

function formatUptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = $days . ' day' . ($days != 1 ? 's' : '');
    if ($hours > 0) $parts[] = $hours . ' hour' . ($hours != 1 ? 's' : '');
    if ($minutes > 0) $parts[] = $minutes . ' minute' . ($minutes != 1 ? 's' : '');
    
    return empty($parts) ? 'Less than a minute' : implode(', ', $parts);
}

function getAggregatedNetworkStats() {
    $stats = ['rx_bytes' => 0, 'tx_bytes' => 0, 'rx_packets' => 0, 'tx_packets' => 0];
    
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows implementation using PowerShell
        $output = [];
        exec('powershell "Get-Counter \\"\\Network Interface(*)\\Bytes Received/sec\\",\\"\\Network Interface(*)\\Bytes Sent/sec\\" | Select-Object -ExpandProperty CounterSamples" 2>nul', $output);
        
        // This is a simplified implementation - Windows network stats are more complex
        // For production, you'd want to get cumulative stats rather than per-second rates
        $stats['rx_bytes'] = 0;
        $stats['tx_bytes'] = 0;
        $stats['rx_packets'] = 0;
        $stats['tx_packets'] = 0;
    } else {
        // Linux implementation
        $netdev = @file_get_contents('/proc/net/dev');
        if ($netdev) {
            $lines = explode("\n", $netdev);
            foreach ($lines as $line) {
                if (preg_match('/^\s*([^:]+):\s*(.+)/', $line, $matches)) {
                    $interface = trim($matches[1]);
                    if ($interface === 'lo') continue; // Skip loopback
                    
                    $data = preg_split('/\s+/', trim($matches[2]));
                    if (count($data) >= 9) {
                        $stats['rx_bytes'] += intval($data[0]);
                        $stats['rx_packets'] += intval($data[1]);
                        $stats['tx_bytes'] += intval($data[8]);
                        $stats['tx_packets'] += intval($data[9]);
                    }
                }
            }
        }
    }
    
    return $stats;
}

function getCPUUsage() {
    static $prevIdle = null;
    static $prevTotal = null;
    
    $stat = @file_get_contents('/proc/stat');
    if (!$stat) return 0;
    
    $lines = explode("\n", $stat);
    $cpuLine = $lines[0];
    
    if (preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $cpuLine, $matches)) {
        $user = intval($matches[1]);
        $nice = intval($matches[2]);
        $system = intval($matches[3]);
        $idle = intval($matches[4]);
        
        $total = $user + $nice + $system + $idle;
        
        if ($prevIdle !== null && $prevTotal !== null) {
            $totalDiff = $total - $prevTotal;
            $idleDiff = $idle - $prevIdle;
            
            if ($totalDiff > 0) {
                $usage = 100 - (($idleDiff / $totalDiff) * 100);
                $prevIdle = $idle;
                $prevTotal = $total;
                return round($usage, 2);
            }
        }
        
        $prevIdle = $idle;
        $prevTotal = $total;
    }
    
    return 0;
}

function getServiceStatus($service) {
    $output = [];
    $returnVar = 0;
    
    exec("systemctl is-active $service 2>/dev/null", $output, $returnVar);
    $status = trim(implode('', $output));
    
    return [
        'active' => $status === 'active',
        'status' => $status,
        'name' => $service
    ];
}

function getApacheStatus() {
    return getServiceStatus('apache2');
}

function getMySQLStatus() {
    return getServiceStatus('mysql');
}

function getBindStatus() {
    return getServiceStatus('named');
}

function createDNSZoneFile($domain, $records) {
    $zoneDir = rtrim(DNS_ZONE_PATH, '/');
    if (!is_dir($zoneDir)) {
        mkdir($zoneDir, 0755, true);
    }
    
    $zoneFile = $zoneDir . '/db.' . $domain;
    $serial = date('Ymdhi'); // YYYYMMDDHHMM format
    
    $content = "; Zone file for $domain\n";
    $content .= "; Generated by hosting panel on " . date('Y-m-d H:i:s') . "\n";
    $content .= "\$TTL 86400\n";
    $content .= "@\tIN\tSOA\tns1.$domain.\tadmin.$domain. (\n";
    $content .= "\t\t$serial\t; Serial\n";
    $content .= "\t\t7200\t\t; Refresh\n";
    $content .= "\t\t3600\t\t; Retry\n";
    $content .= "\t\t1209600\t; Expire\n";
    $content .= "\t\t86400 )\t; Minimum\n\n";
    
    // Default NS records
    $content .= "@\tIN\tNS\tns1.$domain.\n";
    $content .= "@\tIN\tNS\tns2.$domain.\n\n";
    
    // Add custom records
    foreach ($records as $record) {
        $name = $record['name'] === '@' ? '@' : $record['name'];
        $ttl = $record['ttl'] ?: 86400;
        $type = strtoupper($record['record_type']);
        $value = $record['value'];
        
        if ($type === 'MX') {
            $priority = $record['priority'] ?: 10;
            $content .= "$name\t$ttl\tIN\tMX\t$priority\t$value\n";
        } else {
            $content .= "$name\t$ttl\tIN\t$type\t$value\n";
        }
    }
    
    file_put_contents($zoneFile, $content);
    
    // Reload BIND if configured
    $reloadCmd = env('BIND_RELOAD_CMD', 'systemctl reload named');
    if ($reloadCmd) {
        exec($reloadCmd . ' 2>&1', $output, $returnVar);
    }
    
    return true;
}

function getUserResourceUsage($conn, $userId) {
    // Get user's web directory size
    $user = getUserById($conn, $userId);
    if (!$user) return ['disk' => 0, 'bandwidth' => 0];
    
    $userDir = rtrim(WEB_ROOT, '/') . '/' . $user['username'];
    $diskUsed = is_dir($userDir) ? getDirSize($userDir) : 0;
    
    // Get bandwidth from logs (simplified - you'd parse Apache logs in production)
    $bandwidthUsed = $user['bandwidth_used'] ?? 0;
    
    return [
        'disk' => $diskUsed,
        'bandwidth' => $bandwidthUsed
    ];
}

function getUserById($conn, $userId) {
    $query = "SELECT * FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_assoc($result);
    }
    return null;
}

function getDirSize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return $size;
}

// Include additional domain-related functions
require_once __DIR__ . '/domain-functions.php';