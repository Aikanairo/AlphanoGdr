<?php
declare(strict_types=1);
require_once __DIR__.'/ai-coercion.inc.php';

/** Incoming conduct is perceived before a reply. Consent and reciprocation belong to the NPC. */
function ai_episode_interpret(string $raw):array {
    $reading=ai_narrative_parse($raw);$spoken=$reading['speech'];$action=$reading['action'];
    // A sending-channel prefix cannot hide direct addressed words. Recover only
    // obvious utterances, never a physical attempt, thought or reported action.
    if($spoken===''&&$action!==''&&!in_array($reading['intent'],['assalto','arresto','furto','potere'],true)&&preg_match('/^(?:avete|hai|volete|vuoi|decidete|obbedisci|obbedite|da\s+oggi|se\s+non)\b/iu',$action))$spoken=$action;
    $episode=['category'=>'dialogo','evidence'=>'','reading'=>$reading,'known'=>false,'help_offer'=>(bool)preg_match('/\b(?:posso|vorrei|voglio|desidero)\s+aiutar(?:ti|vi)\b/iu',$spoken),'invitation'=>'','aggression'=>false];
    $speech=preg_split('/(?<=[.!?;])\s+/u',$spoken,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $patterns=[
        'minaccia'=>'/\b(?:ti|vi)\s+(?:ucciderò|ammazzerò|farò\s+(?:del\s+)?male|spezzerò|distruggerò)|\b(?:ucciderò|ammazzerò|farò\s+(?:del\s+)?male\s+a)\b|\b(?:obbedire\s+o\s+morire|obbedisci\s+o\s+(?:muori|ti\s+uccido)|obbedite\s+o\s+(?:morite|vi\s+uccido))\b|\b(?:ti\s+uccido|vi\s+uccido)\b|\b(?:se\s+non\s+(?:obbedisci|obbedite))\b.{0,80}\b(?:morir|uccid|ammazz)\p{L}*/iu',
        'offesa'=>'/\b(?:ti|vi)\s+(?:disprezzo|odio|umilierò)|\b(?:sei|siete)\b.{0,35}\b(?:spregevole|inutile|patetic[oa]|disgustos[oa]|idiot[ai]|stupid[oa]|imbecille|bugiard[oa]|miserabile)\b|\bil\s+vostro\s+mestiere\s+non\s+vale\s+niente\b/iu',
        'critica'=>'/\bmi\s+(?:avete|hai)\s+deluso\b|\bnon\s+mi\s+fido\s+(?:affatto\s+|più\s+)?(?:di\s+(?:te|voi)|delle\s+(?:tue|vostre)\s+parole)\b|\b(?:trovo|ritengo)\s+(?:il\s+)?(?:vostro|tuo)\s+(?:atteggiamento|comportamento)\s+(?:arrogante|scorretto|sgradevole|irrispettoso)\b/iu',
        'riconciliazione'=>'/\b(?:chiedo\s+scusa|chiedo\s+perdono|mi\s+dispiace|ho\s+sbagliato|sono\s+pentit[oa])\b/iu',
        'rassicurazione'=>'/\bnon\s+(?:voglio|intendo|cercherò\s+di)\s+(?:farti|farvi|ferirti|ferirvi|colpirvi|colpirti|nuocerti|nuocervi|aggredirti|aggredirvi)|\bnon\s+(?:ti|vi)\s+farò\s+(?:del\s+)?male|\brispetto\b.{0,40}\b(?:paura|distanza|scelta)\b/iu',
        'corteggiamento'=>'/\b(?:mi\s+piaci|mi\s+piacete|ti\s+amo|vi\s+amo|sono\s+attratt[oa]\s+da\s+(?:te|voi)|vorrei\s+corteggiar(?:ti|vi)|posso\s+(?:tenerti|tenervi)\s+la\s+mano)|\b(?:siete|sei)\s+(?:una\s+donna\s+)?(?:molto\s+)?bell[ao]|\b(?:vorrei|desidero)\b.{0,45}\b(?:conoscerti|conoscervi|rivederti|rivedervi)\b/iu',
        'confidenza'=>'/\b(?:ti|vi)\s+(?:confido|racconto|affido)\b|\b(?:da\s+(?:ragazz[oa]|bambin[oa])|anch[’\x{27}]io|anche\s+io)\b.{0,100}\b(?:temevo|paura|rinunciat|fratello|sorella|ridevano|perdere)|\bcon\s+(?:te|voi)\s+mi\s+sento\s+(?:ascoltat[oa]|al\s+sicuro|capit[oa])/iu',
        'cordialita'=>'/\b(?:vi|ti)\s+ascolto\s+senza\s+giudicare|\bnon\s+voglio\s+giudicar(?:ti|vi)|\bmi\s+piace\s+ascoltare\s+la\s+persona|\b(?:posso|vorrei)\s+aiutar(?:ti|vi)\b|\brispetto\s+(?:il\s+vostro|il\s+tuo)\s+(?:bisogno|desiderio)\b|\b(?:grazie|(?:vi|ti)\s+ringrazio)\s+per\b|\bapprezzo\b.{0,70}\b(?:gentilezza|aiuto|ascolto|pazienza|lavoro|accoglienza|compagnia)\b|\b(?:sei|siete)\s+(?:davvero\s+|molto\s+)?(?:gentile|premuros[oa]|cordiale)\b|\b(?:mi\s+fa\s+piacere|è\s+un\s+piacere)\s+(?:parlare|conversare|stare)\s+con\s+(?:te|voi)\b/iu'
    ];
    // A narrated attempt can cause fear; it cannot establish a successful hit.
    if(in_array($reading['intent'],['assalto','arresto','furto'],true))return [...$episode,'category'=>'minaccia','evidence'=>ai_text($action,250),'known'=>true,'aggression'=>in_array($reading['intent'],['assalto','arresto'],true)];
    if(preg_match('/\b(?:urla|grida|ringhia)\b.{0,100}\b(?:intimor|minacci|spavent)\p{L}*/iu',$action))return [...$episode,'category'=>'minaccia','evidence'=>ai_text($action,250),'known'=>true];
    foreach($patterns as $category=>$pattern)foreach($speech as $sentence){
        if(preg_match('/\b(?:ha\s+detto|diceva|racconta\s+che)\b/iu',$sentence))continue;
        if(preg_match('/\b(?:non\s+(?:ti|vi)\s+(?:odio|disprezzo|umilierò|ucciderò|ammazzerò|uccido|ammazzo)|non\s+(?:sei|siete))\b/iu',$sentence)&&in_array($category,['minaccia','offesa'],true))continue;
        if($category==='cordialita'&&preg_match('/\bnon\s+(?:sei|siete|vi\s+ringrazio|ti\s+ringrazio|mi\s+fa\s+piacere|apprezzo)\b/iu',$sentence))continue;
        if($category==='riconciliazione'&&preg_match('/non\s+(?:vi\s+|ti\s+)?(?:chiedo\s+scusa|chiedo\s+perdono)|non\s+mi\s+dispiace|colpa\s+(?:tua|vostra)/iu',$sentence))continue;
        if($category==='critica'&&preg_match('/\b(?:non\s+mi\s+(?:avete|hai)\s+deluso|non\s+(?:trovo|ritengo))\b/iu',$sentence))continue;
        if(preg_match($pattern,$sentence))return [...$episode,'category'=>$category==='critica'?'offesa':$category,'criticism'=>$category==='critica','coercive'=>$category==='minaccia'&&(bool)preg_match('/obbed|ordini|comando|servir/iu',$spoken),'evidence'=>ai_text($sentence,250),'known'=>true];
    }
    foreach($speech as $sentence){
        if(preg_match('/\b(?:non\s+(?:voglio|desidero|vorrei)|ha\s+detto|diceva|racconta\s+che)\b/iu',$sentence))continue;
        $invitation=match(true){
            (bool)preg_match('/\b(?:conversazione|compagnia|ompagnia|trascorrere\s+(?:del\s+)?tempo\s+con\s+(?:te|voi)|parlatemi\s+di\s+voi|parlami\s+di\s+te|cambiamo\s+argomento|parlare\s+(?:un\s+po|con\s+(?:te|voi)))\b/iu',$sentence)=>'conversare',
            (bool)preg_match('/\b(?:andrebbe|va|vorrest[ei]|volete|vuoi|facciamo|possiamo)\b.{0,50}\b(?:passeggiata|due\s+passi|pausa|riposar|sederc[ie])\b/iu',$sentence)=>'una pausa',
            default=>''
        };
        if($invitation!=='')return [...$episode,'category'=>'cordialita','evidence'=>ai_text($sentence,250),'known'=>true,'invitation'=>$invitation];
    }
    return $episode;
}

/** Pair-indexed lookup; NULL job_id leaves the existing publication/reflection receipt unique. */
function ai_episode_previous(array $job,int $npc):?array {
    $source=(int)($job['source_id']??0);if($source<1)return null;
    $scope=ai_relation_context_event_scope($npc,(int)$job['user_id']);
    foreach(ai_db()->query("SELECT after_state FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND JSON_EXTRACT(after_state,'$._episode.source_id')=? AND JSON_UNQUOTE(JSON_EXTRACT(after_state,'$._episode.chat_key'))=?".$scope.' ORDER BY id DESC LIMIT 1',[$npc,(int)$job['user_id'],$source,(string)($job['chat_key']??'')]) as $r){
        $after=json_decode($r['after_state'],true)?:[];$e=$after['_episode']??null;
        if($e&&(int)$e['source_id']===$source&&($e['chat_key']??'')===($job['chat_key']??''))return $e;
    }
    return null;
}

function ai_episode_recent_refusal(array $npc,array $job):bool {
    if(($job['kind']??'')!=='play')return false;
    foreach(ai_dialogue_history($job,6) as $line)if((int)$line['npc_id']===(int)$npc['id']){
        if((int)$line['created_at']<=ai_relation_last_correction((int)$npc['id'],(int)$job['user_id']))return false;
        return (bool)preg_match('/restiamo\s+amic|preferisco.{0,35}(?:amic|distan)|non.{0,20}(?:interessat|desidero.{0,12}corteggi|ricambio)/iu',ai_narrative_parse($line['content'])['speech']);
    }
    return false;
}
function ai_episode_verified_trauma(int $npc,int $player,string $chat,int $days):bool {
    $private=str_starts_with($chat,'rent_')||str_starts_with($chat,'pvt_');$scope="(LEFT(chat_key,5)<>'rent_' AND LEFT(chat_key,4)<>'pvt_')";$params=[$npc,$player,time()-$days*86400];
    if($private){$scope='('.$scope.' OR chat_key=?)';$params[]=$chat;}
    return (bool)ai_db()->query("SELECT id FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1 AND category='trauma' AND source='system' AND created_at>? AND $scope".ai_relation_context_memory_scope($npc,$player).' ORDER BY id DESC LIMIT 1',$params);
}

/** Same budgets, replay protection and relationship ledger as retrospective evolution. */
function ai_episode_prepare(array $npc,array $job):array {
    $episode=ai_episode_interpret((string)$job['prompt']);$nid=(int)$npc['id'];$uid=(int)($job['user_id']??0);$source=(int)($job['source_id']??0);
    // Only quest intake supplies this flag, after validating an offensive ability and target.
    if(($job['kind']??'')==='combat-event'&&!empty($job['combat_hostile']))$episode=[...$episode,'known'=>true,'category'=>'minaccia','aggression'=>true,'evidence'=>ai_text($episode['reading']['action']?:ai_personality_observable($job['prompt']),250)];
    if(!$episode['known']||$uid<1||$source<1||!in_array($job['kind'],['play','npc-turn','combat-event'],true))return $episode;
    // A staff-defined present supersedes this source's old emotional receipt.
    // Physical attempts still belong to the quest engine and are not annulled.
    $context=ai_relation_context_current($nid,$uid);
    if($context&&$source<=(int)$context['chat_id'])return [...$episode,'known'=>false,'past_context'=>true];
    if(ai_recognition_anonymous($npc,$job))return [...$episode,'anonymous'=>true];
    $db=ai_db();$owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try{
        // The original public/own-room source must still be readable by this NPC.
        $row=$db->query('SELECT pg,chat,dest FROM chat WHERE id=?',[$source])[0]??null;
        if(!$row||(int)$row['pg']!==$uid||$row['chat']!==$job['chat_key']||(int)$row['dest']!==0){if($owned)$db->commit();return [...$episode,'known'=>false];}
        $scene=ai_narrative_scene_context($job['chat_key'],$uid,$source,null,true);$context=$scene['context'];$context['purpose']=in_array($episode['reading']['intent'],['assalto','arresto','furto'],true)?'action':'address';$context['selected']=$nid;
        $address=ai_narrative_resolve($episode['reading'],$scene['present'],$context);
        if(($address['status']??'')==='resolved'&&ai_narrative_actor_key($address['target'])!=='npc:'.$nid){$episode['observer']=true;$episode['target_name']=$address['target']['name'];}
        $db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$uid,time(),time()]);
        $before=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$uid])[0];
        // Recheck after the same pair lock used by staff edits: a correction
        // may have committed while this worker was waiting for that lock.
        $current=ai_relation_context_current($nid,$uid);
        if($current&&$source<=(int)$current['chat_id']){if($owned)$db->commit();return [...$episode,'known'=>false,'past_context'=>true];}
        if($previous=ai_episode_previous($job,$nid)){if($owned)$db->commit();return [...$episode,...$previous];}
        $rules=ai_social_rules($nid);$romance=ai_romance_allowed($npc,ai_player_identity($uid),$rules);$category=$episode['category'];
        $episode['declined']=$category==='corteggiamento'&&ai_episode_recent_refusal($npc,$job);
        $episode['verified_trauma']=ai_episode_verified_trauma($nid,$uid,$job['chat_key'],(int)$rules['trauma_memory_days']);
        $day=$db->query('SELECT before_state,after_state,created_at FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND actor_id=0 AND created_at>?'.ai_relation_context_event_scope($nid,$uid).' ORDER BY id DESC LIMIT 500',[$nid,$uid,time()-86400]);
        $recent=0;foreach($day as &$event){$event['_decoded_after']=json_decode($event['after_state'],true)?:[];$event['_decoded_before']=json_decode($event['before_state'],true)?:[];if((int)$event['created_at']>time()-300&&!empty($event['_decoded_after']['_evolution']['meaningful']))$recent++;}unset($event);
        $repeated=ai_social_repeated_evidence($episode['evidence'],$day);$significant=(!empty($episode['aggression'])||!$repeated)&&count($day)<500;
        $bond=$significant&&$recent<(int)$rules['meaningful_window_limit'];
        $traits=ai_npc_game($nid)['traits'];
        $raw=match($category){
            'minaccia'=>['fear'=>(int)($traits['courage']??50)>=75?1:3,'hostility'=>3,'trust'=>-3,'sympathy'=>-2,'respect'=>-1],
            'offesa'=>!empty($episode['criticism'])?['trust'=>-2,'sympathy'=>-1,'respect'=>-1]:['hostility'=>3,'trust'=>-2,'sympathy'=>-3,'respect'=>-2],
            'confidenza'=>['sympathy'=>1,'trust'=>1,'affection'=>1],
            'cordialita'=>['sympathy'=>2,'trust'=>1],
            'corteggiamento'=>$romance&&!$episode['declined']?['sympathy'=>1]:[],
            default=>[]
        };
        if(!empty($episode['invitation']))$raw=match(ai_situation_openness($npc,$rules,$before)){'open'=>['sympathy'=>2,'trust'=>1],'cautious'=>['sympathy'=>1],default=>[]};
        // Witnessing a dispute between two others does not establish a personal enemy.
        // The published NPC may later express a directed feeling, evaluated separately.
        if(!empty($episode['observer']))$raw=[];
        // A frightened/hostile NPC need not trust a sudden compliment or reassurance.
        if((int)$before['fear']>=(int)$rules['romance_fear_limit']||(int)$before['hostility']>=20)foreach($raw as $key=>$v)if($v>0&&in_array($key,['sympathy','trust','affection'],true))unset($raw[$key]);
        if((int)$before['fear']>0&&($episode['verified_trauma']||(int)$before['hostility']>=10))foreach(['trust','affection'] as $key)if(($raw[$key]??0)>0)unset($raw[$key]);
        $after=$before;$changes=[];
        foreach($raw as $key=>$value){$delta=ai_social_bounded_delta($key,$value,$category,$before,$rules,$significant,$bond,$romance,'',$day);$min=in_array($key,['sympathy','trust','respect','honor'],true)?-100:0;$after[$key]=max($min,min(100,(int)$before[$key]+$delta));if($after[$key]!==$before[$key])$changes[$key]=$after[$key]-$before[$key];}
        // A recorded hostile attempt establishes enmity even before any hit is resolved.
        // A sparring proposal, quotation or bystander's turn never reaches this branch.
        if(!empty($episode['aggression'])&&empty($episode['observer'])){
            foreach(['hostility'=>max((int)$after['hostility'],(int)$rules['combat_hostility_min']),
                'trust'=>min((int)$after['trust'],(int)$rules['combat_trust_ceiling']),
                'sympathy'=>min((int)$after['sympathy'],-25),'respect'=>min((int)$after['respect'],-30)] as $key=>$value){$after[$key]=$value;if($value!==(int)$before[$key])$changes[$key]=$value-(int)$before[$key];}
        }
        if($coercion=ai_coercion_assess($npc,$job,$episode)){
            $episode['coercion']=$coercion;
            foreach(['fear'=>max((int)$after['fear'],!empty($coercion['verified_harm'])?80:65),'trust'=>min((int)$after['trust'],-40),'hostility'=>max((int)$after['hostility'],40)] as $key=>$value){$after[$key]=$value;if($value!==(int)$before[$key])$changes[$key]=$value-(int)$before[$key];}
        }
        $counted=$bond&&(bool)$changes;$after['meaningful']=(int)$before['meaningful']+(int)$counted;$after['updated_at']=time();
        if(in_array($category,['minaccia','offesa'],true)&&array_sum(array_map('abs',$changes))>=12)$after['state']=match($before['state']){'interesse','amicizia'=>'conoscenza','corteggiamento'=>'separazione','innamoramento','relazione','riconciliazione'=>'crisi',default=>$before['state']};
        $episode=[...$episode,'source_id'=>$source,'chat_key'=>$job['chat_key'],'changes'=>$changes,'counted'=>$counted,'repeated'=>$repeated];unset($episode['reading']);
        $keys=[...array_keys(AI_RELATION_METRICS),'state','meaningful','updated_at'];$db->execute('UPDATE ai_relationships SET '.implode(',',array_map(static fn($k)=>$k.'=?',$keys)).' WHERE npc_id=? AND player_id=?',[...array_map(static fn($k)=>$after[$k],$keys),$nid,$uid]);
        $record=$after;$record['_episode']=$episode;$record['_evolution']=['category'=>$category,'evidence'=>ai_social_evidence_key($episode['evidence']),'meaningful'=>$counted,'romantic'=>false,'source_id'=>$source];
        ai_relation_event($nid,$uid,$before,$record,'Percezione prima della risposta · '.$category.': '.$episode['evidence'],$job);
        if($changes){$story=$category==='minaccia'&&array_sum(array_map('abs',$changes))>=(int)$rules['trauma_threshold']?'trauma':'evoluzione';$summary='Ha percepito '.$category.' nelle parole o nei tentativi di questo personaggio: «'.$episode['evidence'].'». Il rapporto ne risente; non certifica esiti fisici, sincerità dei racconti o sentimenti del PG.';$db->execute("INSERT IGNORE INTO ai_personal_memories(npc_id,player_id,chat_key,job_id,category,summary,evidence,source,epistemic,visible,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'interpreted','interpretation',?,?,?)",[$nid,$uid,$job['chat_key'],(int)($job['id']??0)>0?(int)$job['id']:null,$story,$summary,$episode['evidence'],empty($rules['hidden_emotions'])?1:0,time(),time()]);}
        if($owned)$db->commit();return [...$episode,'reading'=>ai_narrative_parse((string)$job['prompt'])];
    }catch(Throwable $e){if($owned&&$db->inTransaction())$db->rollBack();throw $e;}
}

function ai_episode_context(array $npc,array $job):string {
    $e=$job['episode']??null;if(!$e||empty($e['known']))return '';
    $uid=(int)$job['user_id'];$p=ai_player_identity($uid);$r=!empty($e['anonymous'])?array_fill_keys(array_keys(AI_RELATION_METRICS),0):ai_relation((int)$npc['id'],$uid);
    if(!empty($e['anonymous']))$p['nome']='figura dal volto coperto';
    else $p['nome']=ai_identity_known_name($npc,$job,$p)?:($p['sesso']==='Femmina'?'la visitatrice':'il visitatore');
    $focus=match($e['category']){
        'minaccia'=>'Ti ha minacciato o tentato di aggredire. Reagisci al pericolo con un limite concreto o proteggendoti; non tornare a parlare del lavoro.',
        'offesa'=>!empty($e['criticism'])?'Ha espresso delusione o sfiducia verso il tuo comportamento. Prendi sul serio la critica: puoi chiedere un chiarimento o dissentire, senza rispondere come a un complimento né trasformarla automaticamente in odio.':'Ti ha offeso. Esprimi il tuo dissenso o prendi distanza; non trattarlo come un complimento.',
        'confidenza'=>'Ti ha affidato una vulnerabilità personale. Rispondi alla sua esperienza con ascolto concreto, senza massime astratte.',
        'cordialita'=>'Ti offre ascolto o aiuto personale. Rispondi a questa disponibilità, conservando i tuoi limiti.',
        'corteggiamento'=>ai_romance_allowed($npc,$p,ai_social_rules((int)$npc['id']))?(ai_relation_feelings($r)['close']?'Questo interlocutore è già il tuo amore: rispondi con affetto alla sua richiesta, conservando i tuoi limiti. Non ricominciare a conoscerlo da estraneo.':'È un corteggiamento rivolto alla tua persona. Puoi ricambiare con cautela, chiedere tempo o rifiutare chiaramente. Non deviare sul tuo mestiere.'):'Ti sta corteggiando, ma non sei disponibile: declina in modo chiaro e personale.',
        'rassicurazione'=>'Sta cercando di rassicurarti. Valuta se credergli in base alla paura, ai precedenti e alla distanza; non dimenticare l’aggressione e non recitare le sue parole.',
        'riconciliazione'=>'Ti sta chiedendo scusa. Puoi rifiutare o aprire uno spiraglio con condizioni concrete; una scusa non cancella offese, aggressioni, ferite o segreti osservati.',
        default=>''
    };
    if(!empty($e['declined']))$focus.=' Hai già rifiutato il corteggiamento: conserva il limite appena espresso.';
    if(!empty($e['observer']))$focus='Hai assistito a un episodio rivolto a '.$e['target_name'].', non a te. Non attribuirti le parole o il ruolo del destinatario e non trattarlo come corteggiamento verso di te.';
    if(!empty($e['help_offer'])&&empty($e['observer']))$focus.=' È '.$p['nome'].' che offre aiuto a te, '.$npc['name'].': rispondi se accetti la sua offerta, senza invertire chi aiuta chi.';
    if(!empty($e['invitation'])&&empty($e['observer']))$focus.=' Ti invita a '.$e['invitation'].': rispondi alla proposta di compagnia. Se accetti, lascia spazio alla persona e chiedile qualcosa di personale o cosa desidera fare; non chiudere riportandola al lavoro o al luogo. Una conversazione amichevole non impone interesse romantico.';
    if(!empty($e['verified_trauma']))$focus.=' Questo interlocutore ti ha ferito in uno scontro accertato: tienine conto, senza cancellare il ricordo davanti a un complimento.';
    return "\nEPISODIO ATTUALE, PRIORITÀ DELLA REPLICA: ".$focus.' Tu sei '.$npc['name'].' ('.ai_npc_profile($npc)['sex'].'); ti rivolgi a '.$p['nome'].' ('.$p['sesso'].'). Stato già aggiornato: fiducia '.(int)$r['trust'].', affetto '.(int)$r['affection'].', paura '.(int)$r['fear'].', ostilità '.(int)$r['hostility'].'. Non citare numeri. Rispondi con la tua voce; non ripetere le battute del PG.';
}

function ai_episode_echo(string $text,string $input):bool {
    $pg=ai_social_evidence_key(ai_narrative_parse($input)['speech']);$speech=ai_social_evidence_key(ai_narrative_parse($text)['speech']);
    $words=explode(' ',$speech);for($i=0;$i+8<=count($words);$i++)if(str_contains(' '.$pg.' ',' '.implode(' ',array_slice($words,$i,8)).' '))return true;
    return false;
}

function ai_episode_reply_problem(array $npc,array $job,string $text):?string {
    if($problem=ai_coercion_reply_problem($npc,$job,$text))return $problem;
    if($problem=ai_recognition_reply_problem($npc,$job,$text))return $problem;
    if($problem=ai_relation_feelings_reply_problem($npc,$job,$text))return $problem;
    if((int)($job['user_id']??0)>0&&!ai_romance_allowed($npc,ai_player_identity((int)$job['user_id']),ai_social_rules((int)$npc['id']))&&ai_social_reciprocal_interest(ai_narrative_parse($text)['speech']))return 'Non sei disponibile per una relazione con questo interlocutore: non ricambiare romanticamente, puoi esprimere cordialità o un rifiuto rispettoso.';
    if(ai_episode_echo($text,(string)($job['episode_input']??$job['prompt'])))return 'La battuta ripete il PG: deve parlare il PNG, reagendo alle sue parole.';
    $e=$job['episode']??[];if(empty($e['known'])||!empty($e['observer']))return null;
    if(!empty($e['invitation'])&&!preg_match('/convers|compagnia|parl(?:are|iamo)|ascolt|raccont|pausa|volentieri|mi\s+fa\s+piacere|preferisco|non\s+posso|devo\s+(?:finire|terminare|restare)|due\s+passi|restiamo|sediamoci/iu',ai_narrative_parse($text)['speech']))return 'Rispondi all’invito di compagnia: puoi accettare, proporre una condizione o declinare, senza deviare su una domanda sul mestiere.';
    if(!empty($e['invitation'])&&ai_situation_accepts_company(ai_narrative_parse($text)['speech'])&&preg_match('/(?:cosa|che\s+cosa)\b.{0,25}(?:sapere|interessa)\b.{0,35}(?:questo\s+posto|questo\s+luogo|mio\s+lavoro|mio\s+mestiere)|(?:domande|chiedere)\b.{0,25}(?:sui|sulle|riguardo\s+ai)\b/iu',ai_narrative_parse($text)['speech']))return 'Hai accettato di parlare d’altro: fai proseguire la conversazione personale, senza ricondurla subito al mestiere o al luogo.';
    $relevant=match($e['category']){
        'minaccia'=>!empty($e['coercive'])?'/obbed|ordini|vita|risparmi|ferir|uccid|ascolt|farò|faro/iu':'/minacc|paura|ferir|far.{0,12}male|colpir|indietro|distan|basta|fermat|allontan|trem|protegg|difend|non.{0,15}(?:tocc|avvicin)|chiama.{0,10}aiuto/iu',
        'offesa'=>!empty($e['criticism'])?'/delus|sfiduc|fidar|non.{0,15}fid|chiar|dissent|non\s+(?:sono|siamo)\s+d[’\x{27}]accordo|(?:mio|nostro)\s+(?:modo|comportamento)|(?:tue|vostre)\s+(?:parole|critiche)|capisco|comprendo|ascolt/iu':'/offes|insult|disprezz|umili|non.{0,20}(?:accett|permett|parlar|trattar)|rispetto|andate|vattene|distan|basta/iu',
        'confidenza'=>'/confid|fiducia|paura|temev|timor|raccont|fratell|sorell|deris|ridevano|sbagli|error|giudic|capisco|ascolto|ascoltar/iu',
        'corteggiamento'=>'/mi fa piacere|complimento|mi piac|preferisc|interess|conoscer|tempo|amic|ricambi|avvicin|distan|tocc|mano|braccio|(?:ti|vi)\s+amo\b|amore|tenerezza|affetto/iu',
        default=>null
    };
    // Acknowledging criticism in narration while replying as to a compliment is
    // not enough. Conversely, mild dissent need not use the vocabulary of insult.
    $relevantText=$text;
    if(!empty($e['criticism'])){
        $quoted=ai_narrative_parse($text,['channel'=>'action'])['speech'];
        $relevantText=$quoted!==''?$quoted:ai_narrative_parse($text)['speech'];
    }
    return $relevant&&!preg_match($relevant,$relevantText)?'La risposta ignora l’episodio '.$e['category'].': affronta prima quel gesto o quelle parole.':null;
}

/** A bounded, relevant reply is preferable to losing a valid source after repeated model failures. */
function ai_episode_fallback(array $npc,array $job):string {
    $name=$npc['name'];$category=$job['episode']['category']??'dialogo';$female=ai_npc_profile($npc)['sex']==='Femmina';
    if(!empty($job['episode']['coercive'])&&ai_coercion_state((int)$npc['id'],(int)($job['user_id']??0)))return ai_coercion_fallback($npc);
    if(function_exists('ai_perception_scene_state')){
        $scene=ai_perception_scene_state($npc,$job);
        if(!empty($scene['alert'])||!empty($scene['confirmed_hold']))return $name.' tiene l’attenzione sul pericolo ancora presente. «Prima di continuare questo discorso dobbiamo affrontare ciò che sta succedendo qui.»';
    }
    $variant=((int)($job['source_id']??$job['id']??0)+(int)$npc['id'])%3;
    $personal=ai_relation_personal_scene($npc,$job);
    $speech=match($category){
        'minaccia'=>['Non avvicinatevi. Non accetto minacce: restate dove siete.','Fermatevi. Se volete parlare, fatelo senza minacciarmi.','State indietro. Ho sentito quello che avete detto e non mi sento al sicuro.'][$variant],
        'offesa'=>['Non accetto di essere trattat'.($female?'a':'o').' così. Mantenete le distanze.','Le vostre parole mi offendono. Preferisco interrompere questo discorso.','Non vi permetto di umiliarmi. Possiamo parlare soltanto con rispetto.'][$variant],
        'confidenza'=>['Grazie per avermelo raccontato. Non vi giudico per la paura o per gli errori.','Capisco che non sia facile confidarsi. Se volete continuare, vi ascolto.','Mi avete affidato qualcosa di personale. Voglio ascoltarvi senza mettervi fretta.'][$variant],
        'corteggiamento'=>empty($job['episode']['declined'])&&ai_romance_allowed($npc,ai_player_identity((int)$job['user_id']),ai_social_rules((int)$npc['id']))?'Ho capito il vostro interesse. Preferisco conoscerci con calma, senza dare per scontato quello che proveremo.':'Non desidero essere corteggiat'.($female?'a':'o').'. Vi chiedo di rispettare questa scelta.',
        'rassicurazione'=>'Vi ho sentito. Per ora mantenete questa distanza: ho bisogno di tempo per sentirmi al sicuro.',
        'riconciliazione'=>'Ho sentito le vostre scuse. Ho bisogno di vedere che cosa cambierà nei vostri gesti, prima di tornare a fidarmi.',
        default=>'Vi ascolto. Spiegatemi quello che desiderate, senza dare per scontata la mia risposta.'
    };
    if(!empty($job['episode']['invitation'])){
        $relation=ai_recognition_anonymous($npc,$job)?[]:ai_relation((int)$npc['id'],(int)$job['user_id']);
        $speech=match(ai_situation_openness($npc,ai_social_rules((int)$npc['id']),$relation)){
            'open'=>'Una breve pausa mi farebbe piacere. Parliamo: che cosa vi ha portato qui oggi?',
            'guarded'=>'Per ora non desidero compagnia: non mi sento al sicuro con voi. Ho bisogno che manteniate la distanza.',
            'reserved'=>'Preferisco finire i miei impegni: oggi non mi va di fare conversazione. Vi chiedo di lasciarmi lavorare.',
            default=>'Posso ascoltarvi da qui, senza mettere da parte tutti i miei impegni. Di che cosa volete parlare?'
        };
    }
    $gesture=in_array($category,['minaccia','offesa'],true)?'si scosta con cautela, tenendo lo sguardo sull’interlocutore':'prende un momento per scegliere le parole';
    if($category==='offesa'&&!empty($job['episode']['criticism'])){$speech='Ho capito che vi ho deluso. Posso ascoltare che cosa non vi è sembrato corretto, senza chiedervi di fidarvi subito.';$gesture='si fa seria e presta attenzione alle parole dell’interlocutore';}
    if($personal&&in_array($category,['dialogo','cordialita','corteggiamento','confidenza'],true)){
        $speech=$category==='confidenza'?'Grazie per avermelo confidato. Ti ascolto, puoi parlarmi senza fretta.':[
            'Mi fa piacere averti qui. Restiamo ancora un poco insieme, senza fretta.',
            'Sono felice di stare qui con te. Dimmi che cosa senti in questo momento.',
            'Ti voglio bene. Possiamo prenderci il tempo per ascoltarci.'
        ][$variant];
        if(!empty($job['episode']['invitation']))$speech='Resto volentieri qui con te. Parlami di quello che hai in mente.';
        $gesture='rivolge all’interlocutore uno sguardo affettuoso, continuando il loro colloquio';
    }
    if(ai_recognition_anonymous($npc,$job)&&!in_array($category,['minaccia','offesa'],true))$speech='Non distinguo il vostro volto. Posso ascoltarvi, ma non so con certezza con chi sto parlando.';
    elseif(!$personal&&($a=ai_npc_authority((int)$npc['id'],(int)($job['user_id']??0),$job))['recognized']){
        $role=ai_authority_titles($a)[0]??'';
        if($role!==''&&empty($job['episode']['invitation'])&&in_array($category,['dialogo','cordialita'],true))$speech=match($a['attitude']['mode']??'respectful'){
            'defiant'=>!empty($a['attitude']['cautious'])?$role.', conosco il vostro incarico. Preferisco sentire che cosa volete dirmi, mantenendo questa distanza.':$role.', so quale incarico ricoprite. Non è il titolo a decidere se vi darò ragione: ditemi che cosa volete.',
            'skeptical'=>$role.', conosco il vostro incarico. Vi ascolto, ma voglio capire la richiesta prima di accettare.',
            default=>$role.', vi riconosco. Vi ascolto: ditemi come posso esservi utile.'
        };
    }
    $affectiveQuestion=(bool)preg_match('/\b(?:mi\s+(?:ami|amate)|(?:provi|provate)\s+per\s+me|sentimenti|innamorat[oa])\b/iu',ai_narrative_parse((string)($job['prompt']??''))['speech']);
    if(!ai_recognition_anonymous($npc,$job)&&empty($job['episode']['observer'])&&empty($job['episode']['declined'])&&($category==='corteggiamento'||(in_array($category,['dialogo','cordialita'],true)&&$affectiveQuestion))&&ai_relation_feelings(ai_relation((int)$npc['id'],(int)$job['user_id']))['close']&&ai_romance_allowed($npc,ai_player_identity((int)$job['user_id']),ai_social_rules((int)$npc['id']))){
        $speech=['Ti amo. Sono felice quando possiamo stare insieme e parlare senza fretta.','Quello che provo per te è amore. Voglio condividere con te questo momento.','Sì, ti amo. Mi fa piacere averti vicino e dedicarti il mio tempo.'][$variant];
        $gesture='si rivolge all’interlocutore con un sorriso affettuoso';
    }
    return $name.' '.$gesture.'. «'.$speech.'»';
}

/** Finalise the same episode from the NPC's actual words, not a second guessed interaction. */
function ai_episode_consolidate(array $npc,array $job,array $result):void {
    if(ai_recognition_anonymous($npc,$job))return;
    $e=$job['episode']??ai_episode_previous($job,(int)$npc['id']);if(!$e||empty($e['known']))return;
    // A bystander's feelings cannot be inferred from the target's episode. Leave
    // its actual words to the grounded reflection instead of closing it as dialogue.
    if(!empty($e['observer']))return;
    $reply=(string)$result['testo'];$speech=ai_narrative_parse($reply)['speech'];$category=empty($e['observer'])?$e['category']:'dialogo';$deltas=[];$rel=ai_relation((int)$npc['id'],(int)$job['user_id']);$state=$rel['state'];
    if($category==='rassicurazione'&&ai_social_reassurance($e['evidence'],$reply))$deltas=['fear'=>-3,'trust'=>1];
    if($category==='riconciliazione'&&ai_social_reconciliation($e['evidence'],$reply))$deltas=['hostility'=>-2,'fear'=>-1,'trust'=>1];
    $companyAccepted=empty($e['invitation'])||ai_situation_invitation_accepted($speech,true);
    if($category==='cordialita'&&!empty($e['invitation'])&&$companyAccepted)$deltas=['sympathy'=>1,'trust'=>1];
    if($category==='corteggiamento'&&ai_social_reciprocal_interest($speech)){$deltas=['attraction'=>2,'affection'=>1];$state=match($state){'conoscenza','amicizia'=>'interesse','interesse'=>'corteggiamento','corteggiamento'=>'innamoramento','innamoramento'=>'relazione',default=>$state};if(in_array($state,['innamoramento','relazione'],true))$deltas['love']=1;}
    if(in_array($category,['confidenza','cordialita'],true)&&in_array($state,['conoscenza','amicizia'],true)&&$companyAccepted&&!preg_match('/non.{0,15}(?:mi fido|voglio ascoltare|desidero ascoltare)/iu',$speech))$state=(int)$rel['meaningful']>=2?'amicizia':$state;
    // Evidence must be a real single segment, not an action and several quoted sentences glued together.
    $parts=array_values(ai_social_segments($reply));$evidence='';$best=-1;
    foreach($parts as $part){if(mb_strlen($part)<8&&!ai_social_reciprocal_interest($part))continue;$score=0;
        if($category==='corteggiamento'&&ai_social_reciprocal_interest($part))$score=3;
        elseif($category==='riconciliazione'&&ai_social_reconciliation($e['evidence'],$part))$score=3;
        elseif($category==='rassicurazione'&&preg_match('/credo|calm|respiro|rilass|allenta/iu',$part))$score=2;
        elseif(in_array($category,['confidenza','cordialita'],true)&&preg_match('/fiducia|ascolt|grazie|capisco|giudic/iu',$part))$score=2;
        if($score>$best){$best=$score;$evidence=ai_text($part,250);}
    }
    ai_social_apply($npc,[...$job,'result'=>json_encode(['testo'=>$reply],JSON_UNESCAPED_UNICODE)],['category'=>$category,'player_evidence'=>$e['evidence'],'npc_evidence'=>$evidence,'deltas'=>$deltas,'state'=>$state,'revealed'=>[],'money'=>null]);
    ai_db()->execute("UPDATE ai_social_jobs SET status='done',proposal=?,error='' WHERE job_id=?",[json_encode(['category'=>$category,'deltas'=>$deltas,'phase'=>'episode_consolidated'],JSON_UNESCAPED_UNICODE),(int)$job['id']]);
}
