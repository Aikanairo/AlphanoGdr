<?php
declare(strict_types=1);
require dirname(__DIR__).'/libs/runtime-requirements.inc.php';
require dirname(__DIR__).'/libs/status-page.inc.php';
foreach (['8.2.35'=>false,'8.3.36'=>false,'8.4.0'=>true,'8.4.26'=>true,'8.4.27'=>true,'8.4.28'=>true,'8.5.0'=>true,'8.5.11'=>true,'8.5.12'=>true,'8.6.0RC1'=>false,'9.0.0'=>false] as $version=>$allowed) {
    if (alphano_php_supported($version)!==$allowed) throw new RuntimeException('Compatibility check failed: '.$version);
}
foreach (['8.3.36'=>false,'8.4.0'=>false,'8.4.26'=>false,'8.4.27'=>true,'8.5.0'=>false,'8.5.11'=>false,'8.5.12'=>true] as $version=>$patched) {
    if (alphano_php_patched($version)!==$patched) throw new RuntimeException('Security recommendation failed: '.$version);
    if (alphano_php_supported($version) && (alphano_runtime_warning($version)!=='')===$patched) throw new RuntimeException('Unexpected warning: '.$version);
}
$warning=alphano_runtime_warning('8.4.26');
if (!str_contains($warning,'Puoi continuare') || !str_contains($warning,'non corregge le vulnerabilità')) throw new RuntimeException('Missing clear nonblocking warning.');
ob_start();alphano_status_page('Prova <titolo>', '<script>alert(1)</script>');$html=ob_get_clean();
if (str_contains($html,'<script>') || !str_contains($html,'&lt;script&gt;') || !str_contains($html,'account-recovery-card') || !str_contains($html,'data-page-heading')) throw new RuntimeException('Unsafe or unstyled system message.');
echo "PASS PHP compatibility, nonblocking security recommendations and styled escaped feedback\n";

require dirname(__DIR__).'/libs/runtime-setup.inc.php';
foreach (['/index.php'=>'/install/','/install/index.php'=>'/install/','/probe/index.php'=>'/probe/install/','/probe/install/index.php'=>'/probe/install/'] as $script=>$url) if(alphano_runtime_install_url($script)!==$url) throw new RuntimeException('Invalid bootstrap installation URL.');
$folder=sys_get_temp_dir().'/alphano-runtime-test-'.bin2hex(random_bytes(8));mkdir($folder);mkdir($folder.'/private');mkdir($folder.'/config');
$original="AddHandler av-php8 .php\n# Existing rules\nRewriteEngine On\nRewriteRule ^private - [F,L]\n";
try {
 file_put_contents($folder.'/.htaccess',$original);
 alphano_runtime_select_php84($folder);$updated=file_get_contents($folder.'/.htaccess');
 if(file_get_contents($folder.'/private/php-runtime-original.htaccess')!==$original || !str_contains($updated,trim($original)) || !str_contains($updated,'AddHandler av-php84 .php')) throw new RuntimeException('PHP selector did not preserve configuration and backup.');
 alphano_runtime_select_php84($folder);
 if(file_get_contents($folder.'/.htaccess')!==$updated) throw new RuntimeException('PHP selection must be idempotent.');
 file_put_contents($folder.'/config/local.php','<?php return [];');$refused=false;
 try{alphano_runtime_select_php84($folder);}catch(DomainException){$refused=true;}
 if(!$refused || file_get_contents($folder.'/.htaccess')!==$updated) throw new RuntimeException('Installed site must not be reconfigured by bootstrap.');
} finally {
 foreach (['/.htaccess','/private/php-runtime-original.htaccess','/private/php-runtime.lock','/config/local.php'] as $file) if(is_file($folder.$file)) unlink($folder.$file);
 rmdir($folder.'/private');rmdir($folder.'/config');rmdir($folder);
}
echo "PASS AlterVista PHP selection, exact backup, preserved rules and installed-site protection\n";
