<?php
declare(strict_types=1);
require __DIR__.'/../libs/libxml.inc.php';
$messages=['<p>Un messaggio di prova.</p>','Unicode: àèìòù'];
$xml=GetXMLFromArray($messages,'giocata');
assert(GetArrayFromBZXML($xml,'giocata')===$messages);
assert(GetArrayFromBZXML($xml,'altra')===false);
assert(GetArrayFromBZXML('<!DOCTYPE FIELDSET [<!ENTITY x SYSTEM "file:///etc/passwd">]><FIELDSET area="giocata" count="0"/>','giocata')===false);
assert(GetArrayFromBZXML('BZh9invalid','giocata')===false);
echo "PASS portable recordings and invalid/legacy input guards\n";
