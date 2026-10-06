<?php
session_start();
require_once "connection.php";
require_once "GoogleAuthenticator.php"; // تأكد أن ملف المكتبة موجود بهذا الاسم

if (!isset($_SESSION['temp_user_id'])) {
    header("Location: secpanel111.php");
    exit();
}

// فحص الحظر
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$stmt = $con->prepare("SELECT id FROM banned_visitors WHERE user_agent = ? LIMIT 1");
$stmt->bind_param("s", $user_agent);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    die("<body style='background:#000;color:red;text-align:center;padding-top:20%'><h1>🚫 BANNED</h1></body>");
}

$error = "";

if (isset($_POST['check_2fa'])) {
    $code = $_POST['code'];
    
    // جلب السيكرت من قاعدة البيانات
    $s = $con->prepare("SELECT google_secret FROM users WHERE id = ?");
    $s->bind_param("i", $_SESSION['temp_user_id']);
    $s->execute();
    $res = $s->get_result();
    
    if($res->num_rows > 0){
        $secret = $res->fetch_assoc()['google_secret'];
        
        // ============================================================
        // ✅ هذا هو التصحيح (السطر 31 تقريباً)
        // نستخدم اسم الكلاس الطويل الموجود في مكتبتك القديمة
        // ============================================================
        $ga = new PHPGangsta_GoogleAuthenticator();
        
        if ($ga->verifyCode($secret, $code, 2)) {
            // ✅ نجاح - تحويل الجلسة لأدمن كامل
            $_SESSION['user_id'] = $_SESSION['temp_user_id'];
            $_SESSION['email'] = $_SESSION['temp_email'];
            $_SESSION['role'] = 'admin';
            
            $ip = getUserIP(); // تأكد أن دالة getUserIP موجودة في connection.php أو هنا
            $con->query("INSERT INTO login_logs (email, ip_address, status, device_info) VALUES ('".$_SESSION['email']."', '$ip', 'Success-2FA', '$user_agent')");

            unset($_SESSION['temp_user_id']);
            unset($_SESSION['temp_email']);
            
            header("Location: admin-dashboard.php");
            exit();
        } else {
            // ❌ الكود خطأ
            $error = "Invalid Code.";
            $ip = getUserIP();
            $con->query("INSERT INTO login_logs (email, ip_address, status, device_info) VALUES ('".$_SESSION['temp_email']."', '$ip', 'Failed-2FA', '$user_agent')");
            
            // عد الأخطاء للحظر
            $c = $con->prepare("SELECT COUNT(*) as cnt FROM login_logs WHERE device_info = ? AND status = 'Failed-2FA' AND attempt_time > (NOW() - INTERVAL 1 DAY)");
            $c->bind_param("s", $user_agent);
            $c->execute();
            if ($c->get_result()->fetch_assoc()['cnt'] >= 3) {
                $con->query("INSERT INTO banned_visitors (user_agent) VALUES ('$user_agent')");
                die("<body style='background:#000;color:red;text-align:center;padding-top:20%'><h1>🚫 BANNED</h1></body>");
            }
        }
    } else {
        // حالة نادرة: المستخدم ليس لديه secret أصلاً
        header("Location: secpanel111.php");
        exit();
    }
}

// دالة مساعدة لجلب الـ IP إذا لم تكن موجودة في connection.php
if (!function_exists('getUserIP')) {
    function getUserIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
        return $_SERVER['REMOTE_ADDR'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify 2FA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>body{background:#000;color:#fff;height:100vh;display:flex;align-items:center;justify-content:center;text-align:center}</style>
</head>
<body>
    <div style="background:#111;padding:40px;border-radius:15px;border:1px solid #333;width:100%;max-width:350px">
        <h3 class="text-danger mb-4">Security Check</h3>
        <form method="post" autocomplete="off">
            <input type="text" name="code" class="form-control text-center" maxlength="6" placeholder="000000" style="background:#222;border:1px solid #444;color:#fff;font-size:24px;letter-spacing:5px" required autofocus>
            <?php if($error) echo "<p class='text-danger mt-2'>$error</p>"; ?>
            <button type="submit" name="check_2fa" class="btn btn-danger btn-block mt-4">VERIFY</button>
        </form>
    </div>
</body>
</html>
