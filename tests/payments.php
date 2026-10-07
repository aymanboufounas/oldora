<?php

// Run only against the local development database; provider responses are fixtures.
declare(strict_types=1);

$providerCalls = 0;
$providerFixtures = [];
function oldora_cryptomus_request($path, array $payload)
{
    global $providerCalls, $providerFixtures;
    $providerCalls++;
    if ($path !== '/v1/payment/info') throw new RuntimeException('Tests must never create real payments.');
    $fixture = $providerFixtures[$payload['order_id']] ?? null;
    if ($fixture instanceof Throwable) throw $fixture;
    if (!is_array($fixture)) throw new RuntimeException('Provider fixture missing.');
    return ['state' => 0, 'result' => $fixture];
}
require_once dirname(__DIR__) . '/includes/payment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$developmentDatabase = oldora_env('DB_NAME');
if ($developmentDatabase !== 'oldora_dev') throw new RuntimeException('Payment tests require the isolated oldora_dev environment.');
$con = new mysqli(oldora_env('DB_HOST', 'localhost'), oldora_env('DB_USER'), oldora_env('DB_PASS'), $developmentDatabase);
$con->set_charset('utf8mb4');
$checks = 0;
function payment_check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function payment_rejected($callback, $message)
{
    try { $callback(); } catch (RuntimeException $expected) { payment_check(true, $message); return; }
    throw new RuntimeException($message);
}
function payment_fixture($order, $status = 'paid', $amount = '29.00000000')
{
    return ['order_id' => $order, 'uuid' => 'fixture-' . $order, 'status' => $status, 'amount' => $amount, 'currency' => 'USD'];
}
function payment_invoice($con, $order, $credits = 70, $plan = 'basic', $amount = '29.00', $status = 'pending', $userId = 1)
{
    $email = 'payments-test@example.invalid';
    $uuid = 'fixture-' . $order;
    $stmt = $con->prepare('INSERT INTO invoices (user_id, user_email, plan_name, credits, amount_usd, order_id, provider_uuid, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ississss', $userId, $email, $plan, $credits, $amount, $order, $uuid, $status);
    $stmt->execute();
    $stmt->close();
    return oldora_payment_get_invoice($con, $order);
}
function payment_balance($con)
{
    return (int) $con->query('SELECT credits FROM users WHERE id = 1')->fetch_assoc()['credits'];
}

function payment_concurrent_workers($database, $mode)
{
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, $mode, $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrent payment test.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        payment_check(proc_close($process) === 0, 'Concurrent payment worker failed: ' . $output . $error);
    }
}

if (($argv[1] ?? '') === '--failing-worker') {
    require_once dirname(__DIR__) . '/connection.php';
    $con = new class {
        public function query($sql) { throw new RuntimeException('Fixture worker database failure.'); }
    };
    putenv('CRYPTOMUS_MERCHANT_ID=fixture-merchant');
    putenv('CRYPTOMUS_API_KEY=fixture-key');
    require dirname(__DIR__) . '/cron_payments.php';
    exit;
}

if (in_array($argv[1] ?? '', ['--race-worker', '--schema-worker'], true)) {
    $database = $argv[2] ?? '';
    if (!preg_match('/^oldora_test_payments_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Invalid test database.');
    $con->select_db($database);
    if ($argv[1] === '--schema-worker') oldora_ensure_payment_schema($con);
    else oldora_apply_paid_invoice($con, 'race', payment_fixture('race'));
    exit;
}

$database = 'oldora_test_payments_' . bin2hex(random_bytes(6));
$con->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$con->select_db($database);
try {
    $con->multi_query(file_get_contents(dirname(__DIR__) . '/database/development.sql'));
    do { if ($result = $con->store_result()) $result->free(); } while ($con->more_results() && $con->next_result());
    oldora_ensure_payment_schema($con);
    $con->query("INSERT INTO users (id, full_name, email, password, credits) VALUES (1, 'Payment fixture', 'payments-test@example.invalid', 'not-a-real-password', 10)");

    payment_check(oldora_payment_quote('pro', '0.01') === ['amount' => '59.00', 'credits' => 200], 'Plan prices must be server-owned.');
    foreach (['0.70' => 1, '2.10' => 3, '3.50' => 5, '70.00' => 100] as $amount => $credits) {
        payment_check(oldora_payment_quote('custom', $amount)['credits'] === $credits, 'Custom credits use exact cents.');
    }
    foreach (['0.69', '10000.01', '2.101', 'NaN', '-2.10', '1e2', ''] as $amount) {
        payment_rejected(fn() => oldora_payment_quote('custom', $amount), 'Invalid custom amount must fail.');
    }
    payment_rejected(fn() => oldora_payment_quote('unknown'), 'Unknown plans must fail.');

    $signed = payment_fixture('signature');
    $signed['additional_data'] = '{"url":"https://example.invalid/فيديو"}';
    $signed['sign'] = md5(base64_encode(json_encode($signed, JSON_UNESCAPED_UNICODE)) . 'fixture-signing-key');
    payment_check(oldora_payment_verify_webhook($signed, 'fixture-signing-key'), 'Valid Unicode and slash-escaped signature.');
    payment_check(!oldora_payment_verify_webhook($signed, ''), 'Missing key must reject callbacks.');
    payment_check(!oldora_payment_verify_webhook($signed, 'incorrect-key'), 'Incorrect key must reject callbacks.');
    $tampered = $signed; $tampered['amount'] = '1.00';
    payment_check(!oldora_payment_verify_webhook($tampered, 'fixture-signing-key'), 'Tampered signature must fail.');
    $tampered['sign'] = [];
    payment_check(!oldora_payment_verify_webhook($tampered, 'fixture-signing-key'), 'Malformed signature must fail.');

    $invoice = payment_invoice($con, 'once');
    payment_check(oldora_apply_paid_invoice($con, 'once', payment_fixture('once'))['credits_added'] === 70, 'Confirmed invoice adds expected credits.');
    payment_check(payment_balance($con) === 80, 'Account balance is credited.');
    payment_check((int) $con->query('SELECT is_paid FROM users WHERE id = 1')->fetch_assoc()['is_paid'] === 1, 'Paid account flag is updated.');
    payment_check(oldora_apply_paid_invoice($con, 'once', payment_fixture('once', 'paid_over'))['credits_added'] === 0, 'Duplicate callback must not credit again.');
    payment_check(payment_balance($con) === 80, 'Repeated payment preserves balance.');
    oldora_payment_record_provider($con, 'once', payment_fixture('once', 'cancel'));
    $fresh = oldora_payment_get_invoice($con, 'once');
    payment_check($fresh['status'] === 'paid' && $fresh['provider_status'] === 'paid' && $fresh['paid_at'] !== null, 'Delayed failed callback must not regress payment.');

    payment_invoice($con, 'invalid');
    foreach (['amount' => '1.00', 'currency' => 'EUR', 'uuid' => 'other-payment', 'order_id' => 'other-order'] as $field => $value) {
        $bad = payment_fixture('invalid'); $bad[$field] = $value;
        payment_rejected(fn() => oldora_apply_paid_invoice($con, 'invalid', $bad), 'Mismatched provider details must not credit.');
    }
    payment_rejected(fn() => oldora_apply_paid_invoice($con, 'invalid', []), 'Empty provider payload must not imply payment.');
    payment_rejected(fn() => oldora_apply_paid_invoice($con, 'invalid', payment_fixture('invalid', 'confirm_check')), 'Unconfirmed payment must not credit.');
    payment_check(payment_balance($con) === 80 && oldora_payment_get_invoice($con, 'invalid')['status'] === 'pending', 'Rejected callbacks leave balance and invoice unchanged.');

    payment_invoice($con, 'legacy', 0, 'basic', '29.00', 'pending', 0);
    oldora_apply_paid_invoice($con, 'legacy', payment_fixture('legacy'));
    $legacy = oldora_payment_get_invoice($con, 'legacy');
    payment_check((int) $legacy['credits'] === 70 && (int) $legacy['user_id'] === 1, 'Legacy unpaid invoice recovers known credits and unique user.');
    payment_check(payment_balance($con) === 150, 'Legacy invoice credits the user once.');
    payment_invoice($con, 'legacy-custom', 0, 'custom', '2.10');
    oldora_apply_paid_invoice($con, 'legacy-custom', payment_fixture('legacy-custom', 'paid', '2.10'));
    payment_check(payment_balance($con) === 153, 'Legacy custom invoice uses exact credit pricing.');
    payment_check($con->query('SELECT plan_type FROM users WHERE id = 1')->fetch_assoc()['plan_type'] === 'basic', 'Custom top-up preserves existing plan.');
    payment_invoice($con, 'legacy-wrong', 0, 'basic', '1.00');
    payment_rejected(fn() => oldora_apply_paid_invoice($con, 'legacy-wrong', payment_fixture('legacy-wrong', 'paid', '1.00')), 'Legacy underpriced plan must not infer credits.');
    payment_invoice($con, 'legacy-paid', 0, 'basic', '29.00', 'paid');
    oldora_apply_paid_invoice($con, 'legacy-paid', payment_fixture('legacy-paid'));
    payment_check(payment_balance($con) === 153, 'Legacy paid status never receives an uncertain second credit.');

    payment_invoice($con, 'missing-user', 70, 'basic', '29.00', 'pending', 999);
    payment_rejected(fn() => oldora_apply_paid_invoice($con, 'missing-user', payment_fixture('missing-user')), 'Missing invoice user must fail.');
    payment_check(oldora_payment_get_invoice($con, 'missing-user')['status'] === 'pending' && payment_balance($con) === 153, 'Failed user update rolls back invoice and credits.');

    payment_invoice($con, 'finalization-failure');
    $con->query("CREATE TRIGGER fixture_finalize_error BEFORE UPDATE ON invoices FOR EACH ROW
        BEGIN IF OLD.order_id = 'finalization-failure' AND NEW.status = 'paid' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture finalization failure'; END IF; END");
    try {
        oldora_apply_paid_invoice($con, 'finalization-failure', payment_fixture('finalization-failure'));
        throw new RuntimeException('Invoice finalization failure must be detected.');
    } catch (mysqli_sql_exception $expected) {
        payment_check(payment_balance($con) === 153 && oldora_payment_get_invoice($con, 'finalization-failure')['status'] === 'pending', 'Finalization failure rolls back the preceding credit update.');
    }
    $con->query('DROP TRIGGER fixture_finalize_error');

    $recovery = payment_invoice($con, 'recovery', 70, 'basic', '29.00', 'create_failed');
    $providerFixtures['recovery'] = payment_fixture('recovery');
    $fresh = oldora_reconcile_invoice($con, $recovery);
    payment_check($fresh['status'] === 'paid' && payment_balance($con) === 223, 'Lost creation response/webhook recovers from authenticated provider info.');
    payment_check((int) $fresh['reconcile_attempts'] === 1 && $fresh['next_check_at'] === null, 'Successful reconciliation records the check and stops retries.');

    $pending = payment_invoice($con, 'throttle');
    $providerFixtures['throttle'] = payment_fixture('throttle', 'check');
    $before = $providerCalls;
    oldora_reconcile_invoice($con, $pending);
    oldora_reconcile_invoice($con, $pending);
    payment_check($providerCalls === $before + 1, 'Concurrent return-page polls must throttle provider calls.');

    $outage = payment_invoice($con, 'outage');
    $providerFixtures['outage'] = new RuntimeException('Fixture provider temporarily unavailable.');
    payment_rejected(fn() => oldora_reconcile_invoice($con, $outage), 'Provider outage is recoverable.');
    $fresh = oldora_payment_get_invoice($con, 'outage');
    payment_check($fresh['status'] === 'pending' && $fresh['last_error'] !== null && $fresh['next_check_at'] !== null, 'Outage preserves pending payment with retry diagnostics.');
    $providerFixtures['outage'] = payment_fixture('outage');
    $con->query("UPDATE invoices SET last_checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), next_check_at = UTC_TIMESTAMP() WHERE order_id = 'outage'");
    $fresh = oldora_reconcile_invoice($con, $fresh);
    payment_check($fresh['status'] === 'paid' && $fresh['last_error'] === null && payment_balance($con) === 293, 'Recovered provider clears error and credits once.');

    // Isolate a due failed invoice for the scheduled worker.
    $con->query("UPDATE invoices SET next_check_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE status <> 'paid'");
    payment_invoice($con, 'worker', 70, 'basic', '29.00', 'failed');
    $providerFixtures['worker'] = payment_fixture('worker');
    $summary = oldora_reconcile_payments($con, 5);
    payment_check($summary['checked'] === 1 && $summary['credited'] === 1 && $summary['errors'] === 0, 'Worker recovers a late confirmed payment after failed callback.');
    payment_check(payment_balance($con) === 363, 'Scheduled recovery updates actual account balance.');

    payment_invoice($con, 'race');
    payment_concurrent_workers($database, '--race-worker');
    payment_check(payment_balance($con) === 433, 'Four concurrent callbacks add credits exactly once.');
    payment_check(oldora_payment_get_invoice($con, 'race')['status'] === 'paid', 'Concurrent payment reaches a committed paid state.');

    $con->query('ALTER TABLE invoices DROP INDEX idx_invoices_reconcile, DROP COLUMN last_checked_at, DROP COLUMN next_check_at, DROP COLUMN reconcile_attempts');
    payment_concurrent_workers($database, '--schema-worker');
    foreach (['last_checked_at', 'next_check_at', 'reconcile_attempts'] as $column) {
        payment_check(oldora_db_has_column($con, 'invoices', $column), 'Concurrent additive legacy migration must complete.');
    }

    // Legacy tables without the unique index may contain ambiguous orders.
    $con->query('ALTER TABLE invoices DROP INDEX uq_invoices_order_id');
    payment_invoice($con, 'duplicate');
    payment_rejected(fn() => payment_invoice($con, 'duplicate'), 'Duplicate insertion helper detects ambiguous orders.');
    payment_rejected(fn() => oldora_apply_paid_invoice($con, 'duplicate', payment_fixture('duplicate')), 'Ambiguous legacy orders must not credit anyone.');
    payment_check(payment_balance($con) === 433, 'Ambiguous legacy orders preserve the balance.');

    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--failing-worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start failed worker test.');
    fclose($pipes[0]);
    $response = json_decode(stream_get_contents($pipes[1]), true);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    payment_check(proc_close($process) === 1, 'Worker errors must return a failure exit status to the supervisor. ' . $errors);
    payment_check(is_array($response) && $response['ok'] === false, 'Failed worker returns a truthful error response.');
    echo "$checks payment checks passed (mock provider, isolated MariaDB database).\n";
} finally {
    $con->select_db($developmentDatabase);
    $con->query("DROP DATABASE `{$database}`");
}
