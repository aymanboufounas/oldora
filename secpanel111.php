<?php
// =====================================================
//  🛡️ ADMIN LOGIN - FIXED (Ban System + 2FA + Telegram Alert)
// =====================================================

// 1. بدء الجلسة أولاً لتجنب مشاكل الهيدر
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

require_once "connection.php";

// =====================================================
// 🤖 دالة إرسال إشعار تيليجرام (تمت إضافتها)
// =====================================================
function sendTelegramNotification($email, $ip, $status, $country, $device) {
    $chat_id = "5077182872"; // الآيدي الخاص بك
    $token = '';

    // محاولة جلب التوكن من ملف .env خارج المجلد العام
    $envPath = __DIR__ . '/../.env'; 
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            if (trim($name) == 'TELEGRAM_BOT_TOKEN') {
                $token = trim($value);
                break;
            }
        }
    }

    if (!empty($token)) {
        $msg = "🛡 <b>Admin Login Attempt</b>\n";
        $msg .= "━━━━━━━━━━━━━━\n";
        $msg .= "📧 <b>Email:</b> " . $email . "\n";
        $msg .= "📡 <b>IP:</b> " . $ip . "\n";
        $msg .= "🏳️ <b>Country:</b> " . $country . "\n";
        $msg .= "📊 <b>Status:</b> " . ($status == 'Success' ? '✅ Success' : '❌ Failed') . "\n";
        $msg .= "💻 <b>Device:</b> " . substr($device, 0, 40);

        $url = "https://api.telegram.org/bot$token/sendMessage";
        $data = ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_exec($ch);
        curl_close($ch);
    }
}
// =====================================================

// 2. التحقق من الحظر (BANNED CHECK)
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$ban_check = $con->prepare("SELECT id FROM banned_visitors WHERE user_agent = ? LIMIT 1");
$ban_check->bind_param("s", $user_agent);
$ban_check->execute();
if ($ban_check->get_result()->num_rows > 0) {
    http_response_code(403);
    die("<body style='background:#000;color:red;display:flex;justify-content:center;align-items:center;height:100vh;font-family:monospace;text-align:center'>
            <div><h1 style='font-size:50px'>🚫 ACCESS DENIED</h1><p>Device Banned.</p></div>
         </body>");
}
$ban_check->close();

// 3. منع التوجيه المتكرر (Redirect Loop Fix)
// نقوم بالتوجيه فقط إذا كان المستخدم "admin" ولديه جلسة كاملة، وليس جلسة مؤقتة (2FA)
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header("Location: admin-dashboard.php");
    exit();
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// 4. معالجة الدخول
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['admin_login'])) {

    // التحقق من التوكن
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors['login'] = "Session expired. Please refresh.";
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $ip_address = getUserIP();
        $geo = getIPInfo($ip_address);
        $country = $geo['country'] ?? 'Unknown';

        if (!empty($email) && !empty($password)) {
            $stmt = $con->prepare("SELECT id, password, role, is_2fa_enabled FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $login_success = false;

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password']) && $user['role'] === 'admin') {
                    $login_success = true;
                    
                    // تسجيل نجاح مبدئي
                    $con->query("INSERT INTO login_logs (email, ip_address, country, status, device_info) VALUES ('$email', '$ip_address', '$country', 'Success-Auth', '$user_agent')");

                    // 🔥 إرسال إشعار النجاح لتيليجرام
                    sendTelegramNotification($email, $ip_address, 'Success', $country, $user_agent);

                    // تجديد الجلسة
                    session_regenerate_id(true);
                    
                    // منطق 2FA
                    if ($user['is_2fa_enabled'] == 1) {
                        // تعيين جلسة مؤقتة فقط
                        $_SESSION['temp_user_id'] = $user['id'];
                        $_SESSION['temp_email'] = $email;
                        header("Location: admin-2fa-verify.php"); // التوجيه لصفحة الكود
                        exit();
                    } else {
                        // دخول مباشر (أو توجيه لإعداد 2FA إذا كان إلزامياً)
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $email;
                        $_SESSION['role'] = 'admin';
                        header("Location: admin-dashboard.php");
                        exit();
                    }
                }
            }

            if (!$login_success) {
                $errors['login'] = "Incorrect email or password.";
                
                // تسجيل الفشل
                $stmt_log = $con->prepare("INSERT INTO login_logs (email, ip_address, country, status, device_info) VALUES (?, ?, ?, 'Failed', ?)");
                $stmt_log->bind_param("ssss", $email, $ip_address, $country, $user_agent);
                $stmt_log->execute();
                
                // 🔥 إرسال إشعار الفشل لتيليجرام
                sendTelegramNotification($email, $ip_address, 'Failed', $country, $user_agent);

                $stmt_log->close();

                // التحقق من عدد المحاولات للحظر (3 Strikes)
                $stmt_count = $con->prepare("SELECT COUNT(*) as cnt FROM login_logs WHERE device_info = ? AND status = 'Failed' AND attempt_time > (NOW() - INTERVAL 1 DAY)");
                $stmt_count->bind_param("s", $user_agent);
                $stmt_count->execute();
                $res_count = $stmt_count->get_result()->fetch_assoc();

                if ($res_count['cnt'] >= 3) {
                    $con->query("INSERT INTO banned_visitors (user_agent) VALUES ('$user_agent')");
                    die("<script>window.location.reload();</script>"); // إعادة تحميل لتفعيل الحظر
                }
            }
            $stmt->close();
        } else {
            $errors['login'] = "All fields required.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: radial-gradient(circle at top, #1a0b2e, #000); min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Cairo', sans-serif; color: #fff; overflow: hidden; }
        body::before { content: ''; position: absolute; width: 150%; height: 150%; background: url('https://www.transparenttextures.com/patterns/stardust.png'); opacity: 0.1; animation: moveBackground 50s linear infinite; z-index: -1; }
        @keyframes moveBackground { 0% { transform: translate(0, 0); } 100% { transform: translate(-10%, -10%); } }
        .login-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; padding: 40px 30px; width: 100%; max-width: 420px; box-shadow: 0 0 40px rgba(0, 0, 0, 0.5); }
        .admin-avatar { width: 90px; height: 90px; background: linear-gradient(135deg, #ff0050, #550022); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 0 20px rgba(255, 0, 80, 0.4); font-size: 40px; color: white; }
        .form-control { background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); color: #fff; height: 55px; padding-left: 20px; border-radius: 12px; transition: 0.3s; }
        .form-control:focus { background: rgba(0, 0, 0, 0.6); border-color: #ff0050; box-shadow: 0 0 15px rgba(255, 0, 80, 0.2); color: #fff; }
        .btn-login { background: linear-gradient(90deg, #ff0050, #cc0040); border: none; height: 55px; border-radius: 12px; font-weight: 700; font-size: 1.1rem; color: white; transition: 0.3s; margin-top: 10px; width: 100%; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(255, 0, 80, 0.4); color: #fff; }
        .input-group-text { background: transparent; border: none; color: #aaa; position: absolute; right: 15px; top: 18px; z-index: 10; }
        .form-group { position: relative; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="login-card text-center">
                <div class="admin-avatar"><i class="fa-solid fa-user-shield"></i></div>
                <h2 class="mb-1">Admin Panel</h2>
                <p class="text-white-50 mb-4">Secure Access Gateway</p>
                
                <?php if(!empty($errors['login'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($errors['login']); ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group text-left">
                        <label class="small text-white-50 pl-1">Email Address</label>
                        <input class="form-control" type="email" name="email" required>
                        <i class="fa fa-envelope input-group-text"></i>
                    </div>
                    <div class="form-group text-left">
                        <label class="small text-white-50 pl-1">Password</label>
                        <input class="form-control" type="password" name="password" required>
                        <i class="fa fa-lock input-group-text"></i>
                    </div>
                    <button class="btn btn-login" type="submit" name="admin_login">Login <i class="fa fa-arrow-right ml-2"></i></button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
