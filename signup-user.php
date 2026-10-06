<?php
require_once "connection.php";

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', $isHttps ? 1 : 0);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

if (isset($_GET['cancel']) && $_GET['cancel'] === '1') {
    unset($_SESSION['signup_data'], $_SESSION['captcha_code']);
    header('Location: signup-user.php');
    exit;
}

$errors = [];
$info = "";
$show_otp_form = isset($_SESSION['signup_data']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$envPath = __DIR__ . '/../.env';
$env = [];

if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
}

function envv($env, $key, $default = '') {
    if (!isset($env[$key])) return $default;
    return trim(trim((string)$env[$key]), "\"'");
}

require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function buildOldoraEmailTemplate($subject, $htmlContent) {
    $logoUrl = "https://oldora.vip/Logo.png";
    $siteName = "OLDORA";
    $year = date("Y");
    $subjectSafe = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    return '
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#09090b;font-family:Arial,sans-serif;color:#ffffff;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.$subjectSafe.'</div>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#09090b;padding:24px 12px;">
<tr>
<td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" width="560"
style="max-width:560px;width:100%;border-radius:26px;overflow:hidden;
background:radial-gradient(circle at 50% 0%,#1a1a2e 0%,#000000 100%);
border:1px solid rgba(255,255,255,0.12);box-shadow:0 24px 60px rgba(0,0,0,0.6);">
<tr>
<td style="padding:34px 24px 10px;text-align:center;">
<img src="'.$logoUrl.'" width="118" alt="'.$siteName.'" style="display:block;margin:0 auto 14px;">
<div style="font-size:23px;font-weight:900;letter-spacing:2px;
background:linear-gradient(135deg,#00C6FF,#0072FF);-webkit-background-clip:text;background-clip:text;color:transparent;">
'.$siteName.'
</div>
<div style="font-size:13px;color:#9aa0a6;margin-top:8px;">'.$subjectSafe.'</div>
</td>
</tr>
<tr>
<td style="padding:16px 18px 30px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
style="border-radius:22px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);">
<tr>
<td style="padding:24px 20px;color:#e8eaed;line-height:1.8;font-size:14px;">
'.$htmlContent.'
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:0 24px 24px;text-align:center;color:#6b7280;font-size:12px;line-height:1.6;">
© '.$year.' '.$siteName.'. All rights reserved.
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';
}

function sendOldoraMail($env, $toEmail, $subject, $htmlContent) {
    $smtpHost = envv($env, 'SMTP_HOST');
    $smtpUser = envv($env, 'SMTP_USER');
    $smtpPass = envv($env, 'SMTP_PASS');
    $smtpPort = (int) envv($env, 'SMTP_PORT', '587');
    $smtpEnc  = strtolower(envv($env, 'SMTP_ENCRYPTION', ''));

    if (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
        return false;
    }

    if ($smtpEnc === '') {
        $smtpEnc = ($smtpPort === 465) ? 'ssl' : 'tls';
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = ($smtpEnc === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtpUser, 'OLDORA');
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = buildOldoraEmailTemplate($subject, $htmlContent);
        $mail->AltBody = strip_tags($htmlContent);
        $mail->send();

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getIPAddress() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function generateReferralCode($length = 8) {
    return strtoupper(substr(bin2hex(random_bytes(12)), 0, $length));
}

function refreshCaptcha() {
    $_SESSION['captcha_code'] = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
}

if (empty($_SESSION['captcha_code']) && !$show_otp_form) {
    refreshCaptcha();
}

$captcha_code = $_SESSION['captcha_code'] ?? '';

$name = "";
$email = "";
$referral_code_input = "";

if (isset($_POST['signup'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF");
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $cpassword = $_POST['cpassword'] ?? '';
    $referral_code_input = trim($_POST['referral_code'] ?? '');
    $entered_captcha = strtoupper(trim($_POST['captcha_input'] ?? ''));

    if ($name === '' || mb_strlen($name) < 3) {
        $errors['name'] = "Full name must contain at least 3 characters.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email_format'] = "Please enter a valid email address.";
    }

    if (strlen($password) < 8) {
        $errors['password_length'] = "Password must contain at least 8 characters.";
    }

    if ($password !== $cpassword) {
        $errors['password_match'] = "Confirm password does not match.";
    }

    if ($entered_captcha !== ($_SESSION['captcha_code'] ?? '')) {
        $errors['captcha'] = "Incorrect captcha code.";
        refreshCaptcha();
        $captcha_code = $_SESSION['captcha_code'];
    }

    if (empty($errors)) {
        $stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $emailCheck = $stmt->get_result();

        if ($emailCheck->num_rows > 0) {
            $errors['email_exists'] = "Email already exists.";
        }
    }

    $referrer_id = null;

    if (empty($errors) && $referral_code_input !== '') {
        $stmt = $con->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
        $stmt->bind_param("s", $referral_code_input);
        $stmt->execute();
        $refResult = $stmt->get_result();

        if ($refResult->num_rows > 0) {
            $referrer = $refResult->fetch_assoc();
            $referrer_id = (int)$referrer['id'];
        } else {
            $errors['referral'] = "Invalid referral code.";
        }
    }

    if (empty($errors)) {
        $otp = random_int(100000, 999999);

        $_SESSION['signup_data'] = [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'referrer_id' => $referrer_id,
            'ip' => getIPAddress(),
            'otp' => (string)$otp,
            'created_at' => time()
        ];

        $subject = "Verify your email - OLDORA";

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars((string)$otp, ENT_QUOTES, 'UTF-8');

        $message = "
            <h2 style='margin:0 0 12px;color:#00C6FF;'>Welcome, {$safeName}!</h2>
            <p>Your OLDORA verification code is:</p>
            <div style='font-size:34px;font-weight:900;letter-spacing:7px;color:#ffffff;
                        background:rgba(255,255,255,0.1);display:inline-block;
                        padding:14px 24px;border-radius:14px;margin:10px 0 16px;'>
                {$safeOtp}
            </div>
            <p style='color:#9aa0a6;'>This code is valid for 10 minutes.</p>
            <p style='color:#ff6b8b;font-size:12px;'>
                If you did not request this account, ignore this email.
            </p>
        ";

        if (sendOldoraMail($env, $email, $subject, $message)) {
            $show_otp_form = true;
            $info = "Verification code sent to " . htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        } else {
            unset($_SESSION['signup_data']);
            $errors['mail'] = "Failed to send verification email. Check SMTP settings.";
        }
    }
}

if (isset($_POST['verify_email'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF");
    }

    $show_otp_form = true;
    $otp_code = preg_replace('/\D/', '', $_POST['otp'] ?? '');

    if ($otp_code === '' && isset($_POST['otp_digit']) && is_array($_POST['otp_digit'])) {
        foreach ($_POST['otp_digit'] as $digit) {
            $otp_code .= substr(preg_replace('/\D/', '', (string)$digit), 0, 1);
        }
        $otp_code = substr($otp_code, 0, 6);
    }

    if (!isset($_SESSION['signup_data'])) {
        $errors['session'] = "Session expired. Please restart signup.";
        $show_otp_form = false;
        refreshCaptcha();
        $captcha_code = $_SESSION['captcha_code'];
    } else {
        $data = $_SESSION['signup_data'];

        if (time() - ($data['created_at'] ?? 0) > 600) {
            $errors['expired'] = "Verification code expired. Please restart signup.";
            unset($_SESSION['signup_data']);
            $show_otp_form = false;
            refreshCaptcha();
            $captcha_code = $_SESSION['captcha_code'];
        } elseif (strlen($otp_code) !== 6) {
            $errors['otp'] = "Please enter all 6 digits.";
        } elseif (!hash_equals((string)$data['otp'], (string)$otp_code)) {
            $errors['otp'] = "Invalid verification code.";
        } else {
            $encpass = password_hash($data['password'], PASSWORD_BCRYPT);

            do {
                $my_referral_code = generateReferralCode();
                $stmt = $con->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
                $stmt->bind_param("s", $my_referral_code);
                $stmt->execute();
                $exists = $stmt->get_result();
            } while ($exists->num_rows > 0);

            $fullname = $data['name'];
            $u_email = $data['email'];
            $ip_addr = $data['ip'];
            $role = "user";
            $status = "active";
            $plan_type = "free";
            $is_paid = 0;
            $credits = 0;
            $referral_count = 0;
            $referrer_id = $data['referrer_id'];

            $stmt = $con->prepare("
                INSERT INTO users 
                (full_name, email, password, ip_address, role, status, plan_type, is_paid, referral_code, referred_by, credits, referral_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sssssssisiii",
                $fullname,
                $u_email,
                $encpass,
                $ip_addr,
                $role,
                $status,
                $plan_type,
                $is_paid,
                $my_referral_code,
                $referrer_id,
                $credits,
                $referral_count
            );

            if ($stmt->execute()) {
                if (!empty($referrer_id)) {
                    $stmtRef = $con->prepare("UPDATE users SET referral_count = referral_count + 1, credits = credits + 1 WHERE id = ?");
                    $stmtRef->bind_param("i", $referrer_id);
                    $stmtRef->execute();
                }

                setcookie("device_registered_token", "1", [
                    'expires' => time() + (86400 * 365),
                    'path' => '/',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);

                session_regenerate_id(true);

                $_SESSION['email'] = $u_email;
                $_SESSION['name'] = $fullname;
                $_SESSION['role'] = 'user';

                unset($_SESSION['captcha_code']);
                unset($_SESSION['signup_data']);

                header('location: home.php');
                exit();
            } else {
                $errors['db'] = "Database error: failed to create account.";
            }
        }
    }
}

$refValue = "";
if (isset($_GET['ref'])) {
    $refValue = htmlspecialchars($_GET['ref'], ENT_QUOTES, 'UTF-8');
} elseif (isset($_POST['referral_code'])) {
    $refValue = htmlspecialchars($_POST['referral_code'], ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Signup | OLDORA</title>

    <link rel="icon" href="Logo.png" type="image/png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --bg: #030712;
            --card: rgba(255, 255, 255, 0.08);
            --border: rgba(255, 255, 255, 0.15);
            --text: #ffffff;
            --muted: rgba(255,255,255,0.64);
            --input: rgba(255,255,255,0.09);
            --blue: #00d2ff;
            --deep: #0072ff;
            --purple: #8a2be2;
            --pink: #ff007a;
            --green: #35ffb6;
            --red: #ff477e;
            --orange: #ffb703;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", sans-serif;
        }

        body {
            min-height: 100vh;
            color: var(--text);
            background: var(--bg);
            overflow-x: hidden;
        }

        .world {
            position: fixed;
            inset: 0;
            z-index: -20;
            background:
                radial-gradient(circle at 15% 15%, rgba(0,210,255,0.24), transparent 32%),
                radial-gradient(circle at 84% 18%, rgba(255,0,122,0.18), transparent 30%),
                radial-gradient(circle at 52% 92%, rgba(138,43,226,0.24), transparent 36%),
                linear-gradient(135deg, #02040c, #07111f 55%, #080015);
        }

        .aurora {
            position: fixed;
            inset: -45%;
            z-index: -19;
            background: conic-gradient(
                from 0deg,
                transparent,
                rgba(0,210,255,0.2),
                transparent,
                rgba(255,0,122,0.16),
                transparent,
                rgba(138,43,226,0.23),
                transparent
            );
            filter: blur(95px);
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
            transform: perspective(800px) rotateX(60deg) scale(1.7) translateY(160px);
            transform-origin: bottom;
            mask-image: linear-gradient(to top, black, transparent 75%);
            animation: gridMove 7s linear infinite;
        }

        @keyframes gridMove {
            to {
                background-position: 0 54px;
            }
        }

        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(28px);
            opacity: 0.62;
            z-index: -17;
            animation: floatOrb 9s ease-in-out infinite;
        }

        .orb.one {
            width: 310px;
            height: 310px;
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

        .noise {
            position: fixed;
            inset: 0;
            z-index: -16;
            opacity: 0.055;
            background-image: radial-gradient(circle, white 1px, transparent 1px);
            background-size: 4px 4px;
            pointer-events: none;
        }

        .cursor-glow {
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            pointer-events: none;
            background: radial-gradient(circle, rgba(0,210,255,0.15), transparent 68%);
            transform: translate(-50%, -50%);
            z-index: 1;
        }

        .page {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            padding: 28px;
        }

        .hero {
            position: relative;
            border-radius: 40px;
            padding: 42px;
            background: linear-gradient(145deg, rgba(255,255,255,0.12), rgba(255,255,255,0.04));
            border: 1px solid rgba(255,255,255,0.14);
            backdrop-filter: blur(28px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 40px 110px rgba(0,0,0,0.45);
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,0.12), transparent);
            transform: translateX(-100%);
            animation: shine 7s ease-in-out infinite;
        }

        @keyframes shine {
            0%, 70% {
                transform: translateX(-100%);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .brand {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 900;
            font-size: 24px;
        }

        .brand img {
            width: 58px;
            height: 58px;
            border-radius: 20px;
            object-fit: cover;
            box-shadow: 0 0 40px rgba(0,210,255,0.4);
        }

        .brand span {
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.09);
            border: 1px solid rgba(255,255,255,0.14);
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 26px;
        }

        .pulse-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 18px var(--green);
            animation: pulseDot 1.4s infinite;
        }

        @keyframes pulseDot {
            50% {
                transform: scale(1.55);
                opacity: 0.5;
            }
        }

        .hero h1 {
            font-size: clamp(46px, 7vw, 86px);
            line-height: 0.9;
            letter-spacing: -5px;
            font-weight: 900;
            margin-bottom: 24px;
        }

        .hero h1 span {
            background: linear-gradient(135deg, #fff, var(--blue), var(--pink));
            -webkit-background-clip: text;
            color: transparent;
            filter: drop-shadow(0 0 35px rgba(0,210,255,0.22));
        }

        .hero p {
            color: var(--muted);
            font-size: 17px;
            line-height: 1.8;
            max-width: 600px;
        }

        .benefits {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .benefit {
            padding: 18px;
            border-radius: 24px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
        }

        .benefit i {
            color: var(--blue);
            font-size: 22px;
            margin-bottom: 12px;
        }

        .benefit strong {
            display: block;
            font-size: 15px;
            margin-bottom: 5px;
        }

        .benefit span {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .signup-zone {
            display: flex;
            align-items: center;
            justify-content: center;
            perspective: 1200px;
        }

        .container-box {
            position: relative;
            width: 100%;
            max-width: 500px;
            padding: 34px;
            border-radius: 38px;
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(34px);
            -webkit-backdrop-filter: blur(34px);
            box-shadow:
                0 40px 110px rgba(0,0,0,0.48),
                inset 0 1px 0 rgba(255,255,255,0.22);
            overflow: hidden;
            transform-style: flat;
            animation: cardEnter 0.8s ease forwards;
        }

        .container-box::before {
            content: "";
            position: absolute;
            inset: -2px;
            background: linear-gradient(135deg, var(--blue), transparent, var(--pink), transparent, var(--purple));
            filter: blur(20px);
            opacity: 0.32;
            z-index: -1;
        }

        @keyframes cardEnter {
            from {
                opacity: 0;
                transform: translateY(35px) rotateX(10deg) scale(0.96);
            }

            to {
                opacity: 1;
                transform: translateY(0) rotateX(0deg) scale(1);
            }
        }

        .top-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .mini-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 900;
        }

        .logo-img {
            width: 58px;
            height: 58px;
            border-radius: 20px;
            object-fit: cover;
            box-shadow: 0 0 35px rgba(0,210,255,0.35);
        }

        .brand-name {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 1px;
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        .home-link {
            width: 48px;
            height: 48px;
            border-radius: 17px;
            display: grid;
            place-items: center;
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.14);
            transition: 0.25s ease;
        }

        .home-link:hover {
            color: white;
            transform: translateY(-3px) rotate(8deg);
            background: rgba(255,255,255,0.16);
        }

        .title {
            font-size: 34px;
            font-weight: 900;
            letter-spacing: -1.5px;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 22px;
        }

        .alert-error,
        .alert-info {
            text-align: left;
            padding: 14px 15px;
            border-radius: 18px;
            font-size: 13px;
            line-height: 1.65;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .alert-error {
            background: rgba(255,71,126,0.12);
            border: 1px solid rgba(255,71,126,0.32);
            color: #ff87a9;
        }

        .alert-info {
            background: rgba(0,210,255,0.1);
            border: 1px solid rgba(0,210,255,0.28);
            color: var(--blue);
        }

        .alert-error i,
        .alert-info i {
            margin-right: 8px;
        }

        .input-group {
            position: relative;
            margin-bottom: 16px;
        }

        .input-field {
            width: 100%;
            height: 60px;
            border-radius: 19px;
            border: 1px solid rgba(255,255,255,0.15);
            background: var(--input);
            color: white;
            outline: none;
            font-size: 15px;
            padding: 20px 52px 8px 48px;
            transition: 0.25s ease;
        }

        .input-field::placeholder {
            color: transparent;
        }

        .floating-label {
            position: absolute;
            left: 48px;
            top: 19px;
            color: var(--muted);
            pointer-events: none;
            font-size: 15px;
            transition: 0.22s ease;
        }

        .input-field:focus,
        .input-field:not(:placeholder-shown) {
            border-color: var(--blue);
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 4px rgba(0,210,255,0.12);
        }

        .input-field:focus ~ .floating-label,
        .input-field:not(:placeholder-shown) ~ .floating-label {
            top: 7px;
            font-size: 11px;
            color: var(--blue);
            font-weight: 900;
        }

        .input-icon {
            position: absolute;
            left: 17px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 17px;
        }

        .toggle-password {
            position: absolute;
            right: 17px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            cursor: pointer;
            font-size: 17px;
        }

        .strength {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 7px;
            margin: -4px 0 15px;
        }

        .strength span {
            height: 5px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            transition: 0.25s ease;
        }

        .strength span.active:nth-child(1) { background: var(--red); }
        .strength span.active:nth-child(2) { background: var(--orange); }
        .strength span.active:nth-child(3) { background: var(--blue); }
        .strength span.active:nth-child(4) { background: var(--green); }

        .captcha-row {
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .captcha-display {
            height: 60px;
            border-radius: 19px;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                linear-gradient(135deg, rgba(0,210,255,0.12), rgba(255,0,122,0.1)),
                rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: white;
            font-family: "Courier New", monospace;
            font-weight: 900;
            font-size: 20px;
            letter-spacing: 5px;
            text-decoration: line-through;
            user-select: none;
        }

        .btn-submit {
            position: relative;
            width: 100%;
            height: 62px;
            border: none;
            border-radius: 20px;
            color: white;
            font-weight: 900;
            font-size: 16px;
            cursor: pointer;
            background: linear-gradient(135deg, var(--deep), var(--blue), var(--purple));
            box-shadow: 0 23px 50px rgba(0,210,255,0.28);
            overflow: hidden;
            transition: 0.25s ease;
        }

        .btn-submit:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 70px rgba(0,210,255,0.4);
        }

        .btn-submit::before {
            content: "";
            position: absolute;
            inset: 0;
            left: -110%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
            transition: 0.55s ease;
        }

        .btn-submit:hover::before {
            left: 110%;
        }

        .btn-submit.loading {
            pointer-events: none;
            opacity: 0.85;
        }

        .btn-submit.loading span {
            opacity: 0;
        }

        .btn-submit.loading::after {
            content: "";
            position: absolute;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.45);
            border-top-color: white;
            top: calc(50% - 12px);
            left: calc(50% - 12px);
            animation: spin 0.75s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .links {
            display: flex;
            justify-content: center;
            margin-top: 22px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }

        .links a {
            color: white;
            text-decoration: none;
            margin-left: 5px;
        }

        .links a:hover {
            color: var(--blue);
        }

        .otp-wrap {
            width: 100%;
            min-width: 0;
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .otp-box {
            width: 100%;
            min-width: 0;
            height: 56px;
            padding: 0;
            border-radius: 17px;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.09);
            color: white;
            font-size: 22px;
            font-weight: 900;
            text-align: center;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
        }

        .otp-box:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 4px rgba(0,210,255,0.12);
        }

        .hidden-otp {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            border: 0;
            opacity: 0;
            pointer-events: none;
        }

        .note {
            margin-bottom: 18px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,183,3,0.08);
            border: 1px solid rgba(255,183,3,0.18);
            color: rgba(255,255,255,0.72);
            font-size: 12px;
            line-height: 1.6;
            font-weight: 700;
        }

        @media (max-width: 1050px) {
            .page {
                grid-template-columns: 1fr;
            }

            .hero {
                min-height: 520px;
            }
        }

        @media (max-width: 620px) {
            .page {
                padding: 14px;
            }

            .hero {
                display: none;
            }

            .container-box {
                padding: 28px 22px;
                border-radius: 30px;
            }

            .captcha-row {
                grid-template-columns: 1fr;
            }

            .otp-wrap {
                gap: 7px;
            }

            .otp-box {
                height: 50px;
                border-radius: 14px;
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

<main class="page">

    <section class="hero">
        <div class="brand">
            <img src="Logo.png" alt="OLDORA">
            <span>OLDORA ACCESS</span>
        </div>

        <div class="hero-content">
            <div class="badge">
                <span class="pulse-dot"></span>
                Join the creator automation engine
            </div>

            <h1>
                Start building <br>
                <span>viral systems.</span>
            </h1>

            <p>
                Create your OLDORA account and unlock AI content generation,
                account warm-up, smart scheduling, referrals, and automated
                social growth workflows.
            </p>
        </div>

        <div class="benefits">
            <div class="benefit">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <strong>AI Content</strong>
                <span>Generate posts, captions and ideas faster.</span>
            </div>

            <div class="benefit">
                <i class="fa-solid fa-fire"></i>
                <strong>Warm-up</strong>
                <span>Prepare accounts before publishing.</span>
            </div>

            <div class="benefit">
                <i class="fa-solid fa-gift"></i>
                <strong>Referral</strong>
                <span>Invite users and earn credits.</span>
            </div>
        </div>
    </section>

    <section class="signup-zone">
        <div class="container-box" id="signupCard">

            <div class="top-actions">
                <div class="mini-brand">
                    <img src="Logo.png" alt="OLDORA Logo" class="logo-img">
                    <div class="brand-name">OLDORA</div>
                </div>

                <a href="index.php" class="home-link" title="Back home">
                    <i class="fa-solid fa-house"></i>
                </a>
            </div>

            <?php if(!$show_otp_form): ?>
                <h1 class="title">Create account</h1>
                <div class="subtitle">Join OLDORA and start your AI-powered growth workflow.</div>
            <?php else: ?>
                <h1 class="title">Verify email</h1>
                <div class="subtitle">Enter the 6-digit code sent to your email address.</div>
            <?php endif; ?>

            <?php if(count($errors) > 0): ?>
                <div class="alert-error">
                    <?php foreach($errors as $error){ echo "<div><i class='fa-solid fa-circle-exclamation'></i> ".htmlspecialchars($error, ENT_QUOTES, 'UTF-8')."</div>"; } ?>
                </div>
            <?php endif; ?>

            <?php if(!empty($info)): ?>
                <div class="alert-info">
                    <i class="fa-solid fa-paper-plane"></i>
                    <?php echo $info; ?>
                </div>
            <?php endif; ?>

            <?php if(!$show_otp_form): ?>

            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" autocomplete="off" id="signupForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                <div class="input-group">
                    <input class="input-field" type="text" name="name" id="name" placeholder="Full Name" required value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="floating-label" for="name">Full Name</label>
                    <i class="fa-solid fa-user input-icon"></i>
                </div>

                <div class="input-group">
                    <input class="input-field" type="email" name="email" id="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="floating-label" for="email">Email Address</label>
                    <i class="fa-solid fa-envelope input-icon"></i>
                </div>

                <div class="input-group">
                    <input class="input-field" type="password" name="password" id="password" placeholder="Password" required>
                    <label class="floating-label" for="password">Password</label>
                    <i class="fa-solid fa-lock input-icon"></i>
                    <i class="fa-solid fa-eye toggle-password" data-target="password"></i>
                </div>

                <div class="strength">
                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>
                </div>

                <div class="input-group">
                    <input class="input-field" type="password" name="cpassword" id="cpassword" placeholder="Confirm Password" required>
                    <label class="floating-label" for="cpassword">Confirm Password</label>
                    <i class="fa-solid fa-lock input-icon"></i>
                    <i class="fa-solid fa-eye toggle-password" data-target="cpassword"></i>
                </div>

                <div class="input-group">
                    <input class="input-field" type="text" name="referral_code" id="referral_code" placeholder="Referral Code" value="<?php echo $refValue; ?>">
                    <label class="floating-label" for="referral_code">Referral Code Optional</label>
                    <i class="fa-solid fa-gift input-icon"></i>
                </div>

                <div class="captcha-row">
                    <div class="captcha-display"><?php echo htmlspecialchars($captcha_code, ENT_QUOTES, 'UTF-8'); ?></div>

                    <div class="input-group" style="margin-bottom:0;">
                        <input class="input-field" style="padding-left:18px;text-align:center;" type="text" name="captcha_input" id="captcha_input" placeholder="Enter Code" required>
                        <label class="floating-label" style="left:18px;" for="captcha_input">Enter Code</label>
                    </div>
                </div>

                <button class="btn-submit" type="submit" name="signup">
                    <span>
                        Create account
                        <i class="fa-solid fa-arrow-right"></i>
                    </span>
                </button>

                <div class="links">
                    <span>Already have an account? <a href="login-user.php">Login</a></span>
                </div>
            </form>

            <?php else: ?>

            <div class="note">
                <i class="fa-solid fa-clock"></i>
                The code may take a few minutes to arrive. Check your spam folder too.
            </div>

            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" autocomplete="off" id="otpForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input class="hidden-otp" type="hidden" name="otp" id="otpCode">

                <div class="otp-wrap">
                    <input class="otp-box" name="otp_digit[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" required autofocus>
                    <input class="otp-box" name="otp_digit[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" required>
                    <input class="otp-box" name="otp_digit[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" required>
                    <input class="otp-box" name="otp_digit[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" required>
                    <input class="otp-box" name="otp_digit[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" required>
                    <input class="otp-box" name="otp_digit[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" required>
                </div>

                <button class="btn-submit" type="submit" name="verify_email">
                    <span>
                        Verify & login
                        <i class="fa-solid fa-shield-halved"></i>
                    </span>
                </button>

                <div class="links">
                    <a href="signup-user.php?cancel=1">Cancel & go back</a>
                </div>
            </form>

            <?php endif; ?>

        </div>
    </section>

</main>

<script>
    const cursorGlow = document.getElementById("cursorGlow");
    const signupCard = document.getElementById("signupCard");
    const signupForm = document.getElementById("signupForm");
    const otpForm = document.getElementById("otpForm");
    const password = document.getElementById("password");
    const strengthLines = document.querySelectorAll(".strength span");
    const toggleButtons = document.querySelectorAll(".toggle-password");
    const otpBoxes = document.querySelectorAll(".otp-box");
    const otpCode = document.getElementById("otpCode");
    const submitButtons = document.querySelectorAll(".btn-submit");

    document.addEventListener("mousemove", function(e) {
        cursorGlow.style.left = e.clientX + "px";
        cursorGlow.style.top = e.clientY + "px";
    });

    toggleButtons.forEach(function(button) {
        button.addEventListener("click", function() {
            const targetId = button.getAttribute("data-target");
            const input = document.getElementById(targetId);

            if (!input) return;

            if (input.type === "password") {
                input.type = "text";
                button.classList.remove("fa-eye");
                button.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                button.classList.remove("fa-eye-slash");
                button.classList.add("fa-eye");
            }
        });
    });

    if (password) {
        password.addEventListener("input", function() {
            const value = password.value;
            let strength = 0;

            if (value.length >= 8) strength++;
            if (/[A-Z]/.test(value)) strength++;
            if (/[0-9]/.test(value)) strength++;
            if (/[^A-Za-z0-9]/.test(value)) strength++;

            strengthLines.forEach(function(line, index) {
                if (index < strength) {
                    line.classList.add("active");
                } else {
                    line.classList.remove("active");
                }
            });
        });
    }

    function setLoading() {
        submitButtons.forEach(function(btn) {
            btn.classList.add("loading");
        });
    }

    if (signupForm) {
        signupForm.addEventListener("submit", setLoading);
    }

    if (otpForm) {
        otpForm.addEventListener("submit", function(event) {
            let code = "";

            otpBoxes.forEach(function(box) {
                code += box.value.replace(/\D/g, "").slice(0, 1);
            });

            if (code.length !== 6) {
                event.preventDefault();
                const emptyBox = Array.from(otpBoxes).find(function(box) {
                    return !/^[0-9]$/.test(box.value);
                });
                if (emptyBox) emptyBox.focus();
                return;
            }

            otpCode.value = code;
            setLoading();
        });
    }

    if (otpBoxes.length > 0) {
        otpBoxes.forEach(function(box, index) {
            box.addEventListener("input", function() {
                box.value = box.value.replace(/\D/g, "");

                if (box.value && index < otpBoxes.length - 1) {
                    otpBoxes[index + 1].focus();
                }

                let code = "";

                otpBoxes.forEach(function(item) {
                    code += item.value;
                });

                otpCode.value = code;
            });

            box.addEventListener("keydown", function(e) {
                if (e.key === "Backspace" && !box.value && index > 0) {
                    otpBoxes[index - 1].focus();
                }
            });

            box.addEventListener("paste", function(e) {
                e.preventDefault();

                const paste = (e.clipboardData || window.clipboardData)
                    .getData("text")
                    .replace(/\D/g, "")
                    .slice(0, 6);

                otpBoxes.forEach(function(item, i) {
                    item.value = paste[i] || "";
                });

                otpCode.value = paste;

                if (paste.length === 6) {
                    otpBoxes[5].focus();
                }
            });
        });
    }
</script>

</body>
</html>
