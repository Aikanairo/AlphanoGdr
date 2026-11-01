<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/runtime.inc.php';
$board=bacheca_legacy_board();
$thread=(int)($_POST['id'] ?? $_GET['id'] ?? 0);
if (!$thread || !getDB()->query('SELECT id FROM bacheche WHERE id=? AND bacheca=? AND replyid=0',[$thread,$board])) alphano_fail('Discussione non trovata.',404);
if (!empty($_POST['delPost'])) {
    $post=bacheca_require_message((int)($_POST['delID'] ?? 0));
    if ((int)$post['bacheca']!==$board || (int)$post['replyid']!==$thread) alphano_fail('Risposta non trovata.',404);
    EliminaPost((int)$post['id']);
}
bacheca_legacy_render($board,$thread);
