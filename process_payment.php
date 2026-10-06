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

$plans = [
    'basic' => ['amount' => '29.00', 'credits' => 70],
    'pro' => ['amount' => '59.00', 'credits' => 200],
    'elite' => ['amount' => '159.00', 'credits' => 500],
    'elite_yearly' => ['amount' => '999.00', 'credits' => 4000]
];

$plan = strtolower(trim((string) ($_POST['plan_type'] ?? '')));
if (isset($plans[$plan])) {
    $amount = $plans[$plan]['amount'];
    $credits = $plans[$plan]['credits'];
} elseif ($plan === 'custom') {
    $custom = round((float) ($_POST['price'] ?? 0), 2);
    if ($custom < 0.70 || $custom > 10000) {
        $_SESSION['payment_error'] = 'Custom payments must be between $0.70 and $10,000.';
        header('Location: planing.php');
        exit;
    }
    $amount = number_format($custom, 2, '.', '');
    $credits = (int) floor($custom / 0.70);
} else {
    $_SESSION['payment_error'] = 'Invalid plan selected.';
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
$amountFloat = (float) $amount;

$insert = $con->prepare('INSERT INTO invoices (user_id, user_email, plan_name, credits, amount_usd, order_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insert->bind_param('issidss', $userId, $email, $plan, $credits, $amountFloat, $orderId, $status);
$insert->execute();
$invoiceId = $insert->insert_id;
$insert->close();

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
    if (empty($provider['url'])) {
        throw new RuntimeException('Payment link was not returned.');
    }

    $providerUuid = (string) ($provider['uuid'] ?? '');
    $paymentUrl = (string) $provider['url'];
    $providerStatus = (string) ($provider['payment_status'] ?? $provider['status'] ?? 'check');
    $payload = json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $update = $con->prepare("UPDATE invoices SET status = 'pending', provider_uuid = ?, payment_url = ?, provider_status = ?, provider_payload = ? WHERE id = ?");
    $update->bind_param('ssssi', $providerUuid, $paymentUrl, $providerStatus, $payload, $invoiceId);
    $update->execute();
    $update->close();

    $_SESSION['last_payment_order'] = $orderId;
    header('Location: ' . $paymentUrl, true, 303);
    exit;
} catch (Throwable $error) {
    $safeError = mb_substr($error->getMessage(), 0, 500);
    $failed = $con->prepare("UPDATE invoices SET status = 'create_failed', last_error = ? WHERE id = ?");
    $failed->bind_param('si', $safeError, $invoiceId);
    $failed->execute();
    $failed->close();
    oldora_log('payments', 'Invoice creation failed', ['order_id' => $orderId, 'error' => $safeError]);
    $_SESSION['payment_error'] = 'Could not create the payment. Please try again or contact support.';
    header('Location: planing.php');
    exit;
}

