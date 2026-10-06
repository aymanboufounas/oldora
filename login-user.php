<?php 
require_once "connection.php"; 
require_once "GoogleAuthenticator.php"; 

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.cookie_secure', $isHttps ? 1 : 0);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

$errors = array();
$show_otp_form = false; 

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
    $v = trim((string)$env[$key]);
    return trim($v, "\"'");
}

require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function buildOldoraEmailTemplate($subject, $htmlContent) {
    $logoUrl = "https://i.postimg.cc/bv1QQwBc/1768055586557.png";
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
               style="max-width:560px;width:100%;border-radius:24px;overflow:hidden;
                      background: radial-gradient(circle at 50% 0%, #1a1a2e 0%, #000000 100%);
                      border:1px solid rgba(255,255,255,0.10);
                      box-shadow:0 20px 50px rgba(0,0,0,0.55);">

          <tr>
            <td style="padding:30px 24px 10px 24px;text-align:center;">
              <img src="'.$logoUrl.'" width="120" alt="'.$siteName.' Logo" style="display:block;margin:0 auto 12px auto;">
              <div style="font-size:22px;font-weight:800;letter-spacing:2px;
                          background: linear-gradient(135deg, #00C6FF 0%, #0072FF 100%);
                          -webkit-background-clip:text;background-clip:text;color:transparent;">
                '.$siteName.'
              </div>
              <div style="font-size:13px;color:#9aa0a6;margin-top:8px;">'.$subjectSafe.'</div>
            </td>
          </tr>

          <tr>
            <td style="padding:16px 18px 28px 18px;">
              <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
                     style="border-radius:20px;background:rgba(255,255,255,0.06);
                            border:1px solid rgba(255,255,255,0.10);">
                <tr>
                  <td style="padding:22px 18px;color:#e8eaed;line-height:1.7;font-size:14px;">
                    '.$htmlContent.'
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:0 24px 22px 24px;text-align:center;color:#6b7280;font-size:12px;line-height:1.6;">
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

    if (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) return false;

    if ($smtpEnc === '') {
        $smtpEnc = ($smtpPort === 465) ? 'ssl' : 'tls';
    }

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;

        if ($smtpEnc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtpUser, 'OLDORA');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = buildOldoraEmailTemplate($subject, $htmlContent);
        $mail->AltBody = strip_tags($htmlContent);

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if(isset($_POST['login'])){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF");
    }

    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    $stmt = $con->prepare("SELECT id, full_name, email, password, role, status, failed_login_attempts, locked_until, google_secret, is_2fa_enabled FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0){
        $fetch = $result->fetch_assoc();

        if ($fetch['locked_until'] && strtotime($fetch['locked_until']) > time()) {
            $remaining = ceil((strtotime($fetch['locked_until']) - time()) / 60);
            $errors['login'] = "Account locked. Try again in $remaining min.";
        } else {
            if(password_verify($password, $fetch['password'])){
                if($fetch['status'] == 'verified' || $fetch['status'] == 'active'){
                    if($fetch['is_2fa_enabled'] == 1){
                        $_SESSION['temp_2fa_user_id'] = $fetch['id'];
                        $_SESSION['temp_2fa_email'] = $email;
                        $show_otp_form = true; 
                    } else {
                        doLogin($fetch);
                    }
                } else {
                    $errors['otp-error'] = "Account not verified yet.";
                }
            } else {
                handleLoginFail($fetch['id'], $fetch['failed_login_attempts'], $con);
                $errors['login'] = "Incorrect Email or Password.";
            }
        }
    } else {
        $errors['login'] = "Incorrect Email or Password.";
    }
}

if(isset($_POST['verify_otp'])){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF");
    }

    $otp_code = preg_replace('/\D/', '', $_POST['otp_code']);

    if(isset($_SESSION['temp_2fa_user_id'])){
        $user_id = $_SESSION['temp_2fa_user_id'];

        $stmt = $con->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $fetch = $result->fetch_assoc();

        $ga = new PHPGangsta_GoogleAuthenticator();
        $checkResult = $ga->verifyCode($fetch['google_secret'], $otp_code, 1);

        if($checkResult){
            doLogin($fetch);
        } else {
            $errors['otp'] = "Invalid Code.";
            $show_otp_form = true; 
        }
    } else {
        header("location: login-user.php");
        exit();
    }
}

function doLogin($fetch){
    global $con, $env;

    $ip = getClientIP();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $time = date("Y-m-d H:i:s");

    $subject = "New login to your OLDORA account";

    $content = '
        <div style="font-size:15px;margin-bottom:10px;">
            Hi <b>'.htmlspecialchars($fetch['full_name'], ENT_QUOTES, "UTF-8").'</b>,
        </div>
        <div style="color:#e8eaed;line-height:1.8;">
            We noticed a new login to your OLDORA account.<br><br>
            <b>Time:</b> '.$time.'<br>
            <b>Account:</b> '.htmlspecialchars($fetch['email'], ENT_QUOTES, "UTF-8").'<br>
            <b>IP Address:</b> '.htmlspecialchars($ip, ENT_QUOTES, "UTF-8").'<br>
            <b>Device:</b> '.htmlspecialchars($ua, ENT_QUOTES, "UTF-8").'<br><br>
            If this was you, you can ignore this email.<br>
            If you did not login, please reset your password immediately and enable 2FA.
        </div>
    ';

    @sendOldoraMail($env, $fetch['email'], $subject, $content);

    session_regenerate_id(true);

    $_SESSION['email'] = $fetch['email'];
    $_SESSION['name'] = $fetch['full_name'];
    $_SESSION['role'] = $fetch['role'];

    unset($_SESSION['temp_2fa_user_id']);
    unset($_SESSION['temp_2fa_email']);

    $user_id = (int)$fetch['id'];
    $con->query("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = $user_id");

    header('location: home.php');
    exit();
}

function handleLoginFail($uid, $attempts, $con){
    $uid = (int)$uid;
    $new = (int)$attempts + 1;

    $sql = "UPDATE users SET failed_login_attempts = $new";

    if ($new >= 5) {
        $lock = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $lockSafe = $con->real_escape_string($lock);
        $sql .= ", locked_until = '$lockSafe'";
    }

    $sql .= " WHERE id = $uid";
    $con->query($sql);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | OLDORA</title>

    <link rel="icon" href="https://i.postimg.cc/bv1QQwBc/1768055586557.png" type="image/png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --bg: #030712;
            --card: rgba(255, 255, 255, 0.08);
            --card-border: rgba(255, 255, 255, 0.16);
            --text: #ffffff;
            --muted: rgba(255, 255, 255, 0.62);
            --input: rgba(255, 255, 255, 0.09);
            --blue: #00d2ff;
            --deep-blue: #0072ff;
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
            overflow: hidden;
        }

        .world {
            position: fixed;
            inset: 0;
            z-index: -20;
            background:
                radial-gradient(circle at 15% 15%, rgba(0, 210, 255, 0.24), transparent 32%),
                radial-gradient(circle at 85% 20%, rgba(255, 0, 122, 0.18), transparent 30%),
                radial-gradient(circle at 50% 95%, rgba(138, 43, 226, 0.24), transparent 38%),
                linear-gradient(135deg, #02040c, #07111f 55%, #080015);
        }

        .aurora {
            position: fixed;
            inset: -45%;
            z-index: -19;
            background: conic-gradient(
                from 0deg,
                transparent,
                rgba(0, 210, 255, 0.20),
                transparent,
                rgba(255, 0, 122, 0.16),
                transparent,
                rgba(138, 43, 226, 0.24),
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
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }

            50% {
                transform: translate(30px, -45px) scale(1.08);
            }
        }

        .cursor-glow {
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            pointer-events: none;
            background: radial-gradient(circle, rgba(0, 210, 255, 0.15), transparent 68%);
            transform: translate(-50%, -50%);
            z-index: 2;
        }

        .scanline {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 50;
            opacity: 0.16;
            background: linear-gradient(
                to bottom,
                transparent,
                transparent 48%,
                rgba(0,210,255,0.16) 50%,
                transparent 52%,
                transparent
            );
            background-size: 100% 10px;
        }

        .page {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            padding: 28px;
            gap: 28px;
        }

        .hero {
            position: relative;
            padding: 42px;
            border-radius: 40px;
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
            letter-spacing: -0.7px;
        }

        .brand img {
            width: 58px;
            height: 58px;
            border-radius: 20px;
            object-fit: cover;
            box-shadow: 0 0 40px rgba(0, 210, 255, 0.4);
        }

        .brand span {
            background: linear-gradient(135deg, #fff, var(--blue));
            -webkit-background-clip: text;
            color: transparent;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 720px;
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
            font-size: clamp(48px, 7vw, 88px);
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

        .security-row {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            max-width: 620px;
        }

        .security-card {
            padding: 18px;
            border-radius: 24px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            backdrop-filter: blur(20px);
        }

        .security-card i {
            color: var(--blue);
            font-size: 22px;
            margin-bottom: 12px;
        }

        .security-card strong {
            display: block;
            font-size: 15px;
            margin-bottom: 5px;
        }

        .security-card span {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .holo-card {
            position: absolute;
            right: -70px;
            bottom: -55px;
            width: 430px;
            padding: 24px;
            border-radius: 34px;
            background: rgba(3, 7, 18, 0.62);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(28px);
            transform: rotate(-7deg);
            box-shadow: 0 35px 100px rgba(0,0,0,0.55);
        }

        .holo-top {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
        }

        .holo-top span {
            width: 11px;
            height: 11px;
            border-radius: 50%;
        }

        .holo-top span:nth-child(1) {
            background: var(--red);
        }

        .holo-top span:nth-child(2) {
            background: var(--orange);
        }

        .holo-top span:nth-child(3) {
            background: var(--green);
        }

        .holo-line {
            height: 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.11);
            margin-bottom: 13px;
            overflow: hidden;
        }

        .holo-line::after {
            content: "";
            display: block;
            height: 100%;
            width: 70%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--blue), var(--purple));
            animation: lineFlow 2.4s ease-in-out infinite;
        }

        .holo-line:nth-child(3)::after {
            width: 48%;
            animation-delay: 0.4s;
        }

        .holo-line:nth-child(4)::after {
            width: 85%;
            animation-delay: 0.8s;
        }

        @keyframes lineFlow {
            50% {
                transform: translateX(24px);
                opacity: 0.65;
            }
        }

        .login-zone {
            display: flex;
            align-items: center;
            justify-content: center;
            perspective: 1200px;
        }

        .container-box {
            position: relative;
            width: 100%;
            max-width: 470px;
            padding: 36px;
            border-radius: 38px;
            background: var(--card);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(34px);
            box-shadow:
                0 40px 110px rgba(0,0,0,0.48),
                inset 0 1px 0 rgba(255,255,255,0.22);
            overflow: hidden;
            transform-style: preserve-3d;
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
            margin-bottom: 26px;
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
            box-shadow: 0 0 35px rgba(0, 210, 255, 0.35);
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
            font-size: 36px;
            font-weight: 900;
            letter-spacing: -1.6px;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 24px;
        }

        .session-pill {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
            border-radius: 18px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            margin-bottom: 22px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .session-pill span:last-child {
            color: var(--blue);
        }

        .alert-error {
            position: relative;
            text-align: left;
            background: rgba(255, 71, 126, 0.12);
            border: 1px solid rgba(255, 71, 126, 0.32);
            color: #ff87a9;
            padding: 14px 15px;
            border-radius: 18px;
            font-size: 13px;
            line-height: 1.65;
            margin-bottom: 22px;
            font-weight: 700;
        }

        .alert-error i {
            margin-right: 8px;
            color: var(--red);
        }

        .input-group {
            position: relative;
            margin-bottom: 18px;
            text-align: left;
        }

        .input-field {
            width: 100%;
            height: 62px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.15);
            background: var(--input);
            color: white;
            outline: none;
            font-size: 15px;
            padding: 20px 54px 8px 48px;
            transition: 0.25s ease;
        }

        .input-field::placeholder {
            color: transparent;
        }

        .floating-label {
            position: absolute;
            left: 48px;
            top: 20px;
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
            top: 8px;
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
            transition: 0.25s ease;
        }

        .input-field:focus ~ .input-icon {
            color: var(--blue);
        }

        .toggle-password {
            position: absolute;
            right: 17px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            cursor: pointer;
            font-size: 17px;
            transition: 0.25s ease;
        }

        .toggle-password:hover {
            color: var(--blue);
        }

        .links {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 8px 0 22px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }

        .links.center {
            justify-content: center;
            margin-top: 22px;
            margin-bottom: 0;
        }

        .links a {
            color: white;
            text-decoration: none;
            transition: 0.25s ease;
        }

        .links a:hover {
            color: var(--blue);
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 9px;
            cursor: pointer;
        }

        .remember input {
            display: none;
        }

        .switch {
            width: 42px;
            height: 24px;
            border-radius: 999px;
            background: rgba(255,255,255,0.15);
            position: relative;
            transition: 0.25s ease;
        }

        .switch::after {
            content: "";
            position: absolute;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: white;
            top: 3px;
            left: 3px;
            transition: 0.25s ease;
        }

        .remember input:checked + .switch {
            background: linear-gradient(135deg, var(--blue), var(--purple));
        }

        .remember input:checked + .switch::after {
            left: 21px;
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
            background: linear-gradient(135deg, var(--deep-blue), var(--blue), var(--purple));
            box-shadow: 0 23px 50px rgba(0, 210, 255, 0.28);
            overflow: hidden;
            transition: 0.25s ease;
        }

        .btn-submit:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 70px rgba(0, 210, 255, 0.4);
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

        .otp-wrap {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .otp-box {
            height: 56px;
            border-radius: 17px;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.09);
            color: white;
            font-size: 22px;
            font-weight: 900;
            text-align: center;
            outline: none;
            transition: 0.25s ease;
        }

        .otp-box:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 4px rgba(0,210,255,0.12);
        }

        .hidden-otp {
            display: none;
        }

        .secure-note {
            margin-top: 18px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(53,255,182,0.08);
            border: 1px solid rgba(53,255,182,0.16);
            color: rgba(255,255,255,0.72);
            font-size: 12px;
            line-height: 1.6;
            font-weight: 700;
        }

        .secure-note i {
            color: var(--green);
            margin-right: 6px;
        }

        @media (max-width: 1050px) {
            body {
                overflow: auto;
            }

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

            .title {
                font-size: 31px;
            }

            .links {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            .links.center {
                align-items: center;
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
<div class="scanline"></div>

<main class="page">

    <section class="hero">
        <div class="brand">
            <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" alt="OLDORA">
            <span>OLDORA SECURITY</span>
        </div>

        <div class="hero-content">
            <div class="badge">
                <span class="pulse-dot"></span>
                Protected creator dashboard access
            </div>

            <h1>
                Secure login for <br>
                <span>AI growth.</span>
            </h1>

            <p>
                Access your OLDORA workspace with encrypted sessions, CSRF protection,
                login attempt control, two-factor authentication, and instant login alerts.
            </p>
        </div>

        <div class="security-row">
            <div class="security-card">
                <i class="fa-solid fa-shield-halved"></i>
                <strong>2FA Ready</strong>
                <span>Google Authenticator protection for sensitive accounts.</span>
            </div>

            <div class="security-card">
                <i class="fa-solid fa-lock"></i>
                <strong>Safe Session</strong>
                <span>Secure cookies, regenerated sessions, and CSRF token.</span>
            </div>

            <div class="security-card">
                <i class="fa-solid fa-envelope-circle-check"></i>
                <strong>Login Alerts</strong>
                <span>Email notifications for new account access.</span>
            </div>
        </div>

        <div class="holo-card">
            <div class="holo-top">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <div class="holo-line"></div>
            <div class="holo-line"></div>
            <div class="holo-line"></div>
            <div class="holo-line"></div>
        </div>
    </section>

    <section class="login-zone">
        <div class="container-box" id="loginCard">

            <div class="top-actions">
                <div class="mini-brand">
                    <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" alt="OLDORA Logo" class="logo-img">
                    <div>
                        <div class="brand-name">OLDORA</div>
                    </div>
                </div>

                <a href="index.php" class="home-link" title="Back home">
                    <i class="fa-solid fa-house"></i>
                </a>
            </div>

            <?php if(!$show_otp_form): ?>
                <h1 class="title">Welcome back</h1>
                <div class="subtitle">Login to continue to your creator automation dashboard.</div>
            <?php else: ?>
                <h1 class="title">Verify access</h1>
                <div class="subtitle">Enter the 6-digit code from your authenticator app.</div>
            <?php endif; ?>

            <div class="session-pill">
                <span><i class="fa-solid fa-circle-nodes"></i> Secure session</span>
                <span id="clock">00:00:00</span>
            </div>

            <?php if(count($errors) > 0): ?>
                <div class="alert-error">
                    <?php foreach($errors as $error){ echo "<div><i class='fa-solid fa-circle-exclamation'></i> ".htmlspecialchars($error)."</div>"; } ?>
                </div>
            <?php endif; ?>

            <?php if(!$show_otp_form): ?>

            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" autocomplete="off" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="input-group">
                    <input 
                        class="input-field" 
                        type="email" 
                        name="email" 
                        id="email"
                        placeholder="Email Address" 
                        required 
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                    >
                    <label class="floating-label" for="email">Email Address</label>
                    <i class="fa-solid fa-envelope input-icon"></i>
                </div>

                <div class="input-group">
                    <input 
                        class="input-field" 
                        type="password" 
                        name="password" 
                        id="password"
                        placeholder="Password" 
                        required
                    >
                    <label class="floating-label" for="password">Password</label>
                    <i class="fa-solid fa-lock input-icon"></i>
                    <i class="fa-solid fa-eye toggle-password" id="togglePassword"></i>
                </div>

                <div class="links">
                    <label class="remember">
                        <input type="checkbox">
                        <span class="switch"></span>
                        Remember device
                    </label>

                    <a href="forgot-password.php">Forgot Password?</a>
                </div>

                <button class="btn-submit" type="submit" name="login" id="submitBtn">
                    <span>
                        Login now
                        <i class="fa-solid fa-arrow-right"></i>
                    </span>
                </button>

                <div class="links center">
                    <span>Don't have an account? <a href="signup-user.php">Create account</a></span>
                </div>
            </form>

            <?php else: ?>

            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" autocomplete="off" id="otpForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <input class="hidden-otp" type="tel" name="otp_code" id="otpCode" maxlength="6" required>

                <div class="otp-wrap">
                    <input class="otp-box" type="text" inputmode="numeric" maxlength="1" autofocus>
                    <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
                    <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
                    <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
                    <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
                    <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
                </div>

                <button class="btn-submit" type="submit" name="verify_otp" id="submitBtn">
                    <span>
                        Verify code
                        <i class="fa-solid fa-shield-halved"></i>
                    </span>
                </button>

                <div class="links center">
                    <a href="login-user.php">Back to Login</a>
                </div>
            </form>

            <?php endif; ?>

            <div class="secure-note">
                <i class="fa-solid fa-lock"></i>
                Your login is protected with secure session settings and CSRF validation.
            </div>

        </div>
    </section>

</main>

<script>
    const cursorGlow = document.getElementById("cursorGlow");
    const loginCard = document.getElementById("loginCard");
    const clock = document.getElementById("clock");
    const togglePassword = document.getElementById("togglePassword");
    const password = document.getElementById("password");
    const loginForm = document.getElementById("loginForm");
    const otpForm = document.getElementById("otpForm");
    const submitButtons = document.querySelectorAll(".btn-submit");
    const otpBoxes = document.querySelectorAll(".otp-box");
    const otpCode = document.getElementById("otpCode");

    document.addEventListener("mousemove", function(e) {
        cursorGlow.style.left = e.clientX + "px";
        cursorGlow.style.top = e.clientY + "px";

        const rect = loginCard.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        if (x > 0 && x < rect.width && y > 0 && y < rect.height) {
            const rotateY = ((x / rect.width) - 0.5) * 10;
            const rotateX = ((y / rect.height) - 0.5) * -10;
            loginCard.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
        }
    });

    loginCard.addEventListener("mouseleave", function() {
        loginCard.style.transform = "rotateX(0deg) rotateY(0deg)";
    });

    function updateClock() {
        const now = new Date();
        clock.textContent = now.toLocaleTimeString();
    }

    updateClock();
    setInterval(updateClock, 1000);

    if (togglePassword && password) {
        togglePassword.addEventListener("click", function() {
            if (password.type === "password") {
                password.type = "text";
                togglePassword.classList.remove("fa-eye");
                togglePassword.classList.add("fa-eye-slash");
            } else {
                password.type = "password";
                togglePassword.classList.remove("fa-eye-slash");
                togglePassword.classList.add("fa-eye");
            }
        });
    }

    function setLoading() {
        submitButtons.forEach(function(btn) {
            btn.classList.add("loading");
        });
    }

    if (loginForm) {
        loginForm.addEventListener("submit", function() {
            setLoading();
        });
    }

    if (otpForm) {
        otpForm.addEventListener("submit", function() {
            let code = "";

            otpBoxes.forEach(function(box) {
                code += box.value;
            });

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