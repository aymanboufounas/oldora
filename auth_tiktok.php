<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/accounts.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}

$clientKey = oldora_env('TIKTOK_CLIENT_KEY');
$clientSecret = oldora_env('TIKTOK_CLIENT_SECRET');
$redirectUri = oldora_env('TIKTOK_REDIRECT_URI', oldora_base_url() . '/auth_tiktok.php');
if ($clientKey === '' || $clientSecret === '') {
    header('Location: connect-platforms.php?error=tiktok_not_configured');
    exit;
}

if (isset($_GET['error'])) {
    header('Location: connect-platforms.php?error=tiktok_cancelled');
    exit;
}

if (!empty($_GET['code'])) {
    $state = (string) ($_GET['state'] ?? '');
    if (empty($_SESSION['tiktok_oauth_state']) || !hash_equals($_SESSION['tiktok_oauth_state'], $state)) {
        header('Location: connect-platforms.php?error=invalid_oauth_state');
        exit;
    }
    unset($_SESSION['tiktok_oauth_state']);

    try {
        $token = oldora_http_json('POST', 'https://open.tiktokapis.com/v2/oauth/token/', ['Content-Type: application/x-www-form-urlencoded'], [
            'client_key' => $clientKey,
            'client_secret' => $clientSecret,
            'code' => $_GET['code'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ], true);
        if (empty($token['access_token'])) throw new RuntimeException('TikTok returned no access token.');

        $profile = oldora_http_json('GET', 'https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,avatar_url', [
            'Authorization: Bearer ' . $token['access_token']
        ]);
        $profileUser = $profile['data']['user'] ?? [];
        $openId = (string) ($profileUser['open_id'] ?? $token['open_id'] ?? '');
        if ($openId === '') throw new RuntimeException('TikTok returned no account ID.');

        oldora_upsert_account($con, [
            'user_email' => $_SESSION['email'],
            'platform' => 'tiktok',
            'access_token' => (string) $token['access_token'],
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (int) ($token['expires_in'] ?? 86400)),
            'channel_id' => $openId,
            'channel_name' => (string) ($profileUser['display_name'] ?? 'TikTok account'),
            'picture' => (string) ($profileUser['avatar_url'] ?? ''),
            'scopes' => (string) ($token['scope'] ?? 'user.info.basic,video.upload,video.publish'),
            'metadata' => ['refresh_expires_in' => $token['refresh_expires_in'] ?? null]
        ]);
        if (oldora_db_has_column($con, 'users', 'tiktok_connected')) {
            $stmt = $con->prepare('UPDATE users SET tiktok_connected = 1 WHERE email = ?');
            $stmt->bind_param('s', $_SESSION['email']);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: connect-platforms.php?status=tiktok_connected');
        exit;
    } catch (Throwable $error) {
        oldora_log('oauth', 'TikTok connection failed', ['error' => $error->getMessage()]);
        $_SESSION['connection_error'] = $error->getMessage();
        header('Location: connect-platforms.php?error=tiktok_failed');
        exit;
    }
}

$_SESSION['tiktok_oauth_state'] = bin2hex(random_bytes(24));
$authUrl = 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
    'client_key' => $clientKey,
    'scope' => 'user.info.basic,video.upload,video.publish',
    'response_type' => 'code',
    'redirect_uri' => $redirectUri,
    'state' => $_SESSION['tiktok_oauth_state']
]);
header('Location: ' . $authUrl);
exit;

