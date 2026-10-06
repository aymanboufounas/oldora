<?php
require_once "connection.php";
session_start();

// التحقق من الأدمن
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
    header('location: secpanel111.php');
    exit();
}

$msg = "";

/* =========================================================
   ✅✅✅ ADDED: SMTP + PHPMailer + OLDORA Email Template
   ========================================================= */

// -----------------------------------------------------
// تحميل إعدادات SMTP من .env
// -----------------------------------------------------
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

// PHPMailer includes
require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

        $mail->setFrom($smtpUser, 'OLDORA Support');
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


// معالجة الرد على التذكرة
if (isset($_POST['reply_ticket'])) {

    $ticket_id = $_POST['ticket_id'];
    $reply = mysqli_real_escape_string($con, $_POST['reply_text']);

    // ✅✅✅ ADDED: Fetch ticket info (email + subject + message) before update
    $ticket_id_esc = mysqli_real_escape_string($con, $ticket_id);
    $sql_ticket = "SELECT id, user_email, subject, message FROM support_tickets WHERE id='$ticket_id_esc' LIMIT 1";
    $run_ticket = mysqli_query($con, $sql_ticket);
    $ticket = $run_ticket ? mysqli_fetch_assoc($run_ticket) : null;

    // تحديث التذكرة: إضافة الرد وتغيير الحالة إلى مغلق
    $sql_update = "UPDATE support_tickets SET admin_reply='$reply', status='closed' WHERE id='$ticket_id'";
    if (mysqli_query($con, $sql_update)) {

        // ✅✅✅ ADDED: Send email to user with admin reply (without breaking anything)
        if ($ticket && !empty($ticket['user_email'])) {
            $to = trim($ticket['user_email']);
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {

                $subj = "Support Reply: " . (string)$ticket['subject'];

                $content = "
                    <div style='font-size:15px;margin-bottom:10px;'>
                        Hello,
                    </div>
                    <div style='color:#e8eaed;line-height:1.8;'>
                        Our support team has replied to your ticket.
                        <br><br>
                        <b>Subject:</b> " . htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8') . "<br>
                        <b>Your message:</b><br>
                        <div style='margin-top:8px;padding:12px;border-radius:12px;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.08);'>
                            " . nl2br(htmlspecialchars($ticket['message'], ENT_QUOTES, 'UTF-8')) . "
                        </div>
                        <br>
                        <b>Admin reply:</b><br>
                        <div style='margin-top:8px;padding:12px;border-radius:12px;background:rgba(0, 198, 255, 0.10);border:1px solid rgba(0, 198, 255, 0.25);'>
                            " . nl2br(htmlspecialchars($_POST['reply_text'], ENT_QUOTES, 'UTF-8')) . "
                        </div>
                        <br>
                        Thank you for contacting OLDORA Support.
                    </div>
                ";

                // إرسال (إذا فشل ما نخليوش الصفحة تطيح)
                @sendOldoraMail($env, $to, $subj, $content);
            }
        }

        $msg = "<div class='alert alert-success'>Reply sent successfully!</div>";

    } else {
        $msg = "<div class='alert alert-danger'>Error sending reply.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Support Tickets</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            background: radial-gradient(circle at top, #2e0b16, #000); 
            color: #fff; 
            min-height: 100vh; 
            font-family: 'Cairo', sans-serif;
        }
        .sidebar { min-height: 100vh; background: rgba(0,0,0,0.4); border-right: 1px solid #333; }
        .nav-link { color: #ccc; }
        .nav-link:hover { color: #fff; }
        
        .card-ticket { 
            background: rgba(20, 25, 40, 0.8); 
            border: 1px solid rgba(255,255,255,0.1); 
            margin-bottom: 20px; 
            border-radius: 10px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        
        .badge-open { background-color: #ffc107; color: #000; }
        .badge-closed { background-color: #28a745; color: #fff; }
        .img-thumb { max-width: 100px; max-height: 100px; cursor: pointer; border-radius: 5px; border: 1px solid #555; }
        
        /* تنسيق الخطط بناء على ملف Planning.php */
        .badge-elite { 
            background: #ffd700; /* Gold/Secondary */
            color: #000; 
            font-weight: bold; 
            box-shadow: 0 0 10px rgba(255, 215, 0, 0.4);
        }
        .badge-pro { 
            background: linear-gradient(90deg, #00fff0, #9d4edd); /* Primary to Accent */
            color: #fff; 
            font-weight: bold;
        }
        .badge-basic { 
            background: #17a2b8; 
            color: #fff; 
        }
        .badge-free { 
            background: #6c757d; 
            color: #fff; 
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar p-4 d-none d-md-block">
            <h4 class="mb-5 text-danger font-weight-bold">AI ADMIN</h4>
            <nav class="nav flex-column">
                <a class="nav-link" href="admin-dashboard.php"><i class="fa-solid fa-chart-line mr-2"></i> Dashboard</a>
                <a class="nav-link active text-white" href="admin-support.php"><i class="fa-solid fa-headset mr-2"></i> Support</a>
                <a class="nav-link" href="home.php"><i class="fa-solid fa-home mr-2"></i> Home</a>
            </nav>
        </div>

        <div class="col-md-10 p-5">
            <h2 class="mb-4">Support Tickets (Priority Sort)</h2>
            <?php echo $msg; ?>

            <div class="row">
                <?php
                // =========================================================
                // إعدادات الترتيب حسب الخطة
                // =========================================================
                
                // هام: غير 'subscription' إلى اسم العمود الصحيح في الداتا بيس عندك إذا كان مختلفاً
                $plan_col = 'subscription'; 

                $sql = "SELECT t.*, u.full_name, u.$plan_col 
                        FROM support_tickets t 
                        LEFT JOIN users u ON t.user_email = u.email 
                        ORDER BY 
                        CASE 
                            WHEN u.$plan_col = 'unlimited' THEN 1  -- Elite Plan
                            WHEN u.$plan_col = 'pro' THEN 2        -- Pro Plan
                            WHEN u.$plan_col = 'basic' THEN 3      -- Basic Plan
                            ELSE 4                                 -- Free users
                        END ASC,
                        t.created_at DESC"; // الترتيب الثانوي بالوقت

                $run = mysqli_query($con, $sql);

                if ($run && mysqli_num_rows($run) > 0) {
                    while ($row = mysqli_fetch_assoc($run)) {
                        // حالة التذكرة
                        $status_badge = ($row['status'] == 'open') ? '<span class="badge badge-open">Open</span>' : '<span class="badge badge-closed">Closed</span>';
                        $bg_style = ($row['status'] == 'open') ? 'border-left: 5px solid #ffc107;' : 'border-left: 5px solid #28a745; opacity: 0.8;';
                        
                        // تحديد شكل الخطة بناءً على القيمة
                        $plan_val = isset($row[$plan_col]) ? strtolower($row[$plan_col]) : '';
                        $plan_badge = "";

                        if($plan_val == 'unlimited') {
                            // Elite Plan
                            $plan_badge = '<span class="badge badge-elite ml-2"><i class="fas fa-gem"></i> ELITE</span>';
                        } elseif($plan_val == 'pro') {
                            // Pro Plan
                            $plan_badge = '<span class="badge badge-pro ml-2"><i class="fas fa-crown"></i> PRO</span>';
                        } elseif($plan_val == 'basic') {
                            // Basic Plan
                            $plan_badge = '<span class="badge badge-basic ml-2"><i class="fas fa-rocket"></i> BASIC</span>';
                        } else {
                            // Free
                            $plan_badge = '<span class="badge badge-free ml-2">FREE</span>';
                        }
                ?>
                    <div class="col-12">
                        <div class="card card-ticket p-3" style="<?php echo $bg_style; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <h5 class="m-0 font-weight-bold text-info">
                                        <?php echo htmlspecialchars($row['subject']); ?>
                                        <?php echo $status_badge; ?>
                                    </h5>
                                    <div class="mt-2">
                                        <small class="text-light" style="font-size: 0.9rem;">
                                            <i class="fa-solid fa-user"></i> <?php echo $row['full_name'] ? $row['full_name'] : 'Unknown'; ?>
                                            <?php echo $plan_badge; ?> </small>
                                        <span class="text-muted mx-2">|</span>
                                        <small class="text-muted">
                                            <i class="fa-solid fa-envelope"></i> <?php echo $row['user_email']; ?>
                                        </small>
                                        <span class="text-muted mx-2">|</span>
                                        <small class="text-muted">
                                            <i class="fa-solid fa-clock"></i> <?php echo $row['created_at']; ?>
                                        </small>
                                    </div>
                                </div>
                                <?php if (!empty($row['image_path'])): ?>
                                    <a href="<?php echo $row['image_path']; ?>" target="_blank">
                                        <img src="<?php echo $row['image_path']; ?>" class="img-thumb" title="Click to enlarge">
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="bg-dark p-3 rounded mb-3 text-white">
                                <strong>Message:</strong><br>
                                <?php echo nl2br(htmlspecialchars($row['message'])); ?>
                            </div>

                            <?php if($row['status'] == 'open'): ?>
                                <form method="POST" action="">
                                    <input type="hidden" name="ticket_id" value="<?php echo $row['id']; ?>">
                                    <div class="input-group">
                                        <textarea name="reply_text" class="form-control" rows="1" placeholder="Write a reply..." required></textarea>
                                        <div class="input-group-append">
                                            <button type="submit" name="reply_ticket" class="btn btn-success"><i class="fa-solid fa-paper-plane"></i> Reply</button>
                                        </div>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-success p-2 mb-0">
                                    <strong><i class="fa-solid fa-check"></i> Replied:</strong> <?php echo htmlspecialchars($row['admin_reply']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php 
                    }
                } else {
                    if(!$run) {
                        echo "<div class='alert alert-danger'>Database Error: " . mysqli_error($con) . "</div>";
                    } else {
                        echo "<div class='col-12'><div class='alert alert-info text-center'>No support tickets found.</div></div>";
                    }
                }
                ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
