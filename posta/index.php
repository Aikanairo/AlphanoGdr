<?php
declare(strict_types=1);

// ———————————————————————————
// MASSIMO DEBUG
// ———————————————————————————
function h(string|int|null $v): string {
    return htmlentities((string)$v, ENT_QUOTES, 'UTF-8');
}

// ———————————————————————————
// 1) INCLUDI LIBRERIE
// ———————————————————————————
$root = dirname(__DIR__);
require_once "$root/libs/kernel.inc.php";
require_once "$root/libs/libgrafica.inc.php";
require_once "$root/libs/libpg.inc.php";
require_once "$root/libs/libposta.inc.php";
require_once "$root/libs/mail-presentation.inc.php";






// ———————————————————————————
// 2) LOGIN & DB
// ———————————————————————————
$pgid      = alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
$db        = ConnectDB();





if (IsAdmin($pgid)) {
    EnsureAdmin();
}

// Pulisce la casella di posta secondo le regole definite in libposta.inc.php
PurgePosta($pgid);

// ———————————————————————————
// 3) CARICO I MESSAGGI
// ———————————————————————————
$mail = [0=>[],1=>[],2=>[]];
$mittenti = [];
$noRead = 0;

// ricevuti (baule = 0) e baule (baule = 1)
foreach ($db->query(
    'SELECT * FROM mail WHERE iddestinatario = ? AND deldest = 0',
    [$pgid]
) as $d) {
    if (!$d['baule'] && !$d['letto']) {
        $noRead++;
    }
    $mittenti[] = $d['idmittente'];
    $mail[(int)$d['baule']][] = $d;
}

// inviati
foreach ($db->query(
    'SELECT * FROM mail WHERE idmittente = ? AND delmitt = 0',
    [$pgid]
) as $d) {
    $mittenti[] = $d['iddestinatario'];
    $mail[2][]  = $d;
}

$names=[$pgid=>GetPGFromID($pgid)];
$uniqueMail=[];foreach($mail as $box)foreach($box as $row)$uniqueMail[(int)$row['id']]=$row;
$mailView=alphano_mail_boxes(array_values($uniqueMail),$pgid);
require __DIR__.'/../templates/mail/workspace.php';
ClosePage();
