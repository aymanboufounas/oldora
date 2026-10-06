<?php
require_once "connection.php";
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// -----------------------------------------------------
// تحميل إعدادات SMTP من .env
// -----------------------------------------------------
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) { die('ENV file not found.'); }
$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);

function envv($env, $key, $default = '') {
    if (!isset($env[$key])) return $default;
    $v = trim((string)$env[$key]);
    $v = trim($v, "\"'");
    return $v;
}

// PHPMailer includes
require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_POST['check-email'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die("Invalid CSRF");

    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Invalid email format!";
    } else {

        // ✅ تحقق من وجود الإيميل
        $email_esc = mysqli_real_escape_string($con, $email);
        $check_email = "SELECT id,email FROM users WHERE email='$email_esc' LIMIT 1";
        $run_sql = mysqli_query($con, $check_email);

        if (mysqli_num_rows($run_sql) > 0) {

            $code = random_int(100000, 999999);

            // ✅ حفظ الكود فالداتابيز
            $insert_code = "UPDATE users SET code=$code WHERE email='$email_esc'";
            $run_query = mysqli_query($con, $insert_code);

            if ($run_query) {
                // ✅ إرسال الإيميل عبر SMTP Hostinger
                $smtpHost = envv($env, 'SMTP_HOST');
                $smtpUser = envv($env, 'SMTP_USER');
                $smtpPass = envv($env, 'SMTP_PASS');
                $smtpPort = (int) envv($env, 'SMTP_PORT', '587');
                $smtpEnc  = strtolower(envv($env, 'SMTP_ENCRYPTION', ''));

                if ($smtpEnc === '') {
                    $smtpEnc = ($smtpPort === 465) ? 'ssl' : 'tls';
                }

                try {
                    $mail = new PHPMailer(true);
                    // $mail->SMTPDebug = 2; // للتجربة فقط

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
                    $mail->addAddress($email);

                    // =========================================================
                    // ✅✅✅ EMAIL DESIGN (ONLY THIS PART ADDED) ✅✅✅
                    // =========================================================
                    $logoUrl = "https://i.postimg.cc/bv1QQwBc/1768055586557.png";
                    $siteName = "OLDORA";
                    $year = date("Y");

                    // بدّل الدومين ديالك (اختياري غير زر)
                    $verifyUrl = "https://oldora.vip/reset-code.php";

                    $mail->isHTML(true);
                    $mail->Subject = "Password Reset Code | OLDORA";
                    $mail->Body = '
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#09090b;font-family:Arial,sans-serif;color:#ffffff;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    '.$siteName.' reset code: '.$code.' (valid 10 minutes)
  </div>

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
              <div style="font-size:13px;color:#9aa0a6;margin-top:8px;">
                Password Reset Code
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:16px 18px 28px 18px;">
              <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
                     style="border-radius:20px;background:rgba(255,255,255,0.06);
                            border:1px solid rgba(255,255,255,0.10);">
                <tr>
                  <td style="padding:22px 18px;color:#e8eaed;line-height:1.7;font-size:14px;">

                    <div style="font-size:15px;margin-bottom:12px;">
                      We received a request to reset your password.
                      Use the code below to continue:
                    </div>

                    <div style="text-align:center;margin:18px 0;">
                      <div style="display:inline-block;padding:14px 18px;border-radius:14px;
                                  background:rgba(0,0,0,0.35);
                                  border:1px solid rgba(255,255,255,0.10);">
                        <span style="font-size:34px;font-weight:800;letter-spacing:8px;color:#ffffff;">
                          '.$code.'
                        </span>
                      </div>
                    </div>

                    <div style="font-size:13px;color:#9aa0a6;">
                      This code is valid for <b style="color:#ffffff;">10 minutes</b>.
                      If you didn’t request this, ignore this email.
                    </div>

                    <div style="text-align:center;margin:18px 0 8px 0;">
                      <a href="'.$verifyUrl.'"
                         style="display:inline-block;text-decoration:none;
                                background: linear-gradient(135deg, #00C6FF 0%, #0072FF 100%);
                                color:#ffffff;padding:12px 18px;border-radius:12px;font-weight:700;">
                        Verify Code
                      </a>
                    </div>

                    <div style="font-size:12px;color:#6b7280;margin-top:10px;text-align:center;">
                      If the button doesn’t work, open this link:<br>
                      <span style="color:#9aa0a6;">'.$verifyUrl.'</span>
                    </div>

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
</html>
';

                    $mail->AltBody = "OLDORA reset code: $code (valid 10 minutes). If link doesn't work: $verifyUrl";
                    // =========================================================

                    $mail->send();

                    // ✅ جلسة ضرورية للخطوات اللي جاية
                    $_SESSION['email'] = $email;
                    $_SESSION['info'] = "We've sent a password reset code to your email.";
                    $_SESSION['otp_generated_at'] = time(); // Expiry (10 minutes)

                    header('location: reset-code.php');
                    exit();

                } catch (Exception $e) {
                    $errors['email-send'] = "Email not sent: " . ($mail->ErrorInfo ?? $e->getMessage());
                }

            } else {
                $errors['db-error'] = "Something went wrong!";
            }

        } else {
            // تقدر تخليها رسالة عامة باش ما يكونش email enumeration
            $errors['email'] = "This email address does not exist!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | OLDORA</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --primary-gradient: linear-gradient(135deg, #00C6FF 0%, #0072FF 100%); --glass-bg: rgba(255, 255, 255, 0.05); --input-bg: rgba(0, 0, 0, 0.3); }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at 50% 0%, #1a1a2e 0%, #000000 100%); font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; color: #fff; padding: 20px; }
        .container-box { background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 40px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.5); position: relative; }
        .logo-img { width: 120px; margin-bottom: 15px; }
        .brand-name { font-size: 1.5rem; font-weight: 700; margin-bottom: 5px; background: var(--primary-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .subtitle { font-size: 0.9rem; color: #888; margin-bottom: 30px; }
        .input-group { position: relative; margin-bottom: 20px; text-align: left; }
        .input-field { width: 100%; padding: 15px 15px 15px 45px; background: var(--input-bg); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #fff; outline: none; transition: 0.3s; box-sizing: border-box; }
        .input-field:focus { border-color: #00C6FF; background: rgba(0, 0, 0, 0.5); }
        .input-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #666; transition: 0.3s; }
        .btn-submit { width: 100%; padding: 15px; background: var(--primary-gradient); border: none; border-radius: 12px; color: #fff; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0, 198, 255, 0.4); }
        .links { margin-top: 25px; font-size: 0.85rem; }
        .links a { color: #fff; text-decoration: none; }
        .alert-error { background: rgba(255, 71, 87, 0.1); border: 1px solid rgba(255, 71, 87, 0.3); color: #ff4757; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: rgba(0, 200, 81, 0.1); border: 1px solid rgba(0, 200, 81, 0.3); color: #00c851; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="container-box">
    <img src="Logo.png" alt="OLDORA Logo" class="logo-img">
    <div class="brand-name">OLDORA</div>
    <div class="subtitle">Enter your email to reset your password.</div>

    <?php if(isset($_SESSION['info']) && $_SESSION['info'] != ""): ?>
        <div class="alert-success"><?php echo $_SESSION['info']; ?></div>
    <?php endif; ?>

    <?php if(count($errors) > 0): ?>
        <div class="alert-error">
            <?php foreach($errors as $error){ echo $error . "<br>"; } ?>
        </div>
    <?php endif; ?>

    <form action="forgot-password.php" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="input-group">
            <input class="input-field" type="email" name="email" placeholder="Enter your email" required value="<?php echo htmlspecialchars($email ?? ''); ?>">
            <i class="fa-solid fa-envelope input-icon"></i>
        </div>

        <button class="btn-submit" type="submit" name="check-email">Send Reset Code</button>

        <div class="links">
            <a href="login-user.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
        </div>
    </form>
</div>

</body>
</html>
