<?php
declare(strict_types=1);
require_once __DIR__.'/ai-personality.inc.php';
require_once __DIR__.'/ai-authority.inc.php';

/** Persistent character simulation. The model proposes; this layer owns all state. */
const AI_RELATION_METRICS=['sympathy'=>'Simpatia','trust'=>'Fiducia','fear'=>'Paura','respect'=>'Rispetto','honor'=>'Onore','affection'=>'Affetto','attraction'=>'Attrazione','love'=>'Amore','jealousy'=>'Gelosia','hostility'=>'Ostilità'];
const AI_RELATION_STATES=['conoscenza','amicizia','interesse','corteggiamento','innamoramento','relazione','crisi','separazione','riconciliazione'];
require_once __DIR__.'/ai-social-json.inc.php';
require_once __DIR__.'/ai-relation-feedback.inc.php';
require_once __DIR__.'/ai-social-evolution.inc.php';
require_once __DIR__.'/ai-episodes.inc.php';
/** A changed preference/correction must not be overwritten by an older in-flight prompt. */
function ai_invalidate_pair_work(int $player,int $npc,string $reason,bool $reflections=true,bool $preserveDialogue=false): void {
    $db=ai_db();if(!$db->inTransaction()||$player<1||$npc<0)throw new LogicException('Coppia e transazione richieste.');
    $where='j.user_id=?'.($npc?' AND j.npc_id=?':'');$values=[$player];if($npc)$values[]=$npc;
    $reason=mb_substr($reason,0,250);
    // Staff corrections refresh ordinary drafts through semantic fences. A
    // preference withdrawal still cancels them; immediate shock has its own receipt.
    $kinds=$preserveDialogue?"'reaction'":"'play','preview','observe','reaction'";
    $db->execute("UPDATE ai_jobs j SET j.status='cancelled',j.finished_at=?,j.error=? WHERE $where AND j.kind IN ($kinds) AND j.status IN ('queued','running')",[time(),$reason,...$values]);
    if($reflections)$db->execute("UPDATE ai_social_jobs s JOIN ai_jobs j ON j.id=s.job_id SET s.status='cancelled',s.error=? WHERE $where AND s.status IN ('queued','running')",[$reason,...$values]);
}
function ai_social_defaults(): array {
    return ['duty'=>'','voice_example'=>'','goals'=>'','honor_code'=>'','boundaries'=>'','narrative_rules'=>'','scene_context'=>'','allegiance'=>'','recognize_vampires'=>0,
        'orientation'=>'hetero','availability'=>'unavailable','hidden_emotions'=>0,'authority_attitude'=>'contextual',
        'economy_enabled'=>0,'max_amount'=>50,'daily_limit'=>100,'allow_loans'=>0,'accept_gifts'=>1,...ai_social_policy_defaults()];
}
function ai_social_rules(int $npc): array {
    $row=ai_db()->query('SELECT settings FROM ai_npc_rules WHERE npc_id=?',[$npc])[0]??[];
    return array_replace(ai_social_defaults(),json_decode($row['settings']??'{}',true)?:[]);
}
function ai_social_validate(array $input): array {
    if(array_diff(array_keys($input),array_keys(ai_social_defaults())))throw new DomainException('Impostazione del PNG non riconosciuta.',422);
    $v=array_replace(ai_social_defaults(),$input);
    foreach(['voice_example'=>450,'duty'=>400,'goals'=>600,'honor_code'=>500,'boundaries'=>500,'narrative_rules'=>1600,'scene_context'=>1200,'allegiance'=>80] as $k=>$max)$v[$k]=ai_field($v,$k,$max);
    if($v['allegiance']!==''){$city=ai_db()->query('SELECT city FROM city WHERE city=?',[$v['allegiance']])[0]??null;if(!$city)throw new DomainException('Comunità del PNG non disponibile.',422);$v['allegiance']=$city['city'];}
    foreach(['recognize_vampires','hidden_emotions','economy_enabled','allow_loans','accept_gifts'] as $k)$v[$k]=ai_integer($v,$k,0,1);
    foreach(['max_amount'=>1000000,'daily_limit'=>10000000] as $k=>$max)$v[$k]=ai_integer($v,$k,0,$max);
    if(!in_array($v['orientation'],['hetero','homo','none','men','women','all'],true)||!in_array($v['availability'],['available','unavailable','vow'],true))throw new DomainException('Preferenze affettive non valide.',422);
    if(!in_array($v['authority_attitude'],['contextual','loyal','skeptical','defiant'],true))throw new DomainException('Atteggiamento verso l’autorità non valido.',422);
    foreach(AI_SOCIAL_POLICY as $key=>$policy)$v[$key]=ai_integer($v,$key,$policy['min'],$policy['max']);
    return $v;
}
function ai_relation(int $npc,int $player): array {
    $defaults=['npc_id'=>$npc,'player_id'=>$player,'state'=>'conoscenza','encounters'=>0,'interactions'=>0,'meaningful'=>0,'revealed'=>'[]','created_at'=>time(),'updated_at'=>time(),'last_encounter'=>0,'known_name'=>''];
    foreach(AI_RELATION_METRICS as $key=>$_)$defaults[$key]=0;
    return array_replace($defaults,ai_db()->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=?',[$npc,$player])[0]??[]);
}
/** A derived personal bond, not a second mutable score or an obedience command. */
function ai_relation_summary(array $relation,bool $romance=false,bool $hidden=false,array $revealed=[]):array {
    $weights=['trust'=>.45,'sympathy'=>.2,'respect'=>.2,'affection'=>.15,'hostility'=>-.7];$net=0;
    foreach($weights as $key=>$weight){
        if($hidden&&!in_array($key,$revealed,true))return ['value'=>null,'label'=>'Rapporto riservato','tone'=>'unknown','romantic'=>false,'min_label'=>'Nemico','mid_label'=>'Neutro','max_label'=>'Leale'];
        $net+=(int)($relation[$key]??0)*$weight;
    }
    $value=max(0,min(100,50+(int)round($net)));
    $love=$romance&&(!$hidden||in_array('love',$revealed,true))&&in_array($relation['state']??'',['innamoramento','relazione','riconciliazione'],true)&&(int)($relation['love']??0)>=50;
    $label=match(true){$value===0=>'Nemico',$value<25=>'Ostilità',$value<45=>'Diffidenza',$value<=55=>'Neutro',$value<70=>'Fiducia crescente',$value<85=>'Legame solido',$value<100=>'Grande fiducia',default=>$love?'Innamorato':'Leale'};
    return ['value'=>$value,'signed_value'=>($value-50)*2,'label'=>$label,'tone'=>$value<45?'hostile':($value>55?'friendly':'neutral'),'romantic'=>$love,'min_label'=>'Nemico','mid_label'=>'Neutro','max_label'=>$love?'Innamorato':'Leale'];
}
/** Repeated evidence cannot be farmed by changing the NPC's generated wording. */
function ai_social_evidence_key(string $text):string {
    return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text))??'');
}
function ai_social_repeated_evidence(string $evidence,array $events):bool {
    $key=ai_social_evidence_key($evidence);$tokens=array_unique(explode(' ',$key));
    foreach($events as $event){
        $after=$event['_decoded_after']??(json_decode($event['after_state']??'{}',true)?:[]);$prior=(string)($after['_evolution']['evidence']??'');if($prior==='')continue;
        if($prior===$key)return true;
        $old=array_unique(explode(' ',$prior));
        if(count($tokens)>=5&&count($old)>=5&&count(array_intersect($tokens,$old))/max(1,count(array_unique([...$tokens,...$old])))>=.85)return true;
    }
    return false;
}
/** The first witnessed supernatural shock is a game fact, not a guessed PG quote. */
function ai_social_capture_perception(array $npc,array $job):void {
    if(!function_exists('ai_perception_reaction')||!ai_perception_ready())return;
    $reaction=ai_perception_reaction($npc,$job);$event=$reaction['recent']??null;
    if(empty($reaction['shock'])||!is_array($event))return;
    $nid=(int)$npc['id'];$actor=(int)($event['actor_id']??0);$source=(int)($event['source_id']??0);
    $family=ai_perception_race_family((int)($event['native_race_id']??0));
    if($actor<1||$source<1||!in_array($family,[2,3],true))return;
    if(!ai_recognition_view($nid,$actor,['chat_key'=>$job['chat_key'],'source_id'=>$source])['identified'])return;
    $db=ai_db();$now=time();
    $db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$actor,$now,$now]);
    $before=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$actor])[0];
    $present=ai_relation_context_current($nid,$actor);
    if($present&&$source<=$present['chat_id'])return;
    // The same family cannot be farmed by repeatedly switching forms. The
    // ledger remains the receipt; no parallel emotion table is introduced.
    if($db->query("SELECT id FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND actor_id=0 AND JSON_UNQUOTE(JSON_EXTRACT(after_state,'$._perception.family'))=? LIMIT 1",[$nid,$actor,(string)$family]))return;
    $reaction['self_control']=(int)(ai_npc_game($nid)['traits']['self_control']??50);
    $after=$before;$after['fear']=max((int)$before['fear'],ai_social_shock_fear($reaction,$event));
    $authority=ai_npc_authority($nid,$actor,$job);if($authority['recognized']&&($authority['attitude']['mode']??'respectful')==='respectful')$after['respect']=min(100,(int)$before['respect']+2);
    $after['updated_at']=$now;
    $db->execute('UPDATE ai_relationships SET fear=?,respect=?,updated_at=? WHERE npc_id=? AND player_id=?',[$after['fear'],$after['respect'],$now,$nid,$actor]);
    $after['_perception']=['source_id'=>$source,'family'=>$family,'actor_id'=>$actor,'mode'=>$reaction['mode'],'intensity'=>'panic_floor'];
    ai_relation_event($nid,$actor,$before,$after,'Prima reazione a rivelazione pubblica verificata #'.$source.': intensità del terrore coerente con coraggio e autocontrollo; nessun affetto automatico.',['chat_key'=>$job['chat_key'],'source_id'=>$source]);
    $evidence=ai_text((string)($event['content']??''),450);
    $db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,epistemic,created_at,updated_at) VALUES(?,?,?,'rivelazione',?,?,'system','verified',?,?)",[$nid,$actor,(string)$job['chat_key'],'Ha osservato una natura '.($family===2?'vampirica':'lupina').' manifestarsi ed è rimasto scosso. La propria paura non impone emozioni o gesti al visitatore.',$evidence,$now,$now]);
}
/** Publication-owned projection: revealing one manifested emotion never unlocks the others.
 * No delta is inferred here: immediate perception and the bounded social evaluator own scores.
 * The separate NULL-job receipt leaves the async reflection's unique job_id available.
 */
function ai_social_pair_manifestations(array $npc,array $job,string $reply):array {
    $keys=ai_social_manifested_emotions($npc,$reply);$publishedPanic=false;
    if(($job['kind']??'')==='reaction'&&(int)($job['id']??0)>0){
        $published=ai_db()->query('SELECT result,message_id FROM ai_jobs WHERE id=? AND npc_id=? AND user_id=?',[(int)$job['id'],(int)$npc['id'],(int)$job['user_id']])[0]??[];
        $result=json_decode((string)($published['result']??''),true)?:[];$receipt=$result['scene_reaction']??[];
        // Every variant of the server's actual published panic posture is evidence,
        // including wordless paralysis. A model proposal or an unpublished job is not.
        if((int)($published['message_id']??0)>0&&in_array($receipt['mode']??'',['flee','freeze'],true)
            &&(int)($receipt['actor_id']??0)===(int)$job['user_id']&&(int)($receipt['source_id']??0)===(int)($job['source_id']??0)
            &&in_array($reply,[(string)($result['testo']??''),(string)($receipt['immediate_text']??'')],true)){$keys[]='fear';$publishedPanic=true;}
    }
    $episode=$job['episode']??ai_episode_previous($job,(int)$npc['id']);
    if(!empty($episode['observer']))$keys=array_values(array_intersect($keys,array_keys(ai_social_observer_deltas(['fear'=>1,'hostility'=>1,'trust'=>-1],$reply))));
    // A character's current interlocutor and remembered scene can differ.
    // The reaction receipt owns that fear; a normal reply cannot silently reassign it.
    if(in_array('fear',$keys,true)){
        $spoken=in_array('fear',ai_social_manifested_emotions($npc,$reply,false),true);
        $threat=!empty($episode['known'])&&empty($episode['observer'])&&($episode['category']??'')==='minaccia';
        $otherBody=false;$ownBody=false;
        if(($job['kind']??'')!=='reaction'&&function_exists('ai_perception_scene_state'))foreach(ai_perception_scene_state($npc,$job)['visible'] as $body){
            if((int)$body['actor_id']===(int)$job['user_id'])$ownBody=(int)$body['fear']>0;else $otherBody=true;
        }
        // A shiver/broken breath alone can mean cold, fatigue or pain. It discloses
        // fear only beside an actual threat/body or the published server panic receipt.
        if((!$spoken&&!$threat&&!$ownBody&&!$publishedPanic)||($otherBody&&!$ownBody&&!isset(ai_social_observer_deltas(['fear'=>1],$reply)['fear'])))$keys=array_values(array_diff($keys,['fear']));
    }
    return array_values(array_unique($keys));
}
function ai_social_record_manifestation(array $npc,array $job,string $reply):void {
    if(!ai_relation_context_job_current($job))return;
    $nid=(int)($npc['id']??0);$uid=(int)($job['user_id']??0);
    if($nid<1||$uid<1||ai_recognition_anonymous($npc,$job))return;
    $keys=ai_social_pair_manifestations($npc,$job,$reply);if(!$keys)return;
    $db=ai_db();$owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try{
        $now=time();$db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$uid,$now,$now]);
        $before=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$uid])[0];
        if(!ai_relation_context_job_current($job)){if($owned)$db->commit();return;}
        $revealed=json_decode($before['revealed'],true)?:[];$new=array_values(array_diff($keys,$revealed));
        if($new){
            $after=$before;$after['revealed']=json_encode(array_values(array_unique([...$revealed,...$new])));$after['updated_at']=$now;
            $db->execute('UPDATE ai_relationships SET revealed=?,updated_at=? WHERE npc_id=? AND player_id=?',[$after['revealed'],$now,$nid,$uid]);
            $after['_manifestation']=['job_id'=>(int)($job['id']??0),'source_id'=>(int)($job['source_id']??0),'chat_key'=>(string)($job['chat_key']??''),'metrics'=>$new];
            $db->execute('INSERT INTO ai_relationship_events(npc_id,player_id,before_state,after_state,reason,created_at) VALUES(?,?,?,?,?,?)',[$nid,$uid,json_encode($before),json_encode($after),'Emozioni manifestate nella risposta pubblicata del PNG: '.implode(', ',$new).'. Le emozioni non espresse restano riservate.',$now]);
        }
        if($owned)$db->commit();
    }catch(Throwable $e){if($owned&&$db->inTransaction())$db->rollBack();throw $e;}
}
function ai_romance_allowed(array $npc,array $player,array $rules): bool {
    $p=ai_npc_profile($npc);
    $consent=ai_db()->query('SELECT character_age,romance_enabled,intimacy_enabled FROM ai_player_preferences WHERE player_id=?',[(int)$player['id']])[0]??[];
    $partner=ai_adult_partner((int)$npc['id']);if($partner&&$partner!==(int)$player['id'])return false;
    return (int)$p['age']>=18 && (int)($consent['character_age']??0)>=18 && !empty($consent['romance_enabled'])
        && $rules['availability']==='available' && ($rules['orientation']==='all'||($rules['orientation']==='hetero'&&$p['sex']!==$player['sesso'])||($rules['orientation']==='homo'&&$p['sex']===$player['sesso'])||($rules['orientation']==='men'&&$player['sesso']==='Maschio')||($rules['orientation']==='women'&&$player['sesso']==='Femmina'));
}
function ai_social_context(array $npc,array $job,float $scale): string {
    if((int)($job['user_id']??0)<1)return '';
    $player=ai_player_identity((int)$job['user_id']);if(!$player)return '';
    if(ai_recognition_anonymous($npc,$job))return "\nINTERLOCUTORE NON IDENTIFICATO: percepisci parole e gesti di questa figura dal volto coperto. I rapporti con persone note non appartengono automaticamente a questo incontro. Nessun nome, incarico, segreto o familiarità precedenti sono accertati.";
    $rules=ai_social_rules((int)$npc['id']);$rel=ai_relation((int)$npc['id'],(int)$player['id']);$romance=ai_romance_allowed($npc,$player,$rules);$feelings=ai_relation_feelings($rel);
    $present=ai_relation_context_current((int)$npc['id'],(int)$player['id']);
    $city=(string)($player['city']??'');$office=(string)($player['office']??'');
    $authority=ai_npc_authority((int)$npc['id'],(int)$player['id'],$job);
    $vampire=in_array(ai_perception_known_race((int)$npc['id'],(int)$player['id'],(int)($job['source_id']??0)),[ALPHANO_RACE_VAMPIRE,ALPHANO_RACE_ORIGINAL],true);
    $knownName=ai_identity_known_name($npc,$job,$player,$authority,$rel);
    if($scale<=.5){
        $out="\nINTERLOCUTORE: ".($knownName!==''?'nome proprio di persona: '.$knownName:'nome non appreso: non usarlo').'; carica '.($office?:'nessuna').' / '.($city?:'nessuna città').'. ';
        $out.=ai_authority_context((int)$npc['id'],(int)$player['id'],true,$job);
        $out.=$rules['recognize_vampires']&&$vampire?'Vampiro riconosciuto dal presidio: deferenza. ':'Non dedurre razze o lignaggi segreti. ';
        $out.='Rapporto '.$rel['state'].($present?', contesto attuale ridefinito dallo staff. ':', '.(int)$rel['encounters'].' incontri. ');
        $out.=$romance?($feelings['romantic']?'Il legame romantico è già stabilito: conserva il sentimento attuale, senza ricominciare ogni volta il corteggiamento. ':'Corteggiamento solo consensuale e graduale; rispetto di ogni rifiuto. '):'Nessun corteggiamento: età, consenso o disponibilità non lo consentono. ';
        if($rules['economy_enabled']){
            $debt=ai_db()->query('SELECT player_owes,npc_owes FROM ai_npc_debts WHERE npc_id=? AND player_id=?',[(int)$npc['id'],(int)$player['id']])[0]??[];
            $out.='Cassa '.ai_npc_balance((int)$npc['id']).'; max proposta '.$rules['max_amount'].', max/giorno '.$rules['daily_limit'].alphano_currency_text(' Corone; debiti PG/PNG ').(int)($debt['player_owes']??0).'/'.(int)($debt['npc_owes']??0).'. '.($rules['allow_loans']?'Prestiti abilitati. ':'Niente prestiti. ').'Solo proposte, mai trasferimenti già avvenuti. ';
        }else $out.='Niente scambi monetari. ';
        return $out.ai_personality_context($npc,$job,$scale,$rel).ai_social_development_context($npc,$job,$rules,$scale);
    }
    $out="\nINTERLOCUTORE VERIFICATO DAL GIOCO: ".($knownName!==''?'Nome noto: '.$knownName:'Nome non ancora appreso: non chiamarlo per nome finché non si presenta').'.';
    $out.=ai_authority_context((int)$npc['id'],(int)$player['id'],false,$job);
    if($rules['recognize_vampires']&&$vampire)$out.=' La natura pubblica del personaggio è riconosciuta dal PNG. Usa solo rapporti e incarichi verificati, senza inventare appartenenze.';
    else $out.=' Non dedurre razze segrete, poteri o lignaggi dall’aspetto o dalla carica; non inventare credenziali.';
    $out.="\nDOVERE DEL PNG: ".ai_text($rules['duty'],160)."\nOBIETTIVI: ".ai_text($rules['goals'],160)."\nCODICE E LIMITI: ".ai_text($rules['honor_code'],200).' '.ai_text($rules['boundaries'],250);
    if($rules['voice_example'])$out.="\nVOCE DI RIFERIMENTO (non copiarla): ".$rules['voice_example'];
    $out.="\nRAPPORTO PERSONALE CON QUESTO INTERLOCUTORE: ".$rel['state'].($present?', contesto attuale ridefinito dallo staff. ':', incontri '.(int)$rel['encounters'].', interazioni '.(int)$rel['interactions'].'. ');
    $out.=$romance?($feelings['romantic']?'Il sentimento romantico è già stabilito e va rappresentato coerentemente con i valori attuali; non tornare a un primo corteggiamento. Rispetta i limiti del momento.':'Corteggiamento possibile ma mai dovuto: rispetta preferenze e rifiuti; i doni non comprano affetto.'):'Nessun corteggiamento o proposta romantica: età, consenso o disponibilità non lo consentono. Puoi parlare di amicizia e rifiutare con rispetto.';
    $out.=ai_personality_context($npc,$job,$scale,$rel).ai_social_development_context($npc,$job,$rules,$scale);
    if($rules['economy_enabled']){
        $debt=ai_db()->query('SELECT player_owes,npc_owes FROM ai_npc_debts WHERE npc_id=? AND player_id=?',[(int)$npc['id'],(int)$player['id']])[0]??[];
        $out.=' Cassa effettiva del PNG: '.ai_npc_balance((int)$npc['id']).alphano_currency_text(' Corone; massimo per proposta ').$rules['max_amount'].'. Debito del PG verso di te '.(int)($debt['player_owes']??0).'; tuo debito verso il PG '.(int)($debt['npc_owes']??0).'. Non promettere somme che non possiedi. Formula prezzo, importo e causale chiaramente: il trasferimento avviene solo dopo conferma nel profilo. Non offrire prestiti se non abilitati.';
    }else $out.=' Questo PNG non esegue scambi monetari: non promettere pagamenti reali.';
    $out.="\nDinieghi e tentativi sono solo narrativi: non bloccare accessi, non imporre esiti sui PG. Non dichiarare denaro, cure, oggetti o titoli già trasferiti; una proposta economica richiede conferma del backend. Usa i ricordi spontaneamente, senza elencare i punteggi.";
    return $out;
}
/** An encountered dialogue is durable evidence; no score is inferred from keywords. */
function ai_social_record(array $npc,array $job,array $result): void {
    if(ai_recognition_anonymous($npc,$job)||!ai_relation_context_job_current($job))return;
    $db=ai_db();$id=(int)$job['id'];$player=(int)$job['user_id'];$now=time();
    if($player<1||$id<1)return;
    ai_social_record_manifestation($npc,$job,(string)$result['testo']);
    if($db->query('SELECT id FROM ai_relationship_events WHERE job_id=?',[$id]))return;
    $db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[(int)$npc['id'],$player,$now,$now]);
    $rel=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[(int)$npc['id'],$player])[0];
    if(!ai_relation_context_job_current($job))return;
    // A published job may be retried before asynchronous reflection has written an event.
    if($db->query("SELECT id FROM ai_personal_memories WHERE job_id=? AND category='incontro'",[$id]))return;
    // Consecutive messages are one encounter, preventing message spam from building bonds.
    $name=ai_player_identity($player)['nome']??'';
    $observed=ai_personality_observable((string)$job['prompt']);
    if(ai_identity_introduction($name,(string)$job['prompt']))$db->execute('UPDATE ai_relationships SET known_name=? WHERE npc_id=? AND player_id=?',[$name,(int)$npc['id'],$player]);
    $encounter=(int)$rel['last_encounter']<$now-1800?1:0;
    $db->execute('UPDATE ai_relationships SET encounters=encounters+?,interactions=interactions+1,last_encounter=?,updated_at=? WHERE npc_id=? AND player_id=?',[$encounter,$now,$now,(int)$npc['id'],$player]);
    $summary='Turno osservabile del PG (parole e tentativi, non esiti accertati): '.ai_text($observed,450).'. '.$npc['name'].' ha risposto: '.ai_text($result['testo'],650);
    $db->execute("INSERT IGNORE INTO ai_personal_memories(npc_id,player_id,chat_key,job_id,category,summary,evidence,source,epistemic,created_at,updated_at) VALUES(?,?,?,?,'incontro',?,?,'dialogue','dialogue',?,?)",[(int)$npc['id'],$player,$job['chat_key'],$id,$summary,$observed,$now,$now]);
    ai_social_capture_perception($npc,$job);
    // Quest jobs contain technical instructions in ai_jobs.prompt. Preserve only the real PG
    // observation supplied by the quest publisher; identity and room still come from ai_jobs.
    $observation=($job['kind']??'')==='npc-turn'?json_encode(['prompt'=>(string)$job['prompt'],'result'=>json_encode(['testo'=>(string)$result['testo']],JSON_UNESCAPED_UNICODE),'source_id'=>(int)($job['source_id']??0)],JSON_UNESCAPED_UNICODE):null;
    $db->execute('INSERT IGNORE INTO ai_social_jobs(job_id,available_at,observation) VALUES(?,?,?)',[$id,$now,$observation]);
}
/** One published quest turn may observe several real PG actions. Keep individual
 * receipts without copying the addressed reply/romance to every person in the room
 * and without multiplying model calls by participants × NPCs.
 */
function ai_social_quest_reply_is_primary(array $npc,array $job,string $reply,array $actions):bool {
    $primary=(int)($job['user_id']??0);if($primary<1)return false;
    $ids=array_values(array_unique(array_filter(array_map(static fn($action)=>($action['kind']??'')==='pg'?(int)($action['actor_id']??0):0,$actions))));
    $targets=[];
    if($ids)foreach(ai_db()->query('SELECT id,nome FROM utenti WHERE id IN('.implode(',',$ids).')') as $person)if((int)$person['id']!==$primary)$targets[]=(string)$person['nome'];
    foreach(ai_db()->query('SELECT id,name FROM ai_npcs WHERE BINARY chat_key=BINARY ? AND active=1 LIMIT 24',[(string)$job['chat_key']]) as $other)if((int)$other['id']!==(int)$npc['id'])$targets[]=(string)$other['name'];
    foreach(ai_narrative_parse($reply,['channel'=>'action'])['segments'] as $part){
        $text=(string)($part['text']??'');
        foreach($targets as $name){
            $forms=array_unique([$name,explode(' ',$name)[0]]);
            foreach($forms as $form)if($form!==''){
                $literal=preg_quote($form,'/');
                if(($part['kind']??'')==='speech'&&preg_match('/^\\s*'.$literal.'\\s*[,!:]/iu',$text))return false;
                if(($part['kind']??'')==='action'&&preg_match('/\\b(?:si\\s+rivolge|parla|risponde|domanda|chiede)\\s+(?:ad?|al|alla)\\s+'.$literal.'\\b/iu',$text))return false;
            }
        }
    }
    return true;
}
function ai_social_record_quest_scene(array $npc,array $job,array $result,array $actions):bool {
    $db=ai_db();if(!$db->inTransaction())throw new LogicException('Quest publication transaction required.');
    $nid=(int)$npc['id'];$primary=(int)($job['user_id']??0);$chat=(string)$job['chat_key'];$seen=[];$primaryRecorded=false;
    $replyIsPrimary=ai_social_quest_reply_is_primary($npc,$job,(string)$result['testo'],$actions);
    foreach(array_slice($actions,0,16) as $action){
        $uid=(int)($action['actor_id']??0);$source=(int)($action['source_id']??0);
        if(($action['kind']??'')!=='pg'||$uid<1||$source<1||isset($seen[$uid]))continue;$seen[$uid]=true;
        $public=$db->query('SELECT pg,chat,dest'.(alphano_chat_has_archive()?',history_only':'').' FROM chat WHERE id=?',[$source])[0]??null;
        if(!$public||(int)$public['pg']!==$uid||(int)$public['dest']!==0||$public['chat']!==$chat||!empty($public['history_only']))continue;
        $copy=[...$job,'kind'=>'npc-turn','user_id'=>$uid,'source_id'=>$source,'prompt'=>(string)$action['content']];
        if(!ai_relation_context_job_current($copy))continue;
        if($uid===$primary&&$replyIsPrimary){ai_social_record($npc,$copy,$result);$primaryRecorded=true;continue;}
        unset($copy['episode']);
        if(ai_recognition_anonymous($npc,$copy))continue;
        $now=time();$db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$uid,$now,$now]);
        $db->query('SELECT npc_id FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$uid]);
        if(!ai_relation_context_job_current($copy))continue;
        if($db->query("SELECT id FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND JSON_EXTRACT(after_state,'$._quest_observation.source_id')=? LIMIT 1",[$nid,$uid,$source]))continue;
        if($db->query("SELECT m.id FROM ai_personal_memories m JOIN ai_jobs j ON j.id=m.job_id WHERE m.npc_id=? AND m.player_id=? AND m.category='incontro' AND j.source_id=? LIMIT 1",[$nid,$uid,$source]))continue;
        // A targeted action can have immediate bounded consequences, even if another
        // PG is the technical focus of this NPC turn. Unaddressed witnessing is memory.
        $details=is_array($action['details']??null)?$action['details']:(json_decode((string)($action['details']??'{}'),true)?:[]);
        $direct=($details['target_kind']??'')==='npc'&&(int)($details['target_id']??0)===$nid;
        if($direct||ai_narrative_mentions((string)$action['content'],(string)$npc['name']))ai_episode_prepare($npc,[...$copy,'id'=>0]);
        $before=ai_relation($nid,$uid);$after=$before;$after['encounters']+=(int)((int)$before['last_encounter']<$now-1800);$after['interactions']++;$after['last_encounter']=$now;$after['updated_at']=$now;
        $observed=ai_personality_observable((string)$action['content']);$name=ai_player_identity($uid)['nome']??'';
        if(ai_identity_introduction($name,(string)$action['content']))$after['known_name']=$name;
        $db->execute('UPDATE ai_relationships SET encounters=?,interactions=?,last_encounter=?,updated_at=?,known_name=? WHERE npc_id=? AND player_id=?',[$after['encounters'],$after['interactions'],$now,$now,$after['known_name'],$nid,$uid]);
        $summary='Ha assistito al turno pubblico di questo personaggio nella scena: '.ai_text($observed,600).'. È un ricordo osservato: tentativi e parole non provano colpi riusciti, intenzioni o sentimenti. La replica del PNG nella scena non è automaticamente rivolta a questo personaggio.';
        $db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,epistemic,visible,created_at,updated_at) VALUES(?,?,?,'incontro',?,?,'dialogue','dialogue',1,?,?)",[$nid,$uid,$chat,$summary,$observed,$now,$now]);
        $record=$after;$record['_quest_observation']=['source_id'=>$source,'session_id'=>(int)($job['session_id']??0),'reply_job_id'=>(int)($job['id']??0)];
        $db->execute('INSERT INTO ai_relationship_events(npc_id,player_id,before_state,after_state,reason,created_at) VALUES(?,?,?,?,?,?)',[$nid,$uid,json_encode($before),json_encode($record),'Turno pubblico osservato nella quest: memoria personale per la coppia, senza attribuire a tutti la stessa replica.',$now]);
    }
    return $primaryRecorded;
}
/** Safe, evidence-linked and rate-limited application of model proposals. Caller holds transaction. */
function ai_social_apply(array $npc,array $job,array $proposal): void {
    if(ai_recognition_anonymous($npc,$job))return;
    $db=ai_db();$id=(int)$job['id'];$nid=(int)$npc['id'];$uid=(int)$job['user_id'];$now=time();
    if($db->query('SELECT id FROM ai_relationship_events WHERE job_id=?',[$id]))return;
    $before=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$uid])[0]??null;if(!$before)return;
    if(!ai_relation_context_job_current($job))return;
    $rules=ai_social_rules($nid);$player=ai_player_identity($uid);$romance=ai_romance_allowed($npc,$player,$rules);
    $reply=json_decode($job['result'],true)['testo']??'';
    $pe=ai_text((string)($proposal['player_evidence']??''),250);$ne=ai_text((string)($proposal['npc_evidence']??''),250);
    $episode=ai_episode_previous($job,$nid);
    $grounded=mb_strlen($pe)>=(!empty($episode['known'])?3:8)&&mb_strlen($ne)>=(ai_social_reciprocal_interest($ne)?5:8)&&ai_social_evidence_matches($pe,(string)$job['prompt'])&&ai_social_evidence_matches($ne,$reply);
    $deltas=is_array($proposal['deltas']??null)?$proposal['deltas']:[];
    if(!empty($episode['observer']))$deltas=ai_social_observer_deltas($deltas,$reply);
    $category=ai_social_episode_category(is_string($proposal['category']??null)?$proposal['category']:'dialogo',$pe,$ne,$deltas);
    if(!empty($episode['observer'])&&!$deltas)$category='dialogo';
    $day=$db->query('SELECT before_state,after_state,created_at FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND actor_id=0 AND created_at>?'.ai_relation_context_event_scope($nid,$uid).' ORDER BY id DESC LIMIT 500',[$nid,$uid,$now-86400]);
    $recent=0;
    foreach($day as &$event){
        $a=json_decode($event['after_state'],true)?:[];$b=json_decode($event['before_state'],true)?:[];$event['_decoded_after']=$a;$event['_decoded_before']=$b;
        if((int)$event['created_at']>$now-300&&!empty($a['_evolution']['meaningful']))$recent++;
    }unset($event);
    $priorEvidence=array_filter($day,static fn($event)=>empty($event['_decoded_after']['_episode'])||(int)$event['_decoded_after']['_episode']['source_id']!==(int)($job['source_id']??0));
    $repeated=$grounded&&ai_social_repeated_evidence($pe,$priorEvidence);
    $significant=$grounded&&$category!=='dialogo'&&!$repeated&&count($day)<500;
    $meaningful=$significant&&(!empty($episode['counted'])||$recent<(int)$rules['meaningful_window_limit']);
    $after=$before;
    foreach(AI_RELATION_METRICS as $key=>$_){
        $raw=isset($episode['changes'][$key])?0:($deltas[$key]??0);
        $delta=ai_social_bounded_delta($key,is_int($raw)?$raw:0,$category,$before,$rules,$significant,$meaningful,$romance,$ne,$day);
        $min=in_array($key,['sympathy','trust','respect','honor'],true)?-100:0;
        $after[$key]=max($min,min(100,(int)$before[$key]+$delta));
    }
    $after['meaningful']=(int)$before['meaningful']+(int)($meaningful&&empty($episode['counted']));
    $state=$proposal['state']??$before['state'];
    $stateAllowed=$meaningful && empty($episode['observer']) && in_array($state,AI_RELATION_STATES,true);
    $edges=['conoscenza'=>['amicizia','interesse'],'amicizia'=>['interesse','conoscenza'],'interesse'=>['corteggiamento','amicizia','conoscenza'],'corteggiamento'=>['innamoramento','amicizia','separazione'],'innamoramento'=>['relazione','separazione','crisi'],'relazione'=>['crisi','separazione'],'crisi'=>['separazione','riconciliazione'],'separazione'=>['riconciliazione','amicizia'],'riconciliazione'=>['relazione','crisi','separazione']];
    if(!in_array($state,$edges[$before['state']]??[],true))$stateAllowed=false;
    if($state==='amicizia'&&($after['meaningful']<2||(int)$before['encounters']<1||(int)$after['trust']<4||(int)$after['hostility']>=20))$stateAllowed=false;
    if($state==='amicizia'&&!in_array($category,['cordialita','rassicurazione','favore','confidenza','riconoscenza','promessa','riconciliazione'],true))$stateAllowed=false;
    $reciprocal=$meaningful&&$romance&&(int)$before['fear']<(int)$rules['romance_fear_limit']&&(int)$before['hostility']<20&&in_array($category,['corteggiamento','riconciliazione'],true)&&ai_social_reciprocal_interest($ne);
    $romanticEpisodes=$reciprocal?(int)$db->query("SELECT COUNT(*) AS n FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND actor_id=0 AND JSON_EXTRACT(after_state,'$._evolution.romantic')=true".ai_relation_context_event_scope($nid,$uid),[$nid,$uid])[0]['n']+1:0;
    $episodes=(int)$after['meaningful'];
    if(in_array($state,['interesse','corteggiamento','innamoramento','relazione','riconciliazione'],true)&&(!$romance||$episodes<2||(int)$before['encounters']<1))$stateAllowed=false;
    if(in_array($state,['interesse','corteggiamento','innamoramento','relazione'],true)&&!$reciprocal)$stateAllowed=false;
    if($state==='corteggiamento'&&$romanticEpisodes<2)$stateAllowed=false;
    if(in_array($state,['innamoramento','relazione'],true)&&($romanticEpisodes<(int)$rules['romance_episodes_min']+($state==='relazione'?2:0)||(int)$after['trust']<10||(int)$after['hostility']>=20))$stateAllowed=false;
    // A change needs a relevant narrative event, not merely a number threshold.
    if(in_array($state,['interesse','corteggiamento','innamoramento','relazione'],true)&&$category!=='corteggiamento')$stateAllowed=false;
    if($state==='separazione'&&!in_array($category,['separazione','rifiuto','tradimento'],true))$stateAllowed=false;
    if($state==='riconciliazione'&&$category!=='riconciliazione')$stateAllowed=false;
    if($stateAllowed)$after['state']=$state;
    $revealed=json_decode($before['revealed'],true)?:[];
    // Model output is not required to rediscover a fear already visible in the published body.
    if($grounded)$revealed=[...$revealed,...ai_social_pair_manifestations($npc,$job,$reply)];
    $after['revealed']=json_encode(array_values(array_unique($revealed)));$after['updated_at']=$now;
    $keys=[...array_keys(AI_RELATION_METRICS),'state','meaningful','revealed','updated_at'];
    $db->execute('UPDATE ai_relationships SET '.implode(',',array_map(static fn($k)=>$k.'=?',$keys)).' WHERE npc_id=? AND player_id=?',[...array_map(static fn($k)=>$after[$k],$keys),$nid,$uid]);
    $reason=$grounded?($meaningful?'Evoluzione da episodio significativo · ':($repeated?'Evidenza già registrata, nessun incremento · ':($significant?'Storia conservata, limite di crescita della scena · ':'Dialogo registrato senza variazione · '))).$category.': '.$pe.' / '.$ne:'Nessun cambiamento: proposta priva di riscontro testuale osservabile.';
    $eventAfter=$after;$eventAfter['_evolution']=['category'=>$category,'evidence'=>$grounded?ai_social_evidence_key($pe):'','meaningful'=>$meaningful&&empty($episode['counted']),'romantic'=>$reciprocal,'source_id'=>(int)($job['source_id']??0)];
    ai_relation_event($nid,$uid,$before,$eventAfter,$reason,$job,$id);
    if($significant)$db->execute('INSERT IGNORE INTO ai_personal_memories(npc_id,player_id,chat_key,job_id,category,summary,evidence,source,epistemic,created_at,updated_at) VALUES(?,?,?,?,?,?,?,\'interpreted\',\'interpretation\',?,?)',[$nid,$uid,$job['chat_key'],$id,$category,'Percezione maturata da parole/tentativi del PG: «'.$pe.'». Risposta di '.$npc['name'].': «'.$ne.'». Non certifica esiti o veridicità dei racconti.',$pe.' / '.$ne,$now,$now]);
    ai_social_development_record($npc,$job,$before,$after,$rules,$significant,$pe,$ne,$grounded,$category);
    $money=$proposal['money']??[];
    if($grounded&&is_array($money)&&isset($money['kind'],$money['amount'])&&is_int($money['amount'])&&$money['amount']>0&&preg_match('/(?<![0-9])'.$money['amount'].'\\s+coron[ae]\\b/iu',$reply)){
        try{ai_money_offer($npc,$uid,(string)$money['kind'],$money['amount'],ai_text((string)($money['reason']??'Proposta del PNG'),400),'job:'.$id);}catch(DomainException){/* A rejected proposal never turns into a transfer. */}
    }
}
function ai_npc_balance(int $npc): int {return (int)(ai_db()->query('SELECT balance FROM ai_npc_wallets WHERE npc_id=?',[$npc])[0]['balance']??0);}
function ai_money_offer(array $npc,int $player,string $kind,int $amount,string $reason,string $key): int {
    $rules=ai_social_rules((int)$npc['id']);
    if(!$rules['economy_enabled']||$amount<1||$amount>(int)$rules['max_amount']||!in_array($kind,['payment_to_npc','payment_to_player','gift_to_npc','gift_to_player','loan_to_player','loan_to_npc','repay_player','repay_npc'],true))throw new DomainException('Proposta economica non consentita.',422);
    if(str_starts_with($kind,'loan_')&&!$rules['allow_loans'])throw new DomainException('Questo PNG non concede prestiti.',422);
    $db=ai_db();$db->execute('INSERT IGNORE INTO ai_npc_offers(npc_id,player_id,kind,amount,reason,request_key,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?)',[(int)$npc['id'],$player,$kind,$amount,$reason,$key,time(),time()+3600]);
    return (int)$db->query('SELECT id FROM ai_npc_offers WHERE request_key=?',[$key])[0]['id'];
}
/** Validated wallet transfer, atomic ledger/debt receipt and durable idempotency. */
function ai_money_transfer(int $npc,int $player,string $kind,int $amount,string $reason,string $key,int $actor,?int $offerId=null): array {
    $db=ai_db();if(!$db->inTransaction())throw new LogicException('Transazione richiesta.');
    $old=$db->query('SELECT * FROM ai_npc_transactions WHERE request_key=?',[$key])[0]??null;if($old){if((int)$old['npc_id']!==$npc||(int)$old['player_id']!==$player||$old['kind']!==$kind||(int)$old['amount']!==$amount)throw new DomainException('Identificativo già usato per un movimento diverso.',409);return $old;}
    if($amount<1||$amount>1000000000)throw new DomainException('Importo non valido.',422);
    // Every transaction locks the player before the wallet, including admin funding.
    $pg=$db->query('SELECT denari FROM utenti WHERE id=? FOR UPDATE',[$player])[0]??null;if(!$pg)throw new DomainException('Personaggio non disponibile.',404);
    $db->execute('INSERT IGNORE INTO ai_npc_wallets(npc_id,balance,updated_at) VALUES(?,0,?)',[$npc,time()]);
    $wallet=$db->query('SELECT balance FROM ai_npc_wallets WHERE npc_id=? FOR UPDATE',[$npc])[0];
    $old=$db->query('SELECT * FROM ai_npc_transactions WHERE request_key=? FOR UPDATE',[$key])[0]??null;if($old){if((int)$old['npc_id']!==$npc||(int)$old['player_id']!==$player||$old['kind']!==$kind||(int)$old['amount']!==$amount)throw new DomainException('Identificativo già usato per un movimento diverso.',409);return $old;}
    $toPlayer=in_array($kind,['payment_to_player','gift_to_player','loan_to_player','repay_player','withdraw','reversal_to_player'],true);
    if(!in_array($kind,['payment_to_npc','payment_to_player','gift_to_npc','gift_to_player','loan_to_player','loan_to_npc','repay_player','repay_npc','fund','withdraw','theft_resolved','reversal_to_player','reversal_to_npc'],true))throw new DomainException('Movimento non valido.',422);
    $rules=ai_social_rules($npc);$admin=in_array($kind,['fund','withdraw','theft_resolved','reversal_to_player','reversal_to_npc'],true);
    if(!$admin){
        if(!$rules['economy_enabled']||$amount>(int)$rules['max_amount'])throw new DomainException('Limite del PNG superato.',422);
        $movements=$db->query('SELECT amount FROM ai_npc_transactions WHERE npc_id=? AND kind NOT IN(\'fund\',\'withdraw\',\'theft_resolved\',\'reversal_to_player\',\'reversal_to_npc\') AND created_at>? FOR UPDATE',[$npc,time()-86400]);$spent=array_sum(array_column($movements,'amount'));
        if($spent+$amount>(int)$rules['daily_limit'])throw new DomainException('Limite giornaliero raggiunto.',409);
        if(str_starts_with($kind,'loan_')&&!$rules['allow_loans'])throw new DomainException('Prestiti disabilitati.',422);
    }
    $db->execute('INSERT IGNORE INTO ai_npc_debts(npc_id,player_id,updated_at) VALUES(?,?,?)',[$npc,$player,time()]);
    $debt=$db->query('SELECT * FROM ai_npc_debts WHERE npc_id=? AND player_id=? FOR UPDATE',[$npc,$player])[0];
    if($kind==='repay_npc'&&$amount>(int)$debt['player_owes'])throw new DomainException('Importo superiore al debito del personaggio.',422);
    if($kind==='repay_player'&&$amount>(int)$debt['npc_owes'])throw new DomainException('Importo superiore al debito del PNG.',422);
    $pb=(int)$pg['denari'];$nb=(int)$wallet['balance'];$delta=$toPlayer?$amount:-$amount;
    if($pb+$delta<0||$nb-$delta<0)throw new DomainException(alphano_currency_text('Corone insufficienti: nessun movimento eseguito.'),409);
    if($pb+$delta>1000000000000||$nb-$delta>1000000000000)throw new DomainException('Saldo massimo superato.',422);
    $db->execute('UPDATE utenti SET denari=? WHERE id=?',[$pb+$delta,$player]);$db->execute('UPDATE ai_npc_wallets SET balance=?,updated_at=? WHERE npc_id=?',[$nb-$delta,time(),$npc]);
    $po=(int)$debt['player_owes']+($kind==='loan_to_player'?$amount:0)-($kind==='repay_npc'?$amount:0);
    $no=(int)$debt['npc_owes']+($kind==='loan_to_npc'?$amount:0)-($kind==='repay_player'?$amount:0);
    $db->execute('UPDATE ai_npc_debts SET player_owes=?,npc_owes=?,updated_at=? WHERE npc_id=? AND player_id=?',[$po,$no,time(),$npc,$player]);
    $db->execute('INSERT INTO ai_npc_transactions(npc_id,player_id,offer_id,kind,amount,player_delta,npc_before,npc_after,player_before,player_after,reason,request_key,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$npc,$player,$offerId,$kind,$amount,$delta,$nb,$nb-$delta,$pb,$pb+$delta,$reason,$key,$actor,time()]);
    if(!$admin)$db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,epistemic,created_at,updated_at) SELECT ?,?,chat_key,'transazione',?,?,'system','verified',?,? FROM ai_npcs WHERE id=?",[$npc,$player,($toPlayer?'Il PNG ha consegnato al personaggio ':'Il personaggio ha consegnato al PNG ').$amount.alphano_currency_text(' Corone. Causale: ').$reason,'Movimento verificato '.$key,time(),time(),$npc]);
    // Profile's historical field is a read-through mirror, never a second wallet.
    $row=$db->query('SELECT profile FROM ai_npcs WHERE id=? FOR UPDATE',[$npc])[0];$profile=json_decode($row['profile'],true)?:[];$profile['money']=$nb-$delta;
    $db->execute('UPDATE ai_npcs SET profile=? WHERE id=?',[json_encode($profile,JSON_UNESCAPED_UNICODE),$npc]);
    return $db->query('SELECT * FROM ai_npc_transactions WHERE request_key=?',[$key])[0];
}

/** Same shared model and worker; reflection waits until ordinary chat jobs are served. */
function ai_social_work_once(): bool {
    $db=ai_db();$now=time();
    $db->execute("UPDATE ai_social_jobs SET status='queued',available_at=? WHERE status='running' AND started_at<?",[$now,$now-ai_deadline()-30]);
    $task=$db->query("SELECT s.*,j.npc_id,j.user_id,j.chat_key,j.kind,j.prompt,j.result,j.source_id FROM ai_social_jobs s JOIN ai_jobs j ON j.id=s.job_id WHERE s.status='queued' AND s.attempts<3 AND s.available_at<=? ORDER BY s.job_id LIMIT 1",[$now])[0]??null;
    if(!$task)return false;
    $task=ai_social_observation_job($task);
    $id=(int)$task['job_id'];
    if(!ai_relation_context_job_current([...$task,'id'=>$id])){$db->execute("UPDATE ai_social_jobs SET status='cancelled',error='Il contesto della coppia è stato ridefinito dallo staff.' WHERE job_id=? AND status='queued'",[$id]);return true;}
    $db->beginTransaction();
    try{
        $state=$db->query('SELECT status FROM ai_social_jobs WHERE job_id=? FOR UPDATE',[$id])[0]['status']??'';
        if($state!=='queued'){$db->commit();return true;}
        $db->execute("UPDATE ai_social_jobs SET status='running',started_at=?,attempts=attempts+1 WHERE job_id=?",[$now,$id]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    try{
        $npc=$db->query('SELECT * FROM ai_npcs WHERE id=?',[(int)$task['npc_id']])[0]??null;
        if(!$npc)throw new DomainException('PNG non più disponibile.');
        $rel=ai_relation((int)$npc['id'],(int)$task['user_id']);$rules=ai_social_rules((int)$npc['id']);
        $text=json_decode($task['result'],true)['testo']??'';
        $playerParts=ai_social_segments($task['prompt']);$npcParts=ai_social_segments($text);
        $messages=ai_social_reflection_messages($npc,$rel,$rules,$playerParts,$npcParts,ai_romance_allowed($npc,ai_player_identity((int)$task['user_id']),$rules));
        ai_heartbeat('generating');
        $response=ai_runtime('/v1/chat/completions',['model'=>ai_model_name(),'messages'=>$messages,'temperature'=>0.1,'max_tokens'=>512,'stream'=>false,'cache_prompt'=>false,'grammar'=>ai_social_proposal_grammar(count($playerParts),count($npcParts)),'chat_template_kwargs'=>['enable_thinking'=>false]]);
        $proposal=ai_social_proposal_decode($response);
        $pi=$proposal['player_evidence_id']??0;$ni=$proposal['npc_evidence_id']??0;
        $proposal['player_evidence']=is_int($pi)?($playerParts[$pi]??''):'';
        $proposal['npc_evidence']=is_int($ni)?($npcParts[$ni]??''):'';
        $db->beginTransaction();
        $state=$db->query('SELECT status FROM ai_social_jobs WHERE job_id=? FOR UPDATE',[$id])[0]['status']??'';
        if($state!=='running'){$db->commit();ai_heartbeat('idle');return true;}
        // Use the current profile too: an age/identity edit during inference takes effect now.
        $npc=$db->query('SELECT * FROM ai_npcs WHERE id=?',[(int)$task['npc_id']])[0]??null;
        if(!$npc)throw new DomainException('PNG non più disponibile.');
        $job=['id'=>$id,...array_intersect_key($task,array_flip(['npc_id','user_id','chat_key','source_id','prompt','result']))];
        ai_social_apply($npc,$job,$proposal);
        $db->execute("UPDATE ai_social_jobs SET status='done',proposal=?,error='' WHERE job_id=?",[json_encode($proposal,JSON_UNESCAPED_UNICODE),$id]);$db->commit();
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        $last=(int)$task['attempts']>=2||$error instanceof DomainException;
        $db->execute("UPDATE ai_social_jobs SET status=?,available_at=?,error=? WHERE job_id=? AND status='running'",[$last?'failed':'queued',time()+30,'Riflessione non applicata; dialogo e memoria letterale conservati.',$id]);
        error_log('[PNG riflessione '.$id.'] '.$error->getMessage());
    }
    ai_heartbeat('idle');return true;
}

/** A quest reflection reads the published player's action, never internal Master instructions. */
function ai_social_observation_job(array $task): array {
    if(($task['kind']??'')!=='npc-turn')return $task;
    $observation=json_decode((string)($task['observation']??''),true);
    if(!is_array($observation)||!is_string($observation['prompt']??null)||!is_string($observation['result']??null)){
        // Legacy quest jobs without a captured source cannot establish social evidence.
        $task['prompt']='';$task['result']='{}';return $task;
    }
    $task['prompt']=$observation['prompt'];$task['result']=$observation['result'];
    $task['source_id']=(int)($observation['source_id']??0);return $task;
}
function ai_social_private_view(int $npc,int $viewer): array {
    $rules=ai_social_rules($npc);$r=ai_relation($npc,$viewer);$revealed=json_decode($r['revealed'],true)?:[];
    $character=ai_db()->query('SELECT * FROM ai_npcs WHERE id=?',[$npc])[0]??null;$player=ai_player_identity($viewer);
    $romance=$character&&$player&&ai_romance_allowed($character,$player,$rules);
    // This authenticated view is the player's own scorecard. Hidden thoughts and
    // private memories stay gated separately; an untouched pair really starts at 0.
    $metrics=[];foreach(AI_RELATION_METRICS as $key=>$label)$metrics[$key]=['label'=>$label,'value'=>(int)$r[$key],'min'=>in_array($key,['sympathy','trust','respect','honor'],true)?-100:0];
    return ['metrics'=>$metrics,'state'=>$r['state'],'encounters'=>(int)$r['encounters'],
        'interactions'=>(int)$r['interactions'],'meaningful'=>(int)$r['meaningful'],'bond'=>ai_relation_summary($r,$romance),
        'development'=>ai_social_development_view($npc,$viewer),
        'memories'=>ai_db()->query('SELECT id,category,summary,source,created_at FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND visible=1 AND active=1 ORDER BY id DESC LIMIT 30',[$npc,$viewer]),
        'offers'=>ai_db()->query("SELECT id,kind,amount,reason,expires_at FROM ai_npc_offers WHERE npc_id=? AND player_id=? AND status='pending' AND expires_at>? ORDER BY id DESC",[$npc,$viewer,time()]),
        'debts'=>ai_db()->query('SELECT player_owes,npc_owes FROM ai_npc_debts WHERE npc_id=? AND player_id=?',[$npc,$viewer])[0]??['player_owes'=>0,'npc_owes'=>0],
        'transactions'=>ai_db()->query('SELECT id,kind,amount,player_delta,reason,created_at FROM ai_npc_transactions WHERE npc_id=? AND player_id=? ORDER BY id DESC LIMIT 20',[$npc,$viewer]),
        'preferences'=>ai_db()->query('SELECT character_age,romance_enabled,intimacy_enabled FROM ai_player_preferences WHERE player_id=?',[$viewer])[0]??['character_age'=>0,'romance_enabled'=>0,'intimacy_enabled'=>0]];
}

/** Institutions and duties follow only the configured game state. */
function ai_guard_respects(array $npc,int $playerId,int $before=0): bool {
    $rules=ai_social_rules((int)$npc['id']);$p=ai_player_identity($playerId);
    $authority=ai_npc_authority((int)$npc['id'],$playerId,['chat_key'=>$npc['chat_key']??'','source_id'=>$before]);
    if($authority['recognized'])return ($authority['attitude']['mode']??'respectful')==='respectful';
    if(!$p||trim($rules['allegiance'])===''||!preg_match('/guardia|presidio|sergente/iu',$rules['duty']))return false;
    return !empty($rules['recognize_vampires'])&&in_array(ai_perception_known_race((int)$npc['id'],$playerId,$before),[ALPHANO_RACE_VAMPIRE,ALPHANO_RACE_ORIGINAL],true);
}
function ai_guard_validate_text(array $npc,array $job,string $text): string {
    if(!ai_guard_respects($npc,(int)$job['user_id'],(int)($job['source_id']??0)))return $text;
    $raw=(string)($job['prompt']??'');
    if(in_array(ai_narrative_parse($raw)['intent'],['assalto','potere','arresto','furto'],true))return $text;
    // Civic rank never grants automatic entry into archives, private rooms or other restricted spaces.
    if(preg_match('/archiv|riservat|privat|segreto|permesso scritto/iu',$raw))return $text;
    if(preg_match('/accesso (?:negato|vietato)|non (?:potete|puoi|può)\s+(?:entrar|passar|acceder)|non (?:posso lasciar|vi lascer|ti lascer|passer)|(?:vieto|nego|negare|negato|impedisco).{0,35}(?:accesso|ingresso|passaggio)|(?:devi|dovete) (?:restare fuori|attendere|aspettare)|(?:tornate|torna) indietro|(?:chi sei|chi siete|identificati|identificatevi)|(?:ditemi|mi dia|dimmi|dichiara|fornisci|favorite|mostrate|dimostrate|presentati).{0,45}(?:nome|provenienza|document|permess)|non ho tempo per|cosa vi porta qui/iu',$text)) {
        $scope=ai_authority_npc_scope($npc);
        if(trim((string)$scope['city'])==='')throw new RuntimeException('Manca il territorio dell’incarico verificato.');
        if(isset($job['prompt'])&&!preg_match('/\b(?:entrar\w*|acced\w*|accesso|ingresso|passaggio|castello|corte|salve|saluto|buonasera|buongiorno)\b/iu',$job['prompt']))throw new RuntimeException('Il presidio deve rispondere alla richiesta attuale rispettando il rango: nuovo tentativo.');
        return $npc['name'].' riconosce il rango dell’interlocutore e si scosta dal varco, mantenendo un contegno rispettoso. «Il passaggio è libero. Desiderate che annunci il vostro arrivo alla corte?» La guardia resta al proprio posto, in attesa della risposta.';
    }
    return $text;
}

function ai_social_segments(string $text): array {
    $observable=[];
    if(function_exists('ai_narrative_parse')){
        foreach(ai_narrative_parse($text)['segments']??[] as $part)if(in_array($part['kind']??'',['speech','action'],true))$observable[]=(string)($part['text']??'');
    }else $observable=[$text];
    $result=[];
    foreach($observable as $chunk){
        $parts=preg_split('/(?<=[.!?])\\s+|[«»]/u',$chunk,-1,PREG_SPLIT_NO_EMPTY);
        foreach($parts as $part){$part=trim($part);if(mb_strlen($part)<8&&!ai_social_reciprocal_interest($part))continue;$result[count($result)+1]=mb_substr($part,0,240);if(count($result)>=8)break 2;}
    }
    return $result;
}

/** Evidence must occur in an observable segment, never just somewhere in a hidden thought. */
function ai_social_evidence_matches(string $evidence,string $raw): bool {
    $normalize=static fn($text)=>trim(preg_replace('/\\s+/u',' ',html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
    $needle=$normalize($evidence);if($needle==='')return false;
    foreach(ai_social_segments($raw) as $part)if(str_contains($normalize($part),$needle))return true;
    return false;
}
