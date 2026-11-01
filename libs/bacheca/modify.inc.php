<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/runtime.inc.php';
$board=bacheca_legacy_board();
$id=(int)($_POST['id'] ?? $_GET['id'] ?? 0);
$original=bacheca_require_message($id);
if ((int)$original['bacheca']!==$board) alphano_fail('Messaggio non trovato.',404);
if ((int)$original['idpg']!==$idpg && !alphano_can('admin')) alphano_fail('Permesso negato.',403);
$thread=(int)($original['replyid'] ?: $original['id']);
if (($_POST['modifica'] ?? '')==='1') {
    if (!AggiornaMessaggio($id,(string)($_POST['oggetto'] ?? ''),(string)($_POST['messaggio'] ?? ''))) alphano_fail('Modifica non riuscita.',500);
    bacheca_legacy_render($board,$thread,message:'Messaggio modificato.');
} else {
    $editor=['title'=>'Modifica messaggio','board'=>$board,'thread'=>$thread,'id'=>$id,
        'subject'=>$original['oggetto'],'message'=>$original['messaggio'],'modify'=>true,'notify'=>false];
    require dirname(__DIR__,2).'/templates/forum/editor.php';
}
