<?php
declare(strict_types=1);
const AI_SITUATION_ANCHOR='/\b(?:cerchio(?:\s+(?:di\s+pietra|in\s+rovina))?|campo\s+di\s+studio|banco(?:\s+(?:della|delle|dei|di)\s+\p{L}+)?|portico(?:\s+laterale)?|sala\s+comune|riparo(?:\s+di\s+rami)?|muratura|cancelli|portone|varco|bancone|focolare|tavolo|salone|cortile|fontana|pontile|navata|cripta|molo|ingresso|sentier[oi]|pozzo|argine|cascat[ae]|scalinata|mura|scaffali|fucina|officina|altare|mulino|recinto|radici)\b/iu';

function ai_situation_positions(array $room,array $npc):array {
    $map=ai_db()->query('SELECT descrizione FROM mappe WHERE chat_key=? AND chat=1',[$room['chat_key']])[0]??[];
    preg_match_all(AI_SITUATION_ANCHOR,($room['public_setting']??'').' '.($map['descrizione']??''),$matches);
    return array_values(array_unique([ai_npc_profile($npc)['scene_position'],...($matches[0]??[])]));
}

function ai_situation_at(string $position):string {
    if(preg_match('/^(?:al(?:la|le|lo|l[’\x{27}])?|nel(?:la|le|lo|l[’\x{27}])?|sul(?:la|le|lo|l[’\x{27}])?|presso|vicino|accanto|tra|davanti|dietro)\b/iu',$position))return $position;
    $article=match(true){
        (bool)preg_match('/^(?:cancelli|scaffali|bassorilievi|sentieri)\b/iu',$position)=>'i ',
        (bool)preg_match('/^(?:rovine|radici|mura|colonne|cascate)\b/iu',$position)=>'le ',
        (bool)preg_match('/^[aeiouàèéìòù]/iu',$position)=>'l’',
        (bool)preg_match('/^(?:sala|fontana|navata|cripta|fucina|scalinata|muratura|porta|foresta|colonna|cappella|fortezza|piazza|radura|sporgenza|capanna|riva|stanza)\b/iu',$position)=>'la ',
        default=>'il '
    };
    return 'presso '.$article.$position;
}

/** Scene placement comes from authored public material, never from a visitor's claims. */
function ai_situation_base(array $room,array $npc):array {
    $profile=ai_npc_profile($npc);$map=ai_db()->query('SELECT title,descrizione FROM mappe WHERE chat_key=? AND chat=1',[$room['chat_key']])[0]??[];
    $place=ai_text((string)($map['title']??'Luogo riservato'),80);$own='';
    $public=trim((string)($room['public_setting']??''));
    foreach(preg_split('/(?<=[.!?])\s+|;\s*/u',$public,-1,PREG_SPLIT_NO_EMPTY)?:[] as $clause){
        if(ai_mentions_npc($clause,(string)$npc['name'])){
            // A room often describes several people in one sentence. Do not
            // prepend a colleague's actions as this NPC's new turn.
            $full=mb_stripos($clause,$npc['name']);$first=preg_split('/\s+/u',$npc['name'])[0];$start=$full!==false?$full:mb_stripos($clause,$first);
            $own=mb_substr($clause,(int)$start);
            foreach(ai_db()->query('SELECT name FROM ai_npcs WHERE chat_key=? AND id<>?',[$room['chat_key'],(int)$npc['id']]) as $other){
                $alias=preg_split('/\s+/u',$other['name'])[0];
                if(preg_match('/(?<!\p{L})'.preg_quote($alias,'/').'(?!\p{L})/iu',$own,$m,PREG_OFFSET_CAPTURE))$own=substr($own,0,$m[0][1]);
            }
            $own=preg_replace('/(?:,\s*[^,.]*|\s+e(?:\s+(?:il|la|l[’\x{27}]))?[^,.]*)$/u','',$own)??$own;
            $own=ai_text($own,300);if(count(preg_split('/\s+/u',$own))<4)$own='';break;
        }
    }
    $position=ai_text((string)$profile['scene_position'],100);
    $anchors=AI_SITUATION_ANCHOR;
    if($position===''&&$own!==''&&preg_match($anchors,$own,$match))$position=$match[0];
    if($position===''&&preg_match($anchors,(string)($map['descrizione']??''),$match))$position=$match[0];
    $activity=ai_text((string)$profile['scene_activity'],240);
    // Activity is authored metadata, not a guaranteed conjugated sentence.
    // Keep it in the prompt; only verified spatial data produces automatic prose.
    $opening=$position!==''?$npc['name'].' si trova '.ai_situation_at($position):'';
    return ['place'=>$place,'position'=>$position?:$place,'activity'=>$activity?:($own?:ai_text($profile['occupation'],160)),
        'purpose'=>ai_text((string)$profile['scene_purpose'],240)?:ai_text($profile['occupation'],160),'opening'=>rtrim($opening,". \t\n\r"),'mode'=>'working'];
}

/** Only this NPC's committed public output can carry its current activity into another turn. */
function ai_situation_view(array $room,array $npc,array $job):array {
    $out=ai_situation_base($room,$npc);$out['first']=true;$out['receipt']=0;
    if(!in_array($job['kind']??'',['preview','demo'],true)){
        $previous=ai_db()->query("SELECT id,result FROM ai_jobs WHERE npc_id=? AND chat_key=? AND id<>? AND status='done' AND message_id>0 AND kind IN('play','observe','reaction','npc-turn') AND finished_at>? ORDER BY message_id DESC LIMIT 1",[(int)$npc['id'],$room['chat_key'],(int)($job['id']??0),time()-1800])[0]??[];
        if($previous){
            $out['receipt']=(int)$previous['id'];$state=json_decode($previous['result']??'{}',true)['situation']??[];
            $out['first']=!$state||!ai_db()->query("SELECT id FROM ai_jobs WHERE npc_id=? AND user_id=? AND chat_key=? AND id<>? AND status='done' AND message_id>0 AND kind='play' AND finished_at>? ORDER BY message_id DESC LIMIT 1",[(int)$npc['id'],(int)($job['user_id']??0),$room['chat_key'],(int)($job['id']??0),time()-1800]);
            // Movement stays within authored public anchors in this room.
            if(($state['place']??'')===$out['place']&&in_array($state['position']??'',[...ai_situation_positions($room,$npc),$out['position']],true)&&in_array($state['mode']??'',['working','conversation','alert'],true)){$out['mode']=$state['mode'];$out['position']=$state['position'];}
        }
    }
    if(($job['kind']??'')==='npc-turn'){
        $out['first']=false;$out['mode']='alert';$sid=(int)($job['session_id']??0);
        $cast=$sid?ai_db()->query("SELECT state FROM ai_quest_cast WHERE session_id=? AND kind='npc' AND actor_id=?",[$sid,(int)$npc['id']])[0]??[]:[];
        $state=json_decode($cast['state']??'{}',true)?:[];
        $out['position']=ai_text((string)($state['position']??$out['place']),100);
    }
    $out['hazard']=function_exists('ai_perception_scene_state')?ai_perception_scene_state($npc,$job):['alert'=>false];
    if(!empty($out['hazard']['alert'])){$out['first']=false;$out['mode']='alert';}
    return $out;
}

function ai_situation_openness(array $npc,array $rules,array $relation):string {
    $traits=ai_npc_game((int)$npc['id'])['traits'];
    if((int)($relation['fear']??0)>=50||(int)($relation['hostility']??0)>=40)return 'guarded';
    $score=(int)$rules['social_flexibility']*.5+(int)$traits['sociability']*.3+(int)$traits['empathy']*.2
        +(int)($relation['trust']??0)*.25+(int)($relation['sympathy']??0)*.15+(int)($relation['affection']??0)*.15
        -(int)($relation['hostility']??0)*.6-(int)($relation['fear']??0)*.4;
    return $score>=55?'open':($score>=35?'cautious':'reserved');
}

function ai_situation_accepts_company(string $speech):bool {
    if(preg_match('/\bnon\s+(?:voglio|desidero|posso|accetto)\b.{0,25}(?:compagnia|convers|parlare|pausa)|lasciatemi\s+(?:solo|sola)|preferisco\s+(?:restare|stare)\s+(?:solo|sola)/iu',$speech))return false;
    return (bool)preg_match('/\b(?:volentieri|parliamo|conversiamo|possiamo\s+parlare|mi\s+fa(?:rebbe)?\s+piacere|(?:posso|possiamo|facciamo)\s+(?:fare\s+)?(?:una\s+)?(?:breve\s+)?pausa|(?:posso|voglio)\s+conceder\p{L}*\s+(?:un\s+)?momento|vi\s+ascolto|ti\s+ascolto)\b/iu',$speech);
}

function ai_situation_invitation_accepted(string $speech,bool $invited):bool {
    return ai_situation_accepts_company($speech)||($invited&&preg_match('/\b(?:va\s+bene|d[’\x{27}]accordo|posso\s+fare\s+due\s+passi|accetto\s+la\s+compagnia)\b/iu',$speech)&&!preg_match('/\bnon\s+(?:posso|voglio|accetto)\b/iu',$speech));
}

function ai_situation_context(array $room,array $npc,array $job):string {
    $s=ai_situation_view($room,$npc,$job);
    if(!empty($s['hazard']['alert']))return "\nSCENA DEL PNG: ".$s['place'].' · '.$s['position'].'. Attività abituale sospesa: proteggiti e reagisci al pericolo attuale.';
    $out="\nSCENA DEL PNG: ".$s['place'].' · '.$s['position'].'. Attività di partenza: '.ai_text($s['activity'],200).'. Motivo: '.ai_text($s['purpose'],120).'. ';
    if(($job['kind']??'')==='npc-turn')return $out.'Gli esiti e le posizioni del Master prevalgono: non tornare alle attività abituali durante lo scontro.';
    $out.=$s['first']?'Primo contatto: colloca il tuo corpo in questo punto e mostra brevemente cosa stavi facendo, poi reagisci al visitatore. ':'Il contatto è già avviato: conserva la continuità, non risalutare e non ripresentare il lavoro a ogni risposta. ';
    if($s['mode']==='conversation')$out.='Hai già dato spazio alla conversazione: non ricominciare a lavorare come se non avessi accettato. ';
    $out.='Puoi muoverti tra punti già descritti del luogo, narrando soltanto i tuoi passi; non spostare altri né inventare nuove destinazioni. ';
    $reading=ai_episode_interpret((string)($job['episode_input']??$job['prompt']??''));
    if(!empty($reading['invitation'])){
        $rules=ai_social_rules((int)$npc['id']);$relation=ai_recognition_anonymous($npc,$job)?[]:ai_relation((int)$npc['id'],(int)$job['user_id']);$openness=ai_situation_openness($npc,$rules,$relation);
        $out.='INVITO PERSONALE A '.strtoupper($reading['invitation']).': rispondi alla proposta, non convertirla in una domanda sul tuo mestiere. ';
        $out.=match($openness){'open'=>'Sei incline a dare spazio a una pausa o a un altro argomento; puoi mettere da parte il lavoro e fare una domanda personale pertinente. ','guarded'=>'Paura o ostilità richiedono distanza e prudenza: puoi rifiutare spiegando il motivo concreto. ',default=>'Valuta una breve pausa, una condizione concreta o un rifiuto personale: il lavoro non è un divieto assoluto. '};
        $out.='Amicizia e compagnia sono possibili anche senza disponibilità romantica. Non abbandonare un presidio obbligatorio né spostare il PG: proponi, lascia scegliere. ';
    }else $out.='Il mestiere è una motivazione, non l’unico argomento: puoi interessarti alla persona e cambiare attività secondo carattere, doveri e rapporto. ';
    return $out;
}

/** Public prose may establish a pause, but cannot create travel, romance, items or physical outcomes. */
function ai_situation_result(array $room,array $npc,array $job,array $result):array {
    if(isset($result['situation']))return $result;
    $s=ai_situation_view($room,$npc,$job);$text=(string)$result['testo'];$action=ai_narrative_parse($text,['channel'=>'action'])['action'];$speech=ai_narrative_parse($text)['speech'];
    if(!empty($s['hazard']['alert']))$s['mode']='alert';
    elseif(preg_match('/\b(?:riprende|torna\s+a)\b.{0,25}(?:lavor|disegn|studi|sorvegl)/iu',$action))$s['mode']='working';
    elseif(ai_situation_invitation_accepted($speech,!empty(ai_episode_interpret((string)($job['episode_input']??$job['prompt']??''))['invitation'])))$s['mode']='conversation';
    if(($job['kind']??'')!=='npc-turn'&&preg_match_all('/\b(?:si\s+(?:sposta|avvia|avvicina|porta|siede)|raggiunge|cammina|passeggia|prende\s+posto)\b.{0,65}/iu',$action,$movements))foreach($movements[0] as $movement){
        if(preg_match('/(?:tenta|prova|vorrebbe|cerca)\s+(?:di\s+)?$/iu',strstr($action,$movement,true)?:''))continue;
        foreach(ai_situation_positions($room,$npc) as $zone)if($zone!==''&&ai_narrative_mentions($movement,$zone))$s['position']=$zone;
    }
    if($s['first']&&$s['opening']!==''&&in_array($job['kind']??'',['play','preview','demo'],true)&&$s['position']!==$s['place']&&!ai_narrative_mentions($action,$s['position'])){
        $opening=$s['mode']==='conversation'?$npc['name'].' si trova '.ai_situation_at($s['position']).', con l’attenzione rivolta alla conversazione':$s['opening'];
        if(str_starts_with($text,$npc['name'].',')&&str_starts_with($opening,$npc['name'].' ')){$opening=mb_substr($opening,mb_strlen($npc['name'])+1);$opening=mb_strtoupper(mb_substr($opening,0,1)).mb_substr($opening,1);}
        $text=preg_replace('/^'.preg_quote($npc['name'],'/').'\s+/u','Poi ',$text)??$text;
        $text=$opening.'. '.$text;
    }
    $result['testo']=$text;$result['situation']=array_intersect_key($s,array_flip(['place','position','mode']));
    return $result;
}
