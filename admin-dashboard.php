<?php 
// =====================================================
//  📊 ADMIN DASHBOARD - FULL ANALYTICS EDITION
// =====================================================

require_once "connection.php"; 
session_start();

// 1. التحقق الصارم من الصلاحيات
if(!isset($_SESSION['email']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin'){ 
    header('location: secpanel111.php'); 
    exit(); 
}

// ==========================================
// ⚙️ منطق جلب البيانات (Backend Logic)
// ==========================================

// أ) إحصائيات عامة
// ----------------
// عدد المستخدمين الكلي
$res_total = mysqli_query($con, "SELECT COUNT(*) as total FROM users WHERE role='user'");
$row_total = mysqli_fetch_assoc($res_total);
$total_users = $row_total['total'];

// عدد المشتركين النشطين (المدفوع)
$res_active = mysqli_query($con, "SELECT COUNT(*) as active FROM users WHERE plan_type != 'free'");
$row_active = mysqli_fetch_assoc($res_active);
$total_active = $row_active['active'];

// ب) حساب الأرباح التقريبية (Revenue) - تم التحديث حسب الأسعار الجديدة
// -----------------------------------
// Basic = 29, Pro = 59, Elite = 159, Elite Yearly = 999
$sql_revenue = "SELECT SUM(
                    CASE 
                        WHEN plan_type = 'basic' OR plan_type = 'Basic' THEN 29
                        WHEN plan_type = 'pro' OR plan_type = 'Pro' THEN 59
                        WHEN plan_type = 'elite' OR plan_type = 'Elite' THEN 159
                        WHEN plan_type = 'elite_yearly' THEN 999
                        ELSE 0 
                    END
                ) as revenue 
                FROM users 
                WHERE plan_type != 'free'";

$res_rev = mysqli_query($con, $sql_revenue);
$row_rev = mysqli_fetch_assoc($res_rev);
$total_revenue = $row_rev['revenue'] ? $row_rev['revenue'] : 0;

// ج) بيانات الرسم البياني للنمو (آخر 30 يوم)
// ------------------------------------------
$dates = [];
$registrations = [];

// استعلام يجمع المستخدمين حسب تاريخ التسجيل
$sql_growth = "SELECT DATE(created_at) as reg_date, COUNT(*) as total 
               FROM users 
               WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
               GROUP BY DATE(created_at) 
               ORDER BY reg_date ASC";

$res_growth = mysqli_query($con, $sql_growth);

while($row = mysqli_fetch_assoc($res_growth)){
    // تنسيق التاريخ ليظهر كـ (Jan 01)
    $dates[] = date('M d', strtotime($row['reg_date']));
    $registrations[] = $row['total'];
}

// د) بيانات الدول (Top 5 Countries)
// ---------------------------------
$countries = [];
$country_counts = [];

$sql_country = "SELECT country, COUNT(*) as total 
                FROM users 
                GROUP BY country 
                ORDER BY total DESC 
                LIMIT 5";

$res_country = mysqli_query($con, $sql_country);

while($row = mysqli_fetch_assoc($res_country)){
    $countries[] = !empty($row['country']) ? $row['country'] : 'Unknown';
    $country_counts[] = $row['total'];
}

// هـ) عداد رسائل الدعم الفني (للشعار في القائمة)
// ---------------------------------------------
$pending_support = 0;
// تأكد من وجود جدول support_tickets لتجنب الخطأ
$check_table = mysqli_query($con, "SHOW TABLES LIKE 'support_tickets'");
if(mysqli_num_rows($check_table) > 0) {
    $res_tickets = mysqli_query($con, "SELECT COUNT(*) as pending FROM support_tickets WHERE status='open'");
    $row_tickets = mysqli_fetch_assoc($res_tickets);
    $pending_support = $row_tickets['pending'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | Analytics</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* --- General Dark Theme --- */
        body { 
            background: radial-gradient(circle at top, #1a0b2e, #000); 
            color: #fff; 
            min-height: 100vh; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        /* --- Sidebar --- */
        .sidebar { 
            min-height: 100vh; 
            background: rgba(0,0,0,0.6); 
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255,255,255,0.1); 
        }
        .sidebar h4 { color: #ff0050; font-weight: 800; letter-spacing: 1px; }
        .nav-link { color: #aaa; padding: 12px 15px; transition: 0.3s; border-radius: 8px; margin-bottom: 5px; font-weight: 500; }
        .nav-link i { margin-right: 10px; width: 20px; text-align: center; }
        .nav-link:hover, .nav-link.active { 
            color: #fff; 
            background: linear-gradient(90deg, rgba(255,0,80,0.2), transparent); 
            border-left: 3px solid #ff0050; 
            padding-left: 20px;
        }

        /* --- Stats Cards --- */
        .stat-card {
            background: linear-gradient(145deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.01) 100%);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s;
        }
        .stat-card:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.2); }
        .stat-card h3 { font-size: 2.2rem; font-weight: 700; margin: 5px 0 0 0; }
        .stat-card p { color: #888; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; margin: 0; }
        .stat-icon { position: absolute; right: 20px; top: 25px; font-size: 2.5rem; opacity: 0.15; }
        
        /* Card Colors */
        .card-users h3 { color: #00d2ff; } /* Cyan */
        .card-subs h3 { color: #ffce00; }  /* Yellow */
        .card-money h3 { color: #00ff87; } /* Green */

        /* --- Chart Containers --- */
        .chart-box {
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 20px;
            height: 100%;
        }
        .chart-title { color: #ddd; margin-bottom: 20px; font-weight: 600; font-size: 1.1rem; }

        /* --- Recent Table --- */
        .table-container { 
            background: rgba(0,0,0,0.3); 
            padding: 25px; 
            border-radius: 16px; 
            border: 1px solid rgba(255,255,255,0.05); 
            margin-top: 20px;
        }
        .table-dark { background: transparent; }
        .table-dark th { 
            border-top: none; 
            border-bottom: 1px solid rgba(255,255,255,0.1); 
            color: #888; 
            font-weight: 600; 
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .table-dark td { 
            vertical-align: middle; 
            border-top: 1px solid rgba(255,255,255,0.05); 
            color: #eee;
        }
        .user-avatar {
            width: 35px; height: 35px; background: #333; border-radius: 50%; 
            display: inline-flex; align-items: center; justify-content: center; margin-right: 10px;
            font-size: 0.8rem; color: #fff;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        
        <div class="col-md-2 sidebar p-4 d-none d-md-block">
            <h4 class="mb-5"><i class="fa-solid fa-shield-cat"></i> ADMIN UI</h4>
            <nav class="nav flex-column">
                <a class="nav-link active" href="admin-dashboard.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
                <a class="nav-link" href="admin-users.php"><i class="fa-solid fa-users"></i> Users & Plans</a>
                <a class="nav-link" href="admin-email.php"><i class="fa-solid fa-envelope"></i> Send Email</a>
                <a class="nav-link" href="admin-login-logs.php"><i class="fa-solid fa-list-ul"></i> Login Logs</a>
                
                <a class="nav-link d-flex justify-content-between align-items-center" href="admin-support.php">
                    <span><i class="fa-solid fa-headset"></i> Support</span>
                    <?php if($pending_support > 0): ?>
                        <span class="badge badge-danger rounded-circle"><?php echo $pending_support; ?></span>
                    <?php endif; ?>
                </a>

                <a class="nav-link mt-5 text-muted" href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
            </nav>
        </div>

        <div class="col-md-10 p-4 p-md-5">
            
            <div class="d-flex justify-content-between align-items-center mb-5">
                <div>
                    <h2 class="font-weight-bold">Dashboard Overview</h2>
                    <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($_SESSION['email']); ?></p>
                </div>
                <a href="index.php" target="_blank" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    Visit Website <i class="fa fa-external-link-alt ml-1"></i>
                </a>
            </div>

            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="stat-card card-users">
                        <i class="fa-solid fa-users stat-icon"></i>
                        <p>Total Users</p>
                        <h3><?php echo number_format($total_users); ?></h3>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="stat-card card-subs">
                        <i class="fa-solid fa-crown stat-icon"></i>
                        <p>Active Subscriptions</p>
                        <h3><?php echo number_format($total_active); ?></h3>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="stat-card card-money">
                        <i class="fa-solid fa-sack-dollar stat-icon"></i>
                        <p>Est. Revenue</p>
                        <h3>$<?php echo number_format($total_revenue, 2); ?></h3>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-lg-8 mb-3">
                    <div class="chart-box">
                        <h5 class="chart-title"><i class="fa-solid fa-arrow-trend-up mr-2 text-danger"></i>User Growth (Last 30 Days)</h5>
                        <div style="height: 300px; width: 100%;">
                            <canvas id="growthChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 mb-3">
                    <div class="chart-box">
                        <h5 class="chart-title"><i class="fa-solid fa-globe mr-2 text-info"></i>Top Locations</h5>
                        <div style="height: 300px; position: relative; display: flex; justify-content: center;">
                            <canvas id="countryChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0">Newly Registered Users</h5>
                    <a href="admin-users.php" class="btn btn-sm btn-dark border-secondary">View All</a>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Plan</th>
                                <th>Country</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sql_recent = "SELECT * FROM users WHERE role='user' ORDER BY id DESC LIMIT 5";
                            $res_recent = mysqli_query($con, $sql_recent);

                            if(mysqli_num_rows($res_recent) > 0){
                                while($row = mysqli_fetch_assoc($res_recent)){
                                    // Badge Color Logic
                                    $badgeClass = 'badge-secondary';
                                    $plan_type = strtolower($row['plan_type']);

                                    if($plan_type == 'basic') $badgeClass = 'badge-info';
                                    elseif($plan_type == 'pro') $badgeClass = 'badge-warning';
                                    elseif($plan_type == 'elite') $badgeClass = 'badge-primary'; // تغيير اللون لـ Elite
                                    elseif($plan_type == 'elite_yearly') $badgeClass = 'badge-success';
                                    
                                    // Initials for avatar
                                    $initial = strtoupper(substr($row['full_name'] ?? 'U', 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar"><?php echo $initial; ?></div>
                                        <span><?php echo htmlspecialchars($row['full_name']); ?></span>
                                    </div>
                                </td>
                                <td><?php echo $row['email']; ?></td>
                                <td><span class="badge <?php echo $badgeClass; ?> px-2 py-1"><?php echo ucfirst($row['plan_type']); ?></span></td>
                                <td><?php echo $row['country'] ? $row['country'] : '<span class="text-muted">-</span>'; ?></td>
                                <td class="text-muted small"><?php echo date('M j, H:i', strtotime($row['created_at'])); ?></td>
                            </tr>
                            <?php 
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center py-4 text-muted'>No users found yet.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // --- Chart.js Configuration ---
    
    // Global Defaults for Dark Mode
    Chart.defaults.color = '#888';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
    Chart.defaults.font.family = "'Segoe UI', sans-serif";

    // 1. Growth Chart Logic
    const ctxGrowth = document.getElementById('growthChart').getContext('2d');
    
    // Gradient Fill for Line Chart
    let gradient = ctxGrowth.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(255, 0, 80, 0.5)'); // Top color (Red)
    gradient.addColorStop(1, 'rgba(255, 0, 80, 0)');   // Bottom color (Transparent)

    new Chart(ctxGrowth, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [{
                label: 'New Registrations',
                data: <?php echo json_encode($registrations); ?>,
                borderColor: '#ff0050',
                backgroundColor: gradient,
                borderWidth: 2,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#ff0050',
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4 // Smooth curve
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    displayColors: false
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Country Chart Logic
    const ctxCountry = document.getElementById('countryChart').getContext('2d');
    new Chart(ctxCountry, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($countries); ?>,
            datasets: [{
                data: <?php echo json_encode($country_counts); ?>,
                backgroundColor: [
                    '#00d2ff', // Cyan
                    '#ff0050', // Red
                    '#00ff87', // Green
                    '#ffce00', // Yellow
                    '#9966ff'  // Purple
                ],
                borderWidth: 0,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'bottom', 
                    labels: { boxWidth: 12, padding: 20, color: '#aaa' } 
                }
            },
            cutout: '75%' // Thickness of the ring
        }
    });
</script>

</body>
</html>
