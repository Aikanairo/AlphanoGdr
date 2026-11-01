<?php
declare(strict_types=1);

/** One catalogue for names, registration guidance, handbook and illustrations. */
function alphano_alignment_catalog():array
{
    static $catalog;
    return $catalog??=json_decode(file_get_contents(dirname(__DIR__).'/config/alignments.json'),true,32,JSON_THROW_ON_ERROR);
}
function alphano_alignment(string $code):?array
{
    static $byCode;
    $byCode??=array_column(alphano_alignment_catalog()['alignments'],null,'code');
    return $byCode[strtoupper(trim($code))]??null;
}
function alphano_alignment_manual():string
{
    $catalog=alphano_alignment_catalog();
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<section class="manual-alignment-guides"><h2>Allineamento: come orienta le tue scelte</h2><p>'.$h($catalog['overview']).'</p><p>'.$h($catalog['mechanics']).'</p><div class="manual-alignment-grid">';
    foreach($catalog['alignments'] as $a)$html.='<article class="manual-alignment-guide"><img src="'.$h($a['image']).'" alt="" width="72" height="72" loading="lazy"><div><h3>'.$h($a['name']).'</h3><p>'.$h($a['description']).'</p><p class="manual-alignment-example"><strong>Esempio in gioco:</strong> '.$h($a['example']).'</p></div></article>';
    return $html.'</div></section>';
}
