<?php 
require_once "connection.php"; 
session_start();

// التحقق من صلاحيات الأدمن
if(!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin'){ 
    header('location: secpanel111.php'); 
    exit(); 
}

// =========================================================
// (1) منطق تحديث بيانات المستخدم (تغيير الخطة + الحظر)
// =========================================================
$msg = "";
$csrf_token = oldora_csrf_token();
if(isset($_POST['update_user_btn']) && oldora_verify_csrf($_POST['csrf_token'] ?? '')){
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $plan_type = strtolower((string) ($_POST['plan_type'] ?? 'free'));
    $status = strtolower((string) ($_POST['status'] ?? 'verified'));
    $allowed_plans = ['free', 'basic', 'pro', 'elite', 'elite_yearly'];
    $allowed_statuses = ['verified', 'banned'];
    if (!in_array($plan_type, $allowed_plans, true) || !in_array($status, $allowed_statuses, true)) {
        $msg = "<div class='alert alert-danger'>Invalid user settings.</div>";
    } else {
        $update = $con->prepare('UPDATE users SET plan_type = ?, status = ? WHERE id = ?');
        $update->bind_param('ssi', $plan_type, $status, $user_id);
        $run_update = $update->execute();
        $update->close();
        $msg = $run_update ? "<div class='alert alert-success'>User updated successfully.</div>" : "<div class='alert alert-danger'>Error updating user.</div>";
    }
}

// =========================================================
// (2) منطق حذف المستخدم
// =========================================================
if(isset($_POST['delete_user']) && oldora_verify_csrf($_POST['csrf_token'] ?? '')){
    $id = (int) $_POST['delete_user'];
    $delete = $con->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'");
    $delete->bind_param('i', $id);
    $delete->execute();
    $delete->close();
    header("location: admin-users.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users | Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: radial-gradient(circle at top, #2e0b16, #000); color: #fff; min-height: 100vh; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar Styles */
        .sidebar { min-height: 100vh; background: rgba(0,0,0,0.4); border-right: 1px solid #333; }
        .sidebar h4 { color: #ff003c; font-weight: bold; letter-spacing: 1px; }
        .nav-link { color: #ccc; padding: 12px; transition: 0.3s; border-radius: 5px; margin-bottom: 5px; display: flex; align-items: center; }
        .nav-link i { margin-right: 10px; width: 25px; text-align: center; }
        .nav-link:hover, .nav-link.active { color: #fff; background: rgba(255,0,60,0.2); border-left: 4px solid #ff003c; text-decoration: none; }

        /* Table & Modal Styles */
        .table-dark { background: rgba(255,255,255,0.05); }
        .table-dark th { color: #ff003c; border-top: none; }
        .modal-content { background: #1a1a1a; border: 1px solid #444; color: #fff; }
        .form-control { background: #2a2a2a; border: 1px solid #444; color: #fff; }
        .form-control:focus { background: #333; color: #fff; border-color: #ff003c; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        
        <div class="col-md-2 sidebar p-4 d-none d-md-block">
            <h4 class="mb-5"><i class="fa-solid fa-robot"></i> AI ADMIN</h4>
            <nav class="nav flex-column">
                <a class="nav-link" href="admin-dashboard.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
                <a class="nav-link active" href="admin-users.php"><i class="fa-solid fa-users"></i> Users & Plans</a>
                <a class="nav-link" href="admin-email.php"><i class="fa-solid fa-envelope"></i> Send Email</a>
                <a class="nav-link mt-5 text-muted" href="logout-user.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
            </nav>
        </div>

        <div class="col-md-10 p-5">
            <h2 class="mb-4">User Management</h2>
            <?php echo $msg; ?>

            <div class="table-responsive">
                <table class="table table-dark table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Current Plan</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sql = "SELECT * FROM users WHERE role='user' ORDER BY id DESC";
                        $res = mysqli_query($con, $sql);
                        while($row = mysqli_fetch_assoc($res)){
                            // تحديد لون الحالة
                            $status_badge = ($row['status'] == 'banned') ? 'badge-danger' : 'badge-success';
                            $plan_color = ($row['plan_type'] == 'free') ? 'text-secondary' : 'text-warning font-weight-bold';
                        ?>
                        <tr>
                            <td>#<?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td class="<?php echo $plan_color; ?>"><?php echo strtoupper($row['plan_type']); ?></td>
                            <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editModal<?php echo $row['id']; ?>">
                                    <i class="fa fa-edit"></i> Edit
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete user?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <button type="submit" name="delete_user" value="<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>

                        <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST" action="admin-users.php">
                                        <div class="modal-header border-bottom-0">
                                            <h5 class="modal-title">Edit User: <?php echo htmlspecialchars($row['full_name']); ?></h5>
                                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $row['id']; ?>">
                                            
                                            <div class="form-group">
                                                <label>Subscription Plan</label>
                                                <select name="plan_type" class="form-control">
                                                    <option value="free" <?php if($row['plan_type']=='free') echo 'selected'; ?>>Free</option>
                                                    <option value="basic" <?php if(strtolower($row['plan_type'])=='basic') echo 'selected'; ?>>Basic</option>
                                                    <option value="pro" <?php if(strtolower($row['plan_type'])=='pro') echo 'selected'; ?>>Pro</option>
                                                    <option value="elite" <?php if(strtolower($row['plan_type'])=='elite') echo 'selected'; ?>>Elite</option>
                                                    <option value="elite_yearly" <?php if(strtolower($row['plan_type'])=='elite_yearly') echo 'selected'; ?>>Elite Yearly</option>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label>Account Status</label>
                                                <select name="status" class="form-control">
                                                    <option value="verified" <?php if($row['status']!='banned') echo 'selected'; ?>>Active (Verified)</option>
                                                    <option value="banned" <?php if($row['status']=='banned') echo 'selected'; ?>>🚫 Banned (Suspend)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-top-0">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                            <button type="submit" name="update_user_btn" class="btn btn-success">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
