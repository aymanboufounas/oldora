<?php 
require_once "connection.php"; 
require_once "GoogleAuthenticator.php"; // Include the library
session_start();

// 1. Check Login
if(!isset($_SESSION['email'])){
    header('location: login-user.php');
    exit();
}

$email = $_SESSION['email'];
$msg = "";
$csrf_token = oldora_csrf_token();
$ga = new PHPGangsta_GoogleAuthenticator();

// Get User Info
$sql = "SELECT * FROM users WHERE email = '$email'";
$run_Sql = mysqli_query($con, $sql);
$fetch_info = mysqli_fetch_assoc($run_Sql);
$user_id = $fetch_info['id']; // Needed for sidebar links if specific logic uses it

// Generate Secret if not exists
$secret = $fetch_info['google_secret'];
if(empty($secret)) {
    $secret = $ga->createSecret();
    mysqli_query($con, "UPDATE users SET google_secret='$secret' WHERE email='$email'");
}

/* =========================================================
   ✅✅✅ ADDED: SMTP + PHPMailer + OLDORA Email Template
   ========================================================= */

// Load .env SMTP settings
$envPath = __DIR__ . '/../.env';
$env = [];
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
}

// PHPMailer includes
require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function envv($env, $key, $default = '') {
    if (!isset($env[$key])) return $default;
    $v = trim((string)$env[$key]);
    return trim($v, "\"'");
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

    if (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
        return false; // ما نطيحوش الصفحة إذا SMTP ناقص
    }

    if ($smtpEnc === '') {
        $smtpEnc = ($smtpPort === 465) ? 'ssl' : 'tls';
    }

    try {
        $mail = new PHPMailer(true);
        // $mail->SMTPDebug = 2; // للتجربة

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

/* =========================================================
   ✅✅✅ END ADDED
   ========================================================= */


// 2. Handle Profile Update
if(isset($_POST['update_profile']) && oldora_verify_csrf($_POST['csrf_token'] ?? '')){
    $new_name = mysqli_real_escape_string($con, $_POST['full_name']);
    $new_pass = mysqli_real_escape_string($con, $_POST['password']);
    
    if(!empty($new_pass) && strlen($new_pass) < 10){
        $msg = "Password must be at least 10 characters.";
    } elseif(!empty($new_pass)){
        $hashed_password = password_hash($new_pass, PASSWORD_BCRYPT); 
        $sql_update = "UPDATE users SET full_name='$new_name', password='$hashed_password' WHERE email='$email'";
    } else {
        $sql_update = "UPDATE users SET full_name='$new_name' WHERE email='$email'";
    }

    if($msg === "" && mysqli_query($con, $sql_update)){
        $msg = "Profile updated successfully!";
        $run_Sql = mysqli_query($con, $sql);
        $fetch_info = mysqli_fetch_assoc($run_Sql);

        // ✅✅✅ ADDED: Send email if password changed
        if(!empty($new_pass)){
            $subject = "Your OLDORA password was changed";
            $content = '
                <div style="font-size:15px;margin-bottom:10px;">
                    Hi <b>'.htmlspecialchars($fetch_info['full_name'], ENT_QUOTES, 'UTF-8').'</b>,
                </div>
                <div style="color:#e8eaed;line-height:1.8;">
                    This is a confirmation that your OLDORA account password was changed successfully.<br><br>
                    <b>Time:</b> '.date("Y-m-d H:i:s").'<br>
                    <b>Account:</b> '.htmlspecialchars($email, ENT_QUOTES, 'UTF-8').'<br><br>
                    If you did not make this change, please reset your password immediately and contact support.
                </div>
            ';
            sendOldoraMail($env, $email, $subject, $content);
            mysqli_query($con, "UPDATE users SET code = NULL WHERE email='$email'");
            unset($_SESSION['verified_reset'], $_SESSION['otp_generated_at']);
        }

    } elseif ($msg === "") {
        $msg = "Error updating profile.";
    }
}

// 3. Handle 2FA Toggle
if(isset($_POST['toggle_2fa']) && oldora_verify_csrf($_POST['csrf_token'] ?? '')){
    $entered_code = $_POST['otp_code'];
    $checkResult = $ga->verifyCode($secret, $entered_code, 2); 

    if($checkResult){
        if($fetch_info['is_2fa_enabled'] == 0){
            mysqli_query($con, "UPDATE users SET is_2fa_enabled = 1 WHERE email='$email'");
            $msg = "Two-Factor Authentication ENABLED!";

            // ✅✅✅ ADDED: Email 2FA enabled
            $subject = "Two-Factor Authentication enabled on your OLDORA account";
            $content = '
                <div style="font-size:15px;margin-bottom:10px;">
                    Hi <b>'.htmlspecialchars($fetch_info['full_name'], ENT_QUOTES, 'UTF-8').'</b>,
                </div>
                <div style="color:#e8eaed;line-height:1.8;">
                    Two-Factor Authentication (2FA) has been <b>ENABLED</b> for your OLDORA account.<br><br>
                    <b>Time:</b> '.date("Y-m-d H:i:s").'<br>
                    <b>Account:</b> '.htmlspecialchars($email, ENT_QUOTES, 'UTF-8').'<br><br>
                    If you did not enable 2FA, please contact support immediately.
                </div>
            ';
            sendOldoraMail($env, $email, $subject, $content);

        } else {
             mysqli_query($con, "UPDATE users SET is_2fa_enabled = 0, google_secret=NULL WHERE email='$email'");
             $msg = "Two-Factor Authentication DISABLED!";
             $secret = $ga->createSecret(); 

             // ✅✅✅ ADDED: Email 2FA disabled
             $subject = "Two-Factor Authentication disabled on your OLDORA account";
             $content = '
                <div style="font-size:15px;margin-bottom:10px;">
                    Hi <b>'.htmlspecialchars($fetch_info['full_name'], ENT_QUOTES, 'UTF-8').'</b>,
                </div>
                <div style="color:#e8eaed;line-height:1.8;">
                    Two-Factor Authentication (2FA) has been <b>DISABLED</b> for your OLDORA account.<br><br>
                    <b>Time:</b> '.date("Y-m-d H:i:s").'<br>
                    <b>Account:</b> '.htmlspecialchars($email, ENT_QUOTES, 'UTF-8').'<br><br>
                    If you did not disable 2FA, please enable it again and contact support.
                </div>
             ';
             sendOldoraMail($env, $email, $subject, $content);
        }

        $run_Sql = mysqli_query($con, "SELECT * FROM users WHERE email = '$email'");
        $fetch_info = mysqli_fetch_assoc($run_Sql);
    } else {
        $msg = "Invalid Code. Please scan the QR code and try again.";
    }
}

// Prepare View Data
$name = $fetch_info['full_name'];
$is_2fa = $fetch_info['is_2fa_enabled'];
$qrCodeUrl = $ga->getQRCodeGoogleUrl('OLDORA-App', $secret);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | OLDORA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
      <link rel="icon" type="image/png" href="https://i.postimg.cc/bv1QQwBc/1768055586557.png">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* التنسيقات العامة */
        body { min-height: 100vh; background: radial-gradient(circle at top, #0f2027, #000); font-family: 'Cairo', sans-serif; color: #fff; overflow-x: hidden; }
        
        .glass-panel { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(18px); border-radius: 20px; padding: 30px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 0 30px rgba(0, 0, 0, 0.5); }
        
        h2, h3, h4, h5 { font-weight: 700; color: #00fff0; text-shadow: 0 0 10px rgba(0,255,240,0.4); }
        .text-neon { color: #00fff0; }

        /* Sidebar Styling (نفس الستايل السابق) */
        .sidebar-container { min-height: 100vh; border-right: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); transition: all 0.3s ease-in-out; }
        .nav-link { color: #ccc; font-size: 16px; padding: 15px 20px; border-radius: 12px; margin-bottom: 10px; transition: 0.3s; display: flex; align-items: center; }
        .nav-link i { margin-right: 15px; width: 25px; text-align: center; font-size: 1.2rem; }
        .nav-link:hover, .nav-link.active { background: rgba(0, 255, 240, 0.1); color: #00fff0; box-shadow: 0 0 15px rgba(0,255,240,0.2); text-decoration: none; }
        
        /* Mobile Sidebar */
        @media (max-width: 991px) {
            .sidebar-container {
                position: fixed; top: 0; left: -100%; width: 280px; height: 100%;
                background: #0f2027; z-index: 9999; overflow-y: auto;
                box-shadow: 10px 0 20px rgba(0,0,0,0.5); display: block !important;
            }
            .sidebar-container.active { left: 0; }
            .sidebar-overlay {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.7); z-index: 9998; display: none;
            }
        }

        /* Mobile Header */
        .mobile-nav { background: rgba(15, 32, 39, 0.95); padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); display: none; position: sticky; top: 0; z-index: 100; justify-content: space-between; align-items: center; }
        @media (max-width: 991px) { .mobile-nav { display: flex; } }

        /* Profile Specific Styles */
        .avatar-circle { width: 100px; height: 100px; background: linear-gradient(45deg, #00fff0, #0072ff); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; color: #fff; font-weight: bold; margin: 0 auto 20px; box-shadow: 0 0 20px rgba(0, 255, 240, 0.5); }
        .form-control { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; height: 50px; color: #fff; }
        .form-control:focus { background: rgba(255,255,255,0.12); box-shadow: 0 0 15px rgba(0,255,240,0.3); border-color: #00fff0; color: #fff; }
        .btn-save { background: linear-gradient(135deg, #00fff0, #0066ff); color: #000; font-weight: 700; border-radius: 14px; border: none; padding: 12px 30px; width: 100%; transition: 0.4s; }
        .btn-save:hover { transform: scale(1.02); box-shadow: 0 0 25px rgba(0,255,240,0.6); color: #000; }
        .otp-section { background: rgba(0,255,240,0.03); padding: 25px; border-radius: 15px; border: 1px dashed rgba(0, 255, 240, 0.3); margin-top: 30px; }

    </style>
</head>
<body>

<div class="sidebar-overlay"></div>

<div class="mobile-nav">
    <button class="btn text-white p-0" id="openSidebarBtn" style="font-size: 1.5rem;">
        <i class="fa-solid fa-bars"></i>
    </button>
    <h4 class="m-0"><i class="fa-solid fa-robot"></i> OLDORA</h4>
    <a href="logout-user.php" class="btn btn-sm btn-outline-danger">Logout</a>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-2 sidebar-container pt-4" id="mainSidebar">
             <div class="d-flex justify-content-between align-items-center d-lg-none mb-4 px-2">
                 <h3 class="m-0">OLDORA</h3>
                 <button class="btn text-white" id="closeSidebarBtn">
                    <i class="fa-solid fa-times fa-2x"></i>
                 </button>
            </div>

            <div class="text-center mb-5 d-none d-lg-block"><h3><i class="fa-solid fa-robot"></i> OLDORA</h3></div>
            <nav class="nav flex-column">
                <a class="nav-link active" href="profile.php"><i class="fa-solid fa-user-gear"></i> Settings</a>
                <a class="nav-link" href="home.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Create Video</a>
                <a class="nav-link" href="create-insta.php"><i class="fa-brands fa-instagram"></i> Create Insta Post</a>
                <a class="nav-link" href="automation.php"><i class="fa-solid fa-gears"></i> Automation</a>
                <a class="nav-link" href="analytics.php"><i class="fa-solid fa-gears"></i> Analytics</a>
                <a href="connect-platforms.php" class="nav-link"><i class="fa-solid fa-link"></i> Connected Accounts</a>
                <a class="nav-link" href="planing.php"><i class="fa-solid fa-crown"></i> Your Plan</a>
                <a class="nav-link" href="referral.php"><i class="fa-solid fa-bullhorn"></i> Referral Program</a>
                <a class="nav-link" href="support.php"><i class="fa-solid fa-headset"></i> Contact Support</a>
                <a class="nav-link mt-5" href="logout-user.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </nav>
        </div>

        <div class="col-lg-10 col-12 py-5 px-3 px-lg-5">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><i class="fa-solid fa-id-card"></i> User Profile</h4>
            </div>

            <?php if($msg != ""): ?>
                <div class="alert alert-info text-center border-0" style="background: rgba(0,255,240,0.1); color: #00fff0;"><?php echo $msg; ?></div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="glass-panel">
                        <div class="text-center">
                            <div class="avatar-circle"><?php echo strtoupper(substr($name, 0, 1)); ?></div>
                            <h3 class="text-white"><?php echo $name; ?></h3>
                            <p class="text-white-50"><?php echo $email; ?></p>
                        </div>

                        <form method="POST" action="" class="mt-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="form-group">
                                <label class="text-neon">Full Name</label>
                                <input type="text" name="full_name" class="form-control" value="<?php echo $name; ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="text-neon">Change Password <small class="text-muted">(Leave empty to keep current)</small></label>
                                <input type="password" name="password" class="form-control" placeholder="New Password">
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-save mt-3"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                        </form>

                        <div class="otp-section">
                            <h4 class="mb-3"><i class="fa-solid fa-shield-halved"></i> Two-Factor Authentication (2FA)</h4>
                            
                            <?php if($is_2fa): ?>
                                <div class="alert alert-success border-0" style="background: rgba(40, 167, 69, 0.2); color: #28a745;">
                                    Status: <strong>ENABLED</strong> <i class="fa-solid fa-check-circle ml-2"></i>
                                </div>
                                <p class="text-white-50">To disable 2FA, enter the code from your Authenticator app below:</p>
                            <?php else: ?>
                                <div class="alert alert-warning border-0" style="background: rgba(255, 193, 7, 0.2); color: #ffc107;">
                                    Status: <strong>DISABLED</strong>
                                </div>
                                <div class="row align-items-center mt-4">
                                    <div class="col-md-5 text-center mb-3 mb-md-0">
                                        <div class="bg-white p-2 d-inline-block rounded">
                                            <img src="<?php echo $qrCodeUrl; ?>" class="img-fluid" style="max-width: 150px;">
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <ol class="pl-3 text-white-50 small">
                                            <li class="mb-2">Install <strong>Google Authenticator</strong> on your phone.</li>
                                            <li class="mb-2">Scan the QR code shown here.</li>
                                            <li>Enter the generated 6-digit code below to enable protection.</li>
                                        </ol>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <div class="input-group">
                                    <input type="text" name="otp_code" class="form-control" placeholder="Enter 6-digit Code" required autocomplete="off" style="text-align: center; font-size: 1.2rem; letter-spacing: 5px;">
                                    <div class="input-group-append">
                                        <button type="submit" name="toggle_2fa" class="btn <?php echo $is_2fa ? 'btn-danger' : 'btn-success'; ?>" style="border-radius: 0 12px 12px 0;">
                                            <?php echo $is_2fa ? 'Disable' : 'Enable'; ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function(){
    // كود القائمة الجانبية (Sidebar Toggle)
    $("#openSidebarBtn").click(function(){
        $("#mainSidebar").addClass("active");
        $(".sidebar-overlay").fadeIn();
    });

    $("#closeSidebarBtn, .sidebar-overlay").click(function(){
        $("#mainSidebar").removeClass("active");
        $(".sidebar-overlay").fadeOut();
    });
});
</script>

</body>
</html>
