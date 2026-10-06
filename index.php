<?php
ini_set('expose_php', 0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');

session_start();
require_once "connection.php";

$total_users = 0;

if (isset($con)) {
    $sql_users = "SELECT COUNT(*) as total FROM users";
    $res_users = @mysqli_query($con, $sql_users);

    if ($res_users) {
        $row = mysqli_fetch_assoc($res_users);
        $total_users = (int)$row['total'];
    }
}

$logo_path = "oldora.jpeg";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oldora.vip | AI Social Growth Automation</title>

    <meta name="description" content="Create, warm up, schedule and publish viral social media content with AI automation.">

    <link rel="icon" href="https://i.postimg.cc/bv1QQwBc/1768055586557.png" type="image/png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --bg: #030712;
            --card: rgba(255, 255, 255, 0.08);
            --card-strong: rgba(255, 255, 255, 0.13);
            --border: rgba(255, 255, 255, 0.14);
            --text: #ffffff;
            --muted: rgba(255, 255, 255, 0.65);
            --blue: #00d2ff;
            --deep-blue: #007bff;
            --purple: #8a2be2;
            --pink: #ff007a;
            --green: #2ecc71;
            --orange: #f6b93b;
            --red: #ff4757;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: "Inter", sans-serif;
            overflow-x: hidden;
        }

        body::selection {
            background: var(--blue);
            color: #000;
        }

        .world {
            position: fixed;
            inset: 0;
            z-index: -20;
            background:
                radial-gradient(circle at 15% 15%, rgba(0, 210, 255, 0.22), transparent 30%),
                radial-gradient(circle at 85% 18%, rgba(255, 0, 122, 0.18), transparent 28%),
                radial-gradient(circle at 50% 90%, rgba(138, 43, 226, 0.24), transparent 35%),
                linear-gradient(135deg, #02040c, #07111f 50%, #050015);
        }

        .aurora {
            position: fixed;
            inset: -40%;
            z-index: -19;
            background: conic-gradient(
                from 0deg,
                transparent,
                rgba(0, 210, 255, 0.18),
                transparent,
                rgba(255, 0, 122, 0.15),
                transparent,
                rgba(138, 43, 226, 0.2),
                transparent
            );
            filter: blur(90px);
            animation: rotateAurora 20s linear infinite;
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
            mask-image: linear-gradient(to bottom, transparent, black 20%, black 78%, transparent);
        }

        .noise {
            position: fixed;
            inset: 0;
            z-index: -17;
            pointer-events: none;
            opacity: 0.055;
            background-image: radial-gradient(circle, white 1px, transparent 1px);
            background-size: 4px 4px;
        }

        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(28px);
            opacity: 0.65;
            z-index: -16;
            animation: floatOrb 9s ease-in-out infinite;
        }

        .orb.one {
            width: 320px;
            height: 320px;
            background: var(--blue);
            top: 8%;
            left: 8%;
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
            left: 48%;
            animation-delay: 4s;
        }

        @keyframes floatOrb {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }

            50% {
                transform: translate(28px, -44px) scale(1.08);
            }
        }

        .cursor-glow {
            position: fixed;
            width: 430px;
            height: 430px;
            border-radius: 50%;
            pointer-events: none;
            background: radial-gradient(circle, rgba(0, 210, 255, 0.14), transparent 68%);
            transform: translate(-50%, -50%);
            z-index: 1;
        }

        .navbar {
            padding: 18px 0;
            background: rgba(3, 7, 18, 0.45);
            backdrop-filter: blur(22px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            transition: 0.3s ease;
        }

        .navbar.scrolled {
            padding: 11px 0;
            background: rgba(3, 7, 18, 0.92);
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
        }

        .navbar-brand {
            font-weight: 900;
            color: #fff !important;
            letter-spacing: -0.5px;
        }

        .navbar-brand img {
            height: 44px;
            width: 44px;
            border-radius: 15px;
            margin-right: 12px;
            object-fit: cover;
            box-shadow: 0 0 35px rgba(0, 210, 255, 0.35);
        }

        .navbar-brand span {
            font-size: 1.35rem;
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        .nav-link {
            color: rgba(255,255,255,0.72) !important;
            font-weight: 700;
            font-size: 0.95rem;
            transition: 0.25s ease;
        }

        .nav-link:hover {
            color: var(--blue) !important;
        }

        .btn-glow {
            position: relative;
            border: none;
            border-radius: 999px;
            padding: 13px 27px;
            color: white;
            font-weight: 900;
            background: linear-gradient(135deg, var(--deep-blue), var(--blue), var(--purple));
            box-shadow: 0 18px 40px rgba(0, 210, 255, 0.28);
            overflow: hidden;
            transition: 0.25s ease;
        }

        .btn-glow:hover {
            color: white;
            text-decoration: none;
            transform: translateY(-3px);
            box-shadow: 0 26px 58px rgba(0, 210, 255, 0.42);
        }

        .btn-glow::before {
            content: "";
            position: absolute;
            inset: 0;
            left: -110%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.45), transparent);
            transition: 0.55s ease;
        }

        .btn-glow:hover::before {
            left: 110%;
        }

        .btn-ghost {
            border: 1px solid rgba(255,255,255,0.16);
            color: white;
            border-radius: 999px;
            padding: 13px 27px;
            font-weight: 900;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            transition: 0.25s ease;
        }

        .btn-ghost:hover {
            color: white;
            text-decoration: none;
            transform: translateY(-3px);
            background: rgba(255,255,255,0.14);
        }

        .hero {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 150px 0 90px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.13);
            color: var(--muted);
            font-weight: 800;
            font-size: 0.85rem;
            margin-bottom: 26px;
        }

        .live-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 18px var(--green);
            animation: pulseDot 1.4s infinite;
        }

        @keyframes pulseDot {
            50% {
                transform: scale(1.6);
                opacity: 0.45;
            }
        }

        .hero h1 {
            font-size: clamp(3rem, 7vw, 6.8rem);
            line-height: 0.9;
            letter-spacing: -5px;
            font-weight: 900;
            margin-bottom: 28px;
        }

        .hero h1 span {
            display: inline-block;
            background: linear-gradient(135deg, #fff, var(--blue), var(--pink));
            -webkit-background-clip: text;
            color: transparent;
            filter: drop-shadow(0 0 35px rgba(0, 210, 255, 0.2));
        }

        .hero p {
            color: var(--muted);
            max-width: 690px;
            font-size: 1.13rem;
            line-height: 1.85;
            margin-bottom: 34px;
        }

        .hero-actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 36px;
        }

        .trust-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .trust-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.75);
            font-weight: 800;
            font-size: 0.85rem;
        }

        .hero-visual {
            position: relative;
            min-height: 620px;
            perspective: 1200px;
        }

        .command-card {
            position: absolute;
            width: 430px;
            right: 20px;
            top: 30px;
            padding: 24px;
            border-radius: 34px;
            background: rgba(255,255,255,0.09);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(30px);
            box-shadow: 0 40px 100px rgba(0,0,0,0.45);
            transform: rotateY(-10deg) rotateX(8deg);
            animation: visualFloat 5s ease-in-out infinite;
        }

        @keyframes visualFloat {
            50% {
                transform: rotateY(-10deg) rotateX(8deg) translateY(-18px);
            }
        }

        .window-dots {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        .window-dots span {
            width: 11px;
            height: 11px;
            border-radius: 50%;
        }

        .window-dots span:nth-child(1) {
            background: var(--red);
        }

        .window-dots span:nth-child(2) {
            background: var(--orange);
        }

        .window-dots span:nth-child(3) {
            background: var(--green);
        }

        .prompt-box {
            padding: 18px;
            border-radius: 22px;
            background: rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 18px;
        }

        .prompt-box small {
            color: var(--blue);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .typing {
            color: white;
            font-weight: 800;
            line-height: 1.6;
            margin-top: 8px;
        }

        .video-preview {
            height: 220px;
            border-radius: 26px;
            background:
                linear-gradient(135deg, rgba(0,210,255,0.25), rgba(138,43,226,0.25)),
                radial-gradient(circle at center, rgba(255,255,255,0.18), transparent 45%),
                #080b18;
            border: 1px solid rgba(255,255,255,0.12);
            display: grid;
            place-items: center;
            position: relative;
            overflow: hidden;
        }

        .video-preview::before {
            content: "";
            position: absolute;
            width: 150%;
            height: 80px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.22), transparent);
            transform: rotate(-18deg);
            animation: scanVideo 3.4s linear infinite;
        }

        @keyframes scanVideo {
            from {
                top: -80px;
                left: -100%;
            }

            to {
                top: 100%;
                left: 100%;
            }
        }

        .play {
            width: 74px;
            height: 74px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--blue), var(--purple));
            box-shadow: 0 0 40px rgba(0, 210, 255, 0.42);
            z-index: 2;
            font-size: 1.4rem;
        }

        .mini-panel {
            position: absolute;
            width: 230px;
            padding: 20px;
            border-radius: 28px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.14);
            backdrop-filter: blur(28px);
            box-shadow: 0 30px 80px rgba(0,0,0,0.38);
        }

        .panel-one {
            left: 0;
            bottom: 120px;
            animation: panelMove 5s ease-in-out infinite;
        }

        .panel-two {
            right: 0;
            bottom: 30px;
            animation: panelMove 5s ease-in-out infinite 1.5s;
        }

        @keyframes panelMove {
            50% {
                transform: translateY(-16px);
            }
        }

        .mini-panel i {
            font-size: 1.7rem;
            color: var(--blue);
            margin-bottom: 13px;
        }

        .mini-panel strong {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 4px;
        }

        .mini-panel span {
            color: var(--muted);
            font-size: 0.85rem;
            font-weight: 700;
        }

        .section {
            padding: 100px 0;
            position: relative;
        }

        .section-title {
            text-align: center;
            margin-bottom: 64px;
        }

        .section-title .eyebrow {
            color: var(--blue);
            font-weight: 900;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-size: 0.82rem;
            margin-bottom: 12px;
        }

        .section-title h2 {
            font-size: clamp(2.3rem, 4vw, 4rem);
            font-weight: 900;
            letter-spacing: -2.5px;
            margin-bottom: 14px;
        }

        .section-title p {
            color: var(--muted);
            max-width: 640px;
            margin: auto;
            line-height: 1.75;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .feature-card {
            position: relative;
            padding: 30px;
            border-radius: 32px;
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(25px);
            transition: 0.28s ease;
            overflow: hidden;
            min-height: 260px;
        }

        .feature-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(0,210,255,0.15), transparent, rgba(255,0,122,0.12));
            opacity: 0;
            transition: 0.28s ease;
        }

        .feature-card:hover {
            transform: translateY(-12px);
            border-color: rgba(0,210,255,0.45);
            box-shadow: 0 30px 80px rgba(0,0,0,0.35);
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-card > * {
            position: relative;
            z-index: 2;
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            border-radius: 22px;
            display: grid;
            place-items: center;
            font-size: 1.65rem;
            margin-bottom: 24px;
            background: rgba(255,255,255,0.09);
            border: 1px solid rgba(255,255,255,0.12);
            color: var(--blue);
        }

        .feature-card h3 {
            font-size: 1.25rem;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .feature-card p {
            color: var(--muted);
            line-height: 1.75;
            font-size: 0.95rem;
        }

        .workflow-wrap {
            position: relative;
        }

        .workflow-line {
            position: absolute;
            top: 82px;
            left: 7%;
            width: 86%;
            height: 4px;
            border-radius: 999px;
            background: rgba(255,255,255,0.1);
            overflow: hidden;
        }

        .workflow-line::after {
            content: "";
            position: absolute;
            inset: 0;
            width: 0;
            background: linear-gradient(90deg, var(--deep-blue), var(--blue), var(--purple), var(--pink));
            animation: workflowFill 4s ease forwards;
        }

        @keyframes workflowFill {
            to {
                width: 100%;
            }
        }

        .workflow-grid {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
        }

        .step-card {
            position: relative;
            min-height: 310px;
            padding: 26px 20px;
            text-align: center;
            border-radius: 30px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.13);
            backdrop-filter: blur(25px);
            overflow: hidden;
            transition: 0.28s ease;
        }

        .step-card:hover {
            transform: translateY(-14px);
            border-color: rgba(0,210,255,0.45);
            box-shadow: 0 30px 80px rgba(0,0,0,0.35);
        }

        .step-number {
            position: absolute;
            right: 18px;
            top: 12px;
            font-size: 4rem;
            font-weight: 900;
            color: rgba(255,255,255,0.045);
            line-height: 1;
        }

        .step-icon {
            position: relative;
            z-index: 2;
            width: 84px;
            height: 84px;
            margin: 0 auto 22px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #030712;
            border: 2px solid var(--blue);
            color: var(--blue);
            font-size: 2rem;
            box-shadow: 0 0 30px rgba(0,210,255,0.2);
        }

        .step-card.warmup .step-icon {
            border-color: var(--red);
            color: var(--red);
            animation: warmPulse 2s infinite;
        }

        @keyframes warmPulse {
            70% {
                box-shadow: 0 0 0 16px rgba(255,71,87,0);
            }

            0%, 100% {
                box-shadow: 0 0 0 0 rgba(255,71,87,0.36);
            }
        }

        .step-card h4 {
            position: relative;
            z-index: 2;
            font-size: 1.1rem;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .step-card p {
            position: relative;
            z-index: 2;
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.65;
        }

        .pricing-preview {
            border-radius: 40px;
            padding: 42px;
            background:
                linear-gradient(135deg, rgba(0,210,255,0.12), rgba(255,0,122,0.09)),
                rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(30px);
            box-shadow: 0 35px 90px rgba(0,0,0,0.35);
            overflow: hidden;
            position: relative;
        }

        .pricing-preview::before {
            content: "";
            position: absolute;
            width: 380px;
            height: 380px;
            border-radius: 50%;
            background: rgba(0,210,255,0.14);
            filter: blur(45px);
            right: -130px;
            top: -130px;
        }

        .pricing-preview h2 {
            font-size: clamp(2rem, 4vw, 3.4rem);
            font-weight: 900;
            letter-spacing: -2px;
            margin-bottom: 16px;
        }

        .pricing-preview p {
            color: var(--muted);
            line-height: 1.8;
            max-width: 650px;
            margin-bottom: 0;
        }

        .cta-box {
            padding: 55px;
            text-align: center;
            border-radius: 42px;
            background:
                radial-gradient(circle at 20% 30%, rgba(0,210,255,0.24), transparent 35%),
                radial-gradient(circle at 80% 20%, rgba(255,0,122,0.18), transparent 35%),
                rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.16);
            backdrop-filter: blur(30px);
            box-shadow: 0 35px 90px rgba(0,0,0,0.35);
        }

        .cta-box h2 {
            font-size: clamp(2.2rem, 5vw, 4.2rem);
            font-weight: 900;
            letter-spacing: -3px;
            margin-bottom: 18px;
        }

        .cta-box p {
            max-width: 640px;
            margin: 0 auto 30px;
            color: var(--muted);
            line-height: 1.8;
        }

        footer {
            padding: 48px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.38);
            backdrop-filter: blur(20px);
        }

        .footer-brand {
            font-size: 1.4rem;
            font-weight: 900;
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        .social-icons a {
            width: 43px;
            height: 43px;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.72);
            margin: 0 5px;
            transition: 0.25s ease;
        }

        .social-icons a:hover {
            color: white;
            transform: translateY(-4px);
            background: rgba(0,210,255,0.18);
            text-decoration: none;
        }

        .reveal {
            opacity: 0;
            transform: translateY(35px);
            transition: 0.7s ease;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 1199px) {
            .hero-visual {
                min-height: 520px;
            }

            .command-card {
                right: 0;
                width: 390px;
            }

            .workflow-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .workflow-line {
                display: none;
            }
        }

        @media (max-width: 991px) {
            .hero {
                text-align: center;
                padding-top: 140px;
            }

            .hero p {
                margin-left: auto;
                margin-right: auto;
            }

            .hero-actions,
            .trust-row {
                justify-content: center;
            }

            .hero-visual {
                margin-top: 50px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .hero h1 {
                letter-spacing: -3px;
            }

            .hero-visual {
                min-height: 560px;
            }

            .command-card {
                width: 100%;
                left: 0;
                right: 0;
            }

            .mini-panel {
                width: 48%;
            }

            .workflow-grid {
                grid-template-columns: 1fr;
            }

            .step-card {
                min-height: auto;
            }

            .pricing-preview,
            .cta-box {
                padding: 32px 24px;
                border-radius: 30px;
            }
        }
    </style>
</head>

<body>

    <div class="cursor-glow" id="cursorGlow"></div>

    <div class="world"></div>
    <div class="aurora"></div>
    <div class="grid"></div>
    <div class="noise"></div>
    <div class="orb one"></div>
    <div class="orb two"></div>
    <div class="orb three"></div>

    <nav class="navbar navbar-expand-lg navbar-dark fixed-top" id="navbar">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="<?php echo htmlspecialchars($logo_path); ?>" onerror="this.style.display='none'" alt="Oldora logo">
                <span>Oldora.vip</span>
            </a>

            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#how-it-works">Workflow</a></li>
                    <li class="nav-item"><a class="nav-link" href="planing.php">Pricing</a></li>
                </ul>

                <div class="form-inline ml-lg-3 mt-3 mt-lg-0">
                    <?php if(isset($_SESSION['email'])): ?>
                        <a href="home.php" class="btn-glow">
                            Dashboard <i class="fa-solid fa-arrow-right ml-2"></i>
                        </a>
                    <?php else: ?>
                        <a href="login-user.php" class="nav-link text-white mr-lg-3">Login</a>
                        <a href="signup-user.php" class="btn-glow">Get Started</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <section class="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 reveal active">
                    <div class="hero-badge">
                        <span class="live-dot"></span>
                        AI automation for creators, brands and agencies
                    </div>

                    <h1>
                        Create. Warm up. <br>
                        <span>Go viral.</span>
                    </h1>

                    <p>
                        Oldora turns your prompt or video link into ready-to-post social content,
                        warms up your accounts, schedules your posts, and helps you grow with a
                        complete AI-powered content automation workflow.
                    </p>

                    <div class="hero-actions">
                        <a href="signup-user.php" class="btn-glow btn-lg">
                            <i class="fa-solid fa-rocket mr-2"></i>
                            Start for free
                        </a>

                        <a href="#how-it-works" class="btn-ghost btn-lg">
                            <i class="fa-solid fa-play mr-2"></i>
                            See workflow
                        </a>
                    </div>

                    <div class="trust-row">
                        <div class="trust-pill">
                            <i class="fa-solid fa-users"></i>
                            <span id="userCounter">0</span> creators joined
                        </div>

                        <div class="trust-pill">
                            <i class="fa-solid fa-shield-halved"></i>
                            Warm-up protocol
                        </div>

                        <div class="trust-pill">
                            <i class="fa-solid fa-calendar-check"></i>
                            Auto scheduling
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="hero-visual reveal active">
                        <div class="command-card" id="commandCard">
                            <div class="window-dots">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>

                            <div class="prompt-box">
                                <small>AI Prompt</small>
                                <div class="typing" id="typingText"></div>
                            </div>

                            <div class="video-preview">
                                <div class="play">
                                    <i class="fa-solid fa-play"></i>
                                </div>
                            </div>
                        </div>

                        <div class="mini-panel panel-one">
                            <i class="fa-solid fa-fire"></i>
                            <strong>Warm-up</strong>
                            <span>Trust-building activity before publishing</span>
                        </div>

                        <div class="mini-panel panel-two">
                            <i class="fa-solid fa-chart-line"></i>
                            <strong>Growth</strong>
                            <span>Content queued for reach and consistency</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="section">
        <div class="container">
            <div class="section-title reveal">
                <div class="eyebrow">Why Oldora</div>
                <h2>Everything you need to automate content.</h2>
                <p>
                    From idea generation to publishing, Oldora gives creators a complete
                    automation system for modern social platforms.
                </p>
            </div>

            <div class="features-grid">
                <div class="feature-card reveal">
                    <div class="feature-icon">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </div>
                    <h3>Prompt to content</h3>
                    <p>
                        Write a simple idea and let AI generate videos, captions,
                        hooks and content structures ready for social media.
                    </p>
                </div>

                <div class="feature-card reveal">
                    <div class="feature-icon">
                        <i class="fa-solid fa-link"></i>
                    </div>
                    <h3>Link to content</h3>
                    <p>
                        Paste a YouTube link and transform it into short-form content
                        concepts designed for faster republishing.
                    </p>
                </div>

                <div class="feature-card reveal">
                    <div class="feature-icon">
                        <i class="fa-solid fa-fire-flame-curved"></i>
                    </div>
                    <h3>Account warm-up</h3>
                    <p>
                        Warm-up actions help your connected accounts look active before
                        posting, improving consistency and trust.
                    </p>
                </div>

                <div class="feature-card reveal">
                    <div class="feature-icon">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <h3>Smart scheduling</h3>
                    <p>
                        Plan your publishing calendar and queue your posts automatically
                        instead of posting manually every day.
                    </p>
                </div>

                <div class="feature-card reveal">
                    <div class="feature-icon">
                        <i class="fa-solid fa-chart-simple"></i>
                    </div>
                    <h3>Growth dashboard</h3>
                    <p>
                        Track your workflow, content pipeline and posting activity from
                        one clean creator dashboard.
                    </p>
                </div>

                <div class="feature-card reveal">
                    <div class="feature-icon">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                    <h3>Fast execution</h3>
                    <p>
                        Move from idea to scheduled content faster with a system built
                        for creators who want speed and consistency.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="section">
        <div class="container">
            <div class="section-title reveal">
                <div class="eyebrow">Automated workflow</div>
                <h2>From idea to viral engine in 5 steps.</h2>
                <p>
                    A clear automation pipeline designed to make content creation,
                    account preparation and publishing easier.
                </p>
            </div>

            <div class="workflow-wrap">
                <div class="workflow-line"></div>

                <div class="workflow-grid">
                    <div class="step-card reveal">
                        <span class="step-number">01</span>
                        <div class="step-icon">
                            <i class="fa-solid fa-lightbulb"></i>
                        </div>
                        <h4>Input idea</h4>
                        <p>Write a prompt or paste a content link to start the automation process.</p>
                    </div>

                    <div class="step-card reveal">
                        <span class="step-number">02</span>
                        <div class="step-icon" style="border-color: var(--purple); color: var(--purple);">
                            <i class="fa-solid fa-video"></i>
                        </div>
                        <h4>AI generation</h4>
                        <p>Generate videos, images, captions and post-ready creative assets.</p>
                    </div>

                    <div class="step-card warmup reveal">
                        <span class="step-number">03</span>
                        <div class="step-icon">
                            <i class="fa-solid fa-fire"></i>
                        </div>
                        <h4>Link & warm-up</h4>
                        <p>Connect your accounts and run warm-up activity before publishing.</p>
                    </div>

                    <div class="step-card reveal">
                        <span class="step-number">04</span>
                        <div class="step-icon" style="border-color: var(--orange); color: var(--orange);">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                        <h4>Smart schedule</h4>
                        <p>Choose publishing dates and let the system organize your content queue.</p>
                    </div>

                    <div class="step-card reveal">
                        <span class="step-number">05</span>
                        <div class="step-icon" style="border-color: var(--green); color: var(--green);">
                            <i class="fa-solid fa-arrow-trend-up"></i>
                        </div>
                        <h4>Viral growth</h4>
                        <p>Publish consistently and build organic momentum across your channels.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pt-4">
        <div class="container">
            <div class="pricing-preview reveal">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <h2>Built for creators who want speed.</h2>
                        <p>
                            Oldora helps you skip repetitive manual posting and focus on the ideas,
                            offers and content direction that matter most.
                        </p>
                    </div>

                    <div class="col-lg-4 text-lg-right mt-4 mt-lg-0">
                        <a href="planing.php" class="btn-glow btn-lg">
                            View pricing
                            <i class="fa-solid fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pt-4">
        <div class="container">
            <div class="cta-box reveal">
                <h2>Ready to automate your growth?</h2>
                <p>
                    Start creating content faster, prepare your accounts smarter,
                    and build a consistent social media engine with Oldora.
                </p>

                <a href="signup-user.php" class="btn-glow btn-lg">
                    <i class="fa-solid fa-rocket mr-2"></i>
                    Create your account
                </a>
            </div>
        </div>
    </section>

    <footer>
        <div class="container text-center">
            <div class="footer-brand mb-3">Oldora.vip</div>

            <div class="social-icons mb-4">
                <a href="#"><i class="fab fa-tiktok"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>

            <p class="small text-muted mb-0">
                &copy; <?php echo date('Y'); ?> Oldora. The future of AI content automation.
            </p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const usersCount = <?php echo $total_users; ?>;
        const cursorGlow = document.getElementById("cursorGlow");
        const commandCard = document.getElementById("commandCard");
        const typingText = document.getElementById("typingText");
        const text = "Create 10 viral reels for my brand, add hooks, captions, and schedule them this week.";

        document.addEventListener("mousemove", function(e) {
            cursorGlow.style.left = e.clientX + "px";
            cursorGlow.style.top = e.clientY + "px";

            if (!commandCard) return;

            const rect = commandCard.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            if (x > 0 && x < rect.width && y > 0 && y < rect.height) {
                const rotateY = ((x / rect.width) - 0.5) * 12;
                const rotateX = ((y / rect.height) - 0.5) * -12;
                commandCard.style.transform = `rotateY(${rotateY - 10}deg) rotateX(${rotateX + 8}deg)`;
            }
        });

        if (commandCard) {
            commandCard.addEventListener("mouseleave", function() {
                commandCard.style.transform = "rotateY(-10deg) rotateX(8deg)";
            });
        }

        let typingIndex = 0;

        function typeWriter() {
            if (typingIndex <= text.length) {
                typingText.textContent = text.slice(0, typingIndex);
                typingIndex++;
                setTimeout(typeWriter, 35);
            } else {
                setTimeout(function() {
                    typingIndex = 0;
                    typeWriter();
                }, 2500);
            }
        }

        typeWriter();

        $(window).scroll(function() {
            if ($(window).scrollTop() > 50) {
                $("#navbar").addClass("scrolled");
            } else {
                $("#navbar").removeClass("scrolled");
            }
        });

        function animateCounter() {
            const counterElement = $("#userCounter");
            let current = 0;
            let finalCount = usersCount;

            if (finalCount <= 0) {
                counterElement.text("0");
                return;
            }

            let step = Math.max(1, Math.ceil(finalCount / 100));

            const interval = setInterval(function() {
                current += step;

                if (current >= finalCount) {
                    current = finalCount;
                    clearInterval(interval);
                }

                counterElement.text(current.toLocaleString());
            }, 18);
        }

        animateCounter();

        function revealOnScroll() {
            const reveals = document.querySelectorAll(".reveal");

            reveals.forEach(function(element) {
                const windowHeight = window.innerHeight;
                const elementTop = element.getBoundingClientRect().top;
                const revealPoint = 100;

                if (elementTop < windowHeight - revealPoint) {
                    element.classList.add("active");
                }
            });
        }

        window.addEventListener("scroll", revealOnScroll);
        revealOnScroll();
    </script>
</body>
</html>