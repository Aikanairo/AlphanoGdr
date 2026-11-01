<?php
declare(strict_types=1);
require dirname(__DIR__).'/libs/http-context.inc.php';
$cases=[
 ['canonical HTTPS behind proxy',alphano_https_context('https://game.example',[])],
 ['direct HTTPS',alphano_https_context('',['HTTPS'=>'on'])],
 ['AlterVista TLS proxy',alphano_https_context('',['HTTP_HOST'=>'example.altervista.org','HTTP_X_FORWARDED_PROTO'=>'https'])],
 ['untrusted generic proxy ignored',!alphano_https_context('',['HTTP_HOST'=>'game.example','HTTP_X_FORWARDED_PROTO'=>'https'])],
 ['plain local HTTP',!alphano_https_context('http://localhost',[])],
 ['default port normalized',alphano_canonical_origin('https://GAME.example:443/')==='https://game.example'],
 ['non-default port preserved',alphano_canonical_origin('http://localhost:8000/')==='http://localhost:8000'],
];
foreach($cases as [$label,$ok]){if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}echo 'PASS '.$label.PHP_EOL;}
