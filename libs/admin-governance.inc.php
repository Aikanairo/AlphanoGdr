<?php
declare(strict_types=1);
require_once __DIR__.'/libeconomia.inc.php';

/** Existing user flags are the source of truth; this is not a second role system. */
function alphano_governance_flags():array {
 return ['admin'=>['Amministratore generale','Sistema'],'fatepower'=>['Master di gioco','Gioco'],'adminferite'=>['Guaritore e gestione ferite','Gioco'],'adminmorti'=>['Resurrettore e gestione anime','Gioco'],'adminstatosociale'=>['Stato sociale e cerimonie','Gioco'],'adminaccademia'=>['Accademia e approvazioni','Sistema'],'adminregole'=>['Regole (flag esistente)','Sistema'],'adminorganizzazione'=>['Amministrazione organizzazione','Gruppi'],'admincorporazione'=>['Amministrazione corporazione','Gruppi'],'admincatalogo'=>['Catalogo corporativo','Gruppi'],'admintrasfcorp'=>['Scambi e consegne corporativi','Gruppi'],'sindaco'=>['Sindaco / amministratore cittadino','Città'],'upload'=>['Caricamento file','Account'],'vacanza'=>['Vacanza','Account'],'bloccato'=>['Account bloccato','Account']];
}
function alphano_governance_fields():array {return [...array_keys(alphano_governance_flags()),'organizzazione','caricaorganizzazione','ramoorganizzazione','corporazione','caricacorporazione','ramocorporazione','araldica'];}
function alphano_governance_state(array $u):array {$state=[];foreach(alphano_governance_fields() as $k)$state[$k]=(int)($u[$k]??0);return $state;}
function alphano_governance_revision(array $u):string {return hash('sha256',json_encode(alphano_governance_state($u),JSON_THROW_ON_ERROR));}
function alphano_governance_audit(int $actor,int $target,array $before,array $after,string $reason,bool $announce=true):void {
 $delta=[];foreach($after as $key=>$value)if(($before[$key]??null)!==$value)$delta[$key]=['before'=>$before[$key]??null,'after'=>$value];
 if(!$delta)return;
 ConnectDB()->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,descr,chat) VALUES(?,?,?,'incarichi',?,?)",[time(),$actor,$target,mb_substr($reason,0,255),json_encode(['reason'=>$reason,'changes'=>$delta],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
 if($announce)alphano_record_activity('governance-updated',$actor,[['scope'=>'personale','id'=>$target,'section'=>'profilo','role'=>'member'],['scope'=>'gestione','id'=>0,'section'=>'utenti','role'=>'admin']],'Incarichi aggiornati · '.GetPGFromID($target),'Appartenenze, cariche o deleghe del personaggio sono state aggiornate. I nuovi poteri sono effettivi nei rispettivi ambiti.',['player_id'=>$target,'fields'=>array_keys($delta)]);
}
function alphano_governance_city_role(int $city,bool $top):array {
 if(!ConnectDB()->query('SELECT id FROM city WHERE id=?',[$city]))throw new DomainException('Città non disponibile.',422);
 $role=ConnectDB()->query('SELECT id,city FROM araldiche WHERE city=?'.($top?' ORDER BY livello DESC,id':' AND prima=1 ORDER BY livello,id').' LIMIT 1',[$city])[0]??null;
 if(!$role)throw new DomainException('La città non ha una carica '.($top?'apicale':'iniziale').' configurata.',422);return $role;
}
/** Public office titles follow the same top-rank ordering as appointments. */
function alphano_governance_city_titles():array {
 $titles=[];
 foreach(ConnectDB()->query("SELECT c.id,COALESCE((SELECT a.nome FROM araldiche a WHERE a.city=c.id ORDER BY a.livello DESC,a.id LIMIT 1),'Governante') AS title FROM city c") as $row){
  $title=trim((string)$row['title'])?:'Governante';$titles[(int)$row['id']]=mb_strtoupper(mb_substr($title,0,1)).mb_substr($title,1);
 }
 return $titles;
}
function alphano_governance_city_title(int $city):string {return alphano_governance_city_titles()[$city]??'Governante';}
/** A mayor may admit citizens only into the city of the current, locked mandate. */
function alphano_governance_city_citizenship(int $actor,int $target,int $city,string $decision,string $reason=''):array {
 if(!in_array($decision,['accept','reject','grant'],true)||$actor<1||$target<1||$city<1)throw new DomainException('Operazione di cittadinanza non valida.',422);
 $reason=trim($reason);
 if(mb_strlen($reason)>500||($decision==='grant'&&$reason===''))throw new DomainException('Indica un motivo tra 1 e 500 caratteri.',422);
 return EconomiaAtomica(static function()use($actor,$target,$city,$decision,$reason):array{
  $db=ConnectDB();
  // Same global-first lock order as the general governance editor; read permissions again inside the transaction.
  $db->query('SELECT id FROM utenti WHERE admin=1 ORDER BY id FOR UPDATE');
  $people=$db->query('SELECT * FROM utenti WHERE id IN (?,?) ORDER BY id FOR UPDATE',[$actor,$target]);
  $acting=null;$person=null;foreach($people as $p){if((int)$p['id']===$actor)$acting=$p;if((int)$p['id']===$target)$person=$p;}
  $actorCity=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[(int)($acting['araldica']??0)])[0]['city']??0);
  if(!$acting||!empty($acting['bloccato'])||(empty($acting['admin'])&&(empty($acting['sindaco'])||$actorCity!==$city)))throw new DomainException('Mandato non valido per questa città.',403);
  if(!$person)throw new DomainException('Personaggio non trovato.',404);
  $cityRow=$db->query('SELECT id,city FROM city WHERE id=? FOR UPDATE',[$city])[0]??null;
  if(!$cityRow)throw new DomainException('Città non disponibile.',422);
  $requests=$db->query('SELECT idpg,data FROM richieste WHERE city=? AND idpg=? FOR UPDATE',[$city,$target]);
  if($decision!=='grant'&&!$requests)throw new DomainException('La richiesta è già stata esaminata o non è più disponibile.',409);
  // Citizenship grants never revoke a mayor or replace their office, including through a stale application.
  if($decision!=='reject'&&!empty($person['sindaco']))throw new DomainException('Il personaggio ha un mandato cittadino in corso. Usa la gestione degli incarichi per cambiarlo.',409);
  $before=alphano_governance_state($person);$after=$before;$changed=false;
  if($decision!=='reject'){
   $targetCity=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[(int)$person['araldica']])[0]['city']??0);
   if($targetCity!==$city){
    $entry=$db->query('SELECT id FROM araldiche WHERE city=? AND prima=1 ORDER BY livello,id LIMIT 1 FOR UPDATE',[$city])[0]??null;
    if(!$entry)throw new DomainException('La città non ha una carica iniziale configurata.',422);
    $after['araldica']=(int)$entry['id'];$db->execute('UPDATE utenti SET araldica=? WHERE id=?',[$after['araldica'],$target]);$changed=true;
   }
  }
  if(!$changed&&!$requests)return ['changed'=>false,'resolved'=>false,'message'=>'Il personaggio possiede già questa cittadinanza. La sua carica è stata conservata.'];
  if($decision==='reject')$db->execute('DELETE FROM richieste WHERE city=? AND idpg=?',[$city,$target]);
  else $db->execute('DELETE FROM richieste WHERE idpg=? AND city>0',[$target]);
  $description=$decision==='reject'?'Richiesta di cittadinanza respinta':'Cittadinanza concessa';
  $detail=$description.' · '.$person['nome'].' · '.$cityRow['city'];
  if($reason!=='')$detail.='. '.$reason;
  if($changed)alphano_governance_audit($actor,$target,$before,$after,$detail,false);
  $receipt=['city'=>$city,'decision'=>$decision,'previous_role'=>$before['araldica'],'current_role'=>$after['araldica'],'reason'=>$reason,'request_time'=>$requests?(int)$requests[0]['data']:null];
  $db->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,descr,chat) VALUES(?,?,?,'cittadinanza',?,?)",[time(),$actor,$target,mb_substr($detail,0,255),json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  alphano_record_activity('city_citizenship_'.$decision,$actor,[['scope'=>'citta','id'=>$city,'section'=>'richieste-cittadinanza','role'=>'admin'],['scope'=>'personale','id'=>$target,'section'=>'cittadinanza','role'=>'member']],$description,$detail,$receipt+['player_id'=>$target]);
  return ['changed'=>$changed,'resolved'=>(bool)$requests,'message'=>$decision==='reject'?'Richiesta respinta. Il richiedente ha ricevuto una notifica.':($changed?'Cittadinanza concessa con la carica di ingresso. Il personaggio ha ricevuto una notifica.':'Richiesta accettata. Il personaggio è già cittadino: la sua carica è stata conservata e ha ricevuto una notifica.')];
 });
}
/** Caller validates the nomination and owns the enclosing transaction. Shared with election results. */
function alphano_governance_retire_mayors(int $actor,int $target,int $city,string $reason):void {
 $db=ConnectDB();$entry=(int)alphano_governance_city_role($city,false)['id'];
 foreach($db->query('SELECT u.* FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE a.city=? AND u.sindaco=1 AND u.id<>? FOR UPDATE',[$city,$target]) as $previous){$prior=alphano_governance_state($previous);$new=$prior;$new['sindaco']=0;$new['araldica']=$entry;$db->execute('UPDATE utenti SET sindaco=0,araldica=? WHERE id=?',[$entry,$previous['id']]);alphano_governance_audit($actor,(int)$previous['id'],$prior,$new,'Avvicendamento cittadino: '.$reason);}
}
/** Reused by the global editor, legacy flags and scoped group/city administration. */
function alphano_governance_apply(int $actor,int $target,array $changes,string $reason,?string $revision=null,?string $scope=null,int $scopeId=0):array {
 if(trim($reason)===''||mb_strlen($reason)>500)throw new DomainException('Indica un motivo tra 1 e 500 caratteri.',422);
 $editable=[...array_diff(alphano_governance_fields(),['ramoorganizzazione','ramocorporazione']),'appoint_city'];
 foreach($changes as $k=>$v)if(!in_array($k,$editable,true)||is_array($v)||is_object($v)||filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<0||(isset(alphano_governance_flags()[$k])&&(int)$v>1))throw new DomainException('Incarico o permesso non valido: '.$k,422);
 return EconomiaAtomica(static function()use($actor,$target,$changes,$reason,$revision,$scope,$scopeId):array{
  $db=ConnectDB();
  // Serialize global grants/revocations so two administrators cannot remove the last one concurrently.
  $admins=$db->query("SELECT id,bloccato FROM utenti WHERE admin=1 ORDER BY id FOR UPDATE");
  $acting=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$actor])[0]??null;
  if(!$acting||!empty($acting['bloccato']))throw new DomainException('Mandato amministrativo non valido.',403);
  $u=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$target])[0]??null;if(!$u)throw new DomainException('Personaggio non trovato.',404);
  if(empty($acting['admin'])){
   $allowed=match($scope){'organizzazioni'=>['caricaorganizzazione','adminorganizzazione'],'corporazioni'=>['caricacorporazione','admincorporazione','admincatalogo','admintrasfcorp'],'citta'=>['araldica'],default=>[]};
   if($scope==='organizzazioni'){$g=$db->query('SELECT resurgo,cerimonia,ferite FROM organizzazioni WHERE id=?',[$scopeId])[0]??[];foreach(['resurgo'=>'adminmorti','cerimonia'=>'adminstatosociale','ferite'=>'adminferite'] as $cap=>$flag)if(!empty($g[$cap]))$allowed[]=$flag;}
   $field=$scope==='corporazioni'?'corporazione':'organizzazione';$valid=$scope==='citta'?(!empty($acting['sindaco'])&&GetPGCity($actor)===$scopeId&&GetPGCity($target)===$scopeId):($scopeId>0&&(int)$acting[$field]===$scopeId&&!empty($acting['admin'.$field])&&(int)$u[$field]===$scopeId);
   if(!$valid||array_diff(array_keys($changes),$allowed))throw new DomainException('Puoi gestire soltanto i poteri delegati nel tuo ambito.',403);
   if($scope==='citta'&&((int)$u['sindaco']===1||!$db->query('SELECT id FROM araldiche WHERE id=? AND city=?',[$changes['araldica']??0,$scopeId])))throw new DomainException('Non puoi cambiare il mandato del sindaco o assegnare cariche di altre città.',403);
  }
  if($revision!==null&&!hash_equals(alphano_governance_revision($u),$revision))throw new DomainException('Gli incarichi sono cambiati. Ricarica il personaggio prima di salvare.',409);
  $before=alphano_governance_state($u);$next=$before;
  foreach($changes as $k=>$v)if($k!=='appoint_city')$next[$k]=(int)$v;
  foreach(['organizzazione'=>'organizzazioni','corporazione'=>'corporazioni'] as $field=>$table){
   $rank='carica'.$field;$branch='ramo'.$field;$admin='admin'.$field;$group=$next[$field];
   if($group!==$before[$field]){foreach($field==='corporazione'?[$admin,'admincatalogo','admintrasfcorp']:[$admin] as $flag)if(!array_key_exists($flag,$changes))$next[$flag]=0;if(!array_key_exists($rank,$changes))$next[$rank]=0;}
   if(!$group){$next[$rank]=0;$next[$branch]=0;foreach($field==='corporazione'?[$admin,'admincatalogo','admintrasfcorp']:[$admin] as $flag){if(!empty($changes[$flag]))throw new DomainException('Seleziona prima una '.$field.' per concederne i poteri.',422);$next[$flag]=0;}}
   else {
    if(!$db->query("SELECT id FROM {$table} WHERE id=?",[$group]))throw new DomainException('Gruppo non disponibile.',422);
    if(!$next[$rank]&&array_key_exists($rank,$changes))throw new DomainException('Seleziona una carica valida per la '.$field.'.',422);
    if(!$next[$rank])$next[$rank]=(int)($db->query("SELECT id FROM cariche{$table} WHERE {$field}=? AND prima=1 ORDER BY id LIMIT 1",[$group])[0]['id']??0);
    $role=$db->query("SELECT id,ramo FROM cariche{$table} WHERE id=? AND {$field}=?",[$next[$rank],$group])[0]??null;
    if(!$role)throw new DomainException('La carica non appartiene alla '.$field.' selezionata.',422);$next[$branch]=(int)$role['ramo'];
   }
  }
  $citizenRole=$db->query('SELECT city,nome FROM araldiche WHERE id=?',[$next['araldica']])[0]??null;
  $city=(int)($citizenRole['city']??0);
  if($next['araldica']&&(!$citizenRole||(!$city&&mb_strtolower($citizenRole['nome'])!=='viandante')))throw new DomainException('Carica cittadina inesistente.',422);
  $appoint=(int)($changes['appoint_city']??0);
  if($appoint){if(empty($acting['admin']))throw new DomainException('Nomina riservata all’amministratore generale.',403);$city=$appoint;$next['araldica']=(int)alphano_governance_city_role($city,true)['id'];$next['sindaco']=1;}
  elseif(array_key_exists('araldica',$changes)&&$before['araldica']!==$next['araldica']&&!array_key_exists('sindaco',$changes))$next['sindaco']=0;
  if($before['sindaco']&&!$next['sindaco']&&$city>0&&$before['araldica']===$next['araldica'])$next['araldica']=(int)alphano_governance_city_role($city,false)['id'];
  if($next['sindaco']){
   if(!$city)throw new DomainException('Assegna una cittadinanza prima della nomina.',422);
   if(array_key_exists('sindaco',$changes)&&$next['sindaco']!==$before['sindaco'])$next['araldica']=(int)alphano_governance_city_role($city,true)['id'];
   if((int)alphano_governance_city_role($city,true)['id']!==$next['araldica'])throw new DomainException('Il sindaco deve ricoprire la carica apicale della città. Usa Nomina amministratore.',422);
   if($appoint||(!$before['sindaco']&&$next['sindaco'])){
    alphano_governance_retire_mayors($actor,$target,$city,$reason);
   }
  }
  if($before['admin']&&!$before['bloccato']&&(!$next['admin']||$next['bloccato'])){if(!array_filter($admins,static fn($a)=>(int)$a['id']!==$target&&empty($a['bloccato'])))throw new DomainException('Deve rimanere almeno un amministratore generale non bloccato.',409);}
  $delta=array_filter($next,static fn($v,$k)=>$v!==$before[$k],ARRAY_FILTER_USE_BOTH);
  if($delta){$sets=implode(',',array_map(static fn($k)=>$k.'=?',array_keys($delta)));$db->execute('UPDATE utenti SET '.$sets.' WHERE id=?',[...array_values($delta),$target]);alphano_governance_audit($actor,$target,$before,$next,$reason,$scope===null);
   if($next['corporazione']!==$before['corporazione']||($before['admincorporazione']&&!$next['admincorporazione'])||($before['admintrasfcorp']&&!$next['admintrasfcorp'])||($before['admin']&&!$next['admin'])){
    $corps=array_column($db->query('SELECT DISTINCT corp FROM trasferimenti WHERE pgcorp=? AND stato BETWEEN 0 AND 5',[$target]),'corp');if($before['corporazione'])$corps[]=$before['corporazione'];foreach(array_unique($corps) as $corp)ClearTrasferimenti((int)$corp);
   }
  }
  return ['id'=>$target,'name'=>$u['nome'],'values'=>$next,'revision'=>alphano_governance_revision($next),'changed'=>count($delta)];
 });
}
function alphano_governance_history(int $target=0):array {
 $rows=ConnectDB()->query("SELECT l.data,l.esecutore,l.destinatario,l.descr,l.chat,a.nome AS actor,t.nome AS target FROM logs_adminpg l LEFT JOIN utenti a ON a.id=l.esecutore LEFT JOIN utenti t ON t.id=l.destinatario WHERE l.azione='incarichi'".($target?' AND l.destinatario=?':'').' ORDER BY l.data DESC LIMIT 50',$target?[$target]:[]);
 foreach($rows as &$r){$r['detail']=json_decode($r['chat'],true);unset($r['chat']);}unset($r);return $rows;
}
function alphano_governance_catalog():array {
 $db=ConnectDB();$result=['flags'=>alphano_governance_flags(),'players'=>$db->query('SELECT id,nome FROM utenti ORDER BY nome LIMIT 2000'),'cities'=>$db->query('SELECT id,city FROM city ORDER BY city'),'city_roles'=>$db->query('SELECT id,nome,city,livello,prima FROM araldiche ORDER BY city,livello DESC,id')];
 array_unshift($result['cities'],['id'=>0,'city'=>'Nessuna cittadinanza · Viandante']);
 foreach(['organizzazioni'=>'organizzazione','corporazioni'=>'corporazione'] as $table=>$field){$result[$table]=$db->query("SELECT id,nome FROM {$table} ORDER BY nome");$result[$table.'_roles']=$db->query("SELECT id,nome,{$field} AS group_id,ramo,livello,prima FROM cariche{$table} ORDER BY {$field},livello,id");}
 $result['issues']=alphano_governance_issues();
 return $result;
}

function alphano_governance_issues():array {
 return ConnectDB()->query("SELECT u.id,u.nome FROM utenti u LEFT JOIN caricheorganizzazioni o ON o.id=u.caricaorganizzazione AND o.organizzazione=u.organizzazione LEFT JOIN carichecorporazioni p ON p.id=u.caricacorporazione AND p.corporazione=u.corporazione LEFT JOIN araldiche a ON a.id=u.araldica WHERE (u.organizzazione>0 AND (o.id IS NULL OR o.ramo<>u.ramoorganizzazione)) OR (COALESCE(u.organizzazione,0)=0 AND COALESCE(u.caricaorganizzazione,0)<>0) OR (u.corporazione>0 AND (p.id IS NULL OR p.ramo<>u.ramocorporazione)) OR (COALESCE(u.corporazione,0)=0 AND COALESCE(u.caricacorporazione,0)<>0) OR (u.araldica>0 AND a.id IS NULL) OR (u.adminorganizzazione=1 AND COALESCE(u.organizzazione,0)=0) OR ((u.admincorporazione=1 OR u.admincatalogo=1 OR u.admintrasfcorp=1) AND COALESCE(u.corporazione,0)=0) OR (u.sindaco=1 AND (a.id IS NULL OR a.livello<(SELECT MAX(x.livello) FROM araldiche x WHERE x.city=a.city))) ORDER BY u.nome LIMIT 100");
}
