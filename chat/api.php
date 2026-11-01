<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
$action = (string)($_GET['action'] ?? '');
if (in_array($action, ['chat', 'addlchat', 'sendMessage'], true)) {
    require __DIR__ . '/messages.php';
} elseif ($action === 'control') {
    require __DIR__ . '/recordings.php';
} else {
    alphano_fail('Azione chat non valida.', 400, true);
}
