<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';

/** Immutable receipts. Audiences retain current role checks when read. */
function alphano_record_activity(string $type,int $actor,array $audiences,string $title,string $detail,array $data=[],?string $eventKey=null): int
{
    if(!preg_match('/^[a-zA-Z0-9:_-]{1,64}$/D',$type)||$actor<0||!$audiences||count($audiences)>30)throw new InvalidArgumentException('Evento non valido');
    $scopes=['organizzazioni','corporazioni','citta','accademia','personale','master','gestione'];
    $roles=['member','admin','trade','catalogue'];
    foreach($audiences as $a){
        if(!is_array($a)||!in_array($a['scope']??'',$scopes,true)||!in_array($a['role']??'member',$roles,true)
            ||!is_int($a['id']??null)||$a['id']<0||!preg_match('/^[a-z0-9_-]{1,64}$/D',$a['section']??''))throw new InvalidArgumentException('Destinazione evento non valida');
    }
    $eventKey??=bin2hex(random_bytes(24));
    if(strlen($eventKey)>190||!preg_match('/^[a-zA-Z0-9:_-]+$/D',$eventKey))throw new InvalidArgumentException('Chiave evento non valida');
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR,20);
    if(strlen($json)>65535)throw new InvalidArgumentException('Dettagli evento troppo lunghi');
    $db=ConnectDB();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        $actorName=$actor?($db->query('SELECT nome FROM utenti WHERE id=?',[$actor])[0]['nome']??'Personaggio'):'Sistema';
        $created=$db->execute('INSERT INTO activity_events(event_key,event_type,actor_id,actor_name,title,detail,data_json,created_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)',
            [$eventKey,$type,$actor,$actorName,mb_substr($title,0,255),mb_substr($detail,0,10000),$json,time()]);
        $stored=$db->query('SELECT * FROM activity_events WHERE event_key=? FOR UPDATE',[$eventKey])[0];
        $event=(int)$stored['id'];
        if(!$created){
            $existing=$db->query('SELECT audience_scope AS scope,scope_id AS id,section_key AS section,access_role AS role FROM activity_event_audiences WHERE event_id=? FOR UPDATE',[$event]);
            $normalize=static function(array $list):array{
                $out=[];foreach($list as $a)$out[]=[$a['scope'],(int)$a['id'],$a['section'],$a['role']??'member'];sort($out);return array_values(array_unique($out,SORT_REGULAR));
            };
            if($stored['event_type']!==$type||(int)$stored['actor_id']!==$actor||$stored['title']!==mb_substr($title,0,255)||$stored['detail']!==mb_substr($detail,0,10000)
                ||json_decode($stored['data_json'],true,20,JSON_THROW_ON_ERROR)!=$data||$normalize($existing)!==$normalize($audiences))throw new RuntimeException('Identificativo della ricevuta già usato con dati diversi.',409);
        }
        if($created)foreach($audiences as $a)$db->execute('INSERT IGNORE INTO activity_event_audiences(event_id,audience_scope,scope_id,section_key,access_role) VALUES(?,?,?,?,?)',
            [$event,$a['scope'],$a['id'],$a['section'],$a['role']??'member']);
        if($own)$db->commit();return $event;
    }catch(Throwable $error){if($own&&$db->inTransaction())$db->rollBack();throw $error;}
}

/** Scope/section is server-owned; callers cannot provide arbitrary links. */
function alphano_activity_destination(string $scope,int $id,string $section): array
{
    $group=$scope==='personale'?'gioco':$scope;
    if($section==='tesoreria'&&in_array($scope,['citta','organizzazioni','corporazioni'],true))return ['group'=>$group,'section'=>$section,'url'=>'/governo/?'.http_build_query(['scope'=>$scope,'id'=>$id])];
    if($scope==='citta'&&$section==='elezioni')return ['group'=>'citta','section'=>'elezioni','url'=>'/citta/elezioni.php?city='.$id];
    if($scope==='organizzazioni'&&$section==='abilita')return ['group'=>$group,'section'=>$section,'url'=>'/organizzazioni/admin.php?org='.$id.'&panel=abilita#tab-abilita'];
    if($scope==='accademia'&&$section==='abilita')return ['group'=>$group,'section'=>$section,'url'=>'/admin/accademia.php?panel=abilita#abilita'];
    $url=match($scope){
        'organizzazioni'=>$section==='statuto'?'/organizzazioni/showstatuto.php?org='.$id:'/organizzazioni/admin.php#tab-'.$section,
        'corporazioni'=>$section==='statuto'?'/corporazioni/showstatuto.php?corp='.$id:($section==='trasferimenti'?'/corporazioni/trasferimenti.php#corp':($section==='catalogo'?'/corporazioni/catalogo.php?corp='.$id:'/corporazioni/admin.php#'.$section)),
        'citta'=>in_array($section,['araldiche','rami-araldici'],true)?'/admin/cariche.php#'.$section:'/admin/city.php?city='.$id.'#'.$section,
        'accademia'=>'/admin/accademia.php#'.$section,
        'personale'=>match($section){'cittadinanza','araldiche','araldica'=>'/citta/','profilo'=>'/profili/?Id='.$id,'oggetti'=>'/equip/equip.php?pgid='.$id,'trasferimenti'=>'/trasferimenti/',default=>'/notifiche/'},
        'master'=>in_array($section,['ricompensa','oggetto','consuma','ferisci','resuscita','uccidi','addnote'],true)?'/admin/ferite.php#'.$section:'/sessione/viewlog.php',
        default=>'/admin/utenti.php',
    };
    return ['group'=>$group,'section'=>$section,'url'=>$url];
}

/** SQL clauses are built only from the current authenticated player record. */
function alphano_activity_acl(array $u): array
{
    $db=ConnectDB();
    $clauses=['(a.audience_scope=\'personale\' AND a.scope_id=?)'];$params=[$u['id']];
    foreach(['organizzazioni'=>['organizzazione','adminorganizzazione'],'corporazioni'=>['corporazione','admincorporazione']] as $scope=>[$field,$admin]){
        if((int)$u[$field]<1)continue;
        $roles=['member'];
        if(!empty($u[$admin]))$roles=array_merge($roles,['admin','trade','catalogue']);
        if($scope==='corporazioni'){
            if(!empty($u['admintrasfcorp']))$roles[]='trade';
            if(!empty($u['admincatalogo']))$roles[]='catalogue';
        }
        $roles=array_values(array_unique($roles));
        $clauses[]='(a.audience_scope=? AND a.scope_id=? AND a.access_role IN('.implode(',',array_fill(0,count($roles),'?')).'))';
        array_push($params,$scope,(int)$u[$field],...$roles);
    }
    $city=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[(int)$u['araldica']])[0]['city']??0);
    if($city>0){
        $roles=!empty($u['sindaco'])?['member','admin']:['member'];
        $clauses[]='(a.audience_scope=\'citta\' AND a.scope_id=? AND a.access_role IN('.implode(',',array_fill(0,count($roles),'?')).'))';array_push($params,$city,...$roles);
    }
    if(!empty($u['admin'])||!empty($u['adminaccademia']))$clauses[]="a.audience_scope='accademia'";
    if(!empty($u['admin'])||!empty($u['fatepower']))$clauses[]="a.audience_scope='master'";
    if(!empty($u['admin']))$clauses[]="a.audience_scope='gestione'";
    if(!empty($u['admin']))$clauses[]="a.audience_scope='citta'";
    return ['('.implode(' OR ',$clauses).')',$params];
}

function alphano_activity_where(array $user,bool $includeRead=false,?string $group=null,?string $section=null): array
{
    [$acl,$params]=alphano_activity_acl($user);
    if(!$includeRead)$acl.=' AND nr.read_at IS NULL';
    if($group!==null&&$group!==''){$acl.=' AND a.audience_scope=?';$params[]=$group==='gioco'?'personale':$group;}
    if($section!==null&&$section!==''){$acl.=' AND a.section_key=?';$params[]=$section;}
    return [$acl,$params];
}

/** Existing rows written by older code remain tasks until they have a receipt. */
function alphano_recorded_sources(array $user,string $source,array $ids): array
{
    if(!$ids)return [];
    if(!in_array($source,['loan','order'],true))throw new InvalidArgumentException('Fonte attività non valida');
    [$acl,$params]=alphano_activity_acl($user);$known=[];
    foreach(array_chunk(array_values(array_unique(array_map('intval',$ids))),300) as $chunk){
        $path='$.'.$source.'_id';
        $rows=ConnectDB()->query('SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(e.data_json,?)) AS source_id FROM activity_events e JOIN activity_event_audiences a ON a.event_id=e.id WHERE '.$acl.' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.data_json,?)) AS UNSIGNED) IN('.implode(',',array_fill(0,count($chunk),'?')).')',[$path,...$params,$path,...$chunk]);
        foreach($rows as $row)$known[(int)$row['source_id']]=true;
    }
    return $known;
}

function alphano_activity_history_total(int $recipient,?string $group=null,?string $section=null): int
{
    $db=ConnectDB();$u=$db->query('SELECT * FROM utenti WHERE id=?',[$recipient])[0]??null;if(!$u)return 0;
    [$where,$params]=alphano_activity_where($u,true,$group,$section);
    return (int)$db->query('SELECT COUNT(DISTINCT e.id) AS n FROM activity_events e JOIN activity_event_audiences a ON a.event_id=e.id WHERE '.$where,$params)[0]['n'];
}

/** History and unread receipts share the same current membership/role ACL. */
function alphano_persisted_activity(int $recipient,bool $includeRead=false,int $limit=0,int $offset=0,?string $group=null,?string $section=null,bool $summary=false,array $eventIds=[]): array
{
    $db=ConnectDB();$u=$db->query('SELECT * FROM utenti WHERE id=?',[$recipient])[0]??null;
    if(!$u)return [];
    [$where,$params]=alphano_activity_where($u,$includeRead,$group,$section);
    $ids=[];
    if($eventIds){$eventIds=array_values(array_unique(array_map('intval',$eventIds)));$where.=' AND e.id IN('.implode(',',array_fill(0,count($eventIds),'?')).')';array_push($params,...$eventIds);}
    if($limit>0){
        $limit=max(1,min(100,$limit));$offset=max(0,$offset);
        $selected=$db->query('SELECT DISTINCT e.id,e.created_at FROM activity_events e JOIN activity_event_audiences a ON a.event_id=e.id LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT(\'event:\',e.id) WHERE '.$where.' ORDER BY e.created_at DESC,e.id DESC LIMIT '.$limit.' OFFSET '.$offset,[$recipient,...$params]);
        if(!$selected)return []; $ids=array_map('intval',array_column($selected,'id'));
        $where.=' AND e.id IN('.implode(',',array_fill(0,count($ids),'?')).')';array_push($params,...$ids);
    }
    $projection=$summary?"e.id,e.event_key,e.event_type,e.actor_id,e.actor_name,e.title,LEFT(e.detail,512) AS detail,JSON_OBJECT('verso',JSON_EXTRACT(e.data_json,'$.verso'),'stato',JSON_EXTRACT(e.data_json,'$.stato'),'order_id',JSON_EXTRACT(e.data_json,'$.order_id'),'game_id',JSON_EXTRACT(e.data_json,'$.game_id'),'chat',JSON_EXTRACT(e.data_json,'$.chat'),'presentation',JSON_EXTRACT(e.data_json,'$.presentation')) AS data_json,e.created_at":'e.*';
    $rows=$db->query('SELECT '.$projection.',a.audience_scope,a.scope_id,a.section_key,a.access_role,nr.read_at FROM activity_events e
        JOIN activity_event_audiences a ON a.event_id=e.id
        LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT(\'event:\',e.id)
        WHERE '.$where.' ORDER BY e.created_at DESC,e.id DESC',[$recipient,...$params]);
    $events=[];
    foreach($rows as $row){
        $target=alphano_activity_destination($row['audience_scope'],(int)$row['scope_id'],$row['section_key']);
        $key='event:'.$row['id'];
        $data=json_decode($row['data_json'],true,20,JSON_THROW_ON_ERROR);
        if($row['event_type']==='board-game'&&$row['audience_scope']==='personale'&&!empty($data['game_id']))$target['url']='/chat/index.php?'.http_build_query(['chat'=>$data['chat'],'board_game'=>(int)$data['game_id']]);
        if($row['audience_scope']==='corporazioni'&&$row['section_key']==='trasferimenti')$target['url']='/corporazioni/trasferimenti.php?corp='.(int)$row['scope_id'].(!empty($data['order_id'])?'&trade='.(int)$data['order_id']:'').'#'.((int)($data['verso']??2)===1?'personal':((int)($data['stato']??0)===5?'produzione':($row['event_type']==='order-assigned'&&(int)$row['actor_id']===$recipient?'stven':'corp')));
        // One receipt may concern several menu sections for the same player.
        // The payload is unique; each currently authorized destination gets a badge.
        if(!isset($events[$key]))$events[$key]=['key'=>$key,'group'=>$target['group'],'section'=>$target['section'],'url'=>$target['url'],
            'title'=>$row['title'],'detail'=>$row['detail'],'created_at'=>(int)$row['created_at'],
            'actor'=>$row['actor_name'],'actor_id'=>(int)$row['actor_id'],'event_type'=>$row['event_type'],
            'data'=>$data,'read_at'=>$row['read_at']===null?null:(int)$row['read_at'],'persistent'=>true,'destinations'=>[],'_administrative'=>false];
        $events[$key]['_administrative']=$events[$key]['_administrative']||$row['audience_scope']!=='personale'&&(in_array($row['access_role'],['admin','trade','catalogue'],true)||in_array($row['audience_scope'],['accademia','master','gestione'],true));
        $events[$key]['destinations'][$target['group'].':'.$target['section'].':'.$target['url']]=$target;
        if($row['audience_scope']!=='personale')foreach(['group','section','url'] as $field)$events[$key][$field]=$target[$field];
    }
    foreach($events as &$event){
        $event['destinations']=array_values($event['destinations']);
        if(!$event['_administrative']){
            $presentation=$event['event_type']==='board-game'?($event['data']['presentation']??null):null;
            $public=array_flip(['game_id','chat','order_id','loan_id','pg_loan_id','stato','verso','qta','prezzo','quantita','interessi','restituiti','instance_id','oggetto','importo','motivo','rimborso','donazione','debito_residuo','kind','target','mittente','destinatario']);
            $event['data']=array_filter(array_intersect_key($event['data'],$public),static fn($value)=>is_scalar($value)||$value===null);
            if(is_array($presentation))$event['data']['presentation']=$presentation;
        }
        unset($event['_administrative']);
    }unset($event);
    return array_values($events);
}
