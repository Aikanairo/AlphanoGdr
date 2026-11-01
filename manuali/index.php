<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/kernel.inc.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')alphano_fail('Metodo non consentito.',405);
$manuals=['apiUrl'=>'/api/manuali.php?public=1','adminAccademia'=>false,'public'=>true,'baseUrl'=>'/manuali/'];
require __DIR__.'/../templates/manuals/index.php';
