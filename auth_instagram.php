<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/accounts.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}

$appId = oldora_env('FB_APP_ID');
$appSecret = oldora_env('FB_APP_SECRET');
$redirectUri = oldora_env('FB_REDIRECT_URI', oldora_base_url() . '/auth_instagram.php');
$graph = oldora_env('META_GRAPH_VERSION', 'v25.0');
if ($appId === '' || $appSecret === '') {
    header('Location: connect-platforms.php?error=instagram_not_configured');
    exit;
}

if (isset($_GET['error'])) {
    header('Location: connect-platforms.php?error=instagram_cancelled');
    exit;
}

if (!empty($_GET['code'])) {
    $state = (string) ($_GET['state'] ?? '');
    if (empty($_SESSION['instagram_oauth_state']) || !hash_equals($_SESSION['instagram_oauth_state'], $state)) {
        header('Location: connect-platforms.php?error=invalid_oauth_state');
        exit;
    }
    unset($_SESSION['instagram_oauth_state']);

    try {
        $short = oldora_http_json('GET', 'https://graph.facebook.com/' . rawurlencode($graph) . '/oauth/access_token?' . http_build_query([
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $_GET['code']
        ]));
        $shortToken = (string) ($short['access_token'] ?? '');
        if ($shortToken === '') throw new RuntimeException('No Instagram access token returned.');

        $long = oldora_http_json('GET', 'https://graph.facebook.com/' . rawurlencode($graph) . '/oauth/access_token?' . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortToken
        ]));
        $userToken = (string) ($long['access_token'] ?? '');
        if ($userToken === '') throw new RuntimeException('Could not create a long-lived Instagram token.');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) ($long['expires_in'] ?? 5184000));

        $pages = oldora_http_json('GET', 'https://graph.facebook.com/' . rawurlencode($graph) . '/me/accounts?' . http_build_query([
            'fields' => 'id,name,access_token,instagram_business_account{id,username,profile_picture_url,followers_count}',
            'limit' => 100,
            'access_token' => $userToken
        ]));

        $saved = 0;
        foreach (($pages['data'] ?? []) as $page) {
            $instagram = $page['instagram_business_account'] ?? null;
            if (!$instagram || empty($instagram['id'])) continue;
            oldora_upsert_account($con, [
                'user_email' => $_SESSION['email'],
                'platform' => 'instagram',
                'access_token' => (string) ($page['access_token'] ?? $userToken),
                'refresh_token' => '',
                'expires_at' => $expiresAt,
                'channel_id' => (string) $instagram['id'],
                'channel_name' => (string) ($instagram['username'] ?? $page['name'] ?? 'Instagram account'),
                'picture' => (string) ($instagram['profile_picture_url'] ?? ''),
                'subscribers' => (int) ($instagram['followers_count'] ?? 0),
                'scopes' => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement',
                'metadata' => ['page_id' => $page['id'] ?? null, 'page_name' => $page['name'] ?? null]
            ]);
            $saved++;
        }
        if ($saved === 0) throw new RuntimeException('No professional Instagram account is connected to your Facebook Page.');

        if (oldora_db_has_column($con, 'users', 'instagram_connected')) {
            $stmt = $con->prepare('UPDATE users SET instagram_connected = 1 WHERE email = ?');
            $stmt->bind_param('s', $_SESSION['email']);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: connect-platforms.php?status=instagram_connected');
        exit;
    } catch (Throwable $error) {
        oldora_log('oauth', 'Instagram connection failed', ['error' => $error->getMessage()]);
        $_SESSION['connection_error'] = $error->getMessage();
        header('Location: connect-platforms.php?error=instagram_failed');
        exit;
    }
}

$_SESSION['instagram_oauth_state'] = bin2hex(random_bytes(24));
$authUrl = 'https://www.facebook.com/' . rawurlencode($graph) . '/dialog/oauth?' . http_build_query([
    'client_id' => $appId,
    'redirect_uri' => $redirectUri,
    'scope' => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement',
    'response_type' => 'code',
    'state' => $_SESSION['instagram_oauth_state']
]);
header('Location: ' . $authUrl);
exit;

