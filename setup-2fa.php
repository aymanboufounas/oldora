<?php
require_once "connection.php";
require_once "GoogleAuthenticator.php";
session_start();

if (!isset($_SESSION['email'])) {
    header('location: login-user.php');
    exit();
}

$ga = new PHPGangsta_GoogleAuthenticator();
$secret = $ga->createSecret();
$appName = "OLDORA";
$userEmail = $_SESSION['email'];

// بدلاً من توليد رابط صورة، سنقوم بتجهيز رابط البيانات الخام (URI)
// هذا الرابط هو ما يفهمه تطبيق Google Authenticator
$otpAuthUrl = "otpauth://totp/" . $appName . ":" . $userEmail . "?secret=" . $secret . "&issuer=" . $appName;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup 2FA | OLDORA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        body {
            background: radial-gradient(circle at top, #0f2027, #000);
            color: #fff;
            font-family: 'Cairo', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(15px);
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            max-width: 500px;
            width: 100%;
            box-shadow: 0 0 40px rgba(0,0,0,0.5);
        }
        .app-logo {
            width: 140px;
            margin-bottom: 25px;
            filter: drop-shadow(0 0 15px rgba(0,255,240,0.4)); 
        }
        .qr-box {
            background: #fff;
            padding: 15px;
            border-radius: 15px;
            display: inline-block;
            margin: 20px 0;
            box-shadow: 0 0 15px rgba(0,0,0,0.3);
        }
        .secret-key {
            background: rgba(0, 0, 0, 0.6);
            padding: 12px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            color: #00fff0;
            font-size: 1.1rem;
            letter-spacing: 2px;
            margin-bottom: 25px;
            border: 1px solid #333;
            word-break: break-all;
            user-select: all;
        }
        .form-control {
            background: rgba(255,255,255,0.1);
            border: 1px solid #444;
            color: #fff;
            height: 50px;
            font-size: 18px;
            letter-spacing: 3px;
        }
        .btn-confirm {
            background: linear-gradient(135deg, #00fff0, #0066ff);
            border: none;
            color: #000;
            font-weight: 800;
            padding: 12px 30px;
            border-radius: 12px;
            width: 100%;
            margin-top: 15px;
        }
    </style>
</head>
<body>

<div class="setup-card">
    
    <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" alt="OLDORA Logo" class="app-logo">

    <h4>Secure Your Account</h4>
    <p class="text-muted" style="color: #aaa;">Scan the QR code below</p>

    <div class="qr-box" id="qrcode"></div>

    <p class="mb-2" style="font-size: 0.9rem;">Or enter code manually:</p>
    <div class="secret-key"><?php echo $secret; ?></div>

    <form action="verify_first_otp.php" method="POST">
        <input type="hidden" name="secret" value="<?php echo $secret; ?>">
        
        <div class="form-group">
            <input type="text" name="otp" class="form-control text-center" placeholder="000 000" maxlength="6" required autocomplete="off">
        </div>
        
        <button type="submit" class="btn btn-confirm">ACTIVATE 2FA</button>
    </form>
</div>

<script type="text/javascript">
    var otpUrl = "<?php echo $otpAuthUrl; ?>";
    new QRCode(document.getElementById("qrcode"), {
        text: otpUrl,
        width: 180,
        height: 180,
        colorDark : "#000000",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });
</script>

</body>
</html>