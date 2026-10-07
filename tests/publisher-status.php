<?php
// Provider fixtures and a disposable database; never publishes live content.
$state = 'processing';
$calls = [];
function oldora_http_json($method, $url, array $headers = [], $body = null, $form = false) {
    global $state, $calls;
    $calls[] = $url;
    if (str_contains($url, 'status_code')) return ['status_code' => $state === 'complete' ? 'FINISHED' : 'IN_PROGRESS'];
    if (str_contains($url, 'media_publish')) return ['id' => 'confirmed-ig-post'];
    if (str_contains($url, 'status/fetch')) return ['error' => ['code' => 'ok'], 'data' => ['status' => $state === 'complete' ? 'PUBLISH_COMPLETE' : 'PROCESSING_UPLOAD']];
    throw new RuntimeException('Unexpected fixture request');
}
require_once __DIR__ . '/../includes/publishers.php';
if (oldora_env('DB_NAME') !== 'oldora_dev' || !in_array(oldora_env('DB_HOST'), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Use the local development database only.');
$db = new mysqli(oldora_env('DB_HOST'), oldora_env('DB_USER'), oldora_env('DB_PASS'));
$name = 'oldora_publish_test_' . bin2hex(random_bytes(4));
$db->query("CREATE DATABASE `$name`");
$db->select_db($name);
$count = 0;
function statusCheck(bool $condition, string $message): void { global $count; if (!$condition) throw new RuntimeException($message); $count++; }
try {
    $db->multi_query(file_get_contents(__DIR__ . '/../database/development.sql'));
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    oldora_ensure_content_schema($db);
    $db->query("INSERT INTO users (id,full_name,email,password) VALUES (1,'Owner','owner@example.invalid','disabled'),(2,'Other','other@example.invalid','disabled')");
    $db->query("INSERT INTO user_tokens (id,user_email,platform,access_token,channel_id) VALUES (1,'owner@example.invalid','instagram','fixture','ig-channel'),(2,'owner@example.invalid','tiktok','fixture','tt-channel')");
    foreach (['instagram' => 1, 'tiktok' => 2] as $platform => $tokenId) {
        $job = ['platform' => $platform, 'provider_publish_id' => 'saved-receipt', 'token_id' => $tokenId, 'user_id' => 1];
        $state = 'processing';
        statusCheck(oldora_get_publish_status($db, $job)['status'] === 'submitted', 'Accepted media stays processing');
        $state = 'complete';
        $result = oldora_get_publish_status($db, $job);
        statusCheck($result['status'] === 'published', 'Provider confirms publication');
        if ($platform === 'instagram') statusCheck($result['id'] === 'confirmed-ig-post', 'Final Instagram post ID replaces container ID');
        $job['user_id'] = 2;
        $before = count($calls);
        try { oldora_get_publish_status($db, $job); throw new LogicException('Another owner account was accepted'); }
        catch (RuntimeException $expected) { $count++; }
        statusCheck(count($calls) === $before, 'Ownership checked before provider request');
    }
    echo "$count publisher status checks passed\n";
} finally { $db->query("DROP DATABASE `$name`"); }
