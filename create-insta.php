<?php
require_once __DIR__ . '/connection.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}
header('Location: studio.php?type=image', true, 302);
exit;

