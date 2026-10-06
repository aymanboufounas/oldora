<?php

require_once __DIR__ . '/content.php';

if (!function_exists('oldora_ensure_youtube_watcher_schema')) {
    function oldora_ensure_youtube_watcher_schema($con)
    {
        oldora_ensure_content_schema($con);

        $con->query("CREATE TABLE IF NOT EXISTS youtube_watchers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            source_channel_id VARCHAR(100) NOT NULL,
            source_channel_name VARCHAR(255) NOT NULL,
            source_channel_input VARCHAR(500) NOT NULL,
            uploads_playlist_id VARCHAR(100) NOT NULL,
            destination_token_id BIGINT UNSIGNED NOT NULL,
            prompt_template TEXT NULL,
            voice_name VARCHAR(255) NULL,
            timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
            smart_times_json TEXT NULL,
            privacy_level VARCHAR(30) NOT NULL DEFAULT 'public',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_video_id VARCHAR(40) NULL,
            last_video_published_at DATETIME NULL,
            last_checked_at DATETIME NULL,
            last_error TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_watcher_route (user_id, source_channel_id, destination_token_id),
            KEY idx_watcher_due (is_active, last_checked_at),
            KEY idx_watcher_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $con->query("CREATE TABLE IF NOT EXISTS youtube_watcher_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            watcher_id BIGINT UNSIGNED NOT NULL,
            source_video_id VARCHAR(40) NOT NULL,
            source_title VARCHAR(500) NULL,
            content_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'processing',
            error_message TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_watcher_video (watcher_id, source_video_id),
            KEY idx_watcher_event_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'title' => 'VARCHAR(255) NULL',
            'description' => 'TEXT NULL',
            'source_url' => 'TEXT NULL'
        ] as $column => $definition) {
            if (!oldora_db_has_column($con, 'content_items', $column)) {
                $con->query("ALTER TABLE content_items ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
}

if (!function_exists('oldora_youtube_api_get')) {
    function oldora_youtube_api_get($resource, array $query, $accessToken = '')
    {
        $apiKey = trim((string) oldora_env('YOUTUBE_API_KEY'));
        $headers = [];
        if ($apiKey !== '') {
            $query['key'] = $apiKey;
        } elseif (trim((string) $accessToken) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim((string) $accessToken);
        } else {
            throw new RuntimeException('Add YOUTUBE_API_KEY to .env or reconnect your YouTube account.');
        }
        $url = 'https://www.googleapis.com/youtube/v3/' . ltrim((string) $resource, '/') . '?' . http_build_query($query);
        return oldora_http_json('GET', $url, $headers);
    }
}

if (!function_exists('oldora_resolve_youtube_channel')) {
    function oldora_resolve_youtube_channel($input, $accessToken = '')
    {
        $input = trim((string) $input);
        if ($input === '' || strlen($input) > 500) {
            throw new RuntimeException('Enter a valid YouTube channel URL, handle, or channel ID.');
        }

        $channelId = '';
        $handle = '';

        if (preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $input)) {
            $channelId = $input;
        } elseif (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]+)~i', $input, $match)) {
            $channelId = $match[1];
        } elseif (preg_match('~youtube\.com/@([^/?#]+)~i', $input, $match)) {
            $handle = '@' . ltrim($match[1], '@');
        } elseif (preg_match('/^@([A-Za-z0-9._-]{3,})$/', $input, $match)) {
            $handle = '@' . $match[1];
        } elseif (preg_match('/^[A-Za-z0-9._-]{3,}$/', $input)) {
            $handle = '@' . ltrim($input, '@');
        } else {
            throw new RuntimeException('Use a channel URL such as youtube.com/@handle or a UC channel ID.');
        }

        $query = ['part' => 'snippet,contentDetails', 'maxResults' => 1];
        if ($channelId !== '') {
            $query['id'] = $channelId;
        } else {
            $query['forHandle'] = $handle;
        }

        $response = oldora_youtube_api_get('channels', $query, $accessToken);
        $channel = $response['items'][0] ?? null;
        if (!$channel) {
            throw new RuntimeException('YouTube channel was not found.');
        }

        $uploads = (string) ($channel['contentDetails']['relatedPlaylists']['uploads'] ?? '');
        if ($uploads === '') {
            throw new RuntimeException('YouTube did not return the channel uploads playlist.');
        }

        return [
            'id' => (string) $channel['id'],
            'name' => (string) ($channel['snippet']['title'] ?? 'YouTube channel'),
            'handle' => (string) ($channel['snippet']['customUrl'] ?? $handle),
            'picture' => (string) ($channel['snippet']['thumbnails']['default']['url'] ?? ''),
            'uploads_playlist_id' => $uploads
        ];
    }
}

if (!function_exists('oldora_youtube_uploads')) {
    function oldora_youtube_uploads($playlistId, $limit = 5, $accessToken = '')
    {
        $response = oldora_youtube_api_get('playlistItems', [
            'part' => 'snippet,contentDetails',
            'playlistId' => (string) $playlistId,
            'maxResults' => max(1, min(10, (int) $limit))
        ], $accessToken);

        $videos = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            $videoId = (string) ($item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? '');
            if ($videoId === '') {
                continue;
            }
            $videos[] = [
                'id' => $videoId,
                'title' => trim((string) ($item['snippet']['title'] ?? 'New YouTube video')),
                'description' => trim((string) ($item['snippet']['description'] ?? '')),
                'published_at' => (string) ($item['contentDetails']['videoPublishedAt'] ?? $item['snippet']['publishedAt'] ?? ''),
                'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId)
            ];
        }
        return $videos;
    }
}

if (!function_exists('oldora_watcher_best_time')) {
    function oldora_watcher_best_time($timezoneName, $timesJson)
    {
        try {
            $timezone = new DateTimeZone((string) $timezoneName);
        } catch (Throwable $ignored) {
            $timezone = new DateTimeZone('UTC');
        }

        $times = json_decode((string) $timesJson, true);
        if (!is_array($times) || !$times) {
            $times = ['12:30', '18:30', '21:00'];
        }

        $validTimes = [];
        foreach ($times as $time) {
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $time)) {
                $validTimes[] = (string) $time;
            }
        }
        if (!$validTimes) {
            $validTimes = ['18:30'];
        }
        sort($validTimes);

        $now = new DateTimeImmutable('now', $timezone);
        $minimum = $now->modify('+30 minutes');
        for ($day = 0; $day < 8; $day++) {
            $date = $now->modify('+' . $day . ' days')->format('Y-m-d');
            foreach ($validTimes as $time) {
                $candidate = new DateTimeImmutable($date . ' ' . $time . ':00', $timezone);
                if ($candidate >= $minimum) {
                    return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        }
        return gmdate('Y-m-d H:i:s', time() + 3600);
    }
}

if (!function_exists('oldora_short_package')) {
    function oldora_short_package(array $video, array $watcher)
    {
        $sourceDescription = function_exists('mb_substr')
            ? mb_substr((string) $video['description'], 0, 1800, 'UTF-8')
            : substr((string) $video['description'], 0, 1800);
        $direction = trim((string) ($watcher['prompt_template'] ?? ''));
        $voice = trim((string) ($watcher['voice_name'] ?? ''));

        $fallbackTitle = trim((string) $video['title']);
        if (function_exists('mb_substr')) {
            $fallbackTitle = mb_substr($fallbackTitle, 0, 82, 'UTF-8');
        } else {
            $fallbackTitle = substr($fallbackTitle, 0, 82);
        }
        $fallback = [
            'title' => ($fallbackTitle ?: 'New story') . ' #Shorts',
            'description' => "An original AI-generated short inspired by the topic: " . (string) $video['title'] . "\n\n#Shorts #AI #Trending",
            'video_prompt' => "Create an original 9:16 vertical short video inspired only by this topic: " . (string) $video['title'] . ". Do not copy people, footage, logos, dialogue, or copyrighted visuals from the source. Use a strong first-second hook, fast cinematic cuts, readable on-screen captions, an original visual concept, and a satisfying ending. " . ($voice !== '' ? "Narration style: {$voice}. " : '') . ($direction !== '' ? "Creator direction: {$direction}." : '')
        ];

        try {
            $request = [
                'model' => oldora_env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
                'input' => [
                    ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => 'You create original YouTube Shorts packages. Never copy source footage, protected characters, logos, or dialogue. Return concise JSON only.']]],
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => "Source title: {$video['title']}\nSource description: {$sourceDescription}\nCreator direction: {$direction}\nVoice style: {$voice}\nCreate a high-retention original vertical-video prompt, a title under 95 characters ending with #Shorts, and a natural YouTube description with 3-5 relevant hashtags."]]]
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'short_package',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'video_prompt' => ['type' => 'string']
                            ],
                            'required' => ['title', 'description', 'video_prompt'],
                            'additionalProperties' => false
                        ]
                    ]
                ]
            ];
            $response = oldora_http_json('POST', 'https://api.openai.com/v1/responses', oldora_openai_headers(true), $request);
            $text = '';
            foreach ((array) ($response['output'] ?? []) as $output) {
                foreach ((array) ($output['content'] ?? []) as $content) {
                    if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                        $text .= (string) $content['text'];
                    }
                }
            }
            $package = json_decode($text, true);
            if (is_array($package) && !empty($package['title']) && !empty($package['description']) && !empty($package['video_prompt'])) {
                return $package;
            }
        } catch (Throwable $error) {
            oldora_log('youtube-watcher', 'Metadata generation fallback', ['error' => $error->getMessage()]);
        }
        return $fallback;
    }
}

if (!function_exists('oldora_process_youtube_watcher')) {
    function oldora_process_youtube_watcher($con, array $watcher)
    {
        $accessToken = '';
        $tokenStmt = $con->prepare('SELECT * FROM user_tokens WHERE id = ? LIMIT 1');
        $tokenStmt->bind_param('i', $watcher['destination_token_id']);
        $tokenStmt->execute();
        $apiAccount = $tokenStmt->get_result()->fetch_assoc();
        $tokenStmt->close();
        if ($apiAccount) {
            $accessToken = function_exists('oldora_refresh_youtube_token')
                ? oldora_refresh_youtube_token($con, $apiAccount)
                : (string) ($apiAccount['access_token'] ?? '');
        }
        $videos = oldora_youtube_uploads($watcher['uploads_playlist_id'], 5, $accessToken);
        if (!$videos) {
            throw new RuntimeException('No public uploads were found for this channel.');
        }

        $latest = $videos[0];
        if (empty($watcher['last_video_id'])) {
            $stmt = $con->prepare('UPDATE youtube_watchers SET last_video_id = ?, last_video_published_at = ?, last_checked_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?');
            $published = $latest['published_at'] !== '' ? gmdate('Y-m-d H:i:s', strtotime($latest['published_at'])) : null;
            $stmt->bind_param('ssi', $latest['id'], $published, $watcher['id']);
            $stmt->execute();
            $stmt->close();
            return 'baseline';
        }
        if (hash_equals((string) $watcher['last_video_id'], (string) $latest['id'])) {
            $stmt = $con->prepare('UPDATE youtube_watchers SET last_checked_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?');
            $stmt->bind_param('i', $watcher['id']);
            $stmt->execute();
            $stmt->close();
            return 'unchanged';
        }

        $unseen = [];
        foreach ($videos as $candidate) {
            if (hash_equals((string) $watcher['last_video_id'], (string) $candidate['id'])) {
                break;
            }
            $unseen[] = $candidate;
        }
        if ($unseen) {
            $latest = $unseen[count($unseen) - 1];
        }

        $package = oldora_short_package($latest, $watcher);
        $creditCost = max(1, (int) oldora_env('VIDEO_CREDIT_COST', '5'));
        $contentId = 0;
        $eventId = 0;

        $con->begin_transaction();
        try {
            $event = $con->prepare("INSERT IGNORE INTO youtube_watcher_events (watcher_id, source_video_id, source_title, status) VALUES (?, ?, ?, 'processing')");
            $event->bind_param('iss', $watcher['id'], $latest['id'], $latest['title']);
            $event->execute();
            if ($event->affected_rows !== 1) {
                $event->close();
                $con->rollback();
                $skip = $con->prepare('UPDATE youtube_watchers SET last_video_id = ?, last_checked_at = UTC_TIMESTAMP() WHERE id = ?');
                $skip->bind_param('si', $latest['id'], $watcher['id']);
                $skip->execute();
                $skip->close();
                return 'duplicate';
            }
            $eventId = (int) $event->insert_id;
            $event->close();

            $reserve = $con->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $reserve->bind_param('iii', $creditCost, $watcher['user_id'], $creditCost);
            $reserve->execute();
            if ($reserve->affected_rows !== 1) {
                throw new RuntimeException('Not enough credits for automatic Short generation.');
            }
            $reserve->close();

            $status = 'starting';
            $provider = oldora_video_provider();
            $mediaType = 'video';
            $insert = $con->prepare('INSERT INTO content_items (user_id, media_type, prompt, caption, title, description, source_url, status, provider, credits_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->bind_param('issssssssi', $watcher['user_id'], $mediaType, $package['video_prompt'], $package['description'], $package['title'], $package['description'], $latest['url'], $status, $provider, $creditCost);
            $insert->execute();
            $contentId = (int) $insert->insert_id;
            $insert->close();

            $link = $con->prepare('UPDATE youtube_watcher_events SET content_id = ? WHERE id = ?');
            $link->bind_param('ii', $contentId, $eventId);
            $link->execute();
            $link->close();
            $con->commit();
        } catch (Throwable $error) {
            $con->rollback();
            throw $error;
        }

        try {
            $video = oldora_start_video($package['video_prompt'], $provider);
            $remoteStatus = (string) ($video['status'] ?? 'queued');
            if (!in_array($remoteStatus, ['queued', 'in_progress'], true)) {
                $remoteStatus = 'processing';
            }
            $jobId = (string) $video['id'];
            $progress = (int) ($video['progress'] ?? 0);
            $update = $con->prepare('UPDATE content_items SET status = ?, provider_job_id = ?, progress = ? WHERE id = ?');
            $update->bind_param('ssii', $remoteStatus, $jobId, $progress, $contentId);
            $update->execute();
            $update->close();

            $scheduledAt = oldora_watcher_best_time($watcher['timezone'], $watcher['smart_times_json']);
            $platform = 'youtube';
            $queueStatus = 'waiting_media';
            $queue = $con->prepare('INSERT IGNORE INTO publish_jobs (content_id, user_id, token_id, platform, status, scheduled_at, privacy_level) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $queue->bind_param('iiissss', $contentId, $watcher['user_id'], $watcher['destination_token_id'], $platform, $queueStatus, $scheduledAt, $watcher['privacy_level']);
            $queue->execute();
            $queue->close();

            $published = $latest['published_at'] !== '' ? gmdate('Y-m-d H:i:s', strtotime($latest['published_at'])) : null;
            $done = $con->prepare("UPDATE youtube_watchers SET last_video_id = ?, last_video_published_at = ?, last_checked_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?");
            $done->bind_param('ssi', $latest['id'], $published, $watcher['id']);
            $done->execute();
            $done->close();
            $eventDone = $con->prepare("UPDATE youtube_watcher_events SET status = 'generated' WHERE id = ?");
            $eventDone->bind_param('i', $eventId);
            $eventDone->execute();
            $eventDone->close();
            return 'created';
        } catch (Throwable $error) {
            $message = function_exists('mb_substr') ? mb_substr($error->getMessage(), 0, 1000, 'UTF-8') : substr($error->getMessage(), 0, 1000);
            $con->begin_transaction();
            try {
                $failed = $con->prepare("UPDATE content_items SET status = 'failed', error_message = ? WHERE id = ?");
                $failed->bind_param('si', $message, $contentId);
                $failed->execute();
                $failed->close();
                $refund = $con->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
                $refund->bind_param('ii', $creditCost, $watcher['user_id']);
                $refund->execute();
                $refund->close();
                $eventFailed = $con->prepare("UPDATE youtube_watcher_events SET status = 'failed', error_message = ? WHERE id = ?");
                $eventFailed->bind_param('si', $message, $eventId);
                $eventFailed->execute();
                $eventFailed->close();
                $con->commit();
            } catch (Throwable $refundError) {
                $con->rollback();
            }
            throw $error;
        }
    }
}

if (!function_exists('oldora_process_youtube_watchers')) {
    function oldora_process_youtube_watchers($con, $limit = 2)
    {
        oldora_ensure_youtube_watcher_schema($con);
        $limit = max(1, min(10, (int) $limit));
        $result = $con->query("SELECT * FROM youtube_watchers WHERE is_active = 1 AND (last_checked_at IS NULL OR last_checked_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)) ORDER BY COALESCE(last_checked_at, '1970-01-01') ASC LIMIT {$limit}");
        $summary = ['checked' => 0, 'created' => 0, 'errors' => 0];
        while ($watcher = $result->fetch_assoc()) {
            $summary['checked']++;
            try {
                $state = oldora_process_youtube_watcher($con, $watcher);
                if ($state === 'created') {
                    $summary['created']++;
                }
            } catch (Throwable $error) {
                $summary['errors']++;
                $message = function_exists('mb_substr') ? mb_substr($error->getMessage(), 0, 1000, 'UTF-8') : substr($error->getMessage(), 0, 1000);
                $stmt = $con->prepare('UPDATE youtube_watchers SET last_checked_at = UTC_TIMESTAMP(), last_error = ? WHERE id = ?');
                $stmt->bind_param('si', $message, $watcher['id']);
                $stmt->execute();
                $stmt->close();
                oldora_log('youtube-watcher', 'Watcher failed', ['watcher_id' => $watcher['id'], 'error' => $message]);
            }
        }
        return $summary;
    }
}
