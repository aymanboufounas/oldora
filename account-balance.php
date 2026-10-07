<?php
require_once __DIR__ . '/connection.php';
header('Cache-Control: no-store, private');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) oldora_json(['ok' => false, 'message' => 'Login required.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); oldora_json(['ok' => false, 'message' => 'GET required.'], 405); }
$stmt = $con->prepare('SELECT credits, plan_type, status FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $_SESSION['email']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user || $user['status'] === 'banned') oldora_json(['ok' => false, 'message' => 'Account unavailable.'], 401);
oldora_json(['ok' => true, 'credits' => (int) $user['credits'], 'plan_type' => $user['plan_type']]);
