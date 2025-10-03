<?php
// CLI cron runner - Ubuntu-friendly
// Usage: php /path/to/cli/run_cron.php

require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function cron_field_matches($field, $value) {
    // Supports: *, number, comma list, ranges (a-b), step (*/n, a-b/n, list/n)
    $parts = explode(',', $field);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '*') {
            return true;
        }
        $step = 1;
        if (strpos($part, '/') !== false) {
            [$base, $stepStr] = explode('/', $part, 2);
            $step = max(1, (int)$stepStr);
        } else {
            $base = $part;
        }
        $matches = false;
        if ($base === '*') {
            $matches = ($value % $step) === 0;
        } elseif (strpos($base, '-') !== false) {
            [$start, $end] = explode('-', $base, 2);
            $start = (int)$start; $end = (int)$end;
            if ($start <= $value && $value <= $end) {
                $matches = (($value - $start) % $step) === 0;
            }
        } else {
            $num = (int)$base;
            $matches = ($value === $num) && (($value % $step) === 0);
        }
        if ($matches) return true;
    }
    return false;
}

function cron_is_due($expr, DateTime $now) {
    // expr: "* * * * *" (min hour day month wday)
    $expr = trim($expr);
    $fields = preg_split('/\s+/', $expr);
    if (count($fields) !== 5) return false;
    [$min, $hour, $day, $mon, $wday] = $fields;

    $m = (int)$now->format('i');
    $h = (int)$now->format('G');
    $D = (int)$now->format('j');
    $M = (int)$now->format('n');
    $W = (int)$now->format('w');

    return cron_field_matches($min, $m)
        && cron_field_matches($hour, $h)
        && cron_field_matches($day, $D)
        && cron_field_matches($mon, $M)
        && cron_field_matches($wday, $W);
}

function within_user_base($path, $username) {
    $base = rtrim(str_replace('\\\\', '/', WEB_ROOT), '/') . '/' . $username;
    $p = str_replace('\\\\', '/', $path);
    return strpos($p, $base) === 0;
}

$now = new DateTime('now');

// Fetch enabled jobs
$sql = "SELECT cj.*, u.username FROM cron_jobs cj JOIN users u ON cj.user_id = u.id WHERE cj.status = 'enabled'";
$res = mysqli_query($conn, $sql);
if (!$res) {
    fwrite(STDERR, "DB error fetching cron jobs: " . mysqli_error($conn) . "\n");
    exit(2);
}

while ($job = mysqli_fetch_assoc($res)) {
    $schedule = $job['schedule'];
    $username = $job['username'];
    $cmd = trim($job['command']);
    $jobId = (int)$job['id'];

    if (!cron_is_due($schedule, $now)) continue;

    // Only allow php scripts within user's WEB_ROOT/{username}
    // Expected format: php /path/to/script.php [args]
    $tokens = preg_split('/\s+/', $cmd);
    if (count($tokens) < 2 || strtolower($tokens[0]) !== 'php') {
        fwrite(STDOUT, "[SKIP] Job #$jobId invalid or not permitted command format\n");
        continue;
    }

    $script = $tokens[1];
    $scriptReal = realpath($script);
    if ($scriptReal === false) {
        fwrite(STDOUT, "[SKIP] Job #$jobId script not found: $script\n");
        continue;
    }

    if (!within_user_base($scriptReal, $username)) {
        fwrite(STDOUT, "[SKIP] Job #$jobId script outside user base\n");
        continue;
    }

    // Rebuild command safely: php "script" [args...]
    $safeCmd = 'php ' . escapeshellarg($scriptReal);
    if (count($tokens) > 2) {
        $args = array_slice($tokens, 2);
        foreach ($args as $a) { $safeCmd .= ' ' . escapeshellarg($a); }
    }

    $output = [];
    $rc = 0;
    exec($safeCmd . ' 2>&1', $output, $rc);
    $outText = implode("\n", $output);

    // Update last_run
    $st = mysqli_prepare($conn, 'UPDATE cron_jobs SET last_run = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($st, 'i', $jobId);
    mysqli_stmt_execute($st);

    // Optionally log to stdout; production could log to a file
    fwrite(STDOUT, "[RUN] Job #$jobId ($schedule) rc=$rc\n");
    if ($outText !== '') fwrite(STDOUT, $outText . "\n");
}

// Finish
exit(0);
