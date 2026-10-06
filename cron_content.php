<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/publishers.php';
require_once __DIR__ . '/includes/youtube_watcher.php';

header('Content-Type: application/json; charset=utf-8');
$configuredSecret = oldora_env('CRON_SECRET');
$providedSecret = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['key'] ?? '');
if (PHP_SAPI !== 'cli' && ($configuredSecret === '' || !hash_equals($configuredSecret, $providedSecret))) {
    oldora_json(['ok' => false, 'message' => 'Unauthorized.'], 401);
}

oldora_ensure_content_schema($con);
oldora_ensure_youtube_watcher_schema($con);
$summary = ['watchers_checked' => 0, 'shorts_created' => 0, 'watcher_errors' => 0, 'videos_checked' => 0, 'videos_ready' => 0, 'videos_failed' => 0, 'posts_published' => 0, 'posts_failed' => 0];

$watcherSummary = oldora_process_youtube_watchers($con, 2);
$summary['watchers_checked'] = (int) $watcherSummary['checked'];
$summary['shorts_created'] = (int) $watcherSummary['created'];
$summary['watcher_errors'] = (int) $watcherSummary['errors'];

$videoRows = $con->query("SELECT * FROM content_items WHERE media_type = 'video' AND status IN ('queued', 'in_progress', 'processing') AND provider_job_id IS NOT NULL ORDER BY id ASC LIMIT 5");
while ($content = $videoRows->fetch_assoc()) {
    $summary['videos_checked']++;
    try {
        $remote = oldora_get_video_job($content['provider_job_id']);
        $remoteStatus = (string) ($remote['status'] ?? 'in_progress');
        $progress = max(0, min(100, (int) ($remote['progress'] ?? 0)));

        if ($remoteStatus === 'completed') {
            $asset = oldora_download_video($content['provider_job_id'], (int) $content['user_id']);
            $ready = 'ready';
            $progress = 100;
            $stmt = $con->prepare('UPDATE content_items SET status = ?, progress = ?, asset_url = ?, asset_path = ?, error_message = NULL WHERE id = ?');
            $stmt->bind_param('sissi', $ready, $progress, $asset['url'], $asset['path'], $content['id']);
            $stmt->execute();
            $stmt->close();
            $queue = $con->prepare("UPDATE publish_jobs SET status = 'pending' WHERE content_id = ? AND status = 'waiting_media'");
            $queue->bind_param('i', $content['id']);
            $queue->execute();
            $queue->close();
            $summary['videos_ready']++;
        } elseif ($remoteStatus === 'failed') {
            $message = (string) ($remote['error']['message'] ?? 'Video generation failed.');
            $con->begin_transaction();
            $failed = $con->prepare("UPDATE content_items SET status = 'failed', progress = ?, error_message = ? WHERE id = ? AND status <> 'failed'");
            $failed->bind_param('isi', $progress, $message, $content['id']);
            $failed->execute();
            if ($failed->affected_rows === 1) {
                $refund = $con->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
                $refund->bind_param('ii', $content['credits_used'], $content['user_id']);
                $refund->execute();
                $refund->close();
            }
            $failed->close();
            $con->commit();
            $summary['videos_failed']++;
        } else {
            $stmt = $con->prepare('UPDATE content_items SET status = ?, progress = ? WHERE id = ?');
            $stmt->bind_param('sii', $remoteStatus, $progress, $content['id']);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $error) {
        oldora_log('content', 'Video polling error', ['content_id' => $content['id'], 'error' => $error->getMessage()]);
    }
}

$jobs = $con->query("SELECT * FROM publish_jobs WHERE status = 'pending' AND scheduled_at <= UTC_TIMESTAMP() AND attempts < 3 ORDER BY scheduled_at ASC LIMIT 5");
while ($job = $jobs->fetch_assoc()) {
    $jobId = (int) $job['id'];
    $claim = $con->prepare("UPDATE publish_jobs SET status = 'publishing', attempts = attempts + 1 WHERE id = ? AND status = 'pending'");
    $claim->bind_param('i', $jobId);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) continue;

    try {
        $publishId = oldora_publish_job($con, $job);
        $done = $con->prepare("UPDATE publish_jobs SET status = 'published', provider_publish_id = ?, published_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?");
        $done->bind_param('si', $publishId, $jobId);
        $done->execute();
        $done->close();
        $summary['posts_published']++;
    } catch (Throwable $error) {
        $message = function_exists('mb_substr') ? mb_substr($error->getMessage(), 0, 1000, 'UTF-8') : substr($error->getMessage(), 0, 1000);
        $attempts = (int) $job['attempts'] + 1;
        $nextStatus = $attempts >= 3 ? 'failed' : 'pending';
        $retry = $con->prepare('UPDATE publish_jobs SET status = ?, last_error = ?, scheduled_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = ?');
        $retry->bind_param('ssi', $nextStatus, $message, $jobId);
        $retry->execute();
        $retry->close();
        $summary['posts_failed']++;
        oldora_log('publishing', 'Publish job failed', ['job_id' => $jobId, 'platform' => $job['platform'], 'error' => $message]);
    }
}

oldora_json(['ok' => true, 'summary' => $summary]);
