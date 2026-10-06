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
$accounts = [];

$accountStmt = $con->prepare(
    'SELECT id, platform, channel_name, picture
     FROM user_tokens
     WHERE user_email = ?
     ORDER BY platform, channel_name'
);

$accountStmt->bind_param('s', $_SESSION['email']);
$accountStmt->execute();
$accountResult = $accountStmt->get_result();

while ($row = $accountResult->fetch_assoc()) {
    $accounts[] = $row;
}

$accountStmt->close();

$recent = [];

$recentStmt = $con->prepare(
    'SELECT *
     FROM content_items
     WHERE user_id = ?
     ORDER BY id DESC
     LIMIT 8'
);

$recentStmt->bind_param('i', $userId);
$recentStmt->execute();
$recentResult = $recentStmt->get_result();

while ($row = $recentResult->fetch_assoc()) {
    $recent[] = $row;
}

$recentStmt->close();

$requestedType = ($_GET['type'] ?? '') === 'image'
    ? 'image'
    : 'video';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta name="viewport"
          content="width=device-width,initial-scale=1">

    <title>Content Studio | OLDORA</title>

    <link rel="icon"
          href="Logo.png"
          type="image/png">

    <link rel="preconnect"
          href="https://fonts.googleapis.com">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --bg: #050914;
            --panel: rgba(12, 18, 34, 0.76);
            --panel-2: rgba(255, 255, 255, 0.045);
            --line: rgba(255, 255, 255, 0.1);
            --text: #f8faff;
            --muted: #929db2;
            --cyan: #42e8e0;
            --blue: #6487ff;
            --purple: #a66cff;
            --red: #ff6384;
            --green: #3ae79e;
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
                    circle at 8% 5%,
                    rgba(66, 232, 224, 0.14),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 92% 12%,
                    rgba(166, 108, 255, 0.14),
                    transparent 30%
                ),
                var(--bg);
            font-family: Inter, sans-serif;
        }

        .studio {
            width: 100%;
            max-width: 1480px;
            margin: 0 auto;
            padding: 42px 34px 70px;
        }

        .top {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
        }

        .eyebrow {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--cyan);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.13em;
            text-transform: uppercase;
        }

        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 14px var(--green);
        }

        h1 {
            margin: 8px 0 7px;
            font-size: clamp(30px, 4vw, 50px);
            letter-spacing: -0.045em;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
        }

        .credits {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 16px;
            border: 1px solid rgba(66, 232, 224, 0.18);
            border-radius: 17px;
            background: rgba(66, 232, 224, 0.07);
        }

        .credits i {
            color: #ffd166;
        }

        .credits span {
            display: grid;
        }

        .credits small {
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
        }

        .credits strong {
            font-size: 20px;
        }

        .layout {
            display: grid;
            grid-template-columns:
                minmax(0, 1.1fr)
                minmax(330px, 0.72fr);
            gap: 22px;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 25px;
            background: var(--panel);
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.24);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }

        .composer {
            padding: 24px;
        }

        .type-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 22px;
        }

        .type-option {
            position: relative;
        }

        .type-option input {
            position: absolute;
            opacity: 0;
        }

        .type-option label {
            min-height: 65px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--panel-2);
            cursor: pointer;
            transition: 0.2s;
        }

        .type-option label i {
            width: 37px;
            height: 37px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.055);
            color: #aab5c8;
        }

        .type-option label span {
            display: grid;
        }

        .type-option label small {
            margin-top: 2px;
            color: var(--muted);
            font-size: 10px;
        }

        .type-option input:checked + label {
            border-color: rgba(66, 232, 224, 0.45);
            background:
                linear-gradient(
                    135deg,
                    rgba(66, 232, 224, 0.12),
                    rgba(100, 135, 255, 0.08)
                );
            box-shadow:
                0 0 0 3px rgba(66, 232, 224, 0.05);
        }

        .type-option input:checked + label i {
            color: var(--cyan);
        }

        .field {
            margin-top: 17px;
        }

        .field > label,
        .section-label {
            display: block;
            margin: 0 0 8px;
            color: #cbd3e3;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .field textarea,
        .field input[type="datetime-local"],
        .field select {
            width: 100%;
            color: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            outline: 0;
            background: rgba(3, 7, 16, 0.7);
            font: inherit;
        }

        .field textarea {
            min-height: 142px;
            padding: 15px;
            resize: vertical;
            line-height: 1.6;
        }

        .field textarea.caption {
            min-height: 86px;
        }

        .field input[type="datetime-local"],
        .field select {
            height: 46px;
            padding: 0 13px;
            color-scheme: dark;
        }

        .field textarea:focus,
        .field input:focus,
        .field select:focus {
            border-color: rgba(66, 232, 224, 0.55);
            box-shadow:
                0 0 0 3px rgba(66, 232, 224, 0.06);
        }

        .counter {
            display: block;
            margin-top: 5px;
            color: #69748a;
            font-size: 10px;
            text-align: right;
        }

        .account-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 9px;
        }

        .account {
            position: relative;
        }

        .account input {
            position: absolute;
            opacity: 0;
        }

        .account label {
            min-height: 58px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.025);
            cursor: pointer;
        }

        .account img,
        .fallback {
            width: 35px;
            height: 35px;
            display: grid;
            place-items: center;
            object-fit: cover;
            border-radius: 11px;
            background: rgba(255, 255, 255, 0.08);
        }

        .account span {
            min-width: 0;
            display: grid;
        }

        .account strong {
            overflow: hidden;
            font-size: 11px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .account small {
            color: var(--muted);
            font-size: 9px;
            text-transform: capitalize;
        }

        .account input:checked + label {
            border-color: rgba(100, 135, 255, 0.55);
            background: rgba(100, 135, 255, 0.11);
        }

        .account.disabled {
            opacity: 0.35;
            pointer-events: none;
        }

        .empty {
            padding: 14px;
            color: var(--muted);
            border: 1px dashed var(--line);
            border-radius: 14px;
            font-size: 12px;
        }

        .schedule-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .check-row {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 17px;
            padding: 12px;
            color: #aab5c8;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.025);
            font-size: 11px;
            line-height: 1.5;
        }

        .check-row input {
            margin-top: 2px;
            accent-color: var(--cyan);
        }

        .submit {
            width: 100%;
            height: 54px;
            margin-top: 20px;
            color: #041018;
            border: 0;
            border-radius: 15px;
            background:
                linear-gradient(
                    100deg,
                    var(--cyan),
                    #8cf7ef 45%,
                    var(--blue)
                );
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            box-shadow:
                0 16px 36px rgba(66, 232, 224, 0.18);
            transition: 0.2s;
        }

        .submit:hover {
            transform: translateY(-2px);
        }

        .submit:disabled {
            cursor: not-allowed;
            opacity: 0.55;
            transform: none;
        }

        .preview {
            min-height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 19px 20px;
            border-bottom: 1px solid var(--line);
        }

        .preview-head strong {
            font-size: 13px;
        }

        .status {
            padding: 5px 9px;
            color: var(--muted);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            font-size: 9px;
            text-transform: uppercase;
        }

        .preview-stage {
            position: relative;
            min-height: 470px;
            display: grid;
            place-items: center;
            padding: 22px;
            background:
                radial-gradient(
                    circle at center,
                    rgba(100, 135, 255, 0.13),
                    transparent 60%
                );
        }

        .preview-stage img,
        .preview-stage video {
            width: 100%;
            max-height: 520px;
            object-fit: contain;
            border-radius: 18px;
        }

        .placeholder {
            color: var(--muted);
            text-align: center;
        }

        .placeholder i {
            width: 66px;
            height: 66px;
            display: grid;
            place-items: center;
            margin: 0 auto 15px;
            color: var(--cyan);
            border: 1px solid rgba(66, 232, 224, 0.18);
            border-radius: 21px;
            background: rgba(66, 232, 224, 0.07);
            font-size: 24px;
        }

        .placeholder strong {
            display: block;
            margin-bottom: 6px;
            color: #dae0ec;
        }

        .progress-wrap {
            display: none;
            width: min(80%, 320px);
            text-align: center;
        }

        .progress-bar {
            height: 7px;
            overflow: hidden;
            border-radius: 99px;
            background: rgba(255, 255, 255, 0.08);
        }

        .progress-fill {
            width: 8%;
            height: 100%;
            border-radius: inherit;
            background:
                linear-gradient(
                    90deg,
                    var(--cyan),
                    var(--blue)
                );
            transition: width 0.5s;
        }

        .progress-text {
            margin-top: 10px;
            color: var(--muted);
            font-size: 11px;
        }

        .message {
            display: none;
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 13px;
            font-size: 11px;
            line-height: 1.5;
        }

        .message.error {
            display: block;
            color: #ff9ab0;
            border: 1px solid rgba(255, 99, 132, 0.2);
            background: rgba(255, 99, 132, 0.09);
        }

        .message.success {
            display: block;
            color: #8cf6c9;
            border: 1px solid rgba(58, 231, 158, 0.18);
            background: rgba(58, 231, 158, 0.08);
        }

        .recent {
            margin-top: 24px;
        }

        .recent-title {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .recent-title h2 {
            margin: 0;
            font-size: 18px;
        }

        .recent-title span {
            color: var(--muted);
            font-size: 10px;
        }

        .recent-grid {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .item {
            min-width: 0;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.03);
        }

        .thumb {
            aspect-ratio: 4 / 3;
            display: grid;
            place-items: center;
            overflow: hidden;
            color: #59657b;
            background: rgba(255, 255, 255, 0.035);
        }

        .thumb img,
        .thumb video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .item-info {
            padding: 11px;
        }

        .item-info strong {
            display: block;
            overflow: hidden;
            font-size: 10px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .item-info span {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            color: var(--muted);
            font-size: 9px;
        }

        .state-ready {
            color: var(--green) !important;
        }

        .state-failed {
            color: var(--red) !important;
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
            border: 1px solid rgba(122, 231, 255, 0.14) !important;
            border-radius: 27px !important;
            background:
                radial-gradient(
                    circle at 12% 2%,
                    rgba(0, 221, 255, 0.18),
                    transparent 27%
                ),
                radial-gradient(
                    circle at 100% 48%,
                    rgba(150, 74, 255, 0.15),
                    transparent 35%
                ),
                linear-gradient(
                    180deg,
                    rgba(7, 16, 34, 0.98),
                    rgba(4, 8, 22, 0.98)
                ) !important;
            box-shadow:
                0 30px 90px rgba(0, 0, 0, 0.55),
                inset 0 1px 0 rgba(255, 255, 255, 0.08) !important;
            backdrop-filter: blur(30px) saturate(135%) !important;
            -webkit-backdrop-filter:
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
            opacity: 0.9;
        }

        #oldoraSharedMenu .oldora-menu-brand {
            min-height: 64px !important;
            margin-bottom: 10px !important;
            padding: 8px 8px 9px !important;
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 19px;
            background: rgba(255, 255, 255, 0.035);
        }

        #oldoraSharedMenu .oldora-menu-brand > a {
            gap: 12px !important;
        }

        #oldoraSharedMenu .oldora-brand-logo {
            width: 43px !important;
            height: 43px !important;
            flex-basis: 43px !important;
            border: 1px solid rgba(255, 255, 255, 0.18) !important;
            border-radius: 14px !important;
            box-shadow:
                0 9px 25px rgba(28, 220, 255, 0.24) !important;
        }

        #oldoraSharedMenu .oldora-menu-brand strong {
            font-size: 15px !important;
            letter-spacing: 0.17em !important;
        }

        #oldoraSharedMenu .oldora-menu-brand small {
            margin-top: 4px !important;
            color: #71809d !important;
            font-size: 9px !important;
        }

        #oldoraSharedMenu .oldora-balance-card {
            min-height: 65px !important;
            margin: 0 0 11px !important;
            padding: 11px 13px !important;
            border-color: rgba(42, 225, 255, 0.16) !important;
            border-radius: 18px !important;
            background:
                linear-gradient(
                    115deg,
                    rgba(25, 220, 255, 0.12),
                    rgba(101, 93, 255, 0.1)
                ) !important;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
        }

        #oldoraSharedMenu .oldora-balance-card small {
            color: #8190aa !important;
            font-size: 9px !important;
            letter-spacing: 0.12em !important;
        }

        #oldoraSharedMenu .oldora-balance-card strong {
            margin-top: 2px;
            color: #fff !important;
            font-size: 22px !important;
        }

        #oldoraSharedMenu .oldora-balance-card > i {
            width: 35px;
            height: 35px;
            display: grid;
            place-items: center;
            color: #42e8e0 !important;
            border: 1px solid rgba(42, 225, 255, 0.17);
            border-radius: 12px;
            background: rgba(42, 225, 255, 0.08);
            font-size: 13px;
        }

        #oldoraSharedMenu .oldora-menu-links {
            padding: 0 2px 10px !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            scrollbar-width: thin;
            scrollbar-color:
                rgba(255, 255, 255, 0.14)
                transparent;
        }

        #oldoraSharedMenu .oldora-menu-links::-webkit-scrollbar {
            width: 4px;
        }

        #oldoraSharedMenu .oldora-menu-links::-webkit-scrollbar-thumb {
            border-radius: 99px;
            background: rgba(255, 255, 255, 0.14);
        }

        #oldoraSharedMenu .oldora-menu-label {
            margin: 12px 10px 6px !important;
            color: #54617b !important;
            font-size: 9px !important;
            letter-spacing: 0.18em !important;
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
            transform: none !important;
        }

        #oldoraSharedMenu .oldora-menu-link > i {
            width: 30px !important;
            height: 30px !important;
            display: grid !important;
            place-items: center !important;
            flex: 0 0 30px !important;
            color: #71809a !important;
            border: 1px solid rgba(255, 255, 255, 0.065);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.035);
            font-size: 13px !important;
            transition: 0.2s ease;
        }

        #oldoraSharedMenu .oldora-menu-link:hover {
            color: #fff !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            background: rgba(255, 255, 255, 0.045) !important;
        }

        #oldoraSharedMenu .oldora-menu-link:hover > i {
            color: #40e8ff !important;
            border-color: rgba(64, 232, 255, 0.17) !important;
            background: rgba(64, 232, 255, 0.08) !important;
        }

        #oldoraSharedMenu .oldora-menu-link.oldora-menu-active {
            color: #fff !important;
            border-color: rgba(63, 221, 255, 0.19) !important;
            background:
                linear-gradient(
                    100deg,
                    rgba(24, 210, 255, 0.16),
                    rgba(105, 92, 255, 0.12)
                ) !important;
            box-shadow:
                0 12px 30px rgba(0, 0, 0, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.06) !important;
        }

        #oldoraSharedMenu .oldora-menu-link.oldora-menu-active > i {
            color: #06111b !important;
            border-color: transparent !important;
            background:
                linear-gradient(
                    135deg,
                    #38efff,
                    #77f7df
                ) !important;
            box-shadow:
                0 8px 20px rgba(52, 234, 255, 0.22);
        }

        #oldoraSharedMenu .oldora-menu-link.oldora-menu-active::after {
            content: "";
            width: 6px;
            height: 6px;
            margin-left: auto;
            border-radius: 50%;
            background: #51f4d0;
            box-shadow: 0 0 12px #51f4d0;
        }

        #oldoraSharedMenu .oldora-menu-badge {
            margin-left: auto !important;
            padding: 3px 7px !important;
            color: #ccfbff !important;
            border: 1px solid rgba(45, 228, 255, 0.16);
            background: rgba(45, 228, 255, 0.09) !important;
            font-size: 8px !important;
        }

        #oldoraSharedMenu
        .oldora-menu-link.oldora-menu-active
        .oldora-menu-badge {
            display: none !important;
        }

        #oldoraSharedMenu .oldora-menu-user {
            min-height: 60px !important;
            gap: 9px !important;
            padding: 9px !important;
            border: 1px solid rgba(255, 255, 255, 0.075) !important;
            border-radius: 17px;
            background: rgba(255, 255, 255, 0.035);
        }

        #oldoraSharedMenu .oldora-menu-avatar {
            width: 38px !important;
            height: 38px !important;
            border-radius: 12px !important;
            background:
                linear-gradient(
                    135deg,
                    #536dfe,
                    #a34eff
                ) !important;
        }

        #oldoraSharedMenu .oldora-menu-user strong {
            font-size: 11px !important;
        }

        #oldoraSharedMenu .oldora-menu-user small {
            margin-top: 2px;
            color: #6e7b93 !important;
            font-size: 9px !important;
        }

        #oldoraSharedMenu .oldora-menu-user > a {
            width: 33px !important;
            height: 33px !important;
            border-radius: 11px !important;
        }

        @media (max-width: 1100px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .preview-stage {
                min-height: 380px;
            }

            .recent-grid {
                grid-template-columns: repeat(3, 1fr);
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
                border-radius: 25px !important;
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
                padding: 0 10px !important;
                border: 1px solid rgba(255, 255, 255, 0.1) !important;
                border-radius: 18px !important;
                background: rgba(6, 13, 29, 0.92) !important;
                box-shadow:
                    0 15px 45px rgba(0, 0, 0, 0.32) !important;
            }

            html body.oldora-shared-shell
            .oldora-mobile-header
            .oldora-brand-logo {
                width: 34px !important;
                height: 34px !important;
                flex-basis: 34px !important;
                border-radius: 11px !important;
            }

            html body.oldora-shared-shell
            .oldora-mobile-header
            .oldora-mobile-brand strong {
                font-size: 13px !important;
                letter-spacing: 0.15em !important;
            }
        }

        @media (max-width: 680px) {
            .studio {
                padding: 22px 14px 50px !important;
            }

            .top {
                align-items: flex-start;
                flex-direction: column;
            }

            .credits {
                width: 100%;
                justify-content: center;
            }

            .layout {
                gap: 15px;
            }

            .panel {
                border-radius: 21px;
            }

            .composer {
                padding: 17px;
            }

            .type-switch,
            .account-grid,
            .schedule-row {
                grid-template-columns: 1fr;
            }

            .preview-stage {
                min-height: 320px;
            }

            .recent-grid {
                grid-template-columns: 1fr 1fr;
            }

            h1 {
                font-size: 34px;
            }
        }

        @media (max-width: 420px) {
            .recent-grid {
                grid-template-columns: 1fr;
            }

            .top h1 {
                font-size: 31px;
            }

            .composer {
                padding: 15px;
            }

            .type-option label {
                min-height: 60px;
            }
        }
    </style>
</head>
<body>
<main class="studio">
    <header class="top">
        <div>
            <div class="eyebrow">
                <span class="dot"></span>
                AI production workspace
            </div>

            <h1>Create once. Publish everywhere.</h1>

            <p class="subtitle">
                Generate a social image or short video, then publish
                now or add it to the schedule.
            </p>
        </div>

        <div class="credits">
            <i class="fa-solid fa-bolt"></i>

            <span>
                <small>Available credits</small>

                <strong id="creditCount">
                    <?php echo (int)$user['credits']; ?>
                </strong>
            </span>
        </div>
    </header>

    <div class="layout">
        <section class="panel composer">
            <form id="studioForm">
                <input type="hidden"
                       name="csrf_token"
                       value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

                <input type="hidden"
                       name="timezone"
                       id="timezone"
                       value="UTC">

                <div class="type-switch">
                    <div class="type-option">
                        <input type="radio"
                               id="typeVideo"
                               name="media_type"
                               value="video"
                               <?php echo $requestedType === 'video' ? 'checked' : ''; ?>>

                        <label for="typeVideo">
                            <i class="fa-solid fa-film"></i>

                            <span>
                                <strong>AI video</strong>
                                <small>5 credits · vertical short</small>
                            </span>
                        </label>
                    </div>

                    <div class="type-option">
                        <input type="radio"
                               id="typeImage"
                               name="media_type"
                               value="image"
                               <?php echo $requestedType === 'image' ? 'checked' : ''; ?>>

                        <label for="typeImage">
                            <i class="fa-solid fa-image"></i>

                            <span>
                                <strong>AI image</strong>
                                <small>1 credit · portrait post</small>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="field">
                    <label for="prompt">
                        Describe the content
                    </label>

                    <textarea id="prompt"
                              name="prompt"
                              maxlength="4000"
                              required
                              placeholder="Example: Cinematic portrait video of a freelance developer building a business at night, teal light, slow camera push, premium commercial look..."></textarea>

                    <span class="counter">
                        <span id="promptCount">0</span>/4000
                    </span>
                </div>

                <div class="field">
                    <label for="caption">
                        Caption and hashtags
                    </label>

                    <textarea class="caption"
                              id="caption"
                              name="caption"
                              maxlength="2200"
                              placeholder="Write the caption that will be published with this content..."></textarea>

                    <span class="counter">
                        <span id="captionCount">0</span>/2200
                    </span>
                </div>

                <div class="field">
                    <span class="section-label">
                        Publish to linked accounts
                    </span>

                    <?php if ($accounts): ?>
                        <div class="account-grid"
                             id="accountGrid">

                            <?php foreach ($accounts as $account): ?>
                                <?php
                                $platform = strtolower(
                                    (string)$account['platform']
                                );
                                ?>

                                <div class="account"
                                     data-platform="<?php echo htmlspecialchars($platform, ENT_QUOTES, 'UTF-8'); ?>">

                                    <input type="checkbox"
                                           name="token_ids[]"
                                           id="acc<?php echo (int)$account['id']; ?>"
                                           value="<?php echo (int)$account['id']; ?>">

                                    <label for="acc<?php echo (int)$account['id']; ?>">
                                        <?php if (!empty($account['picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($account['picture'], ENT_QUOTES, 'UTF-8'); ?>"
                                                 alt="">
                                        <?php else: ?>
                                            <span class="fallback">
                                                <i class="fa-brands fa-<?php
                                                echo $platform === 'youtube'
                                                    ? 'youtube'
                                                    : (
                                                        $platform === 'tiktok'
                                                            ? 'tiktok'
                                                            : 'instagram'
                                                    );
                                                ?>"></i>
                                            </span>
                                        <?php endif; ?>

                                        <span>
                                            <strong>
                                                <?php
                                                echo htmlspecialchars(
                                                    $account['channel_name']
                                                        ?: ucfirst($platform) . ' account',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>
                                            </strong>

                                            <small>
                                                <?php
                                                echo htmlspecialchars(
                                                    $platform,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>
                                            </small>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">
                            No accounts connected yet.

                            <a href="connect-platforms.php"
                               style="color:var(--cyan)">
                                Connect one now
                            </a>.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="schedule-row">
                    <div class="field">
                        <label for="scheduledAt">
                            Publish time
                        </label>

                        <input type="datetime-local"
                               id="scheduledAt"
                               name="scheduled_at">

                        <span class="counter">
                            Leave empty to publish as soon as media is ready.
                        </span>
                    </div>

                    <div class="field">
                        <label for="privacy">
                            TikTok privacy
                        </label>

                        <select name="privacy_level"
                                id="privacy">

                            <option value="SELF_ONLY">
                                Only me
                            </option>

                            <option value="PUBLIC_TO_EVERYONE">
                                Public
                            </option>

                            <option value="MUTUAL_FOLLOW_FRIENDS">
                                Friends
                            </option>

                            <option value="FOLLOWER_OF_CREATOR">
                                Followers
                            </option>
                        </select>
                    </div>
                </div>

                <label class="check-row">
                    <input type="checkbox"
                           name="publish_consent"
                           value="1"
                           id="publishConsent">

                    <span>
                        I confirm that OLDORA may send this AI-generated
                        content to the accounts I selected. Platform review,
                        permissions and publishing limits still apply.
                    </span>
                </label>

                <div class="message"
                     id="formMessage"></div>

                <button class="submit"
                        id="createButton"
                        type="submit">

                    <span>Create content</span>

                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </section>

        <aside class="panel preview">
            <div class="preview-head">
                <strong>Live result</strong>

                <span class="status"
                      id="previewStatus">
                    Ready
                </span>
            </div>

            <div class="preview-stage"
                 id="previewStage">

                <div class="placeholder"
                     id="placeholder">

                    <i class="fa-solid fa-sparkles"></i>

                    <strong>
                        Your creation appears here
                    </strong>

                    <span>
                        Video renders continue in the background.
                    </span>
                </div>

                <div class="progress-wrap"
                     id="progressWrap">

                    <div class="progress-bar">
                        <div class="progress-fill"
                             id="progressFill"></div>
                    </div>

                    <div class="progress-text"
                         id="progressText">
                        Starting generation...
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <section class="recent">
        <div class="recent-title">
            <h2>Recent creations</h2>
            <span>Newest first</span>
        </div>

        <div class="recent-grid"
             id="recentGrid">

            <?php foreach ($recent as $item): ?>
                <article class="item">
                    <div class="thumb">
                        <?php if (
                            !empty($item['asset_url']) &&
                            $item['media_type'] === 'image'
                        ): ?>
                            <img src="<?php echo htmlspecialchars($item['asset_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="Generated image">

                        <?php elseif (
                            !empty($item['asset_url']) &&
                            $item['media_type'] === 'video'
                        ): ?>
                            <video src="<?php echo htmlspecialchars($item['asset_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                   muted
                                   preload="metadata"></video>
                        <?php else: ?>
                            <i class="fa-solid <?php
                            echo $item['media_type'] === 'video'
                                ? 'fa-film'
                                : 'fa-image';
                            ?>"></i>
                        <?php endif; ?>
                    </div>

                    <div class="item-info">
                        <strong>
                            <?php
                            echo htmlspecialchars(
                                $item['prompt'],
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                        </strong>

                        <span>
                            <em>
                                <?php
                                echo htmlspecialchars(
                                    $item['media_type'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </em>

                            <em class="state-<?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php
                                echo htmlspecialchars(
                                    $item['status'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </em>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<script>
(function () {
    const form = document.getElementById("studioForm");
    const prompt = document.getElementById("prompt");
    const caption = document.getElementById("caption");
    const button = document.getElementById("createButton");
    const message = document.getElementById("formMessage");
    const placeholder = document.getElementById("placeholder");
    const progressWrap = document.getElementById("progressWrap");
    const progressFill = document.getElementById("progressFill");
    const progressText = document.getElementById("progressText");
    const previewStatus = document.getElementById("previewStatus");
    const stage = document.getElementById("previewStage");

    try {
        document.getElementById("timezone").value =
            Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
    } catch (error) {
        document.getElementById("timezone").value = "UTC";
    }

    const draftPrompt =
        sessionStorage.getItem("oldora_draft_prompt");

    const draftCaption =
        sessionStorage.getItem("oldora_draft_caption");

    if (draftPrompt) {
        prompt.value = draftPrompt;
        sessionStorage.removeItem("oldora_draft_prompt");
    }

    if (draftCaption) {
        caption.value = draftCaption;
        sessionStorage.removeItem("oldora_draft_caption");
    }

    function updateCounts() {
        document.getElementById("promptCount").textContent =
            prompt.value.length;

        document.getElementById("captionCount").textContent =
            caption.value.length;
    }

    prompt.addEventListener("input", updateCounts);
    caption.addEventListener("input", updateCounts);
    updateCounts();

    function mediaType() {
        return form.querySelector(
            'input[name="media_type"]:checked'
        ).value;
    }

    function syncAccounts() {
        const image = mediaType() === "image";

        document.querySelectorAll(".account").forEach(function (card) {
            const blocked =
                image && card.dataset.platform !== "instagram";

            card.classList.toggle("disabled", blocked);

            if (blocked) {
                card.querySelector("input").checked = false;
            }
        });
    }

    form.querySelectorAll(
        'input[name="media_type"]'
    ).forEach(function (input) {
        input.addEventListener("change", syncAccounts);
    });

    syncAccounts();

    function selectedAccounts() {
        return form.querySelectorAll(
            'input[name="token_ids[]"]:checked'
        ).length;
    }

    function setMessage(text, type) {
        message.textContent = text;
        message.className = "message " + type;
    }

    function setBusy(busy) {
        button.disabled = busy;

        button.querySelector("span").textContent =
            busy ? "Creating..." : "Create content";
    }

    function showProgress(percent, text) {
        placeholder.style.display = "none";
        progressWrap.style.display = "block";
        progressFill.style.width =
            Math.max(6, percent || 0) + "%";
        progressText.textContent = text;
        previewStatus.textContent =
            (percent || 0) + "%";
    }

    function showAsset(type, url) {
        progressWrap.style.display = "none";
        placeholder.style.display = "none";

        stage.querySelectorAll("img,video").forEach(function (node) {
            node.remove();
        });

        const element = document.createElement(
            type === "image" ? "img" : "video"
        );

        element.src = url;

        if (type === "video") {
            element.controls = true;
            element.autoplay = false;
        }

        stage.appendChild(element);
        previewStatus.textContent = "Ready";
    }

    function poll(contentId) {
        fetch(
            "content-status.php?id=" +
            encodeURIComponent(contentId),
            {
                credentials: "same-origin",
                cache: "no-store"
            }
        )
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(
                    data.message || "Status failed"
                );
            }

            const content = data.content;

            if (
                content.status === "ready" &&
                content.asset_url
            ) {
                showAsset(
                    content.media_type,
                    content.asset_url
                );

                setMessage(
                    "Content is ready. Publishing jobs will run at their scheduled time.",
                    "success"
                );

                setBusy(false);
                return;
            }

            if (content.status === "failed") {
                setMessage(
                    content.error_message ||
                    "Generation failed. Your credits were refunded.",
                    "error"
                );

                progressWrap.style.display = "none";
                placeholder.style.display = "block";
                previewStatus.textContent = "Failed";
                setBusy(false);
                return;
            }

            showProgress(
                content.progress || 8,
                "Rendering video... " +
                (content.progress || 0) +
                "%"
            );

            setTimeout(function () {
                poll(contentId);
            }, 12000);
        })
        .catch(function () {
            setTimeout(function () {
                poll(contentId);
            }, 16000);
        });
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        message.className = "message";

        if (
            selectedAccounts() > 0 &&
            !document.getElementById("publishConsent").checked
        ) {
            setMessage(
                "Confirm publishing consent for the selected accounts.",
                "error"
            );

            return;
        }

        setBusy(true);

        showProgress(
            8,
            "Sending your request to the AI model..."
        );

        const formData = new FormData(form);

        fetch("content-create.php", {
            method: "POST",
            body: formData,
            credentials: "same-origin"
        })
        .then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.ok) {
                    throw new Error(
                        json.message || "Creation failed"
                    );
                }

                return json;
            });
        })
        .then(function (result) {
            const credits =
                document.getElementById("creditCount");

            credits.textContent = Math.max(
                0,
                parseInt(credits.textContent || "0", 10) -
                result.credits_used
            );

            if (result.status === "ready") {
                showAsset("image", result.asset_url);
                setMessage(result.message, "success");
                setBusy(false);
            } else {
                setMessage(result.message, "success");

                showProgress(
                    10,
                    "Video queued. Rendering can take several minutes."
                );

                poll(result.content_id);
            }
        })
        .catch(function (error) {
            setMessage(error.message, "error");
            progressWrap.style.display = "none";
            placeholder.style.display = "block";
            previewStatus.textContent = "Ready";
            setBusy(false);
        });
    });
})();
</script>
</body>
</html>