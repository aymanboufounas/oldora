<?php

// Isolated database and provider stubs: this test cannot generate or publish real media.
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/content.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (oldora_env('DB_NAME') !== 'oldora_dev') throw new RuntimeException('Run only in the isolated cloud development environment.');
$con = new mysqli(oldora_env('DB_HOST'), oldora_env('DB_USER'), oldora_env('DB_PASS'), 'oldora_dev');
$con->set_charset('utf8mb4');
$database = 'oldora_test_content_' . bin2hex(random_bytes(6));
$con->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$con->select_db($database);
$checks = 0;
function endpointCheck(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}

$config = [
    'DB_HOST' => oldora_env('DB_HOST'), 'DB_USER' => oldora_env('DB_USER'),
    'DB_PASS' => oldora_env('DB_PASS'), 'DB_NAME' => $database, 'APP_URL' => 'https://example.invalid',
    'VIDEO_PROVIDER' => 'moneyprinterturbo'
];
$runner = <<<'PHP'
function oldora_env_all() { return json_decode(getenv('OLDORA_TEST_CONFIGURATION'), true); }
function fixtureStartingTimeout($userId, $credits) {
    if (getenv('OLDORA_TEST_TIMEOUT') !== '1') return;
    $db = $GLOBALS['con'];
    $db->query("UPDATE content_items SET status = 'failed', error_message = 'Fixture startup timed out.' WHERE user_id = " . (int) $userId . " AND status = 'starting'");
    if ($db->affected_rows === 1) $db->query('UPDATE users SET credits = credits + ' . (int) $credits . ' WHERE id = ' . (int) $userId);
    $db->query("UPDATE publish_jobs p INNER JOIN content_items c ON c.id = p.content_id SET p.status = 'failed' WHERE c.status = 'failed' AND p.status = 'waiting_media'");
}
function oldora_generate_image($prompt, $userId, array $options = []) {
    if (getenv('OLDORA_TEST_FAIL') === '1') throw new RuntimeException('Fixture generation failure.');
    fixtureStartingTimeout($userId, 1);
    return ['url' => 'https://example.invalid/fixture.png', 'path' => '/fixture/image.png'];
}
function oldora_start_video($prompt, $provider = null, array $options = []) {
    if (getenv('OLDORA_TEST_FAIL') === '1') throw new RuntimeException('Fixture generation failure.');
    fixtureStartingTimeout(1, 5);
    return ['id' => 'fixture-job', 'status' => 'queued', 'progress' => 0];
}
ini_set('session.save_path', '/tmp');
session_id('contentfixture' . bin2hex(random_bytes(8)));
session_start();
$_SESSION = ['email' => 'content-test@example.invalid', 'csrf_token' => 'fixture-csrf'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/content-create.php';
$_POST = json_decode(stream_get_contents(STDIN), true);
require '/workspace/oldora/content-create.php';
PHP;

function endpointRequest(array $post, bool $fail = false, bool $timeout = false): array
{
    global $runner, $config;
    $process = proc_open([PHP_BINARY, '-r', $runner], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), [
        'OLDORA_TEST_CONFIGURATION' => json_encode($config), 'OLDORA_TEST_FAIL' => $fail ? '1' : '0',
        'OLDORA_TEST_TIMEOUT' => $timeout ? '1' : '0'
    ]);
    if (!is_resource($process)) throw new RuntimeException('Could not start endpoint test.');
    fwrite($pipes[0], json_encode($post)); fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_array($data = json_decode($raw, true))) {
        throw new RuntimeException('Endpoint fixture failed: ' . $errors . $raw);
    }
    return $data;
}

try {
    $con->multi_query(file_get_contents(dirname(__DIR__) . '/database/development.sql'));
    do { if ($result = $con->store_result()) $result->free(); } while ($con->more_results() && $con->next_result());
    oldora_ensure_content_schema($con);
    $con->query("INSERT INTO users (id, full_name, email, password, credits) VALUES (1, 'Content fixture', 'content-test@example.invalid', 'fixture-only', 20), (2, 'Other fixture', 'other-content@example.invalid', 'fixture-only', 20)");
    $con->query("INSERT INTO user_tokens (id, user_email, platform, access_token) VALUES (1, 'content-test@example.invalid', 'instagram', 'fixture-only'), (2, 'other-content@example.invalid', 'instagram', 'fixture-only'), (3, 'content-test@example.invalid', 'youtube', 'fixture-only')");
    $base = ['csrf_token' => 'fixture-csrf', 'media_type' => 'video', 'prompt' => 'A useful coffee tip', 'caption' => 'A cup of inspiration'];
    $invalid = [
        ['csrf_token' => 'wrong'], ['prompt' => ['array']], ['prompt' => str_repeat('م', 4001)],
        ['caption' => str_repeat('م', 2201)], ['language' => ['ar']], ['voice' => 'injected'],
        ['brand_color' => 'url(injected)'], ['privacy_level' => 'INVALID'],
        ['youtube_privacy' => 'PUBLIC_TO_EVERYONE'], ['youtube_privacy' => ['public']],
        ['token_ids' => '1'], ['token_ids' => ['0']], ['token_ids' => ['1'], 'publish_consent' => '0'],
        ['token_ids' => ['2'], 'publish_consent' => '1'],
        ['media_type' => 'image', 'token_ids' => ['3'], 'publish_consent' => '1'],
        ['media_type' => 'image', 'image_aspect' => 'portrait', 'token_ids' => ['1'], 'publish_consent' => '1'],
        ['scheduled_at' => 'tomorrow'], ['scheduled_at' => '2026-02-31T20:00'],
        ['scheduled_at' => '2000-01-01T20:00'], ['scheduled_at' => '2099-01-01T20:00', 'timezone' => 'Invalid/Timezone']
    ];
    foreach ($invalid as $override) {
        $result = endpointRequest(array_replace($base, $override));
        endpointCheck($result['ok'] === false, 'Invalid generation/publishing input must be rejected.');
    }
    endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 20, 'Preflight failures do not charge credits.');
    endpointCheck((int) $con->query('SELECT COUNT(*) AS total FROM content_items')->fetch_assoc()['total'] === 0, 'Preflight failures do not create jobs.');

    $video = endpointRequest($base + ['language' => 'ar-MA', 'voice' => 'masculine', 'token_ids' => ['1'], 'publish_consent' => '1']);
    endpointCheck($video['ok'] && $video['status'] === 'processing' && $video['publish_jobs'] === 1, 'Validated video queues one selected account.');
    $content = $con->query('SELECT * FROM content_items WHERE id = ' . (int) $video['content_id'])->fetch_assoc();
    $options = json_decode($content['generation_options'], true);
    endpointCheck($options['language'] === 'ar-MA' && $options['voice'] === 'masculine', 'Actual creative options are persisted with the content.');
    endpointCheck($content['provider_job_id'] === 'fixture-job', 'Provider job links to the queued creation.');
    endpointCheck($con->query('SELECT status FROM publish_jobs WHERE content_id = ' . (int) $video['content_id'])->fetch_assoc()['status'] === 'waiting_media', 'Publishing waits for generated video.');
    endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 15, 'Video reserves the server-owned cost.');
    endpointCheck($video['balance_credits'] === 15, 'Queued video returns the authoritative reserved balance.');

    $image = endpointRequest(array_replace($base, ['media_type' => 'image', 'image_aspect' => 'square', 'token_ids' => ['1'], 'publish_consent' => '1']));
    endpointCheck($image['ok'] && $image['status'] === 'ready', 'Square image creation completes.');
    endpointCheck($con->query('SELECT status FROM publish_jobs WHERE content_id = ' . (int) $image['content_id'])->fetch_assoc()['status'] === 'pending', 'Ready images release the selected publish job.');
    endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 14, 'Image generation charges once.');
    endpointCheck($image['balance_credits'] === 14, 'Completed image returns the current server balance.');

    $con->query("CREATE TRIGGER fail_queue_release BEFORE UPDATE ON publish_jobs FOR EACH ROW BEGIN IF OLD.status = 'waiting_media' AND NEW.status = 'pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture queue release failed.'; END IF; END");
    $durable = endpointRequest(array_replace($base, ['media_type' => 'image', 'token_ids' => ['1'], 'publish_consent' => '1']));
    endpointCheck($durable['ok'] && $durable['status'] === 'ready' && $durable['asset_url'] === 'https://example.invalid/fixture.png', 'Queue-release failure preserves and returns already-created media.');
    endpointCheck($con->query('SELECT status FROM content_items WHERE id = ' . (int) $durable['content_id'])->fetch_assoc()['status'] === 'ready', 'Durably saved media is never relabeled failed.');
    endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 13 && $durable['balance_credits'] === 13, 'Finalization failure does not refund delivered media.');
    endpointCheck($con->query('SELECT status FROM publish_jobs WHERE content_id = ' . (int) $durable['content_id'])->fetch_assoc()['status'] === 'waiting_media', 'Persisted publishing intent survives queue-release failure.');
    $con->query('DROP TRIGGER fail_queue_release');
    require_once dirname(__DIR__) . '/includes/automation_worker.php';
    oldora_run_content_worker($con, [
        'watchers' => fn() => [], 'poll' => fn() => ['status' => 'queued', 'progress' => 0],
        'publish' => fn() => 'fixture-confirmed-post'
    ]);
    endpointCheck($con->query('SELECT status FROM publish_jobs WHERE content_id = ' . (int) $durable['content_id'])->fetch_assoc()['status'] === 'published', 'The next worker pass repairs and publishes the retained ready-media job.');
    endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 13, 'Worker recovery does not change the generation charge.');

    $failure = endpointRequest($base + ['token_ids' => ['1'], 'publish_consent' => '1'], true);
    endpointCheck(!$failure['ok'] && $failure['message'] === 'Fixture generation failure.', 'Generation failures are returned.');
    endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 13, 'Failed generation refunds its reservation.');
    endpointCheck($con->query('SELECT status FROM publish_jobs WHERE content_id = ' . (int) $failure['content_id'])->fetch_assoc()['status'] === 'failed', 'Failed generation cancels its waiting publishing job.');
    foreach (['image', 'video'] as $type) {
        $late = endpointRequest(array_replace($base, ['media_type' => $type]), false, true);
        endpointCheck(!$late['ok'] && $late['message'] === 'Fixture startup timed out.', 'Late provider responses preserve the prior worker failure.');
        endpointCheck($con->query('SELECT status FROM content_items WHERE id = ' . (int) $late['content_id'])->fetch_assoc()['status'] === 'failed', 'Late provider output cannot resurrect a refunded generation.');
        endpointCheck((int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'] === 13, 'A previously refunded generation is not refunded twice.');
    }
    $youtubePrivate = endpointRequest($base + ['token_ids' => ['3'], 'publish_consent' => '1', 'privacy_level' => 'PUBLIC_TO_EVERYONE']);
    endpointCheck($youtubePrivate['ok'], 'YouTube accepts a generation with default visibility.');
    endpointCheck($con->query('SELECT privacy_level FROM publish_jobs WHERE content_id = ' . (int) $youtubePrivate['content_id'])->fetch_assoc()['privacy_level'] === 'private', 'YouTube defaults to private independently of TikTok privacy.');
    $youtubePublic = endpointRequest($base + ['token_ids' => ['3'], 'publish_consent' => '1', 'youtube_privacy' => 'public', 'privacy_level' => 'SELF_ONLY']);
    endpointCheck($youtubePublic['ok'], 'Explicit YouTube public visibility is accepted.');
    endpointCheck($con->query('SELECT privacy_level FROM publish_jobs WHERE content_id = ' . (int) $youtubePublic['content_id'])->fetch_assoc()['privacy_level'] === 'public', 'Explicit YouTube public visibility is stored independently of TikTok privacy.');
    echo "$checks content endpoint checks passed\n";
} finally {
    $con->query("DROP DATABASE `{$database}`");
}
