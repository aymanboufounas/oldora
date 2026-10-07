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

require_once __DIR__ . '/includes/admin_metrics.php';
if (!oldora_admin_authorized($con, (string) $_SESSION['email'])) {
    http_response_code(403);
    exit('Administrator access required.');
}
$adminData = oldora_admin_metrics($con);
$metrics = $adminData['summary'];
$total_users = $metrics['total_users'];
$total_active = $metrics['paid_plan_users'];
$total_revenue = $metrics['revenue_usd'];
$recentPayments = $adminData['recent_payments'];

// ج) بيانات الرسم البياني للنمو (آخر 30 يوم)
// ------------------------------------------
$dates = [];
$registrations = [];

// استعلام يجمع المستخدمين حسب تاريخ التسجيل
$sql_growth = "SELECT DATE(created_at) as reg_date, COUNT(*) as total
               FROM users
               WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
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

$countryExpression = oldora_db_has_column($con, 'users', 'country') ? "COALESCE(NULLIF(country, ''), 'Unknown')" : "'Unknown'";
$sql_country = "SELECT {$countryExpression} AS country, COUNT(*) as total
                FROM users
                WHERE role = 'user'
                GROUP BY {$countryExpression}
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
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-4.5.2.min.css">
    <script src="assets/vendor/chartjs/chart.umd.js"></script>

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
    <link rel="icon" href="assets/oldora-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/icons/css/all.min.css">
    <link rel="stylesheet" href="assets/admin-dashboard.css?v=20261006-1">
</head>
<body class="oldora-admin">
<nav class="admin-mobile-nav" aria-label="Admin navigation"><a href="admin-dashboard.php">Overview</a><a href="admin-users.php">Users</a><a href="admin-support.php">Support</a><a href="logout.php">Logout</a></nav>

<div class="container-fluid">
    <div class="row">

        <div class="col-md-2 sidebar p-4 d-none d-md-block">
            <h4 class="mb-5"><i class="fa-solid fa-shield-halved"></i> ADMIN UI</h4>
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
                        <h3><span data-admin-metric="total_users"><?php echo number_format($total_users); ?></span></h3>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="stat-card card-subs">
                        <i class="fa-solid fa-crown stat-icon"></i>
                        <p>Paid-plan users</p>
                        <h3><span data-admin-metric="paid_plan_users"><?php echo number_format($total_active); ?></span></h3>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="stat-card card-money">
                        <i class="fa-solid fa-sack-dollar stat-icon"></i>
                        <p>Confirmed revenue · USD</p>
                        <h3>$<span data-admin-metric="revenue_usd"><?php echo number_format((float) $total_revenue, 2); ?></span></h3><small>From paid invoices, including credit top-ups</small>
                    </div>
                </div>
            </div>

            <section class="admin-health-grid" aria-label="Operational overview">
                <?php foreach (['pending_invoices' => 'Payments awaiting confirmation', 'available_credits' => 'User credit balances', 'active_generations' => 'Videos & images processing', 'posts_need_attention' => 'Posts needing attention'] as $key => $label): ?>
                    <article><span><?php echo $label; ?></span><strong data-admin-metric="<?php echo $key; ?>"><?php echo number_format($metrics[$key]); ?></strong></article>
                <?php endforeach; ?>
            </section>
            <section class="table-container admin-payments" aria-labelledby="payment-heading">
                <div class="admin-section-heading"><div><h5 id="payment-heading">Recent payments</h5><p>Confirmed invoices update revenue and the user's credit balance.</p></div><div><span id="adminFreshness" role="status">Updates every 30 seconds</span><p id="adminWorkerState">Automation worker: <?php echo htmlspecialchars(str_replace('_', ' ', $adminData['worker']['state']), ENT_QUOTES, 'UTF-8'); ?></p></div></div>
                <div class="table-responsive"><table class="table table-dark table-hover"><thead><tr><th>Order / account</th><th>Plan</th><th>Amount</th><th>Credits</th><th>Status</th><th>Provider / details</th></tr></thead>
                <tbody id="adminPaymentRows">
                    <?php if (!$recentPayments): ?><tr><td colspan="6" class="admin-empty">No invoices yet. Confirmed payments will appear here.</td></tr><?php endif; ?>
                    <?php foreach ($recentPayments as $invoice): ?>
                    <tr><td><strong><?php echo htmlspecialchars($invoice['order_id'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars($invoice['user_email'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                        <td><?php echo htmlspecialchars(ucfirst($invoice['plan_name']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>$<?php echo number_format((float) $invoice['amount_usd'], 2); ?></td><td><?php echo number_format((int) $invoice['credits']); ?></td>
                        <td><span class="admin-status" data-state="<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $invoice['status'])), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><span><?php echo htmlspecialchars($invoice['provider_status'] ?? 'Awaiting provider', ENT_QUOTES, 'UTF-8'); ?></span><?php if ($invoice['last_error']): ?><small class="admin-error"><?php echo htmlspecialchars($invoice['last_error'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </section>

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
                                        <div class="user-avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <span><?php echo htmlspecialchars($row['full_name']); ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge <?php echo $badgeClass; ?> px-2 py-1"><?php echo htmlspecialchars(ucfirst($row['plan_type']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($row['country'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td>
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

<script src="assets/vendor/jquery/jquery-3.5.1.min.js"></script>
<script src="assets/vendor/bootstrap/bootstrap-4.5.2.bundle.min.js"></script>

<script>
    // --- Chart.js Configuration ---

    // Global Defaults for Dark Mode
    if (window.Chart) {
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
            labels: <?php echo json_encode($countries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
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
    } else {
        document.querySelectorAll('.chart-box canvas').forEach(function (canvas) { var note = document.createElement('p'); note.className = 'text-muted'; note.textContent = 'Charts are unavailable. Payment totals and tables remain up to date.'; canvas.replaceWith(note); });
    }
</script>

<script src="assets/admin-dashboard.js?v=20261006-1"></script>
</body>
</html>
