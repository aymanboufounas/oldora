<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/accounts.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}

$clientId = oldora_env('YOUTUBE_CLIENT_ID');
$clientSecret = oldora_env('YOUTUBE_CLIENT_SECRET');
$redirectUri = oldora_env('YOUTUBE_REDIRECT_URI', oldora_base_url() . '/auth_youtube.php');
if ($clientId === '' || $clientSecret === '') {
    header('Location: connect-platforms.php?error=youtube_not_configured');
    exit;
}

if (isset($_GET['error'])) {
    header('Location: connect-platforms.php?error=youtube_cancelled');
    exit;
}

if (!empty($_GET['code'])) {
    $state = (string) ($_GET['state'] ?? '');
    if (empty($_SESSION['youtube_oauth_state']) || !hash_equals($_SESSION['youtube_oauth_state'], $state)) {
        header('Location: connect-platforms.php?error=invalid_oauth_state');
        exit;
    }
    unset($_SESSION['youtube_oauth_state']);

    try {
        $token = oldora_http_json('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $_GET['code'],
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ], true);
        if (empty($token['access_token'])) throw new RuntimeException('YouTube returned no access token.');

        $channels = oldora_http_json('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&mine=true', [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json'
        ]);
        $channel = $channels['items'][0] ?? null;
        if (!$channel || empty($channel['id'])) throw new RuntimeException('No YouTube channel was found for this Google account.');
        $snippet = $channel['snippet'] ?? [];
        $stats = $channel['statistics'] ?? [];
        $thumbnails = $snippet['thumbnails'] ?? [];
        $picture = (string) ($thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? '');

        oldora_upsert_account($con, [
            'user_email' => $_SESSION['email'],
            'platform' => 'youtube',
            'access_token' => (string) $token['access_token'],
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (int) ($token['expires_in'] ?? 3600)),
            'channel_id' => (string) $channel['id'],
            'channel_name' => (string) ($snippet['title'] ?? 'YouTube channel'),
            'picture' => $picture,
            'subscribers' => (int) ($stats['subscriberCount'] ?? 0),
            'views' => (int) ($stats['viewCount'] ?? 0),
            'scopes' => (string) ($token['scope'] ?? 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly')
        ]);
        if (oldora_db_has_column($con, 'users', 'youtube_connected')) {
            $stmt = $con->prepare('UPDATE users SET youtube_connected = 1 WHERE email = ?');
            $stmt->bind_param('s', $_SESSION['email']);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: connect-platforms.php?status=youtube_connected');
        exit;
    } catch (Throwable $error) {
        oldora_log('oauth', 'YouTube connection failed', ['error' => $error->getMessage()]);
        $_SESSION['connection_error'] = $error->getMessage();
        header('Location: connect-platforms.php?error=youtube_failed');
        exit;
    }
}

$_SESSION['youtube_oauth_state'] = bin2hex(random_bytes(24));
$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
    'access_type' => 'offline',
    'prompt' => 'consent',
    'include_granted_scopes' => 'true',
    'state' => $_SESSION['youtube_oauth_state']
]);
header('Location: ' . $authUrl);
exit;

