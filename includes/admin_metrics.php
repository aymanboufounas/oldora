<?php
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/automation_worker.php';

function oldora_admin_authorized($con, string $email): bool
{
    $stmt = $con->prepare("SELECT id FROM users WHERE email = ? AND role = 'admin' AND status IN ('active','verified') LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $allowed;
}

function oldora_admin_metrics($con): array
{
    oldora_ensure_payment_schema($con);
    oldora_ensure_content_schema($con);
    $users = $con->query("SELECT COUNT(*) AS total_users,
        COALESCE(SUM(plan_type <> 'free' AND status IN ('active','verified')),0) AS paid_plan_users,
        COALESCE(SUM(credits),0) AS available_credits FROM users WHERE role = 'user'")->fetch_assoc();
    $payments = $con->query("SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_usd ELSE 0 END),0) AS revenue_usd,
        COALESCE(SUM(status = 'paid'),0) AS paid_invoices,
        COALESCE(SUM(status IN ('pending','creating')),0) AS pending_invoices,
        COALESCE(SUM(status IN ('failed','create_failed')),0) AS failed_invoices,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN credits ELSE 0 END),0) AS credited_total
        FROM invoices")->fetch_assoc();
    $jobs = $con->query("SELECT
        COALESCE(SUM(status IN ('pending','waiting_media','publishing','submitted')),0) AS queued_posts,
        COALESCE(SUM(status = 'published'),0) AS published_posts,
        COALESCE(SUM(status IN ('failed','needs_review')),0) AS posts_need_attention FROM publish_jobs")->fetch_assoc();
    $content = $con->query("SELECT COALESCE(SUM(status = 'failed'),0) AS failed_generations,
        COALESCE(SUM(status IN ('starting','queued','processing','in_progress')),0) AS active_generations FROM content_items")->fetch_assoc();
    $recent = $con->query('SELECT order_id,user_email,plan_name,amount_usd,credits,status,provider_status,last_error,paid_at,created_at FROM invoices ORDER BY id DESC LIMIT 12')->fetch_all(MYSQLI_ASSOC);
    $summary = array_merge($users, $payments, $jobs, $content);
    foreach ($summary as $key => &$value) if ($key !== 'revenue_usd') $value = (int) $value;
    unset($value);
    return ['summary' => $summary, 'recent_payments' => $recent, 'worker' => oldora_automation_health($con)];
}
