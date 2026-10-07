<?php

require_once __DIR__ . '/content.php';

if (!function_exists('oldora_ensure_automation_schema')) {
    function oldora_ensure_automation_schema($con)
    {
        $con->query("CREATE TABLE IF NOT EXISTS automation_worker_runs (
            worker_name VARCHAR(40) NOT NULL,
            started_at DATETIME NULL,
            heartbeat_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_error TEXT NULL,
            summary_json TEXT NULL,
            PRIMARY KEY (worker_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('oldora_automation_health')) {
    function oldora_automation_health($con)
    {
        oldora_ensure_automation_schema($con);
        $row = $con->query("SELECT *, TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP()) AS age_seconds
            FROM automation_worker_runs WHERE worker_name = 'content' LIMIT 1")->fetch_assoc();
        if (!$row) return ['state' => 'never_run', 'last_started_at' => null, 'last_completed_at' => null];
        $staleAfter = max(300, min(3600, (int) oldora_env('AUTOMATION_STALE_SECONDS', '900')));
        $running = $row['started_at'] && (!$row['completed_at'] || $row['started_at'] > $row['completed_at']);
        $state = (int) $row['age_seconds'] > $staleAfter ? 'stale' : ($running ? 'running' : ($row['last_error'] ? 'error' : 'healthy'));
        return [
            'state' => $state,
            'last_started_at' => $row['started_at'],
            'last_completed_at' => $row['completed_at'],
            'heartbeat_at' => $row['heartbeat_at'],
            'age_seconds' => max(0, (int) $row['age_seconds'])
        ];
    }
}

if (!function_exists('oldora_automation_lock_name')) {
    function oldora_automation_lock_name($con)
    {
        $database = $con->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
        return 'oldora.content.' . substr(hash('sha256', (string) $database), 0, 32);
    }
}

if (!function_exists('oldora_automation_message')) {
    function oldora_automation_message($message)
    {
        return function_exists('mb_substr') ? mb_substr((string) $message, 0, 1000, 'UTF-8') : substr((string) $message, 0, 1000);
    }
}

if (!function_exists('oldora_fail_video')) {
    function oldora_fail_video($con, array $content, $message, $progress = 0, $recoverInterruptedStart = false)
    {
        $id = (int) $content['id'];
        $message = oldora_automation_message($message);
        $progress = max(0, min(100, (int) $progress));
        $con->begin_transaction();
        try {
            $locked = $con->prepare('SELECT status, user_id, credits_used, provider_job_id,
                (created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)) AS stale_start
                FROM content_items WHERE id = ? FOR UPDATE');
            $locked->bind_param('i', $id);
            $locked->execute();
            $current = $locked->get_result()->fetch_assoc();
            $locked->close();
            $eligible = $current && ($recoverInterruptedStart
                ? $current['status'] === 'starting' && $current['provider_job_id'] === null && (int) $current['stale_start'] === 1
                : in_array($current['status'], ['queued', 'processing', 'in_progress'], true));
            if (!$eligible) {
                $con->commit();
                return false;
            }
            $failed = $con->prepare("UPDATE content_items SET status = 'failed', progress = ?, error_message = ? WHERE id = ?");
            $failed->bind_param('isi', $progress, $message, $id);
            $failed->execute();
            $failed->close();
            $credits = (int) $current['credits_used'];
            $userId = (int) $current['user_id'];
            $refund = $con->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $refund->bind_param('ii', $credits, $userId);
            $refund->execute();
            if ($refund->affected_rows !== 1 && $credits > 0) throw new RuntimeException('Could not refund the failed generation.');
            $refund->close();
            $queueStatus = $recoverInterruptedStart ? 'cancelled' : 'failed';
            $queueMessage = $recoverInterruptedStart
                ? 'Generation start was interrupted. Credits were returned; create new content before publishing.'
                : 'Media generation failed. Create new content before publishing.';
            $queue = $con->prepare("UPDATE publish_jobs SET status = ?, last_error = ? WHERE content_id = ? AND user_id = ? AND status IN ('waiting_media', 'pending')");
            $queue->bind_param('ssii', $queueStatus, $queueMessage, $id, $userId);
            $queue->execute();
            $queue->close();
            $con->commit();
            return true;
        } catch (Throwable $error) {
            $con->rollback();
            throw $error;
        }
    }
}

if (!function_exists('oldora_claim_publish_job')) {
    function oldora_claim_publish_job($con, $jobId)
    {
        $jobId = (int) $jobId;
        $stmt = $con->prepare("UPDATE publish_jobs p INNER JOIN content_items c ON c.id = p.content_id AND c.user_id = p.user_id
            SET p.status = 'publishing', p.attempts = p.attempts + 1
            WHERE p.id = ? AND p.status = 'pending' AND p.scheduled_at <= UTC_TIMESTAMP() AND p.attempts < 3 AND c.status = 'ready'");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }
}

if (!function_exists('oldora_run_content_worker')) {
    /** Callbacks allow deterministic validation without contacting publishing accounts. */
    function oldora_run_content_worker($con, array $callbacks = [], $limit = 5)
    {
        oldora_ensure_automation_schema($con);
        $limit = max(1, min(20, (int) $limit));
        $lockName = oldora_automation_lock_name($con);
        $lock = $con->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        $lock->bind_param('s', $lockName);
        $lock->execute();
        $acquired = (int) $lock->get_result()->fetch_assoc()['acquired'] === 1;
        $lock->close();
        $summary = ['watchers_checked' => 0, 'shorts_created' => 0, 'watcher_errors' => 0, 'videos_checked' => 0,
            'videos_ready' => 0, 'videos_failed' => 0, 'video_poll_errors' => 0, 'posts_published' => 0,
            'posts_failed' => 0, 'posts_retrying' => 0, 'posts_need_review' => 0, 'posts_submitted' => 0,
            'starts_recovered' => 0, 'start_recovery_errors' => 0,
            'publications_checked' => 0, 'publication_poll_errors' => 0, 'skipped_busy' => !$acquired];
        if (!$acquired) return $summary;

        $poll = $callbacks['poll'] ?? 'oldora_get_video_job';
        $download = $callbacks['download'] ?? 'oldora_download_video';
        $publish = $callbacks['publish'] ?? 'oldora_publish_job';
        $publicationStatus = $callbacks['publication_status'] ?? 'oldora_get_publish_status';
        $watchers = $callbacks['watchers'] ?? 'oldora_process_youtube_watchers';
        $errorKind = $callbacks['publish_error_kind'] ?? static function ($error) {
            return is_a($error, 'OldoraPublishUncertainException') ? 'uncertain' : 'transient';
        };
        try {
            $con->query("INSERT INTO automation_worker_runs (worker_name, started_at, heartbeat_at, completed_at, last_error)
                VALUES ('content', UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)
                ON DUPLICATE KEY UPDATE started_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP(), completed_at = NULL, last_error = NULL");
            // The connection-scoped lock proves no other worker is publishing.
            // An interrupted upload may have reached the platform; check before uploading again.
            $con->query("UPDATE publish_jobs SET status = 'needs_review', last_error = 'Publishing was interrupted. Check the connected account before retrying to avoid a duplicate post.' WHERE status = 'publishing'");
            $summary['posts_need_review'] += $con->affected_rows;
            // Provider requests finish within four minutes. A half-hour grace period
            // protects active requests while recovering reservations left by a crashed start.
            $interruptedStarts = $con->query("SELECT * FROM content_items WHERE status = 'starting' AND provider_job_id IS NULL
                AND media_type IN ('image', 'video') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
                ORDER BY created_at ASC, id ASC LIMIT {$limit}")->fetch_all(MYSQLI_ASSOC);
            foreach ($interruptedStarts as $content) {
                try {
                    if (oldora_fail_video($con, $content, 'Generation start was interrupted; credits returned.', 0, true)) $summary['starts_recovered']++;
                } catch (Throwable $error) {
                    $summary['start_recovery_errors']++;
                    oldora_log('content', 'Interrupted generation recovery failed', ['content_id' => $content['id'], 'error' => $error->getMessage()]);
                }
            }
            $con->query("UPDATE publish_jobs p INNER JOIN content_items c ON c.id = p.content_id AND c.user_id = p.user_id SET p.status = 'pending' WHERE p.status = 'waiting_media' AND c.status = 'ready'");
            $con->query("UPDATE publish_jobs p INNER JOIN content_items c ON c.id = p.content_id AND c.user_id = p.user_id SET p.status = 'failed', p.last_error = 'Media generation failed. Create new content before publishing.' WHERE p.status IN ('waiting_media', 'pending') AND c.status = 'failed'");
            $con->query("UPDATE publish_jobs p INNER JOIN content_items c ON c.id = p.content_id AND c.user_id = p.user_id SET p.status = 'waiting_media' WHERE p.status = 'pending' AND c.status IN ('starting', 'queued', 'processing', 'in_progress')");
            $con->query("UPDATE publish_jobs SET status = 'failed', last_error = COALESCE(last_error, 'Publishing stopped after three unsuccessful attempts.') WHERE status = 'pending' AND attempts >= 3");

            if (is_callable($watchers)) {
                $watcherSummary = $watchers($con, 2);
                $summary['watchers_checked'] = (int) ($watcherSummary['checked'] ?? 0);
                $summary['shorts_created'] = (int) ($watcherSummary['created'] ?? 0);
                $summary['watcher_errors'] = (int) ($watcherSummary['errors'] ?? 0);
            }
            $videos = $con->query("SELECT * FROM content_items WHERE media_type = 'video' AND status IN ('queued', 'in_progress', 'processing') AND provider_job_id IS NOT NULL
                ORDER BY COALESCE(updated_at, created_at) ASC, id ASC LIMIT {$limit}")->fetch_all(MYSQLI_ASSOC);
            foreach ($videos as $content) {
                $summary['videos_checked']++;
                $con->query("UPDATE automation_worker_runs SET heartbeat_at = UTC_TIMESTAMP() WHERE worker_name = 'content'");
                try {
                    $remote = $poll($content['provider_job_id'], $content['provider']);
                    $status = (string) ($remote['status'] ?? 'in_progress');
                    $progress = max((int) $content['progress'], max(0, min(100, (int) ($remote['progress'] ?? 0))));
                    if ($status === 'completed') {
                        $asset = $download($content['provider_job_id'], (int) $content['user_id'], $content['provider']);
                        $stmt = $con->prepare("UPDATE content_items SET status = 'ready', progress = 100, asset_url = ?, asset_path = ?, error_message = NULL, updated_at = UTC_TIMESTAMP()
                            WHERE id = ? AND status IN ('queued', 'in_progress', 'processing')");
                        $stmt->bind_param('ssi', $asset['url'], $asset['path'], $content['id']);
                        $stmt->execute();
                        $summary['videos_ready'] += $stmt->affected_rows;
                        $stmt->close();
                        $queue = $con->prepare("UPDATE publish_jobs SET status = 'pending' WHERE content_id = ? AND user_id = ? AND status = 'waiting_media'");
                        $queue->bind_param('ii', $content['id'], $content['user_id']);
                        $queue->execute();
                        $queue->close();
                    } elseif (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                        $message = (string) ($remote['error']['message'] ?? 'Video generation failed.');
                        if (oldora_fail_video($con, $content, $message, $progress)) $summary['videos_failed']++;
                    } elseif (in_array($status, ['queued', 'in_progress', 'processing'], true)) {
                        $stmt = $con->prepare("UPDATE content_items SET status = ?, progress = ?, error_message = NULL, updated_at = UTC_TIMESTAMP()
                            WHERE id = ? AND status IN ('queued', 'in_progress', 'processing')");
                        $stmt->bind_param('sii', $status, $progress, $content['id']);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        throw new RuntimeException('Video provider returned an unrecognized status.');
                    }
                } catch (Throwable $error) {
                    $summary['video_poll_errors']++;
                    $message = oldora_automation_message('Status refresh failed; the worker will try again. ' . $error->getMessage());
                    $stmt = $con->prepare("UPDATE content_items SET error_message = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('queued', 'in_progress', 'processing')");
                    $stmt->bind_param('si', $message, $content['id']);
                    $stmt->execute();
                    $stmt->close();
                    oldora_log('content', 'Video polling error', ['content_id' => $content['id'], 'error' => $error->getMessage()]);
                }
            }

            $submitted = $con->query("SELECT * FROM publish_jobs WHERE status = 'submitted' AND provider_publish_id IS NOT NULL
                ORDER BY COALESCE(updated_at, created_at) ASC, id ASC LIMIT {$limit}")->fetch_all(MYSQLI_ASSOC);
            foreach ($submitted as $job) {
                $jobId = (int) $job['id'];
                $receiptId = (string) $job['provider_publish_id'];
                $confirmedPublished = false;
                $summary['publications_checked']++;
                $con->query("UPDATE automation_worker_runs SET heartbeat_at = UTC_TIMESTAMP() WHERE worker_name = 'content'");
                try {
                    $remote = $publicationStatus($con, $job);
                    $status = (string) ($remote['status'] ?? '');
                    if (!in_array($status, ['submitted', 'published', 'failed'], true)) {
                        throw new RuntimeException('Platform returned an unrecognized publication status.');
                    }
                    $confirmedPublished = $status === 'published';
                    if (!empty($remote['id'])) $receiptId = (string) $remote['id'];
                    $message = $status === 'failed' ? oldora_automation_message((string) ($remote['error'] ?? 'The platform could not finish publishing. Retry after resolving the reported issue.')) : null;
                    $stmt = $con->prepare("UPDATE publish_jobs SET status = ?, provider_publish_id = ?, last_error = ?, published_at = CASE WHEN ? = 'published' THEN UTC_TIMESTAMP() ELSE NULL END, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'submitted'");
                    $stmt->bind_param('ssssi', $status, $receiptId, $message, $status, $jobId);
                    $stmt->execute();
                    $stmt->close();
                    if ($status === 'published') $summary['posts_published']++;
                    if ($status === 'failed') $summary['posts_failed']++;
                } catch (Throwable $error) {
                    $summary['publication_poll_errors']++;
                    $uncertain = $confirmedPublished || is_a($error, 'OldoraPublishUncertainException');
                    $nextStatus = $uncertain ? 'needs_review' : 'submitted';
                    $prefix = $uncertain ? 'Check the connected account before retrying to avoid a duplicate post. ' : 'Publication status refresh failed. The worker will check again without uploading another copy. ';
                    $message = oldora_automation_message($prefix . $error->getMessage());
                    $stmt = $con->prepare("UPDATE publish_jobs SET status = ?, provider_publish_id = ?, last_error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'submitted'");
                    $stmt->bind_param('sssi', $nextStatus, $receiptId, $message, $jobId);
                    $stmt->execute();
                    $stmt->close();
                    if ($uncertain) $summary['posts_need_review']++;
                    oldora_log('publishing', 'Publication status polling failed', ['job_id' => $jobId, 'error' => $error->getMessage()]);
                }
            }

            $jobs = $con->query("SELECT p.* FROM publish_jobs p INNER JOIN content_items c ON c.id = p.content_id AND c.user_id = p.user_id
                WHERE p.status = 'pending' AND p.scheduled_at <= UTC_TIMESTAMP() AND p.attempts < 3 AND c.status = 'ready'
                ORDER BY p.scheduled_at ASC, p.id ASC LIMIT {$limit}")->fetch_all(MYSQLI_ASSOC);
            foreach ($jobs as $job) {
                $jobId = (int) $job['id'];
                if (!oldora_claim_publish_job($con, $jobId)) continue;
                $con->query("UPDATE automation_worker_runs SET heartbeat_at = UTC_TIMESTAMP() WHERE worker_name = 'content'");
                $kind = null;
                $publishId = '';
                try {
                    $result = $publish($con, $job);
                    $publishId = (string) (is_array($result) ? ($result['id'] ?? '') : $result);
                    $publishStatus = is_array($result) ? (string) ($result['status'] ?? 'published') : 'published';
                    if ($publishId === '') {
                        $kind = 'uncertain';
                        throw new RuntimeException('Platform returned no publishing receipt. Check the account before retrying.');
                    }
                    if (!in_array($publishStatus, ['published', 'submitted'], true)) throw new RuntimeException('Platform returned an unrecognized publishing receipt.');
                    $done = $con->prepare("UPDATE publish_jobs SET status = ?, provider_publish_id = ?, published_at = CASE WHEN ? = 'published' THEN UTC_TIMESTAMP() ELSE NULL END, last_error = NULL WHERE id = ? AND status = 'publishing'");
                    $done->bind_param('sssi', $publishStatus, $publishId, $publishStatus, $jobId);
                    $done->execute();
                    $summary[$publishStatus === 'submitted' ? 'posts_submitted' : 'posts_published'] += $done->affected_rows;
                    $done->close();
                } catch (Throwable $error) {
                    $message = oldora_automation_message($error->getMessage());
                    $kind = $publishId !== '' ? 'uncertain' : ($kind ?? (string) $errorKind($error));
                    $attempts = (int) $job['attempts'] + 1;
                    $nextStatus = $kind === 'transient' && $attempts < 3 ? 'pending' : ($kind === 'permanent' ? 'failed' : 'needs_review');
                    if ($kind === 'transient' && $attempts >= 3) $nextStatus = 'failed';
                    if ($nextStatus === 'needs_review') $message = oldora_automation_message('Check the connected account before retrying to avoid a duplicate post. ' . $message);
                    $minutes = 5 * (2 ** max(0, $attempts - 1));
                    $retry = $con->prepare("UPDATE publish_jobs SET status = ?, last_error = ?, provider_publish_id = CASE WHEN ? <> '' THEN ? ELSE provider_publish_id END, scheduled_at = CASE WHEN ? = 'pending' THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) ELSE scheduled_at END WHERE id = ? AND status = 'publishing'");
                    $retry->bind_param('sssssii', $nextStatus, $message, $publishId, $publishId, $nextStatus, $minutes, $jobId);
                    $retry->execute();
                    $retry->close();
                    $summary[$nextStatus === 'pending' ? 'posts_retrying' : ($nextStatus === 'needs_review' ? 'posts_need_review' : 'posts_failed')]++;
                    oldora_log('publishing', 'Publish job failed', ['job_id' => $jobId, 'platform' => $job['platform'], 'error' => $message]);
                }
                unset($kind);
            }
            $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $done = $con->prepare("UPDATE automation_worker_runs SET completed_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP(), summary_json = ? WHERE worker_name = 'content'");
            $done->bind_param('s', $summaryJson);
            $done->execute();
            $done->close();
            return $summary;
        } catch (Throwable $error) {
            $message = oldora_automation_message($error->getMessage());
            $failed = $con->prepare("UPDATE automation_worker_runs SET completed_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP(), last_error = ? WHERE worker_name = 'content'");
            $failed->bind_param('s', $message);
            $failed->execute();
            $failed->close();
            throw $error;
        } finally {
            $unlock = $con->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->bind_param('s', $lockName);
            $unlock->execute();
            $unlock->close();
        }
    }
}

if (!function_exists('oldora_automation_schedule')) {
    function oldora_automation_schedule($raw, $timezoneName = 'UTC')
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', (string) $raw)) {
            throw new RuntimeException('Choose a valid publishing date and time.');
        }
        try {
            $zone = new DateTimeZone((string) $timezoneName);
            $format = strlen((string) $raw) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s';
            $date = DateTimeImmutable::createFromFormat('!' . $format, (string) $raw, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format($format) !== $raw) {
                throw new RuntimeException('Invalid date or local time.');
            }
        } catch (Throwable $error) {
            throw new RuntimeException('The selected date, time, or timezone is invalid.');
        }
        if ($date->getTimestamp() < time()) throw new RuntimeException('The publishing time cannot be in the past.');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

if (!function_exists('oldora_automation_job_action')) {
    function oldora_automation_job_action($con, $jobId, $userId, $action, array $options = [])
    {
        $jobId = (int) $jobId;
        $userId = (int) $userId;
        $allowed = [
            'pause' => ['pending', 'waiting_media'],
            'resume' => ['paused'],
            'publish_now' => ['pending', 'waiting_media', 'paused', 'failed'],
            'retry' => ['failed', 'needs_review'],
            'cancel' => ['pending', 'waiting_media', 'paused', 'failed', 'needs_review'],
            'delete' => ['cancelled', 'failed'],
            'reschedule' => ['pending', 'waiting_media', 'paused', 'failed']
        ];
        if (!isset($allowed[$action])) throw new RuntimeException('Unknown automation action.');
        $con->begin_transaction();
        try {
            // Lock the job during validation so a concurrent worker cannot publish while it is rescheduled.
            $stmt = $con->prepare('SELECT p.*, c.status AS media_status FROM publish_jobs p INNER JOIN content_items c ON c.id = p.content_id AND c.user_id = p.user_id WHERE p.id = ? AND p.user_id = ? FOR UPDATE');
            $stmt->bind_param('ii', $jobId, $userId);
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$job || !in_array($job['status'], $allowed[$action], true)) {
                $con->commit();
                return false;
            }
            if ($action === 'delete') {
                $stmt = $con->prepare('DELETE FROM publish_jobs WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ii', $jobId, $userId);
            } else {
                $nextStatus = $job['media_status'] === 'ready' ? 'pending' : 'waiting_media';
                $schedule = (string) $job['scheduled_at'];
                $attempts = (int) $job['attempts'];
                if (in_array($action, ['resume', 'publish_now', 'retry', 'reschedule'], true)) {
                    if (!in_array($job['media_status'], ['ready', 'starting', 'queued', 'processing', 'in_progress'], true)) {
                        throw new RuntimeException('Media generation failed. Create new content before publishing.');
                    }
                    if ($job['status'] === 'needs_review' && empty($options['confirm_review'])) {
                        throw new RuntimeException('Check the connected account first, then confirm that retrying will not create a duplicate post.');
                    }
                    if (in_array($job['status'], ['failed', 'needs_review'], true)) $attempts = 0;
                    if ($action === 'reschedule') {
                        $schedule = oldora_automation_schedule($options['scheduled_at'] ?? '', $options['timezone'] ?? 'UTC');
                        if ($job['status'] === 'paused') $nextStatus = 'paused';
                    } elseif ($action === 'publish_now' || $action === 'retry' || $schedule < gmdate('Y-m-d H:i:s')) {
                        $schedule = gmdate('Y-m-d H:i:s');
                    }
                } elseif ($action === 'pause') {
                    $nextStatus = 'paused';
                } else {
                    $nextStatus = 'cancelled';
                }
                $stmt = $con->prepare('UPDATE publish_jobs SET status = ?, scheduled_at = ?, attempts = ?, last_error = NULL WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ssiii', $nextStatus, $schedule, $attempts, $jobId, $userId);
            }
            $stmt->execute();
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            $con->commit();
            return $changed;
        } catch (Throwable $error) {
            $con->rollback();
            throw $error;
        }
    }
}
