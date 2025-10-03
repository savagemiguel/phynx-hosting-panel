<?php
// Docker helper library for CLI-first integration on Windows/Linux
// Requires env() from includes/env.php to be loaded via config.php

if (!function_exists('docker_shesc')) {
    function docker_shesc(string $arg): string {
        // Use double-quotes and escape inner quotes for Windows cmd compatibility
        if ($arg === '') return '""';
        $need = preg_match('/[\s"^&|<>]/', $arg);
        $arg = str_replace('"', '\\"', $arg);
        return $need ? '"' . $arg . '"' : $arg;
    }
}

if (!function_exists('docker_bin')) {
    function docker_bin(): string {
        $bin = env('DOCKER_CLI_PATH', 'docker');
        return $bin;
    }
}

if (!function_exists('docker_compose_subcmd')) {
    function docker_compose_subcmd(): string {
        // Prefer external docker-compose if provided, else use `docker compose`
        $composePath = env('DOCKER_COMPOSE_PATH', '');
        if ($composePath) {
            return $composePath;
        }
        return docker_bin() . ' compose';
    }
}

if (!function_exists('docker_exec_cmd')) {
    function docker_exec_cmd(string $cmd, ?array &$output = null, ?int &$code = null): bool {
        $output = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $output, $code);
        return $code === 0;
    }
}

if (!function_exists('docker_cmd')) {
    function docker_cmd(array $args): array {
        $parts = [docker_shesc(docker_bin())];
        foreach ($args as $a) { $parts[] = docker_shesc((string)$a); }
        $cmd = implode(' ', $parts);
        $out = []; $rc = 0;
        docker_exec_cmd($cmd, $out, $rc);
        return [$rc, $out, $cmd];
    }
}

if (!function_exists('docker_compose_cmd')) {
    function docker_compose_cmd(string $composeFile, array $args, ?string $workdir = null): array {
        $envPrefix = '';
        if (stripos(PHP_OS, 'WIN') === 0) {
            $envPrefix = 'set COMPOSE_CONVERT_WINDOWS_PATHS=1 && ';
        }
        $parts = [$envPrefix . docker_compose_subcmd(), '-f', $composeFile];
        foreach ($args as $a) { $parts[] = $a; }
        $cmd = '';
        if ($workdir && is_dir($workdir)) {
            if (stripos(PHP_OS, 'WIN') === 0) {
                $cmd = 'cd ' . docker_shesc($workdir) . ' && ' . implode(' ', array_map('docker_shesc', $parts));
            } else {
                $cmd = 'cd ' . escapeshellarg($workdir) . ' && ' . implode(' ', array_map('docker_shesc', $parts));
            }
        } else {
            $cmd = implode(' ', array_map('docker_shesc', $parts));
        }
        $out = []; $rc = 0;
        docker_exec_cmd($cmd, $out, $rc);
        return [$rc, $out, $cmd];
    }
}

// High-level helpers
if (!function_exists('docker_info')) {
    function docker_info(): array {
        [$rc, $out] = docker_cmd(['info']);
        return ['ok' => $rc === 0, 'output' => $out];
    }
}

if (!function_exists('docker_ps')) {
    function docker_ps(bool $all = true): array {
        $fmt = '{{json .}}';
        $args = ['ps'];
        if ($all) $args[] = '-a';
        $args[] = '--format'; $args[] = $fmt;
        [$rc, $out, $cmd] = docker_cmd($args);
        $list = [];
        if ($rc === 0) {
            foreach ($out as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $dec = json_decode($line, true);
                    if (is_array($dec)) $list[] = $dec;
                }
            }
        }
        return ['ok' => $rc === 0, 'containers' => $list, 'cmd' => $cmd, 'raw' => $out];
    }
}

if (!function_exists('docker_images')) {
    function docker_images(): array {
        $fmt = '{{json .}}';
        [$rc, $out, $cmd] = docker_cmd(['images', '--format', $fmt]);
        $list = [];
        if ($rc === 0) {
            foreach ($out as $line) { $dec = json_decode($line, true); if (is_array($dec)) $list[] = $dec; }
        }
        return ['ok' => $rc === 0, 'images' => $list, 'cmd' => $cmd, 'raw' => $out];
    }
}

if (!function_exists('docker_inspect')) {
    function docker_inspect(string $name): array {
        [$rc, $out, $cmd] = docker_cmd(['inspect', $name]);
        $data = null;
        if ($rc === 0) {
            $json = implode("\n", $out);
            $dec = json_decode($json, true);
            if (is_array($dec)) $data = $dec;
        }
        return ['ok' => $rc === 0, 'data' => $data, 'cmd' => $cmd, 'raw' => $out];
    }
}

if (!function_exists('docker_stats')) {
    function docker_stats(array $names = [], bool $noStream = true): array {
        $fmt = '{{json .}}';
        $args = ['stats'];
        if ($noStream) $args[] = '--no-stream';
        $args[] = '--format'; $args[] = $fmt;
        foreach ($names as $n) { $args[] = $n; }
        [$rc, $out, $cmd] = docker_cmd($args);
        $list = [];
        if ($rc === 0) {
            foreach ($out as $line) { $dec = json_decode($line, true); if (is_array($dec)) $list[] = $dec; }
        }
        return ['ok' => $rc === 0, 'stats' => $list, 'cmd' => $cmd, 'raw' => $out];
    }
}

if (!function_exists('docker_logs')) {
    function docker_logs(string $name, int $tail = 200): array {
        [$rc, $out, $cmd] = docker_cmd(['logs', '--tail', (string)$tail, $name]);
        return ['ok' => $rc === 0, 'lines' => $out, 'cmd' => $cmd];
    }
}

if (!function_exists('docker_run')) {
    function docker_run(array $opts): array {
        // $opts: image (required), name, ports [[host,cont,proto]], env [k=>v], mounts [[host,cont,ro]], network, cpu, mem
        $image = $opts['image'] ?? '';
        if (!$image) return ['ok' => false, 'error' => 'image required'];
        $args = ['run', '-d'];
        if (!empty($opts['name'])) { $args[] = '--name'; $args[] = $opts['name']; }
        if (!empty($opts['network'])) { $args[] = '--network'; $args[] = $opts['network']; }
        if (!empty($opts['cpu'])) { $args[] = '--cpus'; $args[] = (string)$opts['cpu']; }
        if (!empty($opts['mem'])) { $args[] = '--memory'; $args[] = (string)$opts['mem']; }
        if (!empty($opts['env']) && is_array($opts['env'])) {
            foreach ($opts['env'] as $k => $v) { $args[] = '-e'; $args[] = $k . '=' . $v; }
        }
        if (!empty($opts['ports']) && is_array($opts['ports'])) {
            foreach ($opts['ports'] as $p) {
                // [host, cont, proto]
                $proto = $p[2] ?? 'tcp';
                $args[] = '-p'; $args[] = $p[0] . ':' . $p[1] . '/' . $proto;
            }
        }
        if (!empty($opts['mounts']) && is_array($opts['mounts'])) {
            foreach ($opts['mounts'] as $m) {
                // [host, cont, ro?]
                $spec = $m[0] . ':' . $m[1];
                if (!empty($m[2])) $spec .= ':ro';
                $args[] = '-v'; $args[] = $spec;
            }
        }
        $args[] = $image;
        if (!empty($opts['cmd'])) {
            foreach ((array)$opts['cmd'] as $c) { $args[] = $c; }
        }
        [$rc, $out, $cmd] = docker_cmd($args);
        return ['ok' => $rc === 0, 'output' => $out, 'cmd' => $cmd];
    }
}

if (!function_exists('docker_rm')) {
    function docker_rm(string $name, bool $force = true): array {
        $args = ['rm'];
        if ($force) $args[] = '-f';
        $args[] = $name;
        [$rc, $out, $cmd] = docker_cmd($args);
        return ['ok' => $rc === 0, 'output' => $out, 'cmd' => $cmd];
    }
}

if (!function_exists('docker_compose_up')) {
    function docker_compose_up(string $composeFile, ?string $workdir = null): array {
        return docker_compose_cmd($composeFile, ['up', '-d'], $workdir);
    }
}

if (!function_exists('docker_compose_down')) {
    function docker_compose_down(string $composeFile, ?string $workdir = null): array {
        return docker_compose_cmd($composeFile, ['down'], $workdir);
    }
}

if (!function_exists('docker_compose_ps')) {
    function docker_compose_ps(string $composeFile, ?string $workdir = null): array {
        return docker_compose_cmd($composeFile, ['ps'], $workdir);
    }
}

?>
