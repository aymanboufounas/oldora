<?php
// verify-otp.php
session_start();
require_once "connection.php";

if (!isset($_SESSION['otp_email'])) {
    header("location: forgot-password.php");
    exit();
}

$email = $_SESSION['otp_email'];
$errors = [];

if (isset($_POST['verify_otp'])) {
    $otp_input = $_POST['otp_code'];

    if (empty($otp_input)) {
        $errors[] = "Please enter the verification code.";
    } else {
        // التحقق من صحة الكود وصلاحية الوقت
        $stmt = $con->prepare("SELECT * FROM users WHERE email = ? AND otp_code = ? AND otp_expiry > NOW()");
        $stmt->bind_param("ss", $email, $otp_input);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // 1. تسجيل الدخول (إنشاء الجلسة)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role']; // مهم جداً لتحديد الصلاحيات
            $_SESSION['full_name'] = $user['full_name'];

            // 2. مسح الكود بعد الاستخدام لمنع استخدامه مرة أخرى
            $clear_otp = $con->prepare("UPDATE users SET otp_code = NULL, otp_expiry = NULL WHERE id = ?");
            $clear_otp->bind_param("i", $user['id']);
            $clear_otp->execute();

            // 3. التوجيه حسب الدور (أدمن يذهب للداشبورد، مستخدم للبروفايل)
            if ($user['role'] === 'admin') {
                header("location: admin-dashboard.php");
            } else {
                header("location: profile.php"); // تأكد من تغيير هذا لاسم صفحة البروفايل لديك
            }
            exit();

        } else {
            $errors[] = "Invalid or expired code.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Code</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background: radial-gradient(circle at top, #1a0b2e, #000); height: 100vh; display: flex; align-items: center; justify-content: center; color: #fff; font-family: sans-serif; }
        .card-custom { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); padding: 30px; border-radius: 15px; width: 100%; max-width: 400px; text-align: center; }
        .form-control { background: rgba(0,0,0,0.5); border: 1px solid #444; color: #fff; letter-spacing: 5px; font-size: 20px; text-align: center; }
        .form-control:focus { background: rgba(0,0,0,0.7); color: #fff; border-color: #00d2ff; box-shadow: none; }
        .btn-custom { background: #00d2ff; border: none; width: 100%; padding: 10px; color: #000; border-radius: 5px; font-weight: bold; margin-top: 15px; }
        .btn-custom:hover { background: #00a0c4; }
    </style>
</head>
<body>
    <div class="card-custom">
        <h3 class="mb-2">Enter OTP</h3>
        <p class="text-white-50 small mb-4">We sent a code to <?php echo htmlspecialchars($email); ?></p>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger"><?php echo $errors[0]; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <input type="text" name="otp_code" class="form-control" maxlength="6" placeholder="------" required autocomplete="off">
            </div>
            <button type="submit" name="verify_otp" class="btn btn-custom">Verify & Login</button>
        </form>
    </div>
</body>
</html>