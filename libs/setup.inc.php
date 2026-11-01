<?php
declare(strict_types=1);
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/world.inc.php';
require_once dirname(__DIR__).'/install/installer.php';

function alphano_setup_steps():array {return ['general'=>'Impostazioni generali','chats'=>'Chat e luoghi','races'=>'Razze e trasformazioni','ethnicities'=>'Etnie','cities'=>'Città ed elezioni','organizations'=>'Organizzazioni','corporations'=>'Corporazioni e produzione','appearance'=>'Template e disposizione','rules'=>'Regole, abilità e testi','review'=>'Verifica e apertura'];}
function alphano_setup_status():array {return array_column(ConnectDB()->query('SELECT step_key,status FROM engine_setup_steps'),'status','step_key');}
function alphano_setup_mark(string $step,string $status,int $actor):void {
 if(!isset(alphano_setup_steps()[$step])||!in_array($status,['pending','skipped','completed'],true)||($step==='general'&&$status!=='completed'))throw new DomainException('Passaggio non valido.');
 ConnectDB()->execute('UPDATE engine_setup_steps SET status=?,updated_by=?,updated_at=? WHERE step_key=?',[$status,$actor,time(),$step]);
 ConnectDB()->execute('INSERT INTO engine_admin_audit(actor,action,created_at) VALUES(?,?,?)',[$actor,'setup:'.$step.':'.$status,time()]);
}
function alphano_setup_settings(array $changes):void {
 $path=dirname(__DIR__).'/private/settings.json';$old=is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):[];
 alphano_settings_write(array_replace($old,$changes));
}
function alphano_setup_next(string $step):string {$keys=array_keys(alphano_setup_steps());$pos=array_search($step,$keys,true);return $keys[$pos+1]??'';}
function alphano_setup_save(string $step,array $in,int $actor):void {
 $db=ConnectDB();$pdo=$db->getConnection();$insert=static fn($table,$values)=>alphano_seed_insert($pdo,$table,$values);
 if($step==='races'){
  $input=$in;$input['name']=$in['name']??'';foreach(['special','enabled','human_default','transformation_enabled','loses_human_language'] as $k)$input[$k]=!empty($in[$k]);
  $r=rpg_admin_save_race($input,$actor);$default=alphano_human_race_id(true);alphano_setup_settings(['initial_race'=>$default]);
  if($default)$db->execute('UPDATE utenti SET razza=? WHERE razza=0',[$default]);return;
 }
 if($step==='appearance'){
  $out=[];foreach(['theme'=>array_keys(alphano_themes()),'sidebar_position'=>['left','right'],'header_position'=>['top','bottom']] as $k=>$allowed){if(!in_array($in[$k]??'', $allowed,true))throw new DomainException('Opzione template non valida.');$out[$k]=$in[$k];}alphano_setup_settings($out);return;
 }
 if($step==='rules'){
  $points=rpg_int($in['initial_points']??20,0,100,'Punti iniziali');
  // Creation and combat share eight stable keys; labels are freely editable.
  $names=[];foreach(rpg_catalog()['stats'] as $stat)$names[$stat['key']]=rpg_text($in['stat_names'][$stat['key']]??'',80,true);
  $privacy=rpg_text($in['privacy_text']??'',20000,true);$terms=rpg_text($in['terms_text']??'',20000,true);
  foreach($names as $key=>$name)$db->execute('UPDATE rpg_characteristics SET name=? WHERE stat_key=?',[$name,$key]);
  alphano_setup_settings(['initial_points'=>$points,'abilities_enabled'=>!empty($in['abilities_enabled']),'privacy_text'=>$privacy,'terms_text'=>$terms]);return;
 }
 if($step==='review'){
  if(!empty($in['registration_enabled'])&&alphano_human_race_id()<1)throw new DomainException('Per aprire le iscrizioni seleziona una razza ordinaria attiva come iniziale.');
  alphano_setup_settings(['registration_enabled'=>!empty($in['registration_enabled'])]);return;
 }
 if(in_array($step,['ethnicities','organizations','corporations'],true)&&($in['action']??'')==='toggle'){
  $key=['ethnicities'=>'ethnicities_enabled','organizations'=>'organizations_enabled','corporations'=>'corporations_enabled'][$step];alphano_setup_settings([$key=>!empty($in['feature_enabled'])]);return;
 }
 $db->beginTransaction();try{
  if($step==='chats'){
   $area=(int)($in['area_id']??0);$parent=$db->query('SELECT * FROM world_areas WHERE id=?',[$area])[0]??null;
   if(!$parent){$title=rpg_text($in['area_title']??'',120,true);$slug='area_'.bin2hex(random_bytes(6));$area=$insert('world_areas',['slug'=>$slug,'title'=>$title,'description'=>'','image'=>'/assets/placeholder.svg']);$parent=['slug'=>$slug];}
   if(($in['action']??'')==='demo_chats')$titles=['Piazza','Locanda','Giardino','Biblioteca'];else $titles=[rpg_text($in['name']??'',120,true)];
   foreach($titles as $i=>$title)$insert('mappe',['chat_key'=>'room_'.bin2hex(random_bytes(8)),'chat'=>1,'title'=>$title,'position'=>$parent['slug'],'descrizione'=>rpg_text($in['description']??'',10000),'scene_image'=>'/assets/placeholder.svg','scene_thumbnail'=>'/assets/placeholder.svg','public_enabled'=>1,'area_id'=>$area,'sort_order'=>$i]);
  }elseif($step==='ethnicities'){
   $id=(int)($in['id']??0);$name=rpg_text($in['name']??'',120,true);$desc=rpg_text($in['description']??'',10000);
   if($id){if(!$db->query('SELECT id FROM etnie WHERE id=?',[$id]))throw new DomainException('Etnia non trovata.');$db->execute('UPDATE etnie SET nome=?,descrizione=? WHERE id=?',[$name,$desc,$id]);}else $insert('etnie',['nome'=>$name,'descrizione'=>$desc]);
  }elseif($step==='cities'){
   $id=(int)($in['id']??0);$name=rpg_text($in['name']??'',100,true);$description=rpg_text($in['description']??'',10000);$elections=(int)!empty($in['elections_enabled']);
   if($id){if(!$db->query('SELECT id FROM city WHERE id=?',[$id]))throw new DomainException('Città non trovata.');$db->execute('UPDATE city SET city=?,statuto=?,elections_enabled=?,elezioni=? WHERE id=?',[$name,$description,$elections,(string)$elections,$id]);}
   else{$id=$insert('city',['city'=>$name,'statuto'=>$description,'newstatuto'=>'','budget'=>0,'elections_enabled'=>$elections,'elezioni'=>(string)$elections]);$insert('araldiche',['nome'=>'Cittadino','prima'=>'1','city'=>$id,'descrizione'=>'Cittadinanza','livello'=>1]);}
   $area=(int)($in['area_id']??0);
   if($area){if(!$db->query('SELECT id FROM world_areas WHERE id=?',[$area]))throw new DomainException('Destinazione non trovata.');$db->execute('UPDATE world_areas SET city_id=?,title=?,description=? WHERE id=?',[$id,$name,$description,$area]);}
   elseif(!$db->query('SELECT id FROM world_areas WHERE city_id=?',[$id]))$insert('world_areas',['city_id'=>$id,'slug'=>'city_'.bin2hex(random_bytes(6)),'title'=>$name,'description'=>$description,'image'=>'/assets/placeholder.svg']);
  }elseif(in_array($step,['organizations','corporations'],true)){
   $org=$step==='organizations';$table=$org?'organizzazioni':'corporazioni';$singular=$org?'organizzazione':'corporazione';$id=(int)($in['id']??0);$name=rpg_text($in['name']??'',100,true);$desc=rpg_text($in['description']??'',10000);$city=(int)($in['city_id']??0);$c=$city?$db->query('SELECT city FROM city WHERE id=?',[$city])[0]??null:null;if($city&&!$c)throw new DomainException('Città non valida.');$town=$org?($c['city']??''):$city;
   if($id){if(!$db->query('SELECT id FROM '.$table.' WHERE id=?',[$id]))throw new DomainException('Gruppo non trovato.');$db->execute('UPDATE '.$table.' SET nome=?,descrizione=?,city=? WHERE id=?',[$name,$desc,$town,$id]);}
   else{$id=$insert($table,['nome'=>$name,'idmaster'=>$actor,'newidmaster'=>$actor,'approvato'=>'1','city'=>$town,'statuto'=>$desc,'newstatuto'=>$desc,'descrizione'=>$desc,'budget'=>0]);$insert('cariche'.$table,[$singular=>$id,'nome'=>'Responsabile','prima'=>'1','livello'=>10,'descrizione'=>'Responsabile del gruppo']);$insert('cariche'.$table,[$singular=>$id,'nome'=>'Membro','prima'=>'0','livello'=>1,'descrizione'=>'Membro del gruppo']);}
  }else throw new DomainException('Passaggio non modificabile da questa pagina.');
  $db->execute('INSERT INTO engine_admin_audit(actor,action,created_at) VALUES(?,?,?)',[$actor,'setup:save:'.$step,time()]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
