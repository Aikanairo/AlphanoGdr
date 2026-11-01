<?php
clearstatcache(true); // Fixtures intentionally replace/delete PHP files between requests.
// Local verification router only. Production uses Apache's .htaccess or docs/HOSTING.md.
$root=dirname(__DIR__);$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
if(str_contains($path,"\0")||preg_match('~(?:^|/)\.|^/(?:config|database|private|tests|docs|vendor|third_party|templates|libs|bin|temp|sessione/log)(?:/|$)|\.(?:sql|log|bak|env|eml)$|^/composer\.(?:json|lock)$~i',$path)){http_response_code(403);exit;}
$file=realpath($root.$path);if(!$file||!str_starts_with($file,$root.'/')&&$file!==$root){http_response_code(404);exit;}
if(is_dir($file))$file.='/index.php';if(!is_file($file)){http_response_code(404);exit;}
if(pathinfo($file,PATHINFO_EXTENSION)==='php'){$_SERVER['SCRIPT_NAME']=substr($file,strlen($root));require $file;return true;}
return false;
