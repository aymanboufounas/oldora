<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/content_options.php';

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
$userEmail = (string) $_SESSION['email'];
// Provider requests can take minutes; keep account and payment polling responsive.
session_write_close();

foreach (['media_type', 'prompt', 'caption', 'privacy_level', 'youtube_privacy', 'scheduled_at', 'timezone'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) {
        oldora_json(['ok' => false, 'message' => 'Invalid ' . str_replace('_', ' ', $field) . '.'], 422);
    }
}
$type = strtolower(trim($_POST['media_type'] ?? ''));
$prompt = trim($_POST['prompt'] ?? '');
$caption = trim($_POST['caption'] ?? '');
$submittedIds = $_POST['token_ids'] ?? [];
if (!is_array($submittedIds) || count($submittedIds) > 20) {
    oldora_json(['ok' => false, 'message' => 'Choose up to 20 linked accounts.'], 422);
}
$tokenIds = [];
foreach ($submittedIds as $tokenId) {
    if (!is_string($tokenId) || !ctype_digit($tokenId) || (int) $tokenId < 1) {
        oldora_json(['ok' => false, 'message' => 'Invalid linked account.'], 422);
    }
    $tokenIds[] = (int) $tokenId;
}
$tokenIds = array_values(array_unique($tokenIds));
$consent = ($_POST['publish_consent'] ?? '') === '1';
$privacy = trim((string) ($_POST['privacy_level'] ?? 'SELF_ONLY'));
$youtubePrivacy = trim($_POST['youtube_privacy'] ?? 'private');

if (!in_array($type, ['image', 'video'], true)) {
    oldora_json(['ok' => false, 'message' => 'Choose image or video.'], 422);
}
if ($prompt === '' || preg_match('//u', $prompt) !== 1 || oldora_content_text_length($prompt) > 4000) {
    oldora_json(['ok' => false, 'message' => 'Prompt must be between 1 and 4,000 characters.'], 422);
}
if (preg_match('//u', $caption) !== 1 || oldora_content_text_length($caption) > 2200) {
    oldora_json(['ok' => false, 'message' => 'Caption must be 2,200 characters or fewer.'], 422);
}
if ($tokenIds && !$consent) {
    oldora_json(['ok' => false, 'message' => 'Confirm that you want OLDORA to publish to the selected accounts.'], 422);
}
if (!in_array($privacy, ['SELF_ONLY', 'PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR'], true)) {
    oldora_json(['ok' => false, 'message' => 'Choose a valid publishing privacy level.'], 422);
}
if (!in_array($youtubePrivacy, ['private', 'unlisted', 'public'], true)) {
    oldora_json(['ok' => false, 'message' => 'Choose a valid YouTube visibility.'], 422);
}
try {
    $generationOptions = oldora_content_options($_POST, $type);
    $optionsJson = json_encode($generationOptions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    oldora_json(['ok' => false, 'message' => $error->getMessage()], 422);
}

$scheduledAt = gmdate('Y-m-d H:i:s');
$scheduledRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
if ($scheduledRaw !== '') {
    try {
        $timezone = new DateTimeZone((string) ($_POST['timezone'] ?? 'UTC'));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledRaw, $timezone);
        if (!$date || $date->format('Y-m-d\TH:i') !== $scheduledRaw || $date->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Choose a future publish time.');
        }
        $scheduledAt = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $ignored) {
        oldora_json(['ok' => false, 'message' => 'Choose a valid future publish time.'], 422);
    }
}

oldora_ensure_content_schema($con);
$userStmt = $con->prepare('SELECT id, credits FROM users WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $userEmail);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!$user) {
    oldora_json(['ok' => false, 'message' => 'User not found.'], 404);
}

$userId = (int) $user['id'];
$publishAccounts = [];
foreach ($tokenIds as $tokenId) {
    $accountStmt = $con->prepare('SELECT t.id, t.platform FROM user_tokens t INNER JOIN users u ON u.email = t.user_email WHERE t.id = ? AND u.id = ? LIMIT 1');
    $accountStmt->bind_param('ii', $tokenId, $userId);
    $accountStmt->execute();
    $account = $accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();
    if (!$account || !in_array(strtolower((string) $account['platform']), ['instagram', 'youtube', 'tiktok'], true)) {
        oldora_json(['ok' => false, 'message' => 'A selected account is unavailable. Refresh your linked accounts.'], 422);
    }
    $platform = strtolower((string) $account['platform']);
    if ($type === 'image' && $platform !== 'instagram') {
        oldora_json(['ok' => false, 'message' => 'Image publishing currently supports Instagram only.'], 422);
    }
    if ($type === 'image' && $platform === 'instagram' && $generationOptions['image_aspect'] === 'portrait') {
        oldora_json(['ok' => false, 'message' => 'Choose square or landscape for Instagram. Portrait 2:3 images are available for download.'], 422);
    }
    $publishAccounts[] = ['id' => $tokenId, 'platform' => $platform];
}
$creditCost = $type === 'image' ? max(1, (int) oldora_env('IMAGE_CREDIT_COST', '1')) : max(1, (int) oldora_env('VIDEO_CREDIT_COST', '5'));
try {
    $provider = $type === 'video' ? oldora_video_provider() : 'openai';
} catch (RuntimeException $error) {
    oldora_log('content', 'Video provider configuration is invalid.');
    oldora_json(['ok' => false, 'message' => 'Video generation is currently unavailable. Contact your administrator.'], 503);
}
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

    $insert = $con->prepare('INSERT INTO content_items (user_id, media_type, prompt, caption, status, provider, credits_used, generation_options) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->bind_param('isssssis', $userId, $type, $prompt, $caption, $starting, $provider, $creditCost, $optionsJson);
    $insert->execute();
    $contentId = (int) $insert->insert_id;
    $insert->close();
    foreach ($publishAccounts as $account) {
        $jobPrivacy = $account['platform'] === 'youtube' ? $youtubePrivacy : $privacy;
        $queue = $con->prepare("INSERT INTO publish_jobs (content_id, user_id, token_id, platform, status, scheduled_at, privacy_level) VALUES (?, ?, ?, ?, 'waiting_media', ?, ?)");
        $queue->bind_param('iiisss', $contentId, $userId, $account['id'], $account['platform'], $scheduledAt, $jobPrivacy);
        $queue->execute();
        $queue->close();
    }
    $con->commit();
} catch (Throwable $error) {
    $con->rollback();
    oldora_json(['ok' => false, 'message' => $error->getMessage()], 422);
}

$sendGenerationResult = function (string $status, ?string $assetUrl, string $resultMessage) use ($con, $userId, $contentId, $creditCost, $publishAccounts): void {
    $balanceCredits = null;
    try {
        $balanceStmt = $con->prepare('SELECT credits FROM users WHERE id = ? LIMIT 1');
        $balanceStmt->bind_param('i', $userId);
        $balanceStmt->execute();
        $balanceRow = $balanceStmt->get_result()->fetch_assoc();
        $balanceCredits = $balanceRow ? (int) $balanceRow['credits'] : null;
        $balanceStmt->close();
    } catch (Throwable $balanceError) {
        // A display refresh must not fail/refund an already-created generation.
        oldora_log('content', 'Could not refresh balance after generation.', ['content_id' => $contentId]);
    }
    oldora_json([
        'ok' => true, 'content_id' => $contentId, 'status' => $status,
        'asset_url' => $assetUrl, 'credits_used' => $creditCost,
        'balance_credits' => $balanceCredits, 'publish_jobs' => count($publishAccounts),
        'message' => $resultMessage
    ]);
};

try {
    if ($type === 'image') {
        $asset = oldora_generate_image($prompt, $userId, $generationOptions);
        $ready = 'ready';
        $progress = 100;
        $update = $con->prepare("UPDATE content_items SET status = ?, asset_url = ?, asset_path = ?, progress = ? WHERE id = ? AND status = 'starting'");
        $update->bind_param('sssii', $ready, $asset['url'], $asset['path'], $progress, $contentId);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('This generation has already been resolved. Refresh its status.');
        $update->close();
        $queueReady = $con->prepare("UPDATE publish_jobs SET status = 'pending' WHERE content_id = ? AND status = 'waiting_media'");
        $queueReady->bind_param('i', $contentId);
        $queueReady->execute();
        $queueReady->close();
    } else {
        $video = oldora_start_video($prompt, $provider, $generationOptions);
        $processing = (string) ($video['status'] ?? 'queued');
        if (!in_array($processing, ['queued', 'in_progress'], true)) {
            $processing = 'processing';
        }
        $jobId = (string) $video['id'];
        $progress = (int) ($video['progress'] ?? 0);
        $update = $con->prepare("UPDATE content_items SET status = ?, provider_job_id = ?, progress = ? WHERE id = ? AND status = 'starting'");
        $update->bind_param('ssii', $processing, $jobId, $progress, $contentId);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('This generation has already been resolved. Refresh its status.');
        $update->close();
    }

    $sendGenerationResult($type === 'image' ? 'ready' : 'processing', $type === 'image' ? $asset['url'] : null,
        $type === 'image' ? 'Image created successfully.' : 'Video generation started. You can leave this page while it renders.');
} catch (Throwable $error) {
    $message = function_exists('mb_substr') ? mb_substr($error->getMessage(), 0, 1000, 'UTF-8') : substr($error->getMessage(), 0, 1000);
    $con->begin_transaction();
    $preservedContent = null;
    try {
        $failed = $con->prepare("UPDATE content_items SET status = 'failed', error_message = ? WHERE id = ? AND status = 'starting'");
        $failed->bind_param('si', $message, $contentId);
        $failed->execute();
        if ($failed->affected_rows === 1) {
            $refund = $con->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $refund->bind_param('ii', $creditCost, $userId);
            $refund->execute();
            $refund->close();
            $cancel = $con->prepare("UPDATE publish_jobs SET status = 'failed', last_error = 'Media generation failed.' WHERE content_id = ? AND status = 'waiting_media'");
            $cancel->bind_param('i', $contentId);
            $cancel->execute();
            $cancel->close();
        } else {
            $current = $con->prepare('SELECT status, asset_url, error_message FROM content_items WHERE id = ? AND user_id = ? LIMIT 1');
            $current->bind_param('ii', $contentId, $userId);
            $current->execute();
            $preservedContent = $current->get_result()->fetch_assoc();
            $current->close();
        }
        $failed->close();
        $con->commit();
    } catch (Throwable $refundError) {
        $con->rollback();
        oldora_log('content', 'Generation refund failed', ['content_id' => $contentId, 'error' => $refundError->getMessage()]);
    }
    if ($preservedContent && in_array($preservedContent['status'], ['ready', 'queued', 'processing', 'in_progress'], true)) {
        oldora_log('content', 'Generation is saved; finalization will continue in the worker.', ['content_id' => $contentId, 'error' => $message]);
        $isReady = $preservedContent['status'] === 'ready';
        $sendGenerationResult($isReady ? 'ready' : 'processing', $isReady ? $preservedContent['asset_url'] : null,
            $isReady ? 'Content was created successfully. Publishing will continue in the background.' : 'Video generation started. You can leave this page while it renders.');
    }
    if ($preservedContent && $preservedContent['status'] === 'failed' && $preservedContent['error_message']) {
        $message = $preservedContent['error_message'];
    }
    oldora_log('content', 'Generation failed', ['content_id' => $contentId, 'error' => $message]);
    oldora_json(['ok' => false, 'content_id' => $contentId, 'message' => $message], 502);
}
