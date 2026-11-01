<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/alignments.inc.php';
require_once __DIR__.'/profile-symbols.inc.php';
/** Labels can arrive from legacy HTML helpers; the sheet always renders plain escaped text. */
function alphano_profile_label(string $value):string {
 return trim(strip_tags(html_entity_decode(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'),ENT_QUOTES|ENT_HTML5,'UTF-8')));
}
/** Strength shown in the sheet comes from the same progression as combat. */
function alphano_profile_strength(string $kind,int $id,int $race):int {
 if(rpg_ready())return (int)rpg_actor_stats($kind,$id)['strength'];
 return (int)(ConnectDB()->query('SELECT forza FROM razze WHERE id=?',[$race])[0]['forza']??0);
}
/** Public sheet decoration; controllers keep all authorization and private fields. */
function alphano_profile_design(array $p):array {
 $db=ConnectDB();$race=GetRazza((int)$p['race']);$sex=$p['sex'];
 $badge=static fn(string $label,string $icon,string $src=''):array=>compact('label','icon','src');
 $badges=[$badge($race,'user',alphano_race_icon((int)$p['race'],$sex)),$badge($sex,$sex==='Femmina'?'female':'male')];
 $affiliations=[];
 foreach(['corp'=>['corporazioni','Corporazione','shield'],'org'=>['organizzazioni','Organizzazione','swords']] as $key=>[$table,$label,$icon]){
  $id=(int)($p[$key]??0);$name=$id?($db->query('SELECT nome FROM '.$table.' WHERE id=?',[$id])[0]['nome']??''):'';
  $rank=(int)($p[$key.'_rank']??0);$rankRow=$rank?($db->query('SELECT nome,descrizione FROM cariche'.$table.' WHERE id=? AND '.($key==='corp'?'corporazione':'organizzazione').'=?',[$rank,$id])[0]??[]):[];$rankText=$rankRow['nome']??'';
  $src=$id&&$rank?alphano_badge_url($table,$rank,$id):'';
  $affiliations[]=['kind'=>$key,'label'=>$label,'name'=>$name?:'Nessuna','detail'=>$rankText?:'Nessuna carica','description'=>alphano_profile_label((string)($rankRow['descrizione']??'')),'icon'=>$icon,'src'=>$src,'url'=>$id?'/'.$table.'/gerarchia.php?'.($key==='corp'?'corp':'org').'='.$id:''];
  if($name)$badges[]=$badge($name.($rankText?' · '.$rankText:''),$icon,$src);
 }
 $herald=(int)($p['heraldry']??0);$a=$herald?$db->query('SELECT a.nome,a.descrizione,c.id AS city_id,c.city FROM araldiche a LEFT JOIN city c ON c.id=a.city WHERE a.id=?',[$herald])[0]??[]:[];
 $heraldSrc=$herald?alphano_badge_url('cariche',$herald):'';
 $affiliations[]=['kind'=>'city','label'=>'Città e araldica','name'=>$a['city']??($a['nome']??'Viandante'),'detail'=>$a['nome']??'Nessuna cittadinanza','description'=>alphano_profile_label((string)($a['descrizione']??'')),'icon'=>'city','src'=>$heraldSrc,'url'=>!empty($a['city_id'])?'/citta/?id='.(int)$a['city_id']:''];
 if(!empty($a['city']))$badges[]=$badge($a['city'],'city',$heraldSrc);
 $alignment=alphano_alignment((string)($p['alignment_code']??''));
 $cards=[['kind'=>'race','label'=>'Razza','name'=>$race,'detail'=>'Storia e abilità della stirpe','description'=>'La razza mostrata corrisponde alla forma visibile del personaggio.','icon'=>'user','src'=>alphano_race_icon((int)$p['race'],$sex),'url'=>'/razze/?id='.(int)$p['race']],['kind'=>'sex','label'=>'Sesso','name'=>$sex,'detail'=>'','description'=>'Sesso del personaggio.','icon'=>$sex==='Femmina'?'female':'male','src'=>'','url'=>''],...$affiliations];
 if($alignment)$cards[]=['kind'=>'alignment','label'=>'Allineamento','name'=>$alignment['name'],'detail'=>'','description'=>$alignment['description'],'icon'=>'compass','src'=>$alignment['image'],'url'=>'/manuali/?chapter=primi-passi#manual-paragraph-5'];
 return ['sex'=>$sex,'alignment'=>$alignment,'cards'=>$cards,'badges'=>$badges,'affiliations'=>$affiliations,'crest'=>$affiliations[0],
  'register'=>[['label'=>'Personaggio','value'=>$p['name']],['label'=>'Razza','value'=>$race],['label'=>'Sesso · Allineamento','value'=>$sex.' · '.$p['alignment']],['label'=>'Luogo','value'=>$p['location']]],
  'location'=>alphano_profile_label((string)$p['location']),'hp'=>(int)$p['hp'],'max_hp'=>maxpuntif];
}

/** Three public tabs; staff-only rows have already been filtered by the controllers. */
function alphano_profile_compact_sections(array $sections):array {
 $identity=null;$story=null;$progression=null;$identityMore=[];$storyMore=[];
 foreach($sections as $section){
  if(($section['template']??'')==='progression'){$progression=$section;continue;}
  if(in_array($section['id'],['general','identita'],true)){$identity=$section;continue;}
  if(in_array($section['id'],['personal','storia'],true)){$story=$section;continue;}
  if(in_array($section['id'],['relazioni','note','noi'],true))$storyMore[]=$section;else $identityMore[]=$section;
 }
 $identity??=['id'=>'identita','rows'=>[]];$story??=['id'=>'storia','rows'=>[]];
 if($identity['id']==='general')$identity['rows']=array_values(array_filter($identity['rows'],static fn(array $row):bool=>!in_array($row['label'],['Corporazione','Carica di corporazione','Organizzazione','Carica di organizzazione','Araldica','Luogo'],true)));
 $identity=array_replace($identity,['title'=>'Identità','icon'=>'user','template'=>'identity','children'=>$identityMore]);
 $story=array_replace($story,['title'=>'Storia','icon'=>'book','template'=>'history','children'=>$storyMore]);
 $progression=array_replace($progression??['id'=>'progressione','rows'=>[]],['title'=>'Abilità & Malus','icon'=>'sparkles','template'=>'progression']);
 return [$progression,$identity,$story,['id'=>'giochi','title'=>'Giochi','icon'=>'crown','template'=>'board-games','rows'=>[]]];
}
