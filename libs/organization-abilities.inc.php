<?php
declare(strict_types=1);
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/activity.inc.php';
function org_abilities_ready():bool {static $v;return $v??=(bool)ConnectDB()->query("SHOW TABLES LIKE 'org_ability_proposals'");}
function org_ability_reviewer(array $u):bool {return !empty($u['admin'])||!empty($u['adminaccademia']);}
function org_ability_manager(array $u,int $org):bool {return !empty($u['admin'])||((int)($u['organizzazione']??0)===$org&&!empty($u['adminorganizzazione']));}
function org_ability_group(int $org):array {$r=ConnectDB()->query('SELECT id,nome,approvato FROM organizzazioni WHERE id=?',[$org])[0]??null;if(!$r||!$r['approvato'])throw new DomainException('Organizzazione approvata non disponibile.',422);return $r;}
function org_ability_spec(array $in,int $org,int $ability=0):array {
 $out=['name'=>rpg_text($in['name']??'',100,true),'description'=>rpg_text($in['description']??'',2000,true),'rationale'=>rpg_text($in['rationale']??'',2000,true),'image'=>rpg_ability_image($in['image']??''),'cost'=>rpg_int($in['cost']??0,1,1000000,'Costo PE'),'cooldown'=>rpg_int($in['cooldown']??0,0,100,'Recupero')];
 $duplicate=ConnectDB()->query('SELECT id FROM rpg_abilities WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND id<>? AND enabled=1 LIMIT 1',[$out['name'],$ability]);if($duplicate)throw new DomainException('Esiste già una capacità attiva con questo nome. Scegli un nome distinto.',422);
 $kind=$in['kind']??'';if(!in_array($kind,['narrative','attack','defence','mental','heal','perception'],true))throw new DomainException('Scegli un tipo di capacità supportato dal motore.',422);$out['kind']=$kind;
 $out['requirements']=rpg_validate_requirements($in['requirements']??[],$ability);$out['effect']=rpg_validate_effect($in['effect']??[],$kind);
 if(in_array($kind,['narrative','perception'],true)&&array_sum(array_intersect_key($out['effect'],array_flip(['bonus','damage','heal','duration']))))throw new DomainException('Narrativa e percezione richiedono valutazione del Master: lascia a zero gli effetti numerici automatici.',422);
 $races=$in['race_ids']??[];if(!is_array($races)||!array_is_list($races)||!$races||count($races)>20)throw new DomainException('Seleziona almeno una razza compatibile.',422);
 $out['race_ids']=array_values(array_unique(array_map(static fn($v)=>rpg_int($v,1,100000,'Razza'),$races)));sort($out['race_ids']);foreach($out['race_ids'] as $rid){$r=rpg_race($rid);if(!$r['enabled'])throw new DomainException('La razza selezionata è sospesa.',422);if(in_array($rid,[4,7,8],true)&&!in_array($kind,['narrative','perception'],true))throw new DomainException('Le forme incorporee non acquisiscono effetti fisici o mentali offensivi.',422);}
 $out['form']=$in['form']??'human';if(!in_array($out['form'],['human','native','both'],true))throw new DomainException('Forma non valida.',422);
 $out['minimum_rank']=rpg_int($in['minimum_rank']??0,0,100000,'Livello minimo della carica');if($out['minimum_rank']>0&&!ConnectDB()->query('SELECT id FROM caricheorganizzazioni WHERE organizzazione=? AND livello>=? LIMIT 1',[$org,$out['minimum_rank']]))throw new DomainException('Nessuna carica dell’organizzazione raggiunge il livello richiesto.',422);
 return $out;
}
function org_ability_event(array $p,int $actor,string $type,string $reason,array $payload=[]):void {
 ConnectDB()->execute('INSERT INTO org_ability_events(proposal_id,revision,actor_id,event_type,reason,payload,created_at) VALUES(?,?,?,?,?,?,?)',[(int)$p['id'],(int)$p['revision'],$actor,$type,$reason,rpg_json($payload),time()]);
}
function org_ability_notify(array $p,array $u,string $type,string $reason):void {
 $spec=rpg_decode($p['spec']);$audiences=[['scope'=>'organizzazioni','id'=>(int)$p['org_id'],'section'=>'abilita','role'=>'admin']];if(in_array($type,['submitted','review','approved','rejected'],true))$audiences[]=['scope'=>'accademia','id'=>0,'section'=>'abilita'];
 $labels=['submitted'=>'Abilità inviata all’Accademia','approved'=>'Abilità approvata','rejected'=>'Abilità negata','review'=>'Nuovo parere su un’abilità'];
 alphano_record_activity('organization-ability-'.$type,(int)$u['id'],$audiences,$labels[$type].': '.$spec['name'],$reason,['proposal_id'=>(int)$p['id'],'revision'=>(int)$p['revision'],'version'=>(int)$p['version']], 'orgability:'.$p['id'].':'.$p['version'].':'.$type.':'.$u['id']);
}
function org_ability_payload(array $p,array $u,string $mode='organization'):array {
 $db=ConnectDB();foreach(['id','org_id','author_id','revision','version','ability_id','reviewer_id','created_at','updated_at','submitted_at'] as $k)$p[$k]=(int)($p[$k]??0);
 $manager=org_ability_manager($u,$p['org_id']);$reviewer=org_ability_reviewer($u);if(!$manager&&!$reviewer)throw new DomainException('Non puoi consultare questa proposta.',403);
 if(!$manager&&$p['revision']===0)throw new DomainException('La bozza non è ancora stata inviata.',403);
 if(($mode==='academy'||!$manager)&&$p['status']!=='submitted'){$last=$db->query('SELECT spec FROM org_ability_revisions WHERE proposal_id=? AND revision=?',[$p['id'],$p['revision']])[0]??null;if($last)$p['spec']=$last['spec'];}
 $p['spec']=rpg_decode($p['spec']);$p['org_name']=$db->query('SELECT nome FROM organizzazioni WHERE id=?',[$p['org_id']])[0]['nome']??'Organizzazione';
 $p['can_edit']=$manager&&$p['status']!=='submitted';$p['can_delete']=$manager&&!$p['ability_id']&&in_array($p['status'],['draft','rejected'],true);$p['can_review']=$reviewer&&$p['status']==='submitted';
 $p['reviews']=$db->query('SELECT r.*,u.nome AS reviewer FROM org_ability_reviews r LEFT JOIN utenti u ON u.id=r.reviewer_id WHERE proposal_id=? ORDER BY revision DESC,updated_at,reviewer_id',[$p['id']]);
 $p['history']=$db->query('SELECT e.id,e.revision,e.event_type,e.reason,e.created_at,u.nome AS actor FROM org_ability_events e LEFT JOIN utenti u ON u.id=e.actor_id WHERE e.proposal_id=? ORDER BY e.id DESC',[$p['id']]);
 $p['revisions']=$db->query('SELECT revision,spec,submitted_at FROM org_ability_revisions WHERE proposal_id=? ORDER BY revision DESC',[$p['id']]);foreach($p['revisions'] as &$r)$r['spec']=rpg_decode($r['spec']);unset($r);return $p;
}
function org_abilities_data(array $u,string $mode,int $org=0):array {
 if(!org_abilities_ready())throw new DomainException('Il sistema delle abilità è in aggiornamento.',503);$db=ConnectDB();
 if($mode==='academy'){if(!org_ability_reviewer($u))throw new DomainException('Permessi Accademia richiesti.',403);$rows=$db->query('SELECT * FROM org_ability_proposals WHERE revision>0 ORDER BY status=\'submitted\' DESC,updated_at DESC,id DESC');}
 else{if(!$org)$org=(int)$u['organizzazione'];if(!org_ability_manager($u,$org))throw new DomainException('Permessi di gestione dell’organizzazione richiesti.',403);org_ability_group($org);$rows=$db->query('SELECT * FROM org_ability_proposals WHERE org_id=? ORDER BY updated_at DESC,id DESC',[$org]);}
 $catalog=rpg_catalog();return ['mode'=>$mode,'org_id'=>$org,'reviewer'=>org_ability_reviewer($u),'viewer_id'=>(int)$u['id'],'proposals'=>array_map(static fn($p)=>org_ability_payload($p,$u,$mode),$rows),'catalog'=>['stats'=>$catalog['stats'],'races'=>array_values(array_filter($catalog['races'],static fn($r)=>$r['enabled'])),'abilities'=>array_values(array_filter($catalog['abilities'],static fn($a)=>$a['enabled']))],'ranks'=>$org?$db->query('SELECT id,nome,livello FROM caricheorganizzazioni WHERE organizzazione=? ORDER BY livello,id',[$org]):[]];
}
function org_ability_publish(array $p,array $u):int {
 $db=ConnectDB();$db->query("SELECT setting_key FROM rpg_settings WHERE setting_key='quest_xp_enabled' FOR UPDATE");rpg_catalog(true);$spec=org_ability_spec(rpg_decode($p['spec']),(int)$p['org_id'],(int)($p['ability_id']??0));$org=org_ability_group((int)$p['org_id']);
 $key='org_'.$p['org_id'].'_'.$p['id'];$values=[$spec['name'],$spec['description'],$spec['image'],'Organizzazione: '.$org['nome'].' · Accademia, revisione '.$p['revision'],'purchasable',$spec['kind'],rpg_json($spec['race_ids']),rpg_json($spec['requirements']),$spec['cost'],$spec['cooldown'],rpg_json($spec['effect']),1,time()];
 if($p['ability_id']){$id=(int)$p['ability_id'];$old=$db->query('SELECT ability_key FROM rpg_abilities WHERE id=? FOR UPDATE',[$id])[0]??[];if(($old['ability_key']??'')!==$key)throw new DomainException('Pubblicazione incoerente: nessuna capacità modificata.',409);$db->execute('UPDATE rpg_abilities SET name=?,description=?,image=?,source=?,category=?,kind=?,race_ids=?,requirements=?,cost=?,cooldown=?,effect=?,enabled=?,updated_at=? WHERE id=?',[...$values,$id]);}
 else{$db->execute('INSERT INTO rpg_abilities(name,description,image,source,category,kind,race_ids,requirements,cost,cooldown,effect,enabled,updated_at,ability_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$values,$key]);$id=(int)$db->lastInsertId();}
 $db->execute('INSERT INTO org_ability_publications(ability_id,proposal_id,org_id,revision,spec,approved_by,approved_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),spec=VALUES(spec),approved_by=VALUES(approved_by),approved_at=VALUES(approved_at)',[$id,$p['id'],$p['org_id'],$p['revision'],rpg_json($spec),$u['id'],time()]);
 rpg_admin_audit((int)$u['id'],'organization_ability_approved',(string)$id,null,['proposal_id'=>(int)$p['id'],'revision'=>(int)$p['revision'],'spec'=>$spec]);return $id;
}
function org_ability_mutate(array $u,array $in):array {
 if(!org_abilities_ready())throw new DomainException('Sistema in aggiornamento.',503);$actor=(int)$u['id'];$request=rpg_request_key($in['request_id']??'');$payload=$in;unset($payload['request_id']);$hash=hash('sha256',rpg_json($payload));
 return rpg_atomic(function()use($actor,$request,$hash,$in){$db=ConnectDB();$u=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$actor])[0]??null;if(!$u)throw new DomainException('Sessione non disponibile.',403);
  $receipt=$db->query('SELECT payload_hash,result FROM org_ability_receipts WHERE actor_id=? AND request_key=?',[$actor,$request])[0]??null;
  if($receipt){if(!hash_equals($receipt['payload_hash'],$hash))throw new DomainException('Identificativo già usato con dati diversi.',409);$result=rpg_decode($receipt['result']);if(!empty($result['deleted']))return $result;$stored=$db->query('SELECT * FROM org_ability_proposals WHERE id=?',[$result['id']])[0]??null;if(!$stored)return ['id'=>$result['id'],'deleted'=>true];return org_ability_payload($stored,$u);}
  $action=(string)($in['action']??'');$id=rpg_int($in['id']??0,0,PHP_INT_MAX,'Proposta');$p=$id?($db->query('SELECT * FROM org_ability_proposals WHERE id=? FOR UPDATE',[$id])[0]??null):null;
  if($id&&!$p)throw new DomainException('Proposta non disponibile.',404);
  $org=$p?(int)$p['org_id']:rpg_int($in['org_id']??0,1,PHP_INT_MAX,'Organizzazione');org_ability_group($org);
  if(in_array($action,['save','submit','delete'],true)){if(!org_ability_manager($u,$org))throw new DomainException('Non gestisci questa organizzazione.',403);}
  elseif(in_array($action,['review','approve','reject'],true)){if(!org_ability_reviewer($u))throw new DomainException('Permessi Accademia richiesti.',403);}else throw new DomainException('Azione non riconosciuta.',422);
  if($p&&rpg_int($in['version']??0,1,PHP_INT_MAX,'Versione')!==(int)$p['version'])throw new DomainException('La proposta è cambiata. Ricarica prima di procedere.',409);
  if($action==='save'){
   if($p&&$p['status']==='submitted')throw new DomainException('La revisione inviata è bloccata durante la valutazione.',409);
   if(!is_array($in['spec']??null))throw new DomainException('Scheda non valida.',422);$spec=org_ability_spec($in['spec'],$org,(int)($p['ability_id']??0));
   if(!$p){$db->execute('INSERT INTO org_ability_proposals(org_id,author_id,spec,created_at,updated_at) VALUES(?,?,?,?,?)',[$org,$actor,rpg_json($spec),time(),time()]);$id=(int)$db->lastInsertId();}
   else $db->execute("UPDATE org_ability_proposals SET spec=?,status=IF(status='approved','draft',status),version=version+1,updated_at=? WHERE id=?",[rpg_json($spec),time(),$id]);
  }else{
   if(!$p)throw new DomainException('Salva prima la proposta.',422);
   if($action==='delete'){
    if($p['ability_id']||!in_array($p['status'],['draft','rejected'],true))throw new DomainException('Puoi eliminare solo bozze o proposte negate che non siano già pubblicate.',409);
    org_ability_event($p,$actor,'deleted','Proposta eliminata dal gestore.',['spec'=>rpg_decode($p['spec'])]);foreach(['org_ability_reviews','org_ability_revisions','org_ability_proposals'] as $table)$db->execute('DELETE FROM '.$table.' WHERE '.($table==='org_ability_proposals'?'id':'proposal_id').'=?',[$id]);$result=['id'=>$id,'deleted'=>true];
   }elseif($action==='submit'){
    if(!in_array($p['status'],['draft','rejected'],true))throw new DomainException('La proposta non è una bozza da inviare.',409);
    $spec=org_ability_spec(rpg_decode($p['spec']),$org,(int)($p['ability_id']??0));$revision=(int)$p['revision']+1;
    $db->execute('INSERT INTO org_ability_revisions VALUES(?,?,?,?,?)',[$id,$revision,rpg_json($spec),$actor,time()]);$db->execute("UPDATE org_ability_proposals SET status='submitted',revision=?,version=version+1,decision_reason=NULL,reviewer_id=NULL,submitted_at=?,updated_at=? WHERE id=?",[$revision,time(),time(),$id]);
   }else{
    if($p['status']!=='submitted')throw new DomainException('Questa revisione non è più in valutazione.',409);
    $reason=rpg_text($in['reason']??'',2000,true);
    if($action==='review'){$opinion=$in['opinion']??'';if(!in_array($opinion,['positive','negative','changes'],true))throw new DomainException('Scegli un parere valido.',422);$db->execute('INSERT INTO org_ability_reviews VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE opinion=VALUES(opinion),reason=VALUES(reason),updated_at=VALUES(updated_at)',[$id,$p['revision'],$actor,$opinion,$reason,time()]);$db->execute('UPDATE org_ability_proposals SET version=version+1,updated_at=? WHERE id=?',[time(),$id]);}
    else{$ability=$action==='approve'?org_ability_publish($p,$u):($p['ability_id']?:null);$db->execute('UPDATE org_ability_proposals SET status=?,ability_id=?,decision_reason=?,reviewer_id=?,version=version+1,updated_at=? WHERE id=?',[$action==='approve'?'approved':'rejected',$ability,$reason,$actor,time(),$id]);}
   }
  }
  if(empty($result['deleted'])){$p=$db->query('SELECT * FROM org_ability_proposals WHERE id=?',[$id])[0];$type=match($action){'submit'=>'submitted','approve'=>'approved','reject'=>'rejected',default=>$action};org_ability_event($p,$actor,$type,(string)($in['reason']??($action==='save'?'Scheda salvata.':'Revisione inviata.')));if(in_array($type,['submitted','review','approved','rejected'],true))org_ability_notify($p,$u,$type,(string)($in['reason']??'Una nuova revisione è pronta per la valutazione.'));$result=org_ability_payload($p,$u);}
  $db->execute('INSERT INTO org_ability_receipts VALUES(?,?,?,?,?)',[$actor,$request,$hash,rpg_json(['id'=>$id,'deleted'=>!empty($result['deleted'])]),time()]);rpg_catalog(true);return $result;
 });
}
