<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/admin-governance.inc.php';
require_once __DIR__.'/libposta.inc.php';
require_once __DIR__.'/profile-art.inc.php';
require_once __DIR__.'/player-runtime.inc.php';
const ALPHANO_ELECTION_CANDIDACY=172800;
const ALPHANO_ELECTION_VOTING=432000;
const ALPHANO_ELECTION_RUNOFF=172800;
/** Storage uses UTC, independent of CLI/FPM timezone and daylight-saving changes. */
function alphano_elections_deadline_label(string $utc):string {return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i').' (ora italiana)';}
function alphano_elections_ready():bool {static $r;return $r??=(bool)ConnectDB()->query("SHOW TABLES LIKE 'civic_election_votes'");}
function alphano_elections_city(int $player):int {return (int)(ConnectDB()->query('SELECT a.city FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE u.id=? AND u.bloccato=0 AND u.puntif>0',[$player])[0]['city']??0);}
function alphano_elections_round(int $city):?array {if(!alphano_elections_ready())return null;return ConnectDB()->query("SELECT * FROM storicoelezioni WHERE city_id=? AND civic_state IN('candidacy','voting','runoff') ORDER BY id DESC LIMIT 1",[$city])[0]??null;}
/** One public query for all cards; never exposes ballots, vote totals or private programs. */
function alphano_elections_summaries(?int $now=null):array {
 $now??=time();$ready=alphano_elections_ready();$rounds=[];
 if($ready)foreach(ConnectDB()->query("SELECT s.id,s.city_id,s.civic_state,s.start_elezioni,s.end_elezioni,
  (SELECT COUNT(*) FROM civic_election_candidates x JOIN utenti u ON u.id=x.player_id JOIN araldiche a ON a.id=u.araldica WHERE x.round_id=s.id AND x.eligible=1 AND u.bloccato=0 AND u.puntif>0 AND a.city=s.city_id) AS candidates
  FROM storicoelezioni s JOIN (SELECT city_id,MAX(id) AS id FROM storicoelezioni WHERE civic_state IN('candidacy','voting','runoff') GROUP BY city_id) recent ON recent.id=s.id") as $r)$rounds[(int)$r['city_id']]=$r;
 $out=[];
 foreach(ConnectDB()->query('SELECT id FROM city ORDER BY id') as $c){
  $id=(int)$c['id'];$r=$rounds[$id]??null;$phase=$r['civic_state']??($ready?'waiting':'unavailable');
  $utc=$r?($r[$phase==='candidacy'?'start_elezioni':'end_elezioni']):null;
  $out[$id]=['city_id'=>$id,'phase'=>$phase,'active'=>$r!==null,'round_id'=>(int)($r['id']??0),'candidates'=>(int)($r['candidates']??0),'deadline'=>$utc?strtotime($utc.' UTC'):0,'deadline_label'=>$utc?alphano_elections_deadline_label($utc):'','server_time'=>$now,'url'=>'/citta/elezioni.php?city='.$id];
 }
 return $out;
}
function alphano_elections_candidates(array $round):array {
 $rows=ConnectDB()->query('SELECT c.player_id AS id,u.nome AS name,c.organization_id AS idorg,COALESCE(o.nome,\'\') AS organization,c.program,c.eligible,u.razza,u.alternate_form,u.sesso,u.avatar,u.avatar2,COUNT(v.voter_id) AS votes FROM civic_election_candidates c JOIN utenti u ON u.id=c.player_id LEFT JOIN organizzazioni o ON o.id=c.organization_id LEFT JOIN civic_election_votes v ON v.round_id=c.round_id AND v.ballot=? AND v.candidate_id=c.player_id WHERE c.round_id=? AND c.eligible=1 AND u.bloccato=0 AND u.puntif>0 AND EXISTS(SELECT 1 FROM araldiche a WHERE a.id=u.araldica AND a.city=?) GROUP BY c.player_id,u.nome,c.organization_id,o.nome,c.program,c.eligible,u.razza,u.alternate_form,u.sesso,u.avatar,u.avatar2 ORDER BY votes DESC,u.nome,c.player_id',[(int)$round['civic_ballot'],(int)$round['id'],(int)$round['city_id']]);
 foreach($rows as &$r){$r['id']=(int)$r['id'];$r['votes']=(int)$r['votes'];$r['idorg']=(int)$r['idorg'];}unset($r);return $rows;
}
/** The immutable event is recorded with personal mail in the enclosing city transaction. */
function alphano_elections_announce(int $city,int $round,string $key,string $title,string $body,int $now):void {
 $db=ConnectDB();if($db->query('SELECT id FROM civic_election_events WHERE event_key=?',[$key]))return;
 $db->execute('INSERT INTO civic_election_events(event_key,city_id,round_id,title,body,created_at) VALUES(?,?,?,?,?,?)',[$key,$city,$round,$title,$body,$now]);$event=(int)$db->lastInsertId();
 $link='/citta/elezioni.php?city='.$city;$html=htmlspecialchars($body,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'<br><a href="'.$link.'">Apri le elezioni e partecipa</a>';
 foreach($db->query('SELECT u.id FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE a.city=? AND u.bloccato=0',[$city]) as $u){InviaMissiva($title,$html,(int)$u['id'],0,0);$mail=(int)$db->lastInsertId();$db->execute('INSERT INTO civic_election_deliveries(event_id,player_id,mail_id) VALUES(?,?,?)',[$event,(int)$u['id'],$mail]);}
 alphano_record_activity('civic-election',0,[['scope'=>'citta','id'=>$city,'section'=>'elezioni','role'=>'member']],$title,$body,['city_id'=>$city,'round_id'=>$round,'event_id'=>$event],'civic:'.$key);
}
function alphano_elections_appoint(int $city,int $winner,int $round,int $now):void {
 $db=ConnectDB();alphano_governance_retire_mayors(0,$winner,$city,'Elezioni civiche #'.$round);
 $u=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$winner])[0]??null;if(!$u||alphano_elections_city($winner)!==$city)throw new DomainException('Il vincitore non è più eleggibile.',409);
 $before=alphano_governance_state($u);$after=$before;$after['araldica']=(int)alphano_governance_city_role($city,true)['id'];$after['sindaco']=1;
 $db->execute('UPDATE utenti SET sindaco=1,araldica=? WHERE id=?',[$after['araldica'],$winner]);alphano_governance_audit(0,$winner,$before,$after,'Proclamazione elezioni civiche #'.$round);
}
/** Caller holds the city row lock. Each phase boundary is processed exactly once. */
function alphano_elections_advance(array $city,int $now):void {
 $db=ConnectDB();$cid=(int)$city['id'];if(empty($city['elections_enabled']))return;
 for($step=0;$step<4;$step++){
  $r=alphano_elections_round($cid);if(!$r)return;
  $deadline=strtotime($r[$r['civic_state']==='candidacy'?'start_elezioni':'end_elezioni'].' UTC');if($now<$deadline)return;
  $c=alphano_elections_candidates($r);$rid=(int)$r['id'];
  if($r['civic_state']==='candidacy'&&count($c)>1){
   $db->execute("UPDATE storicoelezioni SET civic_state='voting',voting_notified=1 WHERE id=?",[$rid]);
   alphano_elections_announce($cid,$rid,'voting:'.$rid,'Si vota a '.$city['city'],'Candidature chiuse. Hai 5 giorni per scegliere chi ricoprirà la carica di '.alphano_governance_city_title($cid).'. Scadenza: '.alphano_elections_deadline_label($r['end_elezioni']).'. Il voto è riservato ai cittadini e resta segreto fino allo scrutinio.',$now);continue;
  }
  $ties=$c?array_values(array_filter($c,static fn($x)=>$x['votes']===$c[0]['votes'])):[];
  if(count($c)>1&&count($ties)>1){
   $ids=array_column($ties,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$ballot=(int)$r['civic_ballot']+1;
   $db->execute("UPDATE civic_election_candidates SET eligible=0 WHERE round_id=? AND player_id NOT IN($marks)",[$rid,...$ids]);
   $db->execute("UPDATE storicoelezioni SET civic_state='runoff',civic_ballot=?,start_elezioni=?,end_elezioni=? WHERE id=?",[$ballot,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+ALPHANO_ELECTION_RUNOFF),$rid]);$db->execute('UPDATE city SET ballottaggio=1 WHERE id=?',[$cid]);
   alphano_elections_announce($cid,$rid,'runoff:'.$rid.':'.$ballot,'Ballottaggio a '.$city['city'],'Parità fra '.implode(', ',array_column($ties,'name')).'. Si apre un nuovo voto di 48 ore fra i candidati a pari merito. Il voto precedente rimane nel registro. Nessun vincitore viene sorteggiato.',$now);return;
  }
  $winner=$c?(int)$c[0]['id']:0;
  if($winner)alphano_elections_appoint($cid,$winner,$rid,$now);
  $snapshot=array_map(static fn($x)=>array_intersect_key($x,array_flip(['id','name','votes','organization','program'])),$c);
  $db->execute("UPDATE storicoelezioni SET civic_state='closed',winner_id=?,civic_closed_at=?,consultazioni=? WHERE id=?",[$winner,$now,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$rid]);
  $db->execute('UPDATE city SET ballottaggio=0 WHERE id=?',[$cid]);
  $db->execute('DELETE e FROM elezioni e JOIN candidati c ON c.idpg=e.idcandidato WHERE c.city=?',[$cid]);$db->execute('DELETE FROM candidati WHERE city=?',[$cid]);
  $body=$winner?$c[0]['name'].' assume la carica di '.alphano_governance_city_title($cid).' alla guida di '.$city['city'].'. '.($r['civic_state']==='candidacy'?'Unica candidatura valida al termine delle 48 ore.':'Scrutinio concluso: '.$c[0]['votes'].' voti.').' Il mandato comprende cittadinanze, richieste, cariche cittadine e gestione della città dal menu Città.':'La tornata si chiude senza candidati eleggibili. Una nuova candidatura potrà aprire un nuovo ciclo.';
  alphano_elections_announce($cid,$rid,'closed:'.$rid,'Proclamazione · '.$city['city'],$body,$now);return;
 }
}
function alphano_elections_locked(int $city,callable $fn):mixed {
 // Keep governance's admin-first order; a competing treasury mutation can own
 // the city first. Retry the whole DB transaction if InnoDB resolves that cycle.
 return EconomiaAtomicaResiliente(static function()use($city,$fn){$db=ConnectDB();$db->query('SELECT id FROM utenti WHERE admin=1 ORDER BY id FOR UPDATE');$row=$db->query('SELECT * FROM city WHERE id=? FOR UPDATE',[$city])[0]??null;if(!$row)throw new DomainException('Città non disponibile.',404);if(empty($row['elections_enabled']))throw new DomainException('Elezioni disabilitate per questa città.',403);return $fn($row);});
}
function alphano_elections_candidate(int $city,int $player,string $program,?int $now=null,int $expectedRound=0):array {
 if(!alphano_elections_ready())throw new DomainException('Sistema elettorale in preparazione.',503);$now??=time();$program=trim($program);if($program===''||mb_strlen($program)>10000)throw new DomainException('Scrivi un programma da 1 a 10000 caratteri.',422);
 return alphano_elections_locked($city,static function($c)use($city,$player,$program,$now,$expectedRound){$db=ConnectDB();alphano_elections_advance($c,$now);
  if(alphano_elections_city($player)!==$city)throw new DomainException('Solo un cittadino vivo e non bloccato può candidarsi nella propria città.',403);
  $r=alphano_elections_round($city);
  if($expectedRound>0&&(!$r||(int)$r['id']!==$expectedRound))throw new DomainException('La tornata è cambiata. Rileggi il registro prima di confermare.',409);
  if(!$r){$db->execute("INSERT INTO storicoelezioni(city_id,tipologia,start_candidature,start_elezioni,end_elezioni,civic_state) VALUES(?,'civiche',?,?,?,'candidacy')",[$city,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+ALPHANO_ELECTION_CANDIDACY),gmdate('Y-m-d H:i:s',$now+ALPHANO_ELECTION_CANDIDACY+ALPHANO_ELECTION_VOTING)]);$r=alphano_elections_round($city);}
  if($r['civic_state']!=='candidacy')throw new DomainException('Le candidature di questa tornata sono chiuse.',409);
  $rid=(int)$r['id'];$org=(int)($db->query('SELECT organizzazione FROM utenti WHERE id=? FOR UPDATE',[$player])[0]['organizzazione']??0);
  $db->execute('INSERT INTO civic_election_candidates(round_id,player_id,organization_id,program,created_at,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE program=VALUES(program),updated_at=VALUES(updated_at)',[$rid,$player,$org,$program,$now,$now]);
  $db->execute('INSERT INTO candidati(idpg,organizzazione,city,programma) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE organizzazione=VALUES(organizzazione),city=VALUES(city),programma=VALUES(programma)',[$player,$org,$city,$program]);
  alphano_elections_announce($city,$rid,'opened:'.$rid,'Candidature aperte · '.$c['city'],'La prima candidatura ha aperto le elezioni. Hai 48 ore per candidarti, fino al '.alphano_elections_deadline_label($r['start_elezioni']).'. Con un solo candidato eleggibile verrà proclamato; con più candidati seguiranno 5 giorni di voto.',$now);
  return ['round_id'=>$rid,'message'=>'Candidatura registrata. Il programma è modificabile fino alla scadenza.'];
 });
}
function alphano_elections_vote(int $city,int $player,int $candidate,?int $now=null,int $expectedRound=0,int $expectedBallot=0):array {
 $now??=time();return alphano_elections_locked($city,static function($c)use($city,$player,$candidate,$now,$expectedRound,$expectedBallot){$db=ConnectDB();alphano_elections_advance($c,$now);$r=alphano_elections_round($city);
  if(alphano_elections_city($player)!==$city)throw new DomainException('Solo i cittadini possono votare nella propria città.',403);
  if(!$r||!in_array($r['civic_state'],['voting','runoff'],true))throw new DomainException('Votazioni chiuse.',409);
  if(($expectedRound>0&&(int)$r['id']!==$expectedRound)||($expectedBallot>0&&(int)$r['civic_ballot']!==$expectedBallot))throw new DomainException('Il turno di voto è cambiato. Rileggi il registro prima di votare.',409);
  $valid=array_column(alphano_elections_candidates($r),'id');if(!in_array($candidate,$valid,true))throw new DomainException('Candidato non eleggibile.',422);
  $old=$db->query('SELECT candidate_id FROM civic_election_votes WHERE round_id=? AND ballot=? AND voter_id=?',[(int)$r['id'],(int)$r['civic_ballot'],$player])[0]??null;
  if($old){if((int)$old['candidate_id']!==$candidate)throw new DomainException('Hai già espresso il voto in questo turno.',409);return ['message'=>'Il tuo voto era già stato registrato.','replayed'=>true];}
  $db->execute('INSERT INTO civic_election_votes(round_id,ballot,voter_id,candidate_id,created_at) VALUES(?,?,?,?,?)',[(int)$r['id'],(int)$r['civic_ballot'],$player,$candidate,$now]);return ['message'=>'Voto registrato. La scelta rimane riservata.','replayed'=>false];
 });
}
function alphano_elections_state(int $city,int $viewer=0,?int $now=null):array {
 $now??=time();$db=ConnectDB();$c=$db->query('SELECT id,city,elections_enabled FROM city WHERE id=?',[$city])[0]??null;if(!$c)throw new DomainException('Città non disponibile.',404);
 if(!alphano_elections_ready()||empty($c['elections_enabled']))return ['city_id'=>$city,'city'=>$c['city'],'office_title'=>alphano_governance_city_title($city),'phase'=>'unavailable','round_id'=>0,'ballot'=>1,'server_time'=>$now,'deadline'=>0,'candidates'=>[],'is_citizen'=>false,'can_candidate'=>false,'can_vote'=>false,'voted'=>false,'own_program'=>'','last_result'=>null,'url'=>'/citta/elezioni.php?city='.$city];
 $r=alphano_elections_round($city);$citizen=$viewer>0&&alphano_elections_city($viewer)===$city;$candidates=$r?alphano_elections_candidates($r):[];
 $voted=$r&&$citizen&&(bool)$db->query('SELECT 1 FROM civic_election_votes WHERE round_id=? AND ballot=? AND voter_id=?',[(int)$r['id'],(int)$r['civic_ballot'],$viewer]);
 $own='';foreach($candidates as &$entry){if($entry['id']===$viewer)$own=$entry['program'];$entry['portrait']=alphano_profile_portrait($entry)['url'];$entry=array_intersect_key($entry,array_flip(['id','name','organization','program','portrait']));}unset($entry);
 $last=$db->query("SELECT s.id,s.winner_id,s.civic_closed_at,s.consultazioni,u.nome AS winner_name FROM storicoelezioni s LEFT JOIN utenti u ON u.id=s.winner_id WHERE s.city_id=? AND s.civic_state='closed' ORDER BY s.id DESC LIMIT 1",[$city])[0]??null;
 return ['city_id'=>$city,'city'=>$c['city'],'office_title'=>alphano_governance_city_title($city),'phase'=>$r['civic_state']??'waiting','round_id'=>(int)($r['id']??0),'ballot'=>(int)($r['civic_ballot']??1),'server_time'=>$now,'deadline'=>$r?strtotime($r[$r['civic_state']==='candidacy'?'start_elezioni':'end_elezioni'].' UTC'):0,'candidates'=>$candidates,'is_citizen'=>$citizen,'can_candidate'=>$citizen&&(!$r||$r['civic_state']==='candidacy'),'can_vote'=>$citizen&&$r&&in_array($r['civic_state'],['voting','runoff'],true)&&!$voted,'voted'=>$voted,'own_program'=>$own,'last_result'=>$last,'url'=>'/citta/elezioni.php?city='.$city];
}
/** Call autonomously every minute; no visitor is required for transitions or delivery. */
function alphano_elections_tick(?int $now=null,bool $deliver=true):array {
 if(!alphano_elections_ready())return ['processed'=>0];$now??=time();$count=0;
 foreach(ConnectDB()->query("SELECT DISTINCT s.city_id FROM storicoelezioni s JOIN city c ON c.id=s.city_id WHERE c.elections_enabled=1 AND s.civic_state IN('candidacy','voting','runoff')") as $row){alphano_elections_locked((int)$row['city_id'],static function($c)use($now){alphano_elections_advance($c,$now);});$count++;}
 return ['processed'=>$count,'email'=>$deliver?alphano_elections_deliver(25,$now):0];
}
/** SMTP outbox is retryable; local spool is used in isolated tests. */
function alphano_elections_deliver(int $limit=25,?int $now=null,int $budget=20):int {
 require_once __DIR__.'/email-notifications.inc.php';$db=ConnectDB();
 foreach($db->query("SELECT d.event_id,d.player_id,e.created_at,e.city_id FROM civic_election_deliveries d JOIN civic_election_events e ON e.id=d.event_id WHERE d.email_status IN('pending','retry','sending') ORDER BY d.event_id LIMIT ".max(1,min(100,$limit))) as $row){
  alphano_email_enqueue((int)$row['player_id'],'civic:'.$row['event_id'],'civic','Aggiornamento delle elezioni cittadine','/citta/elezioni.php?city='.$row['city_id'],(int)$row['created_at']);
  $db->execute("UPDATE civic_election_deliveries SET email_status='queued',email_error='' WHERE event_id=? AND player_id=?",[$row['event_id'],$row['player_id']]);
 }
 return alphano_email_deliver($limit,$budget)['sent'];
}
/** First-login prompt links to the form; it never creates a candidature. */
function alphano_elections_invitation(int $viewer):?array {
 if(!alphano_elections_ready())return null;$db=ConnectDB();$city=alphano_elections_city($viewer);if(!$city||empty($db->query('SELECT elections_enabled FROM city WHERE id=?',[$city])[0]['elections_enabled']))return null;
 if($db->query('SELECT 1 FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE a.city=? AND u.sindaco=1 AND u.bloccato=0',[$city]))return null;
 if($db->query('SELECT 1 FROM civic_election_prompt_receipts WHERE player_id=? AND city_id=?',[$viewer,$city]))return null;
 $state=alphano_elections_state($city,$viewer);return ['city_id'=>$city,'city'=>$state['city'],'title'=>'La tua città cerca una guida','message'=>'Puoi candidarti per la carica di '.$state['office_title'].' a '.$state['city'].'. Apri le candidature per leggere i programmi o presentare il tuo. La candidatura è sempre una scelta esplicita.','url'=>$state['url'],'phase'=>$state['phase']];
}
function alphano_elections_acknowledge(int $viewer,int $city):void {if(alphano_elections_city($viewer)!==$city)throw new DomainException('Invito non disponibile.',403);ConnectDB()->execute('INSERT INTO civic_election_prompt_receipts(player_id,city_id,seen_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at)',[$viewer,$city,time()]);}
function alphano_registration_citizenship(int $city):int {
 $db=ConnectDB();if($city===0){$r=$db->query("SELECT id FROM araldiche WHERE city=0 AND LOWER(nome)='viandante' ORDER BY id LIMIT 1")[0]??null;if(!$r)throw new DomainException('Ingresso Viandante in preparazione. Riprova tra poco.',503);return (int)$r['id'];}
 return (int)alphano_governance_city_role($city,false)['id'];
}
