<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/env.php';
$database = oldora_env('DB_NAME');
$host = oldora_env('DB_HOST', 'localhost');
$local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || preg_match('/^(?:localhost|127\.0\.0\.1):[0-9]+$/D', $host);
if ((!$local || $database !== 'oldora_dev') && !in_array('--development-database', $argv, true)) {
    throw new RuntimeException('Automatic bootstrap requires a local oldora_dev database. Use --development-database only for an explicitly selected development database.');
}
require_once dirname(__DIR__) . '/connection.php';
require_once dirname(__DIR__) . '/includes/content.php';
require_once dirname(__DIR__) . '/includes/payment.php';
require_once dirname(__DIR__) . '/includes/youtube_watcher.php';
$con->multi_query(file_get_contents(dirname(__DIR__) . '/database/development.sql'));
do {
    if ($result = $con->store_result()) $result->free();
} while ($con->more_results() && $con->next_result());
oldora_ensure_content_schema($con);
oldora_ensure_payment_schema($con);
oldora_ensure_youtube_watcher_schema($con);
echo "Development schema initialized. No users seeded.\n";
