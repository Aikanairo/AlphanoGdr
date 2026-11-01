<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/runtime.inc.php';
$board=bacheca_legacy_board();
$id=(int)($_POST['oldid'] ?? $_GET['oldid'] ?? 0);
$original=bacheca_require_message($id);
if ((int)$original['bacheca']!==$board) alphano_fail('Messaggio non trovato.',404);
$thread=(int)($original['replyid'] ?: $original['id']);
require_once dirname(__DIR__).'/profile-art.inc.php';
$history=GetStorico($id);
foreach($history as &$entry) $entry['portrait']=alphano_profile_portrait(array_merge($entry,['id'=>(int)$entry['idpg']]));
unset($entry);
require dirname(__DIR__,2).'/templates/forum/history.php';
