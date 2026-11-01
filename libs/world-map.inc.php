<?php
declare(strict_types=1);
require_once __DIR__.'/world.inc.php';require_once __DIR__.'/managed-config.inc.php';
function alphano_world_map():array{return array_replace(['mode'=>'cards','maps'=>[]],alphano_managed_read('world-map'));}
function alphano_world_destinations():array{return ConnectDB()->query('SELECT a.*,COUNT(m.id) AS rooms FROM world_areas a LEFT JOIN mappe m ON m.area_id=a.id AND m.chat=1 AND m.public_enabled=1 WHERE a.enabled=1 GROUP BY a.id ORDER BY a.position,a.title');}
function alphano_world_map_rows(int $area,bool $editor=false):array{
 if(!$area)return $editor?ConnectDB()->query('SELECT * FROM world_areas ORDER BY position,title'):alphano_world_destinations();
 return ConnectDB()->query('SELECT * FROM mappe WHERE area_id=? AND chat=1'.($editor?'':' AND public_enabled=1').' ORDER BY sort_order,title',[$area]);
}
function alphano_world_map_image(array $row,int $area):string{return alphano_world_image((string)($area?($row['scene_thumbnail']?:$row['scene_image']):$row['image']));}
function alphano_world_map_save(int $area,array $in,array $files):void{
 $config=alphano_world_map();if(!in_array($in['mode']??'', ['cards','visual'],true))throw new DomainException('Modalità mappa non valida.');
 $key=$area?'area-'.$area:'world';$old=$config['maps'][$key]??[];$image=$old['image']??'';
 if(!empty($in['remove_image']))$image='';
 if(($files['map_image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$image=alphano_world_upload($files['map_image']);
 $input=json_decode((string)($in['markers']??'{}'),true,16,JSON_THROW_ON_ERROR);if(!is_array($input))throw new DomainException('Posizioni non valide.');$valid=array_column(alphano_world_map_rows($area,true),null,'id');$markers=[];
 foreach($input as $id=>$marker){if(!isset($valid[$id])||!is_array($marker))throw new DomainException('Destinazione non disponibile: ricarica la mappa.');
  $x=$marker['x']??null;$y=$marker['y']??null;$style=$marker['style']??'image';if(!is_numeric($x)||!is_numeric($y)||!is_finite((float)$x)||!is_finite((float)$y)||(float)$x<0||(float)$x>100||(float)$y<0||(float)$y>100||!in_array($style,['image','label'],true))throw new DomainException('Posizione fuori mappa o stile non valido.');
  $markers[(string)(int)$id]=['x'=>round((float)$x,5),'y'=>round((float)$y,5),'style'=>$style];
 }
 if(count($markers)>300)throw new DomainException('Massimo 300 segnaposti per mappa.');
 $config['mode']=$in['mode'];$config['maps'][$key]=['image'=>$image,'markers'=>(object)$markers];alphano_managed_save('world-map',$config,(string)($in['revision']??''));
}
function alphano_world_map_payload(int $area,array $rows,bool $editor=false):array{
 $map=alphano_world_map()['maps'][$area?'area-'.$area:'world']??[];$markers=[];
 foreach($rows as $row){$point=$map['markers'][(string)$row['id']]??null;if(!$point&&!$editor)continue;$id=(int)$row['id'];$markers[]=['id'=>$id,'title'=>$row['title'],'description'=>$area?$row['descrizione']:$row['description'],'image'=>alphano_world_map_image($row,$area),'url'=>$area?'/chat/index.php?chat='.rawurlencode($row['chat_key']):'/mappa.php?area='.$id,'x'=>(float)($point['x']??50),'y'=>(float)($point['y']??50),'style'=>$point['style']??'image','placed'=>$point!==null];}
 $destinations=array_map(static fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'url'=>$area?'/chat/index.php?chat='.rawurlencode($r['chat_key']):'/mappa.php?area='.(int)$r['id']],$rows);
 return ['image'=>empty($map['image'])?'':alphano_world_image($map['image']),'markers'=>$markers,'destinations'=>$destinations,'editor'=>$editor];
}
function alphano_world_map_component(array $payload,string $title,string $back='/mappa.php?view=cards'):void{
 ?><section class="atlas" data-atlas tabindex="0" aria-label="<?=alphano_escape($title)?>"><nav class="atlas-toolbar" aria-label="Strumenti della mappa"><a href="<?=alphano_escape($back)?>" class="atlas-control" title="Indietro o elenco" aria-label="Indietro o elenco">←</a><strong><?=alphano_escape($title)?></strong><button type="button" data-atlas-action="out" aria-label="Riduci zoom">−</button><output data-atlas-zoom aria-label="Livello di zoom">100%</output><button type="button" data-atlas-action="in" aria-label="Aumenta zoom">+</button><button type="button" data-atlas-action="cover" title="Riempi lo spazio">Riempi</button><?php if($payload['editor']):?><button type="button" data-atlas-action="fit" title="Mostra tutta la mappa">Intera</button><?php endif;?><button type="button" data-atlas-action="fullscreen" aria-label="Schermo intero">⛶</button><label class="atlas-jump"><span class="sr-only">Trova una destinazione</span><select data-atlas-jump><option value="">Trova un luogo…</option></select></label></nav><div class="atlas-viewport" data-atlas-viewport><div class="atlas-canvas" data-atlas-canvas><img data-atlas-image alt="" draggable="false"><div data-atlas-markers></div></div></div><p class="atlas-hint" data-atlas-hint>Trascina per esplorare · + e − per lo zoom · frecce per spostarti</p><p class="atlas-status" role="status" data-atlas-status></p><script type="application/json" data-atlas-config><?=json_encode($payload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_UNESCAPED_UNICODE)?></script></section><?php
}
