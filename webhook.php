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

$raw = file_get_contents('php://input', false, null, 0, 65537);
$data = json_decode((string) $raw, true);
if (strlen((string) $raw) > 65536 || !is_array($data) || empty($data['sign'])) {
    oldora_log('payments', 'Rejected empty webhook', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$apiKey = oldora_env('CRYPTOMUS_API_KEY');
if (!oldora_payment_verify_webhook($data, $apiKey)) {
    oldora_log('payments', 'Rejected webhook signature', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}
unset($data['sign']);

$orderId = $data['order_id'] ?? '';
if (!is_string($orderId) || $orderId === '' || strlen($orderId) > 100) {
    http_response_code(400);
    echo 'Missing order';
    exit;
}

try {
    oldora_ensure_payment_schema($con);
    oldora_payment_record_provider($con, $orderId, $data);

    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    try {
        $invoice = oldora_payment_get_invoice($con, $orderId);
        oldora_payment_record_error($con, (int) $invoice['id'], $error->getMessage());
    } catch (Throwable $ignored) {
        // Unknown callbacks must not create local invoices.
    }
    oldora_log('payments', 'Webhook processing failed', ['order_id' => $orderId, 'error' => $error->getMessage()]);
    http_response_code(500);
    echo 'Retry';
}
