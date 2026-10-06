<?php 
require_once "connection.php"; 
session_start();

// التحقق من صلاحيات الأدمن
if(!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin'){ 
    header('location: secpanel111.php'); 
    exit(); 
}

// -----------------------------------------------------
// 1️⃣ تحميل إعدادات الإيميل من .env
// -----------------------------------------------------
$envPath = __DIR__ . '/../.env'; 
if (!file_exists($envPath)) { die('ENV file not found.'); }
$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);

// استدعاء ملفات PHPMailer
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$msg = "";
$msg_type = "";

// ✅ قالب تصميم الإيميل (OLDORA STYLE) — جديد فقط
function buildOldoraTemplate($subject, $htmlBody, $heading = '', $buttonText = '', $buttonUrl = '') {
    $logoUrl = "https://i.postimg.cc/bv1QQwBc/1768055586557.png";
    $siteName = "OLDORA";
    $year = date("Y");

    // تنظيف بسيط (ما كيحيدش HTML ديالك)
    $subjectSafe = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $headingSafe = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $buttonTextSafe = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');

    $btnBlock = "";
    if (!empty($buttonText) && !empty($buttonUrl)) {
        $buttonUrlSafe = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
        $btnBlock = '
          <div style="text-align:center;margin:18px 0 6px 0;">
            <a href="'.$buttonUrlSafe.'" style="display:inline-block;text-decoration:none;
              background: linear-gradient(135deg, #00C6FF 0%, #0072FF 100%);
              color:#ffffff;padding:12px 18px;border-radius:12px;font-weight:700;">
              '.$buttonTextSafe.'
            </a>
          </div>
          <div style="font-size:12px;color:#6b7280;margin-top:10px;text-align:center;">
            If the button doesn’t work, open this link:<br>
            <span style="color:#9aa0a6;">'.$buttonUrlSafe.'</span>
          </div>
        ';
    }

    $headingBlock = "";
    if (!empty($heading)) {
        $headingBlock = '<div style="font-size:15px;margin-bottom:12px;color:#e8eaed;">'.$headingSafe.'</div>';
    }

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
                    '.$headingBlock.'
                    <div style="color:#e8eaed;line-height:1.8;">
                      '.$htmlBody.'
                    </div>
                    '.$btnBlock.'
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

// ✅ قراءة الإيميلات من ملف TXT
function getEmailsFromTxtFile($fileInputName) {
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Please upload a valid TXT file.");
    }

    $fileName = $_FILES[$fileInputName]['name'];
    $fileTmp  = $_FILES[$fileInputName]['tmp_name'];
    $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileExt !== 'txt') {
        throw new Exception("Only TXT files are allowed.");
    }

    $content = file_get_contents($fileTmp);

    if (trim($content) === '') {
        throw new Exception("The TXT file is empty.");
    }

    $parts = preg_split('/[\s,;]+/', $content);
    $emails = [];

    foreach ($parts as $email) {
        $email = trim($email);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower($email);
        }
    }

    $emails = array_unique($emails);

    if (count($emails) === 0) {
        throw new Exception("No valid emails found in the TXT file.");
    }

    return $emails;
}

// ==========================================
// منطق الإرسال
// ==========================================
if(isset($_POST['send_mail_btn'])){
    
    $subject = $_POST['subject']; 
    $message_body = $_POST['message']; 
    $recipient_type = $_POST['recipient_type']; // all / single / file

    // ✅ حقول اختيارية جديدة (ما كتأثرش إذا خليتيهم خاويين)
    $heading = $_POST['email_heading'] ?? '';
    $button_text = $_POST['button_text'] ?? '';
    $button_url  = $_POST['button_url'] ?? '';

    $mail = new PHPMailer(true);

    try {
        // ✅ إعدادات السيرفر من ملف .env
        $mail->isSMTP();
        $mail->Host       = $env['SMTP_HOST']; 
        $mail->SMTPAuth   = true;
        $mail->Username   = $env['SMTP_USER'];
        $mail->Password   = $env['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $env['SMTP_PORT'];

        // إعدادات المرسل
        $mail->setFrom($env['SMTP_USER'], 'Oldora Admin'); 
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8'; 
        $mail->Subject = $subject;

        // ✅ ندخلو HTML ديالك داخل Template ديزاين
        $mail->Body    = buildOldoraTemplate($subject, $message_body, $heading, $button_text, $button_url);
        $mail->AltBody = strip_tags($message_body);

        $alreadySent = false;

        // ------------------------------------------------
        // 🚀 تحديد المستقبلين (Logic Switch)
        // ------------------------------------------------
        if ($recipient_type === 'single') {

            // 👤 إرسال لشخص واحد فقط
            $single_email = trim($_POST['single_email']);

            if (!filter_var($single_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address format.");
            }

            $mail->addAddress($single_email);
            $target_msg = "user ($single_email)";

        } elseif ($recipient_type === 'file') {

            // 📄 إرسال إلى إيميلات من ملف TXT
            set_time_limit(0);

            $emails = getEmailsFromTxtFile('emails_file');

            $batchSize = 5;
            $sleepSeconds = 10;
            $totalSent = 0;
            $failedEmails = [];

            foreach (array_chunk($emails, $batchSize) as $batch) {

                $mail->clearAddresses();
                $mail->clearCCs();
                $mail->clearBCCs();
                $mail->clearReplyTos();
                $mail->ErrorInfo = '';

                foreach ($batch as $email) {
                    $mail->addBCC($email);
                }

                try {
                    $mail->send();
                    $totalSent += count($batch);
                } catch (Exception $e) {
                    foreach ($batch as $failedEmail) {
                        $failedEmails[] = $failedEmail;
                    }
                }

                sleep($sleepSeconds);
            }

            $alreadySent = true;

            if (count($failedEmails) > 0) {
                $target_msg = "$totalSent emails from TXT file. Failed: " . count($failedEmails);
            } else {
                $target_msg = "$totalSent emails from TXT file";
            }

        } else {

            // 👥 إرسال للجميع (Bulk)
            $sql = "SELECT email FROM users WHERE role = 'user'";
            $run = mysqli_query($con, $sql);

            if (!$run) {
                throw new Exception("Database query failed.");
            }

            set_time_limit(0);

            $usersEmails = [];

            while($row = mysqli_fetch_assoc($run)){
                if (!empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $usersEmails[] = strtolower(trim($row['email']));
                }
            }

            $usersEmails = array_unique($usersEmails);

            if (count($usersEmails) === 0) {
                throw new Exception("No valid users emails found.");
            }

            $batchSize = 5;
            $sleepSeconds = 10;
            $totalSent = 0;
            $failedEmails = [];

            foreach (array_chunk($usersEmails, $batchSize) as $batch) {

                $mail->clearAddresses();
                $mail->clearCCs();
                $mail->clearBCCs();
                $mail->clearReplyTos();
                $mail->ErrorInfo = '';

                foreach ($batch as $email) {
                    $mail->addBCC($email);
                }

                try {
                    $mail->send();
                    $totalSent += count($batch);
                } catch (Exception $e) {
                    foreach ($batch as $failedEmail) {
                        $failedEmails[] = $failedEmail;
                    }
                }

                sleep($sleepSeconds);
            }

            $alreadySent = true;

            if (count($failedEmails) > 0) {
                $target_msg = "$totalSent users. Failed: " . count($failedEmails);
            } else {
                $target_msg = "all users ($totalSent emails)";
            }
        }

        if (!$alreadySent) {
            $mail->send();
        }
        
        $msg = "Email successfully sent to $target_msg! ✅";
        $msg_type = "success";

    } catch (Exception $e) {
        $msg = "Message could not be sent. Error: {$e->getMessage()}";
        if(isset($mail->ErrorInfo) && !empty($mail->ErrorInfo)){
             $msg .= " | Mailer Error: " . $mail->ErrorInfo;
        }
        $msg_type = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Send Email | Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { 
            background: radial-gradient(circle at top, #2e0b16, #000); 
            color: #fff; 
            min-height: 100vh; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .sidebar { min-height: 100vh; background: rgba(0,0,0,0.4); border-right: 1px solid #333; }
        .sidebar h4 { color: #ff003c; font-weight: bold; letter-spacing: 1px; }
        .nav-link { color: #ccc; padding: 12px; transition: 0.3s; border-radius: 5px; margin-bottom: 5px; }
        .nav-link i { margin-right: 10px; width: 20px; text-align: center; }
        .nav-link:hover, .nav-link.active { color: #fff; background: rgba(255,0,60,0.2); border-left: 4px solid #ff003c; }
        .glass-panel { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 15px; padding: 30px; }
        .form-control, .custom-select { background: rgba(0,0,0,0.3); border: 1px solid #444; color: #fff; }
        .form-control:focus, .custom-select:focus { background: rgba(0,0,0,0.5); color: #fff; border-color: #ff003c; box-shadow: none; }
        .btn-custom { background: #ff003c; border: none; color: white; padding: 10px 30px; font-weight: bold; transition: 0.3s; }
        .btn-custom:hover { background: #d60033; transform: scale(1.05); }
        option { background: #000; color: #fff; }
        .helper-text{ font-size:12px; color:#bbb; margin-top:6px; }
        .mini-note{ font-size:12px; color:#9aa0a6; margin-top:10px; }
        .divider{ height:1px; background:rgba(255,255,255,0.12); margin:18px 0; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar p-4 d-none d-md-block">
            <h4 class="mb-5"><i class="fa-solid fa-robot"></i> AI ADMIN</h4>
            <nav class="nav flex-column">
                <a class="nav-link" href="admin-dashboard.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
                <a class="nav-link" href="admin-users.php"><i class="fa-solid fa-users"></i> Users & Plans</a>
                <a class="nav-link active" href="admin-email.php"><i class="fa-solid fa-envelope"></i> Send Email</a>
                <a class="nav-link mt-5 text-muted" href="logout-user.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
            </nav>
        </div>

        <div class="col-md-10 p-5">
            <div class="d-flex justify-content-between align-items-center mb-5">
                <h2>Broadcast Email 📢</h2>
            </div>

            <?php if($msg != ""): ?>
                <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $msg; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="glass-panel">
                        <form method="POST" action="" enctype="multipart/form-data">
                            
                            <div class="form-group">
                                <label class="text-white-50">Recipient Type</label>
                                <select name="recipient_type" id="recipient_type" class="custom-select" onchange="toggleEmailInput()">
                                    <option value="all">📢 Send to All Users (Broadcast)</option>
                                    <option value="single">👤 Send to Specific User</option>
                                    <option value="file">📄 Send to Emails from TXT File</option>
                                </select>
                            </div>

                            <div class="form-group" id="single_email_group" style="display: none;">
                                <label class="text-white-50">User Email Address</label>
                                <input type="email" name="single_email" id="single_email" class="form-control" placeholder="user@example.com">
                            </div>

                            <div class="form-group" id="emails_file_group" style="display: none;">
                                <label class="text-white-50">Upload TXT File</label>
                                <input type="file" name="emails_file" id="emails_file" class="form-control" accept=".txt">
                                <div class="helper-text">
                                    Put emails inside TXT file. One email per line, or separated by comma / semicolon / space.
                                </div>
                            </div>

                            <div class="divider"></div>

                            <!-- ✅ حقول اختيارية للي بغى Template احترافي -->
                            <div class="form-group">
                                <label class="text-white-50">Email Heading (Optional)</label>
                                <input type="text" name="email_heading" class="form-control" placeholder="e.g. Important Update for Oldora Users">
                                <div class="helper-text">This appears at the top of the email content inside the designed card.</div>
                            </div>

                            <div class="form-group">
                                <label class="text-white-50">Button Text (Optional)</label>
                                <input type="text" name="button_text" class="form-control" placeholder="e.g. Open Dashboard">
                            </div>

                            <div class="form-group">
                                <label class="text-white-50">Button URL (Optional)</label>
                                <input type="url" name="button_url" class="form-control" placeholder="https://yourdomain.com/...">
                                <div class="helper-text">If you fill Button Text + URL, a gradient button will appear in email.</div>
                            </div>

                            <div class="divider"></div>

                            <div class="form-group">
                                <label class="text-white-50">Email Subject</label>
                                <input type="text" name="subject" class="form-control" placeholder="e.g. Important Update" required>
                            </div>

                            <div class="form-group">
                                <label class="text-white-50">Message Body (HTML Allowed)</label>
                                <textarea name="message" id="message" class="form-control" rows="8" placeholder="Type your message here..." required></textarea>
                                <div class="mini-note">
                                    ✅ You can write HTML here (CKEditor). It will be wrapped in OLDORA email design automatically.
                                </div>
                            </div>

                            <button type="submit" name="send_mail_btn" class="btn btn-custom btn-block mt-4">
                                <i class="fa-solid fa-paper-plane"></i> Send Email
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.ckeditor.com/4.16.2/standard/ckeditor.js"></script>

<script>
    // تفعيل محرر النصوص
    CKEDITOR.replace('message');

    // كود إظهار/إخفاء حقل الإيميل حسب الاختيار
    function toggleEmailInput() {
        var type = document.getElementById("recipient_type").value;

        var emailGroup = document.getElementById("single_email_group");
        var emailInput = document.getElementById("single_email");

        var fileGroup = document.getElementById("emails_file_group");
        var fileInput = document.getElementById("emails_file");

        if (type === "single") {
            emailGroup.style.display = "block";
            emailInput.setAttribute("required", "required");

            fileGroup.style.display = "none";
            fileInput.removeAttribute("required");
            fileInput.value = "";

        } else if (type === "file") {
            fileGroup.style.display = "block";
            fileInput.setAttribute("required", "required");

            emailGroup.style.display = "none";
            emailInput.removeAttribute("required");
            emailInput.value = "";

        } else {
            emailGroup.style.display = "none";
            emailInput.removeAttribute("required");
            emailInput.value = "";

            fileGroup.style.display = "none";
            fileInput.removeAttribute("required");
            fileInput.value = "";
        }
    }

    toggleEmailInput();
</script>

</body>
</html>
