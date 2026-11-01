<?php
declare(strict_types=1);

// ———————————————————————————
// MASSIMO DEBUG & ERROR HANDLING
// ———————————————————————————
function h(string|int|null $v): string {
    return htmlentities((string)$v, ENT_QUOTES, 'UTF-8');
}

// ———————————————————————————
// INCLUDI LIBRERIE
// ———————————————————————————
$root = dirname(__DIR__);
require_once "$root/libs/kernel.inc.php";
require_once "$root/libs/libgrafica.inc.php";
require_once "$root/libs/libpg.inc.php";
require_once "$root/libs/libtext.inc.php";
require_once "$root/libs/libposta.inc.php";
require_once "$root/libs/mail-presentation.inc.php";  // per PurgePosta se serve



// ———————————————————————————
// LOGIN & COLORI
// ———————————————————————————
$idpg    = alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
$db      = ConnectDB();


// ———————————————————————————
// RACCOLTA PARAMETRI
// ———————————————————————————
$msgId  = (int)($_POST['id']    ?? 0);
$inBaule= isset($_POST['baule']) ? (bool)$_POST['baule'] : false;
$delId  = (int)($_POST['delid'] ?? 0);

// ———————————————————————————
// CANCELLA MISSIVA (se richiesto)
// ———————————————————————————
if ($delId) {
    $d = $db->query('SELECT idmittente,iddestinatario FROM mail WHERE id = ?', [$delId])[0] ?? null;
    if (!$d) {
        header('Location: ./');
        exit;
    }
    $isMitt = ((int)$d['idmittente'] === $idpg);
    $isDest = ((int)$d['iddestinatario']=== $idpg);
    if ($isMitt && $isDest) {
        $db->query('DELETE FROM mail WHERE id = ?', [$delId]);
    } elseif ($isMitt) {
        $db->query("UPDATE mail SET delmitt=1 WHERE id=?", [$delId]);
    } elseif ($isDest) {
        $db->query("UPDATE mail SET deldest=1 WHERE id=?", [$delId]);
    }
    header('Location: ./');
    exit;
}

// ———————————————————————————
// CARICA MISSIVA
// ———————————————————————————
$row = $db->query('SELECT * FROM mail WHERE id = ?', [$msgId])[0] ?? null;
if (!$row) {
    alphano_fail('Messaggio non trovato.',404);
}
$isMitt = ((int)$row['idmittente'] === $idpg);
$isDest = ((int)$row['iddestinatario']=== $idpg);
if (!$isMitt && !$isDest) {
    alphano_fail('Non puoi accedere a questa missiva.',403);
}

$tradeId=0;
if((int)$row['idmittente']===0&&preg_match('/\[alphano-trade:(\d+):(\d+)\]/',(string)$row['messaggio'],$context)){
 $tradeId=(int)$context[1];$row['messaggio']=str_replace($context[0],'',(string)$row['messaggio']);
}
// Archive changes are explicit; merely opening a conversation preserves the folder.
$inBaule=(bool)$row['baule'];
if($isDest&&isset($_POST['baule'])&&in_array((string)$_POST['baule'],['0','1'],true)){
 $inBaule=(bool)(int)$_POST['baule'];$db->execute('UPDATE mail SET baule=? WHERE id=? AND iddestinatario=?',[(int)$inBaule,$msgId,$idpg]);
}
// segna come letto se destinatario
if ($isDest) {
    if ($inBaule) {
        $db->query('UPDATE mail SET baule=1,letto=1 WHERE id=?', [$msgId]);
    } else {
        $db->query('UPDATE mail SET letto=1 WHERE id=?', [$msgId]);
    }
}

// ———————————————————————————
// PREPARA DATI PER TITOLO & BODY
// ———————————————————————————
$otherId = $isDest ? (int)$row['idmittente'] : (int)$row['iddestinatario'];
$otherName = $otherId>0?GetPGFromID($otherId):'Alphano · Sistema';
$mailPeople=alphano_mail_people([(int)$row['idmittente'],(int)$row['iddestinatario']]);
$before=max(0,(int)($_POST['before']??0));
$conversation=alphano_mail_contact_conversation($row,$idpg,$before);
$hasEarlier=count($conversation)>80;if($hasEarlier)array_shift($conversation);
if(!$conversation)$conversation=[$row];
$readIds=[];foreach($conversation as $letter)if((int)$letter['iddestinatario']===$idpg&&!(int)$letter['letto'])$readIds[]=(int)$letter['id'];
if($readIds)$db->execute('UPDATE mail SET letto=1 WHERE iddestinatario=? AND id IN('.implode(',',array_fill(0,count($readIds),'?')).')',[$idpg,...$readIds]);
$replyToId=(int)$conversation[count($conversation)-1]['id'];
$readNoticeId=$readIds[0]??($isDest?$msgId:0);
$title = $otherId>0 ? "Conversazione con $otherName" : "Avviso di Alphano";

$mailActivePerson=$otherId;require __DIR__.'/../templates/mail/workspace.php';
ClosePage();
