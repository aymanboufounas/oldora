<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);
require_once __DIR__ . '/includes/env.php';

$secret = oldora_env('CRON_SECRET');
$provided = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
if (PHP_SAPI !== 'cli' && ($secret === '' || !hash_equals($secret, $provided))) {
    oldora_json(['ok' => false, 'message' => 'Unauthorized.'], 401);
}
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    oldora_json(['ok' => false, 'message' => 'POST required.'], 405);
}

header('Cache-Control: no-store');
try {
    require_once __DIR__ . '/connection.php';
    require_once __DIR__ . '/includes/payment.php';
    if (oldora_env('CRYPTOMUS_MERCHANT_ID') === '' || oldora_env('CRYPTOMUS_API_KEY') === '') {
        oldora_json(['ok' => true, 'configured' => false, 'message' => 'Payment reconciliation is waiting for provider configuration.']);
    }
    oldora_json(['ok' => true, 'configured' => true, 'summary' => oldora_reconcile_payments($con)]);
} catch (Throwable $error) {
    oldora_log('payments', 'Payment worker failed', ['error' => $error->getMessage()]);
    if (PHP_SAPI === 'cli') {
        echo json_encode(['ok' => false, 'message' => 'Payment reconciliation could not run. Check the payment log.']) . PHP_EOL;
        exit(1);
    }
    oldora_json(['ok' => false, 'message' => 'Payment reconciliation could not run. Check the payment log.'], 503);
}
