<?php
declare(strict_types=1);
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/libequip.inc.php';
require_once __DIR__.'/ai-verdict-presentation.inc.php';
const CHAT_WORK_DEFAULTS=['enabled'=>true,'max_units'=>10,'window_seconds'=>21600,'rest_seconds'=>21600,'require_narration'=>true,'narration_min_chars'=>20,'attempt_cooldown'=>60,'bonus_step'=>10,'max_bonus'=>3];
function alphano_work_settings():array {
 $db=ConnectDB();$row=$db->query('SELECT settings FROM chat_work_settings WHERE id=1')[0]??null;
 if(!$row){$db->execute('INSERT IGNORE INTO chat_work_settings(id,settings,updated_at) VALUES(1,?,?)',['{}',time()]);$row=$db->query('SELECT settings FROM chat_work_settings WHERE id=1')[0];}
 return array_replace(CHAT_WORK_DEFAULTS,json_decode($row['settings'],true)?:[]);
}
function alphano_work_resources():array {
 $rows=ConnectDB()->query("SELECT e.id,e.shortDesc AS name,e.materiaprima,e.immagine FROM equip e JOIN tipo t ON t.id=e.tipo WHERE t.tipoG='risorsa' AND e.approvato=1 AND e.visibile=1 AND e.corp=0 ORDER BY e.shortDesc");
 return array_values(array_map(static function($r){$r['id']=(int)$r['id'];$r['image']=alphano_equipment_image(rawurldecode($r['immagine']));return $r;},array_filter($rows,static fn($r)=>in_array($r['materiaprima'],MATERIE_PRIME,true))));
}
function alphano_work_catalog(string $chat=''):array {
 return ConnectDB()->query("SELECT l.*,COALESCE(o.enabled,1) AS enabled,COALESCE(o.quantity,1) AS quantity,COALESCE(o.stat_key,'perception') AS stat_key,e.shortDesc AS resource_name,e.immagine AS resource_image,t.tipoG,e.materiaprima,e.approvato,e.visibile,e.corp AS resource_corp,m.title AS room_name FROM lavori l LEFT JOIN chat_work_options o ON o.job_id=l.id LEFT JOIN equip e ON e.id=l.res LEFT JOIN tipo t ON t.id=e.tipo LEFT JOIN mappe m ON m.chat_key=l.chat AND m.chat=1".($chat!==''?' WHERE l.chat=?':'').' ORDER BY l.chat,l.id',$chat!==''?[$chat]:[]);
}
function alphano_work_valid_resource(array $j):bool {return (int)($j['resource_corp']??0)===0&&($j['tipoG']??'')==='risorsa'&&in_array($j['materiaprima']??'',MATERIE_PRIME,true)&&!empty($j['approvato'])&&!empty($j['visibile']);}
function alphano_work_success(int $roll,int $bonus,int $difficulty):bool {return $roll===20||($roll!==1&&$roll+$bonus>=$difficulty);}
/** At most +3 by default: racial strength cannot make a harvest automatic. Natural 1 fails, natural 20 succeeds. */
function alphano_work_odds(array $job,array $stats,array $settings):array {
 $stat=$job['stat_key'];$value=(int)($stats[$stat]??10);$bonus=max(0,min((int)$settings['max_bonus'],(int)floor(($value-10)/max(1,(int)$settings['bonus_step']))));
 $base=max(1,min(19,(int)round((int)$job['prob']/5)));$dc=21-$base;$wins=0;
 for($roll=1;$roll<=20;$roll++)if($roll===20||($roll!==1&&$roll+$bonus>=$dc))$wins++;
 return ['stat_key'=>$stat,'stat_value'=>$value,'bonus'=>$bonus,'difficulty'=>$dc,'probability'=>$wins*5];
}
function alphano_work_pending(int $player):bool {return (bool)(int)(ConnectDB()->query('SELECT pending_attempt FROM chat_work_players WHERE player_id=?',[$player])[0]['pending_attempt']??0);}
function alphano_work_state(int $player,string $chat):array {
 $d=ConnectDB();$s=alphano_work_settings();$now=time();$p=$d->query('SELECT * FROM chat_work_players WHERE player_id=?',[$player])[0]??[];
 $units=(int)$d->query('SELECT COALESCE(SUM(quantity),0) n FROM chat_work_attempts WHERE player_id=? AND created_at>?',[$player,$now-$s['window_seconds']])[0]['n'];
 $last=$d->query('SELECT id,created_at,quantity,snapshot FROM chat_work_attempts WHERE player_id=? ORDER BY id DESC LIMIT 1',[$player])[0]??null;
 $until=max((int)($p['rest_until']??0),$last?(int)$last['created_at']+$s['attempt_cooldown']:0);$remaining=max(0,$s['max_units']-$units);
 if(!$remaining){$rows=$d->query('SELECT created_at,quantity FROM chat_work_attempts WHERE player_id=? AND quantity>0 AND created_at>? ORDER BY created_at,id',[$player,$now-$s['window_seconds']]);$needed=$units-$s['max_units']+1;foreach($rows as $r){$needed-=(int)$r['quantity'];$until=max($until,(int)$r['created_at']+$s['window_seconds']);if($needed<=0)break;}}
 $u=$d->query('SELECT chat,bloccato,imprigionato,puntif,razza FROM utenti WHERE id=?',[$player])[0]??[];
 $session=(bool)$d->query('SELECT id FROM sessioni WHERE chat=? AND run=1 LIMIT 1',[$chat]);
 $pending=$s['require_narration']&&!empty($p['pending_attempt']);$reason='';
 if(!$s['enabled'])$reason='Le attività di lavoro sono temporaneamente sospese.';
 elseif(($u['chat']??'')!==$chat)$reason='Raggiungi questo luogo prima di lavorare.';
 elseif(!empty($u['bloccato'])||!empty($u['imprigionato'])||(int)($u['puntif']??0)<=0||in_array((int)($u['razza']??0),[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true))$reason='Nella tua condizione attuale non puoi lavorare.';
 elseif($session)$reason='È in corso una sessione: segui la giocata. Il lavoro riprenderà dopo la sua conclusione.';
 elseif((int)($p['rest_until']??0)>$now||!$remaining)$reason='Sei stanco: hai raggiunto il limite di raccolta. Riposa fino al '.date('d/m H:i',$until).'.';
 elseif($pending)$reason='Prima di un altro lavoro, invia un’Azione ordinaria: renditi conto dell’esito e, se hai trovato una risorsa, descrivi come la raccogli e la riponi nella borsa. Non viene assegnata una seconda volta.';
 elseif($until>$now)$reason='Riprendi fiato prima del prossimo tentativo: attendi '.($until-$now).' secondi.';
 $stats=rpg_actor_stats('pg',$player);$jobs=[];
 foreach(alphano_work_catalog($chat) as $j){if(!$j['enabled']||!alphano_work_valid_resource($j))continue;$odds=alphano_work_odds($j,$stats,$s);$data=['id'=>(int)$j['id'],'action'=>$j['azione'],'resource_id'=>(int)$j['res'],'resource_name'=>$j['resource_name'],'image'=>alphano_equipment_image(rawurldecode($j['resource_image'])),'quantity'=>min((int)$j['quantity'],$remaining)]+$odds;
  $data['success_text']=$j['frase'];$data['quote']=hash('sha256',rpg_json([$j,$odds,$s,$data['quantity']]));$jobs[]=$data;
 }
 return ['server_time'=>$now,'settings'=>$s,'units'=>$units,'remaining'=>$remaining,'available_at'=>$until,'pending_narration'=>$pending,'can_work'=>$reason==='','reason'=>$reason,'jobs'=>$jobs];
}
/** Caller holds the player lock, and commits chat, inventory, receipt and narration gate together. */
function alphano_work_prepare(int $player,string $chat,int $jobId,string $text,bool $confirmed,string $quote):array {
 if(!$confirmed)throw new DomainException('Stai per compiere un’azione lavoro: selezionala dal menu e conferma probabilità ed esito possibile.',409);
 $state=alphano_work_state($player,$chat);if(!$state['can_work'])throw new DomainException($state['reason'],409);
 $job=null;foreach($state['jobs'] as $j)if($j['id']===$jobId){$job=$j;break;}
 if(!$job)throw new DomainException('Questo lavoro non è disponibile in questo luogo.',422);
 if(!hash_equals($job['quote'],$quote))throw new DomainException('Le condizioni del lavoro sono cambiate: selezionalo di nuovo e conferma i valori aggiornati.',409);
 if(!str_starts_with(ltrim($text),'+')||mb_strlen(trim(substr(ltrim($text),1)))<5)throw new DomainException('Descrivi il tentativo nel canale Azione.',422);
 return ['job'=>$job,'state'=>$state];
}
/** Small resource receipts stay distinct from narrative Master verdicts. */
function alphano_work_receipt_html(array $receipt,array $job,string $name,array $notes=[]):string {
 $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$id=(int)$receipt['id'];$qty=(int)$receipt['quantity'];$roll=(int)$receipt['roll'];$bonus=(int)$receipt['bonus'];$dc=(int)$receipt['difficulty'];$prob=(int)$receipt['probability'];$player=(int)$receipt['player_id'];
 $html='<section class="work-receipt '.($qty?'work-receipt-success':'work-receipt-empty').'" data-work-attempt="'.$id.'" aria-label="Esito della raccolta"><div class="work-receipt-main"><img class="work-receipt-material" src="'.$h($job['image']).'" width="44" height="44" alt=""><div class="work-receipt-heading"><small>Raccolta · '.$h($name).'</small><h3>'.($qty?'<b>+'.$qty.'</b> '.$h($job['resource_name']):'Nessuna risorsa trovata').'</h3><span>'.($qty?'Registrato nella borsa':$h($job['resource_name']).' · Tentativo fallito').'</span></div><div class="work-receipt-dice" data-dice-result="'.$roll.'" data-dice-receipt="work-'.$id.'"><img src="'.$h(ai_scene_effect_icon('dice')).'" width="29" height="29" alt="Dado a venti facce"><div><p><strong class="work-receipt-actor">'.$h($name).'</strong><span>d20</span> <b>'.$roll.'</b><span> + '.$bonus.'</span></p><small>Soglia '.$dc.' · '.$prob.'%</small></div></div></div>';
 foreach($notes as $note){$narration=str_starts_with($note,'Ora invia');$html.='<p class="work-receipt-note'.($narration?'':' work-receipt-rest').'">'.$h($narration?'Ora un’Azione ordinaria: racconta l’esito e riponi l’eventuale materiale nella borsa.':$note).'</p>';}
 $html.='<details class="work-receipt-details"><summary>Dettagli del tentativo</summary><p>'.$h($job['action']).'</p>';
 if($qty&&trim($job['success_text']??'')!=='')$html.='<p>'.$h(str_replace('=nome',$name,$job['success_text'])).'</p>';
 $html.='<p>d20 '.$roll.' + '.$bonus.' contro '.$dc.' · Probabilità '.$prob.'%. 1 naturale fallisce, 20 naturale riesce.</p></details>';
 if($qty){$events=[['name'=>$name,'title'=>'Esito del lavoro','kind'=>'pg','id'=>$player,'changes'=>[['key'=>'item','label'=>$job['resource_name'],'value'=>'+'.$qty.' nella borsa','delta'=>$qty,'icon'=>$job['image']]]]];$html.='<span hidden data-scene-effects="'.$h(rpg_json($events)).'"></span>';}
 return $html.'</section>';
}
function alphano_work_complete(int $player,string $chat,string $request,int $source,array $prepared):array {
 $d=ConnectDB();$j=$prepared['job'];$s=$prepared['state']['settings'];$roll=random_int(1,20);$success=alphano_work_success($roll,$j['bonus'],$j['difficulty']);$qty=$success?$j['quantity']:0;$ids=[];
 for($i=0;$i<$qty;$i++){$d->execute('INSERT INTO equippg(uid,idpg) VALUES(?,?)',[$j['resource_id'],$player]);$ids[]=(int)$d->lastInsertId();}
 $d->execute('INSERT INTO chat_work_attempts(player_id,chat_key,job_id,request_key,action_chat_id,roll,bonus,difficulty,probability,quantity,resource_id,snapshot,inventory_ids,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$player,$chat,$j['id'],$request,$source,$roll,$j['bonus'],$j['difficulty'],$j['probability'],$qty,$j['resource_id'],rpg_json($j),rpg_json($ids),time()]);$id=(int)$d->lastInsertId();
 $rest=$qty>0&&$prepared['state']['units']+$qty>=$s['max_units']?time()+$s['rest_seconds']:0;
 $d->execute('INSERT INTO chat_work_players(player_id,pending_attempt,rest_until) VALUES(?,?,?) ON DUPLICATE KEY UPDATE pending_attempt=VALUES(pending_attempt),rest_until=GREATEST(rest_until,VALUES(rest_until))',[$player,$s['require_narration']?$id:0,$rest]);
 $notes=[];if($s['require_narration'])$notes[]='Ora invia un’Azione ordinaria';
 if($rest)$notes[]='Sei stanco: limite raggiunto. Riposa almeno '.rtrim(rtrim(number_format($s['rest_seconds']/3600,2,',',''),'0'),',').' ore, fino al '.date('d/m H:i',$rest).'.';
 $html='<div class="MasterKey">'.alphano_work_receipt_html(['id'=>$id,'player_id'=>$player,'roll'=>$roll,'bonus'=>$j['bonus'],'difficulty'=>$j['difficulty'],'probability'=>$j['probability'],'quantity'=>$qty],$j,GetPGFromID($player),$notes).'</div>';
 require_once __DIR__.'/../chat/libs/chat-core.php';$message=insertHtml($chat,$html,0,0,'','0');$d->execute('UPDATE chat_work_attempts SET outcome_chat_id=? WHERE id=?',[$message,$id]);
 return ['attempt_id'=>$id,'roll'=>$roll,'quantity'=>$qty,'resource_name'=>$j['resource_name'],'outcome_id'=>$message,'state'=>alphano_work_state($player,$chat)];
}
/** Only a non-work public action counts; OOC, whispers, greetings and retrying a preset cannot unlock gathering. */
function alphano_work_narrated(int $player,string $chat,string $text,int $dest,int $source):void {
 $s=alphano_work_settings();if($dest||!str_starts_with(ltrim($text),'+'))return;$body=trim(substr(ltrim($text),1));
 if(mb_strlen(preg_replace('/\s+/u','',$body))<$s['narration_min_chars']||ConnectDB()->query('SELECT id FROM lavori WHERE chat=? AND azione=? LIMIT 1',[$chat,$body]))return;
 $pending=(int)(ConnectDB()->query('SELECT pending_attempt FROM chat_work_players WHERE player_id=?',[$player])[0]['pending_attempt']??0);
 if($pending)ConnectDB()->execute('UPDATE chat_work_attempts SET narration_chat_id=? WHERE id=? AND player_id=? AND narration_chat_id=0',[$source,$pending,$player]);
 ConnectDB()->execute('UPDATE chat_work_players SET pending_attempt=0 WHERE player_id=? AND pending_attempt>0',[$player]);
}
function alphano_work_staff(int $user):bool {return (bool)ConnectDB()->query('SELECT id FROM utenti WHERE id=? AND (admin=1 OR adminaccademia=1)',[$user]);}
