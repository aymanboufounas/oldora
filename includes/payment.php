<?php

require_once __DIR__ . '/env.php';

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
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_invoices_order_id (order_id),
            KEY idx_invoices_user_status (user_id, status),
            KEY idx_invoices_email_status (user_email, status)
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
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
        ];

        foreach ($columns as $name => $definition) {
            if (!oldora_db_has_column($con, 'invoices', $name)) {
                $con->query("ALTER TABLE invoices ADD COLUMN `{$name}` {$definition}");
            }
        }

        $index = $con->query("SHOW INDEX FROM invoices WHERE Key_name = 'uq_invoices_order_id'");
        if (!$index || $index->num_rows === 0) {
            $duplicates = $con->query("SELECT order_id FROM invoices GROUP BY order_id HAVING COUNT(*) > 1 LIMIT 1");
            if ($duplicates && $duplicates->num_rows === 0) {
                $con->query('ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_order_id (order_id)');
            }
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

if (!function_exists('oldora_apply_paid_invoice')) {
    function oldora_apply_paid_invoice($con, $orderId, array $providerPayload = [])
    {
        oldora_ensure_payment_schema($con);
        $con->begin_transaction();

        try {
            $stmt = $con->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('s', $orderId);
            $stmt->execute();
            $invoice = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }

            if ($invoice['status'] === 'paid') {
                $con->commit();
                return ['status' => 'already_paid', 'invoice' => $invoice];
            }

            $providerStatus = (string) ($providerPayload['status'] ?? 'paid');
            if (!in_array($providerStatus, ['paid', 'paid_over'], true)) {
                throw new RuntimeException('The provider has not confirmed this payment.');
            }

            $credits = (int) $invoice['credits'];
            if ($credits <= 0) {
                throw new RuntimeException('Invoice contains no credits.');
            }

            $plan = (string) $invoice['plan_name'];
            $payloadJson = json_encode($providerPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $providerUuid = (string) ($providerPayload['uuid'] ?? $invoice['provider_uuid'] ?? '');

            if ((int) ($invoice['user_id'] ?? 0) > 0) {
                $userId = (int) $invoice['user_id'];
                $update = $con->prepare("UPDATE users
                    SET credits = COALESCE(credits, 0) + ?,
                        plan_type = CASE WHEN ? = 'custom' THEN plan_type ELSE ? END
                    WHERE id = ?");
                $update->bind_param('issi', $credits, $plan, $plan, $userId);
            } else {
                $email = (string) $invoice['user_email'];
                $update = $con->prepare("UPDATE users
                    SET credits = COALESCE(credits, 0) + ?,
                        plan_type = CASE WHEN ? = 'custom' THEN plan_type ELSE ? END
                    WHERE email = ?");
                $update->bind_param('isss', $credits, $plan, $plan, $email);
            }

            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new RuntimeException('The invoice user could not be updated.');
            }
            $update->close();

            $paid = $con->prepare("UPDATE invoices
                SET status = 'paid', provider_status = ?, provider_uuid = ?, provider_payload = ?, paid_at = UTC_TIMESTAMP(), last_error = NULL
                WHERE id = ?");
            $invoiceId = (int) $invoice['id'];
            $paid->bind_param('sssi', $providerStatus, $providerUuid, $payloadJson, $invoiceId);
            $paid->execute();
            if ($paid->affected_rows !== 1) {
                throw new RuntimeException('The invoice could not be finalized.');
            }
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
}

if (!function_exists('oldora_reconcile_invoice')) {
    function oldora_reconcile_invoice($con, array $invoice)
    {
        if (($invoice['status'] ?? '') === 'paid') {
            return $invoice;
        }

        $response = oldora_cryptomus_request('/v1/payment/info', ['order_id' => $invoice['order_id']]);
        $provider = $response['result'] ?? [];
        $providerStatus = (string) ($provider['payment_status'] ?? $provider['status'] ?? 'unknown');
        $provider['status'] = $providerStatus;

        if (in_array($providerStatus, ['paid', 'paid_over'], true)) {
            oldora_apply_paid_invoice($con, $invoice['order_id'], $provider);
        } else {
            $payload = json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $con->prepare('UPDATE invoices SET provider_status = ?, provider_payload = ? WHERE id = ?');
            $invoiceId = (int) $invoice['id'];
            $stmt->bind_param('ssi', $providerStatus, $payload, $invoiceId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $con->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
        $invoiceId = (int) $invoice['id'];
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $fresh = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fresh ?: $invoice;
    }
}

