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
$msg = "";
$msg_type = ""; // success or danger
$csrf_token = oldora_csrf_token();

// 2. جلب بيانات المستخدم (Prepared Statement)
$sql = "SELECT * FROM users WHERE email = ?";
$stmt = mysqli_prepare($con, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// 3. معالجة إرسال التذكرة
if (isset($_POST['submit_ticket']) && oldora_verify_csrf($_POST['csrf_token'] ?? '')) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $image_path = null;
    $uploadOk = 1;

    // معالجة رفع الصورة (إذا وجدت)
    if (!empty($_FILES['attachment']['name'])) {
        $target_dir = "uploads/tickets/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        
        $file_extension = strtolower(pathinfo($_FILES["attachment"]["name"], PATHINFO_EXTENSION));
        // تغيير اسم الملف لمنع التكرار وللأمان
        $new_file_name = "ticket_" . time() . "_" . bin2hex(random_bytes(8)) . "." . $file_extension;
        $target_file = $target_dir . $new_file_name;

        if ((int) $_FILES['attachment']['size'] > 5 * 1024 * 1024) {
            $msg = "The image must be 5 MB or smaller.";
            $msg_type = "danger";
            $uploadOk = 0;
        }

        $check = getimagesize($_FILES["attachment"]["tmp_name"]);
        $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["attachment"]["tmp_name"]) : ($_FILES['attachment']['type'] ?? '');
        $allowed_mimes = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
        if($check === false || !isset($allowed_mimes[$mime]) || !in_array($file_extension, $allowed_mimes[$mime], true)) {
            $msg = "File is not an image.";
            $msg_type = "danger";
            $uploadOk = 0;
        }

        if ($uploadOk == 1) {
            if (move_uploaded_file($_FILES["attachment"]["tmp_name"], $target_file)) {
                $image_path = $target_file;
            } else {
                $msg = "Sorry, there was an error uploading your file.";
                $msg_type = "danger";
                $uploadOk = 0;
            }
        }
    }

    // الإدخال في قاعدة البيانات (فقط إذا لم يكن هناك خطأ في الرفع)
    if ($uploadOk == 1 && !empty($subject) && !empty($message)) {
        $sql_insert = "INSERT INTO support_tickets (user_email, subject, message, image_path, created_at, status) VALUES (?, ?, ?, ?, NOW(), 'open')";
        $stmt_insert = mysqli_prepare($con, $sql_insert);
        if ($stmt_insert) {
            mysqli_stmt_bind_param($stmt_insert, "ssss", $email, $subject, $message, $image_path);
            if (mysqli_stmt_execute($stmt_insert)) {
                $msg = "Ticket submitted successfully! We will reply soon.";
                $msg_type = "success";
            } else {
                $msg = "Database Error: " . mysqli_error($con);
                $msg_type = "danger";
            }
            mysqli_stmt_close($stmt_insert);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center | OLDORA</title>
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
            background-image: radial-gradient(circle at 80% 10%, rgba(0, 255, 240, 0.06), transparent 25%),
                              radial-gradient(circle at 20% 90%, rgba(255, 215, 0, 0.04), transparent 25%);
        }

        /* Sidebar & Layout */
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
            margin-bottom: 20px;
        }

        /* Form Inputs */
        .form-control { 
            background: rgba(0,0,0,0.3); 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 12px; 
            color: #fff; 
            height: 50px;
        }
        .form-control:focus { 
            background: rgba(0,0,0,0.5); 
            border-color: var(--primary); 
            box-shadow: 0 0 0 0.2rem rgba(0, 255, 240, 0.15); 
            color: #fff;
        }
        textarea.form-control { height: auto; }
        
        .btn-submit {
            background: linear-gradient(45deg, var(--primary), #00a8a8);
            color: #000;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            padding: 12px;
            width: 100%;
            transition: 0.3s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,255,240,0.3);
            color: #000;
        }

        /* Ticket Cards */
        .ticket-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            transition: 0.3s;
        }
        .ticket-card:hover {
            background: rgba(255,255,255,0.04);
            border-color: rgba(255,255,255,0.1);
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-open { background: rgba(255, 193, 7, 0.2); color: #ffc107; }
        .status-closed { background: rgba(40, 167, 69, 0.2); color: #28a745; }

        .admin-reply-box {
            margin-top: 15px;
            padding: 15px;
            background: rgba(0, 255, 240, 0.05);
            border-left: 3px solid var(--primary);
            border-radius: 0 10px 10px 0;
        }

        /* File Input Styling */
        .custom-file-label {
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
            color: #aaa;
            border-radius: 12px;
        }
        .custom-file-label::after {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-radius: 0 12px 12px 0;
        }

        /* Custom Scrollbar for History */
        .history-container {
            max-height: 650px;
            overflow-y: auto;
            padding-right: 5px;
        }
        .history-container::-webkit-scrollbar { width: 6px; }
        .history-container::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
        .history-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
        .history-container::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

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
                <a class="nav-link" href="referral.php"><i class="fa-solid fa-gift"></i> Referrals</a>
                <a class="nav-link active" href="support.php"><i class="fa-solid fa-headset"></i> Support</a>
                <a class="nav-link" href="profile.php"><i class="fa-solid fa-gear"></i> Settings</a>
                <a class="nav-link mt-5 text-danger" href="logout-user.php"><i class="fa-solid fa-power-off"></i> Logout</a>
            </nav>
            <div class="text-center mt-auto pb-4 fixed-bottom col-lg-2 d-none d-lg-block">
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.03);">
                    <small class="text-muted d-block">Logged in as</small>
                    <strong style="color: var(--primary);"><?php echo htmlspecialchars($user_data['full_name'] ?? 'User'); ?></strong>
                </div>
            </div>
        </div>

        <div class="col-lg-10 py-4 px-lg-5">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="font-weight-bold">Support Center</h3>
                    <p class="text-muted">We are here to help you.</p>
                </div>
                <div class="glass-panel py-2 px-4 mb-0 d-flex align-items-center" style="border-radius:50px; border-color: var(--primary);">
                    <span class="mr-2 text-white-50">Credits:</span>
                    <h4 class="mb-0 mx-2" style="color: var(--primary);"><?php echo $user_data['credits'] ?? 0; ?></h4> 
                    <i class="fa-solid fa-bolt text-warning"></i>
                </div>
            </div>

            <div class="row">
                
                <div class="col-lg-5 mb-4">
                    <div class="glass-panel h-100">
                        <h5 class="mb-4 text-white"><i class="fa-solid fa-pen-to-square mr-2 text-primary"></i>New Ticket</h5>
                        
                        <?php if(!empty($msg)): ?>
                            <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                                <?php echo $msg; ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <form action="" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="form-group">
                                <label class="text-white-50 small">Subject</label>
                                <input type="text" name="subject" class="form-control" required placeholder="Billing, Bug, Feature request...">
                            </div>
                            <div class="form-group">
                                <label class="text-white-50 small">Message</label>
                                <textarea name="message" class="form-control" rows="6" required placeholder="Describe your issue detailedly..."></textarea>
                            </div>
                            <div class="form-group">
                                <label class="text-white-50 small">Screenshot (Optional)</label>
                                <div class="custom-file">
                                    <input type="file" name="attachment" class="custom-file-input" id="customFile">
                                    <label class="custom-file-label" for="customFile">Choose file</label>
                                </div>
                            </div>
                            <button type="submit" name="submit_ticket" class="btn-submit mt-2">
                                Submit Ticket <i class="fa-solid fa-paper-plane ml-2"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="glass-panel h-100">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="text-white mb-0"><i class="fa-solid fa-clock-rotate-left mr-2 text-warning"></i>History</h5>
                            <span class="badge badge-secondary p-2">Recent Tickets</span>
                        </div>
                        
                        <div class="history-container">
                            <?php
                            $sql_get = "SELECT * FROM support_tickets WHERE user_email = ? ORDER BY created_at DESC";
                            $stmt_get = mysqli_prepare($con, $sql_get);
                            if ($stmt_get) {
                                mysqli_stmt_bind_param($stmt_get, "s", $email);
                                mysqli_stmt_execute($stmt_get);
                                $res_get = mysqli_stmt_get_result($stmt_get);

                                if (mysqli_num_rows($res_get) > 0) {
                                    while ($row = mysqli_fetch_assoc($res_get)) {
                                        $is_open = ($row['status'] == 'open');
                                        $status_text = $is_open ? 'Pending' : 'Solved';
                                        $status_class = $is_open ? 'status-open' : 'status-closed';
                                        $icon = $is_open ? 'fa-hourglass-half' : 'fa-check';
                            ?>
                                <div class="ticket-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="text-white font-weight-bold mb-0">
                                            <?php echo htmlspecialchars($row['subject']); ?>
                                        </h6>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fa-solid <?php echo $icon; ?> mr-1"></i> <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                    
                                    <p class="text-white-50 small mb-2">
                                        <i class="far fa-calendar-alt mr-1"></i> <?php echo date('M d, Y • h:i A', strtotime($row['created_at'])); ?>
                                    </p>
                                    
                                    <div class="bg-dark p-3 rounded mb-2" style="background: rgba(0,0,0,0.3) !important;">
                                        <p class="mb-0 text-light small" style="white-space: pre-line;"><?php echo htmlspecialchars($row['message']); ?></p>
                                    </div>

                                    <?php if (!empty($row['image_path'])): ?>
                                        <a href="<?php echo $row['image_path']; ?>" target="_blank" class="btn btn-sm btn-outline-light mb-2 border-0 bg-dark">
                                            <i class="fa-solid fa-paperclip mr-1"></i> View Attachment
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($row['admin_reply'])): ?>
                                        <div class="admin-reply-box">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fa-solid fa-user-shield text-primary mr-2"></i>
                                                <strong class="text-white small">Support Team</strong>
                                            </div>
                                            <p class="mb-0 text-white-50 small" style="white-space: pre-line;"><?php echo htmlspecialchars($row['admin_reply']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php 
                                    }
                                } else {
                                    echo '<div class="text-center py-5 text-muted">
                                            <i class="fa-regular fa-folder-open fa-3x mb-3 opacity-50"></i>
                                            <p>No tickets yet.</p>
                                          </div>';
                                }
                                mysqli_stmt_close($stmt_get);
                            }
                            ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // تحديث اسم الملف عند الاختيار
    $(".custom-file-input").on("change", function() {
        var fileName = $(this).val().split("\\").pop();
        $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
    });
</script>

</body>
</html>
