<?php

require_once __DIR__ . '/env.php';

/** Only curated provider settings may cross the public generation boundary. */
function oldora_content_option_choices(): array
{
    return [
        'language' => ['en' => 'English', 'ar' => 'العربية', 'ar-MA' => 'الدارجة المغربية', 'fr' => 'Français', 'es' => 'Español'],
        'tone' => ['educational' => 'Clear & educational', 'storytelling' => 'Storytelling', 'promotional' => 'Product showcase', 'inspirational' => 'Inspiring'],
        'visual_style' => ['cinematic' => 'Cinematic', 'natural' => 'Natural photography', 'minimal' => 'Clean & minimal', 'illustration' => 'Editorial illustration'],
        'image_aspect' => ['square' => 'Square · 1:1', 'portrait' => 'Portrait · 2:3 (download)', 'landscape' => 'Landscape · 3:2'],
        'voice' => ['auto' => 'Language default', 'feminine' => 'Feminine voice', 'masculine' => 'Masculine voice'],
        'voice_pace' => ['normal' => 'Natural', 'slow' => 'Relaxed', 'brisk' => 'Energetic'],
        'subtitle_style' => ['clean' => 'Clean captions', 'bold' => 'Bold captions', 'none' => 'No subtitles'],
        'music' => ['none' => 'Voice only', 'soft' => 'Soft background music']
    ];
}

function oldora_content_text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./us', $text);
}

function oldora_content_options(array $input, string $type): array
{
    if (!in_array($type, ['image', 'video'], true)) {
        throw new InvalidArgumentException('Choose image or video.');
    }
    $defaults = [
        'language' => 'en', 'tone' => 'educational', 'visual_style' => 'cinematic',
        'image_aspect' => 'square', 'voice' => 'auto', 'voice_pace' => 'normal',
        'subtitle_style' => 'clean', 'music' => 'none'
    ];
    $options = [];
    foreach (oldora_content_option_choices() as $key => $choices) {
        $value = $input[$key] ?? $defaults[$key];
        if (!is_string($value) || !array_key_exists($value, $choices)) {
            throw new InvalidArgumentException('Choose a valid ' . str_replace('_', ' ', $key) . '.');
        }
        $options[$key] = $value;
    }
    $audience = $input['audience'] ?? '';
    if (!is_string($audience) || preg_match('//u', $audience) !== 1 || oldora_content_text_length($audience) > 160) {
        throw new InvalidArgumentException('Audience must be 160 characters or fewer.');
    }
    $options['audience'] = trim($audience);
    $brandColor = $input['brand_color'] ?? '';
    if (!is_string($brandColor) || ($brandColor !== '' && !preg_match('/\A#[0-9a-fA-F]{6}\z/', $brandColor))) {
        throw new InvalidArgumentException('Brand color must be a six-digit hex color.');
    }
    $options['brand_color'] = strtoupper($brandColor);
    return $options;
}

function oldora_content_direction(array $options): string
{
    $tones = [
        'educational' => 'Explain one useful idea clearly with a concrete example. Avoid jargon and unsupported claims.',
        'storytelling' => 'Build a short narrative with a relatable opening, a clear turning point, and a satisfying ending.',
        'promotional' => 'Show a specific product benefit with honest, concrete details. Do not invent offers, testimonials, or results.',
        'inspirational' => 'Use an encouraging, grounded tone and a practical takeaway. Avoid exaggerated promises.'
    ];
    $styles = [
        'cinematic' => 'Cinematic composition, intentional soft lighting, restrained color grading and a clear focal subject.',
        'natural' => 'Believable natural light, authentic textures, realistic proportions and candid composition.',
        'minimal' => 'Simple composition, ample breathing room, a restrained palette and one clear visual message.',
        'illustration' => 'Editorial illustration, cohesive shapes, intentional color and a polished, consistent visual style.'
    ];
    $languages = ['en' => 'English', 'ar' => 'Modern Standard Arabic', 'ar-MA' => 'Moroccan Arabic (Darija)', 'fr' => 'French', 'es' => 'Spanish'];
    $direction = 'Content language: ' . $languages[$options['language']] . '. ' . $tones[$options['tone']] . ' ' . $styles[$options['visual_style']];
    if ($options['audience'] !== '') {
        $direction .= "\nIntended audience (creative context): " . $options['audience'];
    }
    if ($options['brand_color'] !== '') {
        $direction .= "\nUse " . $options['brand_color'] . ' as a restrained accent color, with readable contrast.';
    }
    return $direction;
}

function oldora_content_prompt(string $prompt, string $type, array $options = []): string
{
    $options = oldora_content_options($options, $type);
    $direction = oldora_content_direction($options);
    if ($type === 'image') {
        $direction .= "\nCreate one finished social image with crisp detail and a balanced composition. Keep the main subject inside a generous safe margin. Avoid unintended text, watermarks, logos and clutter. Include legible text only if the user's brief explicitly requests it, in the selected language.";
    } else {
        $direction .= "\nCreate a cohesive vertical short with an immediate visual hook, purposeful pacing and a clear ending. Keep the subject inside the central safe area and use coherent movement between shots.";
    }
    return $direction . "\n\nCreative brief:\n" . $prompt;
}

function oldora_content_image_payload(string $prompt, array $options = []): array
{
    $options = oldora_content_options($options, 'image');
    $sizes = ['portrait' => '1024x1536', 'square' => '1024x1024', 'landscape' => '1536x1024'];
    return ['prompt' => oldora_content_prompt($prompt, 'image', $options), 'size' => $sizes[$options['image_aspect']]];
}

function oldora_content_voice(array $options): string
{
    $voices = [
        'en' => ['en-US-AriaNeural', 'en-US-GuyNeural'],
        'ar' => ['ar-SA-ZariyahNeural', 'ar-SA-HamedNeural'],
        'ar-MA' => ['ar-MA-MounaNeural', 'ar-MA-OmarNeural'],
        'fr' => ['fr-FR-DeniseNeural', 'fr-FR-HenriNeural'],
        'es' => ['es-ES-ElviraNeural', 'es-ES-AlvaroNeural']
    ];
    return $voices[$options['language']][$options['voice'] === 'masculine' ? 1 : 0];
}
