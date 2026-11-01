<?php
declare(strict_types=1);
/** Visible credits are centralized; their integrity policy is described in LICENSE. */
function alphano_render_credit(string $placement): string
{
    if(!in_array($placement,['home','equipment'],true))throw new InvalidArgumentException('Posizione dei crediti non valida.');
    if(!is_file(dirname(__DIR__).'/config/credits.php')||!is_file(dirname(__DIR__).'/templates/credits/'.$placement.'.php'))return '';
    $credit=require dirname(__DIR__).'/config/credits.php';
    $h=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $version=trim(file_get_contents(dirname(__DIR__).'/VERSION'));
    ob_start();require dirname(__DIR__).'/templates/credits/'.$placement.'.php';return ob_get_clean();
}
