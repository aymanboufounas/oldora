<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/payment.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}
$invoiceId = (int) ($_GET['order'] ?? 0);
oldora_ensure_payment_schema($con);
$stmt = $con->prepare('SELECT order_id, payment_url FROM invoices WHERE id = ? AND user_email = ? LIMIT 1');
$stmt->bind_param('is', $invoiceId, $_SESSION['email']);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$invoice) {
    http_response_code(404);
    exit('Payment not found.');
}
if (!empty($invoice['payment_url'])) {
    header('Location: ' . $invoice['payment_url'], true, 303);
    exit;
}
header('Location: success.php?order_id=' . rawurlencode($invoice['order_id']), true, 302);
exit;

