<?php
require_once "connection.php";
session_start();

// التحقق من تسجيل الدخول
if (!isset($_SESSION['email'])) {
    header('location: login-user.php');
    exit();
}

$payment_csrf = oldora_csrf_token();
$payment_error = $_SESSION['payment_error'] ?? '';
unset($_SESSION['payment_error']);

$email = $_SESSION['email'];
$current_credits = 0;
$first_name = "User";

// جلب بيانات المستخدم والكريديت
$sql = "SELECT * FROM users WHERE email = ?";
$stmt = mysqli_prepare($con, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $current_credits = $row['credits'] ?? 0;
        if(isset($row['full_name'])){
            $parts = explode(' ', $row['full_name']);
            $first_name = isset($parts[0]) ? $parts[0] : 'User';
        }
    }
    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upgrade Plan | Oldora</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="https://i.postimg.cc/bv1QQwBc/1768055586557.png">

    <style>
        :root {
            --primary: #00fff0;
            --secondary: #ffd700;
            --dark-bg: #0a0a0f;
            --card-bg: rgba(255, 255, 255, 0.05);
            --accent: #9d4edd;
            --text-light: #f8f9fa;
        }
        
        body { 
            min-height: 100vh; 
            background: radial-gradient(circle at top, #0f2027, #000); 
            font-family: 'Cairo', sans-serif; 
            color: #fff; 
            overflow-x: hidden; 
        }

        /* --- Sidebar Styling --- */
        .sidebar-container { min-height: 100vh; border-right: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); transition: all 0.3s ease-in-out; }
        .nav-link { color: #ccc; font-size: 16px; padding: 15px 20px; border-radius: 12px; margin-bottom: 10px; transition: 0.3s; display: flex; align-items: center; }
        .nav-link i { margin-right: 15px; width: 25px; text-align: center; font-size: 1.2rem; }
        .nav-link:hover, .nav-link.active { background: rgba(0, 255, 240, 0.1); color: #00fff0; box-shadow: 0 0 15px rgba(0,255,240,0.2); text-decoration: none; }
        
        .text-neon { color: #00fff0; }

        @media (max-width: 991px) {
            .sidebar-container {
                position: fixed; top: 0; left: -100%; width: 280px; height: 100%;
                background: #0f2027; z-index: 9999; overflow-y: auto;
                box-shadow: 10px 0 20px rgba(0,0,0,0.5); display: block !important;
            }
            .sidebar-container.active { left: 0; }
            .sidebar-overlay {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.7); z-index: 9998; display: none;
            }
        }
        
        .mobile-nav { background: rgba(15, 32, 39, 0.95); padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); display: none; position: sticky; top: 0; z-index: 100; justify-content: space-between; align-items: center; }
        @media (max-width: 991px) { .mobile-nav { display: flex; } }

        /* --- Pricing Cards Styling --- */
        .header-title { text-align: center; margin-bottom: 3rem; margin-top: 1rem; }
        .header-title h2 { font-size: 2.5rem; font-weight: 700; color: #fff; text-shadow: 0 0 15px rgba(0,255,240,0.3); }
        
        .plan-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            transition: all 0.4s ease;
            height: 100%;
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        .plan-card:hover {
            transform: translateY(-10px);
            border-color: rgba(0, 255, 240, 0.5);
            box-shadow: 0 10px 40px rgba(0, 255, 240, 0.1);
        }
        
        .plan-card.featured {
            background: rgba(0, 255, 240, 0.05);
            border: 1px solid var(--primary);
            transform: scale(1.05);
            z-index: 2;
        }
        .plan-card.featured:hover { transform: scale(1.05) translateY(-10px); }
        
        .plan-badge {
            position: absolute; top: -15px; left: 50%; transform: translateX(-50%);
            background: linear-gradient(90deg, var(--primary), #0066ff);
            color: #000; padding: 5px 20px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 800; box-shadow: 0 0 15px var(--primary);
        }

        /* شارة التوفير */
        .save-badge {
            display: inline-block;
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid #28a745;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .plan-name { font-size: 1.4rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; text-align: center; }
        .price-container { text-align: center; margin: 1rem 0; color: #fff; }
        .price { font-size: 3rem; font-weight: 800; line-height: 1; }
        .currency { font-size: 1.5rem; vertical-align: top; }
        
        .plan-features { list-style: none; padding: 0; margin-bottom: 2rem; flex-grow: 1; }
        .plan-features li { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; color: #ccc; }
        .plan-features li i { margin-right: 10px; width: 20px; text-align: center; }
        
        .btn-upgrade {
            background: transparent; border: 2px solid var(--primary); color: var(--primary);
            border-radius: 50px; padding: 10px; font-weight: 700; width: 100%; transition: 0.3s;
            text-transform: uppercase; letter-spacing: 1px;
        }
        .btn-upgrade:hover { background: var(--primary); color: #000; box-shadow: 0 0 20px rgba(0,255,240,0.4); }
        
        .btn-featured { background: var(--primary); color: #000; border: none; }
        .btn-elite { border-color: var(--secondary); color: var(--secondary); }
        .btn-elite:hover { background: var(--secondary); color: #000; box-shadow: 0 0 20px rgba(255, 215, 0, 0.4); }

        .custom-input {
            background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2);
            color: #00fff0; font-size: 1.5rem; text-align: center; font-weight: bold;
            border-radius: 10px; height: 50px;
        }
        .custom-input:focus { background: rgba(0,0,0,0.5); border-color: #00fff0; box-shadow: none; color: #fff; }

    </style>
</head>
<body>

<?php if ($payment_error !== ''): ?>
<div class="alert alert-danger m-3 text-center" role="alert"><?php echo htmlspecialchars($payment_error); ?></div>
<?php endif; ?>

<div class="sidebar-overlay"></div>

<div class="mobile-nav">
    <button class="btn text-white p-0" id="openSidebarBtn" style="font-size: 1.5rem;">
        <i class="fa-solid fa-bars"></i>
    </button>
    <h4 class="m-0"><i class="fa-solid fa-crown text-neon"></i> PLANS</h4>
    <div class="text-white small">Credits: <span class="text-neon font-weight-bold"><?php echo $current_credits; ?></span></div>
</div>

<div class="container-fluid">
    <div class="row">
        
        <div class="col-lg-2 sidebar-container pt-4" id="mainSidebar">
            <div class="d-flex justify-content-between align-items-center d-lg-none mb-4 px-2">
                 <h3 class="m-0">OLDORA</h3>
                 <button class="btn text-white" id="closeSidebarBtn"><i class="fa-solid fa-times fa-2x"></i></button>
            </div>

            <div class="text-center mb-5 d-none d-lg-block"><h3><i class="fa-solid fa-robot"></i> OLDORA</h3></div>
            <nav class="nav flex-column">
                <a class="nav-link" href="profile.php"><i class="fa-solid fa-user-gear"></i> Settings</a>
                <a class="nav-link" href="home.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Create Video</a>
                <a class="nav-link" href="create-insta.php"><i class="fa-brands fa-instagram"></i> Create Insta Post</a>
                <a class="nav-link" href="automation.php"><i class="fa-solid fa-gears"></i> Automation</a>
                <a class="nav-link" href="analytics.php"><i class="fa-solid fa-chart-line"></i> Analytics</a>
                <a href="connect-platforms.php" class="nav-link"><i class="fa-solid fa-link"></i> Connected Accounts</a>
                <a class="nav-link active" href="planing.php"><i class="fa-solid fa-crown"></i> Your Plan</a>
                <a class="nav-link" href="referral.php"><i class="fa-solid fa-bullhorn"></i> Referral Program</a>
                <a class="nav-link" href="support.php"><i class="fa-solid fa-headset"></i> Contact Support</a>
                <a class="nav-link mt-5" href="logout-user.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </nav>
            
            <div class="text-center mt-auto pb-4 fixed-bottom col-lg-2 d-none d-lg-block" style="bottom:0; width:100%;">
                <hr style="border-color:rgba(255,255,255,0.1);">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="mr-2"><i class="fa-solid fa-user-circle fa-2x text-muted"></i></div>
                    <div class="text-left"><small class="text-muted d-block">User</small><strong class="text-neon"><?php echo htmlspecialchars($first_name); ?></strong></div>
                </div>
            </div>
        </div>

        <div class="col-lg-10 col-12 py-4 px-3 px-lg-4">
            
            <div class="header-title">
                <h2>Choose Your Power</h2>
                <p class="text-white-50">Standard Rate: <span class="text-white font-weight-bold">$0.70</span> / Credit</p>
                <div class="text-right d-block d-md-none text-neon font-weight-bold">
                    You have: <?php echo $current_credits; ?> Credits
                </div>
            </div>

            <div class="row align-items-center justify-content-center">
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="plan-card">
                        <div class="text-center"><span class="save-badge">SAVE 40%</span></div>
                        <div class="plan-name" style="color:#fff;">Basic</div>
                        <div class="price-container">
                            <span class="currency">$</span><span class="price">29</span>
                        </div>
                        <ul class="plan-features">
                            <li><i class="fas fa-bolt text-info"></i> <strong>70 Credits</strong></li>
                            <li><i class="fas fa-link text-info"></i> 5 Connecting accounts</li>
                            <li><i class="fas fa-video text-info"></i> HD Quality</li>
                        </ul>
                        <form action="process_payment.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($payment_csrf); ?>">
                            <input type="hidden" name="plan_type" value="basic">
                            <input type="hidden" name="price" value="29">
                            <button type="submit" class="btn-upgrade">Buy Basic</button>
                        </form>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="plan-card featured">
                        <div class="plan-badge">MOST POPULAR</div>
                        <div class="text-center mt-2"><span class="save-badge" style="background:rgba(0,255,240,0.1); border-color:#00fff0; color:#00fff0;">SAVE 58%</span></div>
                        <div class="plan-name" style="color:var(--primary);">Pro</div>
                        <div class="price-container">
                            <span class="currency">$</span><span class="price">59</span>
                        </div>
                        <ul class="plan-features">
                            <li><i class="fas fa-bolt text-primary"></i> <strong>200 Credits</strong></li>
                            <li><i class="fas fa-link text-primary"></i> 20 Connecting accounts</li>
                            <li><i class="fas fa-video text-primary"></i> 1080p Full HD</li>
                            <li><i class="fas fa-star text-primary"></i> Fast Support</li>
                        </ul>
                        <form action="process_payment.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($payment_csrf); ?>">
                            <input type="hidden" name="plan_type" value="pro">
                            <input type="hidden" name="price" value="59">
                            <button type="submit" class="btn-upgrade btn-featured">Buy Pro</button>
                        </form>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="plan-card elite">
                        <div class="text-center"><span class="save-badge" style="color:var(--secondary); border-color:var(--secondary);">SAVE 55%</span></div>
                        <div class="plan-name" style="color:var(--secondary);">Elite</div>
                        <div class="price-container">
                            <span class="currency">$</span><span class="price">159</span>
                        </div>
                        <ul class="plan-features">
                            <li><i class="fas fa-bolt text-warning"></i> <strong>500 Credits</strong></li>
                            <li><i class="fas fa-link text-warning"></i> 50 Accounts</li>
                            <li><i class="fas fa-film text-warning"></i> 4K Ultra HD</li>
                            <li><i class="fas fa-crown text-warning"></i> Priority Support</li>
                        </ul>
                        <form action="process_payment.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($payment_csrf); ?>">
                            <input type="hidden" name="plan_type" value="elite">
                            <input type="hidden" name="price" value="159">
                            <button type="submit" class="btn-upgrade btn-elite">Buy Elite</button>
                        </form>
                    </div>
                </div>

            </div>

            <hr style="border-color: rgba(255,255,255,0.1); margin: 3rem 0;">
            
            <div class="row justify-content-center">
                
                <div class="col-md-6 mb-4">
                    <div class="plan-card" style="border: 1px dashed rgba(0, 255, 240, 0.4);">
                        <div class="plan-header text-center mb-3">
                            <div class="plan-name" style="color:#00fff0;">Pay As You Want</div>
                            <p class="text-white-50 small mb-2">Flexible Plan - <strong class="text-white">$0.70 / Credit</strong></p>
                            
                            <div class="price-container my-2">
                                <span class="currency">$</span>
                                <span class="price" id="customPriceDisplay">0.70</span>
                            </div>
                        </div>
                        
                        <div class="form-group text-center">
                            <label class="text-white mb-2">Enter Amount of Credits:</label>
                            <input type="number" id="customCreditsInput" class="form-control custom-input mx-auto" style="max-width:200px;" value="1" min="1" step="1">
                        </div>
                        
                        <div class="mt-auto">
                            <form action="process_payment.php" method="POST" id="customPlanForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($payment_csrf); ?>">
                                <input type="hidden" name="plan_type" value="custom">
                                <input type="hidden" name="price" id="hiddenCustomPrice" value="0.70">
                                <button type="submit" class="btn-upgrade" style="border-style:dashed;">Buy Custom Amount</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-4">
                    <div class="plan-card elite">
                         <span class="badge badge-danger position-absolute" style="top:15px; right:15px; font-size:0.9rem; padding:8px 12px;">SAVE 64%</span>
                        <div class="plan-name text-center" style="color:var(--secondary);">Yearly Elite</div>
                        <div class="price-container">
                            <span class="currency">$</span><span class="price">999</span>
                        </div>
                        <ul class="plan-features">
                            <li><i class="fas fa-bolt text-success"></i> <strong>4000 Credits</strong></li>
                            <li><i class="fas fa-link text-success"></i> 150 Accounts</li>
                            <li><i class="fas fa-gem text-success"></i> VIP Support</li>
                        </ul>
                        <form action="process_payment.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($payment_csrf); ?>">
                            <input type="hidden" name="plan_type" value="elite_yearly">
                            <input type="hidden" name="price" value="999">
                            <button type="submit" class="btn-upgrade btn-elite">Buy Yearly</button>
                        </form>
                    </div>
                </div>

            </div>

            <div class="row mt-4">
                <div class="col-12 text-center">
                    <p class="text-muted small"><i class="fas fa-lock text-success mr-1"></i> Secure payment powered by Cryptomus</p>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // Sidebar logic
    $("#openSidebarBtn").click(function(){ $("#mainSidebar").addClass("active"); $(".sidebar-overlay").fadeIn(); });
    $("#closeSidebarBtn, .sidebar-overlay").click(function(){ $("#mainSidebar").removeClass("active"); $(".sidebar-overlay").fadeOut(); });

    // Custom Calc logic
    const pricePerCredit = 0.70;

    $("#customCreditsInput").on("input change keyup", function() {
        let credits = parseInt($(this).val());
        
        if (isNaN(credits) || credits < 1) {
            credits = 1;
        }

        let totalPrice = (credits * pricePerCredit).toFixed(2);
        $("#customPriceDisplay").text(totalPrice);
        $("#hiddenCustomPrice").val(totalPrice);
    });

    $("#customPlanForm").on("submit", function(e){
        let credits = parseInt($("#customCreditsInput").val());
        if (credits < 1) {
            e.preventDefault();
            alert("Please enter at least 1 credit.");
        }
    });
});
</script>
</body>
</html>
