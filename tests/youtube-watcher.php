<?php

// Fixture providers are declared before loading the guarded application helpers.
// No YouTube, OpenAI, or video-generation request leaves this process.
$providerMode = 'success';
$providerCalls = 0;
function oldora_youtube_uploads($playlist, $limit, $token)
{
    return [['id' => 'fixture-source', 'title' => 'A new topic', 'description' => 'Fixture source',
        'url' => 'https://www.youtube.com/watch?v=fixture-source', 'published_at' => '2026-10-01T12:00:00Z']];
}
function oldora_short_package(array $video, array $watcher)
{
    return ['title' => 'Fixture #Shorts', 'description' => 'An original fixture', 'video_prompt' => 'An original video about the stars'];
}
function oldora_start_video($prompt, $provider = null, $options = null)
{
    global $con, $providerMode, $providerCalls;
    $providerCalls++;
    if ($providerMode === 'failure') throw new RuntimeException('Fixture provider rejected generation');
    if ($providerMode === 'late') {
        $row = $con->query("SELECT * FROM content_items WHERE user_id = 90 AND status = 'starting' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $con->query('UPDATE content_items SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE) WHERE id = ' . (int) $row['id']);
        oldora_fail_video($con, $row, 'Generation start was interrupted; credits returned.', 0, true);
    }
    return ['id' => 'fixture-render-receipt', 'status' => 'queued', 'progress' => 0];
}
require_once __DIR__ . '/../includes/automation_worker.php';
require_once __DIR__ . '/../includes/youtube_watcher.php';
putenv('VIDEO_PROVIDER=moneyprinterturbo');
putenv('VIDEO_CREDIT_COST=5');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli(oldora_env('DB_HOST'), oldora_env('DB_USER'), oldora_env('DB_PASS'), oldora_env('DB_NAME'));
oldora_ensure_youtube_watcher_schema($con);
foreach (['users', 'user_tokens', 'content_items', 'publish_jobs', 'youtube_watchers', 'youtube_watcher_events'] as $table) {
    $definition = $con->query("SHOW CREATE TABLE {$table}")->fetch_row()[1];
    $con->query(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition));
}
$checks = 0;
function watcherCheck($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function watcherReject($callback, $message)
{
    try { $callback(); } catch (RuntimeException $expected) { watcherCheck(true, $message); return; }
    watcherCheck(false, $message);
}
function watcherFixture($con)
{
    global $providerMode, $providerCalls;
    foreach (['publish_jobs', 'content_items', 'youtube_watcher_events', 'youtube_watchers', 'user_tokens', 'users'] as $table) $con->query("DELETE FROM {$table}");
    $providerMode = 'success';
    $providerCalls = 0;
    $con->query("INSERT INTO users (id, full_name, email, password, credits) VALUES (90, 'Fixture', 'watcher@example.invalid', 'disabled', 20), (91, 'Other', 'other@example.invalid', 'disabled', 20)");
    $con->query("INSERT INTO user_tokens (id, user_email, platform, access_token, channel_id) VALUES
        (90, 'watcher@example.invalid', 'youtube', 'fixture-access', 'fixture-channel'),
        (91, 'other@example.invalid', 'youtube', 'other-fixture-access', 'other-channel')");
    $con->query("INSERT INTO youtube_watchers (id, user_id, source_channel_id, source_channel_name, source_channel_input, uploads_playlist_id, destination_token_id, last_video_id)
        VALUES (90, 90, 'source', 'Fixture source', '@fixture', 'playlist', 90, 'previous')");
    return $con->query('SELECT * FROM youtube_watchers WHERE id = 90')->fetch_assoc();
}
function watcherCredits($con) { return (int) $con->query('SELECT credits FROM users WHERE id = 90')->fetch_assoc()['credits']; }
function watcherContent($con) { return $con->query('SELECT * FROM content_items ORDER BY id DESC LIMIT 1')->fetch_assoc(); }
function watcherEvent($con) { return $con->query('SELECT * FROM youtube_watcher_events ORDER BY id DESC LIMIT 1')->fetch_assoc(); }
function watcherJobCount($con) { return (int) $con->query('SELECT COUNT(*) AS total FROM publish_jobs')->fetch_assoc()['total']; }
class WatcherFaultConnection
{
    private $db;
    private $mode;
    private $failed = false;
    private $commits = 0;
    public function __construct($db, $mode) { $this->db = $db; $this->mode = $mode; }
    public function __call($method, $args) { return $this->db->{$method}(...$args); }
    public function __get($name) { return $this->db->{$name}; }
    public function prepare($sql) {
        $matches = ($this->mode === 'metadata' && str_starts_with($sql, 'UPDATE youtube_watchers SET last_video_id = ?, last_video_published_at = ?'))
            || ($this->mode === 'queue' && str_starts_with($sql, 'INSERT IGNORE INTO publish_jobs'));
        if ($matches && !$this->failed) {
            $this->failed = true;
            throw new RuntimeException('Fixture ' . $this->mode . ' update failure');
        }
        return $this->db->prepare($sql);
    }
    public function commit() {
        $result = $this->db->commit();
        $this->commits++;
        if ($this->mode === 'commit_response' && $this->commits === 2 && !$this->failed) {
            $this->failed = true;
            throw new RuntimeException('Fixture response failure after committed generation');
        }
        return $result;
    }
}

$watcher = watcherFixture($con);
$state = oldora_process_youtube_watcher(new WatcherFaultConnection($con, 'metadata'), $watcher);
watcherCheck($state === 'created', 'A watcher metadata failure preserves a created generation');
watcherCheck(watcherContent($con)['status'] === 'queued' && watcherContent($con)['provider_job_id'] === 'fixture-render-receipt', 'Metadata failure does not fail or lose a durable provider receipt');
watcherCheck(watcherJobCount($con) === 1 && watcherCredits($con) === 15, 'Scheduled post stays saved and credits are not refunded after durable start');
watcherCheck(watcherEvent($con)['status'] === 'generated' && watcherEvent($con)['error_message'] !== null, 'Generated event records a metadata error for review');
$watcher = $con->query('SELECT * FROM youtube_watchers WHERE id = 90')->fetch_assoc();
watcherCheck(oldora_process_youtube_watcher($con, $watcher) === 'duplicate' && $providerCalls === 1, 'Retrying a watcher after metadata failure never starts a duplicate render');
watcherCheck(watcherJobCount($con) === 1 && watcherCredits($con) === 15, 'Duplicate watcher check does not refund or duplicate a scheduled post');

$watcher = watcherFixture($con);
$providerMode = 'failure';
watcherReject(fn() => oldora_process_youtube_watcher($con, $watcher), 'Provider failure reports its error');
watcherCheck(watcherContent($con)['status'] === 'failed' && watcherCredits($con) === 20, 'Failed unsaved start returns its reserved credits');
watcherCheck(watcherEvent($con)['status'] === 'failed' && watcherJobCount($con) === 0, 'Failed provider start leaves no publishing job');
watcherCheck(oldora_process_youtube_watcher($con, $watcher) === 'duplicate' && watcherCredits($con) === 20 && $providerCalls === 1, 'Failed watcher event cannot refund twice or resubmit automatically');

$watcher = watcherFixture($con);
watcherReject(fn() => oldora_process_youtube_watcher(new WatcherFaultConnection($con, 'queue'), $watcher), 'Critical publish queue persistence failure is reported');
watcherCheck(watcherContent($con)['status'] === 'failed' && watcherContent($con)['provider_job_id'] === null, 'Generation link and publishing job are rolled back together');
watcherCheck(watcherJobCount($con) === 0 && watcherCredits($con) === 20 && watcherEvent($con)['status'] === 'failed', 'Unpersisted watcher request is refunded once after transaction rollback');

$watcher = watcherFixture($con);
watcherCheck(oldora_process_youtube_watcher(new WatcherFaultConnection($con, 'commit_response'), $watcher) === 'created', 'Interrupted commit response recognizes already durable generation');
watcherCheck(watcherContent($con)['status'] === 'queued' && watcherJobCount($con) === 1 && watcherCredits($con) === 15, 'Committed generation and schedule survive interrupted acknowledgement without a refund');
watcherCheck(watcherEvent($con)['status'] === 'generated', 'Interrupted acknowledgement preserves the generated event');

$watcher = watcherFixture($con);
$providerMode = 'late';
watcherReject(fn() => oldora_process_youtube_watcher($con, $watcher), 'Late provider receipt cannot revive a recovered stale start');
watcherCheck(watcherContent($con)['status'] === 'failed' && watcherContent($con)['provider_job_id'] === null, 'Recovered start stays failed after the provider eventually responds');
watcherCheck(watcherCredits($con) === 20 && watcherJobCount($con) === 0, 'Late provider response neither double-refunds nor schedules failed content');

$watcher = watcherFixture($con);
$watcher['destination_token_id'] = 91;
watcherReject(fn() => oldora_process_youtube_watcher($con, $watcher), 'Watcher cannot use another user destination token');
watcherCheck($providerCalls === 0 && watcherCredits($con) === 20 && watcherContent($con) === null, 'Ownership failure is rejected before generation or credit reservation');
$con->close();
echo "{$checks} watcher reliability checks passed\n";
