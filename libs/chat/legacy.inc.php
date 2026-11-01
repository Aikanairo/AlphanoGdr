<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/kernel.inc.php';
function alphano_legacy_chat_key(mixed $legacy): string
{
    $key = $_GET['chat'] ?? $legacy ?? '';
    if (!is_string($key) || $key === '') alphano_fail('Seleziona un luogo dalla mappa.', 400);
    return $key;
}
