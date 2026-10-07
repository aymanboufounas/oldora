<?php
$mode = 'success';
$privacyOptions = ['SELF_ONLY'];
$calls = [];
function oldora_http_json($method, $url, array $headers = [], $body = null, $form = false) {
    global $mode, $privacyOptions, $calls;
    $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
    if ($method === 'GET' && str_contains($url, 'status_code')) {
        if ($mode === 'read-timeout') throw new RuntimeException('Operation timed out');
        return ['status_code' => match ($mode) {'processing' => 'IN_PROGRESS', 'failed' => 'ERROR', 'already-published' => 'PUBLISHED', default => 'FINISHED'}];
    }
    if (str_contains($url, 'media_publish')) {
        if ($mode === 'timeout') throw new RuntimeException('Operation timed out');
        if ($mode === 'missing') return [];
        return ['id' => 'ig-post'];
    }
    if (str_contains($url, '/media')) return ['id' => 'container'];
    if (str_contains($url, 'creator_info')) return ['data' => ['privacy_level_options' => $privacyOptions]];
    if (str_contains($url, 'video/init')) {
        if ($mode === 'timeout') throw new RuntimeException('Operation timed out');
        return $mode === 'missing' ? ['error' => ['code' => 'ok']] : ['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'tt-post']];
    }
    throw new RuntimeException('Unexpected fixture request');
}
require_once __DIR__ . '/../includes/publishers.php';
$account = ['channel_id' => 'account', 'access_token' => 'fixture', 'expires_at' => null];
$image = ['media_type' => 'image', 'caption' => 'Test', 'asset_url' => 'https://media.example.com/image.jpg'];
$video = ['media_type' => 'video', 'caption' => 'Test', 'prompt' => 'Test', 'asset_url' => 'https://media.example.com/video.mp4'];
if (oldora_publish_instagram($account, $image) !== 'ig-post') throw new RuntimeException('Instagram success');
if (oldora_publish_tiktok(null, $account, $video, 'SELF_ONLY') !== 'tt-post') throw new RuntimeException('TikTok success');
$count = 2;
foreach (['timeout', 'missing'] as $mode) {
    foreach ([fn() => oldora_publish_instagram($account, $image), fn() => oldora_publish_tiktok(null, $account, $video, 'SELF_ONLY')] as $call) {
        try { $call(); throw new LogicException('Uncertain result must not be reported as success'); }
        catch (OldoraPublishUncertainException $expected) { $count++; }
    }
}
foreach (['http://localhost/image.png', 'https://127.0.0.1/video.mp4', 'https://internal/file.png'] as $url) {
    try { oldora_require_public_media_url($url); throw new LogicException('Private media URL accepted'); }
    catch (RuntimeException $expected) { $count++; }
}
$mode = 'processing';
$calls = [];
$receipt = oldora_publish_instagram($account, $video);
if ($receipt !== ['id' => 'container', 'status' => 'submitted'] || count($calls) !== 1) throw new LogicException('Instagram container must be saved immediately');
$count++;
for ($i = 0; $i < 2; $i++) {
    if (oldora_instagram_container_status($account, $receipt['id']) !== ['status' => 'submitted']) throw new LogicException('Processing receipt must be reused');
    $count++;
}
if (count(array_filter($calls, fn($call) => $call['method'] === 'POST')) !== 1) throw new LogicException('Instagram polls resubmitted media');
$count++;
$mode = 'success';
if (oldora_instagram_container_status($account, $receipt['id']) !== ['status' => 'published', 'id' => 'ig-post']) throw new LogicException('Instagram final post ID missing');
$count++;
$mode = 'failed';
if (oldora_instagram_container_status($account, $receipt['id'])['status'] !== 'failed') throw new LogicException('Instagram rejection state');
$count++;
foreach (['already-published', 'timeout', 'missing'] as $mode) {
    try { oldora_instagram_container_status($account, $receipt['id']); throw new LogicException('Ambiguous Instagram publication accepted'); }
    catch (OldoraPublishUncertainException $expected) { $count++; }
}
$mode = 'read-timeout';
try { oldora_instagram_container_status($account, $receipt['id']); throw new LogicException('Failed poll accepted'); }
catch (OldoraPublishUncertainException $unexpected) { throw new LogicException('Read failure must stay retryable'); }
catch (RuntimeException $expected) { $count++; }
$mode = 'success';
foreach ([['PUBLIC_TO_EVERYONE'], []] as $privacyOptions) {
    $calls = [];
    try { oldora_publish_tiktok(null, $account, $video, 'SELF_ONLY'); throw new LogicException('Private request silently changed privacy'); }
    catch (RuntimeException $expected) { $count++; }
    if (count($calls) !== 1) throw new LogicException('Invalid privacy reached posting endpoint');
    $count++;
}
$privacyOptions = ['SELF_ONLY'];
$calls = [];
$video['caption'] = '';
$video['prompt'] = str_repeat('ق', 4000);
oldora_publish_tiktok(null, $account, $video, 'SELF_ONLY');
$payload = $calls[count($calls) - 1]['body'];
if (mb_strlen($payload['post_info']['title'], 'UTF-8') !== 2200 || preg_match('//u', $payload['post_info']['title']) !== 1) throw new LogicException('TikTok title must respect UTF-8 limit');
$count++;
echo "$count publisher contract checks passed\n";
