<?php
declare(strict_types=1);
/** Also available to the installer and standalone error pages before database setup. */
function alphano_favicon_links():string{
 $version=substr(hash_file('sha256',dirname(__DIR__).'/favicon.png'),0,12);
 return '<link rel="icon" type="image/png" sizes="1254x1254" href="/favicon.png?v='.$version.'"><link rel="apple-touch-icon" href="/favicon.png?v='.$version.'">';
}
