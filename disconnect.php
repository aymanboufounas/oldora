<?php
require_once __DIR__ . '/connection.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}
if (!oldora_verify_csrf($_GET['csrf'] ?? '')) {
    http_response_code(403);
    exit('Invalid request.');
}
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $con->prepare('DELETE FROM user_tokens WHERE id = ? AND user_email = ?');
    $stmt->bind_param('is', $id, $_SESSION['email']);
    $stmt->execute();
    $stmt->close();
}
header('Location: connect-platforms.php?status=disconnected');
exit;

