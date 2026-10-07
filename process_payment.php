<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/payment.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planing.php');
    exit;
}

if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}

if (!oldora_verify_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['payment_error'] = 'Your session expired. Please try the payment again.';
    header('Location: planing.php');
    exit;
}

$planValue = $_POST['plan_type'] ?? '';
$plan = is_string($planValue) ? strtolower(trim($planValue)) : '';
try {
    $quote = oldora_payment_quote($plan, $_POST['price'] ?? '');
    $amount = $quote['amount'];
    $credits = $quote['credits'];
} catch (RuntimeException $error) {
    $_SESSION['payment_error'] = $error->getMessage();
    header('Location: planing.php');
    exit;
}

$userStmt = $con->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $_SESSION['email']);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: login-user.php');
    exit;
}

oldora_ensure_payment_schema($con);

$orderId = 'OLD-' . gmdate('ymdHis') . '-' . bin2hex(random_bytes(5));
$status = 'creating';
$userId = (int) $user['id'];
$email = (string) $user['email'];
$insert = $con->prepare('INSERT INTO invoices (user_id, user_email, plan_name, credits, amount_usd, order_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insert->bind_param('ississs', $userId, $email, $plan, $credits, $amount, $orderId, $status);
$insert->execute();
$invoiceId = $insert->insert_id;
$insert->close();
$_SESSION['last_payment_order'] = $orderId;
// Provider latency must not hold the customer's session lock or hide fresh balances.
session_write_close();

try {
    $baseUrl = oldora_base_url();
    $request = [
        'amount' => $amount,
        'currency' => 'USD',
        'order_id' => $orderId,
        'url_return' => $baseUrl . '/success.php?order_id=' . rawurlencode($orderId),
        'url_success' => $baseUrl . '/success.php?order_id=' . rawurlencode($orderId),
        'url_callback' => $baseUrl . '/webhook.php',
        'is_payment_multiple' => false,
        'lifetime' => 3600,
        'to_currency' => 'USDT',
        'additional_data' => json_encode(['user_id' => $userId, 'credits' => $credits])
    ];

    $response = oldora_cryptomus_request('/v1/payment', $request);
    $provider = $response['result'] ?? [];
    if (!is_array($provider) || empty($provider['url']) || !is_string($provider['url']) ||
        filter_var($provider['url'], FILTER_VALIDATE_URL) === false || parse_url($provider['url'], PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Payment link was not returned.');
    }
    oldora_payment_validate_provider(['order_id' => $orderId, 'amount_usd' => $amount], $provider);

    $providerUuid = (string) ($provider['uuid'] ?? '');
    $paymentUrl = (string) $provider['url'];
    $providerStatus = (string) ($provider['payment_status'] ?? $provider['status'] ?? 'check');
    $payload = json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $update = $con->prepare("UPDATE invoices SET status = 'pending', provider_uuid = ?, payment_url = ?, provider_status = ?, provider_payload = ?, next_check_at = UTC_TIMESTAMP() WHERE id = ? AND status <> 'paid'");
    $update->bind_param('ssssi', $providerUuid, $paymentUrl, $providerStatus, $payload, $invoiceId);
    $update->execute();
    $update->close();

    if (in_array($providerStatus, ['paid', 'paid_over'], true)) {
        oldora_apply_paid_invoice($con, $orderId, $provider);
        header('Location: success.php?order_id=' . rawurlencode($orderId), true, 303);
        exit;
    }
    header('Location: ' . $paymentUrl, true, 303);
    exit;
} catch (Throwable $error) {
    $safeError = function_exists('mb_substr') ? mb_substr($error->getMessage(), 0, 500, 'UTF-8') : substr($error->getMessage(), 0, 500);
    $failed = $con->prepare("UPDATE invoices SET status = 'create_failed', last_error = ?, next_check_at = UTC_TIMESTAMP() WHERE id = ? AND status <> 'paid'");
    $failed->bind_param('si', $safeError, $invoiceId);
    $failed->execute();
    $failed->close();
    oldora_log('payments', 'Invoice creation failed', ['order_id' => $orderId, 'error' => $safeError]);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['payment_error'] = 'Could not create the payment. Please try again or contact support.';
    header('Location: planing.php');
    exit;
}
