<?php
declare(strict_types=1);
/** Resolve the checked-in CSS manifest. No build tools are required on hosting. */
function alphano_theme_files(): array {
    $root=dirname(__DIR__);$manifest=file_get_contents($root.'/css/theme/theme.css');
    preg_match_all('~@import url\("([a-z-]+\.css)"\);~',$manifest,$matches);
    return [...array_map(static fn($name)=>$root.'/css/theme/'.$name,$matches[1]),$root.'/assets/engine.css',$root.'/assets/appearance.css',$root.'/assets/portal.css',$root.'/assets/credits.css',$root.'/assets/management.css'];
}
function alphano_theme_version(): string {
    static $version;
    return $version??=substr(hash('sha256',implode('',array_map(static fn($file)=>hash_file('sha256',$file),alphano_theme_files()))),0,20);
}
