<?php
declare(strict_types=1);
/** Original, replaceable artwork shipped with every installation. */
function alphano_default_art_catalog(): array {
    static $catalog;
    return $catalog??=array_merge(
        json_decode(file_get_contents(dirname(__DIR__).'/config/default-art.json'),true,32,JSON_THROW_ON_ERROR)['assets'],
        json_decode(file_get_contents(dirname(__DIR__).'/config/header-art.json'),true,32,JSON_THROW_ON_ERROR)['assets']
    );
}
function alphano_default_art(string $key): string {
    foreach(alphano_default_art_catalog() as $asset)if($asset['key']===$key)return $asset['path'];
    return '/assets/default-nav-scroll.svg';
}
function alphano_is_default_art(string $path): bool {
    return in_array($path,array_column(alphano_default_art_catalog(),'path'),true);
}

/** Pure URL lookup: public artwork never needs to expose game catalogues. */
function alphano_default_header(string $url): string {
    static $manifest;
    $manifest??=json_decode(file_get_contents(dirname(__DIR__).'/config/header-art.json'),true,32,JSON_THROW_ON_ERROR);
    $key=alphano_appearance_page_key($url);
    $base=alphano_appearance_page_key(parse_url($url,PHP_URL_PATH)?:'/');
    $art=$manifest['pages'][$key]??$manifest['pages'][$base]??null;
    if($art===null)foreach($manifest['families'] as $prefix=>$value)if($base===$prefix||str_starts_with($base,$prefix.'-')){$art=$value;break;}
    return alphano_default_art('header-'.($art??'panorama'));
}
