<?php
require_once "connection.php";
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

if (!isset($_SESSION['email'])) {
    header('location: forgot-password.php');
    exit();
}

if (isset($_POST['check-reset-otp'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die("Invalid CSRF");

    $otp = preg_replace('/\D+/', '', $_POST['otp'] ?? '');
    $email = $_SESSION['email'];

    // ✅ Expiry 10 min (من Session)
    $generatedAt = (int)($_SESSION['otp_generated_at'] ?? 0);
    if ($generatedAt <= 0 || (time() - $generatedAt) > 600) {
        $errors['otp-error'] = "Code expired. Please request a new one.";
    } else {

        if (strlen($otp) !== 6) {
            $errors['otp-error'] = "Invalid code format.";
        } else {

            $otp_esc = mysqli_real_escape_string($con, $otp);
            $email_esc = mysqli_real_escape_string($con, $email);

            // ✅ مهم: ربط email + code
            $check = "SELECT id,email FROM users WHERE email='$email_esc' AND code='$otp_esc' LIMIT 1";
            $res = mysqli_query($con, $check);

            if ($res && mysqli_num_rows($res) > 0) {

                $_SESSION['info'] = "Please create a new password that you don't use on any other site.";
                $_SESSION['verified_reset'] = true;

                // (اختياري) ما نمسحوش الكود هنا، نخليه حتى يتبدل الباس
                header('location: profile.php');
                exit();

            } else {
                $errors['otp-error'] = "You've entered incorrect code!";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Verification | OLDORA</title>
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
        .btn-submit { width: 100%; padding: 15px; background: var(--primary-gradient); border: none; border-radius: 12px; color: #fff; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .alert-error { background: rgba(255, 71, 87, 0.1); border: 1px solid rgba(255, 71, 87, 0.3); color: #ff4757; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: rgba(0, 200, 81, 0.1); border: 1px solid rgba(0, 200, 81, 0.3); color: #00c851; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="container-box">
    <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" alt="OLDORA Logo" class="logo-img">
    <div class="brand-name">OLDORA</div>

    <?php if(isset($_SESSION['info']) && $_SESSION['info'] != ""): ?>
        <div class="alert-success"><?php echo $_SESSION['info']; ?></div>
    <?php endif; ?>

    <?php if(count($errors) > 0): ?>
        <div class="alert-error">
            <?php foreach($errors as $error){ echo $error . "<br>"; } ?>
        </div>
    <?php endif; ?>

    <div class="subtitle">Enter the code sent to your email.</div>

    <form action="reset-code.php" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="input-group">
            <input class="input-field" style="text-align: center; letter-spacing: 5px; font-size: 1.2rem;"
                   type="tel" name="otp" placeholder="000000" maxlength="6" required>
            <i class="fa-solid fa-key input-icon"></i>
        </div>

        <button class="btn-submit" type="submit" name="check-reset-otp">Verify Code</button>
    </form>
</div>

</body>
</html>
