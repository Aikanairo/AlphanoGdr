<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/manuals.inc.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new DomainException('Metodo non consentito.',405);
    $u=($_GET['public']??'')==='1'?alphano_manual_guest():alphano_manual_user(alphano_require_login(true));$key=$_GET['chapter']??'';$screen=$_GET['screen']??'';
    if(!is_string($key)||!is_string($screen))throw new DomainException('Schermata non disponibile.',404);
    $rule=alphano_manual_find($key,0,$u);$definition=alphano_manual_definition((string)$rule['manual_key']);$file=null;
    foreach($definition['screens']??[] as $entry)if($entry['key']===$screen)$file=$entry['file'];
    if(!$file)throw new DomainException('Schermata non disponibile.',404);$path=alphano_manual_screen_path($file);
    if(!is_file($path)||is_link($path))throw new DomainException('Schermata non disponibile.',404);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    header('Content-Type: image/png');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));readfile($path);
}catch(Throwable $e){http_response_code($e instanceof DomainException?$e->getCode():404);header('Cache-Control: private, no-store');exit;}
