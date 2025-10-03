<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Formats bytes into a human-readable string (KB, MB, GB, TB, PB, etc.)
 * @param int $bytes => The number of bytes to format.
 * @param int $precision => The number of decimal places to display.
 * @return string => The formatted string.
 */
function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / (1024 ** $i), $precision) . ' ' . $units[$i];
}


/**
 * Function to get system uptime in a human-readable format
 * @return array => An array with 'text' and 'details'.
 */
function getLinuxUptime() {
    $uptime_data = @file_get_contents('/proc/uptime');
    if ($uptime_data) {
        $uptime_seconds = (float)explode(' ', $uptime_data)[0];
        $days = floor($uptime_seconds / 86400);
        $hours = floor(($uptime_seconds % 86400) / 3600);
        $minutes = floor(($uptime_seconds % 3600) / 60);

        $uptime_str = '';
        if ($days > 0) $uptime_str .= $days . 'd ';
        $uptime_str .= $hours . 'h ';
        $uptime_str .= $minutes . 'm';

        return ['text' => trim($uptime_str), 'details' => 'System has been up for ' . $days . ' days, ' . $hours . ' hours, and ' . $minutes . ' minutes.'];
    }
    return ['text' => 'N/A', 'details' => 'Could not read /proc/uptime. This function is for Linux.'];
}

/**
 * Function to get the total memory usage of the system.
 * @return array An array with 'percentage' and 'details'.
 */
function getLinuxRamUsage() {
    $meminfo_data = @file_get_contents('/proc/meminfo');
    if ($meminfo_data) {
        $meminfo = [];
        foreach (explode("\n", $meminfo_data) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB$/', $line, $matches)) {
                $meminfo[$matches[1]] = (int)$matches[2];
            }
        }

        if (isset($meminfo['MemTotal'], $meminfo['MemAvailable'])) {
            $total = $meminfo['MemTotal'] * 1024; // to bytes
            $available = $meminfo['MemAvailable'] * 1024;
            $used = $total - $available;
            $percentage = $total > 0 ? round(($used / $total) * 100, 1) : 0;

            return ['percentage' => $percentage . '%', 'details' => 'Used: ' . formatBytes($used) . ' / ' . formatBytes($total)];
        }
    }
    return ['percentage' => 'N/A', 'details' => 'Could not parse /proc/meminfo. This function is for Linux.'];
}

/**
 * Gets system CPU usage percentage. Works on Linux (including CentOS).
 * This is a more accurate method that reads /proc/stat. It is better than
 * parsing `top` or using load average for real-time values.
 * @return array An array with 'percentage' and 'details'.
 */
function getLinuxCpuUsage() {
    // Method 1: More accurate, by reading /proc/stat
    $stat_file = '/proc/stat';
    if (@is_readable($stat_file)) {
        try {
            $stat1_str = @file_get_contents($stat_file);
            if (!$stat1_str) throw new Exception();
            
            $stat1 = sscanf(trim(substr($stat1_str, 5)), "%d %d %d %d %d %d %d");
            usleep(200000); // 200ms sleep

            $stat2_str = @file_get_contents($stat_file);
            if (!$stat2_str) throw new Exception();
            
            $stat2 = sscanf(trim(substr($stat2_str, 5)), "%d %d %d %d %d %d %d");

            $prev_total = array_sum($stat1);
            $total = array_sum($stat2);
            $prev_idle = $stat1[3] + $stat1[4];
            $idle = $stat2[3] + $stat2[4];

            $total_diff = $total - $prev_total;
            $idle_diff = $idle - $prev_idle;

            $cpu_usage = 0;
            if ($total_diff > 0) {
                $cpu_usage = 100 * ($total_diff - $idle_diff) / $total_diff;
            }
            
            return ['percentage' => round($cpu_usage, 1) . '%', 'details' => 'Real-time usage from /proc/stat'];
        } catch (Exception $e) {
            // Fallback to method 2 if there's an error
        }
    }

    // Method 2: Fallback to load average (less accurate for real-time)
    if (function_exists('sys_getloadavg')) {
        $cpu_cores = 1;
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo) {
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $cpu_cores = count($matches[0]);
            }
        }
        
        $load = sys_getloadavg(); // 1, 5, 15 min load averages
        $percentage = $cpu_cores > 0 ? round(($load[0] / $cpu_cores) * 100, 1) : 0;

        return ['percentage' => $percentage . '%', 'details' => "Load Avg (1m): " . $load[0] . " | Cores: " . $cpu_cores];
    }
    return ['percentage' => 'N/A', 'details' => 'Could not determine CPU usage.'];
}

/**
 * Function to get disk usage for root partition. Cross-platform.
 * @return array => An array with 'percentage' and 'details'.
 */
function getDiskUsage() {
    $diskTotal = @disk_total_space('/');
    $diskFree = @disk_free_space('/');
    if ($diskTotal > 0 && $diskFree !== false) {
        $diskUsed = $diskTotal - $diskFree;
        $percentage = round(($diskUsed / $diskTotal) * 100, 1);
        return ['percentage' => $percentage . '%', 'details' => 'Used: ' . formatBytes($diskUsed) . ' / ' . formatBytes($diskTotal)];
    }
    return ['percentage' => 'N/A', 'details' => 'Could not determine disk space.'];
}

/**
 * Gets system uptime on Windows by parsing the last boot time.
 * @return array An array with 'text' and 'details'.
 */
function getWindowsUptime() {
    // This function requires the `exec` function to be enabled.
    if (!function_exists('exec')) {
        return ['text' => 'N/A', 'details' => '`exec()` function is disabled. Cannot determine uptime on Windows.'];
    }

    // Use WMIC to get the last boot time. The output is in a specific format.
    // e.g., "LastBootUpTime\r\n20231027083000.123456+060\r\n\r\n"
    $last_boot_time_str = @exec('wmic os get lastbootuptime');
    if (!$last_boot_time_str) {
        return ['text' => 'N/A', 'details' => 'Failed to execute WMIC command. Cannot determine uptime.'];
    }

    // Find the line with the timestamp.
    $timestamp_line = '';
    foreach (explode("\n", $last_boot_time_str) as $line) {
        if (preg_match('/^(\d{14})/', trim($line), $matches)) {
            $timestamp_line = $matches[1];
            break;
        }
    }

    if (empty($timestamp_line)) {
        return ['text' => 'N/A', 'details' => 'Could not parse uptime from WMIC output.'];
    }

    try {
        // Create a DateTime object from the YYYYMMDDHHMMSS format.
        $boot_time = DateTime::createFromFormat('YmdHis', $timestamp_line);
        if ($boot_time === false) {
            throw new Exception('Failed to parse date from WMIC.');
        }

        $now = new DateTime();
        $interval = $now->diff($boot_time);

        $uptime_str = '';
        if ($interval->d > 0) {
            $uptime_str .= $interval->d . 'd ';
        }
        $uptime_str .= $interval->h . 'h ';
        $uptime_str .= $interval->i . 'm';

        return [
            'text' => trim($uptime_str),
            'details' => 'System has been up for ' . $interval->format('%a days, %h hours, and %i minutes.')
        ];
    } catch (Exception $e) {
        return ['text' => 'N/A', 'details' => 'Error calculating uptime: ' . $e->getMessage()];
    }
}

$stats = [];

// Check if on Windows, as some functions are Linux-specific
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows-specific logic is complex and can be slow. We provide simulated data as a fallback.
    $disk = getDiskUsage();
    $uptime = getWindowsUptime();
    $stats = ['cpu_usage' => rand(5, 25) . '%', 'cpu_details' => 'Simulated for Windows OS', 'ram_usage' => rand(30, 60) . '%', 'ram_details' => 'Simulated for Windows OS', 'disk_usage' => $disk['percentage'], 'disk_details' => $disk['details'], 'uptime' => $uptime['text'], 'uptime_details' => $uptime['details']];
} else {
    // For Linux/Unix-like systems (including CentOS)
    $uptime = getLinuxUptime();
    $ram = getLinuxRamUsage();
    $cpu = getLinuxCpuUsage();
    $disk = getDiskUsage();
    $stats = ['cpu_usage' => $cpu['percentage'], 'cpu_details' => $cpu['details'], 'ram_usage' => $ram['percentage'], 'ram_details' => $ram['details'], 'disk_usage' => $disk['percentage'], 'disk_details' => $disk['details'], 'uptime' => $uptime['text'], 'uptime_details' => $uptime['details']];
}

echo json_encode($stats);