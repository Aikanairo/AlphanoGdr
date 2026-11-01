<?php
declare(strict_types=1);
/** Character creation v2: one server authority shared by registration and existing characters. */
function rpg_human_manual():array {static $v;return $v??=json_decode(file_get_contents(__DIR__.'/../config/human-rules-v2.json'),true,64,JSON_THROW_ON_ERROR);}
function rpg_creation_ready():bool {static $v;return $v??=(bool)ConnectDB()->query("SHOW TABLES LIKE 'rpg_creation'");}
function rpg_human_rule(string $key):?array {foreach(rpg_human_manual()['abilities'] as $a)if($a['key']===$key)return $a;return null;}
/** Initial human talents stay innate in nature, but extra choices can be learned for PE. */
function rpg_ability_learnable(array $a):bool {
 return alphano_config('abilities_enabled') && ($a['category']==='purchasable'||($a['category']==='innate'&&!empty(rpg_human_rule($a['key']??$a['ability_key']??'')['initial'])));
}
function rpg_creation_status(string $kind,int $id,?array $identity=null):array {
 $identity??=rpg_identity($kind,$id);$state=rpg_creation_ready()?(ConnectDB()->query('SELECT * FROM rpg_creation WHERE owner_kind=? AND owner_id=?',[$kind,$id])[0]??[]):[];
 $lineage=(int)($identity['native_race_id']??$identity['race_id']);$eligible=(int)alphano_config('initial_innates')>0;
 return ['manual_points'=>(bool)($state['manual_points']??false),'innates_chosen'=>(bool)($state['innates_chosen']??false),'needs_points'=>$kind==='pg'&&empty($state['manual_points']),'needs_innates'=>$eligible&&empty($state['innates_chosen']),'eligible'=>$eligible,'choices'=>(int)alphano_config('initial_innates')];
}
function rpg_creation_catalog():array {
 $human=rpg_race(alphano_human_race_id());$skills=[];foreach(rpg_catalog()['abilities'] as $a)if($a['enabled']&&$a['category']==='innate'&&in_array(alphano_human_race_id(),$a['race_ids'],true)&&($rule=rpg_human_rule($a['key']))&&$rule['initial'])$skills[]=$a+['discipline'=>$rule['discipline'],'rules'=>$a['rules']??$rule];
 return ['points'=>(int)alphano_config('initial_points'),'choices'=>(int)alphano_config('initial_innates'),'stats'=>array_map(static fn($s)=>$s+['base'=>$human['stats'][$s['key']],'max'=>100],rpg_catalog()['stats']),'abilities'=>$skills,'malus'=>rpg_racial_catalog(alphano_human_race_id())];
}
function rpg_validate_allocation(mixed $input,array $identity,array $p):array {
 $points=(int)alphano_config('initial_points');if(!is_array($input)||array_diff(array_keys($input),RPG_STATS)||count($input)!==8)throw new DomainException('Distribuisci i punti fra tutte le otto caratteristiche.',422);
 $allocation=[];foreach(RPG_STATS as $k)$allocation[$k]=rpg_int($input[$k]??null,0,$points,rpg_stat_name($k));
 if(array_sum($allocation)!==$points)throw new DomainException('Devi assegnare esattamente '.$points.' punti iniziali.',422);
 $candidate=$p;$candidate['initial_stats']='{}';foreach(rpg_stat_forms($identity,$candidate) as $form)foreach($allocation as $k=>$v)if($form['stats'][$k]+$v>100)throw new DomainException(rpg_stat_name($k).' supererebbe 100 in '.$form['name'].'.',422);
 return $allocation;
}
function rpg_validate_innates(mixed $input):array {
 if(!is_array($input)||!array_is_list($input)||count($input)!==(int)alphano_config('initial_innates'))throw new DomainException('Scegli esattamente quattro abilità innate.',422);
 $ids=array_map(static fn($v)=>rpg_int($v,1,PHP_INT_MAX,'Abilità innata'),$input);if(count(array_unique($ids))!==(int)alphano_config('initial_innates'))throw new DomainException('Le quattro abilità devono essere differenti.',422);
 $allowed=array_column(rpg_creation_catalog()['abilities'],'id');if(array_diff($ids,$allowed))throw new DomainException('Scegli soltanto abilità innate del nuovo catalogo umano.',422);sort($ids);return $ids;
}
function rpg_complete_creation(string $kind,int $id,mixed $allocation,mixed $innates,string $request,int $actor):array {
 if(!rpg_creation_ready())throw new DomainException('Creazione personaggio in aggiornamento.',503);
 return rpg_atomic(function()use($kind,$id,$allocation,$innates,$request,$actor){
  $identity=rpg_identity($kind,$id,true);$p=rpg_progress($kind,$id,true);$db=ConnectDB();$status=rpg_creation_status($kind,$id,$identity);
  $payload=['allocation'=>$allocation,'innates'=>$innates];$receipt=rpg_receipt($kind,$id,$request,'creation');if($receipt){if(rpg_decode($receipt['payload'])!=$payload)throw new DomainException('Questa conferma contiene scelte diverse.',409);return rpg_sheet($kind,$id);}
  if(!$status['needs_points']&&!$status['needs_innates'])throw new DomainException('Le scelte iniziali sono già state confermate.',409);
  $points=$status['needs_points']?rpg_validate_allocation($allocation,$identity,$p):null;$selected=$status['needs_innates']?rpg_validate_innates($innates):[];
  $db->execute('INSERT IGNORE INTO rpg_creation(owner_kind,owner_id) VALUES(?,?)',[$kind,$id]);
  if($points!==null){$db->execute('UPDATE rpg_progress SET initial_claimed=1,initial_stats=?,version=version+1 WHERE owner_kind=? AND owner_id=?',[rpg_json($points),$kind,$id]);$db->execute('UPDATE rpg_creation SET manual_points=1 WHERE owner_kind=? AND owner_id=?',[$kind,$id]);}
  foreach($selected as $ability)$db->execute('INSERT INTO rpg_learned(owner_kind,owner_id,ability_id,cost,learned_at) VALUES(?,?,?,0,?)',[$kind,$id,$ability,time()]);
  if($selected)$db->execute('UPDATE rpg_creation SET innates_chosen=1 WHERE owner_kind=? AND owner_id=?',[$kind,$id]);
  $db->execute('UPDATE rpg_creation SET updated_at=? WHERE owner_kind=? AND owner_id=?',[time(),$kind,$id]);
  rpg_ledger($kind,$id,$request,'creation',0,'Scelte iniziali del personaggio.',$payload,$actor);return rpg_sheet($kind,$id);
 });
}

function rpg_validate_human_rule_overrides(mixed $input):array {
 if(!is_array($input)||array_diff(array_keys($input),['probability','activation','duration','bonus_text','requirement_text','mechanic']))throw new DomainException('Regole umane non valide.',422);$out=[];
 foreach(['probability'=>[1,95],'activation'=>[1,5],'duration'=>[1,10]] as $k=>[$min,$max])if(isset($input[$k]))$out[$k]=rpg_int($input[$k],$min,$max,$k);
 foreach(['bonus_text'=>500,'requirement_text'=>500,'mechanic'=>1500] as $k=>$max)if(isset($input[$k]))$out[$k]=rpg_text($input[$k],$max,true);return $out;
}
