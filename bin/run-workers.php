<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$storage = dirname($root) . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0750, true)) throw new RuntimeException('Worker storage is unavailable.');
$lock = fopen($storage . '/worker-supervisor.lock', 'c');
if (!$lock) throw new RuntimeException('Worker lock could not be opened.');
if (!flock($lock, LOCK_EX | LOCK_NB)) { echo "Worker supervisor is already running.\n"; exit; }
$once = in_array('--once', $argv, true);
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
}
do {
    $failed = false;
    foreach (['cron_payments.php', 'cron_content.php'] as $script) {
        echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $script . PHP_EOL;
        $process = proc_open([PHP_BINARY, $root . '/' . $script], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
        if (!is_resource($process)) { $failed = true; echo "Could not start worker.\n"; continue; }
        $exit = proc_close($process);
        echo PHP_EOL;
        if ($exit !== 0) { $failed = true; echo "Worker exited with status $exit; the next pass will retry.\n"; }
    }
    if ($once) exit($failed ? 1 : 0);
    // A single supervisor and each worker's database locks prevent overlap.
    for ($second = 0; $running && $second < 60; $second++) sleep(1);
} while ($running);
flock($lock, LOCK_UN);
fclose($lock);
