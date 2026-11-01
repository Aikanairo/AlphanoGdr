<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/ai-perception.inc.php';
require_once __DIR__.'/profile-art.inc.php';
require_once __DIR__.'/ai-narrative.inc.php';

/** A witness must be present at publication and roleplay within this bounded scene. */
const ALPHANO_REVELATION_WINDOW=1800;
/** One read policy for relationship dossiers; civic offices do not grant this access. */
function alphano_relations_staff(int $viewer):bool {
    if($viewer<1)return false;
    $u=ConnectDB()->query('SELECT admin,fatepower,adminaccademia,bloccato FROM utenti WHERE id=?',[$viewer])[0]??[];
    return empty($u['bloccato'])&&(!empty($u['admin'])||!empty($u['fatepower'])||!empty($u['adminaccademia']));
}
/** Master and administrator may correct pair emotions; academic access remains read-only. */
function alphano_relations_editor(int $viewer):bool {
    $u=ConnectDB()->query('SELECT admin,fatepower,bloccato FROM utenti WHERE id=?',[$viewer])[0]??[];
    return $u&&empty($u['bloccato'])&&(!empty($u['admin'])||!empty($u['fatepower']));
}
/** Presentation only: retain the observed evidence, without joined HTML headings. */
function alphano_revelation_excerpt(string $evidence):string {
    $text=ai_perception_plain(preg_replace('~</?(?:h[1-6]|strong|p|div|li|br)\b[^>]*>~iu',' ',$evidence)??$evidence,1500);
    $text=preg_replace('/^(?:La metamorfosi|Il risveglio del sangue)\s*[·:—-]?\s*/iu','',$text)??$text;
    return ai_perception_event_text(['kind'=>'transformation','content'=>trim($text)]);
}
/** Bounded read-only staff projection. No offers, preferences or money movements. */
function alphano_relations_npc_context(int $npc,int $player,int $viewer):array {
    if(!alphano_relations_staff($viewer))throw new DomainException('Consultazione riservata a Master, Accademia e amministratori.',403);
    require_once __DIR__.'/ai.inc.php';
    $identity=ai_player_identity($player);if(!$identity)throw new DomainException('Personaggio non disponibile.',404);
    $r=ai_relation($npc,$player);$metrics=[];
    foreach(AI_RELATION_METRICS as $key=>$label)$metrics[$key]=['label'=>$label,'value'=>(int)$r[$key],'min'=>in_array($key,['sympathy','trust','respect','honor'],true)?-100:0];
    return ['metrics'=>$metrics,'state'=>$r['state'],'encounters'=>(int)$r['encounters'],'interactions'=>(int)$r['interactions'],'meaningful'=>(int)$r['meaningful'],
        'development'=>ai_social_development_view($npc,$player,true),
        'bond'=>ai_relation_summary($r,in_array($r['state'],['innamoramento','relazione','crisi','riconciliazione'],true)),
        'memories'=>ai_db()->query('SELECT id,category,summary,source,visible,active,created_at FROM ai_personal_memories WHERE npc_id=? AND player_id=? ORDER BY id DESC LIMIT 100',[$npc,$player]),
        'knowledge'=>alphano_revelations_profile('npc',$npc,$player),'player'=>['id'=>$player,'name'=>$identity['nome']],
        'overview'=>ai_personality_overview($npc),'current_context'=>ai_relation_context_current($npc,$player), 'read_only'=>true];
}
function alphano_revelations_ready():bool {
    static $ready=null;
    return $ready??=(bool)ConnectDB()->query("SHOW TABLES LIKE 'pg_nature_observations'");
}
function alphano_revelations_ic_html(string $html):bool {
    if(preg_match('/\b(?:MasterKey|DescrKey|Off|chat-ooc-text)\b/u',$html))return false;
    // Stored legacy rows contain the speaker link and timestamp, which are not
    // participation prose. Remove them before using the shared narrative parser.
    $body=preg_replace('~<a\b[^>]*>.*?</a>~isu','',$html)??$html;
    $body=preg_replace('/\b\d{1,2}:\d{2}(?::\d{2})?\b/u','',ai_perception_plain($body,5000))??'';
    $reading=ai_narrative_parse($body);
    return trim($reading['speech'].' '.$reading['action'])!=='';
}
function alphano_revelations_participation(int $player,string $chat,int $source,int $time):int {
    foreach(ConnectDB()->query('SELECT id,msg FROM chat WHERE pg=? AND BINARY chat=BINARY ? AND dest=0 AND id<? AND ora BETWEEN ? AND ? ORDER BY id DESC LIMIT 30',[$player,$chat,$source,$time-ALPHANO_REVELATION_WINDOW,$time]) as $row)
        if(alphano_revelations_ic_html($row['msg']))return (int)$row['id'];
    return 0;
}
/** Reuse the canonical Master ledger; no raw player claim can create a revelation. */
function alphano_revelations_capture(array $event,array $revealed=[]):void {
    if(!alphano_revelations_ready())return;
    $subjects=$revealed;
    if($event['kind']==='transformation'&&(int)$event['actor_id']>0&&in_array((int)$event['native_race_id'],[2,3,5,6],true))
        $subjects[]=['player'=>(int)$event['actor_id'],'race'=>(int)$event['native_race_id'],'evidence'=>$event['content']];
    if(!$subjects)return;
    $db=ConnectDB();
    foreach($subjects as $s)$db->execute('INSERT IGNORE INTO pg_scene_revelations(event_id,subject_id,race_id,evidence) VALUES(?,?,?,?)',[(int)$event['id'],(int)$s['player'],(int)$s['race'],mb_substr((string)$s['evidence'],0,500)]);
    foreach($db->query("SELECT id FROM utenti WHERE BINARY chat=BINARY ? AND posizione NOT IN('off','online','') AND puntif>0 AND bloccato=0",[$event['chat_key']]) as $u){
        $pid=(int)$u['id'];$proof=alphano_revelations_participation($pid,$event['chat_key'],(int)$event['source_id'],(int)$event['created_at']);
        $db->execute('INSERT IGNORE INTO pg_scene_witnesses(event_id,player_id,participation_source_id) VALUES(?,?,?)',[(int)$event['id'],$pid,$proof]);
        if($proof)foreach($subjects as $s)alphano_revelations_remember($pid,(int)$s['player'],(int)$s['race'],$event,$proof,(string)$s['evidence']);
    }
}
function alphano_revelations_remember(int $observer,int $subject,int $race,array $event,int $proof,string $evidence):void {
    if($observer===$subject||!in_array($race,[2,3,5,6],true)||$proof<1)return;
    ConnectDB()->execute('INSERT IGNORE INTO pg_nature_observations(observer_id,subject_id,race_id,source_id,participation_source_id,chat_key,evidence,created_at,discovered_at) VALUES(?,?,?,?,?,?,?,?,?)',[$observer,$subject,$race,(int)$event['source_id'],$proof,$event['chat_key'],mb_substr(ai_perception_event_text(['kind'=>$event['kind'],'content'=>$evidence]),0,500),(int)$event['created_at'],time()]);
}
/** Called for every saved public IC turn, including when the AI room is disabled. */
function alphano_revelations_after_message(string $chat,int $source,int $player,string $raw,int $dest):void {
    if($dest!==0||$source<1||!alphano_revelations_ready()||preg_match('/^(?:\/\/|[#%$&])/u',ltrim($raw)))return;
    $reading=ai_narrative_parse($raw);if(trim($reading['speech'].' '.$reading['action'])==='')return;
    $db=ConnectDB();
    $row=$db->query('SELECT c.id,c.ora,c.msg FROM chat c JOIN utenti u ON u.id=c.pg WHERE c.id=? AND c.pg=? AND c.dest=0 AND BINARY c.chat=BINARY ? AND BINARY u.chat=BINARY c.chat AND u.posizione NOT IN(\'off\',\'online\',\'\') AND u.puntif>0 AND u.bloccato=0',[$source,$player,$chat])[0]??null;
    if(!$row||!alphano_revelations_ic_html($row['msg']))return;
    $own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        $events=$db->query('SELECT e.*,r.subject_id,r.race_id,r.evidence FROM pg_scene_witnesses w JOIN ai_scene_events e ON e.id=w.event_id JOIN pg_scene_revelations r ON r.event_id=e.id WHERE w.player_id=? AND w.participation_source_id=0 AND BINARY e.chat_key=BINARY ? AND e.source_id<? AND e.created_at BETWEEN ? AND ? ORDER BY e.id',[$player,$chat,$source,(int)$row['ora']-ALPHANO_REVELATION_WINDOW,(int)$row['ora']]);
        foreach($events as $event){
            alphano_revelations_remember($player,(int)$event['subject_id'],(int)$event['race_id'],$event,$source,$event['evidence']);
            $db->execute('UPDATE pg_scene_witnesses SET participation_source_id=? WHERE event_id=? AND player_id=? AND participation_source_id=0',[$source,(int)$event['id'],$player]);
        }
        if($own)$db->commit();
    }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}

/** Profile projection is pairwise for visitors; only the owner and Master see the full archive. */
function alphano_revelations_profile(string $kind,int $id,int $viewer):array {
    $out=['allowed'=>false,'items'=>[],'count'=>0,'scope'=>'personal'];
    if($viewer<1||$id<1||!in_array($kind,['npc','pg'],true))return $out;
    $db=ConnectDB();$rows=[];
    if($kind==='npc'){
        if(!ai_perception_ready())return $out;
        $rows=$db->query("SELECT 'npc' AS observer_kind,k.npc_id AS observer_id,k.player_id AS subject_id,k.race_id,k.first_seen,k.last_seen,k.chat_key,k.evidence,n.name AS name,n.profile AS npc_profile,m.title AS place FROM ai_nature_knowledge k JOIN ai_npcs n ON n.id=k.npc_id LEFT JOIN mappe m ON BINARY m.chat_key=BINARY k.chat_key WHERE k.npc_id=? AND k.player_id=?",[$id,$viewer]);
    }else{
        if(!alphano_revelations_ready())return $out;
        $full=$viewer===$id||alphano_relations_staff($viewer);$out['scope']=$full?'archive':'shared';
        $where=$full?'(o.subject_id=? OR o.observer_id=?)':'((o.subject_id=? AND o.observer_id=?) OR (o.observer_id=? AND o.subject_id=?))';
        $params=$full?[$id,$id]:[$id,$viewer,$id,$viewer];
        $rows=$db->query("SELECT 'pg' AS observer_kind,o.*,m.title AS place,(SELECT MIN(k.created_at) FROM pg_nature_observations k WHERE k.observer_id=o.observer_id AND k.subject_id=o.subject_id) AS first_seen,o.created_at AS last_seen FROM pg_nature_observations o LEFT JOIN mappe m ON BINARY m.chat_key=BINARY o.chat_key WHERE $where AND NOT EXISTS(SELECT 1 FROM pg_nature_observations newer WHERE newer.observer_id=o.observer_id AND newer.subject_id=o.subject_id AND (newer.created_at>o.created_at OR (newer.created_at=o.created_at AND newer.source_id>o.source_id) OR (newer.created_at=o.created_at AND newer.source_id=o.source_id AND newer.id>o.id))) ORDER BY o.created_at DESC LIMIT 100",$params);
        if($full&&ai_perception_ready())$rows=array_merge($rows,$db->query("SELECT 'npc' AS observer_kind,k.npc_id AS observer_id,k.player_id AS subject_id,k.race_id,k.first_seen,k.last_seen,k.chat_key,k.evidence,n.name AS name,n.profile AS npc_profile,m.title AS place FROM ai_nature_knowledge k JOIN ai_npcs n ON n.id=k.npc_id LEFT JOIN mappe m ON BINARY m.chat_key=BINARY k.chat_key WHERE k.player_id=? ORDER BY k.last_seen DESC LIMIT 100",[$id]));
    }
    $people=[];$personIds=[];foreach($rows as $r)if($r['observer_kind']==='pg')$personIds[]=(int)((int)$r['subject_id']===$id?$r['observer_id']:$r['subject_id']);
    if($personIds){$personIds=array_values(array_unique($personIds));$marks=implode(',',array_fill(0,count($personIds),'?'));foreach($db->query("SELECT id,nome,razza,vampire_human_form,sesso,avatar,avatar2 FROM utenti WHERE id IN ($marks)",$personIds) as $u)$people[(int)$u['id']]=$u;}
    foreach($rows as $r){
        $direction=$kind==='npc'||(int)$r['subject_id']===$id?'known_by':'discovered';
        $other=$direction==='known_by'?(int)$r['observer_id']:(int)$r['subject_id'];
        if($r['observer_kind']==='npc'){$p=json_decode($r['npc_profile'],true)?:[];$name=$r['name'];$portrait=$p['portrait']??'/assets/placeholder.svg';$url='/profili/png.php?Id='.$other;$sex=$p['sex']??'Maschio';$race=(int)($p['race_id']??1);}
        else{$u=$people[$other]??null;if(!$u)continue;$name=$u['nome'];$portrait=alphano_profile_portrait($u)['url'];$url='/profili/?Id='.$other;$sex=$u['sesso'];$race=alphano_visible_race($u);}
        $out['items'][]=['direction'=>$direction,'observer_kind'=>$r['observer_kind'],'observer_id'=>(int)$r['observer_id'],'subject_id'=>(int)$r['subject_id'],'name'=>$name,'portrait'=>$portrait?:'/assets/placeholder.svg','profile_url'=>$url,'sex'=>$sex,'race_icon'=>alphano_race_icon($race,$sex),'race_label'=>ai_perception_race_family((int)$r['race_id'])===2?'vampiro':'licantropo','first_seen'=>(int)$r['first_seen'],'last_seen'=>(int)$r['last_seen'],'place'=>$r['place']?:$r['chat_key'],'chat_key'=>$r['chat_key'],'evidence'=>alphano_revelation_excerpt($r['evidence'])];
    }
    $out['allowed']=true;$out['count']=count($out['items']);return $out;
}

/** A PG's complete NPC relationship overview is reserved to its owner and Masters. */
function alphano_relations_profile_pg(int $player,int $viewer):array {
    $out=['allowed'=>false,'items'=>[],'player_id'=>$player,'read_only'=>$player!==$viewer];$db=ConnectDB();
    if($player!==$viewer&&!alphano_relations_staff($viewer))return $out;
    require_once __DIR__.'/ai.inc.php';
    $identity=ai_player_identity($player);
    foreach($db->query('SELECT r.*,n.name,n.profile,n.active,nr.settings AS social_rules FROM ai_relationships r JOIN ai_npcs n ON n.id=r.npc_id LEFT JOIN ai_npc_rules nr ON nr.npc_id=n.id WHERE r.player_id=? AND r.interactions>0 ORDER BY r.updated_at DESC LIMIT 60',[$player]) as $r){
        $r['id']=(int)$r['npc_id'];
        $rules=array_replace(ai_social_defaults(),json_decode($r['social_rules']??'{}',true)?:[]);$p=json_decode($r['profile'],true)?:[];
        $romantic=in_array($r['state'],['innamoramento','relazione','crisi','riconciliazione'],true)&&ai_romance_allowed($r,$identity,$rules);
        $bond=ai_relation_summary($r,$romantic);
        $out['items'][]=['id'=>(int)$r['npc_id'],'name'=>$r['name'],'portrait'=>($p['portrait']??'')?:'/assets/placeholder.svg','interactions'=>(int)$r['interactions'],'encounters'=>(int)$r['encounters'],'bond'=>$bond];
    }
    $out['allowed']=true;return $out;
}

/** Technical Master-only data: knowledge is individual, never shared with the whole cast. */
function alphano_revelations_master_context(string $chat,int $before=0):array {
    if(!alphano_revelations_ready())return [];
    return ConnectDB()->query('SELECT o.observer_id,a.nome AS observer_name,o.subject_id,b.nome AS subject_name,o.race_id,o.created_at,o.chat_key FROM pg_nature_observations o JOIN utenti a ON a.id=o.observer_id JOIN utenti b ON b.id=o.subject_id WHERE BINARY a.chat=BINARY ? AND BINARY b.chat=BINARY ? AND (?=0 OR (o.source_id<=? AND o.participation_source_id<=?)) AND NOT EXISTS(SELECT 1 FROM pg_nature_observations n WHERE n.observer_id=o.observer_id AND n.subject_id=o.subject_id AND (n.created_at>o.created_at OR (n.created_at=o.created_at AND n.source_id>o.source_id) OR (n.created_at=o.created_at AND n.source_id=o.source_id AND n.id>o.id)) AND (?=0 OR (n.source_id<=? AND n.participation_source_id<=?))) ORDER BY o.id DESC LIMIT 30',[$chat,$chat,$before,$before,$before,$before,$before,$before]);
}
