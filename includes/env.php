<?php

if (!function_exists('oldora_env_all')) {
    function oldora_env_all()
    {
        static $values = null;
        if ($values !== null) {
            return $values;
        }

        $values = [];
        $candidates = [
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__) . '/.env'
        ];

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
                if (is_array($parsed)) {
                    $values = $parsed;
                    break;
                }
            }
        }

        return $values;
    }
}

if (!function_exists('oldora_env')) {
    function oldora_env($key, $default = '')
    {
        $runtime = getenv($key);
        if ($runtime !== false && $runtime !== '') {
            return trim((string) $runtime, "\"'");
        }

        $values = oldora_env_all();
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return trim((string) $values[$key], "\"'");
    }
}

if (!function_exists('oldora_base_url')) {
    function oldora_base_url()
    {
        return rtrim(oldora_env('APP_URL', 'https://oldora.vip'), '/');
    }
}

if (!function_exists('oldora_csrf_token')) {
    function oldora_csrf_token()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('oldora_verify_csrf')) {
    function oldora_verify_csrf($token)
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('oldora_json')) {
    function oldora_json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('oldora_log')) {
    function oldora_log($channel, $message, array $context = [])
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        foreach (['api_key', 'access_token', 'refresh_token', 'sign', 'password', 'secret'] as $secretKey) {
            if (isset($context[$secretKey])) {
                $context[$secretKey] = '[redacted]';
            }
        }

        $safeChannel = preg_replace('/[^a-z0-9_-]/i', '', (string) $channel);
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        @file_put_contents($directory . '/' . $safeChannel . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

