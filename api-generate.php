<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/env.php';
oldora_json(['status' => 'error', 'message' => 'This demo endpoint was retired. Use Content Studio.', 'url' => 'studio.php'], 410);

