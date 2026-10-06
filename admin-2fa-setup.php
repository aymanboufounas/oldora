<?php
session_start();
require_once "connection.php";
require_once "GoogleAuthenticator.php"; // ✅ الآن الاسم صحيح

// حماية: يجب أن يكون قد مر بصفحة Login أولاً
if (!isset($_SESSION['temp_user_id'])) {
    header("Location: secpanel111.php");
    exit();
}

$ga = new GoogleAuthenticator();
$secret = "";

if (!isset($_SESSION['temp_secret'])) {
    $secret = $ga->createSecret();
    $_SESSION['temp_secret'] = $secret;
} else {
    $secret = $_SESSION['temp_secret'];
}

$qrCodeUrl = $ga->getQRCodeGoogleUrl('AdminPanel', $secret);
$error = "";

if (isset($_POST['verify_code'])) {
    $code = $_POST['code'];
    if ($ga->verifyCode($secret, $code, 2)) {
        // ✅ الكود صحيح: حفظ التفعيل
        $stmt = $con->prepare("UPDATE users SET google_secret = ?, is_2fa_enabled = 1 WHERE id = ?");
        $stmt->bind_param("si", $secret, $_SESSION['temp_user_id']);
        $stmt->execute();

        // تحويل الجلسة إلى أدمن كامل
        $_SESSION['user_id'] = $_SESSION['temp_user_id'];
        $_SESSION['email'] = $_SESSION['temp_email'];
        $_SESSION['role'] = 'admin';
        
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_email']);
        unset($_SESSION['temp_secret']);
        
        header("Location: admin-dashboard.php");
        exit();
    } else {
        $error = "Invalid Code.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Setup 2FA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>body{background:#000;color:#fff;height:100vh;display:flex;align-items:center;justify-content:center;text-align:center}</style>
</head>
<body>
    <div style="background:#111;padding:30px;border-radius:15px;border:1px solid #333">
        <h3 class="text-danger">Setup 2FA</h3>
        <img src="<?php echo $qrCodeUrl; ?>" style="background:white;padding:10px;margin:20px 0;border-radius:5px">
        <p class="text-muted">Secret: <?php echo $secret; ?></p>
        <form method="post">
            <input type="text" name="code" class="form-control text-center" placeholder="Enter Code" style="background:#222;border:1px solid #444;color:#fff;font-size:20px" required>
            <?php if($error) echo "<p class='text-danger mt-2'>$error</p>"; ?>
            <button type="submit" name="verify_code" class="btn btn-danger btn-block mt-3">Activate</button>
        </form>
    </div>
</body>
</html>
