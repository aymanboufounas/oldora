<?php
require_once __DIR__ . '/includes/env.php';
oldora_json(['status' => 'error', 'message' => 'This endpoint was disabled because it exposed connected-account session data.'], 410);

