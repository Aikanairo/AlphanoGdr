<?php
declare(strict_types=1);
require_once __DIR__.'/libequip.inc.php';
require_once __DIR__.'/rpg.inc.php';
function item_engine_ready():bool {static $r;return $r??=(bool)db()->query("SHOW TABLES LIKE 'equipment_state'");}
/** A bounded, allowlisted specification travels through the existing model approval workflow. */
function item_spec(mixed $raw):array {
 $s=is_array($raw)?$raw:rpg_decode($raw);if(!$s)return [];
 $kind=(string)($s['kind']??'tool');if(!in_array($kind,['tool','consumable','device','animal','container','feed','battery','generator'],true))throw new DomainException('Categoria funzionale non valida.',422);
 $out=['kind'=>$kind,'charges'=>rpg_int($s['charges']??0,0,100,'Cariche'),'recharge_rounds'=>rpg_int($s['recharge_rounds']??0,0,30,'Ricarica'),'cooldown_rounds'=>rpg_int($s['cooldown_rounds']??0,0,20,'Recupero'),'effects'=>[],'target'=>($s['target']??'self')==='opponent'?'opponent':'self'];
 foreach(['capacity'=>500,'stock'=>10000,'training'=>3] as $k=>$max)if(isset($s[$k]))$out[$k]=rpg_int($s[$k],0,$max,$k);
 if(isset($s['offensive_material'])){if(!in_array($s['offensive_material'],['ordinary','silver'],true))throw new DomainException('Materiale offensivo non valido.',422);$out['offensive_material']=$s['offensive_material'];}
 foreach(['diet','family','utility','ammunition','fuel'] as $k)if(isset($s[$k])&&trim((string)$s[$k])!=='')$out[$k]=rpg_text($s[$k],$k==='utility'?700:80);
 foreach(['mount','stationary'] as $k)if(isset($s[$k]))$out[$k]=(bool)$s[$k];if(isset($s['fuel_quantity']))$out['fuel_quantity']=rpg_int($s['fuel_quantity'],1,20,'Combustibile');
 if(count($s['effects']??[])>4)throw new DomainException('Massimo quattro effetti per oggetto.',422);
 foreach($s['effects']??[] as $e){if(!is_array($e)||!in_array($e['type']??'',['heal','bonus','poison','stun','antidote','analgesia','distraction'],true))throw new DomainException('Effetto non valido.',422);$stat=(string)($e['stat']??'strength');if(!in_array($stat,RPG_STATS,true))throw new DomainException('Caratteristica non valida.',422);$out['effects'][]=['type'=>$e['type'],'amount'=>rpg_int($e['amount']??0,0,$e['type']==='heal'?250:($e['type']==='analgesia'?50:20),'Intensità'),'rounds'=>rpg_int($e['rounds']??0,0,10,'Durata'),'stat'=>$stat,'test'=>rpg_text($e['test']??'',40),'family'=>rpg_text($e['family']??($s['family']??$e['type']),80),'description'=>rpg_text($e['description']??'',600)];}
 if(in_array($kind,['feed','consumable'],true))$out['charges']=1;return $out;
}
function item_instance(int $id,int $owner,bool $lock=false):array {
 $r=db()->query('SELECT p.*,e.shortDesc,e.game_rules,e.durata,t.subtipo,t.nome AS type FROM equippg p JOIN equip e ON e.id=p.uid JOIN tipo t ON t.id=e.tipo WHERE p.id=? AND p.idpg=?'.($lock?' FOR UPDATE':''),[$id,$owner])[0]??null;if(!$r)throw new DomainException('Oggetto non posseduto.',403);$r['rules']=item_spec($r['game_rules']);return $r;
}
function item_state(array $i,bool $lock=false):array {
 $s=db()->query('SELECT * FROM equipment_state WHERE instance_id=?'.($lock?' FOR UPDATE':''),[$i['id']])[0]??['instance_id'=>(int)$i['id'],'charges'=>(int)($i['rules']['charges']??0),'cycles'=>0,'ready_round'=>0,'session_id'=>0,'ready_at'=>0,'fed_until'=>0,'mode'=>'','updated_at'=>0];$s['charges']=min((int)$s['charges'],(int)($i['rules']['charges']??0));return array_map(static fn($v)=>is_numeric($v)?(int)$v:$v,$s);
}
function item_save_state(array $s):void {db()->execute('INSERT INTO equipment_state(instance_id,charges,cycles,ready_round,ready_at,fed_until,mode,session_id,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE charges=VALUES(charges),cycles=VALUES(cycles),ready_round=VALUES(ready_round),ready_at=VALUES(ready_at),fed_until=VALUES(fed_until),mode=VALUES(mode),session_id=VALUES(session_id),updated_at=VALUES(updated_at)',[$s['instance_id'],$s['charges'],$s['cycles'],$s['ready_round'],$s['ready_at'],$s['fed_until'],$s['mode'],$s['session_id']??0,time()]);}
/** Round counters apply only to the quest that created the cooldown. */
function item_effective_state(array $i,int $session=0,int $round=0):array {
 $s=item_state($i);$waiting=$session>0&&(int)($s['session_id']??0)===$session?(int)$s['ready_round']>$round:(int)$s['ready_at']>time();
 if($s['mode']==='charging'&&!$waiting){$s['mode']='';$s['charges']=$i['rules']['charges'];}
 $s['_waiting']=$waiting;return $s;
}
function item_conditions(string $kind,int $id,int $session=0,int $round=0):array {
 if(!item_engine_ready())return [];$rows=db()->query('SELECT * FROM equipment_conditions WHERE owner_kind=? AND owner_id=? AND ((session_id=? AND expires_round>=? AND session_id>0) OR (session_id=0 AND expires_at>?))',[$kind,$id,$session,$round,time()]);foreach($rows as &$r)$r['effect']=rpg_decode($r['effect']);return $rows;
}
function item_score_modifier(array $conditions,string $test,array $stats):float {
 $groups=['attack'=>['strength','dexterity','perception'],'defence'=>['dexterity','constitution','willpower'],'mental'=>['charisma','intelligence','willpower'],'resist'=>['willpower','wisdom','constitution'],'perception'=>['perception']];$families=[];
 foreach($conditions as $r){$e=$r['effect'];if(!in_array($e['type'],['bonus','distraction'],true)||!in_array($e['stat'],$groups[$test]??[],true))continue;if(($e['test']??'')!==''&&$e['test']!==$test)continue;$value=($e['type']==='distraction'?-1:1)*(int)$e['amount'];$f=$r['family'];if(!isset($families[$f])||abs($value)>abs($families[$f]))$families[$f]=$value;}
 return 1+max(-30,min(25,array_sum($families)))/100;
}
function item_stock_delivery(array $model,int $qty):void {
 $s=item_spec($model['game_rules']??'{}');if(($s['kind']??'')!=='animal')return;
 $r=db()->query('SELECT available FROM equipment_breeding WHERE model_id=? FOR UPDATE',[$model['id']])[0]??null;if(!$r||(int)$r['available']<$qty)throw new DomainException('L’allevamento non dispone di questi animali. Nessun pagamento eseguito.',409);db()->execute('UPDATE equipment_breeding SET available=available-?,updated_at=? WHERE model_id=?',[$qty,time(),$model['id']]);
}
function item_use_commit(int $owner,int $instance,string $operation,string $key,int $session=0,int $round=0,int $target=0):array {
 if(!db()->inTransaction())throw new LogicException('Uso oggetto fuori transazione.');
 $old=db()->query('SELECT after_state,instance_id,operation FROM equipment_ledger WHERE request_key=?',[$key])[0]??null;if($old){if((int)$old['instance_id']!==$instance||$old['operation']!==$operation)throw new DomainException('Richiesta già usata per un’altra operazione.',409);return rpg_decode($old['after_state']);}
 $u=db()->query('SELECT id,chat,razza,puntif,ferite FROM utenti WHERE id=? FOR UPDATE',[$owner])[0]??null;if(!$u||$u['puntif']<=0||in_array((int)$u['razza'],[4,7,8],true))throw new DomainException('Il personaggio non può usare oggetti.',403);
 $i=item_instance($instance,$owner,true);item_usage_precheck($i,$operation,$target,$round,$session);$spec=$i['rules'];if(!$spec)throw new DomainException('Questo oggetto non ha un’attivazione speciale.',422);$s=item_effective_state($i,$session,$round);unset($s['_waiting']);$before=['item'=>$i['shortDesc'],'state'=>$s];
 if($operation==='recharge'){
  if(in_array($spec['kind'],['consumable','feed','animal','container'],true)||!$spec['charges']||!$spec['recharge_rounds'])throw new DomainException('Questo oggetto non è ricaricabile.',422);
  if(($s['mode']??'')==='charging')throw new DomainException('La ricarica è già in corso.',409);
  $provider=$spec['kind']==='generator'?$i:item_instance($target,$owner,true);if(!in_array($provider['rules']['kind']??'',['generator','battery'],true)||($provider['id']===$i['id']&&$spec['kind']!=='generator'))throw new DomainException('Scegli un generatore o accumulatore compatibile posseduto.',422);$ps=item_state($provider,true);if($ps['charges']<1&&$spec['kind']!=='generator')throw new DomainException('La fonte è scarica.',409);
  if(!empty($provider['rules']['fuel'])){$needed=(int)($provider['rules']['fuel_quantity']??1);$fuel=db()->query("SELECT p.id FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.idpg=? AND p.slot='' AND e.materiaprima=? ORDER BY p.id LIMIT ".$needed." FOR UPDATE",[$owner,$provider['rules']['fuel']]);if(count($fuel)<$needed)throw new DomainException('Manca il combustibile della fonte.',409);foreach($fuel as $unit)db()->execute('DELETE FROM equippg WHERE id=?',[$unit['id']]);}
  if($provider['id']!==$i['id']){$ps['charges']--;item_save_state($ps);}$s['mode']='charging';$s['session_id']=$session;$s['ready_round']=$session?$round+$spec['recharge_rounds']:0;$s['ready_at']=time()+$spec['recharge_rounds']*60;
 }elseif($operation==='maintain'){
  $staff=db()->query('SELECT admin,corporazione,admintrasfcorp,admincorporazione FROM utenti WHERE id=?',[$owner])[0];$corp=(int)db()->query('SELECT corp FROM equip WHERE id=?',[$i['uid']])[0]['corp'];if(empty($staff['admin'])&&((int)$staff['corporazione']!==$corp||(!$staff['admintrasfcorp']&&!$staff['admincorporazione'])))throw new DomainException('Manutenzione riservata alla bottega produttrice.',403);$s['cycles']=0;$s['mode']='';
 }elseif(in_array($operation,['mount','unmount'],true)){
  if($spec['kind']!=='animal'||(empty($spec['mount'])||empty($spec['training'])))throw new DomainException('Questo animale non è una cavalcatura.',422);if($operation==='mount'){if($s['fed_until']<time())throw new DomainException('Nutri prima la cavalcatura con un alimento compatibile.',409);if(getPesoTotale($owner)>$spec['capacity'])throw new DomainException('Il carico supera la capacità della cavalcatura.',409);db()->execute("UPDATE equipment_state s JOIN equippg p ON p.id=s.instance_id SET s.mode='' WHERE p.idpg=? AND s.mode='mounted'",[$owner]);}$s['mode']=$operation==='mount'?'mounted':'';
 }elseif(in_array($operation,['pack','unpack'],true)){
  if($spec['kind']!=='container')throw new DomainException('Scegli un contenitore.',422);$child=item_instance($target,$owner,true);
  if($child['id']===$i['id']||($child['rules']['kind']??'')==='animal'||db()->query('SELECT id FROM equippg WHERE idpadre=?',[$child['id']]))throw new DomainException('Non puoi riporre questo bene nel contenitore.',422);
  if($operation==='pack'){$load=(float)db()->query('SELECT COALESCE(SUM(e.peso),0) AS total FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.idpadre=? AND p.id<>?',[$instance,$target])[0]['total'];$weight=(float)db()->query('SELECT peso FROM equip WHERE id=?',[$child['uid']])[0]['peso'];if($load+$weight>($spec['capacity']??0))throw new DomainException('Capacità del contenitore superata.',409);db()->execute("UPDATE equippg SET idpadre=?,slot='' WHERE id=?",[$instance,$target]);}else{if((int)$child['idpadre']!==$instance)throw new DomainException('Il bene non è in questo contenitore.',422);db()->execute("UPDATE equippg SET idpadre=0,slot='' WHERE id=?",[$target]);}
 }elseif($operation==='feed'){
  if($spec['kind']!=='feed')throw new DomainException('Non è un alimento per animali.',422);$animal=item_instance($target,$owner,true);if(($animal['rules']['kind']??'')!=='animal'||$animal['rules']['diet']!==$spec['diet'])throw new DomainException('Alimento non compatibile con l’animale scelto.',422);$as=item_state($animal,true);$as['fed_until']=time()+86400;item_save_state($as);db()->execute('DELETE FROM equippg WHERE id=?',[$instance]);$s['charges']=0;
 }elseif($operation==='activate'){
  if(($s['mode']??'')==='charging'){
   if(($session&&$round<$s['ready_round'])||(!$session&&time()<$s['ready_at']))throw new DomainException('La ricarica non è ancora conclusa.',409);$s['mode']='';$s['charges']=$spec['charges'];
  }
  if($s['cycles']>=5)throw new DomainException('Sono trascorsi cinque cicli: serve manutenzione della bottega.',409);
  if(($session&&(int)($s['session_id']??0)===$session)?$s['ready_round']>$round:$s['ready_at']>time())throw new DomainException('L’oggetto deve completare il recupero.',409);
  if($spec['charges']&&$s['charges']<1)throw new DomainException('Oggetto scarico: occorre ricaricarlo.',409);
  if((int)$i['consumo']>=(int)$i['durata'])throw new DomainException('Oggetto inutilizzabile.',409);
  if($spec['charges']){$s['charges']--;if($s['charges']===0)$s['cycles']++;}$s['session_id']=$session;$s['ready_round']=$session?$round+$spec['cooldown_rounds']:0;$s['ready_at']=time()+$spec['cooldown_rounds']*60;
  if(in_array($spec['kind'],['consumable','feed'],true))db()->execute('DELETE FROM equippg WHERE id=?',[$instance]);
 }else throw new DomainException('Operazione sconosciuta.',422);
 if(db()->query('SELECT id FROM equippg WHERE id=?',[$instance]))item_save_state($s);
 $after=['state'=>$s,'operation'=>$operation,'name'=>$i['shortDesc']];db()->execute('INSERT INTO equipment_ledger(request_key,actor_id,instance_id,operation,session_id,round_number,before_state,after_state,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$key,$owner,$instance,$operation,$session,$round,rpg_json($before),rpg_json($after),time()]);return $after;
}
/** Out-of-quest effects are strictly self-targeted; harmful items need a verified quest declaration. */
function item_use_personal(int $owner,int $instance,string $op,int $target,string $request):array {
 return EquipAtomico(static function()use($owner,$instance,$op,$target,$request):array{
  db()->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$owner]);$key='personal:'.$owner.':'.$request;$old=db()->query('SELECT after_state,instance_id,operation FROM equipment_ledger WHERE request_key=?',[$key])[0]??null;if($old){if((int)$old['instance_id']!==$instance||$old['operation']!==$op)throw new DomainException('Richiesta già usata per un’altra operazione.',409);return rpg_decode($old['after_state']);}
  if(function_exists('ai_quest_row')){$u=db()->query('SELECT chat FROM utenti WHERE id=?',[$owner])[0];if(ai_quest_row($u['chat']))throw new DomainException('Durante una quest dichiara l’uso dalla chat nel tuo turno.',409);}
  $i=item_instance($instance,$owner,true);$spec=$i['rules'];if($op==='activate'&&($spec['target']??'self')!=='self')throw new DomainException('Questo effetto richiede un bersaglio e la risoluzione del Master in quest.',422);
  if($op==='activate'&&array_filter($spec['effects']??[],static fn($e)=>$e['type']==='heal')){$hp=(int)db()->query('SELECT puntif FROM utenti WHERE id=?',[$owner])[0]['puntif'];if($hp>=maxpuntif)throw new DomainException('Salute già al massimo: la dose non viene consumata.',409);}
  $result=item_use_commit($owner,$instance,$op,$key,0,0,$target);
  if($op==='activate')foreach($spec['effects'] as $e){if($e['type']==='heal')db()->execute('UPDATE utenti SET puntif=LEAST(?,puntif+?) WHERE id=?',[maxpuntif,$e['amount'],$owner]);elseif($e['type']==='antidote')db()->execute("DELETE FROM equipment_conditions WHERE owner_kind='pg' AND owner_id=? AND family=?",[$owner,$e['family']]);else db()->execute("INSERT INTO equipment_conditions(owner_kind,owner_id,family,effect,expires_at,source_instance) VALUES('pg',?,?,?,?,?)",[$owner,$e['family'],rpg_json($e),time()+max(1,$e['rounds'])*60,$instance]);}
  return $result;
 });
}
function item_inventory(int $owner):array {
 if(!item_engine_ready())return [];$out=[];foreach(db()->query("SELECT p.id FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.idpg=? ORDER BY e.shortDesc,p.id",[$owner]) as $r){$i=item_instance((int)$r['id'],$owner);$out[]=['id'=>(int)$i['id'],'model'=>(int)$i['uid'],'name'=>$i['shortDesc'],'rules'=>$i['rules'],'state'=>item_state($i),'parent'=>(int)$i['idpadre']];}return $out;
}
function item_declare(int $owner,int $instance,string $operation,int $provider,int $sid,int $round):array {
 $i=item_instance($instance,$owner,true);item_usage_precheck($i,$operation,$provider,$round,$sid);$s=item_state($i,true);if(!$i['rules'])throw new DomainException('Oggetto senza proprietà speciali.',422);if(!in_array($operation,['activate','recharge','feed'],true))throw new DomainException('Operazione non dichiarabile.',422);
 if($i['rules']['kind']==='animal'||$i['rules']['kind']==='container')throw new DomainException('Gestisci questo bene dall’equipaggiamento.',422);
 $snapshot=['instance'=>$instance,'model'=>(int)$i['uid'],'name'=>$i['shortDesc'],'rules'=>$i['rules'],'state'=>$s,'provider'=>$provider,'operation'=>$operation];db()->execute("INSERT INTO equipment_declarations(session_id,round_number,owner_kind,owner_id,instance_id,operation,snapshot) VALUES(?,?,'pg',?,?,?,?)",[$sid,$round,$owner,$instance,$operation,rpg_json($snapshot)]);return ['id'=>(int)db()->lastInsertId(),'name'=>$i['shortDesc']];
}
function item_declaration(array $action,int $sid,int $round):?array {
 $d=rpg_decode($action['details']);if(empty($d['item']))return null;
 $r=db()->query('SELECT * FROM equipment_declarations WHERE id=? AND session_id=? AND round_number=? AND owner_kind=? AND owner_id=?',[(int)$d['item']['id'],$sid,$round,$action['kind'],$action['actor_id']])[0]??null;if(!$r)throw new DomainException('Dichiarazione oggetto non verificabile.',422);
 $snap=rpg_decode($r['snapshot']);$i=item_instance((int)$r['instance_id'],(int)$r['owner_id']);if($i['rules']!==$snap['rules']||item_state($i)!==$snap['state'])throw new DomainException('L’oggetto o le sue cariche sono cambiati: dichiara nuovamente l’azione.',409);return $snap;
}
function item_active_scene(array $state,int $sid,int $round,string $kind,int $id):array {
 $entries=array_values(array_filter($state['item_effects']??[],static fn($e)=>!isset($e['id'])&&(int)$e['expires_round']>=$round));return [...$entries,...item_conditions($kind,$id,$sid,$round)];
}
/** Pure projection: effects and charges are persisted only by verdict publication. */
function item_scene_action(array $declaration,array $actor,array $target,int $sid,int $round,int $roll,int $defence,array &$hp,array &$conditions,array &$cast,array &$changes,array &$result,bool $training):bool {
 $s=$declaration['rules'];$key=$actor['kind'].':'.$actor['id'];$targetKey=($target['kind']??'').':'.($target['id']??0);$owner=(int)$actor['id'];$i=item_instance((int)$declaration['instance'],$owner);item_usage_precheck($i,$declaration['operation'],(int)$declaration['provider'],$round,$sid);$state=item_effective_state($i,$sid,$round);
 if($declaration['operation']!=='activate'){$changes[]=['type'=>'item-use','kind'=>'pg','id'=>$owner,'declaration'=>$declaration];$result['outcome']=$declaration['operation']==='recharge'?'inizia una ricarica verificata':'nutre il proprio animale';$result['reason']='L’operazione usa gli oggetti effettivamente posseduti; disponibilità e tempi sono verificati alla pubblicazione.';return true;}
 if($s['target']==='opponent'&&(!$target||$targetKey===$key||$target['chat']!==$actor['chat']||in_array((int)$target['razza'],[4,7,8],true)||($hp[$targetKey]??0)<1))throw new DomainException('Scegli un bersaglio fisico presente nella quest.',422);
 if($training){$result['outcome']='simula l’impiego del manufatto';$result['reason']='L’allenamento non consuma dosi né cariche e non applica effetti persistenti.';return true;}
 if($s['effects']&&count($s['effects'])===1&&$s['effects'][0]['type']==='heal'&&$hp[$key]>=maxpuntif){$result['outcome']='conserva la dose';$result['reason']='La salute è già al massimo: nessuna dose viene consumata.';return true;}
 $changes[]=['type'=>'item-use','kind'=>'pg','id'=>$owner,'declaration'=>$declaration];$text=[];$harmSucceeded=true;
 if($s['target']==='opponent'){$attack=rpg_actor_stats($actor['kind'],$owner)['dexterity']+$roll;$resist=rpg_actor_stats($target['kind'],(int)$target['id'])['constitution']+$defence;$harmSucceeded=$attack>$resist;$text[]=$harmSucceeded?'Il bersaglio non supera la prova di resistenza.':'Il bersaglio resiste: la dose o carica impiegata è consumata, ma l’effetto ostile non si applica.';}
 foreach($s['effects'] as $e){$hostile=in_array($e['type'],['poison','stun','distraction'],true);if($hostile&&!$harmSucceeded)continue;$dest=$hostile?$targetKey:$key;
  if($e['type']==='heal'){$gain=min($e['amount'],maxpuntif-$hp[$key]);$hp[$key]+=$gain;$result['healing']=$gain;$text[]='Recupera '.$gain.' PF senza cancellare ferite permanenti.';}
  elseif($e['type']==='antidote'){$conditions[$key]['item_effects']=array_values(array_filter($conditions[$key]['item_effects']??[],static fn($r)=>$r['effect']['type']!=='poison'||$r['family']!==$e['family']));$text[]='Il veleno della famiglia '.$e['family'].' viene neutralizzato, senza recuperare danni già subiti.';}
  else{$entry=['family'=>$e['family'],'effect'=>$e,'expires_round'=>$round+max(1,$e['rounds'])-($e['type']==='poison'?0:1),'last_tick'=>0];$conditions[$dest]['item_effects']=array_values(array_filter($conditions[$dest]['item_effects']??[],static fn($r)=>$r['family']!==$e['family']||$r['effect']['type']!==$e['type']));$conditions[$dest]['item_effects'][]=$entry;$cast[$dest]['_item_effects']=array_values($conditions[$dest]['item_effects']);$text[]=$e['description'];}
 }
 $result['outcome']='impiega '.$declaration['name'];$result['reason']=implode(' ',$text)?:($s['utility']??'L’oggetto viene usato entro le possibilità effettive della scena, senza rivelare informazioni nascoste.');
 // A ranged device uses its registered magazine/cartridge, and still resolves an opposed attack.
 return !in_array((int)$i['subtipo'],[2,15],true);
}
function item_pain_factor(array $effects):float {$max=0;foreach($effects as $r)if($r['effect']['type']==='analgesia')$max=max($max,(int)$r['effect']['amount']);return 1-min(50,$max)/100;}
function item_usage_precheck(array $i,string $op,int $target,int $round=0,int $session=0):void {
 $s=item_effective_state($i,$session,$round);$spec=$i['rules'];if(!$spec)throw new DomainException('Oggetto privo di proprietà attivabili.',422);
 if(in_array($op,['activate','recharge','mount','pack','feed'],true)&&(int)$i['durata']>0&&(int)$i['consumo']>=(int)$i['durata'])throw new DomainException('L’oggetto è rotto e non può essere usato.',409);
 if($op==='activate'){
  if($s['_waiting']||$s['mode']==='charging'||($spec['charges']&&$s['charges']<1)||$s['cycles']>=5||(int)$i['consumo']>=(int)$i['durata'])throw new DomainException('Oggetto scarico, in recupero o da revisionare.',409);
  if(in_array($spec['kind'],['animal','container','feed'],true))throw new DomainException('Scegli l’operazione specifica per questo bene.',422);
  if(in_array((int)$i['subtipo'],[2,15],true)&&!in_array($i['slot'],['armadx','armasx'],true))throw new DomainException('Prima impugna l’arma reale.',422);
  if((int)$i['subtipo']===5&&$i['slot']==='')throw new DomainException('Prima indossa la protezione.',422);
 }
 if($op==='recharge'){
  if(!$spec['charges']||!$spec['recharge_rounds']||in_array($spec['kind'],['animal','container','consumable','feed'],true))throw new DomainException('Oggetto non ricaricabile.',422);
  if($s['mode']==='charging')throw new DomainException('La ricarica è già in corso.',409);
  if($s['charges']>=$spec['charges'])throw new DomainException('Le cariche sono già complete.',409);
  if($s['cycles']>=5)throw new DomainException('Serve manutenzione della bottega.',409);
  $provider=$i;
  if($spec['kind']!=='generator'){$provider=item_instance($target,(int)$i['idpg']);$ps=item_effective_state($provider,$session,$round);if($provider['id']===$i['id']||!in_array($provider['rules']['kind']??'',['generator','battery'],true)||$ps['charges']<1||$ps['mode']==='charging'||$ps['_waiting']||((int)$provider['durata']>0&&(int)$provider['consumo']>=(int)$provider['durata']))throw new DomainException('Fonte energetica non disponibile.',409);}
  if(!empty($provider['rules']['fuel'])){$needed=(int)($provider['rules']['fuel_quantity']??1);$fuel=db()->query("SELECT p.id FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.idpg=? AND p.slot='' AND e.materiaprima=? LIMIT ".$needed,[(int)$i['idpg'],$provider['rules']['fuel']]);if(count($fuel)<$needed)throw new DomainException('Manca il combustibile della fonte.',409);}
 }
 if($op==='feed'){$a=item_instance($target,(int)$i['idpg']);if($spec['kind']!=='feed'||($a['rules']['kind']??'')!=='animal'||$a['rules']['diet']!==$spec['diet'])throw new DomainException('Alimento e animale non compatibili.',422);}
}
