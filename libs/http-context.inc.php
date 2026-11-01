<?php
declare(strict_types=1);

/** Canonical installation URL controls cookies and origin checks behind TLS proxies. */
function alphano_https_context(string $canonicalUrl='',?array $server=null):bool {
    $server??=$_SERVER;
    if(strtolower((string)parse_url($canonicalUrl,PHP_URL_SCHEME))==='https')return true;
    if((!empty($server['HTTPS'])&&strtolower((string)$server['HTTPS'])!=='off')||(int)($server['SERVER_PORT']??0)===443)return true;
    // Before configuration exists, recognize AlterVista's documented HTTPS proxy.
    return (bool)preg_match('/^[a-z0-9-]+\.altervista\.org(?::443)?$/iD',(string)($server['HTTP_HOST']??''))
        && strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO']??'')))==='https';
}
function alphano_canonical_origin(string $url):string {
    $parts=parse_url($url);$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));
    if(!in_array($scheme,['http','https'],true)||$host==='')throw new InvalidArgumentException('URL del gioco non valido.');
    $port=(int)($parts['port']??($scheme==='https'?443:80));
    return $scheme.'://'.$host.(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80)?'':':'.$port);
}
