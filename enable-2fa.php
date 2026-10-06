<?php
require_once "connection.php";
require_once "GoogleAuthenticator.php";
session_start();

// 1. Check if user is logged in
if (!isset($_SESSION['email'])) {
    header('location: login-user.php');
    exit();
}

$ga = new PHPGangsta_GoogleAuthenticator();

// 2. Generate a new secret key
// (In a real scenario, you might want to save this to the DB temporarily until verified)
$secret = $ga->createSecret(); 

// 3. Set your App Name
$appName = "OLDORA";
$userEmail = $_SESSION['email'];

// 4. Generate the QR Code Link
// The format is "AppName:Email" for the label, and "AppName" for the issuer.
// This ensures "OLDORA" appears clearly in the app.
$qrCodeUrl = $ga->getQRCodeGoogleUrl($appName . ':' . $userEmail, $secret, $appName);

// Optional: Add image parameter (Works on some apps, ignored by Google Auth)
$logoUrl = "https://i.postimg.cc/bv1QQwBc/1768055586557.png";
$qrCodeUrl .= "&image=" . urlencode($logoUrl);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup 2FA | OLDORA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
            /* Glow effect for the logo */
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
            user-select: all; /* Makes it easy to copy */
        }
        .form-control {
            background: rgba(255,255,255,0.1);
            border: 1px solid #444;
            color: #fff;
            height: 50px;
            font-size: 18px;
            letter-spacing: 3px;
        }
        .form-control:focus {
            background: rgba(255,255,255,0.15);
            color: #fff;
            box-shadow: 0 0 10px rgba(0,255,240,0.5);
            border-color: #00fff0;
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
            transition: 0.3s;
        }
        .btn-confirm:hover {
            transform: scale(1.03);
            box-shadow: 0 0 20px rgba(0,255,240,0.6);
        }
        h4 { color: #00fff0; font-weight: 700; }
        .text-muted { color: #aaa !important; }
    </style>
</head>
<body>

<div class="setup-card">
    
    <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" alt="OLDORA Logo" class="app-logo">

    <h4>Secure Your Account</h4>
    <p class="text-muted">Scan the QR code below with <strong>Google Authenticator</strong></p>

    <div class="qr-box">
        <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code" width="200" height="200">
    </div>

    <p class="mb-2" style="font-size: 0.9rem;">Can't scan? Enter this code manually:</p>
    <div class="secret-key"><?php echo $secret; ?></div>

    <form action="verify_first_otp.php" method="POST">
        <input type="hidden" name="secret" value="<?php echo $secret; ?>">
        
        <div class="form-group">
            <label class="text-left w-100 pl-1" style="font-size: 0.85rem; color: #ccc;">Enter the 6-digit code from the app</label>
            <input type="text" name="otp" class="form-control text-center" placeholder="000 000" maxlength="6" required autocomplete="off">
        </div>
        
        <button type="submit" class="btn btn-confirm">ACTIVATE 2FA</button>
        
        <div class="mt-3">
            <a href="profile.php" class="text-muted" style="font-size: 0.9rem; text-decoration: none;">Cancel</a>
        </div>
    </form>
</div>

</body>
</html>