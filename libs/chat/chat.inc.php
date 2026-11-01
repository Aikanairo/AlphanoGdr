<?php
declare(strict_types=1);
require_once __DIR__ . '/legacy.inc.php';
$_GET['chat'] = alphano_legacy_chat_key($chat ?? null);
require dirname(__DIR__, 2) . '/chat/index.php';
