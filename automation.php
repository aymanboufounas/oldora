<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/content.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}

oldora_ensure_content_schema($con);
$csrf = oldora_csrf_token();

$userStmt = $con->prepare(
    'SELECT id, full_name, credits
     FROM users
     WHERE email = ?
     LIMIT 1'
);

$userStmt->bind_param('s', $_SESSION['email']);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: login-user.php');
    exit;
}

$userId = (int)$user['id'];

function automationFlash($message, $type = 'success')
{
    $_SESSION['automation_flash'] = [
        'message' => (string)$message,
        'type' => $type === 'error' ? 'error' : 'success'
    ];
}

function automationRedirect()
{
    header('Location: automation.php');
    exit;
}

function getAutomationJob($con, $jobId, $userId)
{
    $stmt = $con->prepare(
        'SELECT p.*, c.status AS media_status
         FROM publish_jobs p
         INNER JOIN content_items c ON c.id = p.content_id
         WHERE p.id = ? AND p.user_id = ?
         LIMIT 1'
    );

    $stmt->bind_param('ii', $jobId, $userId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $job ?: null;
}

function getNextPublishStatus($mediaStatus)
{
    return $mediaStatus === 'ready'
        ? 'pending'
        : 'waiting_media';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!oldora_verify_csrf($_POST['csrf_token'] ?? '')) {
        automationFlash(
            'Your session expired. Refresh the page and try again.',
            'error'
        );

        automationRedirect();
    }

    $action = strtolower(
        trim((string)($_POST['action'] ?? ''))
    );

    $jobIds = [];

    if (isset($_POST['job_id'])) {
        $jobIds[] = (int)$_POST['job_id'];
    }

    if (
        isset($_POST['selected_jobs']) &&
        is_array($_POST['selected_jobs'])
    ) {
        foreach ($_POST['selected_jobs'] as $selectedId) {
            $jobIds[] = (int)$selectedId;
        }
    }

    $jobIds = array_slice(
        array_values(
            array_unique(
                array_filter($jobIds)
            )
        ),
        0,
        100
    );

    if (!$jobIds) {
        automationFlash(
            'Select at least one publishing job.',
            'error'
        );

        automationRedirect();
    }

    $changed = 0;
    $skipped = 0;

    try {
        foreach ($jobIds as $jobId) {
            $job = getAutomationJob(
                $con,
                $jobId,
                $userId
            );

            if (!$job) {
                $skipped++;
                continue;
            }

            $status = (string)$job['status'];
            $mediaStatus = (string)$job['media_status'];

            if ($action === 'pause') {
                if (
                    !in_array(
                        $status,
                        ['pending', 'waiting_media'],
                        true
                    )
                ) {
                    $skipped++;
                    continue;
                }

                $stmt = $con->prepare(
                    "UPDATE publish_jobs
                     SET status = 'paused'
                     WHERE id = ?
                     AND user_id = ?
                     AND status IN ('pending','waiting_media')"
                );

                $stmt->bind_param(
                    'ii',
                    $jobId,
                    $userId
                );
            } elseif ($action === 'resume') {
                if ($status !== 'paused') {
                    $skipped++;
                    continue;
                }

                $nextStatus = getNextPublishStatus(
                    $mediaStatus
                );

                $stmt = $con->prepare(
                    "UPDATE publish_jobs
                     SET status = ?,
                         scheduled_at = CASE
                            WHEN scheduled_at < UTC_TIMESTAMP()
                            THEN UTC_TIMESTAMP()
                            ELSE scheduled_at
                         END,
                         last_error = NULL
                     WHERE id = ?
                     AND user_id = ?
                     AND status = 'paused'"
                );

                $stmt->bind_param(
                    'sii',
                    $nextStatus,
                    $jobId,
                    $userId
                );
            } elseif ($action === 'publish_now') {
                if (
                    !in_array(
                        $status,
                        [
                            'pending',
                            'waiting_media',
                            'paused',
                            'failed'
                        ],
                        true
                    )
                ) {
                    $skipped++;
                    continue;
                }

                $nextStatus = getNextPublishStatus(
                    $mediaStatus
                );

                $stmt = $con->prepare(
                    "UPDATE publish_jobs
                     SET status = ?,
                         scheduled_at = UTC_TIMESTAMP(),
                         attempts = 0,
                         last_error = NULL
                     WHERE id = ?
                     AND user_id = ?
                     AND status IN (
                        'pending',
                        'waiting_media',
                        'paused',
                        'failed'
                     )"
                );

                $stmt->bind_param(
                    'sii',
                    $nextStatus,
                    $jobId,
                    $userId
                );
            } elseif ($action === 'retry') {
                if ($status !== 'failed') {
                    $skipped++;
                    continue;
                }

                $nextStatus = getNextPublishStatus(
                    $mediaStatus
                );

                $stmt = $con->prepare(
                    "UPDATE publish_jobs
                     SET status = ?,
                         attempts = 0,
                         last_error = NULL,
                         scheduled_at = UTC_TIMESTAMP()
                     WHERE id = ?
                     AND user_id = ?
                     AND status = 'failed'"
                );

                $stmt->bind_param(
                    'sii',
                    $nextStatus,
                    $jobId,
                    $userId
                );
            } elseif ($action === 'cancel') {
                if (
                    !in_array(
                        $status,
                        [
                            'pending',
                            'waiting_media',
                            'paused',
                            'failed'
                        ],
                        true
                    )
                ) {
                    $skipped++;
                    continue;
                }

                $stmt = $con->prepare(
                    "UPDATE publish_jobs
                     SET status = 'cancelled',
                         last_error = NULL
                     WHERE id = ?
                     AND user_id = ?
                     AND status IN (
                        'pending',
                        'waiting_media',
                        'paused',
                        'failed'
                     )"
                );

                $stmt->bind_param(
                    'ii',
                    $jobId,
                    $userId
                );
            } elseif ($action === 'delete') {
                if (
                    !in_array(
                        $status,
                        ['cancelled', 'failed'],
                        true
                    )
                ) {
                    $skipped++;
                    continue;
                }

                $stmt = $con->prepare(
                    "DELETE FROM publish_jobs
                     WHERE id = ?
                     AND user_id = ?
                     AND status IN ('cancelled','failed')"
                );

                $stmt->bind_param(
                    'ii',
                    $jobId,
                    $userId
                );
            } elseif ($action === 'reschedule') {
                if (count($jobIds) !== 1) {
                    throw new RuntimeException(
                        'Rescheduling supports one job at a time.'
                    );
                }

                if (
                    !in_array(
                        $status,
                        [
                            'pending',
                            'waiting_media',
                            'paused',
                            'failed'
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'This job can no longer be rescheduled.'
                    );
                }

                $scheduledRaw = trim(
                    (string)($_POST['scheduled_at'] ?? '')
                );

                $timezoneName = trim(
                    (string)($_POST['timezone'] ?? 'UTC')
                );

                if ($scheduledRaw === '') {
                    throw new RuntimeException(
                        'Choose a new publishing date and time.'
                    );
                }

                try {
                    $timezone = new DateTimeZone(
                        $timezoneName
                    );

                    $scheduled = new DateTimeImmutable(
                        $scheduledRaw,
                        $timezone
                    );

                    $scheduledUtc = $scheduled
                        ->setTimezone(
                            new DateTimeZone('UTC')
                        )
                        ->format('Y-m-d H:i:s');
                } catch (Throwable $ignored) {
                    throw new RuntimeException(
                        'The selected date, time, or timezone is invalid.'
                    );
                }

                if (strtotime($scheduledUtc) < time() - 60) {
                    throw new RuntimeException(
                        'The publishing time cannot be in the past.'
                    );
                }

                $nextStatus = getNextPublishStatus(
                    $mediaStatus
                );

                $stmt = $con->prepare(
                    'UPDATE publish_jobs
                     SET status = ?,
                         scheduled_at = ?,
                         attempts = 0,
                         last_error = NULL
                     WHERE id = ?
                     AND user_id = ?'
                );

                $stmt->bind_param(
                    'ssii',
                    $nextStatus,
                    $scheduledUtc,
                    $jobId,
                    $userId
                );
            } else {
                throw new RuntimeException(
                    'Unknown automation action.'
                );
            }

            $stmt->execute();
            $changed += max(0, $stmt->affected_rows);
            $stmt->close();
        }

        $message =
            $changed .
            ' job' .
            ($changed === 1 ? '' : 's') .
            ' updated.';

        if ($skipped > 0) {
            $message .=
                ' ' .
                $skipped .
                ' skipped because their status no longer allows this action.';
        }

        automationFlash(
            $message,
            $changed > 0 ? 'success' : 'error'
        );
    } catch (Throwable $error) {
        automationFlash(
            $error->getMessage(),
            'error'
        );
    }

    automationRedirect();
}

$flash = $_SESSION['automation_flash'] ?? null;
unset($_SESSION['automation_flash']);

$stats = [
    'pending' => 0,
    'published' => 0,
    'failed' => 0,
    'paused' => 0,
    'cancelled' => 0
];

$statStmt = $con->prepare(
    'SELECT status, COUNT(*) AS total
     FROM publish_jobs
     WHERE user_id = ?
     GROUP BY status'
);

$statStmt->bind_param('i', $userId);
$statStmt->execute();
$statResult = $statStmt->get_result();

while ($row = $statResult->fetch_assoc()) {
    $status = (string)$row['status'];
    $total = (int)$row['total'];

    if (
        in_array(
            $status,
            [
                'waiting_media',
                'pending',
                'publishing'
            ],
            true
        )
    ) {
        $stats['pending'] += $total;
    } elseif (isset($stats[$status])) {
        $stats[$status] += $total;
    }
}

$statStmt->close();

$jobs = [];

$jobStmt = $con->prepare(
    'SELECT
        p.*,
        c.media_type,
        c.prompt,
        c.title,
        c.asset_url,
        c.status AS media_status,
        t.channel_name,
        t.picture
     FROM publish_jobs p
     INNER JOIN content_items c
        ON c.id = p.content_id
     LEFT JOIN user_tokens t
        ON t.id = p.token_id
     WHERE p.user_id = ?
     ORDER BY p.id DESC
     LIMIT 100'
);

$jobStmt->bind_param('i', $userId);
$jobStmt->execute();
$jobResult = $jobStmt->get_result();

while ($row = $jobResult->fetch_assoc()) {
    $jobs[] = $row;
}

$jobStmt->close();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta name="viewport"
          content="width=device-width,initial-scale=1">

    <title>Automation Control | OLDORA</title>

    <link rel="icon"
          href="Logo.png"
          type="image/png">

    <link rel="preconnect"
          href="https://fonts.googleapis.com">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --bg: #050914;
            --panel: rgba(11,18,34,.82);
            --panel2: rgba(255,255,255,.045);
            --line: rgba(255,255,255,.1);
            --text: #f8faff;
            --muted: #8f9ab0;
            --cyan: #42e8e0;
            --blue: #6487ff;
            --purple: #a66cff;
            --green: #3ae79e;
            --red: #ff6384;
            --amber: #ffd166;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
            color: var(--text);
            background:
                radial-gradient(
                    circle at 8% 3%,
                    rgba(66,232,224,.15),
                    transparent 27%
                ),
                radial-gradient(
                    circle at 92% 10%,
                    rgba(166,108,255,.15),
                    transparent 30%
                ),
                var(--bg);
            font-family: Inter, sans-serif;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .wrap {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
            padding: 38px 32px 70px;
        }

        .head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 22px;
            margin-bottom: 25px;
        }

        .eyebrow {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--cyan);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .live {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 13px var(--green);
        }

        h1 {
            margin: 8px 0 6px;
            font-size: clamp(34px,4.2vw,55px);
            line-height: 1;
            letter-spacing: -.05em;
        }

        .head p {
            max-width: 680px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .quick-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 9px;
        }

        .quick-link {
            min-height: 42px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 13px;
            color: #dce5f5;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: rgba(255,255,255,.045);
            font-size: 10px;
            font-weight: 800;
            text-decoration: none;
            transition: .2s;
        }

        .quick-link:hover {
            color: #fff;
            border-color: rgba(66,232,224,.25);
            background: rgba(66,232,224,.08);
            transform: translateY(-2px);
        }

        .quick-link.primary {
            color: #061017;
            border-color: transparent;
            background:
                linear-gradient(
                    110deg,
                    var(--cyan),
                    #8cf7ef
                );
        }

        .flash {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 17px;
            padding: 13px 15px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 700;
        }

        .flash.success {
            color: #9af8d1;
            border: 1px solid rgba(58,231,158,.2);
            background: rgba(58,231,158,.09);
        }

        .flash.error {
            color: #ff9bb0;
            border: 1px solid rgba(255,99,132,.2);
            background: rgba(255,99,132,.09);
        }

        .stats {
            display: grid;
            grid-template-columns:
                repeat(4,minmax(0,1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .stat {
            position: relative;
            overflow: hidden;
            padding: 17px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
            box-shadow:
                0 16px 45px rgba(0,0,0,.16);
        }

        .stat::after {
            content: "";
            position: absolute;
            right: -22px;
            bottom: -28px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--glow);
            filter: blur(25px);
            opacity: .28;
        }

        .stat-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .stat small {
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .stat i {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            color: var(--tone);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 10px;
            background: rgba(255,255,255,.035);
        }

        .stat strong {
            display: block;
            margin-top: 8px;
            font-size: 27px;
        }

        .control-panel {
            margin-bottom: 14px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
            box-shadow:
                0 18px 55px rgba(0,0,0,.18);
        }

        .filters {
            display: grid;
            grid-template-columns:
                minmax(220px,1fr)
                180px
                180px
                auto;
            gap: 10px;
            align-items: center;
        }

        .control {
            width: 100%;
            height: 43px;
            padding: 0 12px;
            color: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            outline: 0;
            background: rgba(3,7,16,.7);
            font-size: 11px;
            color-scheme: dark;
        }

        .control:focus {
            border-color: rgba(66,232,224,.5);
            box-shadow:
                0 0 0 3px rgba(66,232,224,.06);
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #67748c;
            font-size: 12px;
        }

        .search-wrap .control {
            padding-left: 36px;
        }

        .filter-reset {
            height: 43px;
            padding: 0 14px;
            color: #b8c2d4;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255,255,255,.04);
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
        }

        .bulk-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 11px;
            padding-top: 11px;
            border-top:
                1px solid rgba(255,255,255,.065);
        }

        .bulk-count {
            margin-right: auto;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
        }

        .action-button {
            min-height: 36px;
            padding: 0 11px;
            color: #dbe4f2;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: rgba(255,255,255,.045);
            font-size: 9px;
            font-weight: 800;
            cursor: pointer;
            transition: .2s;
        }

        .action-button:hover {
            color: #fff;
            border-color: rgba(66,232,224,.24);
            background: rgba(66,232,224,.08);
        }

        .action-button.danger:hover {
            border-color: rgba(255,99,132,.25);
            background: rgba(255,99,132,.09);
        }

        .action-button:disabled {
            cursor: not-allowed;
            opacity: .35;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: var(--panel);
            box-shadow:
                0 22px 65px rgba(0,0,0,.21);
        }

        table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px 14px;
            text-align: left;
            border-bottom:
                1px solid rgba(255,255,255,.06);
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            color: #647189;
            background: rgba(8,14,29,.97);
            font-size: 8px;
            font-weight: 900;
            letter-spacing: .13em;
            text-transform: uppercase;
        }

        tbody tr {
            transition: .18s;
        }

        tbody tr:hover {
            background: rgba(255,255,255,.025);
        }

        .select-cell {
            width: 42px;
            text-align: center;
        }

        .job-check,
        .select-all {
            accent-color: var(--cyan);
        }

        .content-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 330px;
        }

        .thumb {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            flex: 0 0 48px;
            overflow: hidden;
            color: #627089;
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 13px;
            background: rgba(255,255,255,.045);
        }

        .thumb img,
        .thumb video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content-copy {
            min-width: 0;
        }

        .content-copy strong {
            display: block;
            overflow: hidden;
            color: #eef3ff;
            font-size: 10px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .content-copy small {
            display: block;
            margin-top: 4px;
            color: #68758d;
            font-size: 8px;
        }

        .account-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .account-pic {
            width: 28px;
            height: 28px;
            display: grid;
            place-items: center;
            overflow: hidden;
            flex: 0 0 28px;
            border-radius: 9px;
            background: rgba(255,255,255,.06);
        }

        .account-pic img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .account-cell span {
            max-width: 140px;
            overflow: hidden;
            font-size: 9px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .platform {
            font-size: 9px;
            text-transform: capitalize;
        }

        .platform i {
            margin-right: 6px;
        }

        .platform-youtube i {
            color: #ff526b;
        }

        .platform-instagram i {
            color: #df6bff;
        }

        .platform-tiktok i {
            color: #72f7ed;
        }

        .date-cell strong {
            display: block;
            font-size: 9px;
            font-weight: 700;
        }

        .date-cell small {
            display: block;
            margin-top: 3px;
            color: #69758c;
            font-size: 8px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,.06);
            font-size: 8px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .pill::before {
            content: "";
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 8px currentColor;
        }

        .pill.published {
            color: var(--green);
            background: rgba(58,231,158,.09);
        }

        .pill.failed {
            color: var(--red);
            background: rgba(255,99,132,.09);
        }

        .pill.pending,
        .pill.waiting_media,
        .pill.publishing {
            color: var(--amber);
            background: rgba(255,209,102,.08);
        }

        .pill.paused {
            color: #9aa8ff;
            background: rgba(100,135,255,.09);
        }

        .pill.cancelled {
            color: #7d8799;
            background: rgba(125,135,153,.09);
        }

        .details {
            max-width: 190px;
            overflow: hidden;
            color: #7f8ba1;
            font-size: 8px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .details.error {
            color: #ff8fa7;
        }

        .row-actions {
            display: flex;
            justify-content: flex-end;
            gap: 5px;
        }

        .row-action {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            color: #aab5c8;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 9px;
            background: rgba(255,255,255,.035);
            font-size: 9px;
            cursor: pointer;
        }

        .row-action:hover {
            color: #fff;
            border-color: rgba(66,232,224,.2);
            background: rgba(66,232,224,.07);
        }

        .row-action.danger:hover {
            border-color: rgba(255,99,132,.23);
            background: rgba(255,99,132,.08);
        }

        .empty {
            padding: 60px 20px;
            color: var(--muted);
            text-align: center;
        }

        .empty i {
            color: #59657b;
            font-size: 31px;
        }

        .empty p {
            margin: 10px 0 0;
            font-size: 12px;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 20000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(0,0,0,.72);
            backdrop-filter: blur(6px);
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-card {
            width: min(440px,100%);
            padding: 21px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: #0b1223;
            box-shadow:
                0 30px 90px rgba(0,0,0,.6);
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
        }

        .modal-head h3 {
            margin: 0;
            font-size: 17px;
        }

        .modal-close {
            width: 34px;
            height: 34px;
            color: #aab5c8;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(255,255,255,.04);
            cursor: pointer;
        }

        .modal-actions {
            display: flex;
            gap: 9px;
            margin-top: 17px;
        }

        .modal-actions button {
            flex: 1;
            height: 43px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
        }

        .cancel-modal {
            color: #c8d1e0;
            border: 1px solid var(--line);
            background: rgba(255,255,255,.04);
        }

        .save-modal {
            color: #061017;
            border: 0;
            background: var(--cyan);
        }

        html body.oldora-shared-shell {
            padding-left: 294px !important;
        }

        #oldoraSharedMenu.oldora-menu-sidebar {
            inset: 14px auto 14px 14px !important;
            width: 264px !important;
            height: calc(100vh - 28px) !important;
            padding: 13px !important;
            overflow: hidden !important;
            border: 1px solid rgba(122,231,255,.14) !important;
            border-radius: 27px !important;
            background:
                radial-gradient(
                    circle at 12% 2%,
                    rgba(0,221,255,.18),
                    transparent 27%
                ),
                radial-gradient(
                    circle at 100% 48%,
                    rgba(150,74,255,.15),
                    transparent 35%
                ),
                linear-gradient(
                    180deg,
                    rgba(7,16,34,.98),
                    rgba(4,8,22,.98)
                ) !important;
            box-shadow:
                0 30px 90px rgba(0,0,0,.55),
                inset 0 1px 0 rgba(255,255,255,.08) !important;
            backdrop-filter:
                blur(30px) saturate(135%) !important;
        }

        #oldoraSharedMenu::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 3px;
            border-radius: 27px 27px 0 0;
            background:
                linear-gradient(
                    90deg,
                    #23e9ff,
                    #6f7dff,
                    #ef35a8
                );
        }

        #oldoraSharedMenu .oldora-menu-brand {
            min-height: 64px !important;
            margin-bottom: 10px !important;
            padding: 8px !important;
            border: 1px solid rgba(255,255,255,.07);
            border-radius: 19px;
            background: rgba(255,255,255,.035);
        }

        #oldoraSharedMenu .oldora-brand-logo {
            width: 43px !important;
            height: 43px !important;
            flex-basis: 43px !important;
            border-radius: 14px !important;
        }

        #oldoraSharedMenu .oldora-balance-card {
            min-height: 65px !important;
            margin: 0 0 11px !important;
            padding: 11px 13px !important;
            border-radius: 18px !important;
            background:
                linear-gradient(
                    115deg,
                    rgba(25,220,255,.12),
                    rgba(101,93,255,.1)
                ) !important;
        }

        #oldoraSharedMenu .oldora-menu-link {
            min-height: 43px !important;
            gap: 10px !important;
            margin: 3px 0 !important;
            padding: 7px 9px !important;
            color: #8995ab !important;
            border: 1px solid transparent !important;
            border-radius: 14px !important;
            background: transparent !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            box-shadow: none !important;
        }

        #oldoraSharedMenu .oldora-menu-link > i {
            width: 30px !important;
            height: 30px !important;
            display: grid !important;
            place-items: center !important;
            flex: 0 0 30px !important;
            color: #71809a !important;
            border: 1px solid rgba(255,255,255,.065);
            border-radius: 10px;
            background: rgba(255,255,255,.035);
        }

        #oldoraSharedMenu .oldora-menu-link:hover {
            color: #fff !important;
            background: rgba(255,255,255,.045) !important;
        }

        #oldoraSharedMenu .oldora-menu-link.oldora-menu-active {
            color: #fff !important;
            border-color: rgba(63,221,255,.19) !important;
            background:
                linear-gradient(
                    100deg,
                    rgba(24,210,255,.16),
                    rgba(105,92,255,.12)
                ) !important;
        }

        #oldoraSharedMenu
        .oldora-menu-link.oldora-menu-active > i {
            color: #06111b !important;
            border-color: transparent !important;
            background:
                linear-gradient(
                    135deg,
                    #38efff,
                    #77f7df
                ) !important;
        }

        #oldoraSharedMenu
        .oldora-menu-link.oldora-menu-active::after {
            content: "";
            width: 6px;
            height: 6px;
            margin-left: auto;
            border-radius: 50%;
            background: #51f4d0;
            box-shadow: 0 0 12px #51f4d0;
        }

        #oldoraSharedMenu .oldora-menu-user {
            min-height: 60px !important;
            padding: 9px !important;
            border: 1px solid rgba(255,255,255,.075) !important;
            border-radius: 17px;
            background: rgba(255,255,255,.035);
        }

        @media (max-width: 1150px) {
            .stats {
                grid-template-columns: repeat(2,1fr);
            }

            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .quick-links {
                justify-content: flex-start;
            }
        }

        @media (max-width: 991.98px) {
            html body.oldora-shared-shell {
                padding-left: 0 !important;
                padding-top: 76px !important;
            }

            #oldoraSharedMenu.oldora-menu-sidebar {
                inset: 10px auto 10px 10px !important;
                width: min(86vw,286px) !important;
                height: calc(100vh - 20px) !important;
                transform:
                    translateX(calc(-100% - 24px)) !important;
            }

            body.oldora-menu-open
            #oldoraSharedMenu.oldora-menu-sidebar {
                transform: translateX(0) !important;
            }

            html body.oldora-shared-shell
            .oldora-mobile-header {
                inset: 10px 10px auto !important;
                height: 56px !important;
                border-radius: 18px !important;
                background: rgba(6,13,29,.92) !important;
            }
        }

        @media (max-width: 720px) {
            .wrap {
                padding: 24px 14px 50px;
            }

            .head {
                align-items: flex-start;
                flex-direction: column;
            }

            .quick-links {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .quick-link {
                justify-content: center;
            }

            .stats {
                grid-template-columns: 1fr 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .bulk-bar {
                align-items: stretch;
                flex-wrap: wrap;
            }

            .bulk-count {
                width: 100%;
                margin: 0;
            }

            .action-button {
                flex: 1;
            }

            .control-panel {
                padding: 11px;
            }
        }

        @media (max-width: 430px) {
            .stats,
            .quick-links {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 34px;
            }
        }
    </style>
</head>
<body>
<main class="wrap">
    <header class="head">
        <div>
            <div class="eyebrow">
                <span class="live"></span>
                Publishing control center
            </div>

            <h1>Automation queue</h1>

            <p>
                Control when and where your generated content is
                published. Pause jobs, publish immediately,
                reschedule, retry failures, or cancel queued posts.
            </p>
        </div>

        <nav class="quick-links">
            <a class="quick-link primary"
               href="studio.php">
                <i class="fa-solid fa-plus"></i>
                Create content
            </a>

            <a class="quick-link"
               href="home.php#youtube-watcher">
                <i class="fa-brands fa-youtube"></i>
                Channel Watch
            </a>

            <a class="quick-link"
               href="connect-platforms.php">
                <i class="fa-solid fa-link"></i>
                Accounts
            </a>
        </nav>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?php echo htmlspecialchars($flash['type']); ?>">
            <i class="fa-solid fa-<?php
            echo $flash['type'] === 'error'
                ? 'circle-exclamation'
                : 'circle-check';
            ?>"></i>

            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <article class="stat"
                 style="--tone:var(--amber);--glow:var(--amber)">
            <div class="stat-head">
                <small>Waiting or scheduled</small>
                <i class="fa-solid fa-clock"></i>
            </div>

            <strong><?php echo $stats['pending']; ?></strong>
        </article>

        <article class="stat"
                 style="--tone:var(--green);--glow:var(--green)">
            <div class="stat-head">
                <small>Published</small>
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <strong><?php echo $stats['published']; ?></strong>
        </article>

        <article class="stat"
                 style="--tone:#91a2ff;--glow:#6487ff">
            <div class="stat-head">
                <small>Paused</small>
                <i class="fa-solid fa-pause"></i>
            </div>

            <strong><?php echo $stats['paused']; ?></strong>
        </article>

        <article class="stat"
                 style="--tone:var(--red);--glow:var(--red)">
            <div class="stat-head">
                <small>Needs attention</small>
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <strong><?php echo $stats['failed']; ?></strong>
        </article>
    </section>

    <section class="control-panel">
        <div class="filters">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input class="control"
                       id="jobSearch"
                       type="search"
                       placeholder="Search prompt, account or platform...">
            </div>

            <select class="control"
                    id="statusFilter">

                <option value="">All statuses</option>
                <option value="pending-group">Waiting & scheduled</option>
                <option value="published">Published</option>
                <option value="paused">Paused</option>
                <option value="failed">Failed</option>
                <option value="cancelled">Cancelled</option>
            </select>

            <select class="control"
                    id="platformFilter">

                <option value="">All platforms</option>
                <option value="youtube">YouTube</option>
                <option value="instagram">Instagram</option>
                <option value="tiktok">TikTok</option>
            </select>

            <button class="filter-reset"
                    type="button"
                    id="resetFilters">

                <i class="fa-solid fa-rotate-left"></i>
                Reset
            </button>
        </div>

        <form method="post"
              id="bulkForm"
              class="bulk-bar">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo htmlspecialchars($csrf); ?>">

            <input type="hidden"
                   name="action"
                   id="bulkAction">

            <span class="bulk-count">
                <strong id="selectedCount">0</strong> selected ·
                <span id="visibleCount">
                    <?php echo count($jobs); ?>
                </span> visible
            </span>

            <button class="action-button"
                    type="button"
                    data-bulk="pause">
                <i class="fa-solid fa-pause"></i>
                Pause
            </button>

            <button class="action-button"
                    type="button"
                    data-bulk="resume">
                <i class="fa-solid fa-play"></i>
                Resume
            </button>

            <button class="action-button"
                    type="button"
                    data-bulk="publish_now">
                <i class="fa-solid fa-bolt"></i>
                Publish now
            </button>

            <button class="action-button"
                    type="button"
                    data-bulk="retry">
                <i class="fa-solid fa-rotate"></i>
                Retry
            </button>

            <button class="action-button danger"
                    type="button"
                    data-bulk="cancel">
                <i class="fa-solid fa-ban"></i>
                Cancel
            </button>
        </form>
    </section>

    <section class="table-wrap">
        <?php if (!$jobs): ?>
            <div class="empty">
                <i class="fa-solid fa-calendar-check"></i>
                <p>No publishing jobs yet.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="select-cell">
                            <input class="select-all"
                                   id="selectAll"
                                   type="checkbox"
                                   aria-label="Select visible jobs">
                        </th>

                        <th>Content</th>
                        <th>Account</th>
                        <th>Platform</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>

                <tbody id="jobRows">
                    <?php foreach ($jobs as $job): ?>
                        <?php
                        $jobStatus = (string)$job['status'];
                        $platform = strtolower(
                            (string)$job['platform']
                        );

                        $pendingGroup = in_array(
                            $jobStatus,
                            [
                                'waiting_media',
                                'pending',
                                'publishing'
                            ],
                            true
                        );

                        $canSelect = !in_array(
                            $jobStatus,
                            ['published', 'publishing'],
                            true
                        );

                        $title = trim(
                            (string)(
                                $job['title']
                                ?: $job['prompt']
                            )
                        );

                        $searchValue = strtolower(
                            $title .
                            ' ' .
                            ($job['channel_name'] ?? '') .
                            ' ' .
                            $platform
                        );
                        ?>

                        <tr class="job-row"
                            data-status="<?php echo htmlspecialchars($pendingGroup ? 'pending-group' : $jobStatus); ?>"
                            data-raw-status="<?php echo htmlspecialchars($jobStatus); ?>"
                            data-platform="<?php echo htmlspecialchars($platform); ?>"
                            data-search="<?php echo htmlspecialchars($searchValue); ?>">

                            <td class="select-cell">
                                <?php if ($canSelect): ?>
                                    <input class="job-check"
                                           type="checkbox"
                                           form="bulkForm"
                                           name="selected_jobs[]"
                                           value="<?php echo (int)$job['id']; ?>"
                                           aria-label="Select job <?php echo (int)$job['id']; ?>">
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="content-cell">
                                    <span class="thumb">
                                        <?php if (
                                            $job['asset_url'] &&
                                            $job['media_type'] === 'image'
                                        ): ?>
                                            <img src="<?php echo htmlspecialchars($job['asset_url']); ?>"
                                                 alt="">

                                        <?php elseif ($job['asset_url']): ?>
                                            <video src="<?php echo htmlspecialchars($job['asset_url']); ?>"
                                                   muted
                                                   preload="metadata"></video>
                                        <?php else: ?>
                                            <i class="fa-solid fa-<?php
                                            echo $job['media_type'] === 'video'
                                                ? 'film'
                                                : 'image';
                                            ?>"></i>
                                        <?php endif; ?>
                                    </span>

                                    <span class="content-copy">
                                        <strong title="<?php echo htmlspecialchars($title); ?>">
                                            <?php echo htmlspecialchars($title); ?>
                                        </strong>

                                        <small>
                                            Job #<?php echo (int)$job['id']; ?>
                                            · Media
                                            <?php echo htmlspecialchars($job['media_status']); ?>
                                        </small>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <div class="account-cell">
                                    <span class="account-pic">
                                        <?php if (!empty($job['picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($job['picture']); ?>"
                                                 alt="">
                                        <?php else: ?>
                                            <i class="fa-solid fa-user"></i>
                                        <?php endif; ?>
                                    </span>

                                    <span>
                                        <?php
                                        echo htmlspecialchars(
                                            $job['channel_name']
                                            ?: 'Disconnected account'
                                        );
                                        ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span class="platform platform-<?php echo htmlspecialchars($platform); ?>">
                                    <i class="fa-brands fa-<?php
                                    echo $platform === 'youtube'
                                        ? 'youtube'
                                        : (
                                            $platform === 'tiktok'
                                                ? 'tiktok'
                                                : 'instagram'
                                        );
                                    ?>"></i>

                                    <?php echo htmlspecialchars($platform); ?>
                                </span>
                            </td>

                            <td>
                                <div class="date-cell">
                                    <strong class="local-date"
                                            data-utc="<?php echo htmlspecialchars($job['scheduled_at']); ?> UTC">
                                        <?php echo htmlspecialchars($job['scheduled_at']); ?>
                                    </strong>

                                    <small class="local-zone">
                                        UTC
                                    </small>
                                </div>
                            </td>

                            <td>
                                <span class="pill <?php echo htmlspecialchars($jobStatus); ?>">
                                    <?php
                                    echo htmlspecialchars(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $jobStatus
                                        )
                                    );
                                    ?>
                                </span>
                            </td>

                            <td class="details <?php echo $job['last_error'] ? 'error' : ''; ?>"
                                title="<?php echo htmlspecialchars($job['last_error'] ?? ''); ?>">

                                <?php
                                echo htmlspecialchars(
                                    $job['last_error']
                                    ?: (
                                        $job['provider_publish_id']
                                        ?: '—'
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <div class="row-actions">
                                    <?php if (
                                        in_array(
                                            $jobStatus,
                                            [
                                                'pending',
                                                'waiting_media',
                                                'paused',
                                                'failed'
                                            ],
                                            true
                                        )
                                    ): ?>
                                        <button class="row-action publish-now"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                title="Publish now">

                                            <i class="fa-solid fa-bolt"></i>
                                        </button>

                                        <button class="row-action reschedule"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                data-time="<?php echo htmlspecialchars($job['scheduled_at']); ?>"
                                                title="Reschedule">

                                            <i class="fa-solid fa-calendar-days"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if (
                                        in_array(
                                            $jobStatus,
                                            ['pending', 'waiting_media'],
                                            true
                                        )
                                    ): ?>
                                        <button class="row-action simple-action"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                data-action="pause"
                                                title="Pause">

                                            <i class="fa-solid fa-pause"></i>
                                        </button>

                                    <?php elseif ($jobStatus === 'paused'): ?>
                                        <button class="row-action simple-action"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                data-action="resume"
                                                title="Resume">

                                            <i class="fa-solid fa-play"></i>
                                        </button>

                                    <?php elseif ($jobStatus === 'failed'): ?>
                                        <button class="row-action simple-action"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                data-action="retry"
                                                title="Retry">

                                            <i class="fa-solid fa-rotate"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if (
                                        in_array(
                                            $jobStatus,
                                            [
                                                'pending',
                                                'waiting_media',
                                                'paused',
                                                'failed'
                                            ],
                                            true
                                        )
                                    ): ?>
                                        <button class="row-action danger simple-action"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                data-action="cancel"
                                                title="Cancel">

                                            <i class="fa-solid fa-ban"></i>
                                        </button>

                                    <?php elseif (
                                        in_array(
                                            $jobStatus,
                                            ['cancelled', 'failed'],
                                            true
                                        )
                                    ): ?>
                                        <button class="row-action danger simple-action"
                                                type="button"
                                                data-id="<?php echo (int)$job['id']; ?>"
                                                data-action="delete"
                                                title="Delete">

                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>

<form method="post"
      id="singleActionForm"
      hidden>

    <input type="hidden"
           name="csrf_token"
           value="<?php echo htmlspecialchars($csrf); ?>">

    <input type="hidden"
           name="action"
           id="singleAction">

    <input type="hidden"
           name="job_id"
           id="singleJobId">
</form>

<div class="modal-backdrop"
     id="scheduleModal"
     aria-hidden="true">

    <div class="modal-card"
         role="dialog"
         aria-modal="true"
         aria-labelledby="scheduleTitle">

        <div class="modal-head">
            <h3 id="scheduleTitle">
                Reschedule publication
            </h3>

            <button class="modal-close"
                    type="button"
                    data-modal-close>

                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post"
              id="scheduleForm">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo htmlspecialchars($csrf); ?>">

            <input type="hidden"
                   name="action"
                   value="reschedule">

            <input type="hidden"
                   name="job_id"
                   id="scheduleJobId">

            <input type="hidden"
                   name="timezone"
                   id="scheduleTimezone"
                   value="UTC">

            <label for="newSchedule"
                   style="display:block;margin-bottom:7px;color:#cbd3e3;font-size:10px;font-weight:800">
                New date and time
            </label>

            <input class="control"
                   id="newSchedule"
                   name="scheduled_at"
                   type="datetime-local"
                   required>

            <div class="modal-actions">
                <button class="cancel-modal"
                        type="button"
                        data-modal-close>
                    Cancel
                </button>

                <button class="save-modal"
                        type="submit">
                    Save new time
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const search = document.getElementById("jobSearch");
    const status = document.getElementById("statusFilter");
    const platform = document.getElementById("platformFilter");

    const rows = Array.from(
        document.querySelectorAll(".job-row")
    );

    const selectAll =
        document.getElementById("selectAll");

    const checks = Array.from(
        document.querySelectorAll(".job-check")
    );

    const selectedCount =
        document.getElementById("selectedCount");

    const visibleCount =
        document.getElementById("visibleCount");

    const bulkForm =
        document.getElementById("bulkForm");

    const bulkAction =
        document.getElementById("bulkAction");

    const singleForm =
        document.getElementById("singleActionForm");

    const singleAction =
        document.getElementById("singleAction");

    const singleJob =
        document.getElementById("singleJobId");

    const modal =
        document.getElementById("scheduleModal");

    let timezone = "UTC";

    try {
        timezone =
            Intl.DateTimeFormat()
                .resolvedOptions()
                .timeZone || "UTC";
    } catch (error) {
        timezone = "UTC";
    }

    document.getElementById(
        "scheduleTimezone"
    ).value = timezone;

    document.querySelectorAll(
        ".local-date"
    ).forEach(function (element) {
        const utcValue = element.dataset.utc
            .replace(" ", "T")
            .replace(" UTC", "Z");

        const date = new Date(utcValue);

        if (!isNaN(date)) {
            element.textContent =
                new Intl.DateTimeFormat(
                    undefined,
                    {
                        dateStyle: "medium",
                        timeStyle: "short"
                    }
                ).format(date);

            element.nextElementSibling.textContent =
                timezone;
        }
    });

    function updateSelection() {
        const total = checks.filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        selectedCount.textContent = total;

        document.querySelectorAll(
            "[data-bulk]"
        ).forEach(function (button) {
            button.disabled = total === 0;
        });

        if (!selectAll) {
            return;
        }

        const visibleChecks = checks.filter(
            function (checkbox) {
                return checkbox
                    .closest("tr")
                    .style.display !== "none";
            }
        );

        selectAll.checked =
            visibleChecks.length > 0 &&
            visibleChecks.every(function (checkbox) {
                return checkbox.checked;
            });

        selectAll.indeterminate =
            visibleChecks.some(function (checkbox) {
                return checkbox.checked;
            }) &&
            !visibleChecks.every(function (checkbox) {
                return checkbox.checked;
            });
    }

    function filterRows() {
        const query =
            (search ? search.value : "")
                .toLowerCase()
                .trim();

        const selectedStatus =
            status ? status.value : "";

        const selectedPlatform =
            platform ? platform.value : "";

        let visible = 0;

        rows.forEach(function (row) {
            const show =
                (
                    !query ||
                    row.dataset.search.includes(query)
                ) &&
                (
                    !selectedStatus ||
                    row.dataset.status === selectedStatus
                ) &&
                (
                    !selectedPlatform ||
                    row.dataset.platform === selectedPlatform
                );

            row.style.display = show ? "" : "none";

            if (show) {
                visible++;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = visible;
        }

        updateSelection();
    }

    [search, status, platform].forEach(function (element) {
        if (!element) {
            return;
        }

        element.addEventListener(
            element.tagName === "INPUT"
                ? "input"
                : "change",
            filterRows
        );
    });

    document.getElementById(
        "resetFilters"
    ).addEventListener("click", function () {
        search.value = "";
        status.value = "";
        platform.value = "";
        filterRows();
    });

    checks.forEach(function (checkbox) {
        checkbox.addEventListener(
            "change",
            updateSelection
        );
    });

    if (selectAll) {
        selectAll.addEventListener(
            "change",
            function () {
                checks.forEach(function (checkbox) {
                    if (
                        checkbox.closest("tr").style.display
                        !== "none"
                    ) {
                        checkbox.checked =
                            selectAll.checked;
                    }
                });

                updateSelection();
            }
        );
    }

    document.querySelectorAll(
        "[data-bulk]"
    ).forEach(function (button) {
        button.addEventListener(
            "click",
            function () {
                const action =
                    button.dataset.bulk;

                const count = checks.filter(
                    function (checkbox) {
                        return checkbox.checked;
                    }
                ).length;

                if (!count) {
                    return;
                }

                if (
                    action === "cancel" &&
                    !confirm(
                        "Cancel " +
                        count +
                        " selected publishing jobs?"
                    )
                ) {
                    return;
                }

                bulkAction.value = action;
                bulkForm.submit();
            }
        );
    });

    function submitAction(jobId, action) {
        if (
            action === "cancel" &&
            !confirm("Cancel this publishing job?")
        ) {
            return;
        }

        if (
            action === "delete" &&
            !confirm("Permanently remove this job?")
        ) {
            return;
        }

        singleJob.value = jobId;
        singleAction.value = action;
        singleForm.submit();
    }

    document.querySelectorAll(
        ".simple-action"
    ).forEach(function (button) {
        button.addEventListener(
            "click",
            function () {
                submitAction(
                    button.dataset.id,
                    button.dataset.action
                );
            }
        );
    });

    document.querySelectorAll(
        ".publish-now"
    ).forEach(function (button) {
        button.addEventListener(
            "click",
            function () {
                if (
                    confirm(
                        "Publish this job as soon as its media is ready?"
                    )
                ) {
                    submitAction(
                        button.dataset.id,
                        "publish_now"
                    );
                }
            }
        );
    });

    function closeModal() {
        modal.classList.remove("open");
        modal.setAttribute(
            "aria-hidden",
            "true"
        );
    }

    document.querySelectorAll(
        ".reschedule"
    ).forEach(function (button) {
        button.addEventListener(
            "click",
            function () {
                document.getElementById(
                    "scheduleJobId"
                ).value = button.dataset.id;

                const utcDate = new Date(
                    button.dataset.time
                        .replace(" ", "T") + "Z"
                );

                if (!isNaN(utcDate)) {
                    const localDate = new Date(
                        utcDate.getTime() -
                        utcDate.getTimezoneOffset() *
                        60000
                    );

                    document.getElementById(
                        "newSchedule"
                    ).value = localDate
                        .toISOString()
                        .slice(0, 16);
                }

                modal.classList.add("open");

                modal.setAttribute(
                    "aria-hidden",
                    "false"
                );

                document.getElementById(
                    "newSchedule"
                ).focus();
            }
        );
    });

    document.querySelectorAll(
        "[data-modal-close]"
    ).forEach(function (button) {
        button.addEventListener(
            "click",
            closeModal
        );
    });

    modal.addEventListener(
        "click",
        function (event) {
            if (event.target === modal) {
                closeModal();
            }
        }
    );

    document.addEventListener(
        "keydown",
        function (event) {
            if (event.key === "Escape") {
                closeModal();
            }
        }
    );

    filterRows();
    updateSelection();
})();
</script>
</body>
</html>