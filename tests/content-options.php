<?php

require_once __DIR__ . '/../includes/content_options.php';
require_once __DIR__ . '/../includes/moneyprinter.php';

$checks = 0;
function contentCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function contentReject(callable $callback, string $message): void
{
    try { $callback(); } catch (InvalidArgumentException $expected) { contentCheck(true, $message); return; }
    throw new RuntimeException($message);
}

$defaults = oldora_content_options([], 'image');
contentCheck($defaults['image_aspect'] === 'square', 'Default image format is compatible with Instagram.');
contentCheck(oldora_content_text_length('مرحبا') === 5, 'Arabic limits count characters, not UTF-8 bytes.');
foreach (oldora_content_option_choices() as $key => $choices) {
    contentReject(fn() => oldora_content_options([$key => '../untrusted'], 'video'), 'Reject unknown ' . $key);
    contentReject(fn() => oldora_content_options([$key => ['invalid']], 'video'), 'Reject array ' . $key);
}
contentReject(fn() => oldora_content_options(['audience' => str_repeat('a', 161)], 'image'), 'Reject excessive audience text.');
contentReject(fn() => oldora_content_options(['audience' => [1]], 'image'), 'Reject invalid audience type.');
contentReject(fn() => oldora_content_options(['audience' => "\xff"], 'image'), 'Reject invalid UTF-8.');
contentReject(fn() => oldora_content_options(['brand_color' => 'red'], 'image'), 'Reject unbounded color input.');
contentReject(fn() => oldora_content_options([], 'audio'), 'Reject unsupported media type.');
contentCheck(oldora_content_options(['brand_color' => '#a0b1c2'], 'image')['brand_color'] === '#A0B1C2', 'Normalize chosen accent.');
contentCheck(!array_key_exists('custom_audio_file', oldora_content_options(['custom_audio_file' => '/etc/passwd'], 'video')), 'Untrusted provider options cannot cross the boundary.');

foreach (['square' => '1024x1024', 'portrait' => '1024x1536', 'landscape' => '1536x1024'] as $aspect => $size) {
    $payload = oldora_content_image_payload('A cup beside a window', ['image_aspect' => $aspect, 'language' => 'ar', 'visual_style' => 'natural']);
    contentCheck($payload['size'] === $size, 'Image format maps to a supported provider size.');
    contentCheck(str_contains($payload['prompt'], 'Modern Standard Arabic') && str_contains($payload['prompt'], 'natural light') && str_contains($payload['prompt'], 'A cup beside a window'), 'Selected language, style and brief reach the provider.');
}
contentCheck(str_contains(oldora_content_prompt('A small shop', 'image', ['tone' => 'promotional']), 'Do not invent offers'), 'Promotional brief prohibits invented offers.');
contentCheck(str_contains(oldora_content_prompt('A runner', 'video', ['audience' => 'Beginners']), 'Beginners'), 'Audience context reaches the prompt.');

putenv('MONEYPRINTER_VIDEO_LANGUAGE=en');
putenv('MONEYPRINTER_VOICE=en-US-AriaNeural');
$payload = oldora_moneyprinter_payload('نص عن القهوة', ['language' => 'ar-MA', 'voice' => 'masculine', 'voice_pace' => 'slow', 'subtitle_style' => 'bold', 'brand_color' => '#123abc', 'music' => 'soft']);
contentCheck($payload['video_subject'] === 'نص عن القهوة', 'Keep the original topic separate from narration instructions.');
contentCheck($payload['voice_name'] === 'ar-MA-OmarNeural' && $payload['video_language'] === 'ar-MA', 'Moroccan Arabic narration uses its matching voice.');
contentCheck($payload['voice_rate'] === 0.9, 'Narration pace is constrained.');
contentCheck($payload['font_name'] === 'NotoSansArabic-Bold.ttf', 'Arabic selects an Arabic-capable subtitle font.');
contentCheck($payload['subtitle_enabled'] && $payload['text_fore_color'] === '#123ABC' && $payload['font_size'] === 64, 'Bold subtitle design uses the selected accent.');
contentCheck($payload['bgm_type'] === 'random' && $payload['bgm_volume'] === 0.12, 'Optional music stays quieter than narration.');
contentCheck($payload['video_count'] === 1 && $payload['video_aspect'] === '9:16', 'Generation stays within the existing credit and vertical-video contract.');
contentCheck($payload['match_materials_to_script'] === true && $payload['video_concat_mode'] === 'sequential', 'Footage follows narration order.');
contentCheck(str_contains($payload['custom_system_prompt'], 'Darija') && str_contains($payload['custom_system_prompt'], 'spoken narration'), 'The generated script receives language and pacing instructions.');
contentCheck(oldora_content_text_length($payload['custom_system_prompt']) <= 8000 && oldora_content_text_length($payload['video_script_prompt']) <= 2000, 'Prompt templates stay inside upstream limits.');
$quiet = oldora_moneyprinter_payload('A city', ['subtitle_style' => 'none', 'music' => 'none', 'language' => 'fr', 'voice' => 'feminine']);
contentCheck(!$quiet['subtitle_enabled'] && $quiet['bgm_type'] === '' && $quiet['bgm_volume'] === 0.0, 'No subtitle and music switches reach the provider.');
contentCheck($quiet['voice_name'] === 'fr-FR-DeniseNeural', 'Explicit content language overrides a mismatched configured voice.');
contentCheck(oldora_moneyprinter_payload('Legacy automation')['voice_name'] === 'en-US-AriaNeural', 'Existing configured default voice remains compatible.');

echo "$checks content quality checks passed\n";
