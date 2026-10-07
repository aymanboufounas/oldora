<?php

require_once __DIR__ . '/env.php';

function oldora_payment_plans()
{
    return [
        'basic' => ['amount' => '29.00', 'credits' => 70],
        'pro' => ['amount' => '59.00', 'credits' => 200],
        'elite' => ['amount' => '159.00', 'credits' => 500],
        'elite_yearly' => ['amount' => '999.00', 'credits' => 4000]
    ];
}

// Monetary comparisons and custom credit calculations must not use floats.
function oldora_payment_cents($amount)
{
    if (!is_string($amount) && !is_int($amount)) {
        throw new RuntimeException('Invalid payment amount.');
    }
    if (!preg_match('/^(\d{1,8})(?:\.(\d{1,8}))?$/D', (string) $amount, $parts)) {
        throw new RuntimeException('Invalid payment amount.');
    }
    $fraction = $parts[2] ?? '';
    if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
        throw new RuntimeException('The payment amount must use whole cents.');
    }
    return (int) $parts[1] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
}

function oldora_payment_quote($plan, $customAmount = '')
{
    $plans = oldora_payment_plans();
    if (isset($plans[$plan])) return $plans[$plan];
    if ($plan !== 'custom') throw new RuntimeException('Invalid plan selected.');
    $cents = oldora_payment_cents($customAmount);
    if ($cents < 70 || $cents > 1000000) {
        throw new RuntimeException('Custom payments must be between $0.70 and $10,000.');
    }
    return ['amount' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100), 'credits' => intdiv($cents, 70)];
}

function oldora_payment_provider_status(array $payload)
{
    $status = $payload['payment_status'] ?? $payload['status'] ?? '';
    if (!is_string($status) || $status === '' || strlen($status) > 50) {
        throw new RuntimeException('The provider returned an invalid payment status.');
    }
    return $status;
}

function oldora_payment_validate_provider(array $invoice, array $payload)
{
    if (!isset($payload['order_id']) || !is_string($payload['order_id']) || !hash_equals((string) $invoice['order_id'], $payload['order_id'])) {
        throw new RuntimeException('Provider order does not match the invoice.');
    }
    $uuid = $payload['uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '' || strlen($uuid) > 100) {
        throw new RuntimeException('The provider payment identifier is missing.');
    }
    if (!empty($invoice['provider_uuid']) && !hash_equals((string) $invoice['provider_uuid'], $uuid)) {
        throw new RuntimeException('Provider payment does not match the invoice.');
    }
    if (($payload['currency'] ?? '') !== 'USD' || oldora_payment_cents($payload['amount'] ?? '') !== oldora_payment_cents((string) $invoice['amount_usd'])) {
        throw new RuntimeException('Provider amount or currency does not match the invoice.');
    }
}

function oldora_payment_verify_webhook(array $payload, $apiKey)
{
    $signature = $payload['sign'] ?? null;
    if ($apiKey === '' || !is_string($signature) || !preg_match('/^[a-f0-9]{32}$/iD', $signature)) return false;
    unset($payload['sign']);
    // Cryptomus signs the object without sign, using PHP's default slash escaping.
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    return $json !== false && hash_equals(md5(base64_encode($json) . $apiKey), strtolower($signature));
}

if (!function_exists('oldora_db_has_column')) {
    function oldora_db_has_column($con, $table, $column)
    {
        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
        $safeColumn = $con->real_escape_string($column);
        $result = $con->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('oldora_ensure_payment_schema')) {
    function oldora_ensure_payment_schema($con)
    {
        static $ready = [];
        $database = (string) $con->query('SELECT DATABASE()')->fetch_row()[0];
        $key = $con->thread_id . ':' . $database;
        if (isset($ready[$key])) return;
        // Serialize additive migrations when the first webhook and worker start together.
        $lockName = 'oldora-payments-' . sha1($database);
        $lock = $con->prepare('SELECT GET_LOCK(?, 10)');
        $lock->bind_param('s', $lockName);
        $lock->execute();
        $acquired = (int) $lock->get_result()->fetch_row()[0] === 1;
        $lock->close();
        if (!$acquired) throw new RuntimeException('Payment schema is busy. Please retry.');
        try {
            $con->query("CREATE TABLE IF NOT EXISTS invoices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                user_email VARCHAR(255) NOT NULL,
                plan_name VARCHAR(50) NOT NULL,
                credits INT UNSIGNED NOT NULL DEFAULT 0,
                amount_usd DECIMAL(10,2) NOT NULL,
                order_id VARCHAR(100) NOT NULL,
                provider_uuid VARCHAR(100) NULL,
                payment_url TEXT NULL,
                provider_status VARCHAR(50) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                provider_payload LONGTEXT NULL,
                last_error TEXT NULL,
                paid_at DATETIME NULL,
                last_checked_at DATETIME NULL,
                next_check_at DATETIME NULL,
                reconcile_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_invoices_order_id (order_id),
                KEY idx_invoices_user_status (user_id, status),
                KEY idx_invoices_email_status (user_email, status),
                KEY idx_invoices_reconcile (status, next_check_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $columns = [
                'user_id' => 'BIGINT UNSIGNED NULL AFTER id',
                'credits' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_name',
                'provider_uuid' => 'VARCHAR(100) NULL AFTER order_id',
                'payment_url' => 'TEXT NULL AFTER provider_uuid',
                'provider_status' => 'VARCHAR(50) NULL AFTER payment_url',
                'provider_payload' => 'LONGTEXT NULL AFTER status',
                'last_error' => 'TEXT NULL AFTER provider_payload',
                'paid_at' => 'DATETIME NULL AFTER last_error',
                'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
                'last_checked_at' => 'DATETIME NULL',
                'next_check_at' => 'DATETIME NULL',
                'reconcile_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0'
            ];

            foreach ($columns as $name => $definition) {
                if (!oldora_db_has_column($con, 'invoices', $name)) {
                    $con->query("ALTER TABLE invoices ADD COLUMN `{$name}` {$definition}");
                }
            }

            $reconcileIndex = $con->query("SHOW INDEX FROM invoices WHERE Key_name = 'idx_invoices_reconcile'");
            if ($reconcileIndex && $reconcileIndex->num_rows === 0) {
                $con->query('ALTER TABLE invoices ADD KEY idx_invoices_reconcile (status, next_check_at)');
            }

            $index = $con->query("SHOW INDEX FROM invoices WHERE Key_name = 'uq_invoices_order_id'");
            if (!$index || $index->num_rows === 0) {
                $duplicates = $con->query("SELECT order_id FROM invoices GROUP BY order_id HAVING COUNT(*) > 1 LIMIT 1");
                if ($duplicates && $duplicates->num_rows === 0) {
                    $con->query('ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_order_id (order_id)');
                }
            }
            $ready[$key] = true;
        } finally {
            $release = $con->prepare('SELECT RELEASE_LOCK(?)');
            $release->bind_param('s', $lockName);
            $release->execute();
            $release->close();
        }
    }
}

if (!function_exists('oldora_cryptomus_request')) {
    function oldora_cryptomus_request($path, array $payload)
    {
        $merchantId = oldora_env('CRYPTOMUS_MERCHANT_ID');
        $apiKey = oldora_env('CRYPTOMUS_API_KEY');
        if ($merchantId === '' || $apiKey === '') {
            throw new RuntimeException('Payment provider is not configured.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode the payment request.');
        }

        $ch = curl_init('https://api.cryptomus.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'merchant: ' . $merchantId,
                'sign: ' . md5(base64_encode($json) . $apiKey),
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Payment provider connection failed: ' . $curlError);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Payment provider returned an invalid response.');
        }

        if ($status < 200 || $status >= 300 || (isset($decoded['state']) && (int) $decoded['state'] !== 0)) {
            $message = $decoded['message'] ?? $decoded['errors']['message'] ?? 'Payment provider rejected the request.';
            throw new RuntimeException(is_string($message) ? $message : 'Payment provider rejected the request.');
        }

        return $decoded;
    }
}

function oldora_payment_get_invoice($con, $orderId)
{
    $stmt = $con->prepare('SELECT * FROM invoices WHERE order_id = ?');
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result();
    if ($rows->num_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Invoice not found or order is ambiguous.');
    }
    $invoice = $rows->fetch_assoc();
    $stmt->close();
    return $invoice;
}

function oldora_apply_paid_invoice($con, $orderId, array $providerPayload = [])
{
    oldora_ensure_payment_schema($con);
    $hasPaidFlag = oldora_db_has_column($con, 'users', 'is_paid');
    $con->begin_transaction();

    try {
        // Lock every match: legacy duplicate order IDs must never credit an arbitrary user.
        $stmt = $con->prepare('SELECT * FROM invoices WHERE order_id = ? FOR UPDATE');
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $rows = $stmt->get_result();
        if ($rows->num_rows !== 1) throw new RuntimeException('Invoice not found or order is ambiguous.');
        $invoice = $rows->fetch_assoc();
        $stmt->close();
        oldora_payment_validate_provider($invoice, $providerPayload);
        $providerStatus = oldora_payment_provider_status($providerPayload);
        if (!in_array($providerStatus, ['paid', 'paid_over'], true)) {
            throw new RuntimeException('The provider has not confirmed this payment.');
        }
        if ($invoice['status'] === 'paid') {
            $con->commit();
            return ['status' => 'already_paid', 'credits_added' => 0, 'invoice' => $invoice];
        }

        $credits = (int) $invoice['credits'];
        if ($credits <= 0) {
            // Recover older unpaid invoices only from server-side plan/price information.
            $quote = oldora_payment_quote((string) $invoice['plan_name'], (string) $invoice['amount_usd']);
            if (oldora_payment_cents($quote['amount']) !== oldora_payment_cents((string) $invoice['amount_usd'])) {
                throw new RuntimeException('Legacy invoice price does not match its plan.');
            }
            $credits = $quote['credits'];
        }
        if ($credits <= 0) throw new RuntimeException('Invoice contains no credits.');

        $userId = (int) ($invoice['user_id'] ?? 0);
        if ($userId > 0) {
            $user = $con->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $user->bind_param('i', $userId);
        } else {
            $email = (string) $invoice['user_email'];
            $user = $con->prepare('SELECT id FROM users WHERE email = ? FOR UPDATE');
            $user->bind_param('s', $email);
        }
        $user->execute();
        $users = $user->get_result();
        if ($users->num_rows !== 1) throw new RuntimeException('The invoice user could not be found uniquely.');
        $userId = (int) $users->fetch_assoc()['id'];
        $user->close();

        $plan = (string) $invoice['plan_name'];
        $paidFlag = $hasPaidFlag ? ', is_paid = 1' : '';
        $update = $con->prepare("UPDATE users SET credits = COALESCE(credits, 0) + ?,
            plan_type = CASE WHEN ? = 'custom' THEN plan_type ELSE ? END {$paidFlag} WHERE id = ?");
        $update->bind_param('issi', $credits, $plan, $plan, $userId);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('The invoice user could not be updated.');
        $update->close();

        $payloadJson = json_encode($providerPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $providerUuid = $providerPayload['uuid'];
        $paid = $con->prepare("UPDATE invoices SET status = 'paid', credits = ?, user_id = ?,
            provider_status = ?, provider_uuid = ?, provider_payload = ?, paid_at = UTC_TIMESTAMP(),
            next_check_at = NULL, last_error = NULL WHERE id = ?");
        $invoiceId = (int) $invoice['id'];
        $paid->bind_param('iisssi', $credits, $userId, $providerStatus, $providerUuid, $payloadJson, $invoiceId);
        $paid->execute();
        if ($paid->affected_rows !== 1) throw new RuntimeException('The invoice could not be finalized.');
        $paid->close();
        $con->commit();
        oldora_log('payments', 'Invoice credited', ['order_id' => $orderId, 'credits' => $credits, 'status' => $providerStatus]);
        return ['status' => 'paid', 'credits_added' => $credits];
    } catch (Throwable $error) {
        $con->rollback();
        oldora_log('payments', 'Invoice credit failed', ['order_id' => $orderId, 'error' => $error->getMessage()]);
        throw $error;
    }
}

function oldora_payment_record_provider($con, $orderId, array $provider)
{
    $status = oldora_payment_provider_status($provider);
    if (in_array($status, ['paid', 'paid_over'], true)) {
        return oldora_apply_paid_invoice($con, $orderId, $provider);
    }
    $invoice = oldora_payment_get_invoice($con, $orderId);
    oldora_payment_validate_provider($invoice, $provider);
    $localStatus = in_array($status, ['fail', 'cancel', 'system_fail', 'wrong_amount'], true) ? 'failed' : 'pending';
    $payload = json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $uuid = $provider['uuid'];
    // An older callback/poll result must never overwrite a confirmed payment.
    $stmt = $con->prepare("UPDATE invoices SET provider_status = ?, provider_uuid = ?, provider_payload = ?,
        status = ?, last_error = NULL WHERE id = ? AND status <> 'paid'");
    $invoiceId = (int) $invoice['id'];
    $stmt->bind_param('ssssi', $status, $uuid, $payload, $localStatus, $invoiceId);
    $stmt->execute();
    $stmt->close();
    return ['status' => $localStatus, 'credits_added' => 0];
}

function oldora_payment_record_error($con, $invoiceId, $message)
{
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 500, 'UTF-8') : substr($message, 0, 500);
    $stmt = $con->prepare("UPDATE invoices SET last_error = ?,
        next_check_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST(900, 30 * POW(2, LEAST(5, reconcile_attempts))) SECOND)
        WHERE id = ? AND status <> 'paid'");
    $stmt->bind_param('si', $message, $invoiceId);
    $stmt->execute();
    $stmt->close();
}

function oldora_reconcile_invoice($con, array $invoice)
{
    if (($invoice['status'] ?? '') === 'paid') return $invoice;
    $invoiceId = (int) $invoice['id'];
    // Atomically throttle return-page polling and overlapping workers.
    $claim = $con->prepare("UPDATE invoices SET last_checked_at = UTC_TIMESTAMP(),
        next_check_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND), reconcile_attempts = reconcile_attempts + 1
        WHERE id = ? AND status <> 'paid' AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND))");
    $claim->bind_param('i', $invoiceId);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) return oldora_payment_get_invoice($con, $invoice['order_id']);

    try {
        $response = oldora_cryptomus_request('/v1/payment/info', ['order_id' => $invoice['order_id']]);
        $provider = $response['result'] ?? null;
        if (!is_array($provider)) throw new RuntimeException('Payment provider returned no payment details.');
        oldora_payment_record_provider($con, $invoice['order_id'], $provider);
    } catch (Throwable $error) {
        oldora_payment_record_error($con, $invoiceId, $error->getMessage());
        throw $error;
    }
    return oldora_payment_get_invoice($con, $invoice['order_id']);
}

function oldora_reconcile_payments($con, $limit = 5)
{
    oldora_ensure_payment_schema($con);
    $limit = max(1, min(25, (int) $limit));
    $summary = ['checked' => 0, 'credited' => 0, 'pending' => 0, 'errors' => 0];
    $rows = $con->query("SELECT * FROM invoices
        WHERE status IN ('creating', 'pending', 'failed', 'create_failed')
        AND (status IN ('creating', 'pending') OR created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY))
        AND (next_check_at IS NULL OR next_check_at <= UTC_TIMESTAMP())
        ORDER BY COALESCE(next_check_at, created_at), id LIMIT {$limit}");
    while ($invoice = $rows->fetch_assoc()) {
        $summary['checked']++;
        try {
            $fresh = oldora_reconcile_invoice($con, $invoice);
            $summary[$fresh['status'] === 'paid' ? 'credited' : 'pending']++;
        } catch (Throwable $error) {
            $summary['errors']++;
            oldora_log('payments', 'Scheduled reconciliation deferred', ['order_id' => $invoice['order_id'], 'error' => $error->getMessage()]);
        }
    }
    return $summary;
}
