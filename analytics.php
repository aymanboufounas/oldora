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

$userStmt = $con->prepare(
    'SELECT id, full_name, credits FROM users WHERE email = ? LIMIT 1'
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
$allowedDays = [7, 14, 30, 90];
$days = (int)($_GET['days'] ?? 7);

if (!in_array($days, $allowedDays, true)) {
    $days = 7;
}

$allowedPlatforms = ['', 'youtube', 'instagram', 'tiktok'];
$platformFilter = strtolower(trim((string)($_GET['platform'] ?? '')));

if (!in_array($platformFilter, $allowedPlatforms, true)) {
    $platformFilter = '';
}

$fromUtc = gmdate(
    'Y-m-d 00:00:00',
    strtotime('-' . ($days - 1) . ' days')
);

$accounts = [];
$audience = 0;
$views = 0;

$accStmt = $con->prepare(
    'SELECT platform, channel_name, picture, subscribers, views
     FROM user_tokens
     WHERE user_email = ?
     ORDER BY platform, channel_name'
);
$accStmt->bind_param('s', $_SESSION['email']);
$accStmt->execute();
$accResult = $accStmt->get_result();

while ($row = $accResult->fetch_assoc()) {
    $accounts[] = $row;
    $audience += (int)$row['subscribers'];
    $views += (int)$row['views'];
}

$accStmt->close();

if ($platformFilter === '') {
    $createdStmt = $con->prepare(
        'SELECT COUNT(*) AS total
         FROM content_items
         WHERE user_id = ? AND created_at >= ?'
    );
    $createdStmt->bind_param('is', $userId, $fromUtc);
} else {
    $createdStmt = $con->prepare(
        'SELECT COUNT(DISTINCT c.id) AS total
         FROM content_items c
         INNER JOIN publish_jobs p ON p.content_id = c.id
         WHERE c.user_id = ?
         AND c.created_at >= ?
         AND p.platform = ?'
    );
    $createdStmt->bind_param(
        'iss',
        $userId,
        $fromUtc,
        $platformFilter
    );
}

$createdStmt->execute();
$createdTotal = (int)(
    $createdStmt->get_result()->fetch_assoc()['total'] ?? 0
);
$createdStmt->close();

$jobTotalsStmt = $con->prepare(
    'SELECT
        COUNT(*) AS total,
        SUM(status = \'published\') AS published,
        SUM(status = \'failed\') AS failed,
        SUM(status IN (
            \'pending\',
            \'waiting_media\',
            \'publishing\'
        )) AS pending,
        SUM(status = \'paused\') AS paused,
        SUM(status = \'cancelled\') AS cancelled
     FROM publish_jobs
     WHERE user_id = ?
     AND created_at >= ?
     AND (? = \'\' OR platform = ?)'
);

$jobTotalsStmt->bind_param(
    'isss',
    $userId,
    $fromUtc,
    $platformFilter,
    $platformFilter
);

$jobTotalsStmt->execute();
$jobTotals = $jobTotalsStmt->get_result()->fetch_assoc() ?: [];
$jobTotalsStmt->close();

$publishedTotal = (int)($jobTotals['published'] ?? 0);
$failedTotal = (int)($jobTotals['failed'] ?? 0);
$completedTotal = $publishedTotal + $failedTotal;

$successRate = $completedTotal > 0
    ? round(($publishedTotal / $completedTotal) * 100, 1)
    : 0;

$createdMap = [];
$publishedMap = [];
$failedMap = [];

if ($platformFilter === '') {
    $seriesCreatedStmt = $con->prepare(
        'SELECT DATE(created_at) AS day, COUNT(*) AS total
         FROM content_items
         WHERE user_id = ? AND created_at >= ?
         GROUP BY DATE(created_at)'
    );
    $seriesCreatedStmt->bind_param('is', $userId, $fromUtc);
} else {
    $seriesCreatedStmt = $con->prepare(
        'SELECT
            DATE(c.created_at) AS day,
            COUNT(DISTINCT c.id) AS total
         FROM content_items c
         INNER JOIN publish_jobs p ON p.content_id = c.id
         WHERE c.user_id = ?
         AND c.created_at >= ?
         AND p.platform = ?
         GROUP BY DATE(c.created_at)'
    );

    $seriesCreatedStmt->bind_param(
        'iss',
        $userId,
        $fromUtc,
        $platformFilter
    );
}

$seriesCreatedStmt->execute();
$seriesResult = $seriesCreatedStmt->get_result();

while ($row = $seriesResult->fetch_assoc()) {
    $createdMap[$row['day']] = (int)$row['total'];
}

$seriesCreatedStmt->close();

$publishSeriesStmt = $con->prepare(
    'SELECT
        DATE(COALESCE(
            published_at,
            updated_at,
            created_at
        )) AS day,
        SUM(status = \'published\') AS published,
        SUM(status = \'failed\') AS failed
     FROM publish_jobs
     WHERE user_id = ?
     AND created_at >= ?
     AND (? = \'\' OR platform = ?)
     GROUP BY DATE(COALESCE(
        published_at,
        updated_at,
        created_at
     ))'
);

$publishSeriesStmt->bind_param(
    'isss',
    $userId,
    $fromUtc,
    $platformFilter,
    $platformFilter
);

$publishSeriesStmt->execute();
$publishSeriesResult = $publishSeriesStmt->get_result();

while ($row = $publishSeriesResult->fetch_assoc()) {
    $publishedMap[$row['day']] = (int)$row['published'];
    $failedMap[$row['day']] = (int)$row['failed'];
}

$publishSeriesStmt->close();

$labels = [];
$createdSeries = [];
$publishedSeries = [];
$failedSeries = [];

for ($index = $days - 1; $index >= 0; $index--) {
    $date = gmdate(
        'Y-m-d',
        strtotime('-' . $index . ' days')
    );

    $labels[] = gmdate(
        $days > 30 ? 'M d' : 'D d',
        strtotime($date)
    );

    $createdSeries[] = $createdMap[$date] ?? 0;
    $publishedSeries[] = $publishedMap[$date] ?? 0;
    $failedSeries[] = $failedMap[$date] ?? 0;
}

$platformLabels = [];
$platformValues = [];

$platformStmt = $con->prepare(
    'SELECT platform, COUNT(*) AS total
     FROM publish_jobs
     WHERE user_id = ?
     AND status = \'published\'
     AND created_at >= ?
     GROUP BY platform
     ORDER BY total DESC'
);

$platformStmt->bind_param('is', $userId, $fromUtc);
$platformStmt->execute();
$platformResult = $platformStmt->get_result();

while ($row = $platformResult->fetch_assoc()) {
    $platformLabels[] = ucfirst((string)$row['platform']);
    $platformValues[] = (int)$row['total'];
}

$platformStmt->close();

$recentJobs = [];

$recentStmt = $con->prepare(
    'SELECT
        p.id,
        p.platform,
        p.status,
        p.scheduled_at,
        p.published_at,
        p.last_error,
        c.prompt,
        c.media_type,
        t.channel_name
     FROM publish_jobs p
     INNER JOIN content_items c ON c.id = p.content_id
     LEFT JOIN user_tokens t ON t.id = p.token_id
     WHERE p.user_id = ?
     AND p.created_at >= ?
     AND (? = \'\' OR p.platform = ?)
     ORDER BY p.id DESC
     LIMIT 12'
);

$recentStmt->bind_param(
    'isss',
    $userId,
    $fromUtc,
    $platformFilter,
    $platformFilter
);

$recentStmt->execute();
$recentResult = $recentStmt->get_result();

while ($row = $recentResult->fetch_assoc()) {
    $recentJobs[] = $row;
}

$recentStmt->close();

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'csv'
) {
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' .
        'oldora-analytics-' . gmdate('Y-m-d') . '.csv"'
    );

    $output = fopen('php://output', 'wb');

    fputcsv($output, [
        'Job ID',
        'Platform',
        'Account',
        'Media',
        'Status',
        'Scheduled UTC',
        'Published UTC',
        'Prompt',
        'Error'
    ]);

    $exportStmt = $con->prepare(
        'SELECT
            p.id,
            p.platform,
            p.status,
            p.scheduled_at,
            p.published_at,
            p.last_error,
            c.media_type,
            c.prompt,
            t.channel_name
         FROM publish_jobs p
         INNER JOIN content_items c ON c.id = p.content_id
         LEFT JOIN user_tokens t ON t.id = p.token_id
         WHERE p.user_id = ?
         AND p.created_at >= ?
         AND (? = \'\' OR p.platform = ?)
         ORDER BY p.id DESC'
    );

    $exportStmt->bind_param(
        'isss',
        $userId,
        $fromUtc,
        $platformFilter,
        $platformFilter
    );

    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();

    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['platform'],
            $row['channel_name'],
            $row['media_type'],
            $row['status'],
            $row['scheduled_at'],
            $row['published_at'],
            $row['prompt'],
            $row['last_error']
        ]);
    }

    $exportStmt->close();
    fclose($output);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>Analytics | OLDORA</title>

    <link
        rel="icon"
        href="Logo.png"
        type="image/png"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg: #050914;
            --panel: rgba(11, 18, 34, .80);
            --line: rgba(255, 255, 255, .10);
            --text: #f8faff;
            --muted: #929db2;
            --cyan: #42e8e0;
            --blue: #6487ff;
            --purple: #a66cff;
            --red: #ff6384;
            --green: #3ae79e;
            --amber: #ffd166;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text);
            background:
                radial-gradient(
                    circle at 8% 4%,
                    rgba(66, 232, 224, .14),
                    transparent 27%
                ),
                radial-gradient(
                    circle at 93% 8%,
                    rgba(166, 108, 255, .15),
                    transparent 31%
                ),
                var(--bg);
            font-family: Inter, sans-serif;
        }

        button,
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
            gap: 20px;
            margin-bottom: 23px;
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
            font-size: clamp(34px, 4.3vw, 56px);
            line-height: 1;
            letter-spacing: -.05em;
        }

        .lead {
            max-width: 700px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .filters {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            flex-wrap: wrap;
        }

        .filter {
            height: 42px;
            padding: 0 12px;
            color: #dce4f2;
            border: 1px solid var(--line);
            border-radius: 12px;
            outline: 0;
            background: rgba(255, 255, 255, .045);
            font-size: 10px;
            font-weight: 700;
            color-scheme: dark;
        }

        .filter:focus {
            border-color: rgba(66, 232, 224, .40);
        }

        .export {
            height: 42px;
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 0 13px;
            color: #061017;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(
                110deg,
                var(--cyan),
                #89f7ed
            );
            font-size: 10px;
            font-weight: 900;
            text-decoration: none;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .card {
            position: relative;
            overflow: hidden;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
            box-shadow: 0 20px 55px rgba(0, 0, 0, .17);
        }

        .card::after {
            content: "";
            position: absolute;
            right: -20px;
            bottom: -30px;
            width: 85px;
            height: 85px;
            border-radius: 50%;
            background: var(--glow);
            filter: blur(26px);
            opacity: .25;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card small {
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .card i {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            color: var(--tone);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 10px;
            background: rgba(255, 255, 255, .035);
        }

        .card strong {
            display: block;
            margin-top: 8px;
            font-size: 28px;
        }

        .card em {
            display: block;
            margin-top: 4px;
            color: #69768d;
            font-size: 8px;
            font-style: normal;
        }

        .grid {
            display: grid;
            grid-template-columns:
                minmax(0, 1.5fr)
                minmax(310px, .65fr);
            gap: 15px;
            margin-top: 15px;
        }

        .panel {
            padding: 20px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: var(--panel);
            box-shadow: 0 22px 60px rgba(0, 0, 0, .17);
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 17px;
        }

        .panel h2 {
            margin: 0;
            font-size: 15px;
        }

        .panel-tag {
            padding: 5px 8px;
            color: var(--cyan);
            border: 1px solid rgba(66, 232, 224, .14);
            border-radius: 99px;
            background: rgba(66, 232, 224, .06);
            font-size: 8px;
            font-weight: 800;
        }

        .chart {
            height: 330px;
        }

        .side-grid {
            display: grid;
            gap: 15px;
        }

        .small-chart {
            height: 215px;
        }

        .accounts {
            display: grid;
            gap: 9px;
            max-height: 330px;
            overflow: auto;
        }

        .account {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid rgba(255, 255, 255, .055);
            border-radius: 14px;
            background: rgba(255, 255, 255, .03);
        }

        .account img,
        .avatar {
            width: 37px;
            height: 37px;
            display: grid;
            place-items: center;
            object-fit: cover;
            flex: 0 0 37px;
            border-radius: 11px;
            background: rgba(255, 255, 255, .07);
        }

        .account-copy {
            min-width: 0;
            flex: 1;
            display: grid;
        }

        .account-copy strong {
            overflow: hidden;
            font-size: 10px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .account-copy small {
            margin-top: 3px;
            color: var(--muted);
            font-size: 8px;
            text-transform: capitalize;
        }

        .metric {
            text-align: right;
        }

        .metric b {
            display: block;
            color: #e8eefb;
            font-size: 10px;
        }

        .metric small {
            color: #68758c;
            font-size: 7px;
        }

        .activity {
            margin-top: 15px;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
        }

        table {
            width: 100%;
            min-width: 820px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
        }

        th {
            color: #657189;
            background: rgba(7, 13, 27, .95);
            font-size: 8px;
            letter-spacing: .11em;
            text-transform: uppercase;
        }

        td {
            font-size: 9px;
        }

        .job-title {
            max-width: 300px;
            overflow: hidden;
            color: #dfe7f5;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .platform {
            text-transform: capitalize;
        }

        .platform i {
            margin-right: 6px;
        }

        .status {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 99px;
            background: rgba(255, 255, 255, .06);
            font-size: 8px;
            text-transform: capitalize;
        }

        .status.published {
            color: var(--green);
            background: rgba(58, 231, 158, .08);
        }

        .status.failed {
            color: var(--red);
            background: rgba(255, 99, 132, .08);
        }

        .status.pending,
        .status.waiting_media,
        .status.publishing {
            color: var(--amber);
            background: rgba(255, 209, 102, .08);
        }

        .status.paused {
            color: #9ca8ff;
            background: rgba(100, 135, 255, .08);
        }

        .empty {
            padding: 30px;
            color: var(--muted);
            text-align: center;
            font-size: 10px;
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
            border: 1px solid rgba(122, 231, 255, .14) !important;
            border-radius: 27px !important;
            background:
                radial-gradient(
                    circle at 12% 2%,
                    rgba(0, 221, 255, .18),
                    transparent 27%
                ),
                radial-gradient(
                    circle at 100% 48%,
                    rgba(150, 74, 255, .15),
                    transparent 35%
                ),
                linear-gradient(
                    180deg,
                    rgba(7, 16, 34, .98),
                    rgba(4, 8, 22, .98)
                ) !important;
            box-shadow:
                0 30px 90px rgba(0, 0, 0, .55),
                inset 0 1px 0 rgba(255, 255, 255, .08) !important;
            backdrop-filter: blur(30px) !important;
        }

        #oldoraSharedMenu::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 3px;
            border-radius: 27px 27px 0 0;
            background: linear-gradient(
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
            border: 1px solid rgba(255, 255, 255, .07);
            border-radius: 19px;
            background: rgba(255, 255, 255, .035);
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
            background: linear-gradient(
                115deg,
                rgba(25, 220, 255, .12),
                rgba(101, 93, 255, .10)
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
            border: 1px solid rgba(255, 255, 255, .065);
            border-radius: 10px;
            background: rgba(255, 255, 255, .035);
        }

        #oldoraSharedMenu .oldora-menu-link:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, .045) !important;
        }

        #oldoraSharedMenu
        .oldora-menu-link.oldora-menu-active {
            color: #ffffff !important;
            border-color: rgba(63, 221, 255, .19) !important;
            background: linear-gradient(
                100deg,
                rgba(24, 210, 255, .16),
                rgba(105, 92, 255, .12)
            ) !important;
        }

        #oldoraSharedMenu
        .oldora-menu-link.oldora-menu-active > i {
            color: #06111b !important;
            border-color: transparent !important;
            background: linear-gradient(
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
            border: 1px solid rgba(255, 255, 255, .075) !important;
            border-radius: 17px;
            background: rgba(255, 255, 255, .035);
        }

        @media (max-width: 1100px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .side-grid {
                grid-template-columns: 1fr 1fr;
            }

            .accounts {
                max-height: 260px;
            }
        }

        @media (max-width: 991.98px) {
            html body.oldora-shared-shell {
                padding-left: 0 !important;
                padding-top: 76px !important;
            }

            #oldoraSharedMenu.oldora-menu-sidebar {
                inset: 10px auto 10px 10px !important;
                width: min(86vw, 286px) !important;
                height: calc(100vh - 20px) !important;
                transform:
                    translateX(calc(-100% - 24px)) !important;
            }

            body.oldora-menu-open
            #oldoraSharedMenu.oldora-menu-sidebar {
                transform: translateX(0) !important;
            }

            html body.oldora-shared-shell .oldora-mobile-header {
                inset: 10px 10px auto !important;
                height: 56px !important;
                border-radius: 18px !important;
                background: rgba(6, 13, 29, .92) !important;
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

            .filters {
                width: 100%;
                justify-content: flex-start;
            }

            .filter {
                flex: 1;
            }

            .export {
                width: 100%;
                justify-content: center;
            }

            .side-grid {
                grid-template-columns: 1fr;
            }

            .chart {
                height: 280px;
            }
        }

        @media (max-width: 460px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .filter {
                width: 100%;
                flex: auto;
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
                Real platform data
            </div>

            <h1>Content analytics</h1>

            <p class="lead">
                Track content production, publishing success,
                platform performance and connected audience
                using your real OLDORA data.
            </p>
        </div>

        <form class="filters" method="get">
            <select
                class="filter"
                name="days"
                aria-label="Date range"
            >
                <?php foreach ($allowedDays as $option): ?>
                    <option
                        value="<?php echo $option; ?>"
                        <?php echo $days === $option
                            ? 'selected'
                            : ''; ?>
                    >
                        Last <?php echo $option; ?> days
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                class="filter"
                name="platform"
                aria-label="Platform"
            >
                <option value="">All platforms</option>

                <option
                    value="youtube"
                    <?php echo $platformFilter === 'youtube'
                        ? 'selected'
                        : ''; ?>
                >
                    YouTube
                </option>

                <option
                    value="instagram"
                    <?php echo $platformFilter === 'instagram'
                        ? 'selected'
                        : ''; ?>
                >
                    Instagram
                </option>

                <option
                    value="tiktok"
                    <?php echo $platformFilter === 'tiktok'
                        ? 'selected'
                        : ''; ?>
                >
                    TikTok
                </option>
            </select>

            <button class="filter" type="submit">
                <i class="fa-solid fa-filter"></i>
                Apply
            </button>

            <a
                class="export"
                href="?days=<?php echo $days; ?>&platform=<?php
                    echo urlencode($platformFilter);
                ?>&export=csv"
            >
                <i class="fa-solid fa-download"></i>
                Export CSV
            </a>
        </form>
    </header>

    <section class="stats">
        <article
            class="card"
            style="--tone:var(--cyan);--glow:var(--cyan)"
        >
            <div class="card-head">
                <small>Connected audience</small>
                <i class="fa-solid fa-users"></i>
            </div>

            <strong>
                <?php echo number_format($audience); ?>
            </strong>

            <em>
                Across <?php echo count($accounts); ?>
                linked accounts
            </em>
        </article>

        <article
            class="card"
            style="--tone:var(--blue);--glow:var(--blue)"
        >
            <div class="card-head">
                <small>Stored platform views</small>
                <i class="fa-solid fa-eye"></i>
            </div>

            <strong>
                <?php echo number_format($views); ?>
            </strong>

            <em>Latest synchronized account totals</em>
        </article>

        <article
            class="card"
            style="--tone:var(--purple);--glow:var(--purple)"
        >
            <div class="card-head">
                <small>Content created</small>
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>

            <strong>
                <?php echo number_format($createdTotal); ?>
            </strong>

            <em>During the selected period</em>
        </article>

        <article
            class="card"
            style="--tone:var(--green);--glow:var(--green)"
        >
            <div class="card-head">
                <small>Publish success rate</small>
                <i class="fa-solid fa-arrow-trend-up"></i>
            </div>

            <strong>
                <?php echo number_format($successRate, 1); ?>%
            </strong>

            <em>
                <?php echo number_format($publishedTotal); ?>
                published ·
                <?php echo number_format($failedTotal); ?>
                failed
            </em>
        </article>
    </section>

    <div class="grid">
        <section class="panel">
            <div class="panel-head">
                <h2>Content and publishing activity</h2>

                <span class="panel-tag">
                    <?php echo $days; ?> days
                </span>
            </div>

            <div class="chart">
                <canvas id="activityChart"></canvas>
            </div>
        </section>

        <aside class="side-grid">
            <section class="panel">
                <div class="panel-head">
                    <h2>Job status</h2>

                    <span class="panel-tag">
                        <?php
                        echo number_format(
                            (int)($jobTotals['total'] ?? 0)
                        );
                        ?>
                        jobs
                    </span>
                </div>

                <div class="small-chart">
                    <canvas id="statusChart"></canvas>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <h2>Published by platform</h2>
                    <span class="panel-tag">Successful</span>
                </div>

                <div class="small-chart">
                    <canvas id="platformChart"></canvas>
                </div>
            </section>
        </aside>
    </div>

    <div class="grid">
        <section class="panel">
            <div class="panel-head">
                <h2>Connected accounts</h2>

                <span class="panel-tag">
                    <?php echo count($accounts); ?> connected
                </span>
            </div>

            <div class="accounts">
                <?php if (!$accounts): ?>
                    <div class="empty">
                        Connect an account to see platform metrics.
                    </div>
                <?php else: ?>
                    <?php foreach ($accounts as $account): ?>
                        <article class="account">
                            <?php if ($account['picture']): ?>
                                <img
                                    src="<?php
                                    echo htmlspecialchars(
                                        $account['picture']
                                    );
                                    ?>"
                                    alt=""
                                >
                            <?php else: ?>
                                <span class="avatar">
                                    <i class="fa-solid fa-user"></i>
                                </span>
                            <?php endif; ?>

                            <span class="account-copy">
                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $account['channel_name']
                                        ?: 'Account'
                                    );
                                    ?>
                                </strong>

                                <small>
                                    <?php
                                    echo htmlspecialchars(
                                        $account['platform']
                                    );
                                    ?>
                                </small>
                            </span>

                            <span class="metric">
                                <b>
                                    <?php
                                    echo number_format(
                                        (int)$account['subscribers']
                                    );
                                    ?>
                                </b>
                                <small>audience</small>
                            </span>

                            <span class="metric">
                                <b>
                                    <?php
                                    echo number_format(
                                        (int)$account['views']
                                    );
                                    ?>
                                </b>
                                <small>views</small>
                            </span>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Performance summary</h2>

                <span class="panel-tag">
                    <?php
                    echo $platformFilter
                        ? ucfirst($platformFilter)
                        : 'All platforms';
                    ?>
                </span>
            </div>

            <div class="accounts">
                <article class="account">
                    <span class="avatar">
                        <i class="fa-solid fa-clock"></i>
                    </span>

                    <span class="account-copy">
                        <strong>Waiting or scheduled</strong>
                        <small>Jobs not completed yet</small>
                    </span>

                    <span class="metric">
                        <b>
                            <?php
                            echo number_format(
                                (int)($jobTotals['pending'] ?? 0)
                            );
                            ?>
                        </b>
                        <small>jobs</small>
                    </span>
                </article>

                <article class="account">
                    <span class="avatar">
                        <i class="fa-solid fa-pause"></i>
                    </span>

                    <span class="account-copy">
                        <strong>Paused by user</strong>
                        <small>Resume from Automation</small>
                    </span>

                    <span class="metric">
                        <b>
                            <?php
                            echo number_format(
                                (int)($jobTotals['paused'] ?? 0)
                            );
                            ?>
                        </b>
                        <small>jobs</small>
                    </span>
                </article>

                <article class="account">
                    <span class="avatar">
                        <i class="fa-solid fa-ban"></i>
                    </span>

                    <span class="account-copy">
                        <strong>Cancelled</strong>
                        <small>Stopped before publishing</small>
                    </span>

                    <span class="metric">
                        <b>
                            <?php
                            echo number_format(
                                (int)($jobTotals['cancelled'] ?? 0)
                            );
                            ?>
                        </b>
                        <small>jobs</small>
                    </span>
                </article>
            </div>
        </section>
    </div>

    <section class="activity panel">
        <div class="panel-head">
            <h2>Recent publishing activity</h2>

            <a
                class="panel-tag"
                href="automation.php"
                style="text-decoration:none"
            >
                Open Automation
            </a>
        </div>

        <?php if (!$recentJobs): ?>
            <div class="empty">
                No publishing activity during this period.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Content</th>
                        <th>Account</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Scheduled</th>
                        <th>Published</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($recentJobs as $job): ?>
                        <?php
                        if ($job['platform'] === 'youtube') {
                            $platformIcon = 'youtube';
                        } elseif ($job['platform'] === 'tiktok') {
                            $platformIcon = 'tiktok';
                        } else {
                            $platformIcon = 'instagram';
                        }
                        ?>

                        <tr>
                            <td
                                class="job-title"
                                title="<?php
                                echo htmlspecialchars($job['prompt']);
                                ?>"
                            >
                                <?php
                                echo htmlspecialchars($job['prompt']);
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $job['channel_name']
                                    ?: 'Disconnected account'
                                );
                                ?>
                            </td>

                            <td class="platform">
                                <i class="fa-brands fa-<?php
                                    echo $platformIcon;
                                ?>"></i>

                                <?php
                                echo htmlspecialchars(
                                    $job['platform']
                                );
                                ?>
                            </td>

                            <td>
                                <span
                                    class="status <?php
                                    echo htmlspecialchars(
                                        $job['status']
                                    );
                                    ?>"
                                >
                                    <?php
                                    echo htmlspecialchars(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $job['status']
                                        )
                                    );
                                    ?>
                                </span>
                            </td>

                            <td
                                class="local-date"
                                data-utc="<?php
                                echo htmlspecialchars(
                                    $job['scheduled_at'] . ' UTC'
                                );
                                ?>"
                            >
                                <?php
                                echo htmlspecialchars(
                                    $job['scheduled_at']
                                );
                                ?>
                            </td>

                            <td
                                class="local-date"
                                data-utc="<?php
                                echo htmlspecialchars(
                                    $job['published_at']
                                        ? $job['published_at'] . ' UTC'
                                        : ''
                                );
                                ?>"
                            >
                                <?php
                                echo htmlspecialchars(
                                    $job['published_at'] ?: '—'
                                );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
const chartText = '#8d99b0';
const gridColor = 'rgba(255,255,255,.045)';

new Chart(
    document.getElementById('activityChart'),
    {
        type: 'line',

        data: {
            labels: <?php echo json_encode($labels); ?>,

            datasets: [
                {
                    label: 'Created',
                    data: <?php
                    echo json_encode($createdSeries);
                    ?>,
                    borderColor: '#42e8e0',
                    backgroundColor: 'rgba(66,232,224,.08)',
                    fill: true,
                    tension: .38,
                    borderWidth: 2,
                    pointRadius: <?php
                    echo $days > 30 ? 0 : 2;
                    ?>
                },
                {
                    label: 'Published',
                    data: <?php
                    echo json_encode($publishedSeries);
                    ?>,
                    borderColor: '#6487ff',
                    backgroundColor: 'rgba(100,135,255,.04)',
                    fill: true,
                    tension: .38,
                    borderWidth: 2,
                    pointRadius: <?php
                    echo $days > 30 ? 0 : 2;
                    ?>
                },
                {
                    label: 'Failed',
                    data: <?php
                    echo json_encode($failedSeries);
                    ?>,
                    borderColor: '#ff6384',
                    backgroundColor: 'transparent',
                    tension: .38,
                    borderWidth: 1.5,
                    pointRadius: <?php
                    echo $days > 30 ? 0 : 2;
                    ?>
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            interaction: {
                mode: 'index',
                intersect: false
            },

            plugins: {
                legend: {
                    labels: {
                        color: chartText,
                        boxWidth: 10,
                        usePointStyle: true
                    }
                }
            },

            scales: {
                x: {
                    ticks: {
                        color: chartText,
                        maxTicksLimit: 12
                    },
                    grid: {
                        color: gridColor
                    }
                },

                y: {
                    beginAtZero: true,
                    ticks: {
                        color: chartText,
                        precision: 0
                    },
                    grid: {
                        color: gridColor
                    }
                }
            }
        }
    }
);

new Chart(
    document.getElementById('statusChart'),
    {
        type: 'doughnut',

        data: {
            labels: [
                'Published',
                'Waiting',
                'Failed',
                'Paused',
                'Cancelled'
            ],

            datasets: [
                {
                    data: [
                        <?php
                        echo (int)(
                            $jobTotals['published'] ?? 0
                        );
                        ?>,
                        <?php
                        echo (int)(
                            $jobTotals['pending'] ?? 0
                        );
                        ?>,
                        <?php
                        echo (int)(
                            $jobTotals['failed'] ?? 0
                        );
                        ?>,
                        <?php
                        echo (int)(
                            $jobTotals['paused'] ?? 0
                        );
                        ?>,
                        <?php
                        echo (int)(
                            $jobTotals['cancelled'] ?? 0
                        );
                        ?>
                    ],

                    backgroundColor: [
                        '#3ae79e',
                        '#ffd166',
                        '#ff6384',
                        '#6487ff',
                        '#667085'
                    ],

                    borderWidth: 0,
                    hoverOffset: 5
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',

            plugins: {
                legend: {
                    position: 'bottom',

                    labels: {
                        color: chartText,
                        boxWidth: 8,
                        usePointStyle: true,

                        font: {
                            size: 9
                        }
                    }
                }
            }
        }
    }
);

new Chart(
    document.getElementById('platformChart'),
    {
        type: 'bar',

        data: {
            labels: <?php
            echo json_encode(
                $platformLabels ?: ['No data']
            );
            ?>,

            datasets: [
                {
                    label: 'Published',

                    data: <?php
                    echo json_encode(
                        $platformValues ?: [0]
                    );
                    ?>,

                    backgroundColor: [
                        'rgba(255,82,107,.72)',
                        'rgba(223,107,255,.72)',
                        'rgba(66,232,224,.72)'
                    ],

                    borderRadius: 8,
                    borderSkipped: false
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                }
            },

            scales: {
                x: {
                    ticks: {
                        color: chartText
                    },

                    grid: {
                        display: false
                    }
                },

                y: {
                    beginAtZero: true,

                    ticks: {
                        color: chartText,
                        precision: 0
                    },

                    grid: {
                        color: gridColor
                    }
                }
            }
        }
    }
);

document
    .querySelectorAll('.local-date')
    .forEach(function (element) {
        if (!element.dataset.utc) {
            return;
        }

        const value = element.dataset.utc
            .replace(' ', 'T')
            .replace(' UTC', 'Z');

        const date = new Date(value);

        if (!isNaN(date)) {
            element.textContent = new Intl.DateTimeFormat(
                undefined,
                {
                    dateStyle: 'medium',
                    timeStyle: 'short'
                }
            ).format(date);
        }
    });
</script>
</body>
</html>