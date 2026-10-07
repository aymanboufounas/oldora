<?php

$path = var_export(dirname(__DIR__) . '/cron_content.php', true);
$cases = [
    'function oldora_ensure_content_schema($con) { throw new RuntimeException("Fixture setup failure"); } require ' . $path . ';',
    'function oldora_env_all() { return ["DB_HOST" => "127.0.0.1:1"]; } require ' . $path . ';'
];
foreach ($cases as $code) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not start the CLI fixture.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $response = json_decode($output, true);
    if ($exitCode !== 1 || !is_array($response) || $response['ok'] !== false || str_contains($output, 'Fixture setup failure') || str_contains($output, '127.0.0.1:1') || $errors !== '') {
        throw new RuntimeException('Failed CLI worker must return safe JSON and a nonzero exit code.');
    }
}
echo "2 CLI worker failure contracts passed\n";
