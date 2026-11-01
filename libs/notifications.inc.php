<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/libelezioni.inc.php';
require_once __DIR__.'/activity.inc.php';

/** Un evento viene consegnato soltanto ai giocatori presenti alla sua apertura. */
function alphano_notify_quest(int $session, string $name, string $chat, string $location, int $created): void
{
    ConnectDB()->execute(
        "INSERT IGNORE INTO quest_notifications(recipient,session_id,title,chat_key,location_label,created_at)
         SELECT id,?,?,?,?,? FROM utenti WHERE posizione<>'off' AND bloccato=0",
        [$session,$name,$chat,$location,$created]
    );
}

function alphano_unread_notifications(int $user,?array &$allActivity=null): array
{
    $db=ConnectDB();
    $mailRow=$db->query('SELECT COUNT(*) AS n,COALESCE(MAX(id),0) AS latest FROM mail WHERE iddestinatario=? AND deldest=0 AND baule=0 AND letto=0',[$user])[0];
    $mail=(int)$mailRow['n'];
    $rows=$db->query('SELECT n.id,n.session_id,n.title,n.location_label,n.created_at,m.chat_key FROM quest_notifications n LEFT JOIN mappe m ON m.chat_key=n.chat_key COLLATE utf8mb4_unicode_ci AND m.chat=1 WHERE n.recipient=? AND n.read_at IS NULL ORDER BY n.id DESC',[$user]);
    foreach($rows as &$row){
        $row['id']=(int)$row['id'];
        $row['url']=$row['chat_key'] ? '/chat/index.php?chat='.rawurlencode($row['chat_key']) : '/sessione/viewlog.php';
        unset($row['chat_key']);
    }
    $activity=alphano_activity_notifications($user);$allActivity=$activity;
    $counts=array_fill_keys(['gioco','organizzazioni','corporazioni','citta','accademia','bacheche','master','gestione'],0);
    $sections=[];$links=[];$filters=[];
    foreach($activity as $notice){
        $noticeGroups=[];$noticeSections=[];$noticeLinks=[];
        foreach($notice['destinations']??[$notice] as $target){
            $noticeGroups[$target['group']]=true;
            $noticeSections[$target['group'].':'.$target['section']]=true;
            $link=alphano_notification_link($target['url']);
            foreach(alphano_notification_parent_links($link) as $parentLink){
                $noticeLinks[$parentLink]=true;
                $filters[$parentLink][$target['group'].':'.$target['section']]=['group'=>$target['group'],'section'=>$target['section']];
            }
        }
        foreach($noticeGroups as $key=>$_)$counts[$key]=($counts[$key]??0)+1;
        foreach($noticeSections as $key=>$_)$sections[$key]=($sections[$key]??0)+1;
        foreach($noticeLinks as $key=>$_)$links[$key]=($links[$key]??0)+1;
    }
    foreach($filters as &$filter)$filter=array_values($filter);unset($filter);
    return ['mail'=>$mail,'mail_latest_unread'=>(int)$mailRow['latest'],'quests'=>$rows,'activity'=>array_slice($activity,0,200),
        'counts'=>$counts,'sections'=>$sections,'links'=>$links,'link_filters'=>$filters,'activity_total'=>count($activity),'activity_limit'=>200];
}

function alphano_read_notifications(int $user, array $ids): void
{
    if(!$ids)return;
    ConnectDB()->execute('UPDATE quest_notifications SET read_at=? WHERE recipient=? AND read_at IS NULL AND id IN('.implode(',',array_fill(0,count($ids),'?')).')',[time(),$user,...$ids]);
}

/** A version changes only when the underlying request/proposal changes. */
function alphano_activity_key(string $source, int|string $identity, array $version): string
{
    return $source.':'.$identity.':'.hash('sha256',json_encode($version,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}

/**
 * Read current source records, never a copied private notification payload.
 * Revoking a membership/role therefore revokes both counters and message titles.
 * The only state stored by notifications is the recipient's acknowledgement.
 */
function alphano_activity_notifications(int $recipient): array
{
    $db=ConnectDB();
    $user=$db->query('SELECT * FROM utenti WHERE id=?',[$recipient])[0] ?? null;
    if(!$user)return [];
    $activity=[];
    $add=static function(string $key,string $group,string $title,string $detail,string $url,int $created=0,int $priority=0)use(&$activity):void{
        $activity[$key]=['key'=>$key,'title'=>$title,'detail'=>$detail,'url'=>$url,'group'=>$group,
            'created_at'=>$created,'priority'=>$priority,'section'=>alphano_notification_section($group,$key,$url),'persistent'=>false,'read_at'=>null];
    };

    // The permission expression is the same OR policy as player_board_permissions.
    $boardAcl="(NOT EXISTS(SELECT 1 FROM diritti r WHERE r.bacheca=b.bacheca)
        OR ?=1 OR EXISTS(SELECT 1 FROM diritti r WHERE r.bacheca=b.bacheca AND
          (r.`all`=1 OR (r.needadmin=1 AND ?=1) OR (r.needfatepower=1 AND ?=1)
           OR (r.needadminferiti=1 AND ?=1) OR (r.needaccademia=1 AND ?=1))))";
    $forum=$db->query("SELECT b.id,b.replyid,b.data,b.idpg,root.id AS thread_id,root.idpg AS owner,
          root.oggetto,l.id AS board_id,l.nome AS board_name,l.location,u.nome AS author
        FROM bacheche b JOIN luoghi l ON l.id=b.bacheca
        JOIN bacheche root ON root.id=IF(b.replyid=0,b.id,b.replyid) AND root.replyid=0 AND root.bacheca=b.bacheca
        LEFT JOIN utenti u ON u.id=b.idpg
        LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT('board:',b.id)
        WHERE b.idpg<>? AND nr.notice_key IS NULL AND $boardAcl",
        [$recipient,$recipient,(int)!empty($user['admin']),(int)!empty($user['admin']),
            (int)!empty($user['fatepower']),(int)!empty($user['adminferite']),(int)!empty($user['adminaccademia'])]);
    foreach($forum as $row){
        $reply=(int)$row['replyid']>0 && (int)$row['owner']===$recipient;
        $add('board:'.$row['id'],'bacheche',($reply?'Risposta al tuo topic: ':'Nuovo messaggio: ').$row['oggetto'],
            ($row['author'] ?: 'Sistema').' · '.$row['location'].' · '.$row['board_name'],
            '/bacheche/?board='.(int)$row['board_id'].'&thread='.(int)$row['thread_id'],(int)$row['data'],$reply?2:0);
    }

    // Appunti are personal: unlike the global administrator's group forums, another
    // player's Appunti are never visible or counted, including to administrators.
    $types=[];
    if(!empty($user['admin']) || !empty($user['fatepower']))$types[]='M';
    if(!empty($user['admin']) || !empty($user['adminaccademia']))$types[]='A';
    if((int)$user['organizzazione']>0)$types[]='Org'.(int)$user['organizzazione'];
    if((int)$user['corporazione']>0)$types[]='Corp'.(int)$user['corporazione'];
    $typeWhere=$types?'d.tipo IN('.implode(',',array_fill(0,count($types),'?')).')':'0';
    if(!empty($user['admin']))$typeWhere="($typeWhere OR d.tipo REGEXP '^(Org|Corp)[1-9][0-9]*$')";
    $posts=$db->query("SELECT d.id,d.replyid,d.data,d.tipo,root.id AS thread_id,root.idpg AS owner,root.oggetto,u.nome AS author,
          o.nome AS org_name,c.nome AS corp_name FROM discussioni d
        JOIN discussioni root ON root.id=IF(d.replyid=0,d.id,d.replyid) AND root.replyid=0 AND root.tipo=d.tipo
        LEFT JOIN utenti u ON u.id=d.idpg
        LEFT JOIN organizzazioni o ON d.tipo=CONCAT('Org',o.id)
        LEFT JOIN corporazioni c ON d.tipo=CONCAT('Corp',c.id)
        LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT('discussion:',d.id)
        WHERE d.idpg<>? AND nr.notice_key IS NULL AND ($typeWhere)",[$recipient,$recipient,...$types]);
    foreach($posts as $row){
        $group=str_starts_with($row['tipo'],'Org')?'organizzazioni':(str_starts_with($row['tipo'],'Corp')?'corporazioni':($row['tipo']==='A'?'accademia':'bacheche'));
        $label=$row['org_name'] ?: ($row['corp_name'] ?: ($row['tipo']==='A'?'Accademia':'Tracce Quest'));
        $reply=(int)$row['replyid']>0 && (int)$row['owner']===$recipient;
        $add('discussion:'.$row['id'],$group,($reply?'Risposta alla tua discussione: ':'Nuovo messaggio: ').$row['oggetto'],
            ($row['author'] ?: 'Sistema').' · '.$label,
            '/discussioni/?tipo='.rawurlencode($row['tipo']).'&thread='.(int)$row['thread_id'],(int)$row['data'],$reply?2:0);
    }

    // Group administration is tied to the actual membership, not global admin.
    $org=!empty($user['adminorganizzazione'])?(int)$user['organizzazione']:0;
    $corp=!empty($user['admincorporazione'])?(int)$user['corporazione']:0;
    $homeCity=$db->query('SELECT c.id,c.city,c.statuto,c.newstatuto,c.ballottaggio FROM araldiche a JOIN city c ON c.id=a.city WHERE a.id=?',[(int)$user['araldica']])[0] ?? null;
    $city=!empty($user['sindaco'])?(int)($homeCity['id'] ?? 0):0;
    if($homeCity){
        $homeId=(int)$homeCity['id'];
        $assemblyUrl='/citta/assemblea.php?nomecity='.rawurlencode($homeCity['city']);
        if(trim((string)$homeCity['newstatuto'])!=='' && !$db->query('SELECT 1 FROM assemblea WHERE city=? AND pg=?',[$homeId,$recipient])){
            $key=alphano_activity_key('city-statute',$homeId,[$homeCity['statuto'],$homeCity['newstatuto']]);
            $add($key,'citta','Nuova proposta di statuto',$homeCity['city'].' · Assemblea cittadina',$assemblyUrl,0,1);
        }
        // Announce actual rounds. Merely polling notifications must never create
        // elections, send mail, reset votes or run the assembly's maintenance.
        if(!alphano_elections_ready()){
        $round=$db->query('SELECT id,start_candidature,start_elezioni,end_elezioni FROM storicoelezioni WHERE city_id=? AND winner_id IS NULL ORDER BY id DESC LIMIT 1',[$homeId])[0] ?? null;
        if($round){
            $now=time();$candidates=strtotime($round['start_candidature']);$voting=strtotime($round['start_elezioni']);$end=strtotime($round['end_elezioni']);
            if($now>=$candidates && $now<$voting){
                $phase='candidature';$title='Aperte le candidature';$created=$candidates;
            }elseif($now>=$voting && $now<$end && CanVote($recipient)){
                $phase=!empty($homeCity['ballottaggio'])?'ballottaggio':'votazioni';
                $title=!empty($homeCity['ballottaggio'])?'Aperto il ballottaggio':'Aperte le votazioni';$created=$voting;
            }else $phase='';
            if($phase!==''){
                $key=alphano_activity_key('city-election',$homeId,[(int)$round['id'],$phase,$round['start_elezioni'],$round['end_elezioni']]);
                $add($key,'citta',$title,$homeCity['city'].' · Assemblea cittadina',$assemblyUrl,$created,1);
            }
        }
        } // Civic rounds publish their own immutable, correctly timed receipts.
    }
    $requests=$db->query('SELECT r.*,u.nome FROM richieste r JOIN utenti u ON u.id=r.idpg
        WHERE (r.organizzazione=? AND r.organizzazione>0) OR (r.corporazione=? AND r.corporazione>0) OR (r.city=? AND r.city>0) OR (?=1 AND r.city>0)',[$org,$corp,$city,(int)!empty($user['admin'])]);
    foreach($requests as $row){
        $requestCity=!empty($user['admin'])?(int)$row['city']:$city;
        foreach(['organizzazione'=>[$org,'organizzazioni','Nuova richiesta di adesione','/organizzazioni/admin.php#tab-iscritti'],
            'corporazione'=>[$corp,'corporazioni','Nuova richiesta di adesione','/corporazioni/admin.php#iscritti'],
            'city'=>[$requestCity,'citta','Nuova richiesta di cittadinanza','/admin/city.php?city='.$requestCity.'#richieste-cittadinanza']] as $column=>$target){
            if(!$target[0] || (int)$row[$column]!==$target[0])continue;
            $key=alphano_activity_key('request-'.$column,(int)$row['idpg'],[$target[0],(int)$row['data']]);
            $add($key,$target[1],$target[2],$row['nome'].' · In attesa di valutazione',$target[3],(int)$row['data'],1);
        }
    }
    if($org || $corp){
        $loans=$db->query('SELECT p.*,o.nome AS org_name,c.nome AS corp_name FROM prestiti p
            JOIN organizzazioni o ON o.id=p.org JOIN corporazioni c ON c.id=p.corp
            WHERE p.stato<6 AND ((p.org=? AND p.org>0) OR (p.corp=? AND p.corp>0))',[$org,$corp]);
        $states=[0=>'Da prendere in carico',1=>'Presa in carico',2=>'Contrattazione organizzazione',3=>'In attesa di risposta',4=>'Contrattazione corporazione',5=>'Accettato'];
        $recorded=alphano_recorded_sources($user,'loan',array_column($loans,'id'));
        foreach($loans as $row){
            if(isset($recorded[(int)$row['id']]))continue;
            $version=[(int)$row['stato'],(int)$row['quantita'],(int)$row['interessi'],(int)$row['restituiti'],(int)$row['pgorg'],(int)$row['pgcorp']];
            if($org && (int)$row['org']===$org)$add(alphano_activity_key('loan-org',$row['id'],$version),'organizzazioni','Prestito: '.$row['corp_name'],
                $row['quantita'].alphano_currency_text(' corone · ').($states[(int)$row['stato']] ?? 'Aggiornato'),'/organizzazioni/admin.php#tab-finanze',(int)$row['data'],1);
            if($corp && (int)$row['corp']===$corp)$add(alphano_activity_key('loan-corp',$row['id'],$version),'corporazioni','Prestito: '.$row['org_name'],
                $row['quantita'].alphano_currency_text(' corone · ').($states[(int)$row['stato']] ?? 'Aggiornato'),'/corporazioni/admin.php#finanze',(int)$row['data'],1);
        }
    }
    if((int)$user['corporazione']>0 && (!empty($user['admincorporazione']) || !empty($user['admintrasfcorp']))){
        $orders=$db->query('SELECT t.*,u.nome AS customer FROM trasferimenti t LEFT JOIN utenti u ON u.id=t.pg
            WHERE t.corp=? AND t.verso=2 AND t.stato<6',[(int)$user['corporazione']]);
        $recorded=alphano_recorded_sources($user,'order',array_column($orders,'id'));
        foreach($orders as $row){
            if(isset($recorded[(int)$row['id']]))continue;
            $key=alphano_activity_key('order',$row['id'],[(int)$row['stato'],(int)$row['qta'],(int)$row['prezzo'],(int)$row['pgcorp']]);
            $add($key,'corporazioni',((int)$row['stato']===5?'Oggetto da consegnare: ':'Ordine: ').$row['nomeobj'],
                ($row['customer'] ?: 'Giocatore').' · Quantità '.$row['qta'],'/corporazioni/trasferimenti.php#'.((int)$row['stato']===5?'produzione':'corp'),(int)$row['data'],1);
        }
    }

    $isAcademy=!empty($user['admin']) || !empty($user['adminaccademia']);
    $catalogCorp=(!empty($user['admincatalogo']) || !empty($user['admincorporazione']))?(int)$user['corporazione']:0;
    if($isAcademy || $catalogCorp){
        foreach(['equip'=>false,'newequip'=>true] as $table=>$change){
            $where=$change?'1':'approvato=0';$params=[];
            if(!$isAcademy){$where.=' AND corp=?';$params[]=$catalogCorp;}
            $items=$db->query("SELECT id,corp,shortDesc,datacreazione,datamodifica,`desc`,idcreatore FROM $table WHERE $where",$params);
            foreach($items as $row){
                $version=[(int)$row['datamodifica'],$row['shortDesc'],$row['desc']];
                $title=($change?'Modifica oggetto: ':'Nuovo oggetto: ').$row['shortDesc'];
                $created=(int)($row['datamodifica'] ?: $row['datacreazione']);
                if($isAcademy)$add(alphano_activity_key($change?'academy-item-change':'academy-item',$row['id'],$version),
                    'accademia',$title,'Valutazione Accademia','/admin/accademia.php#'.($change?'modifiche':'proposte'),$created,1);
                if($catalogCorp && (int)$row['corp']===$catalogCorp)$add(alphano_activity_key($change?'catalog-change':'catalog-item',$row['id'],$version),
                    'corporazioni',$title,'Catalogo · In valutazione Accademia','/corporazioni/catalogo.php?corp='.$catalogCorp,$created,1);
            }
        }
    }
    if($isAcademy){
        foreach(['organizzazioni'=>'org','corporazioni'=>'corp'] as $table=>$type){
            $proposals=$db->query("SELECT id,nome,approvato,statuto,newstatuto,idmaster,newidmaster FROM $table
                WHERE approvato=0 OR newstatuto<>'' OR newidmaster>0");
            foreach($proposals as $row){
                $new=empty($row['approvato']);
                $key=alphano_activity_key('academy-'.$type,$row['id'],[$row['approvato'],$row['statuto'],$row['newstatuto'],(int)$row['idmaster'],(int)$row['newidmaster']]);
                $add($key,'accademia',($new?'Nuova proposta: ':'Modifica proposta: ').$row['nome'],
                    ($type==='org'?'Organizzazione':'Corporazione').' · Valutazione Accademia','/admin/accademia.php#'.($new?'proposte':'modifiche'),0,1);
            }
        }
        foreach($db->query('SELECT id,nome,data,giocata FROM giocate') as $row){
            $add(alphano_activity_key('academy-play',$row['id'],[$row['nome'],$row['giocata']]),'accademia',
                'Giocata da valutare: '.($row['nome'] ?: '#'.$row['id']),'Valutazione Accademia','/admin/accademia.php#proposte',(int)$row['data'],1);
        }
    }

    foreach(alphano_persisted_activity($recipient,false,0,0,null,null,true) as $event){$event['priority']=1;$activity[$event['key']]=$event;}

    // Forum reads were filtered by their indexed join; this also filters pending
    // tasks whose version keys depend on the current state of their source row.
    foreach(array_chunk(array_keys($activity),300) as $keys){
        $read=$db->query('SELECT notice_key FROM activity_notification_reads WHERE recipient=? AND notice_key IN('.implode(',',array_fill(0,count($keys),'?')).')',[$recipient,...$keys]);
        foreach($read as $row)unset($activity[$row['notice_key']]);
    }
    $activity=array_values($activity);
    usort($activity,static fn(array $a,array $b):int=>($b['priority']<=>$a['priority']) ?: ($b['created_at']<=>$a['created_at']) ?: ((str_starts_with($a['key'],'event:')&&str_starts_with($b['key'],'event:'))?((int)substr($b['key'],6)<=>(int)substr($a['key'],6)):strcmp($a['key'],$b['key'])));
    foreach($activity as &$notice)unset($notice['priority']);
    return $activity;
}

function alphano_store_notification_reads(int $recipient,array $keys): void
{
    $db=ConnectDB();
    foreach(array_chunk(array_values(array_unique($keys)),300) as $chunk){
        $values=[];$params=[];
        foreach($chunk as $key){$values[]='(?,?,?)';array_push($params,$recipient,$key,time());}
        if($values)$db->execute('INSERT IGNORE INTO activity_notification_reads(recipient,notice_key,read_at) VALUES'.implode(',',$values),$params);
    }
}

/** Only acknowledge activities currently visible to this exact recipient. */
function alphano_read_activity_notifications(int $recipient,array $keys): void
{
    if(!$keys)return;
    $visible=array_column(alphano_activity_notifications($recipient),null,'key');
    alphano_store_notification_reads($recipient,array_values(array_filter($keys,static fn(string $key):bool=>isset($visible[$key]))));
}

/** Called after a thread's actual posts were rendered, never when opening a list. */
function alphano_read_notification_thread(int $recipient,array $target): void
{
    $db=ConnectDB();$user=$db->query('SELECT * FROM utenti WHERE id=?',[$recipient])[0] ?? [];
    $source=$target['source'] ?? null;$scope=$target['scope'] ?? null;
    $thread=$target['thread'] ?? null;$max=$target['max_id'] ?? null;
    if(!is_int($thread)||$thread<1||!is_int($max)||$max<1)throw new RuntimeException('Discussione non valida',400);
    if($source==='board'){
        if(!is_int($scope)||$scope<1)throw new RuntimeException('Bacheca non valida',400);
        player_require_board($db,$scope,$user);
        $table='bacheche';$column='bacheca';$prefix='board:';
    }elseif($source==='discussion'){
        if(!is_string($scope)||strlen($scope)>50)throw new RuntimeException('Discussione non valida',400);
        player_discussion_access($scope,$user);
        $table='discussioni';$column='tipo';$prefix='discussion:';
    }else throw new RuntimeException('Fonte non valida',400);
    if(!$db->query("SELECT id FROM $table WHERE id=? AND $column=? AND replyid=0",[$thread,$scope]))throw new RuntimeException('Discussione non trovata',404);
    $rows=$db->query("SELECT id FROM $table WHERE $column=? AND (id=? OR replyid=?) AND id<=?",[$scope,$thread,$thread,$max]);
    alphano_store_notification_reads($recipient,array_map(static fn(array $row):string=>$prefix.$row['id'],$rows));
}

/** Canonical keys are shared by menu links, badges and section deep links. */
function alphano_notification_link(string $url): string
{
    $parts=parse_url($url);$path=$parts['path']??'/';
    $path=preg_replace('~/index\.php$~','/',$path);
    parse_str($parts['query']??'',$query);unset($query['v']);ksort($query);
    return $path.($query?'?'.http_build_query($query):'').(!empty($parts['fragment'])?'#'.$parts['fragment']:'');
}

/** A forum menu represents all its threads, while the receipt keeps the exact thread. */
function alphano_notification_parent_links(string $url): array
{
    $links=[$url,explode('#',$url,2)[0]];
    $parts=parse_url($url);$path=$parts['path']??'';
    if(in_array($path,['/bacheche/','/discussioni/'],true)){
        parse_str($parts['query']??'',$query);unset($query['thread']);ksort($query);
        $links[]=$path.($query?'?'.http_build_query($query):'');$links[]=$path;
    }
    return array_values(array_unique($links));
}

function alphano_notification_section(string $group,string $key,string $url): string
{
    if(str_starts_with($key,'board:')||str_starts_with($key,'discussion:'))return 'forum';
    $fragment=parse_url($url,PHP_URL_FRAGMENT);
    if($fragment)return preg_replace('/^tab-/','',$fragment);
    if(str_starts_with($key,'city-statute:'))return 'statuto';
    if(str_starts_with($key,'city-election:'))return 'elezioni';
    if(str_starts_with($key,'catalog-'))return 'catalogo';
    return $group;
}

/** Inbox history is paginated; pending tasks keep their live permission checks. */
function alphano_notification_inbox(int $recipient,string $view,?string $group,?string $section,int $page,?array $unread=null): array
{
    $perPage=40;
    if($view==='history'){
        $total=alphano_activity_history_total($recipient,$group,$section);
        $pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        $items=alphano_persisted_activity($recipient,true,$perPage,($page-1)*$perPage,$group,$section);
    }else{
        $items=[];
        foreach($unread??alphano_activity_notifications($recipient) as $item){
            foreach($item['destinations']??[$item] as $target)if((!$group||$target['group']===$group)&&(!$section||$target['section']===$section)){
                if($group||$section)foreach(['group','section','url'] as $field)$item[$field]=$target[$field];
                $items[]=$item;break;
            }
        }
        $total=count($items);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        $items=array_slice($items,($page-1)*$perPage,$perPage);
        $ids=[];foreach($items as $item)if(!empty($item['persistent']))$ids[]=(int)substr($item['key'],6);
        if($ids){
            $full=array_column(alphano_persisted_activity($recipient,false,0,0,$group,$section,false,$ids),null,'key');
            foreach($items as &$item)if(isset($full[$item['key']]))$item=$full[$item['key']];unset($item);
        }
    }
    return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'view'=>$view];
}
