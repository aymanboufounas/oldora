<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/payment.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'POST required';
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data) || empty($data['sign'])) {
    oldora_log('payments', 'Rejected empty webhook', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$receivedSign = (string) $data['sign'];
unset($data['sign']);
$apiKey = oldora_env('CRYPTOMUS_API_KEY');
$expectedSign = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $apiKey);

if ($apiKey === '' || !hash_equals($expectedSign, $receivedSign)) {
    oldora_log('payments', 'Rejected webhook signature', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$orderId = (string) ($data['order_id'] ?? '');
$status = (string) ($data['status'] ?? 'unknown');
if ($orderId === '') {
    http_response_code(400);
    echo 'Missing order';
    exit;
}

try {
    if (in_array($status, ['paid', 'paid_over'], true)) {
        oldora_apply_paid_invoice($con, $orderId, $data);
    } else {
        oldora_ensure_payment_schema($con);
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $localStatus = in_array($status, ['fail', 'cancel', 'system_fail'], true) ? 'failed' : 'pending';
        $stmt = $con->prepare("UPDATE invoices SET provider_status = ?, provider_payload = ?, status = CASE WHEN status = 'paid' THEN status ELSE ? END WHERE order_id = ?");
        $stmt->bind_param('ssss', $status, $payload, $localStatus, $orderId);
        $stmt->execute();
        $stmt->close();
    }

    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    oldora_log('payments', 'Webhook processing failed', ['order_id' => $orderId, 'error' => $error->getMessage()]);
    http_response_code(500);
    echo 'Retry';
}

