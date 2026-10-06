<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/content.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) oldora_json(['ok' => false, 'message' => 'Login required.'], 401);

$contentId = (int) ($_GET['id'] ?? 0);
if ($contentId <= 0) oldora_json(['ok' => false, 'message' => 'Invalid content ID.'], 422);

oldora_ensure_content_schema($con);
$stmt = $con->prepare('SELECT c.* FROM content_items c INNER JOIN users u ON u.id = c.user_id WHERE c.id = ? AND u.email = ? LIMIT 1');
$stmt->bind_param('is', $contentId, $_SESSION['email']);
$stmt->execute();
$content = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$content) oldora_json(['ok' => false, 'message' => 'Content not found.'], 404);

$jobs = [];
$jobStmt = $con->prepare('SELECT p.id, p.platform, p.status, p.scheduled_at, p.last_error, p.provider_publish_id, t.channel_name FROM publish_jobs p LEFT JOIN user_tokens t ON t.id = p.token_id WHERE p.content_id = ? ORDER BY p.id');
$jobStmt->bind_param('i', $contentId);
$jobStmt->execute();
$result = $jobStmt->get_result();
while ($row = $result->fetch_assoc()) $jobs[] = $row;
$jobStmt->close();

oldora_json([
    'ok' => true,
    'content' => [
        'id' => (int) $content['id'],
        'media_type' => $content['media_type'],
        'status' => $content['status'],
        'progress' => (int) $content['progress'],
        'asset_url' => $content['asset_url'],
        'error_message' => $content['error_message']
    ],
    'publish_jobs' => $jobs
]);

