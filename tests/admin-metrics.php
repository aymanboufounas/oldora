<?php
require_once __DIR__ . '/../includes/admin_metrics.php';
$env = oldora_env_all();
if (($env['DB_NAME'] ?? '') !== 'oldora_dev') throw new RuntimeException('Run against the local oldora_dev environment only.');
$db = new mysqli($env['DB_HOST'], $env['DB_USER'], $env['DB_PASS'] ?? '');
$name = 'oldora_admin_test_' . bin2hex(random_bytes(4));
$db->query("CREATE DATABASE `$name`");
$db->select_db($name);
$count = 0;
function verify($condition, $message) { global $count; if (!$condition) throw new RuntimeException($message); $count++; }
try {
    $db->multi_query(file_get_contents(__DIR__ . '/../database/development.sql'));
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    oldora_ensure_payment_schema($db);
    $db->query("INSERT INTO users (full_name,email,password,credits,plan_type,role) VALUES ('A','a@example.invalid','disabled',100,'elite','user'),('B','b@example.invalid','disabled',11,'free','user'),('Admin','admin@example.invalid','disabled',999,'free','admin')");
    $db->query("INSERT INTO invoices (user_email,plan_name,credits,amount_usd,order_id,status) VALUES ('a@example.invalid','basic',70,29,'basic','paid'),('b@example.invalid','custom',3,2.10,'topup','paid'),('a@example.invalid','elite',500,159,'pending','pending')");
    $metrics = oldora_admin_metrics($db)['summary'];
    verify($metrics['total_users'] === 2, 'Exclude administrators from user counts');
    verify($metrics['available_credits'] === 111, 'Actual credit balances');
    verify($metrics['revenue_usd'] === '31.10', 'Use paid invoices including custom top-ups, not selected plans');
    verify($metrics['paid_invoices'] === 2 && $metrics['pending_invoices'] === 1, 'Payment states');
    verify(oldora_admin_authorized($db, 'admin@example.invalid'), 'Active administrator allowed');
    verify(!oldora_admin_authorized($db, 'a@example.invalid'), 'Ordinary user denied');
    $db->query("UPDATE users SET status='banned' WHERE role='admin'");
    verify(!oldora_admin_authorized($db, 'admin@example.invalid'), 'Revoked administrator denied');
    echo "$count admin metrics checks passed\n";
} finally { $db->query("DROP DATABASE `$name`"); }
