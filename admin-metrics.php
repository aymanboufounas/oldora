<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/admin_metrics.php';
header('Cache-Control: no-store, private');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email']) || !oldora_admin_authorized($con, (string) $_SESSION['email'])) oldora_json(['ok' => false, 'message' => 'Administrator access required.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); oldora_json(['ok' => false, 'message' => 'GET required.'], 405); }
oldora_json(['ok' => true] + oldora_admin_metrics($con));
