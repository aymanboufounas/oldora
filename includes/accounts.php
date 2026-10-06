<?php

require_once __DIR__ . '/env.php';

if (!function_exists('oldora_db_has_column')) {
    function oldora_db_has_column($con, $table, $column)
    {
        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
        $safeColumn = $con->real_escape_string($column);
        $result = $con->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('oldora_ensure_accounts_schema')) {
    function oldora_ensure_accounts_schema($con)
    {
        $con->query("CREATE TABLE IF NOT EXISTS user_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_email VARCHAR(255) NOT NULL,
            platform VARCHAR(30) NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NULL,
            expires_at DATETIME NULL,
            channel_id VARCHAR(255) NULL,
            channel_name VARCHAR(255) NULL,
            picture TEXT NULL,
            channel_pic TEXT NULL,
            subscribers BIGINT NOT NULL DEFAULT 0,
            views BIGINT NOT NULL DEFAULT 0,
            scopes TEXT NULL,
            metadata_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_tokens_owner (user_email, platform)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [
            'channel_id' => 'VARCHAR(255) NULL',
            'channel_name' => 'VARCHAR(255) NULL',
            'picture' => 'TEXT NULL',
            'channel_pic' => 'TEXT NULL',
            'subscribers' => 'BIGINT NOT NULL DEFAULT 0',
            'views' => 'BIGINT NOT NULL DEFAULT 0',
            'scopes' => 'TEXT NULL',
            'metadata_json' => 'LONGTEXT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'
        ];

        foreach ($columns as $name => $definition) {
            if (!oldora_db_has_column($con, 'user_tokens', $name)) {
                $con->query("ALTER TABLE user_tokens ADD COLUMN `{$name}` {$definition}");
            }
        }

        $index = $con->query("SHOW INDEX FROM user_tokens WHERE Key_name = 'uq_user_platform_channel'");
        if (!$index || $index->num_rows === 0) {
            $duplicates = $con->query("SELECT user_email, platform, channel_id FROM user_tokens WHERE channel_id IS NOT NULL AND channel_id <> '' GROUP BY user_email, platform, channel_id HAVING COUNT(*) > 1 LIMIT 1");
            if ($duplicates && $duplicates->num_rows === 0) {
                try {
                    $con->query('ALTER TABLE user_tokens ADD UNIQUE KEY uq_user_platform_channel (user_email, platform, channel_id)');
                } catch (Throwable $ignored) {
                }
            }
        }
    }
}

if (!function_exists('oldora_http_json')) {
    function oldora_http_json($method, $url, array $headers = [], $body = null, $form = false)
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers
        ];
        if (strtoupper($method) !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $form ? http_build_query($body) : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException($error ?: 'Remote API connection failed.');
        }
        $json = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($json) ? ($json['error']['message'] ?? $json['error_description'] ?? $json['message'] ?? 'Remote API request failed.') : 'Remote API request failed.';
            throw new RuntimeException((string) $message);
        }
        if (!is_array($json)) {
            throw new RuntimeException('Remote API returned invalid JSON.');
        }
        return $json;
    }
}

if (!function_exists('oldora_upsert_account')) {
    function oldora_upsert_account($con, array $account)
    {
        oldora_ensure_accounts_schema($con);
        $email = (string) $account['user_email'];
        $platform = strtolower((string) $account['platform']);
        $channelId = (string) $account['channel_id'];

        $find = $con->prepare('SELECT id FROM user_tokens WHERE user_email = ? AND platform = ? AND channel_id = ? LIMIT 1');
        $find->bind_param('sss', $email, $platform, $channelId);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();

        $accessToken = (string) ($account['access_token'] ?? '');
        $refreshToken = (string) ($account['refresh_token'] ?? '');
        $expiresAt = $account['expires_at'] ?? null;
        $channelName = (string) ($account['channel_name'] ?? ucfirst($platform) . ' account');
        $picture = (string) ($account['picture'] ?? '');
        $subscribers = (int) ($account['subscribers'] ?? 0);
        $views = (int) ($account['views'] ?? 0);
        $scopes = (string) ($account['scopes'] ?? '');
        $metadata = json_encode($account['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $id = (int) $existing['id'];
            $stmt = $con->prepare('UPDATE user_tokens SET access_token = ?, refresh_token = CASE WHEN ? = \'\' THEN refresh_token ELSE ? END, expires_at = ?, channel_name = ?, picture = ?, channel_pic = ?, subscribers = ?, views = ?, scopes = ?, metadata_json = ? WHERE id = ?');
            $stmt->bind_param('sssssssiissi', $accessToken, $refreshToken, $refreshToken, $expiresAt, $channelName, $picture, $picture, $subscribers, $views, $scopes, $metadata, $id);
        } else {
            $stmt = $con->prepare('INSERT INTO user_tokens (user_email, platform, access_token, refresh_token, expires_at, channel_id, channel_name, picture, channel_pic, subscribers, views, scopes, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssssssiiss', $email, $platform, $accessToken, $refreshToken, $expiresAt, $channelId, $channelName, $picture, $picture, $subscribers, $views, $scopes, $metadata);
        }
        $stmt->execute();
        $id = $existing ? (int) $existing['id'] : (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }
}
