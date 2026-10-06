<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/content.php';

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
    oldora_json(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 419);
}

$type = strtolower(trim((string) ($_POST['media_type'] ?? '')));
$prompt = trim((string) ($_POST['prompt'] ?? ''));
$caption = trim((string) ($_POST['caption'] ?? ''));
$tokenIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['token_ids'] ?? [])))));
$consent = ($_POST['publish_consent'] ?? '') === '1';
$privacy = trim((string) ($_POST['privacy_level'] ?? 'SELF_ONLY'));

if (!in_array($type, ['image', 'video'], true)) {
    oldora_json(['ok' => false, 'message' => 'Choose image or video.'], 422);
}
if ($prompt === '' || strlen($prompt) > 4000) {
    oldora_json(['ok' => false, 'message' => 'Prompt must be between 1 and 4,000 characters.'], 422);
}
if (strlen($caption) > 2200) {
    oldora_json(['ok' => false, 'message' => 'Caption must be 2,200 characters or fewer.'], 422);
}
if ($tokenIds && !$consent) {
    oldora_json(['ok' => false, 'message' => 'Confirm that you want OLDORA to publish to the selected accounts.'], 422);
}

$scheduledAt = gmdate('Y-m-d H:i:s');
$scheduledRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
if ($scheduledRaw !== '') {
    try {
        $timezone = new DateTimeZone((string) ($_POST['timezone'] ?? 'UTC'));
        $date = new DateTimeImmutable($scheduledRaw, $timezone);
        $scheduledAt = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $ignored) {
        oldora_json(['ok' => false, 'message' => 'Invalid schedule date.'], 422);
    }
}

oldora_ensure_content_schema($con);
$userStmt = $con->prepare('SELECT id, credits FROM users WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $_SESSION['email']);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!$user) {
    oldora_json(['ok' => false, 'message' => 'User not found.'], 404);
}

$userId = (int) $user['id'];
$creditCost = $type === 'image' ? max(1, (int) oldora_env('IMAGE_CREDIT_COST', '1')) : max(1, (int) oldora_env('VIDEO_CREDIT_COST', '5'));
$provider = 'openai';
$starting = 'starting';

$con->begin_transaction();
try {
    $reserve = $con->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
    $reserve->bind_param('iii', $creditCost, $userId, $creditCost);
    $reserve->execute();
    if ($reserve->affected_rows !== 1) {
        throw new RuntimeException('Not enough credits for this generation.');
    }
    $reserve->close();

    $insert = $con->prepare('INSERT INTO content_items (user_id, media_type, prompt, caption, status, provider, credits_used) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->bind_param('isssssi', $userId, $type, $prompt, $caption, $starting, $provider, $creditCost);
    $insert->execute();
    $contentId = (int) $insert->insert_id;
    $insert->close();
    $con->commit();
} catch (Throwable $error) {
    $con->rollback();
    oldora_json(['ok' => false, 'message' => $error->getMessage()], 422);
}

try {
    if ($type === 'image') {
        $asset = oldora_generate_image($prompt, $userId);
        $ready = 'ready';
        $progress = 100;
        $update = $con->prepare('UPDATE content_items SET status = ?, asset_url = ?, asset_path = ?, progress = ? WHERE id = ?');
        $update->bind_param('sssii', $ready, $asset['url'], $asset['path'], $progress, $contentId);
        $update->execute();
        $update->close();
    } else {
        $video = oldora_start_video($prompt);
        $processing = (string) ($video['status'] ?? 'queued');
        if (!in_array($processing, ['queued', 'in_progress'], true)) {
            $processing = 'processing';
        }
        $jobId = (string) $video['id'];
        $progress = (int) ($video['progress'] ?? 0);
        $update = $con->prepare('UPDATE content_items SET status = ?, provider_job_id = ?, progress = ? WHERE id = ?');
        $update->bind_param('ssii', $processing, $jobId, $progress, $contentId);
        $update->execute();
        $update->close();
    }

    foreach ($tokenIds as $tokenId) {
        $accountStmt = $con->prepare('SELECT t.id, t.platform FROM user_tokens t INNER JOIN users u ON u.email = t.user_email WHERE t.id = ? AND u.id = ? LIMIT 1');
        $accountStmt->bind_param('ii', $tokenId, $userId);
        $accountStmt->execute();
        $account = $accountStmt->get_result()->fetch_assoc();
        $accountStmt->close();
        if (!$account) {
            continue;
        }
        $platform = strtolower((string) $account['platform']);
        if (($platform === 'youtube' || $platform === 'tiktok') && $type !== 'video') {
            continue;
        }
        $jobStatus = $type === 'image' ? 'pending' : 'waiting_media';
        $queue = $con->prepare('INSERT IGNORE INTO publish_jobs (content_id, user_id, token_id, platform, status, scheduled_at, privacy_level) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $queue->bind_param('iiissss', $contentId, $userId, $tokenId, $platform, $jobStatus, $scheduledAt, $privacy);
        $queue->execute();
        $queue->close();
    }

    oldora_json([
        'ok' => true,
        'content_id' => $contentId,
        'status' => $type === 'image' ? 'ready' : 'processing',
        'asset_url' => $type === 'image' ? $asset['url'] : null,
        'credits_used' => $creditCost,
        'message' => $type === 'image' ? 'Image created successfully.' : 'Video generation started. You can leave this page while it renders.'
    ]);
} catch (Throwable $error) {
    $message = function_exists('mb_substr') ? mb_substr($error->getMessage(), 0, 1000, 'UTF-8') : substr($error->getMessage(), 0, 1000);
    $con->begin_transaction();
    try {
        $failed = $con->prepare("UPDATE content_items SET status = 'failed', error_message = ? WHERE id = ? AND status <> 'failed'");
        $failed->bind_param('si', $message, $contentId);
        $failed->execute();
        if ($failed->affected_rows === 1) {
            $refund = $con->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $refund->bind_param('ii', $creditCost, $userId);
            $refund->execute();
            $refund->close();
        }
        $failed->close();
        $con->commit();
    } catch (Throwable $refundError) {
        $con->rollback();
        oldora_log('content', 'Generation refund failed', ['content_id' => $contentId, 'error' => $refundError->getMessage()]);
    }
    oldora_log('content', 'Generation failed', ['content_id' => $contentId, 'error' => $message]);
    oldora_json(['ok' => false, 'content_id' => $contentId, 'message' => $message], 502);
}

