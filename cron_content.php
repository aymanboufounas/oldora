<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);

require_once __DIR__ . '/includes/env.php';

header('Content-Type: application/json; charset=utf-8');
$configuredSecret = oldora_env('CRON_SECRET');
$providedSecret = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['key'] ?? '');
if (PHP_SAPI !== 'cli' && ($configuredSecret === '' || !hash_equals($configuredSecret, $providedSecret))) {
    oldora_json(['ok' => false, 'message' => 'Unauthorized.'], 401);
}

require_once __DIR__ . '/includes/publishers.php';
require_once __DIR__ . '/includes/youtube_watcher.php';
require_once __DIR__ . '/includes/automation_worker.php';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/connection.php';
    oldora_ensure_content_schema($con);
    oldora_ensure_youtube_watcher_schema($con);
    oldora_json(['ok' => true, 'summary' => oldora_run_content_worker($con)]);
} catch (Throwable $error) {
    oldora_log('automation', 'Content worker failed', ['error' => $error->getMessage()]);
    $response = ['ok' => false, 'message' => 'The automation worker could not finish. Check the server logs and database connection.'];
    if (PHP_SAPI === 'cli') {
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    oldora_json($response, 500);
}
