<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
$idpg = GetIDPG(true);
$tools = [
 ['title'=>'Appunti personali','description'=>'Pensieri, promemoria e discussioni visibili soltanto a te.','url'=>'/discussioni/?tipo=Appunti'.$idpg,'icon'=>'book'],
 ['title'=>'Segnalazione bug','description'=>'Segnala un problema e segui le risposte della gestione.','url'=>'/bug/','icon'=>'settings'],
 ['title'=>'Giocate salvate','description'=>'Apri una giocata esportata per rileggere la tua storia.','url'=>'/vari/visualizzagiocata.php','icon'=>'scroll'],
];
require __DIR__ . '/../templates/tools/index.php';
