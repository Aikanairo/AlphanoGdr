<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/profile-art.inc.php';
/** Public-only character cards. Never serialize a user row or private NPC knowledge. */
function alphano_chat_character(string $kind,int $id):?array{
 static $cache=[];$key=$kind.':'.$id;if(array_key_exists($key,$cache))return $cache[$key];$db=ConnectDB();
 if($kind==='pg'){$u=$db->query('SELECT id,nome,sesso,razza,vampire_human_form,etnia,avatar,avatar2,allineamento,puntif,denari,corporazione,organizzazione,caricacorporazione,caricaorganizzazione FROM utenti WHERE id=?',[$id])[0]??null;if(!$u)return $cache[$key]=null;
  $p=['name'=>$u['nome'],'sex'=>$u['sesso'],'race'=>alphano_visible_race($u),'ethnicity'=>(int)$u['etnia'],'portrait'=>alphano_profile_portrait($u)['url'],'alignment'=>$u['allineamento'],'hp'=>(int)$u['puntif'],'money'=>(int)$u['denari'],'corporation'=>(int)$u['corporazione'],'organization'=>(int)$u['organizzazione'],'corp_rank'=>(int)$u['caricacorporazione'],'org_rank'=>(int)$u['caricaorganizzazione'],'role'=>'Personaggio'];
 }else{$n=$db->query('SELECT * FROM ai_npcs WHERE id=?',[$id])[0]??null;if(!$n)return $cache[$key]=null;$v=ai_npc_profile($n);$game=ai_npc_game($id);$p=['name'=>$n['name'],'sex'=>$v['sex'],'race'=>(int)$v['race_id'],'ethnicity'=>(int)$v['ethnicity_id'],'portrait'=>$v['portrait'],'alignment'=>$v['alignment'],'hp'=>(int)$v['hit_points'],'money'=>ai_npc_balance($id),'corporation'=>(int)$v['corporation_id'],'organization'=>(int)$v['organization_id'],'corp_rank'=>(int)$game['corporation_office_id'],'org_rank'=>(int)$game['organization_office_id'],'role'=>$v['occupation']?:'Personaggio non giocante'];}
 $race=$db->query('SELECT nome,forza FROM razze WHERE id=?',[$p['race']])[0]??[];$badges=[['src'=>alphano_race_icon((int)$p['race'],$p['sex']),'label'=>($race['nome']??'').' · '.$p['sex']]];
 foreach(['corporation'=>['corporazioni','corp_rank'],'organization'=>['organizzazioni','org_rank']] as $field=>[$table,$rank])if($p[$field]){$name=$db->query('SELECT nome FROM '.$table.' WHERE id=?',[$p[$field]])[0]['nome']??'';if($name!=='')$badges[]=['src'=>$p[$rank]?alphano_badge_url($table,$p[$rank],$p[$field]):'','label'=>$name];}
 $portrait=$p['portrait'];if(str_contains($portrait,'/assets/placeholder.svg'))$portrait='';
 return $cache[$key]=['key'=>$key,'kind'=>$kind,'id'=>$id,'name'=>$p['name'],'sex'=>$p['sex'],'race'=>$race['nome']??'','race_icon'=>$badges[0]['src'],'ethnicity'=>GetEtnia($p['ethnicity'])??'','portrait'=>$portrait,'portrait_default'=>alphano_profile_default_banner((int)$p['race'],(string)$p['sex']),'profile_url'=>($kind==='pg'?'/profili/?Id=':'/profili/png.php?Id=').$id,'role'=>$p['role'],'alignment'=>processAl($p['alignment']),'force'=>rpg_actor_stats($kind,$id)['strength'],'hp'=>$p['hp'],'max_hp'=>maxpuntif,'money'=>$p['money'],'badges'=>array_slice($badges,0,3)];
}
function alphano_node_inner(DOMNode $node):string{$s='';foreach($node->childNodes as $c)$s.=$node->ownerDocument->saveHTML($c);return $s;}
function alphano_chat_view(array $row):array{
 $html=$row['msg'];$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($old);$xp=new DOMXPath($doc);
 $master=$xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," MasterKey ")]')->item(0);
 if($master){$body=alphano_node_inner($master);
  if(!(int)($row['pg']??0)&&str_contains($body,'work-outcome')&&!empty($row['id'])){
   require_once __DIR__.'/chat-work.inc.php';$work=ConnectDB()->query('SELECT * FROM chat_work_attempts WHERE outcome_chat_id=?',[(int)$row['id']])[0]??null;
   if($work){$job=json_decode($work['snapshot'],true);if(is_array($job)){$notes=[];foreach($xp->query('//p[contains(concat(" ",normalize-space(@class)," ")," work-guidance ")]') as $note)$notes[]=$note->textContent;$body=alphano_work_receipt_html($work,$job,GetPGFromID((int)$work['player_id']),$notes);}}
  }
  if(!empty($row['id'])){$verdict=ai_db()->query("SELECT v.* FROM ai_quest_outbox o JOIN ai_quest_verdicts v ON o.event_key=CONCAT('verdict:',v.id,':published') WHERE o.chat_id=? AND v.status='applied'",[(int)$row['id']])[0]??null;if($verdict){require_once __DIR__.'/ai.inc.php';$body=ai_verdict_public_html($verdict);}}
  // Older saved verdicts display their committed receipts without rewriting history or reapplying effects.
  if(!str_contains($body,'Conseguenze registrate')&&!empty($row['id'])){$v=ai_db()->query("SELECT v.id FROM ai_quest_outbox o JOIN ai_quest_verdicts v ON o.event_key=CONCAT('verdict:',v.id,':published') WHERE o.chat_id=? AND v.status='applied'",[(int)$row['id']])[0]??null;if($v){$receipts=ai_verdict_receipts((int)$v['id']);if($receipts)$body.='<p class="chat-effect-receipt"><strong>Conseguenze registrate</strong>'.nl2br(htmlspecialchars(implode("\n",array_map(static fn($r)=>$r['name'].' — '.$r['text'],$receipts)),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>';}}
  return ['kind'=>'master','body'=>$body];
 }
 $typed=$xp->query('//*[@data-message-type]')->item(0);$type=$typed?$typed->getAttribute('data-message-type'):'';
 $npc=$xp->query('//*[@data-ai-job or @data-npc-id]')->item(0);$id=(int)$row['pg'];$kind='pg';
 if($npc){$job=ai_db()->query('SELECT npc_id FROM ai_jobs WHERE id=?',[(int)$npc->getAttribute('data-ai-job')])[0]??[];$id=(int)($job['npc_id']??$npc->getAttribute('data-npc-id'));if(!$id){$link=$xp->query('//a[contains(@href,"/profili/png.php")]')->item(0);if($link&&preg_match('/[?&]Id=(\d+)/',$link->getAttribute('href'),$m))$id=(int)$m[1];}$kind='npc';$content=$xp->query('//*[contains(concat(" ",@class," ")," chat-character-text ")]')->item(0);if(!$content||!$id)return ['kind'=>'system'];$body=alphano_node_inner($content);}
 else{$anchor=$xp->query('//*[contains(concat(" ",@class," ")," chat-character-name ")]')->item(0);if(!$anchor||!$id)return ['kind'=>'system'];$found=false;
  $cut=function(DOMNode $node)use(&$cut,&$found,$anchor,$doc):string{if($node->isSameNode($anchor)){$found=true;return '';}if($found)return $doc->saveHTML($node);$s='';foreach($node->childNodes as $c)$s.=$cut($c);return $s;};$body=$cut($doc->documentElement);
 }
 if(!in_array($type,['action','speech','whisper','animal','ooc'],true)){$type=$npc||$xp->query('//*[starts-with(@class,"Azione")]')->length?'action':((int)($row['dest']??0)>0?'whisper':($xp->query('//*[contains(@class,"Off")]')->length?'ooc':'speech'));}
 return ['kind'=>'character','message_type'=>$type,'actor'=>alphano_chat_character($kind,$id),'body'=>$body];
}
