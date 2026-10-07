<?php

require_once __DIR__ . '/content.php';

// A remote write may have succeeded even if its response was lost.
class OldoraPublishUncertainException extends RuntimeException {}

function oldora_require_public_media_url(string $url): void
{
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost'
        || !str_contains($host, '.') || isset($parts['user']) || isset($parts['pass'])
        || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
        throw new RuntimeException('Publishing requires a public HTTPS media URL. Configure APP_URL before publishing.');
    }
}


if (!function_exists('oldora_refresh_youtube_token')) {
    function oldora_refresh_youtube_token($con, array $account)
    {
        $accessToken = (string) $account['access_token'];
        $expiresAt = !empty($account['expires_at']) ? strtotime($account['expires_at']) : 0;
        if ($expiresAt === 0 || $expiresAt > time() + 300) {
            return $accessToken;
        }
        if (empty($account['refresh_token'])) {
            throw new RuntimeException('YouTube authorization expired. Reconnect the channel.');
        }

        $data = oldora_http_json('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], [
            'client_id' => oldora_env('YOUTUBE_CLIENT_ID'),
            'client_secret' => oldora_env('YOUTUBE_CLIENT_SECRET'),
            'refresh_token' => $account['refresh_token'],
            'grant_type' => 'refresh_token'
        ], true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Could not refresh YouTube authorization.');
        }

        $newToken = (string) $data['access_token'];
        $newExpiry = gmdate('Y-m-d H:i:s', time() + (int) ($data['expires_in'] ?? 3600));
        $id = (int) $account['id'];
        $stmt = $con->prepare('UPDATE user_tokens SET access_token = ?, expires_at = ? WHERE id = ?');
        $stmt->bind_param('ssi', $newToken, $newExpiry, $id);
        $stmt->execute();
        $stmt->close();
        return $newToken;
    }
}

if (!function_exists('oldora_publish_youtube')) {
    function oldora_publish_youtube($con, array $account, array $content)
    {
        if ($content['media_type'] !== 'video') {
            throw new RuntimeException('YouTube publishing currently supports videos only.');
        }
        $path = (string) $content['asset_path'];
        if (!is_file($path) || filesize($path) < 10000) {
            throw new RuntimeException('Generated video file is missing.');
        }

        $token = oldora_refresh_youtube_token($con, $account);
        $size = filesize($path);
        $title = trim((string) (($content['title'] ?? '') ?: ($content['caption'] ?: $content['prompt'])));
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 95, 'UTF-8');
        } else {
            $title = substr($title, 0, 95);
        }
        $metadata = json_encode([
            'snippet' => [
                'title' => $title ?: 'OLDORA AI video',
                'description' => (string) (($content['description'] ?? '') ?: ($content['caption'] ?? '')),
                'categoryId' => oldora_env('YOUTUBE_CATEGORY_ID', '22')
            ],
            'status' => [
                'privacyStatus' => in_array((string) ($content['publish_privacy'] ?? ''), ['private', 'unlisted', 'public'], true)
                    ? (string) $content['publish_privacy']
                    : oldora_env('YOUTUBE_UPLOAD_PRIVACY', 'private'),
                'selfDeclaredMadeForKids' => false,
                'containsSyntheticMedia' => true
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $metadata,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Length: ' . $size,
                'X-Upload-Content-Type: video/mp4'
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || !in_array($status, [200, 201], true)) {
            throw new RuntimeException($error ?: oldora_api_error_message(substr((string) $raw, $headerSize), 'YouTube upload session failed.'));
        }

        $headers = substr($raw, 0, $headerSize);
        if (!preg_match('/^Location:\s*(.+)$/mi', $headers, $match)) {
            throw new RuntimeException('YouTube returned no upload URL.');
        }
        $uploadUrl = trim($match[1]);
        $handle = fopen($path, 'rb');
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $handle,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => ['Content-Type: video/mp4', 'Content-Length: ' . $size],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);
        if ($response === false || !in_array($status, [200, 201], true)) {
            throw new OldoraPublishUncertainException('YouTube upload could not be confirmed. Check the channel before retrying.');
        }
        $decoded = json_decode($response, true);
        if (empty($decoded['id'])) throw new OldoraPublishUncertainException('YouTube returned no video ID. Check the channel before retrying.');
        return (string) $decoded['id'];
    }
}

if (!function_exists('oldora_publish_instagram')) {
    function oldora_publish_instagram(array $account, array $content)
    {
        oldora_require_public_media_url((string) ($content['asset_url'] ?? ''));
        $graph = oldora_env('META_GRAPH_VERSION', 'v25.0');
        $igId = (string) $account['channel_id'];
        if ($igId === '') {
            throw new RuntimeException('Instagram account ID is missing. Reconnect the account.');
        }
        $caption = trim((string) ($content['caption'] ?? ''));
        $form = ['caption' => $caption, 'access_token' => $account['access_token']];
        if ($content['media_type'] === 'image') {
            if (!empty($content['asset_path']) && is_file($content['asset_path'])) {
                $image = @getimagesize($content['asset_path']);
                if (!$image || $image[2] !== IMAGETYPE_JPEG || filesize($content['asset_path']) > 8 * 1024 * 1024) {
                    throw new RuntimeException('Instagram requires a JPEG image smaller than 8 MB. Generate a new image in Studio.');
                }
            }
            $form['image_url'] = $content['asset_url'];
        } else {
            $form['media_type'] = 'REELS';
            $form['video_url'] = $content['asset_url'];
            $form['share_to_feed'] = 'true';
        }

        $container = oldora_http_json('POST', 'https://graph.facebook.com/' . rawurlencode($graph) . '/' . rawurlencode($igId) . '/media', ['Content-Type: application/x-www-form-urlencoded'], $form, true);
        $containerId = (string) ($container['id'] ?? '');
        if ($containerId === '') {
            throw new RuntimeException('Instagram media container was not created.');
        }

        if ($content['media_type'] === 'video') {
            return ['id' => $containerId, 'status' => 'submitted'];
        }
        return oldora_instagram_publish_container($account, $containerId);
    }
}

function oldora_instagram_publish_container(array $account, string $containerId): string
{
        $graph = oldora_env('META_GRAPH_VERSION', 'v25.0');
        $igId = (string) $account['channel_id'];
        try {
            $published = oldora_http_json('POST', 'https://graph.facebook.com/' . rawurlencode($graph) . '/' . rawurlencode($igId) . '/media_publish', ['Content-Type: application/x-www-form-urlencoded'], [
                'creation_id' => $containerId,
                'access_token' => $account['access_token']
            ], true);
        } catch (Throwable $error) {
            throw new OldoraPublishUncertainException('Instagram publishing could not be confirmed. Check the account before retrying.', 0, $error);
        }
        if (empty($published['id'])) {
            throw new OldoraPublishUncertainException('Instagram returned no media ID. Check the account before retrying.');
        }
        return (string) $published['id'];
}

function oldora_instagram_container_status(array $account, string $containerId): array
{
    $graph = oldora_env('META_GRAPH_VERSION', 'v25.0');
    $response = oldora_http_json('GET', 'https://graph.facebook.com/' . rawurlencode($graph) . '/' . rawurlencode($containerId)
        . '?fields=status_code&access_token=' . rawurlencode($account['access_token']));
    $status = (string) ($response['status_code'] ?? '');
    if ($status === 'IN_PROGRESS') return ['status' => 'submitted'];
    if ($status === 'FINISHED') return ['status' => 'published', 'id' => oldora_instagram_publish_container($account, $containerId)];
    if (in_array($status, ['ERROR', 'EXPIRED'], true)) return ['status' => 'failed', 'error' => 'Instagram could not process this video. Check its format and try again.'];
    if ($status === 'PUBLISHED') throw new OldoraPublishUncertainException('Instagram already published this container. Check the account before retrying.');
    throw new RuntimeException('Instagram publishing status is temporarily unavailable.');
}

function oldora_refresh_tiktok_account($con, array $account): array
{
        $expiresAt = !empty($account['expires_at']) ? strtotime($account['expires_at']) : 0;
        if ($expiresAt > 0 && $expiresAt <= time() + 300) {
            if (empty($account['refresh_token'])) {
                throw new RuntimeException('TikTok authorization expired. Reconnect the account.');
            }
            $tokens = oldora_http_json('POST', 'https://open.tiktokapis.com/v2/oauth/token/', ['Content-Type: application/x-www-form-urlencoded'], [
                'client_key' => oldora_env('TIKTOK_CLIENT_KEY'),
                'client_secret' => oldora_env('TIKTOK_CLIENT_SECRET'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account['refresh_token']
            ], true);
            if (empty($tokens['access_token'])) {
                throw new RuntimeException('Could not refresh TikTok authorization.');
            }
            $account['access_token'] = (string) $tokens['access_token'];
            $account['refresh_token'] = (string) ($tokens['refresh_token'] ?? $account['refresh_token']);
            $newAccessToken = $account['access_token'];
            $newRefreshToken = $account['refresh_token'];
            $newExpiry = gmdate('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 86400));
            $accountId = (int) $account['id'];
            $tokenStmt = $con->prepare('UPDATE user_tokens SET access_token = ?, refresh_token = ?, expires_at = ? WHERE id = ?');
            $tokenStmt->bind_param('sssi', $newAccessToken, $newRefreshToken, $newExpiry, $accountId);
            $tokenStmt->execute();
            $tokenStmt->close();
        }
    return $account;
}

if (!function_exists('oldora_publish_tiktok')) {
    function oldora_publish_tiktok($con, array $account, array $content, $privacyLevel)
    {
        if ($content['media_type'] !== 'video') {
            throw new RuntimeException('TikTok direct post currently supports generated videos only.');
        }
        oldora_require_public_media_url((string) ($content['asset_url'] ?? ''));
        $account = oldora_refresh_tiktok_account($con, $account);
        $creator = oldora_http_json('POST', 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/', [
            'Authorization: Bearer ' . $account['access_token'],
            'Content-Type: application/json; charset=UTF-8'
        ], new stdClass());
        $privacyOptions = $creator['data']['privacy_level_options'] ?? [];
        $privacy = $privacyLevel ?: oldora_env('TIKTOK_DEFAULT_PRIVACY', 'SELF_ONLY');
        if (!is_array($privacyOptions) || !in_array($privacy, $privacyOptions, true)) {
            throw new RuntimeException('The selected TikTok privacy setting is unavailable for this account. Choose an available setting before retrying.');
        }
        $title = trim((string) (($content['caption'] ?? '') ?: ($content['prompt'] ?? '')));
        $title = mb_substr($title, 0, 2200, 'UTF-8');
        $payload = [
            'post_info' => [
                'title' => $title,
                'privacy_level' => $privacy,
                'disable_duet' => false,
                'disable_comment' => false,
                'disable_stitch' => false,
                'brand_content_toggle' => false,
                'brand_organic_toggle' => false,
                'is_aigc' => true
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $content['asset_url']
            ]
        ];
        try {
            $response = oldora_http_json('POST', 'https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'Authorization: Bearer ' . $account['access_token'],
                'Content-Type: application/json; charset=UTF-8'
            ], $payload);
        } catch (Throwable $error) {
            throw new OldoraPublishUncertainException('TikTok publishing could not be confirmed. Check the account before retrying.', 0, $error);
        }
        $errorCode = (string) ($response['error']['code'] ?? 'unknown');
        if ($errorCode !== 'ok') {
            throw new RuntimeException('TikTok rejected the publishing request. Check account permissions and privacy settings.');
        }
        if (empty($response['data']['publish_id'])) {
            throw new OldoraPublishUncertainException('TikTok returned no publish ID. Check the account before retrying.');
        }
        return (string) $response['data']['publish_id'];
    }
}

if (!function_exists('oldora_publish_job')) {
    function oldora_publish_job($con, array $job)
    {
        $contentId = (int) $job['content_id'];
        $userId = (int) $job['user_id'];
        $tokenId = (int) $job['token_id'];
        $contentStmt = $con->prepare('SELECT * FROM content_items WHERE id = ? AND user_id = ? LIMIT 1');
        $contentStmt->bind_param('ii', $contentId, $userId);
        $contentStmt->execute();
        $content = $contentStmt->get_result()->fetch_assoc();
        $contentStmt->close();
        if (!$content || $content['status'] !== 'ready' || empty($content['asset_url'])) {
            throw new RuntimeException('Content is not ready to publish.');
        }
        $content['publish_privacy'] = (string) ($job['privacy_level'] ?? '');

        $tokenStmt = $con->prepare('SELECT * FROM user_tokens WHERE id = ? LIMIT 1');
        $tokenStmt->bind_param('i', $tokenId);
        $tokenStmt->execute();
        $account = $tokenStmt->get_result()->fetch_assoc();
        $tokenStmt->close();
        if (!$account) {
            throw new RuntimeException('Connected account no longer exists.');
        }

        $ownerStmt = $con->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $userId);
        $ownerStmt->execute();
        $owner = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        if (!$owner || !hash_equals((string) $owner['email'], (string) $account['user_email'])) {
            throw new RuntimeException('Connected account does not belong to this user.');
        }

        $platform = strtolower((string) $account['platform']);
        if ($platform === 'youtube') {
            return oldora_publish_youtube($con, $account, $content);
        }
        if ($platform === 'instagram') {
            return oldora_publish_instagram($account, $content);
        }
        if ($platform === 'tiktok') {
            return ['id' => oldora_publish_tiktok($con, $account, $content, $job['privacy_level'] ?? ''), 'status' => 'submitted'];
        }
        throw new RuntimeException('Unsupported publishing platform.');
    }
}

// Provider receipts confirm acceptance. Poll the saved receipt without resubmitting.
function oldora_get_publish_status($con, array $job): array
{
    $platform = strtolower((string) ($job['platform'] ?? ''));
    if (!in_array($platform, ['tiktok', 'instagram'], true) || empty($job['provider_publish_id'])) {
        throw new RuntimeException('No supported publishing receipt to check.');
    }
    $stmt = $con->prepare("SELECT t.* FROM user_tokens t INNER JOIN users u ON u.email = t.user_email
        WHERE t.id = ? AND u.id = ? AND t.platform = ? LIMIT 1");
    $stmt->bind_param('iis', $job['token_id'], $job['user_id'], $platform);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account) throw new RuntimeException('Reconnect the social account to check publication.');
    if ($platform === 'instagram') return oldora_instagram_container_status($account, (string) $job['provider_publish_id']);
    $account = oldora_refresh_tiktok_account($con, $account);
    $response = oldora_http_json('POST', 'https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
        'Authorization: Bearer ' . $account['access_token'],
        'Content-Type: application/json; charset=UTF-8'
    ], ['publish_id' => $job['provider_publish_id']]);
    if (($response['error']['code'] ?? '') !== 'ok') throw new RuntimeException('TikTok publishing status is temporarily unavailable.');
    $status = $response['data']['status'] ?? '';
    if ($status === 'PUBLISH_COMPLETE') return ['status' => 'published'];
    if ($status === 'FAILED') return ['status' => 'failed', 'error' => 'TikTok could not publish this video. Review platform restrictions and reconnect if necessary.'];
    if (in_array($status, ['PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD', 'SEND_TO_USER_INBOX'], true)) return ['status' => 'submitted'];
    throw new RuntimeException('TikTok returned an unknown publishing state.');
}
