<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/publishers.php';
require_once __DIR__ . '/includes/youtube_watcher.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['email'])) {
    oldora_json(['ok' => false, 'message' => 'Login required.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    oldora_json(['ok' => false, 'message' => 'POST required.'], 405);
}
if (!oldora_verify_csrf($_POST['csrf_token'] ?? '')) {
    oldora_json(['ok' => false, 'message' => 'Your session expired. Refresh the page.'], 419);
}

oldora_ensure_youtube_watcher_schema($con);

$userStmt = $con->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $_SESSION['email']);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!$user) {
    oldora_json(['ok' => false, 'message' => 'User not found.'], 404);
}

$userId = (int) $user['id'];
$action = strtolower(trim((string) ($_POST['action'] ?? 'save')));
$watcherId = (int) ($_POST['watcher_id'] ?? 0);

if ($action === 'toggle') {
    $active = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
    $stmt = $con->prepare('UPDATE youtube_watchers SET is_active = ?, last_error = NULL WHERE id = ? AND user_id = ?');
    $stmt->bind_param('iii', $active, $watcherId, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    oldora_json(['ok' => $changed > 0, 'message' => $changed > 0 ? ($active ? 'Channel watcher activated.' : 'Channel watcher paused.') : 'Watcher not found.'], $changed > 0 ? 200 : 404);
}

if ($action === 'delete') {
    $stmt = $con->prepare('DELETE FROM youtube_watchers WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $watcherId, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    oldora_json(['ok' => $changed > 0, 'message' => $changed > 0 ? 'Channel watcher deleted.' : 'Watcher not found.'], $changed > 0 ? 200 : 404);
}

$channelInput = trim((string) ($_POST['channel_input'] ?? ''));
$destinationTokenId = (int) ($_POST['destination_token_id'] ?? 0);
$promptTemplate = trim((string) ($_POST['prompt_template'] ?? ''));
$voiceName = trim((string) ($_POST['voice_name'] ?? ''));
$timezone = trim((string) ($_POST['timezone'] ?? 'UTC'));
$privacy = strtolower(trim((string) ($_POST['privacy_level'] ?? 'public')));
$timesRaw = trim((string) ($_POST['best_times'] ?? '12:30, 18:30, 21:00'));

if ($channelInput === '') {
    oldora_json(['ok' => false, 'message' => 'Enter the YouTube channel URL or @handle.'], 422);
}
if ($destinationTokenId < 1) {
    oldora_json(['ok' => false, 'message' => 'Select the YouTube account that will publish the Shorts.'], 422);
}
if (strlen($promptTemplate) > 2000) {
    oldora_json(['ok' => false, 'message' => 'Automation prompt is too long.'], 422);
}
try {
    new DateTimeZone($timezone);
} catch (Throwable $ignored) {
    oldora_json(['ok' => false, 'message' => 'Invalid timezone.'], 422);
}
if (!in_array($privacy, ['private', 'unlisted', 'public'], true)) {
    $privacy = 'public';
}

$times = [];
foreach (preg_split('/[\s,;]+/', $timesRaw) as $time) {
    $time = trim($time);
    if ($time !== '' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $times[] = $time;
    }
}
$times = array_values(array_unique($times));
if (!$times) {
    oldora_json(['ok' => false, 'message' => 'Add at least one publishing time such as 18:30.'], 422);
}

$tokenStmt = $con->prepare("SELECT * FROM user_tokens WHERE id = ? AND user_email = ? AND LOWER(platform) = 'youtube' LIMIT 1");
$tokenStmt->bind_param('is', $destinationTokenId, $_SESSION['email']);
$tokenStmt->execute();
$token = $tokenStmt->get_result()->fetch_assoc();
$tokenStmt->close();
if (!$token) {
    oldora_json(['ok' => false, 'message' => 'The selected YouTube account is not connected to your profile.'], 422);
}

try {
    $apiAccessToken = oldora_refresh_youtube_token($con, $token);
    $channel = oldora_resolve_youtube_channel($channelInput, $apiAccessToken);
    $latest = oldora_youtube_uploads($channel['uploads_playlist_id'], 1, $apiAccessToken);
    $lastVideoId = $latest[0]['id'] ?? null;
    $lastPublished = !empty($latest[0]['published_at']) ? gmdate('Y-m-d H:i:s', strtotime($latest[0]['published_at'])) : null;
    $timesJson = json_encode($times, JSON_UNESCAPED_SLASHES);

    $existing = $con->prepare('SELECT id FROM youtube_watchers WHERE user_id = ? AND source_channel_id = ? AND destination_token_id = ? LIMIT 1');
    $existing->bind_param('isi', $userId, $channel['id'], $destinationTokenId);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    $existing->close();

    if ($row) {
        $id = (int) $row['id'];
        $stmt = $con->prepare('UPDATE youtube_watchers SET source_channel_name = ?, source_channel_input = ?, uploads_playlist_id = ?, prompt_template = ?, voice_name = ?, timezone = ?, smart_times_json = ?, privacy_level = ?, is_active = 1, last_error = NULL WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ssssssssii', $channel['name'], $channelInput, $channel['uploads_playlist_id'], $promptTemplate, $voiceName, $timezone, $timesJson, $privacy, $id, $userId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $con->prepare('INSERT INTO youtube_watchers (user_id, source_channel_id, source_channel_name, source_channel_input, uploads_playlist_id, destination_token_id, prompt_template, voice_name, timezone, smart_times_json, privacy_level, is_active, last_video_id, last_video_published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->bind_param('issssisssssss', $userId, $channel['id'], $channel['name'], $channelInput, $channel['uploads_playlist_id'], $destinationTokenId, $promptTemplate, $voiceName, $timezone, $timesJson, $privacy, $lastVideoId, $lastPublished);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
    }

    oldora_json([
        'ok' => true,
        'watcher_id' => $id,
        'channel_name' => $channel['name'],
        'channel_picture' => $channel['picture'],
        'message' => 'Channel watcher saved. New uploads after this moment will create original Shorts automatically.'
    ]);
} catch (Throwable $error) {
    oldora_log('youtube-watcher', 'Save failed', ['user_id' => $userId, 'error' => $error->getMessage()]);
    oldora_json(['ok' => false, 'message' => $error->getMessage()], 422);
}
