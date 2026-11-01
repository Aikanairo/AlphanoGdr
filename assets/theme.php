<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/theme-bundle.inc.php';
$version=alphano_theme_version();
header('Content-Type: text/css; charset=utf-8');header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');header('ETag: "'.$version.'"');
if(($_SERVER['HTTP_IF_NONE_MATCH']??'')==='"'.$version.'"'){http_response_code(304);exit;}
foreach(alphano_theme_files() as $file){echo "\n/* ".basename($file)." */\n";readfile($file);}
