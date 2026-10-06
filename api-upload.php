<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/env.php';
oldora_json(['status' => 'error', 'message' => 'Direct uploads moved to the verified publishing queue in Content Studio.', 'url' => 'studio.php'], 410);

