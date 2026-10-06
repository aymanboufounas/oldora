<?php
require_once __DIR__ . '/../includes/content.php';
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejected($callback, $message) {
    try { $callback(); } catch (RuntimeException $expected) { check(true, $message); return; }
    check(false, $message);
}
putenv('MONEYPRINTER_API_URL=http://127.0.0.1:8091');
putenv('VIDEO_PROVIDER=moneyprinterturbo');
check(oldora_video_provider() === 'moneyprinterturbo', 'provider selection');
check(oldora_moneyprinter_download_url('/tasks/job-1/final-1.mp4', 'job-1') === 'http://127.0.0.1:8091/tasks/job-1/final-1.mp4', 'relative download');
check(oldora_moneyprinter_download_url('http://127.0.0.1:8091/tasks/job-1/final-1.mp4', 'job-1') === 'http://127.0.0.1:8091/tasks/job-1/final-1.mp4', 'absolute download');
foreach (['https://attacker.example/tasks/job-1/final-1.mp4', '/tasks/job-2/final-1.mp4', '/tasks/job-1/../secret.mp4', '/tasks/job-1/%2e%2e.mp4', '//attacker.example/final.mp4', '/tasks/job-1/final.mp4?key=1'] as $url) {
    rejected(fn() => oldora_moneyprinter_download_url($url, 'job-1'), 'unsafe download URL');
}
rejected(fn() => oldora_moneyprinter_task_path('../secret'), 'invalid task id');
putenv('VIDEO_PROVIDER=invalid');
rejected(fn() => oldora_video_provider(), 'invalid provider');
putenv('VIDEO_PROVIDER=moneyprinterturbo');
$job = oldora_start_video('A short video about the ocean');
check($job['id'] === 'job-1' && $job['status'] === 'queued', 'task creation');
check(oldora_get_video_job('processing', 'moneyprinterturbo')['status'] === 'in_progress', 'processing state');
check(oldora_get_video_job('failed', 'moneyprinterturbo')['status'] === 'failed', 'failed state');
check(oldora_get_video_job('job-1', 'moneyprinterturbo')['progress'] === 100, 'completed state');
rejected(fn() => oldora_get_video_job('malformed', 'moneyprinterturbo'), 'malformed state');
rejected(fn() => oldora_get_video_job('missing', 'moneyprinterturbo'), 'missing task');
$asset = oldora_download_video('job-1', 1, 'moneyprinterturbo');
check(is_file($asset['path']) && filesize($asset['path']) > 10000, 'artifact saved');
unlink($asset['path']);
rejected(fn() => oldora_download_video('processing', 1, 'moneyprinterturbo'), 'unfinished download');
echo "$checks checks passed\n";
