<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/ai-profile.inc.php';

/** Public deaths are facts from effect receipts or an authenticated admin, never prose. */
function alphano_npc_mortality_ready(bool $refresh=false):bool {
 static $ready=null;if($refresh||$ready===null)$ready=(bool)ConnectDB()->query("SHOW TABLES LIKE 'npc_deaths'");return $ready;
}
function alphano_npc_death(int $npc):?array {
 if(!alphano_npc_mortality_ready())return null;
 return ConnectDB()->query("SELECT * FROM npc_deaths WHERE npc_id=? AND status='active' ORDER BY id DESC LIMIT 1",[$npc])[0]??null;
}
function alphano_npc_victims(int $player,int $limit=60):array {
 if(!alphano_npc_mortality_ready())return ['count'=>0,'items'=>[]];$db=ConnectDB();$limit=max(1,min(200,$limit));
 return ['count'=>(int)$db->query("SELECT COUNT(*) n FROM npc_deaths WHERE status='active' AND killer_kind='pg' AND killer_id=?",[$player])[0]['n'],
  'items'=>$db->query("SELECT id,npc_id,npc_name,portrait,death_at,place_name,chat_key,circumstance,story FROM npc_deaths WHERE status='active' AND killer_kind='pg' AND killer_id=? ORDER BY death_at DESC,id DESC LIMIT ".$limit,[$player])];
}
function alphano_npc_mortality_plain(string $text,int $limit=1000):string {
 $text=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$text)??'';
 $text=preg_replace('~</?(?:div|p|li|h[1-6])\b[^>]*>|<br\s*/?>~i',"\n",$text)??'';
 return mb_substr(trim(html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8')),0,$limit);
}
/** Internal writer; caller has verified the source and holds the same transaction as the death. */
function alphano_npc_mortality_insert(int $npc,array $entry):array {
 $db=ConnectDB();if(!$db->inTransaction())throw new LogicException('La morte del PNG richiede una transazione unica.');
 $n=$db->query('SELECT * FROM ai_npcs WHERE id=? FOR UPDATE',[$npc])[0]??null;if(!$n)throw new DomainException('PNG inesistente.',404);
 $profile=ai_npc_profile($n);if((int)$profile['hit_points']>0)throw new DomainException('Non è possibile registrare come morto un PNG ancora vivo.',409);
 $old=$db->query("SELECT * FROM npc_deaths WHERE npc_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$npc])[0]??null;if($old)return $old;
 $key=(string)$entry['source_key'];if($old=$db->query('SELECT * FROM npc_deaths WHERE source_key=?',[$key])[0]??null)return $old;
 $place=$db->query('SELECT title FROM mappe WHERE BINARY chat_key=BINARY ? LIMIT 1',[$n['chat_key']])[0]['title']??$n['chat_key'];
 $db->execute("INSERT INTO npc_deaths(npc_id,source_key,effect_key,session_id,verdict_id,status,killer_kind,killer_id,killer_name,chat_key,place_name,circumstance,death_at,npc_name,portrait,story,profile_snapshot,created_by,created_at) VALUES(?,?,?,?,?,'active',?,?,?,?,?,?,?,?,?,?,?,?,?)",
 [$npc,$key,(string)($entry['effect_key']??''),(int)($entry['session_id']??0),(int)($entry['verdict_id']??0),(string)($entry['killer_kind']??'unknown'),(int)($entry['killer_id']??0),(string)($entry['killer_name']??''),$n['chat_key'],$place,alphano_npc_mortality_plain((string)($entry['circumstance']??'Circostanze non documentate.'),1000),(int)($entry['death_at']??time()),$n['name'],$profile['portrait']?:'/assets/placeholder.svg',alphano_npc_mortality_plain((string)$profile['biography'],2500),json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)($entry['created_by']??0),time()]);
 $id=(int)$db->lastInsertId();$db->execute('UPDATE ai_npcs SET active=0 WHERE id=?',[$npc]);
 $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Il PNG è deceduto: intervento annullato.' WHERE npc_id=? AND status IN('queued','running')",[time(),$npc]);
 $db->execute("UPDATE ai_social_jobs s JOIN ai_jobs j ON j.id=s.job_id SET s.status='cancelled',s.error='Il PNG è deceduto.' WHERE j.npc_id=? AND s.status IN('queued','running')",[$npc]);
 // Keep the character and all memories/inventory; remove only live participation.
 $db->execute("UPDATE ai_quest_cast SET active=0,state=JSON_SET(state,'$.dead',TRUE) WHERE kind='npc' AND actor_id=?",[$npc]);
 return $db->query('SELECT * FROM npc_deaths WHERE id=?',[$id])[0];
}
/** Only an already committed-in-transaction, typed consequence can credit a combat kill. */
function alphano_npc_record_effect_death(string $effectKey,array $change=[],int $reviewer=0):?array {
 if(!alphano_npc_mortality_ready())return null;$db=ConnectDB();
 $e=$db->query("SELECT e.*,v.session_id,v.round_number,v.technical,v.payload,s.chat FROM ai_quest_effects e JOIN ai_quest_verdicts v ON v.id=e.verdict_id JOIN sessioni s ON s.id=v.session_id WHERE e.effect_key=? AND e.target_kind='npc' AND e.effect_type='death' AND e.status='applied'",[$effectKey])[0]??null;
 if(!$e)throw new DomainException('Manca un esito di morte verificato.',409);
 $before=json_decode($e['before_state'],true);$after=json_decode($e['after_state'],true);
 if((int)($before['profile']['hit_points']??0)<=0||(int)($after['profile']['hit_points']??1)>0)throw new DomainException('La conseguenza non documenta la morte di un PNG vivo.',409);
 $auto=(json_decode($e['technical'],true)['engine']??'')==='alphano_scene_v1'&&(int)$e['actor_id']===0;
 if(!$auto){$u=$db->query('SELECT admin,fatepower,bloccato,chat FROM utenti WHERE id=?',[$reviewer])[0]??[];if($reviewer<1||(int)$e['actor_id']!==$reviewer||(!($u['admin']??0)&&!($u['fatepower']??0))||!empty($u['bloccato'])||($u['chat']??'')!==$e['chat'])throw new DomainException('La morte richiede una convalida autentica del Master.',403);}
 if($auto){$parts=explode(':',$effectKey);$index=(int)end($parts);$change=json_decode($e['payload'],true)[$index]??[];}
 if(($change['type']??'')!=='death'||($change['kind']??'')!=='npc'||(int)($change['id']??0)!==(int)$e['target_id'])throw new DomainException('La morte non corrisponde al destinatario verificato.',409);
 $kind=(string)($change['killer_kind']??((int)($change['killer_id']??0)>0?'pg':'unknown'));
 $killer=$kind==='pg'?(int)($change['killer_id']??$change['killer_actor_id']??0):($kind==='npc'?(int)($change['killer_actor_id']??0):0);$name='';
 if(!in_array($kind,['pg','npc'],true)||$killer<1||!$db->query('SELECT actor_id FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=?',[(int)$e['session_id'],$kind,$killer])){$kind='unknown';$killer=0;}
 else{$name=$db->query('SELECT '.($kind==='pg'?'nome':'name').' AS name FROM '.($kind==='pg'?'utenti':'ai_npcs').' WHERE id=?',[$killer])[0]['name']??'';if($name===''){$kind='unknown';$killer=0;}}
 return alphano_npc_mortality_insert((int)$e['target_id'],['source_key'=>'effect:'.$effectKey,'effect_key'=>$effectKey,'session_id'=>(int)$e['session_id'],'verdict_id'=>(int)$e['verdict_id'],'killer_kind'=>$kind,'killer_id'=>$killer,'killer_name'=>$name,'circumstance'=>(string)($change['text']??'Morte convalidata durante lo scontro.'),'death_at'=>(int)$e['created_at'],'created_by'=>$reviewer]);
}
/** HP zero entered by an admin establishes death, but never an invented murderer. */
function alphano_npc_record_admin_death(int $npc,int $admin):?array {
 if(!alphano_npc_mortality_ready())return null;$db=ConnectDB();$u=$db->query('SELECT admin,bloccato FROM utenti WHERE id=?',[$admin])[0]??[];
 if(empty($u['admin'])||!empty($u['bloccato']))throw new DomainException('Registrazione riservata agli amministratori.',403);
 return alphano_npc_mortality_insert($npc,['source_key'=>'admin:'.$npc.':'.bin2hex(random_bytes(8)),'circumstance'=>'Decesso registrato dall’amministrazione. Non è documentata una responsabilità in combattimento.','created_by'=>$admin]);
}
function alphano_npc_assert_profile_edit(int $npc,array $profile,int $active):void {
 if(alphano_npc_death($npc)&&((int)$profile['hit_points']>0||$active))throw new DomainException('Questo PNG ha un memoriale: rettifica prima la registrazione amministrativa dal memoriale, oppure l’esito di combattimento dal registro degli effetti. La scheda non può resuscitarlo.',409);
}
/** Correct only an erroneous, noncombat admin entry. The death id is the replay/CAS key. */
function alphano_npc_correct_admin_death(int $npc,int $expectedDeath,int $admin,int $hitPoints,string $reason):array {
 $db=ConnectDB();if(!$db->inTransaction())throw new LogicException('La rettifica richiede una transazione unica.');
 if(!alphano_npc_mortality_ready())throw new DomainException('Registro dei decessi non disponibile.',409);
 // Re-read authorization after acquiring its lock: a stale page cannot retain admin power.
 $u=$db->query('SELECT admin,bloccato FROM utenti WHERE id=? FOR UPDATE',[$admin])[0]??[];
 if(empty($u['admin'])||!empty($u['bloccato']))throw new DomainException('Rettifica riservata agli amministratori.',403);
 if($hitPoints<1||$hitPoints>maxpuntif)throw new DomainException('Scegli punti ferita da 1 a '.maxpuntif.'.',422);
 $reason=alphano_npc_mortality_plain($reason,1000);if($reason==='')throw new DomainException('Indica il motivo della rettifica.',422);
 $n=$db->query('SELECT * FROM ai_npcs WHERE id=? FOR UPDATE',[$npc])[0]??null;if(!$n)throw new DomainException('PNG inesistente.',404);
 $death=$db->query('SELECT * FROM npc_deaths WHERE id=? AND npc_id=? FOR UPDATE',[$expectedDeath,$npc])[0]??null;
 if(!$death||!str_starts_with($death['source_key'],'admin:'.$npc.':')||$death['effect_key']!=='')throw new DomainException('Questa procedura corregge soltanto i decessi registrati dal pannello amministrativo. Gli esiti di combattimento richiedono lo storno del loro effetto.',409);
 if($death['status']==='reverted'){
  if((int)$death['reverted_by']!==$admin||(int)$death['restored_hp']!==$hitPoints||$death['revert_reason']!==$reason)throw new DomainException('Questa registrazione è già stata rettificata con dati differenti.',409);
  return [...$death,'replayed'=>true]; // A retry never rewrites HP, presence or later history.
 }
 if($death['status']!=='active')throw new DomainException('Registrazione non più rettificabile.',409);
 $current=$db->query("SELECT id FROM npc_deaths WHERE npc_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$npc])[0]??[];
 if((int)($current['id']??0)!==$expectedDeath)throw new DomainException('Il registro è cambiato. Ricarica il memoriale.',409);
 $profile=ai_npc_profile($n);if((int)$profile['hit_points']>0)throw new DomainException('La scheda non corrisponde più al decesso. Verifica il registro.',409);
 $profile['hit_points']=$hitPoints;
 // Do not reopen scenes or cancelled interventions: reactivation is a separate admin choice.
 $db->execute('UPDATE ai_npcs SET profile=?,active=0 WHERE id=?',[json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$npc]);
 $db->execute("UPDATE npc_deaths SET status='reverted',reverted_at=?,reverted_by=?,revert_reason=?,restored_hp=? WHERE id=?",[time(),$admin,$reason,$hitPoints,$expectedDeath]);
 return [...$db->query('SELECT * FROM npc_deaths WHERE id=?',[$expectedDeath])[0],'replayed'=>false];
}
/** A correction removes kill credit and archives the correction; it never reopens a quest. */
function alphano_npc_revert_death(string $effectKey,int $reviewer,string $reason):void {
 if(!alphano_npc_mortality_ready())return;$db=ConnectDB();if(!$db->inTransaction())throw new LogicException('Rettifica fuori transazione.');
 $u=$db->query('SELECT admin,fatepower,bloccato FROM utenti WHERE id=?',[$reviewer])[0]??[];
 if((empty($u['admin'])&&empty($u['fatepower']))||!empty($u['bloccato']))throw new DomainException('Rettifica riservata allo staff autorizzato.',403);
 $effect=$db->query("SELECT target_id FROM ai_quest_effects WHERE effect_key=? AND effect_type='death' AND target_kind='npc' AND status='reverted'",[$effectKey])[0]??null;
 if(!$effect)throw new DomainException('La rettifica richiede lo storno verificato dell’esito di morte.',409);
 $npc=$db->query('SELECT profile FROM ai_npcs WHERE id=?',[(int)$effect['target_id']])[0]??[];if((int)ai_npc_profile($npc)['hit_points']<=0)throw new DomainException('La rettifica non ha ripristinato un PNG vivo.',409);
 $db->execute("UPDATE npc_deaths SET status='reverted',reverted_at=?,reverted_by=?,revert_reason=? WHERE effect_key=? AND status='active'",[time(),$reviewer,alphano_npc_mortality_plain($reason,1000),$effectKey]);
}
