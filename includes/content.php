<?php

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/content_options.php';
require_once __DIR__ . '/moneyprinter.php';

if (!function_exists('oldora_ensure_content_schema')) {
    function oldora_ensure_content_schema($con)
    {
        oldora_ensure_accounts_schema($con);
        $con->query("CREATE TABLE IF NOT EXISTS content_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            media_type VARCHAR(20) NOT NULL,
            prompt TEXT NOT NULL,
            caption TEXT NULL,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            source_url TEXT NULL,
            generation_options TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'starting',
            provider VARCHAR(40) NOT NULL DEFAULT 'openai',
            provider_job_id VARCHAR(150) NULL,
            asset_url TEXT NULL,
            asset_path TEXT NULL,
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            credits_used INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_content_user_created (user_id, created_at),
            KEY idx_content_provider_status (provider, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'title' => 'VARCHAR(255) NULL',
            'description' => 'TEXT NULL',
            'source_url' => 'TEXT NULL',
            'generation_options' => 'TEXT NULL'
        ] as $column => $definition) {
            if (!oldora_db_has_column($con, 'content_items', $column)) {
                try {
                    $con->query("ALTER TABLE content_items ADD COLUMN `{$column}` {$definition}");
                } catch (mysqli_sql_exception $error) {
                    if ($error->getCode() !== 1060 || !oldora_db_has_column($con, 'content_items', $column)) throw $error;
                }
            }
        }

        $con->query("CREATE TABLE IF NOT EXISTS publish_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            content_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            token_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'waiting_media',
            scheduled_at DATETIME NOT NULL,
            privacy_level VARCHAR(60) NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            provider_publish_id VARCHAR(255) NULL,
            last_error TEXT NULL,
            published_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_content_account (content_id, token_id),
            KEY idx_publish_due (status, scheduled_at),
            KEY idx_publish_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('oldora_openai_headers')) {
    function oldora_openai_headers($json = true)
    {
        $key = oldora_env('OPENAI_API_KEY');
        if ($key === '') $key = oldora_env('VIDEO_LLM_KEY');
        if ($key === '') {
            throw new RuntimeException('An OpenAI API key is missing. Configure OPENAI_API_KEY or VIDEO_LLM_KEY.');
        }
        $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }
        return $headers;
    }
}

if (!function_exists('oldora_generated_directory')) {
    function oldora_generated_directory()
    {
        $directory = dirname(__DIR__) . '/uploads/generated';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Generated media directory is not writable.');
        }
        return $directory;
    }
}

if (!function_exists('oldora_api_error_message')) {
    function oldora_api_error_message($raw, $fallback)
    {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $fallback);
        }
        return $fallback;
    }
}

if (!function_exists('oldora_generate_image')) {
    function oldora_generate_image($prompt, $userId, array $options = [])
    {
        $payload = [
            'model' => oldora_env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
            'prompt' => $prompt,
            'n' => 1,
            'size' => oldora_env('OPENAI_IMAGE_SIZE', '1024x1536'),
            'quality' => oldora_env('OPENAI_IMAGE_QUALITY', 'medium'),
            'output_format' => 'jpeg',
            'output_compression' => 90
        ];

        $payload = array_merge($payload, oldora_content_image_payload($prompt, $options));
        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => oldora_openai_headers(true),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($raw === false ? $error : oldora_api_error_message($raw, 'Image generation failed.'));
        }
        $data = json_decode($raw, true);
        $base64 = $data['data'][0]['b64_json'] ?? '';
        $bytes = $base64 !== '' ? base64_decode($base64, true) : false;
        if ($bytes === false || strlen($bytes) < 1000) {
            throw new RuntimeException('Image API returned no usable image.');
        }
        $image = @getimagesizefromstring($bytes);
        if (!$image || $image[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('Image API returned an unsupported image format. Please try again.');
        }

        $filename = 'image-' . (int) $userId . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.jpg';
        $path = oldora_generated_directory() . '/' . $filename;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Could not save the generated image.');
        }
        return ['path' => $path, 'url' => oldora_base_url() . '/uploads/generated/' . rawurlencode($filename)];
    }
}

if (!function_exists('oldora_start_video')) {
    function oldora_start_video($prompt, $provider = null, array $options = [])
    {
        $provider = $provider ?? oldora_video_provider();
        if ($provider === 'moneyprinterturbo') {
            return oldora_moneyprinter_start($prompt, $options);
        }
        if ($provider !== 'openai') throw new RuntimeException('Unsupported video provider.');
        $fields = [
            'model' => oldora_env('OPENAI_VIDEO_MODEL', 'sora-2'),
            'prompt' => oldora_content_prompt($prompt, 'video', $options),
            'size' => oldora_env('OPENAI_VIDEO_SIZE', '720x1280'),
            'seconds' => oldora_env('OPENAI_VIDEO_SECONDS', '8')
        ];
        $ch = curl_init('https://api.openai.com/v1/videos');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => oldora_openai_headers(false),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($raw === false ? $error : oldora_api_error_message($raw, 'Video generation failed to start.'));
        }
        $data = json_decode($raw, true);
        if (empty($data['id'])) {
            throw new RuntimeException('Video API returned no job ID.');
        }
        return $data;
    }
}

if (!function_exists('oldora_get_video_job')) {
    function oldora_get_video_job($jobId, $provider = 'openai')
    {
        if ($provider === 'moneyprinterturbo') return oldora_moneyprinter_status($jobId);
        if ($provider !== 'openai') throw new RuntimeException('Unsupported video provider.');
        $ch = curl_init('https://api.openai.com/v1/videos/' . rawurlencode($jobId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => oldora_openai_headers(false),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($raw === false ? $error : oldora_api_error_message($raw, 'Could not read video status.'));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Video API returned invalid status data.');
        }
        return $data;
    }
}

if (!function_exists('oldora_download_video')) {
    function oldora_download_video($jobId, $userId, $provider = 'openai')
    {
        if ($provider === 'moneyprinterturbo') return oldora_moneyprinter_download($jobId, $userId);
        if ($provider !== 'openai') throw new RuntimeException('Unsupported video provider.');
        $filename = 'video-' . (int) $userId . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.mp4';
        $path = oldora_generated_directory() . '/' . $filename;
        $handle = fopen($path, 'wb');
        if (!$handle) {
            throw new RuntimeException('Could not create the video file.');
        }

        $ch = curl_init('https://api.openai.com/v1/videos/' . rawurlencode($jobId) . '/content');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => oldora_openai_headers(false),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($handle);

        if (!$ok || $status < 200 || $status >= 300 || !is_file($path) || filesize($path) < 10000) {
            @unlink($path);
            throw new RuntimeException($error ?: 'Could not download the completed video.');
        }
        return ['path' => $path, 'url' => oldora_base_url() . '/uploads/generated/' . rawurlencode($filename)];
    }
}
