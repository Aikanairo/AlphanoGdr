<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/runtime.inc.php';
$board=bacheca_legacy_board();
if (!empty($_POST['delThread'])) {
    $message=bacheca_require_message((int)($_POST['delID'] ?? 0));
    if ((int)$message['bacheca']!==$board) alphano_fail('Discussione non trovata.',404);
    EliminaThread((int)$message['id']);
}
bacheca_legacy_render($board);
