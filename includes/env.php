<?php
// Simple .env loader and env() helper
// Usage:
//   require_once __DIR__ . '/env.php';
//   load_env(__DIR__ . '/../.env');
//   $dbHost = env('DB_HOST', 'localhost');

if (!function_exists('load_env')) {
    function load_env(string $filepath): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        if (!file_exists($filepath) || !is_readable($filepath)) {
            return;
        }

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Remove optional surrounding quotes and unescape common sequences
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $q = $value[0];
                if (substr($value, -1) === $q) {
                    $value = substr($value, 1, -1);
                }
                $value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);
            }

            // Do not overwrite existing env values
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $default;
        }
        return $val === null ? $default : $val;
    }
}
