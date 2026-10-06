<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/env.php';
oldora_json(['status' => 'error', 'message' => 'The old order endpoint was retired because it did not generate media. Use Content Studio.', 'url' => 'studio.php'], 410);

