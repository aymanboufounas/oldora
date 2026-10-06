<?php 
header("Content-Security-Policy: default-src 'self' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://ajax.googleapis.com https://code.jquery.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' data: https:; connect-src 'self' https:;");

require_once "connection.php";
require_once __DIR__ . '/includes/youtube_watcher.php';

session_start();

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off") {
    $location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $location);
    exit;
}

if(!isset($_SESSION['email'])){
    header('location: login-user.php');
    exit();
}

$email = $_SESSION['email'];
$email_esc = mysqli_real_escape_string($con, $email);

$sql = "SELECT * FROM users WHERE email = '$email_esc'";
$run_Sql = mysqli_query($con, $sql);

if(mysqli_num_rows($run_Sql) > 0){
    $fetch_info = mysqli_fetch_assoc($run_Sql);

    if(isset($fetch_info['status']) && $fetch_info['status'] == 'banned'){
        session_unset();
        session_destroy();
        header('location: login-user.php?error=banned');
        exit();
    }

    $user_id = isset($fetch_info['id']) ? intval($fetch_info['id']) : 0; 

    $plan_limits = [
        'free'      => 5,
        'basic'     => 50,
        'pro'       => 150,
        'unlimited' => 1000,
        'elite'     => 500
    ];

    $current_plan = 'free';

    if(isset($fetch_info['plan_type']) && $fetch_info['plan_type'] !== ''){
        $current_plan = strtolower($fetch_info['plan_type']);
    }

    $my_credits = isset($fetch_info['credits']) ? intval($fetch_info['credits']) : 0;
    $_SESSION['last_plan_type'] = $current_plan;
} else {
    session_unset();
    session_destroy();
    header('location: login-user.php');
    exit();
}

$token_check = mysqli_query($con, "SELECT * FROM user_tokens WHERE user_email = '$email_esc' AND platform = 'youtube'");
$is_youtube_connected = mysqli_num_rows($token_check) > 0;

$user_connected_accounts = [];

$cols = [];
$colQ = mysqli_query($con, "SHOW COLUMNS FROM user_tokens");

if($colQ){
    while($r = mysqli_fetch_assoc($colQ)){
        if(isset($r['Field'])) {
            $cols[] = $r['Field'];
        }
    }
}

function pick_col_exists($cols, $candidates){
    foreach($candidates as $c){
        if(in_array($c, $cols)) {
            return $c;
        }
    }

    return '';
}

$nameCol = pick_col_exists($cols, ['account_name','channel_name','username','user_name','name','page_name','profile_name']);
$picCol  = pick_col_exists($cols, ['picture','avatar','profile_pic','image','photo','profile_image','pic_url']);

$selectFields = ['id','platform'];

if($nameCol !== '') {
    $selectFields[] = "$nameCol AS account_name";
}

if($picCol !== '') {
    $selectFields[] = "$picCol AS picture";
}

$selectSql = "SELECT " . implode(", ", $selectFields) . " FROM user_tokens WHERE user_email = '$email_esc'";
$acc_sql = mysqli_query($con, $selectSql);

if($acc_sql){
    while($acc_row = mysqli_fetch_assoc($acc_sql)){
        if(!isset($acc_row['account_name']) || $acc_row['account_name'] === ''){
            $acc_row['account_name'] = 'Account #' . $acc_row['id'];
        }

        if(!isset($acc_row['picture'])){
            $acc_row['picture'] = '';
        }

        $user_connected_accounts[] = $acc_row;
    }
}

$accounts_json = json_encode($user_connected_accounts);
$youtube_accounts = array_values(array_filter($user_connected_accounts, function($account){
    return strtolower((string)($account['platform'] ?? '')) === 'youtube';
}));

oldora_ensure_youtube_watcher_schema($con);
$csrf_token = oldora_csrf_token();
$video_credit_cost = max(1, (int)oldora_env('VIDEO_CREDIT_COST', '5'));
$youtube_watchers = [];
$watchStmt = $con->prepare('SELECT w.*, t.channel_name AS destination_name FROM youtube_watchers w LEFT JOIN user_tokens t ON t.id = w.destination_token_id WHERE w.user_id = ? ORDER BY w.id DESC');
if ($watchStmt) {
    $watchStmt->bind_param('i', $user_id);
    $watchStmt->execute();
    $watchResult = $watchStmt->get_result();
    while ($watchRow = $watchResult->fetch_assoc()) {
        $youtube_watchers[] = $watchRow;
    }
    $watchStmt->close();
}

$today_day = date('D'); 
$tasks_count = 0;

$auto_sql = "SELECT schedule_json FROM automation_settings WHERE user_email = '$email_esc' AND is_active = 1";
$auto_query = mysqli_query($con, $auto_sql);

while($row_auto = mysqli_fetch_assoc($auto_query)){
    $schedule = json_decode($row_auto['schedule_json'], true);

    if(isset($schedule[$today_day]) && !empty($schedule[$today_day])) {
        $tasks_count++;
    }
}

$first_name = '';

if(isset($fetch_info['full_name'])){
    $parts = explode(' ', $fetch_info['full_name']);
    $first_name = isset($parts[0]) ? $parts[0] : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | OLDORA</title>

    <link rel="icon" type="image/png" href="Logo.png">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #030712;
            --panel: rgba(255,255,255,0.08);
            --panel-strong: rgba(255,255,255,0.12);
            --border: rgba(255,255,255,0.14);
            --text: #ffffff;
            --muted: rgba(255,255,255,0.63);
            --blue: #00d2ff;
            --deep: #0072ff;
            --purple: #8a2be2;
            --pink: #ff007a;
            --green: #35ffb6;
            --red: #ff477e;
            --orange: #ffb703;
        }

        * {
            box-sizing: border-box;
            font-family: "Inter", sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
        }

        body::selection {
            background: var(--blue);
            color: #020617;
        }

        .world {
            position: fixed;
            inset: 0;
            z-index: -20;
            background:
                radial-gradient(circle at 15% 15%, rgba(0,210,255,0.23), transparent 32%),
                radial-gradient(circle at 85% 20%, rgba(255,0,122,0.18), transparent 30%),
                radial-gradient(circle at 50% 95%, rgba(138,43,226,0.25), transparent 38%),
                linear-gradient(135deg, #02040c, #07111f 55%, #080015);
        }

        .aurora {
            position: fixed;
            inset: -45%;
            z-index: -19;
            background: conic-gradient(
                from 0deg,
                transparent,
                rgba(0,210,255,0.20),
                transparent,
                rgba(255,0,122,0.16),
                transparent,
                rgba(138,43,226,0.24),
                transparent
            );
            filter: blur(95px);
            animation: rotateAurora 22s linear infinite;
        }

        @keyframes rotateAurora {
            to {
                transform: rotate(360deg);
            }
        }

        .grid {
            position: fixed;
            inset: 0;
            z-index: -18;
            background-image:
                linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 54px 54px;
            mask-image: linear-gradient(to bottom, transparent, black 18%, black 82%, transparent);
        }

        .noise {
            position: fixed;
            inset: 0;
            z-index: -17;
            opacity: 0.055;
            pointer-events: none;
            background-image: radial-gradient(circle, white 1px, transparent 1px);
            background-size: 4px 4px;
        }

        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(28px);
            opacity: 0.62;
            z-index: -16;
            animation: floatOrb 9s ease-in-out infinite;
        }

        .orb.one {
            width: 320px;
            height: 320px;
            background: var(--blue);
            top: 8%;
            left: 7%;
        }

        .orb.two {
            width: 300px;
            height: 300px;
            background: var(--purple);
            bottom: 8%;
            right: 6%;
            animation-delay: 2s;
        }

        .orb.three {
            width: 230px;
            height: 230px;
            background: var(--pink);
            top: 58%;
            left: 45%;
            animation-delay: 4s;
        }

        @keyframes floatOrb {
            50% {
                transform: translate(30px, -45px) scale(1.08);
            }
        }

        .app-shell {
            display: grid;
            grid-template-columns: 290px 1fr;
            min-height: 100vh;
            position: relative;
            z-index: 5;
        }

        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 24px 18px;
            background: rgba(3,7,18,0.70);
            border-right: 1px solid rgba(255,255,255,0.10);
            backdrop-filter: blur(28px);
            overflow-y: auto;
        }

        .sidebar::-webkit-scrollbar,
        .projects-list::-webkit-scrollbar {
            width: 7px;
        }

        .sidebar::-webkit-scrollbar-thumb,
        .projects-list::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--deep), var(--blue));
            border-radius: 999px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
            padding: 0 8px;
        }

        .brand-logo {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            object-fit: cover;
            box-shadow: 0 0 34px rgba(0,210,255,0.36);
        }

        .brand-title {
            font-size: 23px;
            font-weight: 900;
            letter-spacing: -0.6px;
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        .brand-sub {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .nav-section-label {
            color: rgba(255,255,255,0.38);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 900;
            margin: 20px 12px 10px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 13px;
            color: rgba(255,255,255,0.72);
            padding: 13px 14px;
            border-radius: 18px;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 800;
            transition: 0.25s ease;
            border: 1px solid transparent;
        }

        .nav-link i {
            width: 22px;
            text-align: center;
            font-size: 17px;
        }

        .nav-link:hover,
        .nav-link.active {
            color: white;
            text-decoration: none;
            background: linear-gradient(135deg, rgba(0,210,255,0.18), rgba(138,43,226,0.14));
            border-color: rgba(0,210,255,0.25);
            box-shadow: 0 16px 38px rgba(0,0,0,0.22);
        }

        .user-mini {
            margin-top: 24px;
            padding: 16px;
            border-radius: 24px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--deep), var(--blue), var(--purple));
            font-size: 20px;
            font-weight: 900;
        }

        .user-mini small {
            color: var(--muted);
            display: block;
            font-weight: 700;
        }

        .user-mini strong {
            color: white;
            font-size: 14px;
        }

        .content {
            padding: 26px;
            min-width: 0;
        }

        .mobile-topbar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 500;
            padding: 14px 16px;
            background: rgba(3,7,18,0.82);
            border-bottom: 1px solid rgba(255,255,255,0.10);
            backdrop-filter: blur(25px);
            align-items: center;
            justify-content: space-between;
        }

        .mobile-topbar h4 {
            margin: 0;
            font-weight: 900;
        }

        .icon-btn {
            width: 46px;
            height: 46px;
            border: none;
            border-radius: 16px;
            color: white;
            background: rgba(255,255,255,0.09);
            border: 1px solid rgba(255,255,255,0.13);
            display: grid;
            place-items: center;
        }

        .page-header {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 18px;
            align-items: center;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.13);
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 16px var(--green);
            animation: pulseDot 1.4s infinite;
        }

        @keyframes pulseDot {
            50% {
                transform: scale(1.6);
                opacity: 0.5;
            }
        }

        .page-header h1 {
            font-size: clamp(32px, 5vw, 58px);
            font-weight: 900;
            letter-spacing: -2.8px;
            line-height: 0.95;
            margin: 0;
        }

        .page-header h1 span {
            background: linear-gradient(135deg, #fff, var(--blue), var(--pink));
            -webkit-background-clip: text;
            color: transparent;
        }

        .page-desc {
            color: var(--muted);
            margin-top: 14px;
            max-width: 720px;
            line-height: 1.75;
            font-weight: 600;
        }

        .stats-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .stat-pill {
            min-width: 185px;
            padding: 15px 18px;
            border-radius: 24px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.13);
            backdrop-filter: blur(22px);
        }

        .stat-pill small {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-pill strong {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 24px;
            font-weight: 900;
        }

        .stat-pill i {
            color: var(--blue);
        }

        .main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(330px, 0.75fr);
            gap: 22px;
        }

        .glass-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            backdrop-filter: blur(28px);
            border-radius: 32px;
            padding: 26px;
            box-shadow:
                0 30px 80px rgba(0,0,0,0.32),
                inset 0 1px 0 rgba(255,255,255,0.12);
        }

        .panel-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .panel-title h5 {
            margin: 0;
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -0.7px;
        }

        .panel-title h5 i {
            color: var(--blue);
            margin-right: 8px;
        }

        .panel-tag {
            color: var(--blue);
            background: rgba(0,210,255,0.10);
            border: 1px solid rgba(0,210,255,0.20);
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
        }

        .form-label {
            color: rgba(255,255,255,0.78);
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .form-control {
            min-height: 56px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.08);
            color: white;
            font-weight: 700;
            padding: 12px 16px;
            transition: 0.25s ease;
        }

        .form-control::placeholder {
            color: rgba(255,255,255,0.35);
        }

        .form-control:focus {
            color: white;
            background: rgba(255,255,255,0.12);
            border-color: var(--blue);
            box-shadow: 0 0 0 4px rgba(0,210,255,0.11);
        }

        textarea.form-control {
            min-height: 112px;
            resize: vertical;
        }

        select.form-control option {
            background: #07111f;
            color: white;
        }

        .input-icon-wrap {
            position: relative;
        }

        .input-icon-wrap .form-control {
            padding-left: 48px;
        }

        .input-icon-wrap i {
            position: absolute;
            left: 17px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--blue);
            z-index: 2;
        }

        .quick-row {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) minmax(130px, 0.38fr);
            gap: 16px;
        }

        .voice-picker {
            position: relative;
        }

        .voice-trigger {
            width: 100%;
            min-height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 16px;
            color: #fff;
            text-align: left;
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 18px;
            background: rgba(255,255,255,0.08);
            font-weight: 800;
            transition: .2s ease;
        }

        .voice-trigger:hover,
        .voice-picker.open .voice-trigger {
            border-color: var(--blue);
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 4px rgba(0,210,255,0.11);
        }

        .voice-trigger i {
            color: var(--blue);
            transition: transform .2s ease;
        }

        .voice-picker.open .voice-trigger i {
            transform: rotate(180deg);
        }

        .voice-menu {
            position: absolute;
            z-index: 1000;
            top: calc(100% + 9px);
            left: 0;
            right: 0;
            display: none;
            padding: 10px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 18px;
            background: rgba(5,11,25,.98);
            box-shadow: 0 24px 70px rgba(0,0,0,.55);
            backdrop-filter: blur(24px);
        }

        .voice-picker.open .voice-menu {
            display: block;
        }

        .voice-search {
            width: 100%;
            height: 43px;
            margin-bottom: 8px;
            padding: 0 13px;
            color: #fff;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px;
            outline: 0;
            background: rgba(255,255,255,.07);
        }

        .voice-options {
            max-height: 245px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(0,210,255,.45) transparent;
        }

        .voice-option {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 11px;
            color: rgba(255,255,255,.78);
            border: 0;
            border-radius: 11px;
            background: transparent;
            text-align: left;
            font-size: 13px;
            font-weight: 700;
        }

        .voice-option:hover,
        .voice-option.selected {
            color: #fff;
            background: linear-gradient(90deg, rgba(0,210,255,.13), rgba(138,43,226,.12));
        }

        .voice-option i {
            width: 18px;
            color: var(--blue);
            text-align: center;
        }

        .prompt-composer {
            position: relative;
            margin-top: 4px;
            padding: 15px;
            border: 1px solid rgba(0,210,255,.18);
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(0,210,255,.07), rgba(138,43,226,.08));
        }

        .prompt-composer textarea {
            min-height: 92px;
            padding-right: 48px;
            line-height: 1.6;
        }

        .prompt-spark {
            position: absolute;
            right: 28px;
            bottom: 30px;
            color: var(--pink);
            pointer-events: none;
        }

        .watcher-panel {
            margin-top: 22px;
            scroll-margin-top: 24px;
        }

        .watcher-intro {
            display: grid;
            grid-template-columns: 48px minmax(0,1fr) auto;
            gap: 14px;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid rgba(255,91,107,.2);
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(255,43,66,.10), rgba(138,43,226,.09));
        }

        .watcher-icon {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            color: #fff;
            border-radius: 15px;
            background: #ff304f;
            font-size: 22px;
            box-shadow: 0 12px 30px rgba(255,48,79,.25);
        }

        .watcher-intro strong { display:block; font-size:15px; }
        .watcher-intro small { color:var(--muted); line-height:1.55; }

        .auto-pill {
            padding: 7px 10px;
            color: var(--green);
            border: 1px solid rgba(53,255,182,.2);
            border-radius: 999px;
            background: rgba(53,255,182,.08);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .watcher-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 14px;
        }

        .watcher-grid .full { grid-column: 1 / -1; }

        .watcher-actions {
            display:flex;
            align-items:center;
            gap:12px;
            margin-top:16px;
        }

        .watcher-status {
            flex:1;
            min-height:20px;
            color:var(--muted);
            font-size:12px;
            font-weight:700;
        }

        .watcher-list {
            display:grid;
            gap:10px;
            margin-top:18px;
        }

        .watcher-row {
            display:grid;
            grid-template-columns: minmax(0,1fr) auto;
            gap:12px;
            align-items:center;
            padding:14px;
            border:1px solid rgba(255,255,255,.11);
            border-radius:17px;
            background:rgba(255,255,255,.055);
        }

        .watcher-row strong { display:block; font-size:13px; }
        .watcher-row small { display:block; margin-top:4px; color:var(--muted); font-size:11px; }
        .watcher-row .watch-error { color:#ff91a8; white-space:normal; }

        .mini-action {
            width:36px;
            height:36px;
            display:grid;
            place-items:center;
            color:#fff;
            border:1px solid rgba(255,255,255,.12);
            border-radius:11px;
            background:rgba(255,255,255,.06);
            cursor:pointer;
        }

        .project-card {
            position:relative;
            overflow:hidden;
            margin-bottom:10px;
            padding:15px;
            border:1px solid rgba(255,255,255,.11);
            border-radius:18px;
            background:linear-gradient(135deg, rgba(255,255,255,.075), rgba(255,255,255,.035));
        }

        .project-card::before {
            content:"";
            position:absolute;
            inset:0 auto 0 0;
            width:3px;
            background:linear-gradient(var(--blue),var(--purple));
        }

        .project-title {
            margin-top:7px;
            color:#fff;
            font-size:13px;
            font-weight:800;
            line-height:1.45;
        }

        .project-meta {
            display:flex;
            justify-content:space-between;
            gap:10px;
            color:var(--muted);
            font-size:10px;
        }

        .qty-input {
            text-align: center;
            font-size: 22px;
            font-weight: 900;
            color: var(--blue);
        }

        .btn-glow {
            position: relative;
            width: 100%;
            min-height: 62px;
            border: none;
            border-radius: 20px;
            color: white;
            font-size: 16px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--deep), var(--blue), var(--purple));
            box-shadow: 0 24px 54px rgba(0,210,255,0.28);
            overflow: hidden;
            transition: 0.25s ease;
        }

        .btn-glow:hover {
            color: white;
            transform: translateY(-4px);
            box-shadow: 0 32px 75px rgba(0,210,255,0.42);
        }

        .btn-glow::before {
            content: "";
            position: absolute;
            inset: 0;
            left: -110%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.48), transparent);
            transition: 0.55s ease;
        }

        .btn-glow:hover::before {
            left: 110%;
        }

        .schedule-card {
            position: relative;
            padding: 20px;
            border-radius: 26px;
            background:
                linear-gradient(135deg, rgba(0,210,255,0.08), rgba(138,43,226,0.06)),
                rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.13);
            margin-bottom: 16px;
            overflow: hidden;
        }

        .schedule-card::before {
            content: "";
            position: absolute;
            width: 140px;
            height: 140px;
            right: -60px;
            top: -60px;
            background: rgba(0,210,255,0.12);
            filter: blur(30px);
            border-radius: 50%;
        }

        .schedule-title {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .schedule-title strong {
            font-size: 15px;
            font-weight: 900;
        }

        .video-badge {
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(0,210,255,0.11);
            color: var(--blue);
            font-size: 12px;
            font-weight: 900;
            border: 1px solid rgba(0,210,255,0.18);
        }

        .platform-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 8px;
        }

        .platform-option input {
            display: none;
        }

        .platform-option label {
            width: 100%;
            cursor: pointer;
            padding: 12px 10px;
            border-radius: 17px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.72);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 900;
            transition: 0.25s ease;
        }

        .platform-option input:checked + label {
            color: white;
            background: linear-gradient(135deg, rgba(0,210,255,0.22), rgba(138,43,226,0.18));
            border-color: rgba(0,210,255,0.32);
            box-shadow: 0 12px 30px rgba(0,0,0,0.20);
        }

        .account-selector {
            display: none;
            margin-top: 12px;
            padding: 12px;
            border-radius: 18px;
            background: rgba(0,0,0,0.22);
            border: 1px solid rgba(255,255,255,0.10);
        }

        .project-card {
            padding: 16px;
            border-radius: 22px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.11);
            margin-bottom: 13px;
            transition: 0.25s ease;
        }

        .project-card:hover {
            transform: translateY(-4px);
            background: rgba(255,255,255,0.10);
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 7px;
        }

        .status-pending {
            background: var(--orange);
            box-shadow: 0 0 12px var(--orange);
        }

        .status-ready {
            background: var(--green);
            box-shadow: 0 0 12px var(--green);
        }

        .status-failed {
            background: var(--red);
            box-shadow: 0 0 12px var(--red);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 42px;
            color: rgba(255,255,255,0.22);
            margin-bottom: 15px;
        }

        #loadingOverlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background:
                radial-gradient(circle at center, rgba(0,210,255,0.16), transparent 35%),
                rgba(0,0,0,0.92);
            backdrop-filter: blur(20px);
            align-items: center;
            justify-content: center;
            flex-direction: column;
            text-align: center;
        }

        .loader-ring {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.12);
            border-top-color: var(--blue);
            border-right-color: var(--purple);
            animation: spin 0.9s linear infinite;
            margin-bottom: 24px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        #loadingText {
            font-size: 28px;
            font-weight: 900;
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        #loadingSubText {
            color: var(--muted);
            font-weight: 700;
        }

        .modal-content {
            border-radius: 30px;
            background:
                radial-gradient(circle at 50% 0%, rgba(0,210,255,0.18), transparent 42%),
                #050816;
            border: 1px solid rgba(53,255,182,0.25);
            color: white;
            box-shadow: 0 30px 100px rgba(0,0,0,0.6);
        }

        .modal-success-icon {
            width: 84px;
            height: 84px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(53,255,182,0.12);
            color: var(--green);
            font-size: 38px;
            box-shadow: 0 0 35px rgba(53,255,182,0.22);
        }

        .sidebar-overlay {
            display: none;
        }

        @media (max-width: 1199px) {
            .app-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: fixed;
                left: -310px;
                top: 0;
                width: 290px;
                z-index: 9999;
                transition: 0.3s ease;
            }

            .sidebar.active {
                left: 0;
            }

            .sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.72);
                z-index: 9998;
            }

            .mobile-topbar {
                display: flex;
            }

            .content {
                padding: 18px;
            }

            .page-header {
                grid-template-columns: 1fr;
            }

            .stats-row {
                justify-content: flex-start;
            }
        }

        @media (max-width: 991px) {
            .main-grid {
                grid-template-columns: 1fr;
            }

            .quick-row {
                grid-template-columns: 1fr;
            }

            .watcher-grid {
                grid-template-columns: 1fr;
            }

            .watcher-grid .full { grid-column:auto; }
        }

        @media (max-width: 620px) {
            .content {
                padding: 14px;
            }

            .glass-panel {
                padding: 20px;
                border-radius: 26px;
            }

            .platform-grid {
                grid-template-columns: 1fr;
            }

            .stats-row {
                flex-direction: column;
            }

            .stat-pill {
                width: 100%;
            }

            .page-header h1 {
                letter-spacing: -1.8px;
            }
        }
    </style>
</head>

<body>

<div class="world"></div>
<div class="aurora"></div>
<div class="grid"></div>
<div class="noise"></div>
<div class="orb one"></div>
<div class="orb two"></div>
<div class="orb three"></div>

<div class="sidebar-overlay"></div>

<div id="loadingOverlay">
    <div class="loader-ring"></div>
    <div id="loadingText">Processing Order...</div>
    <p id="loadingSubText">Saving request and deducting credits.</p>
</div>

<div class="modal fade" id="successModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5 px-4">
                <div class="modal-success-icon">
                    <i class="fa-solid fa-check"></i>
                </div>

                <h4 class="font-weight-bold mb-2">Order placed successfully</h4>

                <p class="text-white-50 mb-2" id="modalMsg">
                    Your request has been saved to the processing queue.
                </p>

                <p class="small" style="color:var(--blue);" id="orderIdDisplay"></p>

                <button type="button" class="btn btn-outline-light rounded-pill px-4 mt-3" data-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<div class="mobile-topbar">
    <button class="icon-btn" id="openSidebarBtn">
        <i class="fa-solid fa-bars"></i>
    </button>

    <h4><i class="fa-solid fa-wand-magic-sparkles" style="color:var(--blue);"></i> OLDORA</h4>

    <a href="logout-user.php" class="icon-btn">
        <i class="fa-solid fa-right-from-bracket"></i>
    </a>
</div>

<div class="app-shell">

    <aside class="sidebar" id="mainSidebar">

        <div class="d-flex justify-content-between align-items-center d-xl-none mb-4">
            <div class="brand mb-0">
                <img src="Logo.png" class="brand-logo" alt="OLDORA">
                <div>
                    <div class="brand-title">OLDORA</div>
                    <div class="brand-sub">Creator Console</div>
                </div>
            </div>

            <button class="icon-btn" id="closeSidebarBtn">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="brand d-none d-xl-flex">
            <img src="Logo.png" class="brand-logo" alt="OLDORA">
            <div>
                <div class="brand-title">OLDORA</div>
                <div class="brand-sub">Creator Console</div>
            </div>
        </div>

        <div class="nav-section-label">Workspace</div>

        <nav class="nav flex-column">
            <a class="nav-link" href="profile.php">
                <i class="fa-solid fa-user-gear"></i> Settings
            </a>

            <a class="nav-link active" href="home.php">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Create Video
            </a>

            <a class="nav-link" href="create-insta.php">
                <i class="fa-brands fa-instagram"></i> Create Insta Post
            </a>

            <a class="nav-link" href="automation.php">
                <i class="fa-solid fa-gears"></i> Automation
                <span class="badge badge-danger ml-auto">New</span>
            </a>

            <a class="nav-link" href="analytics.php">
                <i class="fa-solid fa-chart-line"></i> Analytics
            </a>

            <a class="nav-link" href="connect-platforms.php">
                <i class="fa-solid fa-link"></i> Connected Accounts
            </a>

            <a class="nav-link" href="warmup.php">
                <i class="fa-solid fa-fire"></i> Warm-up Account
            </a>

            <div class="nav-section-label">Account</div>

            <a class="nav-link" href="planing.php">
                <i class="fa-solid fa-crown"></i> Your Plan
            </a>

            <a class="nav-link" href="referral.php">
                <i class="fa-solid fa-bullhorn"></i> Referral Program
            </a>

            <a class="nav-link" href="support.php">
                <i class="fa-solid fa-headset"></i> Contact Support
            </a>

            <a class="nav-link mt-3" href="logout-user.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </nav>

        <div class="user-mini">
            <div class="user-avatar">
                <?php echo strtoupper(substr(htmlspecialchars($first_name), 0, 1)); ?>
            </div>

            <div>
                <small>Logged in as</small>
                <strong><?php echo htmlspecialchars($first_name); ?></strong>
            </div>
        </div>

    </aside>

    <main class="content">

        <div class="page-header">
            <div>
                <div class="eyebrow">
                    <span class="live-dot"></span>
                    AI video generation workspace
                </div>

                <h1>
                    Create viral <span>AI videos.</span>
                </h1>

                <p class="page-desc">
                    Paste a YouTube source, choose an AI voice, add your prompt, then schedule your generated videos
                    across connected platforms from one powerful OLDORA dashboard.
                </p>
            </div>

            <div class="stats-row">
                <div class="stat-pill">
                    <small>Today's Goal</small>
                    <strong>
                        <i class="fa-solid fa-calendar-check"></i>
                        <?php echo intval($tasks_count); ?> Videos
                    </strong>
                </div>

                <div class="stat-pill">
                    <small>Available Credits</small>
                    <strong>
                        <i class="fa-solid fa-bolt" style="color:var(--orange);"></i>
                        <span class="credits-display"><?php echo intval($my_credits); ?></span>
                    </strong>
                </div>
            </div>
        </div>

        <div class="main-grid">

            <section class="glass-panel">

                <div class="panel-title">
                    <h5>
                        <i class="fa-solid fa-clapperboard"></i>
                        New Video Order
                    </h5>

                    <span class="panel-tag"><?php echo (int)$video_credit_cost; ?> credit<?php echo $video_credit_cost === 1 ? '' : 's'; ?> / video</span>
                </div>

                <form id="generateForm">

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa-brands fa-youtube text-danger"></i>
                            Source Video
                        </label>

                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-link"></i>
                            <input type="url" id="youtubeLink" class="form-control" placeholder="https://youtube.com/watch?v=..." required>
                        </div>
                    </div>

                    <div class="quick-row mt-4">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fa-solid fa-microphone-lines"></i>
                                AI Voice
                            </label>

                            <div class="voice-picker" id="voicePicker">
                                <input type="hidden" id="voiceId" value="CwhRBWXzGAHq8TQ4Fs17">
                                <input type="hidden" id="voiceName" value="Roger - Laid-Back, Casual">
                                <button type="button" class="voice-trigger" id="voiceTrigger" aria-expanded="false">
                                    <span id="voiceSelectedLabel">Roger - Laid-Back, Casual</span>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                                <div class="voice-menu" id="voiceMenu">
                                    <input type="search" class="voice-search" id="voiceSearch" placeholder="Search voices..." autocomplete="off">
                                    <div class="voice-options" id="voiceOptions">
                                        <?php
                                        $voices = [
                                            'CwhRBWXzGAHq8TQ4Fs17' => 'Roger - Laid-Back, Casual',
                                            'EXAVITQu4vr4xnSDxMaL' => 'Sarah - Mature, Reassuring',
                                            'FGY2WhTYpPnrIDTdsKH5' => 'Laura - Enthusiastic, Quirky',
                                            'IKne3meq5aSn9XLyUdCD' => 'Charlie - Deep, Confident',
                                            'JBFqnCBsd6RMkjVDRZzb' => 'George - Warm Storyteller',
                                            'N2lVS1w4EtoT3dr4eOWO' => 'Callum - Husky Trickster',
                                            'SAzYHcvj6GT2YYXdXww' => 'River - Relaxed, Informative',
                                            'SOYHLrjzK2X1ezoPC6cr' => 'Harry - Fierce Warrior',
                                            'TX3LPaxmHKxFdv7VOQHJ' => 'Liam - Energetic Creator',
                                            'Xb7hH8MSUJpSbSDYk0k2' => 'Alice - Clear Educator',
                                            'XrExE9yKIg1WjnnlVkGX' => 'Matilda - Professional',
                                            'bIHbv24MWmeRgasZH58o' => 'Will - Relaxed Optimist',
                                            'cgSgspJ2msm6clMCkdW9' => 'Jessica - Playful, Bright',
                                            'cjVigY5qzO86Huf0OWal' => 'Eric - Smooth, Trustworthy',
                                            'iP95p4xoKVk53GoZ742B' => 'Chris - Charming',
                                            'nPczCjzI2devNBz1zQrb' => 'Brian - Deep, Comforting',
                                            'onwK4e9ZLuTAKqWW03F9' => 'Daniel - Steady Broadcaster',
                                            'pFZP5JQG7iQjIQuC4Bku' => 'Lily - Velvety Actress',
                                            'pNInz6obpgDQGcFmaJgB' => 'Adam - Dominant, Firm',
                                            'pqHfZKP75CvOlQylNhV4' => 'Bill - Wise, Mature'
                                        ];
                                        foreach ($voices as $voiceId => $voiceLabel):
                                        ?>
                                            <button type="button" class="voice-option<?php echo $voiceId === 'CwhRBWXzGAHq8TQ4Fs17' ? ' selected' : ''; ?>" data-value="<?php echo htmlspecialchars($voiceId); ?>" data-label="<?php echo htmlspecialchars($voiceLabel); ?>">
                                                <i class="fa-solid fa-wave-square"></i>
                                                <span><?php echo htmlspecialchars($voiceLabel); ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fa-solid fa-layer-group"></i>
                                Quantity
                            </label>

                            <input type="number" id="videoCount" class="form-control qty-input" value="1" min="1" max="10">
                        </div>

                    </div>

                    <div class="form-group prompt-composer">
                        <label class="form-label" for="aiPrompt">
                            <i class="fa-solid fa-pen-nib"></i>
                            AI Prompt
                        </label>
                        <textarea id="aiPrompt" class="form-control" maxlength="2000" placeholder="Describe the hook, visual style, story, pacing, captions and ending you want..." required></textarea>
                        <i class="fa-solid fa-wand-magic-sparkles prompt-spark"></i>
                    </div>

                    <div class="form-group mt-2">
                        <label class="form-label">
                            <i class="fa-solid fa-comment-dots"></i>
                            Custom Script Optional
                        </label>

                        <textarea id="customScript" class="form-control" rows="4" placeholder="Leave empty for auto-generation."></textarea>
                    </div>

                    <div id="dynamic-schedule-area" class="mt-4"></div>

                    <button type="submit" class="btn-glow mt-3">
                        <i class="fa-solid fa-video"></i>
                        Submit Order
                    </button>

                </form>

            </section>

            <aside class="glass-panel">

                <div class="panel-title">
                    <h5>
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        Recent Projects
                    </h5>

                    <span class="panel-tag">Latest 5</span>
                </div>

                <div class="projects-list" style="max-height: 620px; overflow-y: auto; padding-right: 4px;">

                    <?php
                    $hist_stmt = $con->prepare("SELECT id, title, prompt, status, progress, created_at FROM content_items WHERE user_id = ? AND media_type = 'video' ORDER BY id DESC LIMIT 5");
                    $hist_stmt->bind_param('i', $user_id);
                    $hist_stmt->execute();
                    $res = $hist_stmt->get_result();

                    if($res && mysqli_num_rows($res) > 0){
                        while($row = mysqli_fetch_assoc($res)){
                            $ready = $row['status'] === 'ready';
                            $failed = $row['status'] === 'failed';
                            $status_label = $ready ? 'Ready' : ($failed ? 'Failed' : 'Processing ' . (int)$row['progress'] . '%');
                            $dot_class = $ready ? 'status-ready' : ($failed ? 'status-failed' : 'status-pending');
                            $date = !empty($row['created_at']) ? date('d M Y · H:i', strtotime($row['created_at'])) : '';
                            $project_title = trim((string)($row['title'] ?: $row['prompt']));

                            echo '
                            <div class="project-card">
                                <div class="project-meta">
                                    <span>'.htmlspecialchars($date).'</span>
                                    <span class="small font-weight-bold">
                                        <span class="status-dot '.$dot_class.'"></span>
                                        '.htmlspecialchars($status_label).'
                                    </span>
                                </div>

                                <div class="project-title">'.htmlspecialchars($project_title).'</div>
                            </div>';
                        }
                    } else {
                        echo '
                        <div class="empty-state">
                            <i class="fa-solid fa-folder-open"></i>
                            <p>No videos created yet.</p>
                            <small>Your generated videos will appear here.</small>
                        </div>';
                    }
                    $hist_stmt->close();
                    ?>

                </div>

            </aside>

        </div>

        <section class="glass-panel watcher-panel" id="youtube-watcher">
            <div class="panel-title">
                <h5><i class="fa-brands fa-youtube" style="color:#ff405c;"></i> YouTube Channel Watch</h5>
                <span class="panel-tag">New upload → Original Short</span>
            </div>

            <div class="watcher-intro">
                <div class="watcher-icon"><i class="fa-brands fa-youtube"></i></div>
                <div>
                    <strong>Follow a source channel automatically</strong>
                    <small>OLDORA checks for new public uploads, creates an original vertical Short from the topic, writes the title and description, then publishes at the next smart time.</small>
                </div>
                <span class="auto-pill">Runs by cron</span>
            </div>

            <?php if (!$youtube_accounts): ?>
                <div class="alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    Connect a YouTube account before enabling Channel Watch.
                    <a href="connect-platforms.php" style="color:#fff;text-decoration:underline;">Connect YouTube</a>
                </div>
            <?php else: ?>
                <form id="youtubeWatcherForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="timezone" id="watcherTimezone" value="UTC">
                    <input type="hidden" name="voice_name" id="watcherVoiceName" value="Roger - Laid-Back, Casual">

                    <div class="watcher-grid">
                        <div class="form-group full">
                            <label class="form-label"><i class="fa-brands fa-youtube text-danger"></i> Channel URL or @handle</label>
                            <div class="input-icon-wrap">
                                <i class="fa-solid fa-at"></i>
                                <input type="text" name="channel_input" class="form-control" maxlength="500" placeholder="https://youtube.com/@channel or UC channel ID" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-upload"></i> Publish Shorts to</label>
                            <select name="destination_token_id" class="form-control" required>
                                <?php foreach ($youtube_accounts as $account): ?>
                                    <option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-clock"></i> Smart posting times</label>
                            <input type="text" name="best_times" class="form-control" value="12:30, 18:30, 21:00" placeholder="12:30, 18:30, 21:00" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-eye"></i> YouTube privacy</label>
                            <select name="privacy_level" class="form-control">
                                <option value="public">Public</option>
                                <option value="unlisted">Unlisted</option>
                                <option value="private">Private</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-bolt"></i> Generation cost</label>
                            <input type="text" class="form-control" value="<?php echo (int)$video_credit_cost; ?> credits for every new Short" disabled>
                        </div>

                        <div class="form-group full">
                            <label class="form-label"><i class="fa-solid fa-wand-magic-sparkles"></i> Automation prompt</label>
                            <textarea name="prompt_template" class="form-control" maxlength="2000" placeholder="Example: Create a fast 9:16 explainer with a powerful hook, bold captions, original visuals and a clear ending. Never copy the source footage."></textarea>
                        </div>
                    </div>

                    <div class="watcher-actions">
                        <div class="watcher-status" id="watcherStatus">Only uploads published after you enable the watcher will be processed.</div>
                        <button type="submit" class="btn-glow" style="width:auto;min-width:210px;padding:0 24px;">
                            <i class="fa-solid fa-satellite-dish"></i> Start watching
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($youtube_watchers): ?>
                <div class="watcher-list" id="watcherList">
                    <?php foreach ($youtube_watchers as $watcher): ?>
                        <div class="watcher-row" data-watcher-id="<?php echo (int)$watcher['id']; ?>">
                            <div>
                                <strong><i class="fa-brands fa-youtube" style="color:#ff405c;margin-right:7px;"></i><?php echo htmlspecialchars($watcher['source_channel_name']); ?></strong>
                                <small>Posts to <?php echo htmlspecialchars($watcher['destination_name'] ?: 'connected YouTube account'); ?> · <?php echo $watcher['is_active'] ? 'Active' : 'Paused'; ?> · <?php echo htmlspecialchars($watcher['timezone']); ?></small>
                                <?php if (!empty($watcher['last_error'])): ?><small class="watch-error"><?php echo htmlspecialchars($watcher['last_error']); ?></small><?php endif; ?>
                            </div>
                            <div style="display:flex;gap:7px;">
                                <button type="button" class="mini-action watcher-toggle" data-active="<?php echo $watcher['is_active'] ? '0' : '1'; ?>" title="<?php echo $watcher['is_active'] ? 'Pause' : 'Activate'; ?>">
                                    <i class="fa-solid fa-<?php echo $watcher['is_active'] ? 'pause' : 'play'; ?>"></i>
                                </button>
                                <button type="button" class="mini-action watcher-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const connectedAccounts = <?php echo $accounts_json ? $accounts_json : '[]'; ?>;
const oldoraCsrf = <?php echo json_encode($csrf_token); ?>;
const videoCreditCost = <?php echo (int)$video_credit_cost; ?>;

$(document).ready(function(){

    $("#openSidebarBtn").click(function(){
        $("#mainSidebar").addClass("active");
        $(".sidebar-overlay").fadeIn(160);
    });

    $("#closeSidebarBtn, .sidebar-overlay").click(function(){
        $("#mainSidebar").removeClass("active");
        $(".sidebar-overlay").fadeOut(160);
    });

    function escapeHtml(text) {
        return $("<div>").text(text).html();
    }

    const voicePicker = $("#voicePicker");
    $("#voiceTrigger").on("click", function(){
        voicePicker.toggleClass("open");
        $(this).attr("aria-expanded", voicePicker.hasClass("open") ? "true" : "false");
        if (voicePicker.hasClass("open")) setTimeout(() => $("#voiceSearch").trigger("focus"), 30);
    });

    $(document).on("click", function(event){
        if (!$(event.target).closest("#voicePicker").length) {
            voicePicker.removeClass("open");
            $("#voiceTrigger").attr("aria-expanded", "false");
        }
    });

    $("#voiceSearch").on("input", function(){
        const query = ($(this).val() || "").toLowerCase().trim();
        $(".voice-option").each(function(){
            $(this).toggle(($(this).data("label") || "").toLowerCase().includes(query));
        });
    });

    $(document).on("click", ".voice-option", function(){
        const value = String($(this).data("value") || "");
        const label = String($(this).data("label") || "");
        $("#voiceId").val(value);
        $("#voiceName, #watcherVoiceName").val(label);
        $("#voiceSelectedLabel").text(label);
        $(".voice-option").removeClass("selected");
        $(this).addClass("selected");
        voicePicker.removeClass("open");
        $("#voiceTrigger").attr("aria-expanded", "false");
    });

    try {
        $("#watcherTimezone").val(Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC");
    } catch (error) {
        $("#watcherTimezone").val("UTC");
    }

    $("#youtubeWatcherForm").on("submit", function(event){
        event.preventDefault();
        const form = this;
        const button = $(form).find("button[type=submit]");
        button.prop("disabled", true).html('<i class="fa-solid fa-spinner fa-spin"></i> Checking channel...');
        $("#watcherStatus").text("Resolving the channel and saving the latest upload as the starting point...");

        $.ajax({
            url: "youtube-watch-save.php",
            method: "POST",
            data: new FormData(form),
            processData: false,
            contentType: false,
            dataType: "json"
        }).done(function(response){
            if (!response.ok) throw new Error(response.message || "Could not save channel watcher.");
            $("#watcherStatus").css("color", "var(--green)").text(response.message);
            setTimeout(() => window.location.reload(), 1100);
        }).fail(function(xhr){
            const response = xhr.responseJSON || {};
            $("#watcherStatus").css("color", "#ff91a8").text(response.message || "Could not save channel watcher.");
            button.prop("disabled", false).html('<i class="fa-solid fa-satellite-dish"></i> Start watching');
        });
    });

    function watcherAction(row, action, active){
        const data = new FormData();
        data.append("csrf_token", oldoraCsrf);
        data.append("action", action);
        data.append("watcher_id", row.data("watcher-id"));
        if (typeof active !== "undefined") data.append("is_active", active);
        return $.ajax({url:"youtube-watch-save.php",method:"POST",data:data,processData:false,contentType:false,dataType:"json"});
    }

    $(document).on("click", ".watcher-toggle", function(){
        const row = $(this).closest(".watcher-row");
        const active = $(this).data("active");
        $(this).prop("disabled", true);
        watcherAction(row, "toggle", active).done(() => window.location.reload()).fail(function(xhr){
            alert((xhr.responseJSON || {}).message || "Could not update watcher.");
            row.find(".watcher-toggle").prop("disabled", false);
        });
    });

    $(document).on("click", ".watcher-delete", function(){
        if (!confirm("Delete this channel watcher?")) return;
        const row = $(this).closest(".watcher-row");
        $(this).prop("disabled", true);
        watcherAction(row, "delete").done(() => row.slideUp(180, () => row.remove())).fail(function(xhr){
            alert((xhr.responseJSON || {}).message || "Could not delete watcher.");
            row.find(".watcher-delete").prop("disabled", false);
        });
    });

    function renderSchedulingFields() {
        let count = parseInt($("#videoCount").val()) || 1;
        const container = $("#dynamic-schedule-area");

        container.empty();

        if(count > 10) {
            count = 10;
            $("#videoCount").val(10);
        }

        if(count < 1) {
            count = 1;
            $("#videoCount").val(1);
        }

        container.append(`
            <div class="panel-title mt-4 mb-3">
                <h5>
                    <i class="fa-solid fa-calendar-days"></i>
                    Publishing & Scheduling
                </h5>
                <span class="panel-tag">${count} video${count > 1 ? "s" : ""}</span>
            </div>
        `);

        for (let i = 1; i <= count; i++) {
            const html = `
            <div class="schedule-card" id="schedule-block-${i}">
                <div class="schedule-title">
                    <strong>
                        <i class="fa-solid fa-film" style="color:var(--blue);"></i>
                        Video #${i} Settings
                    </strong>

                    <span class="video-badge">Optional auto-post</span>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Publish Date</label>
                        <input type="date" class="form-control date-input" name="date_${i}">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Publish Time</label>
                        <input type="time" class="form-control time-input" name="time_${i}">
                    </div>
                </div>

                <label class="form-label small d-block">Select Platforms</label>

                <div class="platform-grid">
                    <div class="platform-option">
                        <input type="checkbox" class="plat-check" id="plat_yt_${i}" data-vid="${i}" data-plat="youtube">
                        <label for="plat_yt_${i}">
                            <i class="fa-brands fa-youtube text-danger"></i>
                            YouTube
                        </label>
                    </div>

                    <div class="platform-option">
                        <input type="checkbox" class="plat-check" id="plat_tk_${i}" data-vid="${i}" data-plat="tiktok">
                        <label for="plat_tk_${i}">
                            <i class="fa-brands fa-tiktok"></i>
                            TikTok
                        </label>
                    </div>

                    <div class="platform-option">
                        <input type="checkbox" class="plat-check" id="plat_ig_${i}" data-vid="${i}" data-plat="instagram">
                        <label for="plat_ig_${i}">
                            <i class="fa-brands fa-instagram text-warning"></i>
                            Instagram
                        </label>
                    </div>
                </div>

                <div id="accounts-container-${i}" class="mt-2"></div>
            </div>`;

            container.append(html);
        }
    }

    renderSchedulingFields();

    $("#videoCount").on("change keyup", function(){
        renderSchedulingFields();
    });

    $(document).on("change", ".plat-check", function(){
        const vidId = $(this).data("vid");
        const platform = $(this).data("plat");
        const isChecked = $(this).is(":checked");
        const accContainer = $("#accounts-container-" + vidId);
        const selectId = `acc_select_${vidId}_${platform}`;

        if(isChecked) {
            const availableAccs = connectedAccounts.filter(acc => {
                return (acc.platform || "").toLowerCase() === (platform || "").toLowerCase();
            });

            if(availableAccs.length > 0) {
                const options = availableAccs.map(acc => {
                    return `<option value="${escapeHtml(acc.id)}">${escapeHtml(acc.account_name)}</option>`;
                }).join("");

                const selectHtml = `
                <div class="account-selector mb-2" id="wrapper_${selectId}">
                    <label class="form-label small">
                        <i class="fa-solid fa-user-tag"></i>
                        Select ${escapeHtml(platform)} Account
                    </label>

                    <select class="form-control acc-dropdown" data-vid="${vidId}" data-plat="${escapeHtml(platform)}">
                        ${options}
                    </select>
                </div>`;

                accContainer.append(selectHtml);
                $("#wrapper_" + selectId).slideDown(180);
            } else {
                alert("No connected account found for " + platform + ". Please connect it first.");
                $(this).prop("checked", false);
            }
        } else {
            $("#wrapper_" + selectId).slideUp(180, function(){
                $(this).remove();
            });
        }
    });

    $("#generateForm").on("submit", async function(e){
        e.preventDefault();

        const qty = Math.max(1, Math.min(10, parseInt($("#videoCount").val()) || 1));
        const credits = parseInt($(".credits-display").first().text()) || 0;
        const neededCredits = qty * videoCreditCost;
        const sourceUrl = ($("#youtubeLink").val() || "").trim();
        const basePrompt = ($("#aiPrompt").val() || "").trim();
        const customScript = ($("#customScript").val() || "").trim();
        const voiceName = ($("#voiceName").val() || "Roger - Laid-Back, Casual").trim();
        const timezone = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC"; } catch (error) { return "UTC"; } })();

        if (!sourceUrl || !basePrompt) {
            alert("Add the YouTube source and your AI prompt.");
            return;
        }
        if (credits < neededCredits) {
            alert("Not enough credits. You need " + neededCredits + " credits for " + qty + " video" + (qty > 1 ? "s" : "") + ".");
            return;
        }

        const jobs = [];
        for (let i = 1; i <= qty; i++) {
            const block = $("#schedule-block-" + i);
            const date = block.find(".date-input").val();
            const time = block.find(".time-input").val();
            const tokenIds = [];
            block.find(".acc-dropdown").each(function(){ tokenIds.push(String($(this).val())); });
            if (tokenIds.length && (!date || !time)) {
                alert("Select a publish date and time for Video #" + i + ".");
                return;
            }
            jobs.push({index:i,date:date,time:time,tokenIds:tokenIds});
        }

        $("#loadingOverlay").css("display", "flex");
        $("#loadingText").text("Starting AI video generation...");
        $("#loadingSubText").text("Creating 1 of " + qty + " videos.");

        let completed = 0;
        let latestContentId = null;
        try {
            for (const job of jobs) {
                $("#loadingSubText").text("Creating " + job.index + " of " + qty + " videos.");
                const prompt = [
                    "Create an original 9:16 vertical Short.",
                    "Source topic URL for context only: " + sourceUrl + ". Do not copy source footage, logos, people, dialogue, or copyrighted visuals.",
                    "Creator prompt: " + basePrompt,
                    "Narration style: " + voiceName + ".",
                    customScript ? "Custom script/direction: " + customScript : "Use a strong hook, fast pacing, readable captions, original visuals and a satisfying ending.",
                    qty > 1 ? "This is variation " + job.index + " of " + qty + "; make it visually and narratively distinct." : ""
                ].filter(Boolean).join("\n");

                const data = new FormData();
                data.append("csrf_token", oldoraCsrf);
                data.append("media_type", "video");
                data.append("prompt", prompt);
                data.append("caption", customScript || basePrompt);
                data.append("publish_consent", "1");
                data.append("privacy_level", "public");
                data.append("timezone", timezone);
                if (job.date && job.time) data.append("scheduled_at", job.date + "T" + job.time);
                job.tokenIds.forEach(id => data.append("token_ids[]", id));

                const response = await $.ajax({
                    url: "content-create.php",
                    method: "POST",
                    data: data,
                    processData: false,
                    contentType: false,
                    dataType: "json"
                });
                if (!response.ok) throw new Error(response.message || "Video generation failed.");
                completed++;
                latestContentId = response.content_id;
                $(".credits-display").text(Math.max(0, credits - completed * videoCreditCost));
            }

            $("#loadingOverlay").hide();
            $("#modalMsg").text(qty + " video" + (qty > 1 ? "s were" : " was") + " added to the real generation queue.");
            $("#orderIdDisplay").text(latestContentId ? "Latest content ID: " + latestContentId : "");
            $("#successModal").modal("show");
            $("#aiPrompt, #customScript, #youtubeLink").val("");
            $("#videoCount").val(1);
            renderSchedulingFields();
        } catch (error) {
            $("#loadingOverlay").hide();
            const message = error.responseJSON && error.responseJSON.message ? error.responseJSON.message : (error.message || "Video generation failed.");
            alert(message + (completed ? " " + completed + " video(s) were already queued." : ""));
        }
    });

});
</script>

</body>
</html>
