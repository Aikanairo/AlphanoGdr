<?php
declare(strict_types=1);
require_once __DIR__.'/ai-verdict-presentation.inc.php';
/** Public receipts derive from committed effects, never from a model's proposed numbers. */
function ai_verdict_receipts(int $vid):array {
 $rows=[];
 foreach(ai_db()->query("SELECT * FROM ai_quest_effects WHERE verdict_id=? AND status='applied' ORDER BY id",[$vid]) as $e){
  $before=json_decode($e['before_state'],true)?:[];$after=json_decode($e['after_state'],true)?:[];$npc=$e['target_kind']==='npc';
  $hp0=$npc?($before['profile']['hit_points']??null):($before['puntif']??null);$hp1=$npc?($after['profile']['hit_points']??null):($after['puntif']??null);
  $w0=(string)($npc?($before['profile']['wounds']??''):($before['ferite']??''));$w1=(string)($npc?($after['profile']['wounds']??''):($after['ferite']??''));
  $changes=[];$add=static function(string $key,string $label,string $value,?int $delta=null)use(&$changes):void{$changes[]=['key'=>$key,'label'=>$label,'value'=>$value,'delta'=>$delta,'icon'=>ai_scene_effect_icon($key)];};
  $parts=[];if($hp0!==null&&$hp1!==null&&(int)$hp0!==(int)$hp1){$delta=(int)$hp1-(int)$hp0;$parts[]='Punti vita '.(int)$hp0.' → '.(int)$hp1.' ('.($delta>0?'+':'').$delta.')';$add('health','Punti vita',(int)$hp0.' → '.(int)$hp1.' ('.($delta>0?'+':'').$delta.')',$delta);}
  if($w0!==$w1){$value=$w1!==''?ai_text(ltrim($w1,'; '),500):'Nessuna ferita';$parts[]='Ferite: '.$value;$add('wound','Ferite registrate',$value);}
  if($e['effect_type']==='death'){$parts[]='Morte accertata';$add('death','Condizione','Morte accertata');}
  if($e['effect_type']==='infection'&&empty($before['infetto'])&&!empty($after['infetto']))$parts[]='Contagio licantropico registrato · metamorfosi obbligata alla prima Luna Piena';
  if(!$npc&&in_array($e['effect_type'],['hp','death','infection'],true)){
   // Only a receipt is public. The content of notepg is private to authorized Masters.
   $v=ai_db()->query('SELECT session_id,round_number FROM ai_quest_verdicts WHERE id=?',[$vid])[0];
   if(ai_db()->query('SELECT id FROM notepg WHERE pg=? AND nota LIKE ? LIMIT 1',[(int)$e['target_id'],'Quest #'.$v['session_id'].', giro '.$v['round_number'].':%']))$parts[]='Nota Master registrata nel profilo';
  }
  if(in_array($e['effect_type'],['note','condition'],true))$parts[]='Annotazione del Master registrata';
  if(!$changes&&$parts)$add('note','Registrazione',implode(' · ',$parts));
  if($e['effect_type']==='money'){$m0=$before['money']['player_balance']??null;$m1=$after['money']['player_balance']??null;if($m0!==null&&$m1!==null){$d=(int)$m1-(int)$m0;$parts[]=alphano_currency_text('Corone: ').($d>0?'+':'').$d;$add('money',alphano_currency_text('Corone'),($d>0?'+':'').$d,$d);}}
  if($e['effect_type']==='disarm'){$value='Disarmato: '.ai_text((string)($before['item']['nome']??'Arma'),140);$parts[]=$value;$add('item','Equipaggiamento',$value,0);}
  if($e['effect_type']==='item'){
   $item=$after['item']??$before['item']??[];$catalog=(int)($item['uid']??0);$name=trim((string)($item['nome']??''));
   if($name===''&&$catalog>0)$name=(string)(ai_db()->query('SELECT shortDesc FROM equip WHERE id=?',[$catalog])[0]['shortDesc']??'');
   $name=$name!==''?$name:'Oggetto';
   $from=(int)($before['item']['idpg']??0);$to=(int)($after['item']['idpg']??0);
   $value=($from===$to?'Aggiornato: ':'Ricevuto: ').ai_text($name,140);$parts[]=$value;$add('item','Equipaggiamento',$value,$from===$to?0:1);
   if($from&&$from!==$to){$fromKind=$from<0?'npc':'pg';$fromId=abs($from);$rows[]=['kind'=>$fromKind,'id'=>$fromId,'name'=>ai_quest_name($fromKind,$fromId),'text'=>'Ceduto: '.ai_text($name,140),'changes'=>[['key'=>'item','label'=>'Equipaggiamento','value'=>'Ceduto: '.ai_text($name,140),'delta'=>-1,'icon'=>ai_scene_effect_icon('item')]]];}
  }
  if(in_array($e['effect_type'],['ammunition','reload','item-use'],true)){$value=$e['effect_type']==='reload'?'Ricarica registrata':($e['effect_type']==='ammunition'?'Munizione consumata':'Utilizzo oggetto registrato');$parts[]=$value;$add('ammunition','Equipaggiamento',$value);}
  if($parts)$rows[]=['kind'=>$e['target_kind'],'id'=>(int)$e['target_id'],'name'=>ai_quest_name($e['target_kind'],(int)$e['target_id']),'text'=>implode(' · ',$parts),'changes'=>$changes];
 }
 return $rows;
}
function ai_verdict_public_text(array $v):string {
 $rows=ai_verdict_receipts((int)$v['id']);$situation=ai_tactical_situation((int)$v['session_id']);return $v['narrative'].($rows?"\n\nConseguenze registrate\n".implode("\n",array_map(static fn($r)=>$r['name'].' — '.$r['text'],$rows)):'').($situation?"\n\nSituazione al termine del giro\n".implode("\n",$situation):'');
}
/** An NPC receives their own result even when it was at the end of a long verdict. */
function ai_npc_continuity(int $sid,int $nid):array {
 $db=ai_db();$v=$db->query("SELECT * FROM ai_quest_verdicts WHERE session_id=? AND status IN('applied','human') ORDER BY round_number DESC LIMIT 1",[$sid])[0]??null;
 $own=[];$world=[];$verdict='';
 if($v){$technical=json_decode($v['technical'],true)?:[];
  foreach($technical['results']??[] as $r){$line=($r['actor']??'').(!empty($r['target'])?' contro '.$r['target']:'').' · '.($r['outcome']??'').'. '.($r['reason']??'');
   if(($r['actor']??'')===ai_quest_name('npc',$nid)||($r['target']??'')===ai_quest_name('npc',$nid))$own[]=ai_text($line,600);else $world[]=ai_text($line,350);
  }
  foreach(ai_verdict_receipts((int)$v['id']) as $r){$line=$r['name'].' · '.$r['text'];if($r['kind']==='npc'&&$r['id']===$nid)$own[]= $line;else $world[]=$line;}
  $verdict=ai_text($v['narrative'],900);
 }
 $sheet=ai_quest_actor('npc',$nid);$state=json_decode($db->query("SELECT state FROM ai_quest_cast WHERE session_id=? AND kind='npc' AND actor_id=?",[$sid,$nid])[0]['state']??'{}',true)?:[];
 // Human Master public directions after the last verdict also establish the scene.
 $directions=[];$q=$db->query('SELECT q.chat_key,s.datain,s.start_source_id FROM ai_quests q JOIN sessioni s ON s.id=q.session_id WHERE q.session_id=?',[$sid])[0]??[];
 if($q){
  // A new session may start in the very second the previous one ended. Use
  // durable source IDs, and exclude ambiguous same-second legacy messages.
  $where=['BINARY c.chat=?','c.dest=0','(u.admin=1 OR u.fatepower=1)'];$params=[$q['chat_key']];
  $where[]=isset($q['start_source_id'])?'c.id>?':'c.ora>?';$params[]=isset($q['start_source_id'])?(int)$q['start_source_id']:(int)$q['datain'];
  if($v){
   $source=(int)($db->query('SELECT chat_id FROM ai_quest_outbox WHERE session_id=? AND event_key=?',[$sid,'verdict:'.$v['id'].':published'])[0]['chat_id']??0);
   if(!$source&&$v['status']==='human')foreach($db->query("SELECT payload FROM ai_quest_audit WHERE session_id=? AND event_type='human_verdict' ORDER BY id DESC",[$sid]) as $audit){
    $event=json_decode($audit['payload'],true)?:[];if((int)($event['round']??0)===(int)$v['round_number']){$source=(int)($event['source']??0);break;}
   }
   $where[]=$source?'c.id>?':'c.ora>?';$params[]=$source?:max((int)$q['datain'],(int)$v['created_at']);
  }
  // MasterKey in a player's prose is not an outcome. Classify the actual
  // public HTML body, and only then retain the latest two human directions.
  foreach($db->query('SELECT c.id,c.pg,c.dest,c.msg,c.ora FROM chat c JOIN utenti u ON u.id=c.pg WHERE '.implode(' AND ',$where).alphano_chat_live_filter().' ORDER BY c.id DESC',$params) as $r){
   $entry=alphano_session_context_entry($r);if(($entry['kind']??'')!=='master')continue;
   $directions[]=ai_text($entry['text'],600);if(count($directions)===2)break;
  }
 }
 return ['own'=>$own,'scene'=>array_slice($world,0,8),'situation'=>ai_tactical_situation($sid),'verdict'=>$verdict,'human_directions'=>array_reverse($directions),'hp'=>(int)($sheet['puntif']??0),'wounds'=>$sheet['ferite']??'','state'=>$state];
}
function ai_npc_continuity_compact(array $c):array {
 $clip=static fn($items,$length,$count)=>array_map(static fn($v)=>ai_text((string)$v,$length),array_slice($items,-$count));
 return ['hp'=>$c['hp'],'wounds'=>ai_text($c['wounds'],180),
  'state'=>array_intersect_key($c['state'],array_flip(['restrained_by','restrained_until','restrained_kind','position','relative_to','dead','last_stand','distance'])),
  'situation'=>array_slice($c['situation']??[],0,8),
  'own'=>$clip($c['own'],180,2),'human_directions'=>$clip($c['human_directions'],180,2),'scene'=>$clip($c['scene'],100,2)];
}
function ai_npc_continuity_prompt(int $sid,int $nid,bool $compact=false):string {
 $c=ai_npc_continuity($sid,$nid);if($compact)$c=ai_npc_continuity_compact($c);
 return "\nBASE VINCOLANTE DELLA SCENA, STABILITA DAL MASTER: ".ai_quest_json($c)."\nQuesti esiti sono già accaduti: non sono tentativi da ripetere o annullare. Adatta gesti e intenzioni alle ferite, alle prese e alle perdite accertate. Non ricominciare la scena. Non usare un arto dichiarato inutilizzabile, non liberarti da una presa accertata senza tentarlo; un personaggio morto non può tornare a combattere. Le nuove azioni sono tentativi, gli esiti precedenti sono fatti.";
}
/** A verified death gets one final character action, atomically after the verdict.
 * No further inference is needed: an unavailable model must never resurrect or delay a dead NPC.
 */
function ai_npc_last_actions(array $v):void {
 $db=ai_db();$sid=(int)$v['session_id'];
 foreach($db->query("SELECT * FROM ai_quest_effects WHERE verdict_id=? AND target_kind='npc' AND effect_type='death' AND status='applied' ORDER BY id",[(int)$v['id']]) as $e){
  $prior=json_decode($e['before_state'],true)?:[];if((int)($prior['profile']['hit_points']??0)<=0)continue;
  $nid=(int)$e['target_id'];$n=$db->query('SELECT * FROM ai_npcs WHERE id=?',[$nid])[0]??null;if(!$n||(int)ai_npc_profile($n)['hit_points']>0)continue;
  $key='verdict:'.$v['id'].':last-action:'.$nid;if($db->query('SELECT id FROM ai_quest_outbox WHERE event_key=?',[$key]))continue;
  $name=$n['name'];$p=ai_npc_profile($n);$injury=ai_text(preg_replace('/;?\s*Esito fatale.*$/u','',$p['wounds']),300);
  $text=$name.' non riesce più a sostenersi. '.($injury!==''?'Le ferite — '.$injury.' — accompagnano gli ultimi istanti. ':'').'Il respiro si spezza; le dita si allentano, il corpo si abbandona al suolo. L’ultimo sguardo resta rivolto al luogo della sua ultima scena, poi si spegne. '.$name.' muore.';
  $html='<div class="ai-narration scene-note" data-npc-id="'.$nid.'" data-message-type="action"><div class="chat-character-header"><time>'.date('H:i').'</time> '.ai_profile_link($n).'</div><div class="chat-character-text">'.alphano_chat_text($text,$p['sex'],true).'</div></div>';
  ai_quest_publish($sid,$key,'',$html);
  $db->execute("UPDATE ai_quest_cast SET active=0,state=JSON_SET(state,'$.dead',true) WHERE session_id=? AND kind='npc' AND actor_id=?",[$sid,$nid]);
  ai_append_memory($n['chat_key'],0,$text);ai_quest_audit($sid,'npc_last_action',['npc'=>$nid,'verdict'=>(int)$v['id']]);
 }
}
/** Choose an actual threat to this NPC or a living ally, rather than any attack by any PG. */
function ai_npc_quest_threat(array $q,int $nid,int $round):?array {
 $db=ai_db();$faction=ai_npc_game($nid)['faction'];$allies=[$nid];
 if($faction!=='')foreach($db->query("SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind='npc' AND active=1",[(int)$q['session_id']]) as $a)if(ai_npc_game((int)$a['actor_id'])['faction']===$faction)$allies[]=(int)$a['actor_id'];
 foreach($db->query("SELECT actor_id,details,content FROM ai_quest_actions WHERE session_id=? AND round_number=? AND kind='pg' ORDER BY id DESC",[(int)$q['session_id'],$round]) as $r){$d=json_decode($r['details'],true)?:[];if((in_array($d['intent']??'',['assalto','attacco','potere','arresto','furto'],true)||($d['social_category']??'')==='minaccia')&&($d['target_kind']??'')==='npc'&&in_array((int)($d['target_id']??0),$allies,true))return $r;}
 return null;
}
