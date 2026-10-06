<?php
require_once "connection.php";
session_start();

// 1. التحقق من تسجيل الدخول
if (!isset($_SESSION['email'])) {
    header('location: login-user.php');
    exit();
}

$email = $_SESSION['email'];
$disconnect_csrf = oldora_csrf_token();

// 2. جلب بيانات المستخدم + نوع الخطة
$sql_user = "SELECT plan_type FROM users WHERE email = '$email'";
$run_user = mysqli_query($con, $sql_user);
$user_info = mysqli_fetch_assoc($run_user);

// تحديد الخطة والحد الأقصى
$plan_type = $user_info['plan_type'] ?? 'basic'; // الافتراضي basic
$limit_per_platform = 10; // الافتراضي (Basic)

if ($plan_type === 'pro') {
    $limit_per_platform = 30;
} elseif ($plan_type === 'unlimited' || $plan_type === 'elite') {
    $limit_per_platform = 100;
}

// 3. دالة لجلب قائمة الحسابات المتصلة بالكامل
function getConnectedAccounts($con, $email, $platform) {
    // نفترض أن جدول user_tokens يحتوي على أعمدة (id, channel_name, picture) لتخزين تفاصيل القناة
    // إذا لم تكن موجودة، سيتم استخدام بيانات افتراضية في العرض
    $sql = "SELECT * FROM user_tokens WHERE user_email = '$email' AND platform = '$platform'";
    $query = mysqli_query($con, $sql);
    
    $accounts = [];
    while($row = mysqli_fetch_assoc($query)){
        $accounts[] = $row;
    }
    return $accounts;
}

// جلب القوائم لكل منصة
$yt_accounts = getConnectedAccounts($con, $email, 'youtube');
$tt_accounts = getConnectedAccounts($con, $email, 'tiktok');
$ig_accounts = getConnectedAccounts($con, $email, 'instagram');

// حساب الأعداد الحالية
$yt_count = count($yt_accounts);
$tt_count = count($tt_accounts);
$ig_count = count($ig_accounts);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect Platforms | AI Automation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: radial-gradient(circle at top, #0f2027, #000);
            font-family: 'Cairo', sans-serif;
            color: #e9ecef;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .connect-container {
            max-width: 850px;
            margin: auto;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 40px;
            backdrop-filter: blur(15px);
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }
        .header-title {
            text-align: center;
            margin-bottom: 40px;
        }
        .header-title h2 {
            font-weight: 700;
            color: #00fff0;
            text-shadow: 0 0 15px rgba(0, 255, 240, 0.3);
            letter-spacing: 1px;
        }
        .plan-badge {
            background: rgba(0, 255, 240, 0.1);
            border: 1px solid #00fff0;
            color: #00fff0;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-top: 10px;
            display: inline-block;
        }
        
        /* Platform Box Styling */
        .platform-box {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 18px;
            padding: 25px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column; /* Changed to column to accommodate list */
            transition: 0.3s ease;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .platform-box:hover {
            border-color: #00fff0;
            background: rgba(255, 255, 255, 0.08);
            transform: translateX(5px);
        }
        
        .platform-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin-bottom: 15px;
        }

        .platform-meta {
            display: flex;
            align-items: center;
        }
        .platform-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-right: 20px;
        }
        .yt { background: rgba(255, 0, 0, 0.15); color: #ff0000; }
        .tt { background: #000; color: #fff; border: 1px solid #333; }
        .ig { background: linear-gradient(45deg, rgba(240, 148, 51, 0.2), rgba(188, 24, 136, 0.2)); color: #dc2743; }

        .info-text h4 { margin: 0; font-size: 1.15rem; font-weight: 700; }
        .info-text small { color: #aaa; font-size: 0.85rem; }
        
        .limit-counter {
            font-size: 0.75rem;
            color: #00fff0;
            margin-top: 5px;
            display: block;
        }

        /* Accounts List Styling */
        .accounts-list {
            width: 100%;
            margin-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 15px;
            max-height: 200px; /* Scroll if many accounts */
            overflow-y: auto;
        }
        /* Custom Scrollbar */
        .accounts-list::-webkit-scrollbar { width: 5px; }
        .accounts-list::-webkit-scrollbar-thumb { background: #00fff0; border-radius: 10px; }
        .accounts-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }

        .account-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0, 0, 0, 0.3);
            padding: 10px 15px;
            border-radius: 12px;
            margin-bottom: 8px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .account-left { display: flex; align-items: center; }
        .account-avatar {
            width: 30px; height: 30px; border-radius: 50%; margin-right: 10px; object-fit: cover;
        }
        .account-name { font-size: 0.9rem; color: #fff; font-weight: 600; }
        
        .btn-auth {
            border-radius: 12px;
            padding: 8px 16px;
            font-weight: 700;
            text-decoration: none !important;
            transition: 0.3s;
            font-size: 0.85rem;
            display: inline-block;
            white-space: nowrap;
        }
        .btn-connect {
            background: linear-gradient(135deg, #00fff0, #0066ff);
            color: #000;
            border: none;
        }
        .btn-connect:hover {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(0, 255, 240, 0.5);
            color: #000;
        }
        .btn-upgrade-alert {
            background: transparent;
            border: 1px solid #ff4757;
            color: #ff4757;
        }
        .btn-upgrade-alert:hover {
            background: #ff4757;
            color: #fff;
        }
        .disconnect-btn {
            color: #ff4757;
            background: rgba(255, 71, 87, 0.1);
            border: 1px solid rgba(255, 71, 87, 0.2);
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: 0.3s;
            text-decoration: none;
        }
        .disconnect-btn:hover {
            background: #ff4757;
            color: #fff;
        }

        @media (max-width: 600px) {
            .platform-header { flex-direction: column; text-align: center; }
            .platform-meta { flex-direction: column; margin-bottom: 15px; }
            .platform-icon { margin-right: 0; margin-bottom: 10px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="connect-container">
        <div class="header-title">
            <h2><i class="fa-solid fa-robot"></i> Content Automation</h2>
            <p class="text-muted">Manage your connected social accounts.</p>
            <div class="plan-badge">
                Current Plan: <strong><?php echo ucfirst($plan_type); ?></strong> 
                (Max <?php echo $limit_per_platform; ?> acc/platform)
            </div>
        </div>

        <div class="platform-box">
            <div class="platform-header">
                <div class="platform-meta">
                    <div class="platform-icon yt"><i class="fa-brands fa-youtube"></i></div>
                    <div class="info-text">
                        <h4>YouTube Shorts</h4>
                        <small>Publish videos directly.</small>
                        <span class="limit-counter">Connected: <?php echo $yt_count . ' / ' . $limit_per_platform; ?></span>
                    </div>
                </div>
                
                <div class="action-btn">
                    <?php if ($yt_count < $limit_per_platform): ?>
                        <a href="auth_youtube.php" class="btn-auth btn-connect">
                            <i class="fa-solid fa-plus"></i> Connect New
                        </a>
                    <?php else: ?>
                        <a href="plans.php" class="btn-auth btn-upgrade-alert">
                            <i class="fa-solid fa-lock"></i> Upgrade
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($yt_count > 0): ?>
            <div class="accounts-list">
                <?php foreach($yt_accounts as $acc): ?>
                    <div class="account-item">
                        <div class="account-left">
                            <img src="<?php echo !empty($acc['picture']) ? $acc['picture'] : 'https://cdn-icons-png.flaticon.com/512/1384/1384060.png'; ?>" class="account-avatar">
                            <span class="account-name">
                                <?php echo !empty($acc['channel_name']) ? htmlspecialchars($acc['channel_name']) : 'YouTube Channel'; ?>
                            </span>
                        </div>
                        <a href="disconnect.php?id=<?php echo (int) $acc['id']; ?>&platform=youtube&csrf=<?php echo rawurlencode($disconnect_csrf); ?>" class="disconnect-btn" onclick="return confirm('Are you sure you want to disconnect this channel?');">
                            <i class="fa-solid fa-link-slash"></i> Unlink
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="platform-box">
            <div class="platform-header">
                <div class="platform-meta">
                    <div class="platform-icon tt"><i class="fa-brands fa-tiktok"></i></div>
                    <div class="info-text">
                        <h4>TikTok</h4>
                        <small>Post to feed or drafts.</small>
                        <span class="limit-counter">Connected: <?php echo $tt_count . ' / ' . $limit_per_platform; ?></span>
                    </div>
                </div>

                <div class="action-btn">
                    <?php if ($tt_count < $limit_per_platform): ?>
                        <a href="auth_tiktok.php" class="btn-auth btn-connect">
                            <i class="fa-solid fa-plus"></i> Connect New
                        </a>
                    <?php else: ?>
                        <a href="plans.php" class="btn-auth btn-upgrade-alert">
                            <i class="fa-solid fa-lock"></i> Upgrade
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($tt_count > 0): ?>
            <div class="accounts-list">
                <?php foreach($tt_accounts as $acc): ?>
                    <div class="account-item">
                        <div class="account-left">
                            <img src="<?php echo !empty($acc['picture']) ? $acc['picture'] : 'https://cdn-icons-png.flaticon.com/512/3046/3046121.png'; ?>" class="account-avatar">
                            <span class="account-name">
                                <?php echo !empty($acc['channel_name']) ? htmlspecialchars($acc['channel_name']) : 'TikTok Account'; ?>
                            </span>
                        </div>
                        <a href="disconnect.php?id=<?php echo (int) $acc['id']; ?>&platform=tiktok&csrf=<?php echo rawurlencode($disconnect_csrf); ?>" class="disconnect-btn" onclick="return confirm('Disconnect this account?');">
                            <i class="fa-solid fa-link-slash"></i> Unlink
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="platform-box">
            <div class="platform-header">
                <div class="platform-meta">
                    <div class="platform-icon ig"><i class="fa-brands fa-instagram"></i></div>
                    <div class="info-text">
                        <h4>Instagram Reels</h4>
                        <small>Auto-share via Meta.</small>
                        <span class="limit-counter">Connected: <?php echo $ig_count . ' / ' . $limit_per_platform; ?></span>
                    </div>
                </div>

                <div class="action-btn">
                    <?php if ($ig_count < $limit_per_platform): ?>
                        <a href="auth_instagram.php" class="btn-auth btn-connect">
                            <i class="fa-solid fa-plus"></i> Connect New
                        </a>
                    <?php else: ?>
                        <a href="plans.php" class="btn-auth btn-upgrade-alert">
                            <i class="fa-solid fa-lock"></i> Upgrade
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ig_count > 0): ?>
            <div class="accounts-list">
                <?php foreach($ig_accounts as $acc): ?>
                    <div class="account-item">
                        <div class="account-left">
                            <img src="<?php echo !empty($acc['picture']) ? $acc['picture'] : 'https://cdn-icons-png.flaticon.com/512/2111/2111463.png'; ?>" class="account-avatar">
                            <span class="account-name">
                                <?php echo !empty($acc['channel_name']) ? htmlspecialchars($acc['channel_name']) : 'Instagram Account'; ?>
                            </span>
                        </div>
                        <a href="disconnect.php?id=<?php echo (int) $acc['id']; ?>&platform=instagram&csrf=<?php echo rawurlencode($disconnect_csrf); ?>" class="disconnect-btn" onclick="return confirm('Disconnect this account?');">
                            <i class="fa-solid fa-link-slash"></i> Unlink
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="text-center mt-5">
            <a href="home.php" class="back-link" style="color: #aaa; text-decoration: none;"><i class="fa-solid fa-chevron-left"></i> Return to Dashboard</a>
        </div>
    </div>
</div>

</body>
</html>
