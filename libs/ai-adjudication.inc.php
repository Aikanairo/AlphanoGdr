<?php
declare(strict_types=1);
require_once __DIR__.'/rpg-combat.inc.php';
require_once __DIR__.'/lycan.inc.php';
require_once __DIR__.'/item-engine.inc.php';
require_once __DIR__.'/human-ability-engine.inc.php';
function ai_adjudication_enabled(int $sid):bool{
 $q=ai_db()->query('SELECT chat_key,policy FROM ai_quests WHERE session_id=?',[$sid])[0]??[];
 return (json_decode($q['policy']??'{}',true)['resolution']??'')==='alphano_scene_v1'&&(ai_room($q['chat_key'])['quest_rules']??'legacy')==='alphano_scene_v1';
}
function ai_action_target(array $q,string $raw,int $actor,int $selected=0):?array{
 $db=ai_db();$sid=(int)$q['session_id'];$scene=$q['_narrative_context']??ai_narrative_scene_context($q['chat_key'],$actor,0,null,true);
 // A present bystander (including the former human conductor) remains a real
 // possible victim. Admission to the turn queue is handled after resolution;
 // filtering them out here made named PvP attacks look like harmless dialogue.
 $present=$scene['present'];
 $context=array_replace($scene['context'],['purpose'=>'action','selected'=>$selected,'actor'=>'pg:'.$actor]);$opponents=[];
 // Strictly this session and player: no globally remembered opponent or random first cast.
 foreach($db->query("SELECT kind,actor_id,details,content FROM ai_quest_actions WHERE session_id=? AND status='submitted' ORDER BY id DESC LIMIT 20",[$sid]) as $prior){
  $d=json_decode($prior['details'],true)?:[];$intent=ai_narrative_parse($prior['content'])['intent'];
  if(!in_array($intent,['assalto','potere','arresto','furto'],true))continue;
  if($prior['kind']==='pg'&&(int)$prior['actor_id']===$actor&&!isset($context['_quest_prior'])){$context['_quest_prior']=true;$context['last_target']=($d['target_kind']??'npc').':'.(int)($d['target_id']??0);}
  if(($d['target_kind']??'')==='pg'&&(int)($d['target_id']??0)===$actor)$opponents[]=$prior['kind'].':'.(int)$prior['actor_id'];
 }
 $context['opponents']=array_values(array_unique($opponents));
 $result=ai_narrative_resolve(ai_narrative_parse($raw),$present,$context);
 return $result['status']==='resolved'?[...$result['target'],'resolution'=>$result['reason'],'confidence'=>$result['confidence']]:null;
}

/** Only a server-reserved declaration may contribute a skill effect to a verdict. */
function ai_adjudication_ability(array $action,int $session,int $round):?array {
 $details=json_decode($action['details'],true)?:[];$claim=$details['ability']??null;if($claim===null)return null;
 if(!is_array($claim)||empty($claim['id'])||!is_string($claim['source']??null))throw new DomainException('Dichiarazione abilità non verificabile.',422);
 $row=ai_db()->query('SELECT snapshot FROM rpg_ability_uses WHERE owner_kind=? AND owner_id=? AND ability_id=? AND event_key=? AND session_id=? AND round_number=?',[$action['kind'],(int)$action['actor_id'],(int)$claim['id'],$claim['source'],$session,$round])[0]??null;
 if(!$row)throw new DomainException('L’abilità non risulta dichiarata dal personaggio in questo giro.',422);
 $ability=rpg_decode($row['snapshot']);rpg_get_ability($action['kind'],(int)$action['actor_id'],(int)$ability['id']);return $ability;
}
function ai_adjudication_score(array $actor,string $test,?array $ability=null):int {
 $stats=$actor['stats']??rpg_actor_stats($actor['kind'],(int)$actor['id']);$score=rpg_combat_score($stats,$test);
 if($ability&&!isset($ability['human_rules'])){$effect=$ability['effect'];$score=(int)round($score*.75+(int)($stats[$effect['stat']]??0)*.25);$abilityBonus=(int)$effect['bonus'];}
 $score+=max($abilityBonus??0,rpg_human_bonus($actor,$test,$ability));$score-=rpg_malus_evaluate((int)($actor['native_race_id']??$actor['razza']),$actor['_racial_malus']??[],in_array($test,['attack','defence'],true)?'physical':'mental')['penalty'];return (int)round($score*item_score_modifier($actor['_item_effects']??[],$test,$stats));
}
/** Applies bounded damage to the in-memory verdict only, preserving the established racial exceptions. */
function ai_adjudication_damage(array &$result,array $actor,array $target,string $targetKey,int $damage,string $wound,array $cfg,array $room,bool $training,int $round,array &$hp,array &$wounds,array &$conditions,array &$deaths,array $states,int $session=0):void {
 $damage=max(0,min((int)$cfg['damage_max'],$damage));
 if($training){$result['outcome']='la manovra d’allenamento supera la guardia';$result['reason']='Il movimento trova l’apertura, ma resta controllato: l’allenamento non sottrae punti vita.';return;}
 // The specialist's chance is an encounter ceiling, not a new random hit chance. A blocked decisive
 // blow makes the NPC withdraw; no healing, immunity to other actors, or automatic PG victory is added.
 if(ai_npc_super_decisive_damage($hp[$targetKey],$damage)&&($gate=ai_npc_super_encounter_check($session,$actor,$target))){
  $result['specialist_encounter']=$gate;
  if(!$gate['allowed']){$conditions['npc:'.$actor['id']]['super_withdrawn'][$targetKey]=['round'=>$round,'policy'=>$gate['policy']];$result['outcome']='il tentativo decisivo fallisce e il PNG ripiega';$result['reason']='La preparazione e la tenuta dell’avversario negano il varco decisivo. Il PNG interrompe l’assalto e cerca di sottrarsi alla contesa: quel colpo non infligge altre ferite.';return;}
 }
 // An Original can fall to an Ancestral only when already gravely wounded, exactly as in the existing engine.
 if((int)$target['razza']===ALPHANO_RACE_ORIGINAL&&!((int)$actor['razza']===ALPHANO_RACE_ANCESTRAL&&(int)$target['puntif']<maxpuntif/3))$damage=min($damage,max(0,$hp[$targetKey]-1));
 if(empty($room['quest_lethal']))$damage=min($damage,max(0,$hp[$targetKey]-1));
 if($damage===0){$result['outcome']='il colpo non lo abbatte';$result['reason']='Le condizioni dello scontro e la resistenza già accertata del bersaglio impediscono una nuova conseguenza letale. Non viene aggiunta una ferita fittizia.';return;}
 $hp[$targetKey]=max(0,$hp[$targetKey]-$damage);$result['damage']=$damage;$wounds[$targetKey]=ai_text(ltrim(trim($wounds[$targetKey].'; '.$wound),'; '),255);
 if($hp[$targetKey]===0){
  if((int)$target['razza']===ALPHANO_RACE_ANCESTRAL&&empty($states[$targetKey]['last_stand'])){$hp[$targetKey]=1;$conditions[$targetKey]['last_stand']=$round+1;$conditions[$targetKey]['last_stand_cause']=['kind'=>(string)$actor['kind'],'id'=>(int)$actor['id'],'name'=>(string)$actor['name']];$result['reason'].=' La Resilienza Suprema gli concede un’ultima azione critica prima di cadere.';}
  else{$deaths[$targetKey]=['killer_kind'=>(string)($actor['kind']??'unknown'),'killer_actor_id'=>(int)$actor['id'],'killer_id'=>($actor['kind']??'')==='pg'?(int)$actor['id']:0,'killer_name'=>(string)$actor['name']];$result['outcome'].=' e lo scontro è fatale';$result['reason'].=' Le forze vitali già compromesse si esauriscono: la morte viene registrata.';}
 }
 foreach($conditions as &$condition)if(($condition['restrained_by']??'')===$targetKey&&$damage>=60){ai_tactical_release($condition);$result['reason'].=' L’impatto interrompe anche la presa che stava mantenendo.';}unset($condition);
}

/** Resolution is a deterministic function of the persisted declarations, rolls and real sheets. */
function ai_adjudicate(int $sid):array{
 $db=ai_db();$g=ai_game_guidance($sid);$q=$db->query('SELECT * FROM ai_quests WHERE session_id=?',[$sid])[0];$cfg=ai_scene_rules();$round=(int)$g['round_number'];
 $actions=$db->query('SELECT * FROM ai_quest_actions WHERE session_id=? AND round_number=? ORDER BY id',[$sid,$round]);$order=json_decode($g['turn_order'],true)?:[];
 if(count($actions)!==count($order))throw new DomainException('Il giro non è completo.',409);
 $cast=[];$states=[];$declared=[];$active=[];$abilities=[];$abilityErrors=[];foreach($db->query('SELECT * FROM ai_quest_cast WHERE session_id=?',[$sid]) as $c){$key=$c['kind'].':'.$c['actor_id'];$active[$key]=(bool)$c['active'];$cast[$key]=ai_quest_actor($c['kind'],(int)$c['actor_id']);$states[$key]=json_decode($c['state'],true)?:[];}
 foreach($actions as $a){$key=$a['kind'].':'.$a['actor_id'];$declared[$key]=$a;try{$abilities[$key]=ai_adjudication_ability($a,$sid,$round);}catch(DomainException $e){$abilityErrors[$key]=$e->getMessage();}}
 $hp=[];$wounds=[];$conditions=$states;$results=[];$deaths=[];$infections=[];$ammoEffects=[];$room=ai_room($q['chat_key']);$training=ai_scene_kind($q)==='allenamento';
 foreach($cast as $key=>$sheet){$hp[$key]=(int)($sheet['puntif']??0);$wounds[$key]=(string)($sheet['ferite']??'');$cast[$key]['_item_effects']=item_active_scene($states[$key],$sid,$round,$sheet['kind'],(int)$sheet['id']);if($cast[$key]['_item_effects']||isset($states[$key]['item_effects']))$conditions[$key]['item_effects']=$cast[$key]['_item_effects'];if(isset($conditions[$key]['item_effects']))foreach($conditions[$key]['item_effects'] as &$entry)if($entry['effect']['type']==='poison'&&($entry['last_tick']??0)<$round&&!$training){$hp[$key]=max(1,$hp[$key]-(int)$entry['effect']['amount']);$entry['last_tick']=$round;}unset($entry);}
 foreach($cast as $key=>&$member){$d=rpg_decode($declared[$key]['details']??'{}');$m=rpg_malus_scene($member,$states[$key],$round,(string)($d['intent']??'azione'));$conditions[$key]['racial_malus']=$m;$member['_racial_malus']=$m;$member['_human_buffs']=rpg_human_valid_buffs($states[$key],$round,$member);$eval=rpg_malus_evaluate((int)($member['native_race_id']??$member['razza']),$m);if($eval['dead']&&!$training){$hp[$key]=0;$deaths[$key]=['killer_kind'=>'environment','killer_actor_id'=>0,'killer_id'=>0,'killer_name'=>'Vulnerabilità razziale accertata'];$wounds[$key]=trim($wounds[$key].'; Distruzione definitiva per vulnerabilità razziale.','; ');}}unset($member);
 foreach($actions as $a){
  $key=$a['kind'].':'.$a['actor_id'];$actor=$cast[$key]??[];$d=json_decode($a['details'],true)?:[];$analysis=ai_narrative_parse($a['content']);$raw=mb_strtolower($analysis['action']);$targetKey=($d['target_kind']??'npc').':'.(int)($d['target_id']??0);$target=$cast[$targetKey]??[];if(!$target&&$a['kind']==='pg'){$resolved=ai_action_target($q,$a['content'],(int)$a['actor_id']);if($resolved){$targetKey=$resolved['kind'].':'.$resolved['id'];$target=$cast[$targetKey]??[];}}$state=$states[$key]??[];
  $r=['id'=>(int)$a['id'],'source_id'=>(int)$a['source_id'],'actor_kind'=>$a['kind'],'actor_id'=>(int)$a['actor_id'],'target_kind'=>$target['kind']??'','target_id'=>(int)($target['id']??0),'actor'=>$actor['name']??'Assente','target'=>$target['name']??'','attempt'=>ai_text($a['content'],240),'outcome'=>'','reason'=>'','damage'=>0];
  if($a['status']==='skipped'){$r['outcome']='non agisce';$r['reason']='Il tempo concesso è trascorso: il personaggio non porta a termine una nuova manovra, senza subire per questo un colpo automatico.';$results[]=$r;continue;}
  if(isset($abilityErrors[$key])){$r['outcome']='non può usare l’abilità dichiarata';$r['reason']=$abilityErrors[$key];$results[]=$r;continue;}
  $ability=$abilities[$key]??null;$effect=$ability['effect']??null;$effectType=$effect['type']??'';if($ability)$r['ability']=['id'=>$ability['id'],'name'=>$ability['name'],'effect'=>$effect];
  if(!$actor||(($hp[$key]??0)<1&&!in_array((int)($actor['razza']??0),[4,7,ALPHANO_RACE_LEGACY_SPIRIT],true))){$r['outcome']='non può agire';$r['reason']='Le condizioni già registrate gli impediscono di sostenere un’altra azione.';$results[]=$r;continue;}
  $limited=array_filter($actor['_item_effects']??[],static fn($e)=>$e['effect']['type']==='stun');if($limited&&in_array($d['intent']??'',['attacco','assalto','potere','arresto'],true)){$r['outcome']='lo stordimento limita il gesto';$r['reason']='Può mantenere una difesa, ma l’effetto verificato impedisce un nuovo attacco in questo turno.';$results[]=$r;continue;}
  $malus=rpg_malus_evaluate((int)($actor['native_race_id']??$actor['razza']),$actor['_racial_malus']??[]);if($malus['incapacitated']){$r['outcome']='non può sostenere un’azione fisica';$r['reason']='Le condizioni razziali accertate provocano incapacità: serve rimuoverne la causa secondo il manuale.';$results[]=$r;continue;}
  // The preparation comes from the last applied verdict; a new skill replaces it.
  $finishingPreparation=false;if(!$ability&&!empty($state['human_preparing'])&&$round>=$state['human_preparing']['ready_round']){$ability=$state['human_preparing']['ability'];unset($conditions[$key]['human_preparing']);try{rpg_get_ability($a['kind'],(int)$a['actor_id'],(int)$ability['id']);}catch(DomainException $e){$r['outcome']='preparazione interrotta';$r['reason']=$e->getMessage();$results[]=$r;continue;}$finishingPreparation=true;$effect=$ability['effect'];$effectType=$effect['type'];}
  if($ability&&isset($ability['human_rules'])){
   $rules=$ability['human_rules'];$r['activation']=['roll'=>(int)$ability['activation_roll'],'chance'=>(int)($ability['activation_chance']??$rules['probability']),'success'=>(bool)$ability['activated'],'name'=>$ability['name']];
   if(empty($ability['activated'])){$r['outcome']='non attiva '.$ability['name'];$r['reason']='Tiro percentuale '.$ability['activation_roll'].' contro '.($ability['activation_chance']??$rules['probability']).'%. L’attivazione fallisce; il recupero si applica ugualmente.';$results[]=$r;continue;}
   if($rules['activation']>1&&!$finishingPreparation){$conditions[$key]['human_preparing']=['ability'=>$ability,'ready_round'=>$round+$rules['activation']-1];$r['outcome']='prepara '.$ability['name'];$r['reason']='Occorrono '.$rules['activation'].' turni di preparazione. Il bonus non è ancora disponibile; al prossimo turno completa la preparazione senza dichiarare un’altra abilità.';$results[]=$r;continue;}
   $number=(int)$rules['number'];$tests=in_array($number,[2,6,9],true)?['defence']:(in_array($number,[3,5],true)?['attack']:($number===13?['resist']:[]));
   if($tests){if($number===5&&($state['defended_round']??-5)<$round-1){$r['outcome']='non trova la condizione per la contromossa';$r['reason']='Serve una difesa già riuscita: non ottiene un attacco aggiuntivo.';$results[]=$r;continue;}rpg_human_buff($conditions[$key],$ability,$round,$tests,$targetKey);$cast[$key]['_human_buffs']=rpg_human_valid_buffs($conditions[$key],$round,$cast[$key]);}
  }
  if(rpg_racial_family((int)($actor['native_race_id']??$actor['razza']))==='human'&&!empty($actor['_racial_malus']['corruption_offer'])&&empty($state['racial_malus']['_corruption_done'])){$chance=!empty($actor['_racial_malus']['decisive_desire'])?40:60;$roll=((int)($d['roll']??10)-1)*5+3;$conditions[$key]['racial_malus']['_corruption_done']=true;$conditions[$key]['corruption']=['resisted'=>$roll<=$chance,'chance'=>$chance,'roll'=>$roll,'until'=>$round+1,'limit'=>'Solo una concessione proporzionata alla proposta accertata, senza obbedienza assoluta o trasferimenti automatici di beni.'];$r['corruption']=$conditions[$key]['corruption'];}
  if($ability&&isset($ability['human_rules'])&&(int)$ability['human_rules']['number']===12){
   if(!$target||$targetKey===$key||isset($state['distance'])&&(int)$state['distance']>2){$r['outcome']='non raggiunge un compagno da proteggere';$r['reason']='Occorre un alleato adiacente identificato, senza una protezione fuori portata.';$results[]=$r;continue;}
   rpg_human_buff($conditions[$targetKey],$ability,$round,['defence'],$key);$cast[$targetKey]['_human_buffs']=rpg_human_valid_buffs($conditions[$targetKey],$round,$cast[$targetKey]);
  }
  if($ability&&isset($ability['human_rules'])&&(int)$ability['human_rules']['number']===14){$supported=0;foreach($actions as $ally){$ad=rpg_decode($ally['details']);$allyKey=$ally['kind'].':'.$ally['actor_id'];if($allyKey===$key||$ally['status']==='skipped'||($ad['target_kind']??'').':'.($ad['target_id']??0)!==$targetKey||!in_array($ad['intent']??'',['attacco','assalto'],true))continue;rpg_human_buff($conditions[$allyKey],$ability,$round,['attack'],$targetKey);$cast[$allyKey]['_human_buffs']=rpg_human_valid_buffs($conditions[$allyKey],$round,$cast[$allyKey]);if(++$supported===2)break;}}
  $itemDeclaration=null;
  try{$itemDeclaration=item_declaration($a,$sid,$round);if($itemDeclaration){if(array_filter($actor['_item_effects']??[],static fn($e)=>$e['effect']['type']==='stun')&&$itemDeclaration['rules']['target']==='opponent')throw new DomainException('Lo stordimento impedisce un nuovo gesto ostile.',409);$targetDetails=rpg_decode($declared[$targetKey]['details']??'{}');if(item_scene_action($itemDeclaration,$actor,$target,$sid,$round,(int)($d['roll']??10),(int)($targetDetails['roll']??10),$hp,$conditions,$cast,$ammoEffects,$r,$training)){$results[]=$r;continue;}$actor=$cast[$key];}}
  catch(DomainException $e){$r['outcome']='non può impiegare l’oggetto';$r['reason']=$e->getMessage();$results[]=$r;continue;}

  if(in_array((int)$actor['razza'],[4,7,ALPHANO_RACE_LEGACY_SPIRIT],true)&&!in_array($effectType,['narrative','perception','passive'],true)){$r['outcome']='resta una presenza incorporea';$r['reason']='Questa forma non possiede un corpo con cui attaccare, afferrare o guarire ferite fisiche.';$results[]=$r;continue;}
  if($ability){try{rpg_combat_assert_equipment($a['kind'],(int)$a['actor_id'],$ability);}catch(DomainException $e){$r['outcome']='non può usare l’abilità dichiarata';$r['reason']=$e->getMessage();$results[]=$r;continue;}}
  if(in_array($effectType,['narrative','passive','perception'],true)){
   $r['outcome']=$effectType==='perception'?'coglie meglio i movimenti visibili':'dà seguito alla propria capacità narrativa';
   $r['reason']=isset($ability['human_rules'])?'Attivazione riuscita: '.$ability['human_rules']['bonus_text'].' '.$ability['human_rules']['mechanic'].' Richiede: '.$ability['human_rules']['requirement_text']:'Con '.$ability['name'].' il gesto resta entro ciò che il personaggio può osservare e conoscere: non rivela pensieri o segreti e non infligge ferite.';
   if($effectType==='perception'&&!in_array((int)$actor['razza'],[4,7,ALPHANO_RACE_LEGACY_SPIRIT],true)){$conditions[$key]['perception_until']=$round+1;$conditions[$key]['perception_bonus']=(int)$effect['bonus'];$r['reason'].=' L’attenzione raccolta favorisce la lettura della prossima manovra.';}
   $results[]=$r;continue;
  }
  if($effectType==='heal'){
   $r['target']=$actor['name'];$gain=max(0,min((int)$effect['heal'],maxpuntif-$hp[$key]));
   if($training){$r['outcome']='simula il recupero durante l’allenamento';$r['reason']='La dimostrazione di '.$ability['name'].' non modifica i punti vita reali.';}
   elseif($hp[$key]<1||isset($deaths[$key])){$r['outcome']='non riesce a rigenerarsi';$r['reason']='La capacità non resuscita un personaggio già caduto.';}
   elseif(rpg_malus_evaluate((int)($actor['native_race_id']??$actor['razza']),$actor['_racial_malus']??[])['regeneration_blocked']){$r['outcome']='la rigenerazione è ostacolata dall’argento';$r['reason']='Prima occorre rimuovere l’argento trattenuto nella ferita e risolvere le cure.';}
   elseif($gain===0){$r['outcome']='non ha ferite vitali da recuperare';$r['reason']='I punti vita sono già al massimo: non viene attribuita una cura fittizia.';}
   else{$hp[$key]+=$gain;$r['healing']=$gain;$r['outcome']='rimargina parte delle proprie ferite';$r['reason']=$ability['name'].' restituisce '.$gain.' punti vita: il recupero è registrato e non supera la salute massima.';}
   $results[]=$r;continue;
  }
  $intent=$ability?rpg_combat_ability_intent($ability):($d['intent']??'azione');if(!$ability&&$a['kind']==='pg')$intent=$analysis['intent']??'azione';
  $maneuver=ai_tactical_maneuver($analysis);
  if($maneuver==='maintain'&&!$ability){
   if($target&&($conditions[$targetKey]['restrained_by']??'')===$key&&ai_tactical_restrained($conditions[$targetKey],$round)){$conditions[$targetKey]['restrained_kind']='grapple';$conditions[$targetKey]['restrained_until']=0;ai_tactical_contact($conditions,$key,$targetKey,$round);$r['outcome']='mantiene la presa già accertata';$r['reason']='Il vincolo resta attivo: non nasce una nuova presa né una ferita. Il bersaglio può opporre un tentativo di liberazione.';}
   else{$r['outcome']='non mantiene una presa accertata';$r['reason']='Non risulta un avversario già trattenuto da questo personaggio: descrivere una presa non la rende riuscita.';}
   $results[]=$r;continue;
  }
  if($intent==='rilascio'&&!$ability){$released=0;foreach($conditions as &$held)if(($held['restrained_by']??'')===$key){ai_tactical_release($held);$released++;}unset($held);$r['outcome']=$released?'lascia andare la propria presa':'non mantiene una presa da sciogliere';$r['reason']=$released?'Rilascia il vincolo che stava realmente mantenendo, senza infliggere un nuovo morso o imporre una nuova presa.':'Non risulta un vincolo mantenuto da questo personaggio: il gesto non crea né rinnova una presa.';if(!ai_tactical_restrained($state,$round)&&preg_match('/\b(?:arretra|arretro|si allontana|mi allontano)\b/iu',$analysis['action'])){ai_tactical_retreat($conditions,$key,$round);$r['reason'].=' Poi arretra dalla contesa come dichiarato.';}$results[]=$r;continue;}
  $mental=$ability?$effectType==='mental':($analysis['intent']==='potere');
  if($mental&&!$ability){$r['outcome']='la dichiarazione non attiva una capacità appresa';$r['reason']='Per esercitare un potere mentale seleziona un’abilità appresa e disponibile: il nome scritto in chat non assegna effetti, non supera i requisiti e non evita il recupero.';$results[]=$r;continue;}
  $physical=mb_strtolower(implode(' ',array_column(array_filter($analysis['acts'],static fn($act)=>in_array($act['intent'],['assalto','arresto'],true)),'text')));
  $bite=in_array($intent,['assalto','attacco'],true)&&(bool)preg_match('/\b(?:morde|mordo|mordere|morderlo|morderla|mordendolo|mordendola|morderli|morderle|azzanna|azzanno|azzannare|azzannarlo|azzannarla|azzannarli|azzannarle)\b|'.ai_narrative_bite_pattern().'/iu',$physical);$grab=in_array($intent,['assalto','attacco','arresto'],true)&&(bool)preg_match('/\b(?:immobilizza|immobilizzo|immobilizzare|afferra|afferro|afferrare|afferrar(?:lo|la|li|le|gli)|stringe|stringo|stringere|serrare)\b/iu',$physical)&&!preg_match('/visto che|già|ha stretto|mantiene/iu',$physical);
  // One declaration is one offensive manoeuvre. An attempted bite must not
  // disappear behind its preparatory grab, nor grant a free successful grapple.
  if($bite)$grab=false;
  if($bite&&$a['kind']==='pg'){
   $differentBiteTarget=false;
   foreach($analysis['acts'] as $act)if($act['intent']==='assalto'&&preg_match('/\b(?:mord\p{L}*|azzann\p{L}*)\b|'.ai_narrative_bite_pattern().'/iu',$act['text'])){
    $biteTarget=ai_action_target($q,'+ '.$act['text'],(int)$a['actor_id']);
    if($biteTarget&&($biteTarget['kind'].':'.$biteTarget['id'])!==$targetKey)$differentBiteTarget=true;
   }
   if($differentBiteTarget){$r['outcome']='la dichiarazione indica avversari diversi';$r['reason']='La presa e il morso sono rivolti a persone diverse: occorre scegliere una sola manovra e il suo bersaglio. Nessuna ferita viene assegnata alla prima persona nominata per errore.';$results[]=$r;continue;}
  }
  if(preg_match('/iniett\p{L}*.{0,45}veleno|veleno.{0,35}maledizion|farlo trasform/iu',$raw)&&in_array((int)$actor['razza'],[2,5],true)){$r['outcome']='la maledizione non si trasmette in questo modo';$r['reason']='Il morso precedente resta una ferita, ma i denti non iniettano un veleno capace di creare un vampiro. La trasformazione richiede il rito e le condizioni della stirpe: questa intenzione non li sostituisce e non apre una seconda ferita.';$results[]=$r;continue;}
  if($ability&&$effectType!=='attack'){$bite=false;$grab=false;}
  $attack=in_array($intent,['assalto','attacco','potere','arresto','furto'],true)||$mental||$bite||$grab;
  if($intent==='ricarica'){
   $items=ai_game_inventory($a['kind'],(int)$a['actor_id']);$held=rpg_declared_weapon($items,$raw,true);$ammo=$held?array_values(array_filter($items,static fn($i)=>rpg_ranged_ammo($i,$held)&&(int)$i['consumo']<(int)$i['durata'])):[];
   if($held&&($weaponReason=rpg_weapon_lineage_reason(['race_id'=>(int)$actor['razza'],'native_race_id'=>(int)($actor['native_race_id']??$actor['razza'])],$held))){$r['outcome']='non può usare l’arma da fuoco';$r['reason']=$weaponReason;}
   elseif(!$held||!$ammo){$r['outcome']='non riesce a preparare il tiro';$r['reason']='Manca un’arma da tiro impugnata o una munizione utilizzabile nell’inventario reale.';}
   elseif(!empty($state['loaded'])&&(!isset($state['loaded_weapon'])||(int)$state['loaded_weapon']===(int)$held['id'])){$r['outcome']='mantiene l’arma già pronta';$r['reason']='La munizione è già caricata: non viene consumata né duplicata.';}
   elseif($round-(int)($state['last_shot_round']??0)<$cfg['reload_rounds']){$r['outcome']='la ricarica non è ancora conclusa';$r['reason']='È necessario il tempo della preparazione prima di un altro tiro.';}
   else{$conditions[$key]['ammo_instance']=(int)$ammo[0]['id'];$conditions[$key]['ammo']=(int)$ammo[0]['durata']-(int)$ammo[0]['consumo'];$conditions[$key]['loaded']=true;$conditions[$key]['loaded_weapon']=(int)$held['id'];$r['outcome']='prepara una nuova munizione';$r['reason']='L’arma impugnata e la munizione sono presenti: questa azione completa il caricamento, senza scoccare nello stesso istante.';}
   $results[]=$r;continue;
  }
  if($intent==='furto'){$r['outcome']='il tentativo viene scoperto';$r['reason']='Nel pieno dello scontro non si apre un varco sicuro per sottrarre un bene. Nessun oggetto cambia proprietario senza identificarlo e raggiungerlo.';$results[]=$r;continue;}
  if($intent==='potere'&&!$mental&&!$bite&&!$grab&&!$ability){$r['outcome']='il potere non produce l’effetto richiesto';$r['reason']='La capacità e le condizioni della sua applicazione non sono determinate nella dichiarazione e nelle regole disponibili: non nasce un effetto soprannaturale dalla sola intenzione.';$results[]=$r;continue;}
  if(!$attack){
   if($a['kind']==='pg'&&ai_episode_interpret($a['content'])['category']==='minaccia'){$r['outcome']='formula una minaccia concreta';$r['reason']='Le parole esercitano pressione sul rapporto personale e ricevono una risposta di sopravvivenza secondo la credibilità del pericolo. Non costituiscono un nuovo colpo, un potere mentale o un danno automatico.';$results[]=$r;continue;}
   if($a['kind']==='pg'&&$analysis['speech']!==''&&$analysis['action']===''){$r['outcome']='si rivolge agli altri nella contesa';$r['reason']='Le parole e la richiesta entrano nel turno e nel rapporto personale. Non infliggono un colpo né impongono automaticamente la decisione degli altri.';$results[]=$r;continue;}
   if($intent==='resa'){$r['outcome']='cede per salvaguardare la propria vita';$r['reason']='La resa riguarda la propria condotta: non certifica amore, fiducia, una fuga già riuscita o il controllo delle azioni altrui.';$results[]=$r;continue;}
   $escaping=in_array($intent,['ritirata','fuga'],true)||(bool)preg_match('/\b(?:si libera|mi libero|liberarsi|divincola|svincola|strattona|sciogliere la presa)\b/iu',$raw);
   if($escaping&&ai_tactical_restrained($conditions[$key]??[],$round)){$holder=$cast[$conditions[$key]['restrained_by']]??[];$score=ai_adjudication_score($actor,'attack',$ability)+(int)($d['roll']??10);$opp=$holder?ai_adjudication_score($holder,'defence')+10:10;if($score>$opp){ai_tactical_release($conditions[$key]);$r['outcome']='si libera dalla presa';$r['reason']='La torsione del corpo e la spinta delle gambe superano la tenuta dell’avversario.';}else{$r['outcome']='non riesce ancora a liberarsi';$r['reason']='La presa già chiusa limita il movimento; la forza impiegata non basta a scioglierla.';}}
   elseif($escaping&&ai_tactical_restrained($state,$round)){$r['outcome']='non è più trattenuto';$r['reason']='La presa è stata sciolta nel corso del giro: non deve vincere un nuovo confronto contro un vincolo già rilasciato.';ai_tactical_retreat($conditions,$key,$round);}
   elseif($intent==='fine'){$r['outcome']='interrompe il proprio assalto';$r['reason']='La scelta di fermarsi è accolta, senza imporre la resa agli altri.';if(!ai_tactical_restrained($state,$round)&&preg_match('/\b(?:arretra|arretro|si allontana|mi allontano)\b/iu',$analysis['action']))ai_tactical_retreat($conditions,$key,$round);}
   elseif(in_array($intent,['ritirata','fuga'],true)){$r['outcome']='guadagna spazio e si ritira';$r['reason']='Nessuna presa già accertata impedisce di arretrare; non infligge un attacco mentre si allontana.';ai_tactical_retreat($conditions,$key,$round);}
   elseif($intent==='difesa'){$r['outcome']='copre il settore minacciato';$r['reason']='La guardia resta concentrata su una difesa concreta e rende più difficile superarla; non ottiene un colpo gratuito.';}
   else{$r['outcome']='porta a termine il gesto non contrastato';$r['reason']='Nella dichiarazione non emerge un ostacolo concreto né un attacco da risolvere; il gesto può trovare seguito nella scena.';}
   $results[]=$r;continue;
  }
  if(!$target||!($active[$targetKey]??false)||$targetKey===$key||($target['chat']??'')!==$q['chat_key']||($target['kind']==='npc'&&empty($target['active']))){$r['outcome']='non raggiunge un bersaglio determinabile';$r['reason']='L’azione non identifica un avversario presente: deve indicarlo prima di poterlo ferire o vincolare.';$results[]=$r;continue;}
  if(in_array((int)$target['razza'],[4,7,ALPHANO_RACE_LEGACY_SPIRIT],true)){$r['outcome']='non incontra un corpo da ferire';$r['reason']='La presenza incorporea non offre un bersaglio fisico: il tentativo non le assegna ferite corporee.';$results[]=$r;continue;}
  if($hp[$targetKey]<1){$r['outcome']='non porta un altro colpo';$r['reason']='Il bersaglio è già fuori combattimento; questa manovra non viene conteggiata una seconda volta.';$results[]=$r;continue;}
  $defDetails=json_decode($declared[$targetKey]['details']??'{}',true)?:[];$defAbility=($declared[$targetKey]['status']??'')==='submitted'?($abilities[$targetKey]??null):null;if(($defAbility['effect']['type']??'')!=='defence')$defAbility=null;if($mental&&$defAbility&&!in_array($defAbility['effect']['stat'],['willpower','wisdom','intelligence','charisma'],true))$defAbility=null;$atk=ai_adjudication_score($actor,$mental?'mental':'attack',$ability)+(int)($d['roll']??10);$def=ai_adjudication_score($target,$mental?'resist':'defence',$defAbility)+(int)($defDetails['roll']??10);
  if((!$mental&&($defDetails['intent']??'')==='difesa')||$defAbility)$def+=$cfg['defence_bonus'];
  $support=0;if($target['kind']==='npc'){$f=ai_npc_game((int)$target['id'])['faction'];foreach($actions as $ally){if($ally['kind']!=='npc'||(int)$ally['actor_id']===(int)$target['id']||$ally['status']==='skipped')continue;$ad=json_decode($ally['details'],true)?:[];if($f!==''&&ai_npc_game((int)$ally['actor_id'])['faction']===$f&&in_array($ad['intent']??'',['difesa','aiuto'],true))$support++;}}$def+=min($support,$cfg['max_support'])*$cfg['support_bonus'];
  if(ai_tactical_restrained($state,$round))$atk-=$cfg['held_penalty'];
  if(ai_tactical_restrained($conditions[$targetKey]??[],$round))$def-=$cfg['held_penalty'];
  if(!in_array(rpg_racial_family((int)($actor['native_race_id']??$actor['razza'])),['human','lycan'],true))$atk-=(int)round(min($cfg['wound_penalty_max'],(int)floor((maxpuntif-(int)$actor['puntif'])/150))*item_pain_factor($actor['_item_effects']??[]));if(!in_array(rpg_racial_family((int)($target['native_race_id']??$target['razza'])),['human','lycan'],true))$def-=(int)round(min($cfg['wound_penalty_max'],(int)floor((maxpuntif-(int)$target['puntif'])/150))*item_pain_factor($target['_item_effects']??[]));
  if((int)($state['perception_until']??0)>=$round)$atk+=min(10,(int)($state['perception_bonus']??0));if((int)($states[$targetKey]['perception_until']??0)>=$round)$def+=min(10,(int)($states[$targetKey]['perception_bonus']??0));
  $r['calculation']=['attack'=>$atk,'defence'=>$def,'support'=>$support,'roll'=>(int)($d['roll']??10)];
  rpg_human_consume($conditions[$key],$mental?'mental':'attack',$round);rpg_human_consume($conditions[$targetKey],$mental?'resist':'defence',$round);$cast[$key]['_human_buffs']=rpg_human_valid_buffs($conditions[$key],$round,$cast[$key]);$cast[$targetKey]['_human_buffs']=rpg_human_valid_buffs($conditions[$targetKey],$round,$cast[$targetKey]);
  if($mental){
   $domination=in_array($ability['key']??'',['minor_domination','vampire_suggestion'],true)||(bool)preg_match('/ipnosi|persuasi|domina\p{L}*|soggiog\p{L}*|controllo emotivo/iu',$raw);
   if($domination&&in_array((int)($actor['native_race_id']??$actor['razza']),[2,5],true)){
    $sourceRace=(int)($actor['native_race_id']??$actor['razza']);$targetRace=(int)($target['native_race_id']??$target['razza']);$ownedAbilities=array_filter(rpg_sheet($target['kind'],(int)$target['id'],false)['abilities'],static fn($a)=>$a['owned']);$owned=array_column($ownedAbilities,'key');$chances=[];foreach($ownedAbilities as $a)if(isset($a['rules']))$chances[$a['key']]=$a['rules']['probability'];
    $protection=$states[$targetKey]['domination_protection'][$key]??0;$roll=((int)($defDetails['roll']??10)-1)*5+3;$resolution=rpg_domination_resolution($sourceRace,$targetRace,$owned,$roll,$chances);$r['resistance']=$resolution;
    $prior=$conditions[$targetKey]['dominated']??[];
    if(!$resolution['eligible']){$r['outcome']='il comando non vincola questa stirpe';$r['reason']='Il manuale non consente questo soggiogamento fra le due stirpi.';}
    elseif($protection>=$round){$r['outcome']='la protezione dal medesimo Vampiro è ancora attiva';$r['reason']='La precedente resistenza riuscita impedisce un nuovo soggiogamento prima della scadenza.';}
    elseif($prior&&$prior['until']>=$round&&(int)$prior['rank']>=$sourceRace){$r['outcome']='permane il comando precedente';$r['reason']='Fra ordini dello stesso rango prevale il primo ancora attivo; un Originale prevale su un Vampiro comune.';}
    elseif($resolution['resisted']){$conditions[$targetKey]['domination_protection'][$key]=$round+$resolution['duration']-1;$r['outcome']='respinge il comando vampirico';$r['reason']='La resistenza acquisita supera la prova al '.$resolution['resistance'].'%: protezione dallo stesso Vampiro per '.$resolution['duration'].' turni.';}
    else{$conditions[$targetKey]['dominated']=['source'=>$key,'rank'=>$sourceRace,'until'=>$round+$resolution['duration']-1,'command'=>ai_text($analysis['speech']?:$analysis['action'],500)];$r['outcome']='il comando vampirico è vincolante';$r['reason']='Il bersaglio tenta di eseguire il comando compreso per '.$resolution['duration'].' turni. Le azioni concrete richiedono comunque normali prove; nessuna ferita o riuscita viene assegnata automaticamente.';}
   }elseif($atk<=$def){$r['outcome']='la pressione mentale non supera la resistenza';$r['reason']='La capacità non supera la resistenza del bersaglio.';}
   else{$duration=max(1,(int)($effect['duration']??1));$conditions[$targetKey]['distracted_until']=$round+$duration;$r['outcome']='la pressione provoca un’esitazione reale';$r['reason']='La capacità supera la tenuta mentale secondo la prova contrapposta.';if((int)($effect['damage']??0)>0)ai_adjudication_damage($r,$actor,$target,$targetKey,(int)$effect['damage'],'Ferita da '.$ability['name'],$cfg,$room,$training,$round,$hp,$wounds,$conditions,$deaths,$states,$sid);}
   $results[]=$r;continue;
  }
  if((int)($states[$targetKey]['distracted_until']??0)>=$round)$def-=4;
  $inventory=ai_game_inventory($a['kind'],(int)$a['actor_id']);$weapon=rpg_declared_weapon($inventory,$raw);
  if($bite||$grab||preg_match('/pugno|calcio|mani nude|artigli/iu',$raw))$weapon=null;
  if(!empty($effect['requires_weapon'])&&!$weapon){$r['outcome']='la tecnica armata non può diventare un colpo a mani nude';$r['reason']='L’abilità dichiarata richiede di usare l’arma impugnata: il morso, la presa o il pugno descritti non ne ricevono i bonus e non infliggono danni con quella tecnica.';$results[]=$r;continue;}
  $claimedWeapon=(bool)preg_match('/spad\p{L}*|pugnal\p{L}*|lancia\b|fucil\p{L}*|pistol\p{L}*|moschett\p{L}*|carabin\p{L}*|trombon\p{L}*|\bspar\p{L}*|balestr\p{L}*|\barco\b|freccia|dardo/iu',$raw);
  if($claimedWeapon&&!$weapon){$r['outcome']='non può usare l’arma dichiarata';$r['reason']='Quell’arma non risulta impugnata e utilizzabile: la sola dichiarazione non la fa comparire nella mano.';$results[]=$r;continue;}
  if($weaponReason=rpg_weapon_lineage_reason(['race_id'=>(int)$actor['razza'],'native_race_id'=>(int)($actor['native_race_id']??$actor['razza'])],$weapon)){$r['outcome']='non può usare l’arma da fuoco';$r['reason']=$weaponReason;$results[]=$r;continue;}
  $ranged=$weapon&&rpg_ranged_family($weapon)!=='';
  $weaponRules=$weapon?item_spec($db->query('SELECT e.game_rules FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=?',[$weapon['id']])[0]['game_rules']??'{}'):[];if(!empty($weaponRules['ammunition'])&&(!$itemDeclaration||(int)$itemDeclaration['instance']!==(int)$weapon['id'])){$r['outcome']='il dispositivo richiede la propria attivazione';$r['reason']='Seleziona questo oggetto: munizioni ordinarie e testo libero non sostituiscono le sue cariche.';$results[]=$r;continue;}
  if(isset($state['distance'])&&(int)$state['distance']>($ranged?$cfg['ranged_max_range']:2)&&!(!$ranged&&preg_match('/si avvicina|raggiunge|si scaglia|si avventa/iu',$raw))){$r['outcome']='il colpo resta fuori portata';$r['reason']='La distanza accertata separa ancora i contendenti: l’arma non raggiunge il bersaglio da questa posizione.';$results[]=$r;continue;}
  if($ranged&&!($itemDeclaration&&isset($itemDeclaration['rules']['ammunition']))){
   $ammo=array_values(array_filter($inventory,static fn($i)=>(int)$i['id']===(int)($state['ammo_instance']??0)&&rpg_ranged_ammo($i,$weapon)&&(int)$i['consumo']<(int)$i['durata']));
   if(empty($state['loaded'])||(isset($state['loaded_weapon'])&&(int)$state['loaded_weapon']!==(int)$weapon['id'])||(int)($state['ammo']??0)<1||!$ammo){$r['outcome']='non parte alcun proiettile';$r['reason']='L’arma non è caricata con una munizione disponibile nell’inventario: prima di tirare occorre prepararla.';$results[]=$r;continue;}
   $ammoEffects[]=['type'=>'ammunition','kind'=>$a['kind'],'id'=>(int)$a['actor_id'],'instance'=>(int)$ammo[0]['id'],'round'=>$round];$conditions[$key]['ammo']=min((int)$state['ammo'],(int)$ammo[0]['durata']-(int)$ammo[0]['consumo'])-1;$conditions[$key]['loaded']=false;$conditions[$key]['last_shot_round']=$round;
  }
  if($weapon&&!$bite&&!$grab)$atk+=ai_scene_rules()['weapon_accuracy'];
  if($actor['kind']==='npc'&&$support===0){$f=ai_npc_game((int)$actor['id'])['faction'];$cover=0;foreach($actions as $ally){$ad=json_decode($ally['details'],true)?:[];if($ally['kind']==='npc'&&(int)$ally['actor_id']!==(int)$actor['id']&&$ally['status']!=='skipped'&&$f!==''&&ai_npc_game((int)$ally['actor_id'])['faction']===$f&&in_array($ad['intent']??'',['difesa','aiuto'],true))$cover++;}$atk+=min($cover,$cfg['max_support'])*$cfg['support_bonus'];}
  $r['calculation']['attack']=$atk;$r['calculation']['defence']=$def;
  if($atk<=$def){$conditions[$targetKey]['defended_round']=$round;$r['outcome']=$grab?'la presa non si chiude':($bite?'il morso non raggiunge la carne':'il colpo viene contenuto');$r['reason']=$support?'L’avversario si sottrae alla traiettoria e il sostegno del collega restringe il varco disponibile. La maggiore forza da sola non garantisce il successo.':'La risposta difensiva arriva in tempo e nega l’apertura cercata, pur lasciando intatta la tensione dello scontro.';$results[]=$r;continue;}
  if($grab&&($gate=ai_npc_super_encounter_check($sid,$actor,$target))){$r['specialist_encounter']=$gate;if(!$gate['allowed']){$conditions[$key]['super_withdrawn'][$targetKey]=['round'=>$round,'policy'=>$gate['policy']];$r['outcome']='il tentativo decisivo fallisce e il PNG ripiega';$r['reason']='La presa non riesce a contenere la forza del bersaglio: il PNG perde il varco e ripiega, senza immobilizzarlo.';$results[]=$r;continue;}}
  if($grab){$conditions[$targetKey]['restrained_by']=$key;$conditions[$targetKey]['restrained_until']=0;$conditions[$targetKey]['restrained_kind']='grapple';ai_tactical_contact($conditions,$key,$targetKey,$round);$r['outcome']='la presa si chiude e limita il movimento';$r['reason']='La spinta supera la tenuta avversaria e blocca il busto. La presa permane fino a un rilascio o una liberazione accertati; non infligge automaticamente ferite.';$results[]=$r;continue;}
  if($maneuver==='push'){
   ai_tactical_contact($conditions,$key,$targetKey,$round);$position=preg_match('/contro\s+(?:il\s+|la\s+|un[ao]?\s+|l[’\x{27}])?(muro|parete|porta|banco|tavolo)/iu',$raw,$anchor)?'contro '.(in_array(mb_strtolower($anchor[1]),['parete','porta'],true)?'la ':'il ').mb_strtolower($anchor[1]):'spostato all’indietro';
   $conditions[$targetKey]['position']=$position;$r['outcome']='la spinta sposta il bersaglio';$r['reason']='Il confronto supera la resistenza: '.$target['name'].' si trova '.$position.'. Le prese già accertate restano attive; non viene inflitta una ferita automatica.';$results[]=$r;continue;
  }
  if(isset($ability['human_rules'])&&in_array((int)$ability['human_rules']['number'],[4,10,11],true)){
   $n=(int)$ability['human_rules']['number'];if($n===10){$conditions[$targetKey]['restrained_by']=$key;$conditions[$targetKey]['restrained_until']=0;$conditions[$targetKey]['restrained_kind']='grapple';$r['outcome']='la presa riesce';}
   elseif($n===11){$conditions[$targetKey]['position']='sbilanciato';$r['outcome']='sbilancia l’avversario';}
   else{$held=rpg_declared_weapon(ai_game_inventory($target['kind'],(int)$target['id']),'');if($held){$ammoEffects[]=['type'=>'disarm','kind'=>$target['kind'],'id'=>(int)$target['id'],'instance'=>(int)$held['id']];$r['outcome']='disarma l’avversario';}else{$r['outcome']='non trova un’arma da disarmare';}}
   $r['reason']='La prova contrapposta è risolta: la manovra non infligge una ferita aggiuntiva.';$results[]=$r;continue;
  }
  $nativeTarget=(int)($target['native_race_id']??$target['razza']);if(in_array($nativeTarget,[3,6],true)&&!rpg_weapon_silver($weapon)){$r['outcome']='l’impatto non lascia una ferita persistente';$r['reason']='La resistenza mannara impedisce alle armi ordinarie di produrre lesioni autentiche. Serve argento sulla superficie offensiva verificato nell’oggetto.';$results[]=$r;continue;}
  $armor=(bool)array_filter(ai_game_inventory($target['kind'],(int)$target['id']),static fn($i)=>$i['slot']==='armatura'&&(int)$i['consumo']<(int)$i['durata']);
  $actorStats=$actor['stats']??rpg_actor_stats($actor['kind'],(int)$actor['id']);$rpgCfg=rpg_settings();$baseDamage=(int)round(((int)$actorStats['strength']*$rpgCfg['damage_strength']+(int)$actorStats['constitution']*$rpgCfg['damage_constitution'])/100);$damage=max($cfg['damage_min'],min($cfg['damage_max'],$baseDamage*(($weapon&&!$bite)?$cfg['weapon_multiplier']:$cfg['unarmed_multiplier'])+($atk-$def)*$cfg['margin_multiplier']+(int)($effect['damage']??0)-($armor?$cfg['armor_absorption']:0)));
  $r['outcome']=$bite?'il morso ferisce il bersaglio':'il colpo raggiunge il bersaglio';
  $r['reason']=$armor?'La manovra trova spazio oltre la difesa; la protezione indossata assorbe parte dell’impatto, senza cancellarlo.':'La pressione, la tecnica e la forza dell’assalto superano la risposta difensiva: il colpo apre una ferita che peserà sul seguito della contesa.';
  ai_adjudication_damage($r,$actor,$target,$targetKey,$damage,$bite?'Ferita da morso':(rpg_weapon_silver($weapon)?'Ferita d’argento':'Ferita nello scontro'),$cfg,$room,$training,$round,$hp,$wounds,$conditions,$deaths,$states,$sid);
  if($r['damage']>0){$conditions[$targetKey]['racial_malus']=rpg_malus_wounds(array_replace($target,['puntif'=>$hp[$targetKey],'ferite'=>$wounds[$targetKey]]),$conditions[$targetKey]['racial_malus']??[]);}
  if($bite&&alphano_lycan_contagion_attempt($a['content'])){
   if($training)$r['reason'].=' Il morso simulato non trasmette alcuna infezione.';
   elseif(empty(alphano_lycan_state($actor)['eligible'])||empty(alphano_lycan_state($actor)['ancestral']))$r['reason'].=' Questo morso non trasmette il contagio licantropico: il potere appartiene agli Ancestrali.';
   elseif(($target['kind']??'')!=='pg'||!alphano_lycan_can_infect($actor,$target))$r['reason'].=' La natura o le condizioni già accertate del bersaglio impediscono un nuovo contagio licantropico.';
   elseif($r['damage']>0&&!isset($infections[$targetKey]))$infections[$targetKey]=['actor_kind'=>$actor['kind'],'actor_id'=>(int)$actor['id'],'result'=>count($results)];
  }
  $results[]=$r;
 }
 foreach($states as $key=>$state)if(isset($state['last_stand'])&&(int)$state['last_stand']<=$round&&!empty($declared[$key])){$hp[$key]=0;$cause=$state['last_stand_cause']??[];$deaths[$key]=isset($cause['kind'],$cause['id'])?['killer_kind'=>$cause['kind'],'killer_actor_id'=>(int)$cause['id'],'killer_id'=>$cause['kind']==='pg'?(int)$cause['id']:0,'killer_name'=>(string)($cause['name']??'')]:true;foreach($results as &$result)if($result['actor']===($cast[$key]['name']??''))$result['reason'].=' È l’ultima azione concessa dalla Resilienza Suprema: al termine il corpo cede e la morte viene registrata.';unset($result);}
 foreach($conditions as &$condition)if(!empty($condition['restrained_by'])&&(!isset($hp[$condition['restrained_by']])||$hp[$condition['restrained_by']]<1))ai_tactical_release($condition);unset($condition);
 $changes=$ammoEffects;foreach($cast as $key=>$sheet){[$kind,$id]=explode(':',$key);$id=(int)$id;
  if(isset($deaths[$key]))$changes[]=['type'=>'death','kind'=>$kind,'id'=>$id,'text'=>ai_text(trim($wounds[$key].'; Esito fatale dopo l’esaurimento dei punti vita.'),500)]+(is_array($deaths[$key])?$deaths[$key]:['killer_id'=>0,'killer_name'=>'Esaurimento della Resilienza Suprema']);
  elseif($hp[$key]!==(int)$sheet['puntif']||$wounds[$key]!==$sheet['ferite'])$changes[]=['type'=>'health','kind'=>$kind,'id'=>$id,'hp'=>$hp[$key],'wounds'=>$wounds[$key]];
  if($conditions[$key]!==$states[$key])$changes[]=['type'=>'scene','kind'=>$kind,'id'=>$id,'state'=>$conditions[$key]];
 }
 foreach($infections as $key=>$infection){$index=$infection['result'];
  if(isset($deaths[$key])||$hp[$key]<1){$results[$index]['reason'].=' Il bersaglio non sopravvive al giro: il contagio non darà origine a una metamorfosi.';continue;}
  $results[$index]['reason'].=' Attraverso la ferita l’Ancestrale trasmette la maledizione: il contagio è reale. La prima Luna Piena imporrà la forma di Licantropo senza scelta e senza senzienza; se la Luna è già piena, il richiamo agisce subito.';
  $changes[]=['type'=>'infection','kind'=>'pg','id'=>(int)$cast[$key]['id'],'actor_kind'=>$infection['actor_kind'],'actor_id'=>$infection['actor_id']];
 }
 return ['engine'=>'alphano_scene_v1','scene_kind'=>ai_scene_kind($q),'round'=>$round,'review'=>false,'reasons'=>[],'results'=>$results,'allowed_changes'=>$changes,'can_finish'=>ai_scene_can_finish($sid),'rule'=>'Regolamento di regia Alphano v1'];
}
function ai_adjudication_fallback(array $technical):string{
 $parts=[];
 foreach($technical['results'] as $r){
  $actor=$r['actor'];$target=$r['target'];$outcome=$r['outcome'];
  $text=match(true){
   str_starts_with($outcome,'il morso ferisce')=>$actor.' trova finalmente il varco: i denti raggiungono '.$target.' e il morso apre una ferita.',
   str_starts_with($outcome,'il colpo raggiunge')=>'L’assalto di '.$actor.' supera la guardia di '.$target.'. Il colpo va a segno e lascia una ferita reale.',
   $outcome==='il colpo viene contenuto'=>$actor.' porta l’attacco contro '.$target.', ma la traiettoria non trova il varco cercato: il colpo viene contenuto.',
   $outcome==='il morso non raggiunge la carne'=>'La traiettoria di '.$actor.' si spezza contro la difesa di '.$target.': il morso non raggiunge la carne.',
   $outcome==='la presa si chiude e limita il movimento'=>$actor.' supera la tenuta di '.$target.' e chiude la presa: il movimento del bersaglio resta realmente limitato.',
   $outcome==='la presa non si chiude'=>$actor.' cerca di serrare la presa su '.$target.', ma non trova una tenuta sufficiente: il corpo dell’avversario non rimane bloccato.',
   $outcome==='copre il settore minacciato'=>$actor.' dà consistenza alla propria difesa: copre il settore minacciato e conserva una protezione sulla quale il compagno può fare affidamento.',
   str_contains($outcome,'tradire')=>'La voce cerca di insinuarsi nella volontà di '.$target.', ma la suggestione di '.$actor.' non ottiene il tradimento richiesto.',
   $outcome==='il tentativo decisivo fallisce e il PNG ripiega'=>$actor.' cerca l’ultima apertura contro '.$target.', ma non riesce a prevalere e ripiega.',
   $outcome==='non agisce'=>$actor.' lascia trascorrere il momento concesso senza portare una nuova azione nella contesa.',
   $outcome==='interrompe il proprio assalto'=>$actor.' interrompe l’assalto: per lui il ritmo della contesa si arresta, senza obbligare gli altri a fare altrettanto.',
   default=>$actor.($target!==''?' di fronte a '.$target:'').': '.$outcome.'.',
  };
  if(str_contains($outcome,'fatale'))$text.=' Per '.$target.' lo scontro è fatale.';
  $parts[]=$text.' '.$r['reason'];if(isset($r['corruption'])){$c=$r['corruption'];$parts[]=$actor.' valuta l’offerta corruttiva accertata: tiro '.$c['roll'].' su '.$c['chance'].'%. '.($c['resisted']?'Mantiene le proprie convinzioni.':'La proposta apre una concessione proporzionata per due turni, senza obbedienza assoluta e senza trasferimenti automatici di beni.');}
 }
 return implode("\n\n",$parts);
}
/** This capability is not exposed as an API: re-run the backend resolution under the publication locks. */
function ai_adjudication_apply(array $v,array $q,array $s):void{
 $db=ai_db();$sid=(int)$q['session_id'];if(!$db->inTransaction()||$q['mode']!=='auto'||ai_game_guidance($sid)['paused'])throw new DomainException('Regia automatica sospesa.',409);
 $technical=ai_adjudicate($sid);$changes=json_decode($v['payload'],true);if(ai_quest_json($changes)!==ai_quest_json($technical['allowed_changes']))throw new DomainException('Le conseguenze non corrispondono ai calcoli registrati.',409);
 // Preserve the proven attacker at the time of the bite, even if they fall later in this round.
 $infectionActors=[];foreach($changes as $change)if($change['type']==='infection')$infectionActors[$change['actor_kind'].':'.$change['actor_id']]=ai_quest_actor($change['actor_kind'],(int)$change['actor_id']);
 foreach($changes as $i=>$e){$kind=$e['kind'];$id=(int)$e['id'];$key='verdict:'.$v['id'].':effect:'.$i;if($db->query('SELECT id FROM ai_quest_effects WHERE effect_key=?',[$key]))continue;
  $before=$e['type']==='scene'?['cast'=>json_decode($db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$kind,$id])[0]['state'],true)]:ai_effect_state($kind,$id);
  if($e['type']==='disarm'){$item=$db->query('SELECT * FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[$e['instance'],$kind==='pg'?$id:ai_npc_owner($id)])[0]??null;if(!$item||$item['slot']==='')throw new DomainException('L’arma da disarmare è cambiata.',409);$before=['item'=>$item];$db->execute("UPDATE equippg SET slot='' WHERE id=? AND idpg=?",[$e['instance'],$kind==='pg'?$id:ai_npc_owner($id)]);}
  elseif($e['type']==='item-use'){$before=['item'=>item_state(item_instance((int)$e['declaration']['instance'],$id))];$d=$e['declaration'];item_use_commit($id,(int)$d['instance'],$d['operation'],'verdict:'.$v['id'].':item:'.$id,$sid,(int)$v['round_number'],(int)$d['provider']);}
  elseif($e['type']==='ammunition'){$state=json_decode($db->query('SELECT state FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[$sid,$kind,$id])[0]['state'],true);$owner=$kind==='pg'?$id:ai_npc_owner($id);$item=$db->query('SELECT * FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[(int)$e['instance'],$owner])[0]??null;if(!$item)throw new DomainException('Munizione cambiata durante la risoluzione.',409);$before=['cast'=>$state,'ammo_item'=>$item];if(!Consuma($owner,(int)$e['instance'],1))throw new DomainException('Munizione non consumabile.',409);}
  elseif($e['type']==='scene')$db->execute('UPDATE ai_quest_cast SET state=? WHERE session_id=? AND kind=? AND actor_id=?',[ai_quest_json($e['state']),$sid,$kind,$id]);
  elseif($e['type']==='infection'){
   $before['lineage_edge']=$db->query("SELECT * FROM lineage_members WHERE player_id=? AND kind='lycan'",[$id])[0]??null;
   $source=$infectionActors[$e['actor_kind'].':'.$e['actor_id']];
   alphano_lycan_infect($source,$id,$q['chat_key'],'Quest #'.$sid.', giro '.$v['round_number'].': contagio da morso ancestrale di '.$source['name'].'.');
  }
  elseif($kind==='pg'){
   if($e['type']==='death'){
    require_once __DIR__.'/lineages.inc.php';
    $victim=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$id])[0];
    lineage_death($victim,(int)($e['killer_id']??0),(string)($e['killer_name']??'Master IA'),$e['text']);
    $oldRace=(int)$before['razza'];$dead=in_array($oldRace,[2,5,7],true)?ALPHANO_RACE_DEAD_VAMPIRE:ALPHANO_RACE_SPIRIT;$db->execute('UPDATE utenti SET puntif=0,ferite=?,razza=?,infetto=0,ancestrale=0,senziente=0,datamorte=? WHERE id=?',[$e['text'],$dead,time(),$id]);}
   elseif(!$db->query("SELECT player_id FROM lineage_members WHERE player_id=? AND status='eliminated'",[$id]))$db->execute('UPDATE utenti SET puntif=?,ferite=? WHERE id=?',[$e['hp'],$e['wounds'],$id]);
   $db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,0,?, ?,0,?)',['regia-ia:'.$e['type'],time(),$q['chat_key'],$id,$e['hp']??0,'Regolamento Alphano v1, quest #'.$sid.', giro '.$v['round_number']]);
   InsertNote($id,0,'Quest #'.$sid.', giro '.$v['round_number'].': '.($e['type']==='death'?$e['text']:$e['wounds'].'; punti vita '.$e['hp'].'.'));
   alphano_record_activity('master-ia-esito',0,[['scope'=>'personale','id'=>$id,'section'=>'profilo','role'=>'member']],'Esito della quest registrato','La condizione di '.ai_quest_name($kind,$id).' è cambiata durante la quest.',['sessione'=>$sid,'giro'=>(int)$v['round_number']],'ai-effect-'.$v['id'].'-'.$i);
  }else{$n=$db->query('SELECT * FROM ai_npcs WHERE id=? FOR UPDATE',[$id])[0];$p=ai_npc_profile($n);$p['hit_points']=$e['type']==='death'?0:$e['hp'];$p['wounds']=$e['type']==='death'?$e['text']:$e['wounds'];$db->execute('UPDATE ai_npcs SET profile=?,active=? WHERE id=?',[ai_quest_json($p),$e['type']==='death'?0:1,$id]);}
  if($kind==='pg'&&in_array($e['type'],['health','death'],true))foreach($db->query("SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind='npc' AND active=1",[$sid]) as $observer){$summary=ai_quest_name('pg',$id).' · '.($e['type']==='death'?'è caduto nello scontro':($e['type']==='health'?'ha riportato una ferita concreta durante lo scontro':'la sua posizione nella contesa è cambiata secondo il verdetto')).'.';$db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,created_at,updated_at) VALUES(?,?,?,'quest',?,?,'system',?,?)",[(int)$observer['actor_id'],$id,$q['chat_key'],$summary,'effect:'.$key,time(),time()]);}
  $after=$e['type']==='disarm'?['item'=>$db->query('SELECT * FROM equippg WHERE id=?',[$e['instance']])[0]??null]:($e['type']==='item-use'?['operation'=>$e['declaration']['operation'],'instance'=>$e['declaration']['instance']]:($e['type']==='ammunition'?['cast'=>$before['cast'],'ammo_item'=>$db->query('SELECT * FROM equippg WHERE id=?',[(int)$e['instance']])[0]??null]:($e['type']==='scene'?['cast'=>$e['state']]:ai_effect_state($kind,$id))));
  if($e['type']==='infection')$after['lineage_edge']=$db->query("SELECT * FROM lineage_members WHERE player_id=? AND kind='lycan'",[$id])[0]??null;
  $db->execute("INSERT INTO ai_quest_effects(verdict_id,effect_key,effect_type,target_kind,target_id,before_state,after_state,actor_id,reason,created_at) VALUES(?,?,?,?,?,?,?,0,?,?)",[(int)$v['id'],$key,$e['type']==='health'?'hp':$e['type'],$kind,$id,ai_quest_json($before),ai_quest_json($after),'Regolamento di regia Alphano v1',time()]);
  if($kind==='npc'&&$e['type']==='death'){require_once __DIR__.'/npc-mortality.inc.php';alphano_npc_record_effect_death($key,$e);}
  if($kind==='npc'&&$e['type']==='health'&&(int)$e['hp']>0&&function_exists('ai_social_survival_effect'))foreach($technical['results'] as $cause)if(($cause['actor_kind']??'')==='pg'&&($cause['target_kind']??'')==='npc'&&(int)($cause['target_id']??0)===$id&&(int)($cause['damage']??0)>0)ai_social_survival_effect($e,['npc_id'=>$id,'player_id'=>(int)$cause['actor_id'],'actor_kind'=>'pg','actor_id'=>(int)$cause['actor_id'],'chat_key'=>$q['chat_key'],'effect_key'=>'effect:'.$key.':aggressor:'.(int)$cause['actor_id'],'before_hp'=>(int)$before['profile']['hit_points'],'after_hp'=>(int)$e['hp'],'source_id'=>(int)($cause['source_id']??0),'session_id'=>$sid]);
 }
 $db->execute("UPDATE ai_quest_verdicts SET status='applied' WHERE id=?",[(int)$v['id']]);ai_quest_audit($sid,'verdict_applied',['verdict'=>(int)$v['id'],'engine'=>'alphano_scene_v1','effects'=>count($changes)]);
 ai_quest_publish($sid,'verdict:'.$v['id'].':published',ai_verdict_public_text($v),'<div class="MasterKey">'.ai_verdict_public_html($v).'</div>');
 ai_npc_last_actions($v);
 // Only verified outcomes enter personal memories; private technical notes do not become NPC knowledge.
 foreach($technical['results'] as $r)if($r['target']!=='')foreach($db->query("SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind='npc' AND active=1",[$sid]) as $npc)ai_append_memory($q['chat_key'],(int)$npc['actor_id'],ai_text($r['actor'].' · '.$r['outcome'].'. '.$r['reason'],650));
 ai_quest_next_round($sid,0,!empty(json_decode($v['technical'],true)['scene_finished']));
}

/** The optional atmosphere cannot add another actor's turn to an adjudicated round. */
function ai_adjudication_atmosphere_safe(string $text,array $names=[]):bool{
 if($text===''||mb_strlen($text)>650)return false;
 foreach($names as $name)if(is_string($name)&&$name!==''&&ai_mentions_npc($text,$name))return false;
 return !preg_match('/<|```|[\x{3400}-\x{9fff}\x{0400}-\x{04ff}]|convalid|nessun esito|punti vita|corone|ferisce|uccid|muore|morto|cade|sanguin|morso|immobilizz|piogg|piov|nevic|neve|temporale|\b(?:vento|sole|nebbia|grandine|tuono|gelo|sergente|guardia|guardie|soldato|soldati|uomo|donna|visitatore|compagno|qualcuno|nessuno|egli|ella|lui|lei)\b/iu',$text);
}
function ai_adjudication_narrate(array $room,array $technical,bool $offline=false):array{
 $facts=ai_adjudication_fallback($technical);$intro='';
 // Combat already has a concrete, complete narration of verified outcomes.
 // An unrelated atmospheric preamble cannot improve that account or set facts.
 if(!$offline&&($technical['scene_kind']??'')!=='combattimento')try{
  // A model enriches the atmosphere; verified decisions and their reasons always remain present.
  $v=ai_quest_generate_json('Sei il narratore di Alphano. Introduci questo esito con due frasi evocative e concrete in italiano (massimo 55 parole), al presente narrativo, legate al luogo e alla tensione delle dichiarazioni. Non inventare condizioni meteorologiche diverse dai dati forniti. Non nominare personaggi e non descrivere loro azioni, dialoghi, movimenti, pensieri o reazioni. Non introdurre pioggia, neve, sole o altri eventi meteorologici. Non decidere ferite, morti, premi o poteri. Non rimandare a convalide, non scrivere formule tecniche. Restituisci {"narrative":"..."}. Le decisioni complete vengono aggiunte dal backend.', ['location'=>ai_text($room['public_setting'],250),'decisions'=>array_map(static fn($r)=>['actor'=>$r['actor'],'target'=>$r['target'],'outcome'=>$r['outcome'],'reason'=>ai_text($r['reason'],190)],$technical['results'])],220);
  $candidate=$v['narrative']??'';
  $names=[];foreach($technical['results'] as $r)$names=array_merge($names,[$r['actor'],$r['target']]);
  foreach(ai_db()->query('SELECT name FROM ai_npcs WHERE chat_key=?',[$room['chat_key']]) as $n)$names[]=$n['name'];
  if(is_string($candidate)&&ai_adjudication_atmosphere_safe($candidate,$names))$intro=trim($candidate)."\n\n";
 }catch(RuntimeException|JsonException $e){error_log('[Regia atmosfera] '.$e->getMessage());}
 return ['narrative'=>ai_text($intro.$facts,12000),'changes'=>$technical['allowed_changes'],'scene_finished'=>(bool)$technical['can_finish'],'finish_reason'=>'I partecipanti hanno concluso la scena.'];
}

/** A bounded end-of-quest grant: the model never chooses an amount or invents a recipient. */
function ai_quest_settle(int $sid,string $reason):void{
 $db=ai_db();$q=$db->query('SELECT * FROM ai_quests WHERE session_id=? FOR UPDATE',[$sid])[0];if(!ai_adjudication_enabled($sid)||$q['mode']!=='auto')return;
 $cfg=ai_scene_rules();$room=ai_room($q['chat_key']);$g=ai_game_guidance($sid);$count=(int)$db->query('SELECT COUNT(*) n FROM ai_quest_verdicts WHERE session_id=? AND status=\'applied\'',[$sid])[0]['n'];
 foreach($db->query("SELECT DISTINCT actor_id FROM ai_quest_cast WHERE session_id=? AND kind='pg'",[$sid]) as $c){$uid=(int)$c['actor_id'];
  if($db->query('SELECT player_id FROM ai_quest_settlements WHERE session_id=? AND player_id=?',[$sid,$uid]))continue;
  $actions=$db->query("SELECT details FROM ai_quest_actions WHERE session_id=? AND kind='pg' AND actor_id=? AND status='submitted'",[$sid,$uid]);$money=0;$text='Quest #'.$sid.' conclusa: '.ai_text($reason,220).'. Giri risolti: '.$count.'.';
  $eligible=count($actions)>=$cfg['reward_min_actions']&&$count>=$cfg['reward_min_rounds'];
  // A violent attack on a guard is not a repeatable source of money.
  foreach($actions as $declaration){$detail=json_decode($declaration['details'],true)?:[];if(in_array($detail['intent']??'',['assalto','attacco','furto','arresto','potere'],true)||($detail['social_category']??'')==='minaccia')$eligible=false;}
  $u=$db->query('SELECT denari,puntif FROM utenti WHERE id=? FOR UPDATE',[$uid])[0]??null;if(!$u)continue;
  $daily=(int)$db->query('SELECT COALESCE(SUM(amount),0) n FROM ai_quest_settlements WHERE player_id=? AND created_at>?',[$uid,time()-86400])[0]['n'];$last=(int)$db->query('SELECT COALESCE(MAX(created_at),0) n FROM ai_quest_settlements WHERE player_id=? AND amount>0',[$uid])[0]['n'];
  if($eligible&&$u['puntif']>0&&time()-$last>=$cfg['reward_cooldown'])$money=max(0,min(100,(int)$room['quest_reward'],$cfg['reward_daily_cap']-$daily));
  if($money){$db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$money,$uid]);$text.=' Ricompensa di '.$money.alphano_currency_text(' corone per la partecipazione alla giocata.');$db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,0,?,?,0,?)',['regia-ia:ricompensa',time(),$q['chat_key'],$uid,$money,$text]);}
  $xp=rpg_combat_quest_award($sid,$uid,$count,count($actions));if($xp)$text.=' Esperienza maturata: '.$xp.' punti.';
  InsertNote($uid,0,$text);$db->execute('INSERT INTO ai_quest_settlements(session_id,player_id,amount,note,created_at) VALUES(?,?,?,?,?)',[$sid,$uid,$money,$text,time()]);
  alphano_record_activity('master-ia-chiusura',0,[['scope'=>'personale','id'=>$uid,'section'=>'profilo','role'=>'member']],'La quest è conclusa',$text,['sessione'=>$sid,'corone'=>$money,'esperienza'=>$xp],'quest-settlement-'.$sid.'-'.$uid);
  if($money||$xp)ai_quest_publish($sid,'quest:'.$sid.':reward:'.$uid,ai_quest_name('pg',$uid).' matura '.($xp?$xp.' punti esperienza':'').($xp&&$money?' e ':'').($money?$money.alphano_currency_text(' corone'):'').' per la giocata. La cronaca della quest è annotata nel profilo.',ai_reward_public_html(ai_quest_name('pg',$uid),$xp,$money,$uid));
 }
}
