<?php
declare(strict_types=1);
require_once __DIR__.'/default-art.inc.php';
/** Shared board artwork and section ordering, for public lists and the editor. */
function alphano_board_image(array $board):string {
    if(!empty($board['header_image'])){try{return alphano_board_validate_image($board['header_image']);}catch(DomainException $e){/* Recover an obsolete asset through the shared fallback. */}}
    return alphano_default_art('board');
}
function alphano_board_validate_image(string $path):string {
    if(trim($path)==='')return alphano_default_art('board');
    if(alphano_is_default_art($path))return $path;
    if($path==='/assets/placeholder.svg')return $path;
    if(!preg_match('~^/uploads/forum/boards/[a-z0-9-]+\.webp$~D',$path)||!is_file(dirname(__DIR__).$path))throw new DomainException('Scegli un’immagine delle bacheche disponibili.',422);
    return $path;
}
function alphano_board_images():array {
    $out=[];foreach(alphano_default_art_catalog() as $asset)if($asset['kind']==='cover')$out[]=['path'=>$asset['path'],'name'=>$asset['title']];
    foreach(glob(dirname(__DIR__).'/uploads/forum/boards/*.webp')?:[] as $file)$out[]=['path'=>'/uploads/forum/boards/'.basename($file),'name'=>ucwords(str_replace('-',' ',pathinfo($file,PATHINFO_FILENAME)))];
    return $out;
}
function alphano_board_sections(array $boards):array {
    $sections=[];foreach($boards as $b){$key=$b['location'];$sections[$key]??=['location'=>$key,'boards'=>[]];$sections[$key]['boards'][]=$b;}
    ksort($sections,SORT_NATURAL|SORT_FLAG_CASE);return array_values($sections);
}
