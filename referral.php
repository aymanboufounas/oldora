<?php 
require_once "connection.php"; 
session_start();

// 1. التحقق من تسجيل الدخول
if(!isset($_SESSION['email'])){ 
    header('location: login-user.php'); 
    exit(); 
}

$email = $_SESSION['email'];
$user_data = [];

// 2. جلب بيانات المستخدم
$sql = "SELECT * FROM users WHERE email = ?";
$stmt = mysqli_prepare($con, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// === إعدادات الحد الأقصى (الجديد) ===
$max_referrals = 25; // الحد الأقصى المسموح به
$current_referrals = $user_data['referral_count'] ?? 0;
$is_limit_reached = ($current_referrals >= $max_referrals);

// حساب نسبة التقدم للشريط
$progress_percentage = ($current_referrals / $max_referrals) * 100;
if($progress_percentage > 100) $progress_percentage = 100;

// 3. تجهيز الرابط والكود
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$ref_code = $user_data['referral_code'] ?? 'USER'.rand(100,999); 
$my_referral_link = "$protocol://$domain/signup-user.php?ref=" . htmlspecialchars($ref_code);

// روابط المشاركة
$share_text = urlencode("Join Oldora and get free AI credits using my code: " . $ref_code);
$share_link = urlencode($my_referral_link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral | OLDORA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #00fff0;
            --secondary: #ffd700;
            --dark-bg: #0a0a0f;
            --glass-bg: rgba(22, 27, 34, 0.8);
            --border-color: rgba(255, 255, 255, 0.08);
        }

        body { 
            min-height: 100vh; 
            background: var(--dark-bg); 
            font-family: 'Cairo', sans-serif; 
            color: #fff; 
            overflow-x: hidden;
            background-image: radial-gradient(circle at 15% 50%, rgba(0, 255, 240, 0.08), transparent 25%),
                              radial-gradient(circle at 85% 30%, rgba(255, 215, 0, 0.05), transparent 25%);
        }

        /* Sidebar */
        .sidebar-container {
            min-height: 100vh;
            border-right: 1px solid var(--border-color);
            background: rgba(10, 10, 15, 0.6);
            backdrop-filter: blur(10px);
        }
        .nav-link { color: #aaa; margin-bottom: 5px; padding: 12px 15px; border-radius: 8px; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { background: rgba(0,255,240,0.1); color: var(--primary); }
        .nav-link i { width: 25px; margin-right: 10px; }

        /* Glass Cards */
        .glass-panel { 
            background: var(--glass-bg); 
            backdrop-filter: blur(16px); 
            border-radius: 20px; 
            padding: 30px; 
            border: 1px solid var(--border-color); 
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
            height: 100%;
        }

        /* === New Referral Box Design === */
        .referral-action-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.4);
            border-radius: 15px;
            padding: 30px;
            border: 1px dashed rgba(255,255,255,0.2);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .code-display {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: 3px;
            color: var(--primary);
            text-shadow: 0 0 20px rgba(0, 255, 240, 0.4);
            margin-bottom: 20px;
            font-family: monospace;
        }

        .btn-main-copy {
            background: linear-gradient(45deg, var(--primary), #00d2c6);
            border: none;
            color: #000;
            padding: 12px 40px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 15px rgba(0, 255, 240, 0.3);
            transition: all 0.3s ease;
            cursor: pointer;
            width: 100%;
            max-width: 300px;
        }

        .btn-main-copy:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 255, 240, 0.5);
            color: #000;
        }

        .btn-main-copy:active {
            transform: scale(0.98);
        }

        /* Disabled State for Limit Reached */
        .limit-reached-badge {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: #28a745;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            width: 100%;
        }

        /* Progress Bar */
        .progress-container {
            width: 100%;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            height: 10px;
            margin-top: 10px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            width: <?php echo $progress_percentage; ?>%;
            transition: width 0.5s ease;
        }

        /* === Social Buttons Grid (No Overlap) === */
        .social-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .social-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            color: #fff;
            text-decoration: none;
            transition: 0.3s;
            border: 1px solid transparent;
            background: rgba(255,255,255,0.05);
        }

        .social-btn i { margin-right: 8px; font-size: 1.2rem; }
        
        .social-btn.fb { border-color: #3b5998; color: #fff; }
        .social-btn.fb:hover { background: #3b5998; }
        
        .social-btn.tw { border-color: #1da1f2; color: #fff; }
        .social-btn.tw:hover { background: #1da1f2; }
        
        .social-btn.wa { border-color: #25d366; color: #fff; }
        .social-btn.wa:hover { background: #25d366; }

        /* Stats */
        .stat-card {
            background: rgba(255,255,255,0.03);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.05);
        }

        /* Hidden Input for JS */
        #hiddenUrl {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }

    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        
        <div class="col-lg-2 sidebar-container d-none d-lg-block pt-4">
            <div class="text-center mb-5">
                <h3 style="color:var(--primary); font-weight:800;">OLDORA</h3>
            </div>
            <nav class="nav flex-column">
                <a class="nav-link" href="home.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Create</a>
                <a class="nav-link active" href="referral.php"><i class="fa-solid fa-gift"></i> Referrals</a>
                <a class="nav-link" href="profile.php"><i class="fa-solid fa-gear"></i> Settings</a>
                <a class="nav-link mt-5 text-danger" href="logout-user.php"><i class="fa-solid fa-power-off"></i> Logout</a>
            </nav>
        </div>

        <div class="col-lg-10 py-4 px-lg-5">
            
            <div class="mb-4">
                <h3 class="font-weight-bold">Referral Program</h3>
                <p class="text-muted">Invite friends, earn credits (Max <?php echo $max_referrals; ?> users).</p>
            </div>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="glass-panel">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="text-white-50 m-0">Your Unique Referral Code</h5>
                            <span class="badge badge-dark p-2 border border-secondary">
                                Progress: <?php echo $current_referrals . ' / ' . $max_referrals; ?>
                            </span>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>0</span>
                                <span>Max Limit (<?php echo $max_referrals; ?>)</span>
                            </div>
                            <div class="progress-container">
                                <div class="progress-bar-fill"></div>
                            </div>
                        </div>
                        
                        <div class="referral-action-area">
                            <?php if ($is_limit_reached): ?>
                                <div class="limit-reached-badge">
                                    <h3><i class="fa-solid fa-trophy mb-2"></i></h3>
                                    <h4>Max Limit Reached!</h4>
                                    <p class="mb-0">You have earned the maximum 25 credits from referrals. Thank you for sharing!</p>
                                </div>
                            <?php else: ?>
                                <p class="small text-uppercase text-muted mb-1">Your Code</p>
                                <div class="code-display"><?php echo htmlspecialchars($ref_code); ?></div>
                                
                                <input type="text" value="<?php echo $my_referral_link; ?>" id="hiddenUrl" readonly>
                                
                                <button class="btn-main-copy" onclick="copyLink()">
                                    <i class="fa-solid fa-link mr-2"></i> Copy Full Link
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (!$is_limit_reached): ?>
                        <div class="mt-5">
                            <h6 class="mb-3 pl-1 border-left border-primary ml-1">&nbsp; Or Share Directly:</h6>
                            <div class="social-grid">
                                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $share_link; ?>" target="_blank" class="social-btn fb">
                                    <i class="fa-brands fa-facebook-f"></i> Facebook
                                </a>
                                <a href="https://twitter.com/intent/tweet?text=<?php echo $share_text; ?>&url=<?php echo $share_link; ?>" target="_blank" class="social-btn tw">
                                    <i class="fa-brands fa-twitter"></i> Twitter
                                </a>
                                <a href="https://api.whatsapp.com/send?text=<?php echo $share_text . ' ' . $share_link; ?>" target="_blank" class="social-btn wa">
                                    <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="stat-card" style="border-color: rgba(255, 215, 0, 0.3);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="color: var(--secondary);">Credits</span>
                            <i class="fa-solid fa-bolt" style="color: var(--secondary);"></i>
                        </div>
                        <h2 class="font-weight-bold mb-0 text-white"><?php echo $user_data['credits'] ?? 0; ?></h2>
                    </div>

                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Referrals Count</span>
                            <i class="fa-solid fa-users text-muted"></i>
                        </div>
                        <h2 class="font-weight-bold mb-0 text-white">
                            <?php echo $current_referrals; ?> <span style="font-size:1rem; color:#666;">/ <?php echo $max_referrals; ?></span>
                        </h2>
                    </div>

                    <div class="p-3 mt-4" style="background: rgba(0,255,240,0.05); border-radius: 12px; border-left: 3px solid var(--primary);">
                        <p class="mb-0 small text-light">
                            <i class="fa-solid fa-circle-info mr-1 text-info"></i>
                            <strong>How it works:</strong><br>
                            Share your link. When a friend signs up, you get 1 Credit.<br>
                            <span class="text-warning">Note: You can earn a maximum of <?php echo $max_referrals; ?> credits from referrals.</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyLink() {
    // نحدد العنصر المخفي الذي يحتوي على الرابط الطويل
    var copyText = document.getElementById("hiddenUrl");
    
    // عملية النسخ
    copyText.select();
    copyText.setSelectionRange(0, 99999); 
    navigator.clipboard.writeText(copyText.value);
    
    // تغيير شكل الزر لتأكيد النسخ
    var btn = document.querySelector('.btn-main-copy');
    var originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.style.background = '#00ff88'; // لون أخضر للتأكيد
    
    setTimeout(function(){
        btn.innerHTML = '<i class="fa-solid fa-link mr-2"></i> Copy Full Link';
        btn.style.background = 'linear-gradient(45deg, var(--primary), #00d2c6)'; // العودة للون الأصلي
    }, 2000);
}
</script>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>