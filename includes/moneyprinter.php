<?php

require_once __DIR__ . '/env.php';

function oldora_video_provider()
{
    $provider = strtolower(oldora_env('VIDEO_PROVIDER', 'openai'));
    if (!in_array($provider, ['openai', 'moneyprinterturbo'], true)) {
        throw new RuntimeException('VIDEO_PROVIDER must be openai or moneyprinterturbo.');
    }
    return $provider;
}

function oldora_moneyprinter_base_url()
{
    $base = rtrim(oldora_env('MONEYPRINTER_API_URL'), '/');
    $parts = parse_url($base);
    if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Configure MONEYPRINTER_API_URL with the private service base URL.');
    }
    return $base;
}

function oldora_moneyprinter_headers()
{
    $headers = ['Accept: application/json'];
    $key = oldora_env('MONEYPRINTER_API_KEY');
    if (strpbrk($key, "\r\n") !== false) throw new RuntimeException('Invalid MoneyPrinterTurbo API key.');
    if ($key !== '') $headers[] = 'x-api-key: ' . $key;
    return $headers;
}

function oldora_moneyprinter_request($path, $payload = null)
{
    $ch = curl_init(oldora_moneyprinter_base_url() . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(oldora_moneyprinter_headers(), ['Content-Type: application/json']),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = is_string($raw) ? json_decode($raw, true) : null;
    if ($raw === false || $status < 200 || $status >= 300 || !is_array($response)
        || (int) ($response['status'] ?? 0) !== 200 || !is_array($response['data'] ?? null)) {
        // Do not expose upstream messages or credentials to the browser.
        throw new RuntimeException('MoneyPrinterTurbo request failed (HTTP ' . $status . ').');
    }
    return $response['data'];
}

function oldora_moneyprinter_task_path($jobId)
{
    if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $jobId)) {
        throw new RuntimeException('Invalid MoneyPrinterTurbo task ID.');
    }
    return '/api/v1/tasks/' . rawurlencode($jobId);
}

function oldora_moneyprinter_start($prompt)
{
    $data = oldora_moneyprinter_request('/api/v1/videos', [
        'video_subject' => $prompt,
        'video_aspect' => '9:16',
        'video_count' => 1,
        'video_language' => oldora_env('MONEYPRINTER_VIDEO_LANGUAGE', 'en'),
        'voice_name' => oldora_env('MONEYPRINTER_VOICE', 'en-US-AriaNeural'),
        'video_source' => oldora_env('MONEYPRINTER_VIDEO_SOURCE', 'pexels'),
        'font_name' => oldora_env('MONEYPRINTER_FONT', 'MicrosoftYaHeiBold.ttc'),
        'subtitle_enabled' => true
    ]);
    $id = $data['task_id'] ?? '';
    if (!is_string($id) || $id === '') throw new RuntimeException('MoneyPrinterTurbo returned no task ID.');
    oldora_moneyprinter_task_path($id);
    return ['id' => $id, 'status' => 'queued', 'progress' => 0];
}

function oldora_moneyprinter_status($jobId)
{
    $data = oldora_moneyprinter_request(oldora_moneyprinter_task_path($jobId));
    $state = $data['state'] ?? null;
    if (!is_int($state) || !in_array($state, [-1, 1, 4], true)) {
        throw new RuntimeException('MoneyPrinterTurbo returned an unknown task state.');
    }
    return [
        'id' => $jobId,
        'status' => $state === 1 ? 'completed' : ($state === -1 ? 'failed' : 'in_progress'),
        'progress' => $state === 1 ? 100 : max(0, min(100, (int) ($data['progress'] ?? 0))),
        'error' => $state === -1 ? ['message' => 'Video generation failed in MoneyPrinterTurbo.'] : null,
        'videos' => $data['videos'] ?? []
    ];
}

function oldora_moneyprinter_download_url($url, $jobId)
{
    oldora_moneyprinter_task_path($jobId);
    $base = oldora_moneyprinter_base_url();
    // Accept only this task's MP4 on the configured service. Never follow an
    // upstream-supplied host, redirect, or traversal with our API credentials.
    if (str_starts_with($url, $base . '/')) $url = substr($url, strlen($base));
    if (!preg_match('~\A/tasks/' . preg_quote($jobId, '~') . '/[a-zA-Z0-9_-]+\.mp4\z~', $url)) {
        throw new RuntimeException('MoneyPrinterTurbo returned an invalid video location.');
    }
    return $base . $url;
}

function oldora_moneyprinter_download($jobId, $userId)
{
    $task = oldora_moneyprinter_status($jobId);
    if ($task['status'] !== 'completed' || !is_string($task['videos'][0] ?? null)) {
        throw new RuntimeException('MoneyPrinterTurbo video is not ready.');
    }
    $url = oldora_moneyprinter_download_url($task['videos'][0], $jobId);
    $filename = 'video-' . (int) $userId . '-' . bin2hex(random_bytes(12)) . '.mp4';
    $path = oldora_generated_directory() . '/' . $filename;
    $handle = fopen($path, 'xb');
    if (!$handle) throw new RuntimeException('Could not create the video file.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_HTTPHEADER => oldora_moneyprinter_headers(),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($handle);
    if (!$ok || $status !== 200 || filesize($path) < 10000) {
        unlink($path);
        throw new RuntimeException('Could not download the completed MoneyPrinterTurbo video.');
    }
    return ['path' => $path, 'url' => oldora_base_url() . '/uploads/generated/' . rawurlencode($filename)];
}
