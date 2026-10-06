<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/youtube_watcher.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}

oldora_ensure_youtube_watcher_schema($con);

$email = (string)$_SESSION['email'];

$userStmt = $con->prepare(
    'SELECT id, full_name, plan_type, credits
     FROM users
     WHERE email = ?
     LIMIT 1'
);
$userStmt->bind_param('s', $email);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: login-user.php');
    exit;
}

$userId = (int)$user['id'];
$planType = strtolower(
    trim((string)($user['plan_type'] ?? 'free'))
);

$limits = [
    'free' => 1,
    'basic' => 10,
    'pro' => 50,
    'unlimited' => 150,
    'elite' => 150
];

$limitPerPlatform = $limits[$planType] ?? 1;
$csrfToken = oldora_csrf_token();

/*
|--------------------------------------------------------------------------
| Secure unlink action
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'disconnect'
) {
    if (!oldora_verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['account_flash'] = [
            'type' => 'error',
            'message' => 'Your session expired. Refresh the page and try again.'
        ];

        header('Location: connect-platforms.php');
        exit;
    }

    $tokenId = filter_input(
        INPUT_POST,
        'token_id',
        FILTER_VALIDATE_INT
    );

    if (!$tokenId || $tokenId < 1) {
        $_SESSION['account_flash'] = [
            'type' => 'error',
            'message' => 'The selected account is invalid.'
        ];

        header('Location: connect-platforms.php');
        exit;
    }

    $tokenStmt = $con->prepare(
        'SELECT id, platform, channel_name
         FROM user_tokens
         WHERE id = ? AND user_email = ?
         LIMIT 1'
    );
    $tokenStmt->bind_param('is', $tokenId, $email);
    $tokenStmt->execute();
    $ownedToken = $tokenStmt->get_result()->fetch_assoc();
    $tokenStmt->close();

    if (!$ownedToken) {
        $_SESSION['account_flash'] = [
            'type' => 'error',
            'message' => 'Account not found or already disconnected.'
        ];

        header('Location: connect-platforms.php');
        exit;
    }

    try {
        $con->begin_transaction();

        /*
         * Cancel unfinished publishing jobs that use this account.
         */
        $cancelStmt = $con->prepare(
            "UPDATE publish_jobs
             SET
                status = 'cancelled',
                last_error = 'Destination account disconnected by user.'
             WHERE token_id = ?
             AND user_id = ?
             AND status IN (
                'pending',
                'waiting_media',
                'publishing',
                'paused'
             )"
        );

        $cancelStmt->bind_param(
            'ii',
            $tokenId,
            $userId
        );

        if (!$cancelStmt->execute()) {
            throw new RuntimeException(
                'Could not cancel scheduled publishing jobs.'
            );
        }

        $cancelStmt->close();

        /*
         * Pause Channel Watch routes using this destination.
         */
        $watchStmt = $con->prepare(
            "UPDATE youtube_watchers
             SET
                is_active = 0,
                last_error = 'Destination account disconnected by user.'
             WHERE destination_token_id = ?
             AND user_id = ?"
        );

        $watchStmt->bind_param(
            'ii',
            $tokenId,
            $userId
        );

        if (!$watchStmt->execute()) {
            throw new RuntimeException(
                'Could not pause Channel Watch routes.'
            );
        }

        $watchStmt->close();

        /*
         * Delete only a token owned by the logged-in user.
         */
        $deleteStmt = $con->prepare(
            'DELETE FROM user_tokens
             WHERE id = ? AND user_email = ?'
        );

        $deleteStmt->bind_param(
            'is',
            $tokenId,
            $email
        );

        if (
            !$deleteStmt->execute() ||
            $deleteStmt->affected_rows !== 1
        ) {
            throw new RuntimeException(
                'The account could not be disconnected.'
            );
        }

        $deleteStmt->close();
        $con->commit();

        $accountName = $ownedToken['channel_name']
            ?: ucfirst((string)$ownedToken['platform']);

        $_SESSION['account_flash'] = [
            'type' => 'success',
            'message' => $accountName .
                ' was disconnected successfully.'
        ];
    } catch (Throwable $exception) {
        $con->rollback();

        oldora_log(
            'accounts',
            'Account disconnect failed.',
            [
                'user_id' => $userId,
                'token_id' => $tokenId,
                'error' => $exception->getMessage()
            ]
        );

        $_SESSION['account_flash'] = [
            'type' => 'error',
            'message' => 'Disconnect failed. Please try again.'
        ];
    }

    header('Location: connect-platforms.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Flash and OAuth messages
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['account_flash'] ?? null;
unset($_SESSION['account_flash']);

$oauthMessages = [
    'youtube_connected' => [
        'success',
        'YouTube connected successfully.'
    ],
    'tiktok_connected' => [
        'success',
        'TikTok connected successfully.'
    ],
    'instagram_connected' => [
        'success',
        'Instagram connected successfully.'
    ],
    'disconnected' => [
        'success',
        'Account disconnected successfully.'
    ],
    'youtube_not_configured' => [
        'error',
        'YouTube connection is not configured yet.'
    ],
    'tiktok_not_configured' => [
        'error',
        'TikTok connection is not configured yet.'
    ],
    'instagram_not_configured' => [
        'error',
        'Instagram connection is not configured yet.'
    ],
    'invalid_oauth_state' => [
        'error',
        'The connection request expired. Please try again.'
    ],
    'youtube_cancelled' => [
        'error',
        'YouTube connection was cancelled.'
    ],
    'tiktok_cancelled' => [
        'error',
        'TikTok connection was cancelled.'
    ],
    'instagram_cancelled' => [
        'error',
        'Instagram connection was cancelled.'
    ],
    'youtube_failed' => [
        'error',
        'YouTube could not be connected.'
    ],
    'tiktok_failed' => [
        'error',
        'TikTok could not be connected.'
    ],
    'instagram_failed' => [
        'error',
        'Instagram could not be connected.'
    ]
];

$messageKey = (string)(
    $_GET['status'] ??
    $_GET['error'] ??
    ''
);

if (!$flash && isset($oauthMessages[$messageKey])) {
    $flash = [
        'type' => $oauthMessages[$messageKey][0],
        'message' => $oauthMessages[$messageKey][1]
    ];
}

/*
|--------------------------------------------------------------------------
| Load accounts
|--------------------------------------------------------------------------
*/

$accounts = [
    'youtube' => [],
    'tiktok' => [],
    'instagram' => []
];

$accountStmt = $con->prepare(
    'SELECT
        id,
        platform,
        channel_name,
        channel_id,
        picture,
        channel_pic,
        subscribers,
        views,
        expires_at,
        created_at
     FROM user_tokens
     WHERE user_email = ?
     ORDER BY created_at DESC, id DESC'
);

$accountStmt->bind_param('s', $email);
$accountStmt->execute();
$accountResult = $accountStmt->get_result();

while ($account = $accountResult->fetch_assoc()) {
    $platform = strtolower((string)$account['platform']);

    if (
        strpos($platform, 'you') !== false ||
        strpos($platform, 'tube') !== false
    ) {
        $accounts['youtube'][] = $account;
    } elseif (strpos($platform, 'tik') !== false) {
        $accounts['tiktok'][] = $account;
    } elseif (strpos($platform, 'inst') !== false) {
        $accounts['instagram'][] = $account;
    }
}

$accountStmt->close();

$platforms = [
    'youtube' => [
        'name' => 'YouTube Shorts',
        'icon' => 'fa-brands fa-youtube',
        'auth' => 'auth_youtube.php',
        'metric' => 'Subscribers',
        'description' =>
            'Publish generated Shorts and use this channel as an automation destination.'
    ],
    'tiktok' => [
        'name' => 'TikTok',
        'icon' => 'fa-brands fa-tiktok',
        'auth' => 'auth_tiktok.php',
        'metric' => 'Followers',
        'description' =>
            'Send generated vertical videos directly to your connected TikTok account.'
    ],
    'instagram' => [
        'name' => 'Instagram',
        'icon' => 'fa-brands fa-instagram',
        'auth' => 'auth_instagram.php',
        'metric' => 'Followers',
        'description' =>
            'Publish images, Reels and captions to a connected professional account.'
    ]
];

$connectedTotal =
    count($accounts['youtube']) +
    count($accounts['tiktok']) +
    count($accounts['instagram']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>Connected Accounts | OLDORA</title>

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

    <style>
        :root {
            --bg: #050914;
            --panel: rgba(11, 18, 34, .82);
            --panel2: rgba(17, 27, 48, .78);
            --line: rgba(255, 255, 255, .09);
            --text: #f8faff;
            --muted: #8d99b0;
            --cyan: #42e8e0;
            --blue: #6487ff;
            --purple: #a66cff;
            --red: #ff5577;
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
                    circle at 7% 4%,
                    rgba(66, 232, 224, .14),
                    transparent 27%
                ),
                radial-gradient(
                    circle at 94% 8%,
                    rgba(166, 108, 255, .16),
                    transparent 31%
                ),
                var(--bg);
            font-family: Inter, sans-serif;
        }

        button {
            font: inherit;
        }

        .wrap {
            width: 100%;
            max-width: 1450px;
            margin: 0 auto;
            padding: 38px 32px 70px;
        }

        .hero {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 22px;
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
            margin: 8px 0 7px;
            font-size: clamp(35px, 4.4vw, 58px);
            line-height: 1;
            letter-spacing: -.055em;
        }

        .lead {
            max-width: 680px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .plan {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 12px 15px;
            border: 1px solid var(--line);
            border-radius: 17px;
            background: rgba(255, 255, 255, .04);
        }

        .plan-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            color: #09101b;
            border-radius: 12px;
            background: linear-gradient(
                135deg,
                var(--cyan),
                #8df8e5
            );
        }

        .plan span {
            display: grid;
        }

        .plan strong {
            font-size: 11px;
        }

        .plan small {
            margin-top: 3px;
            color: var(--muted);
            font-size: 8px;
        }

        .notice {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 12px 15px;
            border: 1px solid;
            border-radius: 14px;
            font-size: 10px;
            font-weight: 700;
        }

        .notice.success {
            color: var(--green);
            border-color: rgba(58, 231, 158, .22);
            background: rgba(58, 231, 158, .07);
        }

        .notice.error {
            color: #ff91a8;
            border-color: rgba(255, 85, 119, .25);
            background: rgba(255, 85, 119, .07);
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 11px;
            margin-bottom: 15px;
        }

        .summary-card {
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 17px;
            background: var(--panel);
        }

        .summary-card small {
            display: block;
            color: var(--muted);
            font-size: 8px;
            font-weight: 800;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .summary-card strong {
            display: block;
            margin-top: 6px;
            font-size: 22px;
        }

        .summary-card i {
            margin-right: 7px;
            color: var(--cyan);
            font-size: 12px;
        }

        .platform-list {
            display: grid;
            gap: 15px;
        }

        .platform-card {
            position: relative;
            overflow: hidden;
            padding: 20px;
            border: 1px solid var(--line);
            border-radius: 23px;
            background: var(--panel);
            box-shadow: 0 24px 65px rgba(0, 0, 0, .18);
        }

        .platform-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 3px;
            background: var(--platform-color);
        }

        .platform-top {
            display: grid;
            grid-template-columns:
                minmax(260px, 1fr)
                minmax(220px, .7fr)
                auto;
            align-items: center;
            gap: 20px;
        }

        .platform-info {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .platform-icon {
            width: 50px;
            height: 50px;
            display: grid;
            place-items: center;
            flex: 0 0 50px;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 15px;
            background: var(--platform-bg);
            font-size: 22px;
        }

        .platform-copy {
            min-width: 0;
        }

        .platform-copy h2 {
            margin: 0;
            font-size: 16px;
        }

        .platform-copy p {
            max-width: 470px;
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 9px;
            line-height: 1.55;
        }

        .usage-head {
            display: flex;
            justify-content: space-between;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 8px;
        }

        .usage-head b {
            color: #dbe3f2;
        }

        .usage-track {
            height: 7px;
            overflow: hidden;
            border-radius: 99px;
            background: rgba(255, 255, 255, .07);
        }

        .usage-bar {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(
                90deg,
                var(--cyan),
                var(--blue),
                var(--purple)
            );
            box-shadow: 0 0 16px rgba(66, 232, 224, .22);
        }

        .action {
            min-width: 116px;
            height: 43px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 15px;
            border: 1px solid transparent;
            border-radius: 13px;
            font-size: 10px;
            font-weight: 900;
            text-decoration: none;
            cursor: pointer;
        }

        .action.connect {
            color: #06121a;
            background: linear-gradient(
                115deg,
                var(--cyan),
                #8cf6e8
            );
        }

        .action.upgrade {
            color: #ff91a8;
            border-color: rgba(255, 85, 119, .32);
            background: rgba(255, 85, 119, .07);
        }

        .accounts {
            display: grid;
            gap: 8px;
            margin-top: 17px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, .07);
        }

        .account {
            display: grid;
            grid-template-columns:
                minmax(220px, 1fr)
                repeat(2, minmax(90px, .22fr))
                auto;
            align-items: center;
            gap: 14px;
            padding: 11px 12px;
            border: 1px solid rgba(255, 255, 255, .055);
            border-radius: 15px;
            background: rgba(255, 255, 255, .026);
        }

        .identity {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        .avatar {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            object-fit: cover;
            flex: 0 0 40px;
            color: var(--platform-color);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 12px;
            background: var(--platform-bg);
        }

        .identity-copy {
            min-width: 0;
            display: grid;
        }

        .identity-copy strong {
            overflow: hidden;
            font-size: 10px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .identity-copy small {
            overflow: hidden;
            margin-top: 4px;
            color: var(--muted);
            font-size: 8px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .account-metric {
            display: grid;
        }

        .account-metric small {
            color: #657189;
            font-size: 7px;
            text-transform: uppercase;
        }

        .account-metric b {
            margin-top: 3px;
            font-size: 10px;
        }

        .unlink {
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0 12px;
            color: #ff7895;
            border: 1px solid rgba(255, 85, 119, .18);
            border-radius: 11px;
            background: rgba(255, 85, 119, .055);
            font-size: 9px;
            font-weight: 800;
            cursor: pointer;
        }

        .unlink:hover {
            color: #fff;
            border-color: rgba(255, 85, 119, .42);
            background: rgba(255, 85, 119, .14);
        }

        .empty {
            margin-top: 17px;
            padding: 18px;
            color: #718097;
            border: 1px dashed rgba(255, 255, 255, .09);
            border-radius: 14px;
            text-align: center;
            font-size: 9px;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 3000;
            display: none;
            place-items: center;
            padding: 18px;
            background: rgba(1, 5, 14, .78);
            backdrop-filter: blur(8px);
        }

        .modal-backdrop.open {
            display: grid;
        }

        .modal {
            width: min(100%, 430px);
            padding: 22px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 22px;
            background: #0d1628;
            box-shadow: 0 35px 100px rgba(0, 0, 0, .6);
        }

        .modal-icon {
            width: 45px;
            height: 45px;
            display: grid;
            place-items: center;
            color: #ff7895;
            border-radius: 14px;
            background: rgba(255, 85, 119, .09);
        }

        .modal h3 {
            margin: 14px 0 7px;
            font-size: 18px;
        }

        .modal p {
            margin: 0;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.7;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 20px;
        }

        .modal-btn {
            height: 39px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 11px;
            color: #dbe4f2;
            background: rgba(255, 255, 255, .05);
            font-size: 9px;
            font-weight: 800;
            cursor: pointer;
        }

        .modal-btn.danger {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(
                115deg,
                #ff4269,
                #d83279
            );
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
            border:
                1px solid rgba(122, 231, 255, .14) !important;
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
                inset 0 1px 0 rgba(255, 255, 255, .08)
                !important;
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
            color: #fff !important;
            background: rgba(255, 255, 255, .045) !important;
        }

        #oldoraSharedMenu
        .oldora-menu-link.oldora-menu-active {
            color: #fff !important;
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
            border:
                1px solid rgba(255, 255, 255, .075) !important;
            border-radius: 17px;
            background: rgba(255, 255, 255, .035);
        }

        @media (max-width: 1120px) {
            .summary {
                grid-template-columns: repeat(2, 1fr);
            }

            .platform-top {
                grid-template-columns:
                    minmax(240px, 1fr)
                    minmax(180px, .7fr);
            }

            .platform-top > .action {
                grid-column: 1 / -1;
                width: 100%;
            }

            .account {
                grid-template-columns:
                    minmax(200px, 1fr)
                    repeat(2, minmax(80px, .2fr))
                    auto;
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

            html body.oldora-shared-shell
            .oldora-mobile-header {
                inset: 10px 10px auto !important;
                height: 56px !important;
                border-radius: 18px !important;
                background: rgba(6, 13, 29, .92) !important;
            }
        }

        @media (max-width: 760px) {
            .wrap {
                padding: 25px 14px 50px;
            }

            .hero {
                align-items: flex-start;
                flex-direction: column;
            }

            .plan {
                width: 100%;
            }

            .platform-top {
                grid-template-columns: 1fr;
            }

            .platform-top > .action {
                grid-column: auto;
            }

            .usage {
                width: 100%;
            }

            .account {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .account-metric {
                display: none;
            }

            .platform-copy p {
                font-size: 8px;
            }
        }

        @media (max-width: 470px) {
            .summary {
                grid-template-columns: 1fr;
            }

            .platform-card {
                padding: 16px;
            }

            .platform-top > .action {
                width: 100%;
            }

            .account {
                gap: 8px;
                padding: 9px;
            }

            .unlink {
                width: 36px;
                padding: 0;
                font-size: 0;
            }

            .unlink i {
                font-size: 10px;
            }

            h1 {
                font-size: 35px;
            }
        }
    </style>
</head>

<body>
<main class="wrap">
    <header class="hero">
        <div>
            <div class="eyebrow">
                <span class="live"></span>
                Secure social connections
            </div>

            <h1>Connected accounts</h1>

            <p class="lead">
                Connect the channels OLDORA can publish to,
                monitor usage limits and safely remove access
                when you no longer need an account.
            </p>
        </div>

        <div class="plan">
            <span class="plan-icon">
                <i class="fa-solid fa-crown"></i>
            </span>

            <span>
                <strong>
                    <?php
                    echo htmlspecialchars(
                        ucfirst($planType)
                    );
                    ?>
                    plan
                </strong>

                <small>
                    <?php
                    echo number_format($limitPerPlatform);
                    ?>
                    accounts per platform
                </small>
            </span>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="notice <?php
            echo $flash['type'] === 'success'
                ? 'success'
                : 'error';
        ?>">
            <i class="fa-solid <?php
                echo $flash['type'] === 'success'
                    ? 'fa-circle-check'
                    : 'fa-triangle-exclamation';
            ?>"></i>

            <span>
                <?php
                echo htmlspecialchars($flash['message']);
                ?>
            </span>
        </div>
    <?php endif; ?>

    <section class="summary">
        <article class="summary-card">
            <small>
                <i class="fa-solid fa-link"></i>
                Total connected
            </small>

            <strong>
                <?php echo number_format($connectedTotal); ?>
            </strong>
        </article>

        <article class="summary-card">
            <small>
                <i class="fa-brands fa-youtube"></i>
                YouTube
            </small>

            <strong>
                <?php
                echo number_format(
                    count($accounts['youtube'])
                );
                ?>
            </strong>
        </article>

        <article class="summary-card">
            <small>
                <i class="fa-brands fa-tiktok"></i>
                TikTok
            </small>

            <strong>
                <?php
                echo number_format(
                    count($accounts['tiktok'])
                );
                ?>
            </strong>
        </article>

        <article class="summary-card">
            <small>
                <i class="fa-brands fa-instagram"></i>
                Instagram
            </small>

            <strong>
                <?php
                echo number_format(
                    count($accounts['instagram'])
                );
                ?>
            </strong>
        </article>
    </section>

    <section class="platform-list">
        <?php foreach ($platforms as $key => $platform): ?>
            <?php
            $count = count($accounts[$key]);

            $percent = min(
                100,
                $limitPerPlatform > 0
                    ? ($count / $limitPerPlatform) * 100
                    : 0
            );

            if ($key === 'youtube') {
                $color = '#ff4d67';
                $background = 'rgba(255,77,103,.09)';
            } elseif ($key === 'tiktok') {
                $color = '#42e8e0';
                $background = 'rgba(66,232,224,.08)';
            } else {
                $color = '#e95bb4';
                $background = 'rgba(233,91,180,.09)';
            }
            ?>

            <article
                class="platform-card"
                style="--platform-color:<?php
                    echo $color;
                ?>;--platform-bg:<?php
                    echo $background;
                ?>"
            >
                <div class="platform-top">
                    <div class="platform-info">
                        <span class="platform-icon">
                            <i class="<?php
                                echo htmlspecialchars(
                                    $platform['icon']
                                );
                            ?>"></i>
                        </span>

                        <div class="platform-copy">
                            <h2>
                                <?php
                                echo htmlspecialchars(
                                    $platform['name']
                                );
                                ?>
                            </h2>

                            <p>
                                <?php
                                echo htmlspecialchars(
                                    $platform['description']
                                );
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="usage">
                        <div class="usage-head">
                            <span>Account usage</span>

                            <b>
                                <?php echo $count; ?> /
                                <?php echo $limitPerPlatform; ?>
                            </b>
                        </div>

                        <div class="usage-track">
                            <div
                                class="usage-bar"
                                style="width:<?php
                                echo number_format(
                                    $percent,
                                    2,
                                    '.',
                                    ''
                                );
                                ?>%"
                            ></div>
                        </div>
                    </div>

                    <?php if ($count < $limitPerPlatform): ?>
                        <a
                            class="action connect"
                            href="<?php
                            echo htmlspecialchars(
                                $platform['auth']
                            );
                            ?>"
                        >
                            <i class="fa-solid fa-plus"></i>
                            Connect
                        </a>
                    <?php else: ?>
                        <a
                            class="action upgrade"
                            href="planing.php"
                        >
                            <i class="fa-solid fa-arrow-up-right-dots"></i>
                            Upgrade
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($accounts[$key]): ?>
                    <div class="accounts">
                        <?php foreach (
                            $accounts[$key] as $account
                        ): ?>
                            <?php
                            $image = trim(
                                (string)(
                                    $account['picture']
                                    ?: $account['channel_pic']
                                )
                            );
                            ?>

                            <div class="account">
                                <div class="identity">
                                    <?php if ($image !== ''): ?>
                                        <img
                                            class="avatar"
                                            src="<?php
                                            echo htmlspecialchars(
                                                $image
                                            );
                                            ?>"
                                            alt=""
                                        >
                                    <?php else: ?>
                                        <span class="avatar">
                                            <i class="<?php
                                                echo htmlspecialchars(
                                                    $platform['icon']
                                                );
                                            ?>"></i>
                                        </span>
                                    <?php endif; ?>

                                    <span class="identity-copy">
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $account['channel_name']
                                                ?: $platform['name'] .
                                                ' account'
                                            );
                                            ?>
                                        </strong>

                                        <small>
                                            <?php
                                            echo htmlspecialchars(
                                                $account['channel_id']
                                                ?: 'Connected to OLDORA'
                                            );
                                            ?>
                                        </small>
                                    </span>
                                </div>

                                <span class="account-metric">
                                    <small>
                                        <?php
                                        echo htmlspecialchars(
                                            $platform['metric']
                                        );
                                        ?>
                                    </small>

                                    <b>
                                        <?php
                                        echo number_format(
                                            (int)$account['subscribers']
                                        );
                                        ?>
                                    </b>
                                </span>

                                <span class="account-metric">
                                    <small>Views</small>

                                    <b>
                                        <?php
                                        echo number_format(
                                            (int)$account['views']
                                        );
                                        ?>
                                    </b>
                                </span>

                                <button
                                    class="unlink"
                                    type="button"
                                    data-unlink
                                    data-token-id="<?php
                                        echo (int)$account['id'];
                                    ?>"
                                    data-account-name="<?php
                                        echo htmlspecialchars(
                                            $account['channel_name']
                                            ?: $platform['name'],
                                            ENT_QUOTES
                                        );
                                    ?>"
                                >
                                    <i class="fa-solid fa-link-slash"></i>
                                    Unlink
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fa-solid fa-circle-plus"></i>
                        No
                        <?php
                        echo htmlspecialchars(
                            $platform['name']
                        );
                        ?>
                        account connected yet.
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>

<div
    class="modal-backdrop"
    id="unlinkModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="unlinkTitle"
>
    <div class="modal">
        <span class="modal-icon">
            <i class="fa-solid fa-link-slash"></i>
        </span>

        <h3 id="unlinkTitle">Disconnect account?</h3>

        <p>
            <strong id="unlinkAccountName">
                This account
            </strong>
            will no longer receive posts. Scheduled jobs for
            it will be cancelled and its Channel Watch routes
            will be paused.
        </p>

        <form method="post" id="unlinkForm">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php
                echo htmlspecialchars($csrfToken);
                ?>"
            >

            <input
                type="hidden"
                name="action"
                value="disconnect"
            >

            <input
                type="hidden"
                name="token_id"
                id="unlinkTokenId"
                value=""
            >

            <div class="modal-actions">
                <button
                    class="modal-btn"
                    type="button"
                    data-close-modal
                >
                    Keep account
                </button>

                <button
                    class="modal-btn danger"
                    type="submit"
                    id="confirmUnlink"
                >
                    <i class="fa-solid fa-link-slash"></i>
                    Disconnect
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('unlinkModal');
    const tokenInput = document.getElementById('unlinkTokenId');
    const accountName = document.getElementById('unlinkAccountName');
    const form = document.getElementById('unlinkForm');
    const confirmButton = document.getElementById('confirmUnlink');

    function closeModal() {
        modal.classList.remove('open');
        tokenInput.value = '';
        document.body.style.overflow = '';
    }

    document
        .querySelectorAll('[data-unlink]')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                tokenInput.value = button.dataset.tokenId;

                accountName.textContent =
                    button.dataset.accountName ||
                    'This account';

                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
            });
        });

    document
        .querySelectorAll('[data-close-modal]')
        .forEach(function (button) {
            button.addEventListener('click', closeModal);
        });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    form.addEventListener('submit', function () {
        confirmButton.disabled = true;

        confirmButton.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin"></i>' +
            ' Disconnecting...';
    });
})();
</script>
</body>
</html>