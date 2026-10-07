<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/payment.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['email'])) {
    oldora_json(['ok' => false, 'message' => 'Login required.'], 401);
}
$email = (string) $_SESSION['email'];
session_write_close();
header('Cache-Control: no-store, private');

$orderId = $_GET['order_id'] ?? '';
if (!is_string($orderId) || ($orderId = trim($orderId)) === '' || strlen($orderId) > 100) {
    oldora_json(['ok' => false, 'message' => 'Invalid order.'], 422);
}

oldora_ensure_payment_schema($con);
$stmt = $con->prepare('SELECT * FROM invoices WHERE order_id = ? AND user_email = ? LIMIT 1');
$stmt->bind_param('ss', $orderId, $email);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    oldora_json(['ok' => false, 'message' => 'Payment not found.'], 404);
}

if ($invoice['status'] !== 'paid') {
    try {
        $invoice = oldora_reconcile_invoice($con, $invoice);
    } catch (Throwable $error) {
        oldora_log('payments', 'Status reconciliation deferred', ['order_id' => $orderId, 'error' => $error->getMessage()]);
        $invoice = oldora_payment_get_invoice($con, $orderId);
    }
}

$balance = $con->prepare('SELECT credits FROM users WHERE email = ? LIMIT 1');
$balance->bind_param('s', $email);
$balance->execute();
$balanceRow = $balance->get_result()->fetch_assoc();
$balance->close();

$status = (string) $invoice['status'];
$message = 'Waiting for blockchain confirmation.';
if ($status === 'paid') {
    $message = 'Payment confirmed. Your credits are available.';
} elseif (in_array($status, ['failed', 'create_failed'], true)) {
    $message = 'This payment was not completed.';
}

oldora_json([
    'ok' => true,
    'status' => $status,
    'provider_status' => $invoice['provider_status'] ?? null,
    'credits' => (int) ($invoice['credits'] ?? 0),
    'credits_added' => $status === 'paid' ? (int) ($invoice['credits'] ?? 0) : 0,
    'balance_credits' => (int) ($balanceRow['credits'] ?? 0),
    'last_checked_at' => $invoice['last_checked_at'] ?? null,
    'message' => $message
]);
