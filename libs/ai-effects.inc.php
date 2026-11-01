<?php
declare(strict_types=1);
require_once __DIR__.'/libferite.inc.php';
require_once __DIR__.'/libblocco.inc.php';
require_once __DIR__.'/rpg-combat.inc.php';
/** Technical resolution belongs here. No numeric rule is inferred from prose or general lore. */
function ai_quest_technical(int $sid):array{
 $mode=ai_db()->query('SELECT policy FROM ai_quests WHERE session_id=?',[$sid])[0]??null;
 if($mode&&ai_scene_kind($mode)==='narrazione')return alphano_session_narrative_technical($sid);
 if(ai_adjudication_enabled($sid))return ai_adjudicate($sid);
 $db=ai_db();$g=ai_game_guidance($sid);$q=$db->query('SELECT * FROM ai_quests WHERE session_id=?',[$sid])[0];$p=json_decode($q['policy'],true)?:[];$current=ai_knowledge_policy((int)($p['knowledge_id']??0));
 $actions=$db->query('SELECT * FROM ai_quest_actions WHERE session_id=? AND round_number=? ORDER BY id',[$sid,(int)$g['round_number']]);$order=json_decode($g['turn_order'],true)?:[];
 if(count($actions)!==count($order))throw new DomainException('Il giro non è completo: nessun verdetto disponibile.',409);
 $reasons=[];$changes=[];$results=[];$automatic=($current['resolution']??'review')==='opposed_force_d20'&&isset($current['damage']);
 $needsRules=ai_scene_kind($q)!=='allenamento'&&!ai_scene_can_finish($sid);
 if(!$automatic&&$needsRules)$reasons[]='Manca una meccanica di risoluzione ufficiale configurata';
 if(isset($p['knowledge_version'])&&($p['knowledge_version']!==($current['knowledge_version']??null)))$reasons[]='La versione della regola tecnica è cambiata';
 foreach($actions as $a){
  $d=json_decode($a['details'],true)?:[];$actor=ai_quest_actor($a['kind'],(int)$a['actor_id']);$entry=['id'=>(int)$a['id'],'actor'=>$actor['name']??'Assente','attempt'=>ai_text($a['content'],210),'outcome'=>'tentativo'];
  if($a['status']==='skipped'){$entry['outcome']='turno saltato';$results[]=$entry;continue;}
  $state=json_decode($db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$a['kind'],(int)$a['actor_id']])[0]['state']??'{}',true)?:[];
  if(($actor['puntif']??0)<1){$entry['outcome']='azione impossibile: personaggio incapace';$results[]=$entry;continue;}
  if(in_array($d['intent']??'',['difesa','negozia','ritirata','resa','aiuto','azione','fine','allenamento'],true)){$entry['outcome']='intenzione dichiarata; nessun danno automatico';$results[]=$entry;continue;}
  if(trim((string)($actor['ferite']??''))!=='')$reasons[]='Le ferite persistenti di '.$entry['actor'].' richiedono una valutazione fisica del Master';
  $targetKind=$d['target_kind']??'';$targetId=(int)($d['target_id']??0);$target=$targetId&&in_array($targetKind,['pg','npc'],true)?ai_quest_actor($targetKind,$targetId):[];
  if(!$target||!$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=? AND active=1',[$sid,$targetKind,$targetId])){$reasons[]='Bersaglio da precisare per '.$entry['actor'];$results[]=$entry;continue;}
  if(($d['intent']??'')==='potere'){$rules=ai_game_rules((int)$actor['razza'],(bool)($actor['senziente']??0),$a['content']);$entry['racial_rules']=$rules;$reasons[]='Potere razziale da valutare secondo il manuale della stirpe effettiva';$results[]=$entry;continue;}
  if(($d['intent']??'')==='ricarica'){
   $items=ai_game_inventory($a['kind'],(int)$a['actor_id']);$held=rpg_declared_weapon($items,$a['content'],true);$ammo=$held?array_filter($items,static fn($i)=>(int)$i['id']===(int)($state['ammo_instance']??0)&&rpg_ranged_ammo($i,$held)&&(int)$i['consumo']<(int)$i['durata']):[];
   if(!$held||!$ammo){$reasons[]='Ricarica: arma o munizioni compatibili non disponibili';$results[]=$entry;continue;}
   if(!isset($current['reload_rounds'],$state['ammo_instance'])||(int)$g['round_number']-(int)($state['last_shot_round']??0)<(int)$current['reload_rounds']){$reasons[]='Ricarica: tempo o munizioni non verificati';$results[]=$entry;continue;}
   $entry['outcome']='ricarica verificabile dal backend';$changes[]=['type'=>'reload','kind'=>$a['kind'],'id'=>(int)$a['actor_id']];$results[]=$entry;continue;
  }
  $inventory=ai_game_inventory($a['kind'],(int)$a['actor_id']);$weapon=rpg_declared_weapon($inventory,$a['content']);
  $raw=mb_strtolower($a['content']);$ranged=($weapon&&rpg_ranged_family($weapon)!=='')||(bool)preg_match('/balestra|dardo|scocca|freccia|\barco\b|fucil|pistol|moschett|carabin|trombon|archibug|\bspar\p{L}*/u',$raw);$charge=(bool)preg_match('/carica|galoppo/u',$raw);
  if($weapon&&(int)$weapon['consumo']>=(int)$weapon['durata']){$entry['outcome']='arma inutilizzabile';$results[]=$entry;continue;}
  if(!$weapon&&!preg_match('/pugno|calcio|mani nude|artiglio|morso|\bmord\p{L}*/iu',$raw)){$reasons[]='Arma impugnata non registrata per '.$entry['actor'];$results[]=$entry;continue;}
  if($ranged){
   if(!$weapon||rpg_ranged_family($weapon)===''){$entry['outcome']='nessuna arma da tiro impugnata';$results[]=$entry;continue;}
   if(!isset($state['ammo'],$state['ammo_instance'],$state['loaded'])){$reasons[]='Munizioni o caricamento non verificati per '.$entry['actor'];$results[]=$entry;continue;}
   if((int)$state['ammo']<1||!$state['loaded']){$entry['outcome']='tiro impossibile: arma scarica o senza munizioni';$results[]=$entry;continue;}
   $ammo=array_values(array_filter($inventory,static fn($i)=>(int)$i['id']===(int)$state['ammo_instance']&&rpg_ranged_ammo($i,$weapon)));
   if(!$ammo||(int)$ammo[0]['durata']-(int)$ammo[0]['consumo']<(int)$state['ammo']){$reasons[]='Munizioni dichiarate non corrispondenti all’inventario';$results[]=$entry;continue;}
   $last=(int)($state['last_shot_round']??0);if($last&&(int)$g['round_number']-$last<=(int)($current['reload_rounds']??0)){$entry['outcome']='ricarica non ancora completata';$results[]=$entry;continue;}
  }
  if(!isset($state['distance'])){$reasons[]='Distanza non verificata per '.$entry['actor'];$results[]=$entry;continue;}
  if(!isset($current['max_range'])){$reasons[]='Portata non definita nella regola selezionata';$results[]=$entry;continue;}
  if((int)$state['distance']>(int)$current['max_range']){$entry['outcome']='bersaglio fuori portata';$results[]=$entry;continue;}
  if($charge&&(!isset($state['mount_instance'],$state['mount_trained'],$state['clear_space'],$current['charge_space'])||!$state['mount_trained']||(int)$state['clear_space']<(int)$current['charge_space'])){$entry['outcome']='carica non praticabile: cavalcatura o spazio non verificati';$results[]=$entry;continue;}
  if($charge){$mount=array_filter($inventory,static fn($i)=>(int)$i['id']===(int)$state['mount_instance']&&preg_match('/cavall|destrier|corsier/iu',$i['shortDesc'].' '.$i['type']));if(!$mount){$entry['outcome']='cavalcatura non posseduta';$results[]=$entry;continue;}}
  if(($d['intent']??'')==='potere'){$rules=ai_game_rules((int)$actor['razza'],(bool)($actor['senziente']??0),$a['content']);$entry['racial_rules']=$rules;$reasons[]='Potere razziale da valutare secondo il manuale della stirpe effettiva';$results[]=$entry;continue;}
  if(($d['intent']??'')!=='assalto'&&($d['intent']??'')!=='attacco'){$reasons[]='Esito speciale da convalidare: '.($d['intent']??'azione');$results[]=$entry;continue;}
  if(!$automatic){$results[]=$entry;continue;}
  $targetAction=$db->query('SELECT details FROM ai_quest_actions WHERE session_id=? AND round_number=? AND kind=? AND actor_id=?',[$sid,(int)$g['round_number'],$targetKind,$targetId])[0]??[];
  $targetDetails=json_decode($targetAction['details']??'{}',true)?:[];$defence=(int)($targetDetails['roll']??$state['defence_roll']??0);if($defence<1||$defence>20){$reasons[]='Tiro difensivo non ancora registrato per '.$entry['actor'];$results[]=$entry;continue;}
  $atk=(int)$actor['forza']+(int)$d['roll'];$def=(int)$target['forza']+$defence;$damage=$atk>$def?(int)$current['damage']:0;
  $armored=array_filter(ai_game_inventory($targetKind,$targetId),static fn($i)=>$i['slot']==='armatura'&&(int)$i['consumo']<(int)$i['durata']);if($armored)$damage=max(0,$damage-(int)($current['armor_absorption']??0));$entry['outcome']=$damage?'successo tecnico':'difesa riuscita';$entry['calculation']=['attack'=>$atk,'defence'=>$def,'rule'=>(int)$current['knowledge_id']];
  if(ai_npc_super_decisive_damage((int)$target['puntif'],$damage)&&($gate=ai_npc_super_encounter_check($sid,$actor,$target))){$entry['specialist_encounter']=$gate;if(!$gate['allowed']){$damage=0;$entry['outcome']='il tentativo decisivo fallisce e il PNG ripiega';$reasons[]='Il PNG non dispone di un varco decisivo contro la forma speciale: il Master deve gestire il suo ripiegamento.';}}
  if($damage)$changes[]=['type'=>'hp','kind'=>$targetKind,'id'=>$targetId,'delta'=>-$damage];
  if($ranged)$changes[]=['type'=>'ammunition','kind'=>$a['kind'],'id'=>(int)$a['actor_id'],'round'=>(int)$g['round_number']];$results[]=$entry;
 }
 $totals=[];foreach($changes as $change)if($change['type']==='hp'){$key=$change['kind'].':'.$change['id'];$totals[$key]=($totals[$key]??0)+(int)$change['delta'];}foreach($totals as $key=>$delta){[$targetKind,$targetId]=explode(':',$key);$sheet=ai_quest_actor($targetKind,(int)$targetId);if((int)$sheet['puntif']+$delta<1||(int)$sheet['puntif']+$delta>maxpuntif)$reasons[]='Esito cumulativo sui PF da convalidare: morte e superamento dei limiti non sono automatici';}
 if($changes&&array_filter($changes,static fn($c)=>in_array($c['type'],['hp','ammunition','reload'],true)&&$c['kind']==='pg')&&empty($p['mandate']))$reasons[]='Manca il mandato di un Master umano per le modifiche alle schede PG';
 $query=implode(' ',array_column($actions,'content'));$knowledge=[];foreach(ai_knowledge_retrieve($query,2) as $k)$knowledge[]=['id'=>(int)$k['id'],'title'=>$k['title'],'origin'=>$k['origin'],'source'=>$k['source'],'summary'=>ai_text($k['summary'],300)];
 $facts=[];foreach($db->query('SELECT kind,actor_id FROM ai_quest_cast WHERE session_id=? AND active=1 ORDER BY kind,actor_id',[$sid]) as $c){$sheet=ai_quest_actor($c['kind'],(int)$c['actor_id']);$items=[];foreach(ai_game_inventory($c['kind'],(int)$c['actor_id']) as $item)if($item['slot']!=='')$items[]=ai_text($item['nome']?:$item['shortDesc'],40).' ('.$item['slot'].')';$facts[]=['kind'=>$c['kind'],'id'=>(int)$c['actor_id'],'name'=>$sheet['name'],'hp'=>(int)($sheet['puntif']??0),'force'=>$sheet['forza']??null,'dexterity'=>null,'roles'=>$c['kind']==='pg'?ai_authority_roles(ai_player_identity((int)$c['actor_id'])):'PNG del luogo','profile'=>ai_master_profile_summary($sheet),'skills'=>rpg_ai_skill_facts(rpg_sheet($c['kind'],(int)$c['actor_id'],false),$query),'equipment'=>array_slice($items,0,6),'wounds'=>ai_text((string)($sheet['ferite']??''),100)];}
 require_once __DIR__.'/revelations.inc.php';
 return ['public_scene'=>ai_scene_context((string)$q['chat_key']),'individual_revelations'=>alphano_revelations_master_context((string)$q['chat_key']),'knowledge_boundary'=>'Solo i singoli osservatori elencati conoscono la rivelazione. Non comunicarla agli altri PG o PNG.','combatants'=>$facts,'knowledge'=>$knowledge,'round'=>(int)$g['round_number'],'review'=>(bool)$reasons,'reasons'=>array_values(array_unique($reasons)),'results'=>$results,'allowed_changes'=>$changes,'can_finish'=>ai_scene_can_finish($sid),'rule'=>$current['title']??'Valutazione del Master richiesta'];
}
function ai_quest_lock_sheets(int $sid):void{
 $db=ai_db();$ids=$db->query('SELECT kind,actor_id FROM ai_quest_cast WHERE session_id=? ORDER BY kind,actor_id',[$sid]);foreach($ids as $p){$table=$p['kind']==='pg'?'utenti':'ai_npcs';$db->query('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE',[(int)$p['actor_id']]);$db->query('SELECT id FROM equippg WHERE idpg=? ORDER BY id FOR UPDATE',[$p['kind']==='pg'?(int)$p['actor_id']:ai_npc_owner((int)$p['actor_id'])]);}
}
function ai_effect_state(string $kind,int $id):array{
 $db=ai_db();if($kind==='pg')return $db->query('SELECT puntif,ferite,razza,infetto,ancestrale,senziente,datamorte,imprigionato FROM utenti WHERE id=?',[$id])[0]??[];
 $r=$db->query('SELECT profile,active FROM ai_npcs WHERE id=?',[$id])[0]??null;return $r?['profile'=>ai_npc_profile($r),'active'=>(int)$r['active'],'game'=>ai_npc_game($id)]:[];
}
/** No effect is executable from a model response: only the validated round's allowlist or a signed Master review. */
function ai_quest_apply_verdict(int $vid,int $reviewer,string $reason,?array $reviewed=null,bool $irreversible=false):void{
 $db=ai_db();if(!$db->inTransaction())throw new LogicException('Verdetto e conseguenze devono condividere una transazione.');
 $v=$db->query('SELECT * FROM ai_quest_verdicts WHERE id=? FOR UPDATE',[$vid])[0]??null;if(!$v)throw new DomainException('Verdetto non disponibile.',404);if($v['status']==='applied')return;if($v['status']!=='review')throw new DomainException('Verdetto non applicabile.',409);
 $sid=(int)$v['session_id'];$q=$db->query('SELECT * FROM ai_quests WHERE session_id=? FOR UPDATE',[$sid])[0];$s=$db->query('SELECT * FROM sessioni WHERE id=? FOR UPDATE',[$sid])[0];if(!$s['run'])throw new DomainException('Quest terminata.',409);ai_quest_lock_sheets($sid);
 if(!hash_equals($v['snapshot'],ai_quest_snapshot($sid)))throw new DomainException('Schede o regia cambiate. Rigenera il verdetto prima di applicarlo.',409);
 if(!$reviewer&&ai_adjudication_enabled($sid)){ai_adjudication_apply($v,$q,$s);return;}
 $policy=json_decode($q['policy'],true)?:[];$executor=$reviewer?:((int)($policy['mandate']??0));
 if($reviewer){$u=$db->query('SELECT admin,fatepower,chat,bloccato FROM utenti WHERE id=?',[$reviewer])[0]??[];if((empty($u['admin'])&&empty($u['fatepower']))||!empty($u['bloccato'])||$u['chat']!==$s['chat'])throw new DomainException('Convalida riservata a un Master presente nella chat.',403);}
 $changes=$reviewed??json_decode($v['payload'],true);if(!is_array($changes)||!array_is_list($changes)||count($changes)>30)throw new DomainException('Conseguenze non valide.',422);
 if(!$reviewer){$technical=ai_quest_technical($sid);if($technical['review']||ai_quest_json($changes)!==ai_quest_json($technical['allowed_changes']))throw new DomainException('Il verdetto necessita della convalida umana.',409);}
 foreach($changes as $i=>$e){
  if(!is_array($e)||!in_array($e['kind']??'',['pg','npc'],true))throw new DomainException('Destinatario non valido.',422);$id=ai_integer($e,'id',1,2147483647);$kind=$e['kind'];$type=ai_field($e,'type',30,true);
  if(!$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$kind,$id]))throw new DomainException('Destinatario estraneo alla quest.',422);
  $key='verdict:'.$vid.':effect:'.$i;if($db->query('SELECT id FROM ai_quest_effects WHERE effect_key=?',[$key]))continue;$before=ai_effect_state($kind,$id);if(!$before)throw new DomainException('Scheda non disponibile.',404);
  if($type==='hp'){$delta=ai_integer($e,'delta',-maxpuntif+1,maxpuntif-1);$hp=$kind==='pg'?(int)$before['puntif']:(int)$before['profile']['hit_points'];$next=$hp+$delta;if($next<1||$next>maxpuntif)throw new DomainException('PF fuori limite: la morte richiede una convalida esplicita separata.',422);
   if($kind==='pg'){if(!SetFerite($id,$next,(string)$before['ferite'],$executor,$s['chat']))throw new DomainException('Modifica PF non consentita.',422);}
   else{$profile=$before['profile'];$profile['hit_points']=$next;require_once __DIR__.'/npc-mortality.inc.php';alphano_npc_assert_profile_edit($id,$profile,(int)$before['active']);$db->execute('UPDATE ai_npcs SET profile=? WHERE id=?',[ai_quest_json($profile),$id]);}
  }elseif(in_array($type,['wound','death','prison','note','condition'],true)){
   if(!$reviewer||!$irreversible)throw new DomainException('Conseguenza permanente: serve conferma esplicita del Master.',422);$text=ai_field($e,'text',250,true);
   if($type==='death'){if($kind==='pg'){if(!Uccidi($id,$executor,ai_integer($e,'spirit',0,1),$s['chat']))throw new DomainException('Morte non applicabile.',422);}else{$profile=$before['profile'];$db->execute('UPDATE ai_npcs SET active=0 WHERE id=?',[$id]);$profile['hit_points']=0;$profile['wounds']=ai_text($profile['wounds'].'; '.$text,500);$db->execute('UPDATE ai_npcs SET profile=? WHERE id=?',[ai_quest_json($profile),$id]);}}
   elseif($kind==='pg'){
    if($type==='wound'){if(!SetFerite($id,(int)$before['puntif'],ai_text($before['ferite'].'; '.$text,255),$executor,$s['chat']))throw new DomainException('Ferita non registrabile.',422);}
    elseif($type==='prison'){$value=ai_integer($e,'city_id',0,2147483647);$ok=$value?Incarcera($id,$value,$executor):Scarcera($id,$executor);if(!$ok)throw new DomainException('Città di detenzione non valida.',422);InsertNote($id,$executor,$text);}
    else InsertNote($id,$executor,$text);
   }else{$profile=$before['profile'];$field=$type==='wound'?'wounds':'notes';$profile[$field]=ai_text($profile[$field]."\n".$text,$field==='wounds'?500:3000);$db->execute('UPDATE ai_npcs SET profile=? WHERE id=?',[ai_quest_json($profile),$id]);$g=ai_npc_game($id);$g['conditions']=ai_text($g['conditions'].'; '.$text,1500);$db->execute('INSERT INTO ai_npc_game(npc_id,settings,updated_by,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),version=version+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$id,ai_quest_json($g),$reviewer,time()]);}
  }elseif(in_array($type,['ammunition','reload'],true)){
   $c=$db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=? FOR UPDATE',[$sid,$kind,$id])[0];$state=json_decode($c['state'],true)?:[];
   $owner=$kind==='pg'?$id:ai_npc_owner($id);$ammoItem=$db->query('SELECT p.*,e.shortDesc,e.durata,t.nome AS type FROM equippg p JOIN equip e ON e.id=p.uid LEFT JOIN tipo t ON t.id=e.tipo WHERE p.id=? AND p.idpg=? FOR UPDATE',[(int)($state['ammo_instance']??0),$owner])[0]??null;
   $held=rpg_declared_weapon(ai_game_inventory($kind,$id),'',true);
   if(!$ammoItem||!$held||!rpg_ranged_ammo($ammoItem,$held))throw new DomainException('Munizioni effettive non disponibili.',409);
   $before=['cast'=>$state,'ammo_item'=>array_intersect_key($ammoItem,array_flip(['id','uid','idpg','slot','nome','consumo','idpadre']))];
   if($type==='reload'){if(!isset($policy['reload_rounds'])||(int)$v['round_number']-(int)($state['last_shot_round']??0)<(int)$policy['reload_rounds'])throw new DomainException('Tempo di ricarica non trascorso o non configurato.',409);$state['loaded']=true;}
   else{if(empty($state['loaded'])||(int)($state['ammo']??0)<1)throw new DomainException('Arma già scarica o senza munizioni.',409);$state['ammo']--;
   if($kind==='pg'){if(!ConsumaMaster($id,(int)$state['ammo_instance'],1,$executor,$s['chat'],$key))throw new DomainException('Munizioni non consumabili.',422);}elseif(!Consuma($owner,(int)$state['ammo_instance'],1))throw new DomainException('Munizioni non consumabili.',422);$state['loaded']=false;$state['last_shot_round']=ai_integer($e,'round',1,PHP_INT_MAX);}$db->execute('UPDATE ai_quest_cast SET state=? WHERE session_id=? AND kind=? AND actor_id=?',[ai_quest_json($state),$sid,$kind,$id]);
  }elseif($type==='money'){
   if(!$reviewer||$kind!=='pg')throw new DomainException('Trasferimento da convalidare tra un PG e un PNG.',422);$npc=ai_integer($e,'npc_id',1,2147483647);if(!$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=\'npc\' AND actor_id=?',[$sid,$npc]))throw new DomainException('PNG estraneo alla quest.',422);
   $amount=ai_integer($e,'amount',1,1000000000);$direction=ai_field($e,'direction',10,true);if(!in_array($direction,['to_npc','to_player'],true))throw new DomainException('Direzione non valida.',422);
   $before=['money'=>['npc'=>$npc,'player'=>$id,'npc_balance'=>ai_npc_balance($npc),'player_balance'=>(int)$db->query('SELECT denari FROM utenti WHERE id=?',[$id])[0]['denari']]];
   $tx=ai_money_transfer($npc,$id,$direction==='to_npc'?'theft_resolved':'withdraw',$amount,$reason,$key,$reviewer);$before['money']['transaction_id']=(int)$tx['id'];
  }elseif($type==='item'){
   if(!$reviewer)throw new DomainException('Trasferimento oggetti da convalidare.',422);$instance=ai_integer($e,'instance',1,PHP_INT_MAX);$fromKind=ai_field($e,'from_kind',3,true);$from=ai_integer($e,'from_id',1,2147483647);if(!in_array($fromKind,['pg','npc'],true)||!$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$fromKind,$from]))throw new DomainException('Proprietario estraneo alla quest.',422);
   $owner=$fromKind==='pg'?$from:ai_npc_owner($from);$row=$db->query('SELECT * FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[$instance,$owner])[0]??null;if(!$row||$db->query('SELECT id FROM equippg WHERE idpadre=?',[$instance]))throw new DomainException('Oggetto non disponibile o contenitore non vuoto.',422);$before=['item'=>$row];$db->execute("UPDATE equippg SET idpg=?,slot='',idpadre=0 WHERE id=?",[$kind==='pg'?$id:ai_npc_owner($id),$instance]);
  }else throw new DomainException('Tipo di conseguenza non autorizzato.',422);
  $after=match($type){'ammunition','reload'=>['cast'=>json_decode($db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$kind,$id])[0]['state'],true),'ammo_item'=>$db->query('SELECT * FROM equippg WHERE id=?',[(int)$before['ammo_item']['id']])[0]??null],'item'=>['item'=>$db->query('SELECT * FROM equippg WHERE id=?',[$instance])[0]],'money'=>['money'=>['transaction_id'=>(int)$tx['id'],'npc'=>$npc,'player'=>$id,'npc_balance'=>ai_npc_balance($npc),'player_balance'=>(int)$db->query('SELECT denari FROM utenti WHERE id=?',[$id])[0]['denari']]],default=>ai_effect_state($kind,$id)};
  $db->execute('INSERT INTO ai_quest_effects(verdict_id,effect_key,effect_type,target_kind,target_id,before_state,after_state,actor_id,reason,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$vid,$key,$type,$kind,$id,ai_quest_json($before),ai_quest_json($after),$executor,ai_text($reason,500),time()]);
  if($kind==='npc'&&$type==='death'){require_once __DIR__.'/npc-mortality.inc.php';alphano_npc_record_effect_death($key,$e,$reviewer);}
  ai_quest_audit($sid,'effect_applied',['effect'=>$e,'before'=>$before,'after'=>$after],$executor);
  // Verified consequences become durable pair memories, not model-invented facts.
  if(!($kind==='pg'&&in_array($type,['note','condition'],true))){
   $players=$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=\'pg\' AND active=1',[$sid]);$npcs=$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=\'npc\' AND active=1',[$sid]);
   foreach($players as $pg)foreach($npcs as $n){
    if($type==='money'&&((int)$n['actor_id']!==(int)$e['npc_id']||(int)$pg['actor_id']!==$id))continue;
    if($kind==='npc'&&in_array($type,['note','condition'],true)&&(int)$n['actor_id']!==$id)continue;
    $detail=match($type){'wound'=>ai_field($e,'text',250),'death'=>'morte convalidata','prison'=>'condizione di detenzione aggiornata','money'=>'scambio convalidato tra i due interlocutori','item'=>'passaggio di un oggetto convalidato','hp'=>'condizione fisica aggiornata','ammunition'=>'tiro e consumo di una munizione','reload'=>'ricarica verificata',default=>'annotazione pubblica del personaggio aggiornata'};
    $summary='Quest #'.$sid.', giro '.$v['round_number'].': '.ai_quest_name($kind,$id).' — '.$detail.'. Le motivazioni riservate del Master non sono conoscenze del PNG.';
    $db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,created_at,updated_at) VALUES(?,?,?,'quest',?,?,'system',?,?)",[(int)$n['actor_id'],(int)$pg['actor_id'],$s['chat'],$summary,'effect:'.$key,time(),time()]);
   }
  }
 }
 $db->execute("UPDATE ai_quest_verdicts SET status='applied',payload=?,reviewed_by=? WHERE id=?",[ai_quest_json($changes),$executor,$vid]);ai_quest_audit($sid,'verdict_applied',['verdict'=>$vid,'effects'=>count($changes),'reason'=>$reason],$executor);
 ai_quest_publish($sid,'verdict:'.$vid.':published','Verdetto · giro '.$v['round_number']."\n".ai_verdict_public_text($v),'<div class="MasterKey">'.ai_verdict_public_html($v).'</div>');
 ai_npc_last_actions($v);
 ai_quest_next_round($sid,$executor,!empty(json_decode($v['technical'],true)['scene_finished']));
}
/** Shared progression after a validated verdict or an explicitly effect-free narrative round. */
function ai_quest_next_round(int $sid,int $executor=0,bool $finish=false):void{
 $db=ai_db();if(!$db->inTransaction())throw new LogicException('Avanzamento fuori transazione.');
 $s=$db->query('SELECT * FROM sessioni WHERE id=? FOR UPDATE',[$sid])[0];$q=$db->query('SELECT * FROM ai_quests WHERE session_id=? FOR UPDATE',[$sid])[0];$policy=json_decode($q['policy'],true)?:[];
 if($finish&&ai_scene_can_finish($sid)){ai_scene_close($sid,'Il Master IA ha riconosciuto la conclusione della scena dopo il giro completo.',$executor);return;}
 $g=ai_game_guidance($sid);$order=json_decode($g['turn_order'],true)?:[];
 $order=array_values(array_filter($order,static function($member)use($db,$sid){$c=$db->query('SELECT active,state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$member['kind'],(int)$member['id']])[0]??[];return !empty($c['active'])&&empty(json_decode($c['state']??'{}',true)['withdrew']);}));
 $remaining=['pg'=>0,'npc'=>0];foreach($order as $member){$sheet=ai_quest_actor($member['kind'],(int)$member['id']);if($sheet&&$sheet['chat']===$s['chat']&&($sheet['puntif']??0)>0&&($member['kind']==='pg'||!empty($sheet['active'])))$remaining[$member['kind']]++;}
 if(!$remaining['pg']||(ai_scene_kind($q)!=='narrazione'&&(($policy['encounter']??'npc')==='pvp'?array_sum($remaining)<2:!$remaining['npc']))){ai_scene_close($sid,'Non restano partecipanti attivi della scena nel luogo.',$executor);return;}
 foreach($db->query('SELECT kind,actor_id,state FROM ai_quest_cast WHERE session_id=? AND active=0',[$sid]) as $c){$state=json_decode($c['state'],true)?:[];if(empty($state['dead'])&&empty($state['withdrew'])&&(int)($state['join_round']??PHP_INT_MAX)<=(int)$g['round_number']+1){$order[]=['kind'=>$c['kind'],'id'=>(int)$c['actor_id']];$db->execute('UPDATE ai_quest_cast SET active=1 WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$c['kind'],(int)$c['actor_id']]);}}
 if(($policy['initiative']??'entry')==='force')usort($order,static fn($a,$b)=>(int)(ai_quest_actor($b['kind'],$b['id'])['forza']??0)<=>(int)(ai_quest_actor($a['kind'],$a['id'])['forza']??0));
 $room=ai_room($s['chat']);if(ai_scene_kind($q)!=='narrazione'&&($room['quest_rules']??'legacy')==='alphano_scene_v1'&&empty($policy['knowledge_id'])&&($policy['resolution']??'')!=='alphano_scene_v1'){$policy['resolution']='alphano_scene_v1';$db->execute('UPDATE ai_quests SET policy=? WHERE session_id=?',[ai_quest_json($policy),$sid]);}
 $db->execute('UPDATE ai_session_guidance SET turn_order=?,turn_index=0,turn_started_at=?,round_number=round_number+1,version=version+1,updated_at=? WHERE session_id=?',[ai_quest_json($order),time(),time(),$sid]);$db->execute("UPDATE ai_quests SET status='active',version=version+1 WHERE session_id=?",[$sid]);ai_quest_schedule(ai_quest_row($s['chat'],true));
 ai_quest_publish_queue($sid,'quest:'.$sid.':round:'.((int)$g['round_number']+1));
}
function ai_quest_reverse_effect(int $id,int $reviewer,string $reason):void{
 $db=ai_db();$e=$db->query('SELECT e.*,v.session_id FROM ai_quest_effects e JOIN ai_quest_verdicts v ON v.id=e.verdict_id WHERE e.id=? FOR UPDATE',[$id])[0]??null;if(!$e)throw new DomainException('Conseguenza non trovata.',404);if($e['status']==='reverted')return;
 $before=json_decode($e['before_state'],true);$after=json_decode($e['after_state'],true);$kind=$e['target_kind'];$target=(int)$e['target_id'];$type=$e['effect_type']==='disarm'?'item':$e['effect_type'];
 if($type==='death'&&$kind==='pg'&&$db->query("SELECT player_id FROM lineage_members WHERE player_id=? AND status='eliminated'",[$target]))throw new DomainException('Questa morte coinvolge una stirpe: non è possibile annullare soltanto il singolo personaggio. Usa una rettifica coordinata della discendenza.',409);
 if($type==='scene'){$current=json_decode($db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=? FOR UPDATE',[(int)$e['session_id'],$kind,$target])[0]['state'],true);if(ai_quest_json($current)!==ai_quest_json($after['cast']))throw new DomainException('La condizione tattica è cambiata: non può essere sovrascritta.',409);$db->execute('UPDATE ai_quest_cast SET state=? WHERE session_id=? AND kind=? AND actor_id=?',[ai_quest_json($before['cast']),(int)$e['session_id'],$kind,$target]);$db->execute("UPDATE ai_quest_effects SET status='reverted' WHERE id=?",[$id]);ai_quest_audit((int)$e['session_id'],'scene_reverted',['effect'=>$id,'reason'=>$reason],$reviewer);return;}
 if(in_array($type,['note','condition'],true)&&$kind==='pg') {InsertNote($target,$reviewer,'Rettifica della nota del verdetto #'.$e['verdict_id'].': '.$reason);$db->execute("UPDATE ai_quest_effects SET status='reverted' WHERE id=?",[$id]);ai_quest_audit((int)$e['session_id'],'note_corrected',['effect'=>$id,'reason'=>$reason],$reviewer);ai_quest_correct_effect_memory($e,$reason);return;}
 if($type==='money'){$original=$db->query('SELECT * FROM ai_npc_transactions WHERE id=? FOR UPDATE',[(int)$after['money']['transaction_id']])[0];$key='reversal:'.$original['id'];ai_money_transfer((int)$original['npc_id'],(int)$original['player_id'],(int)$original['player_delta']>0?'reversal_to_npc':'reversal_to_player',(int)$original['amount'],'Storno effetto #'.$id.': '.$reason,$key,$reviewer);$db->execute("UPDATE ai_quest_effects SET status='reverted' WHERE id=?",[$id]);ai_quest_audit((int)$e['session_id'],'money_reversed',['effect'=>$id,'reason'=>$reason],$reviewer);ai_quest_correct_effect_memory($e,$reason);return;}
 if($type==='item'){$current=['item'=>$db->query('SELECT * FROM equippg WHERE id=? FOR UPDATE',[(int)$after['item']['id']])[0]??null];}
 elseif(in_array($type,['ammunition','reload'],true)){$current=['cast'=>json_decode($db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=? FOR UPDATE',[(int)$e['session_id'],$kind,$target])[0]['state']??'{}',true),'ammo_item'=>$db->query('SELECT * FROM equippg WHERE id=? FOR UPDATE',[(int)$before['ammo_item']['id']])[0]??null];}
 else{$db->query('SELECT id FROM '.($kind==='pg'?'utenti':'ai_npcs').' WHERE id=? FOR UPDATE',[$target]);$current=ai_effect_state($kind,$target);}
 if($type==='infection'&&array_key_exists('lineage_edge',$after)){
  $current['lineage_edge']=$db->query("SELECT * FROM lineage_members WHERE player_id=? AND kind='lycan' FOR UPDATE",[$target])[0]??null;
  if($db->query("SELECT player_id FROM lineage_members WHERE creator_id=? AND kind='lycan'",[$target]))throw new DomainException('La discendenza è già proseguita: il contagio non può essere annullato isolatamente.',409);
 }
 if(ai_quest_json($current)!==ai_quest_json($after))throw new DomainException('Lo stato è cambiato dopo questo effetto: correggi dal più recente, senza sovrascrivere eventi successivi.',409);
 if($type==='infection'&&array_key_exists('lineage_edge',$before)){
  if($before['lineage_edge']===null)$db->execute("DELETE FROM lineage_members WHERE player_id=? AND kind='lycan'",[$target]);
  unset($before['lineage_edge']);
 }
 if($type==='item')$db->execute('UPDATE equippg SET idpg=?,slot=?,idpadre=? WHERE id=?',[$before['item']['idpg'],$before['item']['slot'],$before['item']['idpadre'],$before['item']['id']]);
 elseif(in_array($type,['ammunition','reload'],true)){$db->execute('UPDATE ai_quest_cast SET state=? WHERE session_id=? AND kind=? AND actor_id=?',[ai_quest_json($before['cast']),$e['session_id'],$kind,$target]);$a=$before['ammo_item'];if($current['ammo_item'])$db->execute('UPDATE equippg SET consumo=? WHERE id=?',[$a['consumo'],$a['id']]);else $db->execute('INSERT INTO equippg(id,uid,idpg,slot,nome,consumo,idpadre) VALUES(?,?,?,?,?,?,?)',[$a['id'],$a['uid'],$a['idpg'],$a['slot'],$a['nome'],$a['consumo'],$a['idpadre']]);}
 elseif($kind==='pg'){$sets=[];$values=[];foreach($before as $k=>$v){$sets[]=$k.'=?';$values[]=$v;}$db->execute('UPDATE utenti SET '.implode(',',$sets).' WHERE id=?',[...$values,$target]);}
 else{$db->execute('UPDATE ai_npcs SET profile=?,active=? WHERE id=?',[ai_quest_json($before['profile']),(int)$before['active'],$target]);$db->execute('UPDATE ai_npc_game SET settings=?,version=version+1,updated_by=?,updated_at=? WHERE npc_id=?',[ai_quest_json($before['game']),$reviewer,time(),$target]);}
 $db->execute("UPDATE ai_quest_effects SET status='reverted' WHERE id=?",[$id]);
 if($kind==='npc'&&$type==='death'){require_once __DIR__.'/npc-mortality.inc.php';alphano_npc_revert_death($e['effect_key'],$reviewer,$reason);}
 ai_quest_audit((int)$e['session_id'],'effect_reverted',['id'=>$id,'reason'=>$reason],$reviewer);
 ai_quest_correct_effect_memory($e,$reason);
}

function ai_quest_correct_effect_memory(array $e,string $reason):void{
 $db=ai_db();$id=(int)$e['id'];
 $db->execute("UPDATE ai_personal_memories SET active=0 WHERE source='system' AND evidence=?",['effect:'.$e['effect_key']]);
 foreach($db->query("SELECT DISTINCT npc_id,player_id,chat_key FROM ai_personal_memories WHERE source='system' AND evidence=?",['effect:'.$e['effect_key']]) as $m)$db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,created_at,updated_at) VALUES(?,?,?,'rettifica',?,?,'system',?,?)",[$m['npc_id'],$m['player_id'],$m['chat_key'],'Rettifica verificata della conseguenza #'.$id.': lo stato precedente non è più attuale. Considera soltanto la condizione corretta, senza dedurre dettagli riservati.','effect-reverse:'.$id,time(),time()]);
}
