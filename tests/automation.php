<?php

require_once __DIR__ . '/../includes/automation_worker.php';
require_once __DIR__ . '/../includes/publishers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function automationTestConnection()
{
    return new mysqli(oldora_env('DB_HOST'), oldora_env('DB_USER'), oldora_env('DB_PASS'), oldora_env('DB_NAME'));
}
$con = automationTestConnection();
oldora_ensure_content_schema($con);
oldora_ensure_automation_schema($con);
// Connection-local tables shadow application data; no account, content, or queue rows are changed.
foreach (['users', 'content_items', 'publish_jobs', 'automation_worker_runs'] as $table) {
    $definition = $con->query("SHOW CREATE TABLE {$table}")->fetch_row()[1];
    $con->query(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition));
}
$checks = 0;
function automationCheck($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function automationReject($callback, $message)
{
    try { $callback(); } catch (RuntimeException $expected) { automationCheck(true, $message); return; }
    automationCheck(false, $message);
}
function automationRow($con, $table, $id)
{
    return $con->query("SELECT * FROM {$table} WHERE id = " . (int) $id)->fetch_assoc();
}
$con->query("INSERT INTO users (id, full_name, email, password, credits) VALUES (90, 'Fixture', 'automation@example.invalid', 'disabled', 20), (91, 'Other', 'other@example.invalid', 'disabled', 20)");
$con->query("INSERT INTO content_items (id, user_id, media_type, prompt, status, provider, provider_job_id, progress, credits_used) VALUES
    (1, 90, 'video', 'failed fixture', 'queued', 'fixture', 'failed', 0, 5),
    (2, 90, 'video', 'completed fixture', 'processing', 'fixture', 'completed', 10, 5),
    (3, 90, 'video', 'progress fixture', 'in_progress', 'fixture', 'progress', 60, 5),
    (4, 90, 'video', 'unknown fixture', 'queued', 'fixture', 'unknown', 0, 5),
    (5, 90, 'video', 'cancelled fixture', 'processing', 'fixture', 'cancelled', 30, 3)");
$con->query("INSERT INTO publish_jobs (id, content_id, user_id, token_id, platform, status, scheduled_at) VALUES
    (1, 1, 90, 1, 'fixture', 'waiting_media', UTC_TIMESTAMP()),
    (2, 2, 90, 2, 'fixture', 'waiting_media', UTC_TIMESTAMP()),
    (3, 2, 90, 3, 'fixture', 'paused', UTC_TIMESTAMP()),
    (4, 2, 90, 4, 'fixture', 'publishing', UTC_TIMESTAMP()),
    (5, 2, 90, 5, 'fixture', 'pending', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY)),
    (6, 2, 91, 6, 'fixture', 'pending', UTC_TIMESTAMP()),
    (7, 2, 90, 7, 'fixture', 'pending', UTC_TIMESTAMP()),
    (8, 2, 90, 8, 'fixture', 'pending', UTC_TIMESTAMP()),
    (9, 4, 90, 9, 'fixture', 'pending', UTC_TIMESTAMP()),
    (10, 2, 90, 10, 'fixture', 'pending', UTC_TIMESTAMP())");

automationCheck(oldora_automation_health($con)['state'] === 'never_run', 'No heartbeat is distinguished from an active worker');
$lockName = oldora_automation_lock_name($con);
$other = automationTestConnection();
$escapedLock = $other->real_escape_string($lockName);
$other->query("SELECT GET_LOCK('{$escapedLock}', 0)");
$blocked = oldora_run_content_worker($con, ['watchers' => fn() => throw new RuntimeException('An overlapping worker must not run callbacks')]);
automationCheck($blocked['skipped_busy'] && $blocked['videos_checked'] === 0, 'Overlapping worker exits without polling or publishing');
automationCheck(oldora_automation_health($con)['state'] === 'never_run', 'Skipped worker does not manufacture a heartbeat');
$other->query("SELECT RELEASE_LOCK('{$escapedLock}')");
$other->close();

$published = [];
$downloads = 0;
$callbacks = [
    'watchers' => fn() => ['checked' => 0, 'created' => 0, 'errors' => 0],
    'poll' => static function ($id) {
        return match ($id) {
            'failed' => ['status' => 'failed', 'error' => ['message' => 'Provider rejected the render']],
            'completed' => ['status' => 'completed', 'progress' => 100],
            'progress' => ['status' => 'in_progress', 'progress' => 40],
            'cancelled' => ['status' => 'cancelled'],
            default => ['status' => 'unexpected']
        };
    },
    'download' => static function () use (&$downloads) {
        $downloads++;
        return ['url' => 'https://example.invalid/fixture.mp4', 'path' => '/tmp/automation-fixture.mp4'];
    },
    'publish' => static function ($db, $job) use (&$published) {
        $id = (int) $job['id'];
        $published[] = $id;
        if ($id === 7) throw new RuntimeException('Rate limit before upload');
        if ($id === 8) throw new OldoraPublishUncertainException('The upload may have reached the platform');
        if ($id === 10) return '';
        if ($id >= 11) return ['id' => 'fixture-receipt-' . $id, 'status' => 'submitted'];
        return 'fixture-receipt-' . $id;
    }
];
$summary = oldora_run_content_worker($con, $callbacks, 20);
automationCheck($summary['videos_checked'] === 5 && $summary['videos_ready'] === 1 && $summary['videos_failed'] === 2, 'Completed and terminal video states are processed');
automationCheck($summary['video_poll_errors'] === 1, 'Unexpected provider status remains retryable');
automationCheck((int) automationRow($con, 'content_items', 3)['progress'] === 60, 'Provider progress cannot move backward');
automationCheck(automationRow($con, 'content_items', 4)['status'] === 'queued', 'Unknown status does not replace valid local state');
automationCheck((int) automationRow($con, 'users', 90)['credits'] === 28, 'Failed and cancelled renders return reserved credits');
automationCheck(automationRow($con, 'publish_jobs', 1)['status'] === 'failed', 'A failed render does not leave publishing waiting forever');
automationCheck(automationRow($con, 'publish_jobs', 2)['status'] === 'published' && $downloads === 1, 'Ready media is downloaded once and releases its publish job');
automationCheck(automationRow($con, 'publish_jobs', 3)['status'] === 'paused', 'Worker preserves paused jobs');
automationCheck(automationRow($con, 'publish_jobs', 4)['status'] === 'needs_review', 'Interrupted publishing requires account review');
automationCheck(automationRow($con, 'publish_jobs', 8)['status'] === 'needs_review', 'Ambiguous platform result is not automatically reposted');
automationCheck(automationRow($con, 'publish_jobs', 10)['status'] === 'needs_review', 'A missing receipt is not reported as successful publication');
automationCheck(automationRow($con, 'publish_jobs', 9)['status'] === 'waiting_media', 'Pending jobs with unfinished media return to the media queue');
automationCheck($published === [2, 7, 8, 10], 'Only due, owned, ready jobs reach the publisher');
$retry = automationRow($con, 'publish_jobs', 7);
automationCheck($retry['status'] === 'pending' && (int) $retry['attempts'] === 1 && strtotime($retry['scheduled_at'] . ' UTC') >= time() + 295, 'Safe failures back off for five minutes');
automationCheck(oldora_automation_health($con)['state'] === 'healthy', 'Completed pass records a real heartbeat');

$again = oldora_run_content_worker($con, $callbacks, 20);
automationCheck($again['videos_failed'] === 0 && $again['posts_published'] === 0 && $downloads === 1, 'Repeated worker pass does not repeat terminal work');
automationCheck((int) automationRow($con, 'users', 90)['credits'] === 28, 'Repeated pass does not double-refund');
automationCheck(!oldora_fail_video($con, automationRow($con, 'content_items', 2), 'stale failure'), 'Ready content cannot be overwritten or refunded by stale failure');
$con->query("UPDATE publish_jobs SET scheduled_at = UTC_TIMESTAMP() WHERE id = 7");
oldora_run_content_worker($con, $callbacks, 20);
$retry = automationRow($con, 'publish_jobs', 7);
automationCheck((int) $retry['attempts'] === 2 && strtotime($retry['scheduled_at'] . ' UTC') >= time() + 595, 'Second retry backs off for ten minutes');
$con->query("UPDATE publish_jobs SET scheduled_at = UTC_TIMESTAMP() WHERE id = 7");
oldora_run_content_worker($con, $callbacks, 20);
automationCheck(automationRow($con, 'publish_jobs', 7)['status'] === 'failed' && (int) automationRow($con, 'publish_jobs', 7)['attempts'] === 3, 'Safe retries stop after three attempts');

$con->query("INSERT INTO publish_jobs (id, content_id, user_id, token_id, platform, status, scheduled_at) VALUES
    (11, 2, 90, 11, 'fixture', 'pending', UTC_TIMESTAMP()),
    (12, 2, 90, 12, 'fixture', 'pending', UTC_TIMESTAMP()),
    (13, 2, 90, 13, 'fixture', 'pending', UTC_TIMESTAMP()),
    (14, 2, 90, 14, 'fixture', 'pending', UTC_TIMESTAMP())");
$accepted = oldora_run_content_worker($con, $callbacks, 20);
automationCheck($accepted['posts_submitted'] === 4 && $accepted['posts_published'] === 0, 'Accepted asynchronous posts are not reported as published');
automationCheck(automationRow($con, 'publish_jobs', 11)['published_at'] === null && automationRow($con, 'publish_jobs', 11)['provider_publish_id'] === 'fixture-receipt-11', 'Accepted receipt is persisted without a publication timestamp');
$statusReceipts = [];
$callbacks['publication_status'] = static function ($db, $job) use (&$statusReceipts) {
    $statusReceipts[(int) $job['id']][] = $job['provider_publish_id'];
    return match ((int) $job['id']) {
        11 => ['status' => 'published', 'id' => 'confirmed-post-11'],
        12 => ['status' => 'failed', 'error' => 'Platform rejected the submitted media'],
        14 => throw new OldoraPublishUncertainException('The final publication request timed out'),
        default => throw new RuntimeException('Temporary status endpoint failure')
    };
};
$publicationCalls = count($published);
$confirmed = oldora_run_content_worker($con, $callbacks, 20);
automationCheck($confirmed['publications_checked'] === 4 && $confirmed['posts_published'] === 1 && $confirmed['posts_failed'] === 1, 'Provider status confirms successful and failed submitted posts');
automationCheck(automationRow($con, 'publish_jobs', 11)['status'] === 'published' && automationRow($con, 'publish_jobs', 11)['published_at'] !== null, 'Publication timestamp is recorded only after confirmation');
automationCheck(automationRow($con, 'publish_jobs', 11)['provider_publish_id'] === 'confirmed-post-11', 'Confirmed Instagram post ID replaces the processing container receipt');
automationCheck(automationRow($con, 'publish_jobs', 12)['status'] === 'failed', 'A rejected submitted post exposes the final failure');
automationCheck($confirmed['publication_poll_errors'] === 2 && automationRow($con, 'publish_jobs', 13)['status'] === 'submitted', 'Status endpoint errors preserve the submitted state');
automationCheck($confirmed['posts_need_review'] === 1 && automationRow($con, 'publish_jobs', 14)['status'] === 'needs_review', 'Ambiguous final publication during polling requires owner review');
automationCheck(automationRow($con, 'publish_jobs', 14)['provider_publish_id'] === 'fixture-receipt-14', 'Review retains the container receipt for investigating an uncertain post');
automationCheck(count($published) === $publicationCalls && (int) automationRow($con, 'publish_jobs', 13)['attempts'] === 1, 'Polling an accepted post never uploads another copy');
automationCheck(!oldora_automation_job_action($con, 13, 90, 'retry', ['confirm_review' => true]), 'Submitted posts cannot be manually reposted while processing');
oldora_run_content_worker($con, $callbacks, 20);
$callbacks['publication_status'] = fn() => ['status' => 'submitted'];
oldora_run_content_worker($con, $callbacks, 20);
automationCheck(automationRow($con, 'publish_jobs', 13)['last_error'] === null, 'A successful status check clears its transient error');
automationCheck($statusReceipts[13] === ['fixture-receipt-13', 'fixture-receipt-13'], 'Repeated status polling reuses the persisted processing receipt');
automationCheck(count($published) === $publicationCalls, 'Interrupted processing checks never create a second container or upload');

class AutomationReceiptFaultConnection
{
    private $db;
    private $failOnce = true;
    public function __construct($db) { $this->db = $db; }
    public function __call($method, $args) { return $this->db->{$method}(...$args); }
    public function __get($name) { return $this->db->{$name}; }
    public function prepare($sql) {
        if ($this->failOnce && str_starts_with($sql, 'UPDATE publish_jobs SET status = ?, provider_publish_id = ?, published_at')) {
            $this->failOnce = false;
            throw new RuntimeException('Simulated database failure while saving an accepted receipt');
        }
        return $this->db->prepare($sql);
    }
}
$con->query("INSERT INTO publish_jobs (id, content_id, user_id, token_id, platform, status, scheduled_at) VALUES (15, 2, 90, 15, 'fixture', 'pending', UTC_TIMESTAMP())");
$fault = oldora_run_content_worker(new AutomationReceiptFaultConnection($con), $callbacks, 20);
automationCheck($fault['posts_need_review'] === 1 && automationRow($con, 'publish_jobs', 15)['status'] === 'needs_review', 'Receipt persistence failure does not cause an automatic re-upload');
automationCheck(automationRow($con, 'publish_jobs', 15)['provider_publish_id'] === 'fixture-receipt-15', 'A known receipt survives the recovery after its initial persistence failed');

automationCheck(!oldora_automation_job_action($con, 3, 91, 'resume'), 'Another user cannot change a publishing job');
automationCheck(!oldora_automation_job_action($con, 2, 90, 'reschedule', ['scheduled_at' => '2030-01-01T12:00']), 'Published jobs cannot be rescheduled');
automationReject(fn() => oldora_automation_job_action($con, 8, 90, 'retry'), 'Uncertain publication cannot be retried without acknowledgement');
automationCheck(oldora_automation_job_action($con, 8, 90, 'retry', ['confirm_review' => true]), 'Owner can retry an uncertain post after checking the account');
automationReject(fn() => oldora_automation_job_action($con, 1, 90, 'retry'), 'Publishing retry does not conceal a failed render');
automationCheck(oldora_automation_job_action($con, 3, 90, 'reschedule', ['scheduled_at' => '2030-07-01T12:00', 'timezone' => 'Africa/Casablanca']), 'Owner can reschedule a paused job');
automationCheck(automationRow($con, 'publish_jobs', 3)['status'] === 'paused', 'Changing a paused schedule does not silently resume publishing');
automationCheck(automationRow($con, 'publish_jobs', 3)['scheduled_at'] === '2030-07-01 11:00:00', 'Local schedule converts correctly to UTC');
automationCheck(oldora_automation_job_action($con, 3, 90, 'resume'), 'Owner can resume paused ready content');
automationCheck(automationRow($con, 'publish_jobs', 3)['scheduled_at'] === '2030-07-01 11:00:00', 'Resume preserves a future schedule');
$con->query("UPDATE publish_jobs SET status = 'publishing' WHERE id = 3");
automationCheck(!oldora_automation_job_action($con, 3, 90, 'reschedule', ['scheduled_at' => '2030-01-01T12:00']), 'Publishing job cannot be overwritten by a schedule action');
automationReject(fn() => oldora_automation_schedule('2030-02-31T12:00'), 'Invalid calendar dates are rejected');
automationReject(fn() => oldora_automation_schedule('2020-01-01T12:00'), 'Past schedules are rejected');
automationReject(fn() => oldora_automation_schedule('2030-01-01T12:00', 'Invalid/Timezone'), 'Invalid timezones are rejected');
automationReject(fn() => oldora_automation_schedule('2030-01-01'), 'Ambiguous schedule input is rejected');
automationReject(fn() => oldora_automation_schedule('2030-03-31T02:30', 'Europe/Paris'), 'A nonexistent daylight-saving time is rejected');

$con->query("INSERT INTO content_items (id, user_id, media_type, prompt, status, provider, provider_job_id, credits_used, created_at) VALUES
    (20, 90, 'image', 'fresh image start', 'starting', 'fixture', NULL, 2, UTC_TIMESTAMP()),
    (21, 90, 'video', 'fresh video start', 'starting', 'fixture', NULL, 5, UTC_TIMESTAMP()),
    (22, 90, 'image', 'interrupted image start', 'starting', 'fixture', NULL, 2, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE)),
    (23, 90, 'video', 'interrupted video start', 'starting', 'fixture', NULL, 5, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE)),
    (24, 90, 'video', 'old known render', 'queued', 'fixture', 'progress', 5, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)),
    (25, 90, 'video', 'old known start receipt', 'starting', 'fixture', 'already-accepted', 5, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)),
    (26, 90, 'image', 'racing fresh start', 'starting', 'fixture', NULL, 2, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE)),
    (27, 90, 'video', 'racing accepted start', 'starting', 'fixture', NULL, 5, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE))");
$con->query("INSERT INTO publish_jobs (id, content_id, user_id, token_id, platform, status, scheduled_at) VALUES
    (20, 22, 90, 20, 'fixture', 'waiting_media', UTC_TIMESTAMP()),
    (21, 23, 90, 21, 'fixture', 'pending', UTC_TIMESTAMP())");
$creditsBeforeRecovery = (int) automationRow($con, 'users', 90)['credits'];
automationCheck(!oldora_fail_video($con, automationRow($con, 'content_items', 22), 'Ordinary video failure'), 'Ordinary failure helper does not refund an unverified starting request');
$freshRace = automationRow($con, 'content_items', 26);
$con->query("UPDATE content_items SET created_at = UTC_TIMESTAMP() WHERE id = 26");
automationCheck(!oldora_fail_video($con, $freshRace, 'Stale snapshot', 0, true), 'Recovery rechecks the grace period under the row lock');
$receiptRace = automationRow($con, 'content_items', 27);
$con->query("UPDATE content_items SET status = 'queued', provider_job_id = 'progress' WHERE id = 27");
automationCheck(!oldora_fail_video($con, $receiptRace, 'Stale snapshot', 0, true), 'Recovery rechecks the durable provider receipt under the row lock');
$recovery = oldora_run_content_worker($con, $callbacks, 20);
automationCheck($recovery['starts_recovered'] === 2 && $recovery['start_recovery_errors'] === 0, 'Old image and video starts are recovered without provider resubmission');
automationCheck(automationRow($con, 'content_items', 20)['status'] === 'starting' && automationRow($con, 'content_items', 21)['status'] === 'starting', 'Fresh image and video starts remain in progress');
automationCheck(automationRow($con, 'content_items', 22)['status'] === 'failed' && automationRow($con, 'content_items', 23)['status'] === 'failed', 'Interrupted image and video requests reach the visible failed state');
automationCheck(automationRow($con, 'content_items', 22)['error_message'] === 'Generation start was interrupted; credits returned.', 'Interrupted start explains its returned credits');
automationCheck((int) automationRow($con, 'users', 90)['credits'] === $creditsBeforeRecovery + 7, 'Recovery returns only the interrupted reservations');
automationCheck(automationRow($con, 'publish_jobs', 20)['status'] === 'cancelled' && automationRow($con, 'publish_jobs', 21)['status'] === 'cancelled', 'Interrupted generation cancels waiting publishing jobs');
automationCheck(automationRow($con, 'content_items', 24)['status'] === 'in_progress' && automationRow($con, 'content_items', 25)['status'] === 'starting', 'Known provider jobs are never classified as unsubmitted starts');
$repeatRecovery = oldora_run_content_worker($con, $callbacks, 20);
automationCheck($repeatRecovery['starts_recovered'] === 0 && (int) automationRow($con, 'users', 90)['credits'] === $creditsBeforeRecovery + 7, 'Repeated interrupted-start recovery cannot refund twice');
$con->query("UPDATE automation_worker_runs SET heartbeat_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE worker_name = 'content'");
automationCheck(oldora_automation_health($con)['state'] === 'stale', 'Old worker heartbeat does not imply readiness');
$con->close();
echo "{$checks} automation checks passed\n";
