<?php 
require_once "connection.php"; 
session_start();

if(!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin'){ 
    header('location: secpanel111.php'); 
    exit(); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Logs | Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: #000; color: #fff; min-height: 100vh; font-family: 'Segoe UI', sans-serif; }
        .table-container { background: #111; padding: 20px; border-radius: 10px; border: 1px solid #333; margin-top: 30px; }
        .table-dark { background-color: #111; }
        .table-dark th { color: #ff003c; border-top: none; }
        .table-dark td { border-color: #333; vertical-align: middle; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fa-solid fa-shield-halved text-danger"></i> Security Logs</h3>
        <a href="admin-dashboard.php" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
    </div>

    <div class="table-container">
        <table class="table table-dark table-hover">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Email</th>
                    <th>IP</th>
                    <th>Country</th>
                    <th>Status</th>
                    <th>Device</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sql = "SELECT * FROM login_logs ORDER BY id DESC LIMIT 50";
                $res = mysqli_query($con, $sql);
                if(mysqli_num_rows($res) > 0){
                    while($row = mysqli_fetch_assoc($res)){
                        $status_color = ($row['status'] == 'Success') ? 'text-success' : 'text-danger';
                        $flag = ($row['country'] != 'Unknown') ? "($row[country])" : "";
                ?>
                <tr>
                    <td><?php echo date('d M Y, H:i', strtotime($row['attempt_time'])); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td>
                        <?php echo $row['ip_address']; ?>
                        <a href="https://whatismyipaddress.com/ip/<?php echo $row['ip_address']; ?>" target="_blank" class="text-muted ml-1"><i class="fa fa-external-link-alt small"></i></a>
                    </td>
                    <td><?php echo $row['country']; ?></td>
                    <td class="<?php echo $status_color; ?> font-weight-bold"><?php echo $row['status']; ?></td>
                    <td class="small text-muted"><?php echo substr($row['device_info'], 0, 40); ?>...</td>
                </tr>
                <?php 
                    }
                } else {
                    echo "<tr><td colspan='6' class='text-center text-muted'>No logs found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
