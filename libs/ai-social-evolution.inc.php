<?php
declare(strict_types=1);

/** These are NPC policy settings; the existing relationship/memory ledgers remain authoritative. */
const AI_SOCIAL_CATEGORIES=['dialogo','cordialita','rassicurazione','minaccia','promessa','favore','offesa','tradimento','confidenza','riconoscenza','rifiuto','corteggiamento','separazione','riconciliazione','scambio'];
const AI_SOCIAL_POLICY=[
    'social_flexibility'=>['default'=>60,'min'=>0,'max'=>100,'label'=>'Disponibilità a fare una pausa o cambiare attività'],
    'combat_hostility_min'=>['default'=>60,'min'=>40,'max'=>90,'label'=>'Ostilità minima verso chi tenta un’aggressione reale'],
    'combat_trust_ceiling'=>['default'=>-30,'min'=>-80,'max'=>0,'label'=>'Fiducia massima dopo un’aggressione · recuperabile giocando'],
    'emotional_reactivity'=>['default'=>2,'min'=>1,'max'=>3,'label'=>'Reattività emotiva'],
    'emotion_daily_limit'=>['default'=>60,'min'=>12,'max'=>100,'label'=>'Variazione emotiva massima / direzione / 24 ore'],
    'bond_daily_limit'=>['default'=>24,'min'=>6,'max'=>50,'label'=>'Crescita massima dei legami / 24 ore'],
    'meaningful_window_limit'=>['default'=>6,'min'=>2,'max'=>12,'label'=>'Episodi che rafforzano un legame / 5 minuti'],
    'romance_episodes_min'=>['default'=>4,'min'=>4,'max'=>20,'label'=>'Episodi reciproci minimi prima dell’innamoramento'],
    'romance_fear_limit'=>['default'=>50,'min'=>20,'max'=>80,'label'=>'Paura che sospende romanticismo e nuove aspirazioni'],
    'trauma_threshold'=>['default'=>18,'min'=>8,'max'=>60,'label'=>'Intensità minima di un trauma narrativo'],
    'trauma_memory_days'=>['default'=>14,'min'=>1,'max'=>90,'label'=>'Giorni di influenza di un trauma sul contegno generale'],
    'allow_nature_aspirations'=>['default'=>1,'min'=>0,'max'=>1,'label'=>'Può maturare desideri di cambiare natura']
];
function ai_social_policy_defaults():array {
    return array_map(static fn($rule)=>$rule['default'],AI_SOCIAL_POLICY);
}
/** One shared rate limiter for immediate perception and later reciprocal feelings. */
function ai_social_bounded_delta(string $key,int $raw,string $category,array $before,array $rules,bool $significant,bool $bond,bool $romance,string $evidence,array $day):int {
    $delta=ai_social_delta($key,$raw,$category,$before,$rules,$significant,$bond,$romance,$evidence);$used=0;
    foreach($day as $event){$a=$event['_decoded_after']??(json_decode($event['after_state']??'{}',true)?:[]);$b=$event['_decoded_before']??(json_decode($event['before_state']??'{}',true)?:[]);$change=(int)($a[$key]??0)-(int)($b[$key]??0);if(($delta>0&&$change>0)||($delta<0&&$change<0))$used+=abs($change);}
    $daily=match($key){'love'=>9,'attraction','jealousy'=>12,'affection'=>min(24,(int)$rules['bond_daily_limit']),'fear','hostility'=>(int)$rules['emotion_daily_limit'],default=>$delta<0?(int)$rules['emotion_daily_limit']:(int)$rules['bond_daily_limit']};
    $limit=max(0,$daily-$used);return max(-$limit,min($limit,$delta));
}
function ai_social_episode_category(string $category,string $player,string $npc,array $deltas=[]):string {
    if(!in_array($category,AI_SOCIAL_CATEGORIES,true))return 'dialogo';
    $plain=ai_social_evidence_key($player);
    // Greetings and purchased praise cannot become a meaningful favour just because a model says so.
    if(preg_match('/^(?:salve|ciao|buon ?giorno|buona ?sera|buona notte|grazie|prego|piacere|come stai|come state)(?:\s+(?:a voi|a te|signore|signora|mio signore|mia signora|mille))?$/u',$plain))return 'dialogo';
    // Some small models recognise relief in deltas but label it ordinary dialogue.
    // Reconcile only that explicit proposal and accepted observable reassurance; no score is guessed.
    if($category==='dialogo'&&is_int($deltas['fear']??null)&&$deltas['fear']<0&&ai_social_reassurance($player,$npc))$category='rassicurazione';
    if($category==='cordialita'&&count(explode(' ',$plain))<7)return 'dialogo';
    if($category==='rassicurazione'&&!ai_social_reassurance($player,$npc))return 'dialogo';
    if($category==='riconciliazione'&&!ai_social_reconciliation($player,$npc))return 'dialogo';
    return $category;
}
/** Apologies open a possibility; only an NPC's own acceptance starts recovery. */
function ai_social_reconciliation(string $player,string $npc):bool {
    $offer=preg_match('/\b(?:chiedo\s+scusa|chiedo\s+perdono|mi\s+dispiace|ho\s+sbagliato|sono\s+pentit[oa])\b/iu',$player);
    if(!$offer||preg_match('/non\s+(?:vi\s+|ti\s+)?(?:chiedo\s+scusa|chiedo\s+perdono)|non\s+mi\s+dispiace|colpa\s+(?:tua|vostra)|(?:devi|dovete)\s+perdonar/iu',$player))return false;
    $speech=ai_narrative_parse($npc)['speech'];
    if(preg_match('/non\b.{0,25}(?:accetto|perdono|credo|fido|pront[oa]|ascoltar)|troppo\s+tardi|vattene|andatevene/iu',$speech))return false;
    return (bool)preg_match('/accetto\b.{0,20}(?:scuse|scusa)|apprezzo\b.{0,30}(?:scuse|scusa|gesto)|(?:posso|voglio|proviamo\s+a)\s+(?:ascoltar|riparlar)|vi\s+ascolto|ti\s+ascolto|dar(?:ti|vi)\s+(?:una\s+)?possibilità|riproviamo|ricominciamo|ti\s+perdono|vi\s+perdono/iu',$speech);
}
/** A player cannot order away fear: the published NPC must visibly accept the reassurance. */
function ai_social_reassurance(string $player,string $npc):bool {
    $offer=preg_match('/non\s+(?:ti|vi|voglio|intendo|desidero).{0,35}(?:mal[ei]|ferir|farvi|farti|nuocer|aggredir)|(?:puoi|potete).{0,20}(?:allontanar|andare|tranquill)|rispett.{0,25}(?:paura|distanza|scelta)|non\s+(?:sei|siete).{0,18}pericolo/iu',$player);
    $refusal=preg_match('/non.{0,16}(?:mi fido|ti credo|vi credo|si fida|si calma|si rilassa|riesce a calmarsi)|non.{0,12}(?:tranquill|rassicurat)|(?:ancora|continu[oa] a).{0,20}(?:tremare|paura|terrore)|non.{0,12}(?:mi calmo|si calma|riesco a calmarmi|riesce a calmarsi)/iu',$npc);
    $accept=preg_match('/(?:si|mi)\s+(?:calm|rilass)|(?:respiro|respira).{0,25}(?:regolar|lent|calm)|(?:abbassa|allenta).{0,25}(?:spalle|guardia|presa)|(?:mi fido|ti credo|vi credo|meno paura|smette di tremare)/iu',$npc);
    return (bool)($offer&&$accept&&!$refusal);
}
function ai_social_reciprocal_interest(string $npc):bool {
    if(preg_match('/restiamo\s+amic|preferisco.{0,35}(?:amic|distan)|non\s+ricambio/iu',$npc))return false;
    if(preg_match('/non.{0,20}(?:mi piaci|ti amo|vi amo|provo.{0,12}(?:amore|attrazione|sentiment)|sono interessat)|non.{0,20}(?:sono|mi sento).{0,12}(?:attratt|innamorat)|solo.{0,10}(?:amicizia|amici)|come\s+(?:un[ao]\s+)?amic[oa]|affetto\s+fratern[oa]|non.{0,15}(?:desidero|voglio).{0,20}(?:relazione|corteggi)/iu',$npc))return false;
    return (bool)preg_match('/mi piaci\b|mi piacete\b|mi piace\s+stare con (?:te|voi)|ti amo|vi amo|(?:sono|mi sento).{0,14}(?:attratt|innamorat)|(?:provo|sento).{0,25}(?:sentiment|amore|attrazione)|(?:vorrei|desidero).{0,18}(?:rivederti|rivedervi|conoscerti|conoscervi|stare con te|stare con voi)|(?:ti|vi).{0,15}(?:bac[io]|abbracc)/iu',$npc);
}
/** A later accepted reassurance can end a temporary panic posture, without erasing witnessed knowledge. */
function ai_social_emotion_declared(string $key,string $reply):bool {
    $speech=ai_narrative_parse($reply)['speech'];
    $patterns=['fear'=>'paura|terrore|spavent','trust'=>'mi fido|fiducia','hostility'=>'odio|ti detesto|vi detesto|nemic','love'=>'ti amo|vi amo|innamorat','attraction'=>'attratt|attrazione|mi piaci|mi piacete','sympathy'=>'simpati','respect'=>'ti rispetto|vi rispetto|rispetto per','honor'=>'onore','affection'=>'affetto|ti voglio bene|vi voglio bene','jealousy'=>'gelosi'];
    return isset($patterns[$key])&&(bool)preg_match('/(?:'.$patterns[$key].')/iu',$speech);
}
/** Only a character's published own words/body disclose feelings, never its hidden sheet.
 * Physical panic is observable even when the NPC tries to hide its thoughts. Conversely,
 * another person's fear, a hypothetical sentence or a thought is not this NPC's admission.
 */
function ai_social_manifested_emotions(array $npc,string $reply,bool $physical=true):array {
    $parts=ai_narrative_parse($reply,['channel'=>'action'])['segments'];$shown=[];
    $speechPatterns=[
        'fear'=>'(?:ho|provo|sento)\\s+(?:(?:molta|una|grande|troppa)\\s+)*(?:paura|terrore)|mi\\s+(?:fai|fate)\\s+paura|sono\\s+(?:spaventat[oa]|terrorizzat[oa])',
        'trust'=>'(?:non\\s+)?mi\\s+fido\\s+di\\s+(?:te|voi)|(?:ho|provo)\\s+fiducia\\s+in\\s+(?:te|voi)',
        'hostility'=>'(?:ti|vi)\\s+(?:odio|detesto)|(?:sei|siete)\\s+(?:un\\s+)?mi[oa]\\s+nemic[oa]',
        'love'=>'(?:ti|vi)\\s+amo|sono\\s+innamorat[oa]\\s+di\\s+(?:te|voi)',
        'attraction'=>'mi\\s+(?:piaci|piacete)|sono\\s+attratt[oa]\\s+da\\s+(?:te|voi)',
        'sympathy'=>'mi\\s+(?:sei|siete)\\s+simpatic[oa]|provo\\s+simpatia\\s+per\\s+(?:te|voi)',
        'respect'=>'(?:ti|vi)\\s+rispetto|(?:ho|provo)\\s+rispetto\\s+per\\s+(?:te|voi)',
        'affection'=>'(?:ti|vi)\\s+voglio\\s+bene|provo\\s+affetto\\s+per\\s+(?:te|voi)',
        'jealousy'=>'sono\\s+gelos[oa]\\s+di\\s+(?:te|voi)'
    ];
    $name=trim((string)($npc['name']??''));$first=explode(' ',$name)[0]??'';$ownAction=false;
    foreach($parts as $part){
        $kind=$part['kind']??'';$text=(string)($part['text']??'');
        if(!in_array($kind,['speech','action'],true))continue;
        foreach(preg_split('/(?<=[.!?;])\\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[] as $sentence){
            if($kind==='speech'){
                if(preg_match('/\\b(?:se|forse|ipoteticamente|diceva|ha\\s+detto|ripeti|ripetete)\\b|\\?$/iu',$sentence))continue;
                foreach($speechPatterns as $key=>$pattern)if(preg_match('/(?:'.$pattern.')/iu',$sentence)){
                    // A negated feeling can be stated, but it is not proof of the opposite
                    // score. Trust's explicit refusal is itself a public stance.
                    if($key!=='trust'&&preg_match('/\\bnon\\b.{0,18}(?:'.$pattern.')/iu',$sentence))continue;
                    $shown[$key]=true;
                }
                continue;
            }
            if(!$physical)continue;
            if($first!==''&&preg_match('/^(?:'.preg_quote($name,'/').'|'.preg_quote($first,'/').')\\b/iu',trim($sentence)))$ownAction=true;
            elseif(preg_match('/^\\s*([\\p{L}\\p{M}]+)(?:\\s+\\p{Lu}[\\p{L}\\p{M}]+){0,2}\\s+(?:trema|piange|ha|si|fugge|scappa|rimane|resta|sente|respira|guarda|osserva|nota|cerca|è|era|diventa)\\b/u',$sentence,$subject)
                &&!in_array(mb_strtolower($subject[1]),['il','lo','la','gli','le','un','una','uno','lui','lei','egli','ella','poi','quindi','allora','intanto','infine','tuttavia','mani','dita','gambe','braccia','voce','respiro','fiato','sguardo'],true))$ownAction=false;
            if(!$ownAction)continue;
            // Merely observing someone else's panic never reveals this NPC's emotions.
            if(preg_match('/\\b(?:osserva|guarda|nota|vede|scorge|racconta|immagina|finge|freddo|febbre|fatica|malattia|dolore)\\b/iu',$sentence))continue;
            $panic='(?:trem(?:a|ano|ore|ito)|fiato\\s+mozzo|respiro\\s+(?:si\\s+spezza|spezzato)|(?:paura|terrore|spavento).{0,35}(?:blocca|ferma|immobil|pietrific|scapp|fugg)|(?:fugge|scappa|cerca\\s+riparo).{0,45}(?:paura|terrore)|(?:terrorizzat|pietrificat|spaventat)[oa])';
            if(preg_match('/'.$panic.'/iu',$sentence)&&!preg_match('/\\b(?:non|senza|smette\\s+di)\\b.{0,18}'.$panic.'/iu',$sentence))$shown['fear']=true;
        }
    }
    return array_keys($shown);
}
/** Severe involuntary terror is represented on the same 0–100 scale as all other emotions.
 * This is a minimum posture intensity, not an additive reward from repeated transformations.
 */
function ai_social_shock_fear(array $reaction,array $event):int {
    if(empty($reaction['shock'])||!in_array($reaction['mode']??'',['flee','freeze'],true))return 0;
    $courage=max(0,min(100,(int)($reaction['courage']??50)));
    $control=max(0,min(100,(int)($reaction['self_control']??50)));
    $ancestral=in_array((int)($event['native_race_id']??0),[5,6],true)?5:0;
    return min(95,max(($reaction['mode']==='flee'?75:65),95-(int)round(.18*$courage+.12*$control)+$ancestral));
}
/** A bystander must explicitly address a feeling to the current actor: a frightened
 * body alone does not identify who caused the fear in a room with several people.
 */
function ai_social_observer_deltas(array $deltas,string $reply):array {
    $speech=ai_narrative_parse($reply)['speech'];$allowed=[];
    if(preg_match('/\\b(?:mi\\s+(?:fai|fate)\\s+paura|ho\\s+paura\\s+di\\s+(?:te|voi))\\b/iu',$speech))$allowed['fear']=max(0,is_int($deltas['fear']??null)?$deltas['fear']:0);
    if(preg_match('/\\b(?:ti|vi)\\s+(?:odio|detesto)\\b/iu',$speech))$allowed['hostility']=max(0,is_int($deltas['hostility']??null)?$deltas['hostility']:0);
    if(preg_match('/\\bnon\\s+mi\\s+fido\\s+di\\s+(?:te|voi)\\b/iu',$speech))$allowed['trust']=min(0,is_int($deltas['trust']??null)?$deltas['trust']:0);
    return array_filter($allowed,static fn($value)=>$value!==0);
}
function ai_social_reassured_since(int $npc,int $player,int $time,int $source=0,int $beforeSource=0):bool {
    if($npc<1||$player<1||$time<1)return false;
    $rows=ai_db()->query("SELECT before_state,after_state FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND actor_id=0 AND created_at>=? AND JSON_UNQUOTE(JSON_EXTRACT(after_state,'$._evolution.category'))='rassicurazione'".ai_relation_context_event_scope($npc,$player).' ORDER BY id DESC LIMIT 10',[$npc,$player,$time]);
    foreach($rows as $row){
        $before=json_decode($row['before_state'],true)?:[];$after=json_decode($row['after_state'],true)?:[];
        if((int)($after['fear']??0)>=(int)($before['fear']??0))continue;
        if($source>0&&(int)($after['_evolution']['source_id']??0)<=$source)continue;
        if($beforeSource>0&&(int)($after['_evolution']['source_id']??0)>$beforeSource)continue;
        return true;
    }
    return false;
}
/** Reactive harm is not suppressed when a scene's positive-bond budget has been used. */
function ai_social_delta(string $key,int $raw,string $category,array $before,array $rules,bool $significant,bool $bondEpisode,bool $romance,string $npcEvidence):int {
    if(!$significant)return 0;
    $negative=in_array($category,['offesa','minaccia','tradimento','rifiuto','separazione'],true);
    $reactive=($negative&&(($raw<0&&in_array($key,['sympathy','trust','respect','honor','affection','attraction','love'],true))||($raw>0&&in_array($key,['fear','hostility'],true))))||($category==='rassicurazione'&&$key==='fear'&&$raw<0)||($category==='riconciliazione'&&in_array($key,['fear','hostility'],true)&&$raw<0);
    if(!$bondEpisode&&!$reactive)return 0;
    $factor=(int)$rules['emotional_reactivity'];
    if(in_array($category,['offesa','minaccia','tradimento','favore','confidenza','riconciliazione'],true))$factor*=2;
    $delta=max(-3,min(3,$raw))*$factor;
    if($negative&&$delta>0&&in_array($key,['sympathy','trust','respect','honor','affection','attraction','love'],true))return 0;
    if(in_array($key,['attraction','love','jealousy'],true)&&!$romance)return 0;
    if($delta>0&&in_array($key,['attraction','love'],true)&&((int)$before['fear']>=(int)$rules['romance_fear_limit']||(int)$before['hostility']>=20))return 0;
    if($delta>0&&in_array($key,['attraction','love'],true)&&(!in_array($category,['corteggiamento','riconciliazione'],true)||!ai_social_reciprocal_interest($npcEvidence)))return 0;
    if(in_array($category,['scambio','riconoscenza'],true)&&$delta>0&&in_array($key,['trust','affection','attraction','love'],true))return 0;
    if($key==='love'&&$delta>0&&!in_array($before['state'],['corteggiamento','innamoramento','relazione','crisi','riconciliazione'],true))return 0;
    if($key==='jealousy'&&!in_array($before['state'],['relazione','crisi','riconciliazione'],true))return 0;
    return match($key){'affection'=>max(-8,min(6,$delta)),'attraction'=>max(-6,min(4,$delta)),'love'=>max(-8,min(3,$delta)),default=>max(-18,min(18,$delta))};
}
/** Current aspirations are a projection of revocable, spoken declarations, never transformation orders. */
function ai_social_aspiration(string $raw):?array {
    $parts=function_exists('ai_narrative_parse')?(ai_narrative_parse($raw)['segments']??[]):[];
    foreach($parts as $part){
        if(($part['kind']??'')!=='speech')continue;
        foreach(preg_split('/(?<=[.!?])\s+/u',(string)$part['text'])?:[] as $sentence){
            if(str_contains($sentence,'?')||preg_match('/\b(?:se|forse|ipoteticamente|dice|disse|diceva|vorresti|volete|vorreste)\b/iu',$sentence))continue;
            if(!preg_match('/^(?:(?:io|ora|invece|ormai|sì[,;]?|si[,;]?)\s+)*(non\s+)?(?:voglio|vorrei|desidero|scelgo di|ho deciso di)\s+(?:mai\s+|più\s+|davvero\s+)?(?:diventare|essere trasformat[oa]|farmi trasformare)\s+(?:in\s+)?(?:un[ao]?\s+)?(vampir[oa]|licantrop[oa])/iu',$sentence,$m))continue;
            return ['family'=>str_starts_with(mb_strtolower($m[2]),'vampir')?'vampiro':'licantropo','stance'=>empty($m[1])?'desiderio':'rifiuto','evidence'=>ai_text($sentence,400)];
        }
    }
    return null;
}
function ai_social_development_record(array $npc,array $job,array $before,array $after,array $rules,bool $significant,string $playerEvidence,string $npcEvidence,bool $declarationEligible=false,string $episodeCategory=''):void {
    if(!$significant&&!$declarationEligible)return;
    $db=ai_db();$nid=(int)$npc['id'];$uid=(int)$job['user_id'];$now=time();$changes=[];$harm=0;
    foreach(AI_RELATION_METRICS as $key=>$label){$delta=(int)$after[$key]-(int)$before[$key];if($delta)$changes[]=($delta>0?'maggiore ':'minore ').mb_strtolower($label);if(($delta<0&&in_array($key,['trust','respect','sympathy'],true))||($delta>0&&in_array($key,['fear','hostility'],true)))$harm+=abs($delta);}
    if($significant&&($changes||$after['state']!==$before['state'])){
        $trauma=$harm>=(int)$rules['trauma_threshold']&&in_array($episodeCategory,['minaccia','tradimento','separazione'],true);$category=$trauma?'trauma':'evoluzione';
        $summary='Dopo parole o gesti dichiarati: «'.ai_text($playerEvidence,110).'», '.($trauma?'questo incontro ha lasciato una ferita emotiva: ':'il rapporto si è evoluto: ').implode(', ',$changes).'. '.($after['state']!==$before['state']?'Il legame passa a '.$after['state'].'. ':'').'È l’esperienza personale del PNG, non una prova delle intenzioni del PG; può evolvere con nuovi incontri.';
        $db->execute("INSERT IGNORE INTO ai_personal_memories(npc_id,player_id,chat_key,job_id,category,summary,evidence,source,epistemic,visible,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'interpreted','interpretation',?,?,?)",[$nid,$uid,$job['chat_key'],(int)$job['id'],$category,$summary,ai_text($playerEvidence.' / '.$npcEvidence,700),empty($rules['hidden_emotions'])?1:0,$now,$now]);
    }
    if(!$declarationEligible||preg_match('/\b(?:ripeti|ripetete|dichiara|dichiarate|scrivi|scrivete)\b/iu',$playerEvidence))return;
    $reply=(string)(json_decode((string)$job['result'],true)['testo']??'');$wish=ai_social_aspiration($reply);
    if(!$wish||(empty($rules['allow_nature_aspirations'])&&$wish['stance']!=='rifiuto'))return;
    if($wish['stance']==='desiderio'&&(int)$after['fear']>=(int)$rules['romance_fear_limit'])return;
    // No private DB race may inform a desire about the interlocutor's hidden bloodline.
    $known=function_exists('ai_perception_known_race')?ai_perception_known_race($nid,$uid,(int)($job['source_id']??0)):0;
    $family=function_exists('ai_perception_race_family')?ai_perception_race_family($known):0;
    if(($wish['family']==='vampiro'&&$family!==2)||($wish['family']==='licantropo'&&$family!==3))return;
    $category=$wish['stance'].'_'.$wish['family'];
    $prior=$db->query("SELECT category FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1 AND category IN(?,?)".ai_relation_context_memory_scope($nid,$uid).' ORDER BY id DESC LIMIT 1',[$nid,$uid,'desiderio_'.$wish['family'],'rifiuto_'.$wish['family']])[0]??[];
    if(($prior['category']??'')===$category)return;
    $summary=($wish['stance']==='desiderio'?'Ha espresso di propria voce il desiderio di diventare ':'Ha espresso di propria voce il rifiuto di diventare ').'un '.$wish['family'].'. È una scelta dichiarata e revocabile: non è una trasformazione, un obbligo, né un consenso irrevocabile.';
    $db->execute("INSERT IGNORE INTO ai_personal_memories(npc_id,player_id,chat_key,job_id,category,summary,evidence,source,epistemic,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'dialogue','dialogue',?,?)",[$nid,$uid,$job['chat_key'],(int)$job['id'],$category,$summary,$wish['evidence'],$now,$now]);
}
/** Own-pair projection for the player; staff must have been authorised by its controller. */
function ai_social_development_view(int $npc,int $player,bool $staff=false):array {
    $db=ai_db();$scope=$staff?'':' AND visible=1';
    $story=$db->query("SELECT id,category,summary,evidence,created_at,chat_key FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1".$scope." AND category IN('evoluzione','trauma') ORDER BY id DESC LIMIT 6",[$npc,$player]);
    $aspirations=[];
    // A choice cannot disappear merely because many newer emotional episodes were recorded.
    foreach(['vampiro','licantropo'] as $family){
        $m=$db->query("SELECT id,category,summary,evidence,created_at,chat_key FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1".$scope." AND category IN(?,?) ORDER BY id DESC LIMIT 1",[$npc,$player,'desiderio_'.$family,'rifiuto_'.$family])[0]??null;
        if($m)$aspirations[]=['family'=>$family,'stance'=>str_starts_with($m['category'],'rifiuto')?'rifiuto':'desiderio',...$m];
    }
    return ['story'=>$story,'aspirations'=>$aspirations];
}
/** Public trauma may alter general caution, while names, private rooms and other pair secrets stay out. */
function ai_social_development_context(array $npc,array $job,array $rules,float $scale=1):string {
    $nid=(int)$npc['id'];$uid=(int)$job['user_id'];$chat=(string)($job['chat_key']??'');$private=preg_match('/^(?:rent_|pvt_)/D',$chat)===1;
    $public="LEFT(chat_key,5)<>'rent_' AND LEFT(chat_key,4)<>'pvt_'";$scope=$private?"($public OR chat_key=?)":"($public)";$params=[$nid,$uid];if($private)$params[]=$chat;
    $memoryScope=ai_relation_context_memory_scope($nid,$uid);
    $wish=ai_db()->query("SELECT category FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1 AND $scope AND category IN('desiderio_vampiro','rifiuto_vampiro','desiderio_licantropo','rifiuto_licantropo')".$memoryScope.' ORDER BY id DESC LIMIT 1',$params)[0]??[];
    $out="\nEVOLUZIONE: ostilità e offese possono ferirti; rispetto concreto, ascolto e aiuto accolti possono farti fidare già nello stesso incontro. Dopo uno spavento puoi calmarti se accetti rassicurazioni e distanza, senza dimenticare il segreto visto. Paura, affetto, lealtà e amore sono distinti: non convertirli automaticamente l’uno nell’altro. Non leggere sincerità, pensieri o consenso del PG.";
    if($scale<=.5)$out="\nLEGAME: offese e aiuti incidono; rassicurazioni accolte calmano senza dimenticare segreti. Affetto e lealtà non impongono amore. ";
    if($wish)$out.=' Tua ultima scelta dichiarata verso questo interlocutore: '.str_replace('_',' ',$wish['category']).'; puoi confermarla o revocarla, non trasformarti automaticamente.';
    // Other relationships retain their history. This pair's superseded trauma
    // cannot return indirectly as general caution in this same interaction.
    $trauma=ai_db()->query("SELECT id FROM ai_personal_memories WHERE npc_id=? AND active=1 AND category='trauma' AND $public AND created_at>? AND (player_id<>? OR (1=1".$memoryScope.')) ORDER BY id DESC LIMIT 1',[$nid,time()-(int)$rules['trauma_memory_days']*86400,$uid]);
    if($trauma)$out.=' Un’esperienza pubblica recente ti ha segnato: puoi essere più prudente; non attribuire la colpa all’interlocutore attuale né raccontare vicende di altre persone.';
    if(empty($rules['allow_nature_aspirations']))$out.=' Non introdurre nuovi desideri di cambiare natura; puoi sempre revocare scelte già dichiarate.';
    else $out.=' Puoi maturare e dire un desiderio o rifiuto personale di cambiare natura solo se coerente con storia, conoscenze e volontà; non farne una risposta obbligata a fascinazione, minacce o richieste del PG.';
    return $scale<=.5?"\n".ai_text($out,180):$out;
}
/** Called only with a persisted server-resolved wound. No LLM claim establishes an aggressor. */
function ai_social_survival_effect(array $effect,array $context):void {
    $db=ai_db();if(!$db->inTransaction())throw new LogicException('Transazione richiesta per una memoria di combattimento.');
    $nid=(int)($context['npc_id']??0);$uid=(int)($context['player_id']??0);$hp=(int)($context['after_hp']??0);$beforeHp=(int)($context['before_hp']??0);$key=(string)($context['effect_key']??'');
    if($nid<1||$uid<1||$hp<1||$beforeHp<=$hp||($context['actor_kind']??'')!=='pg'||(int)($context['actor_id']??0)!==$uid||$key===''||strlen($key)>180||($effect['type']??'')!=='health')return;
    $npc=$db->query('SELECT id FROM ai_npcs WHERE id=?',[$nid])[0]??null;if(!$npc||!ai_player_identity($uid))return;
    $now=time();$rules=ai_social_rules($nid);$db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$uid,$now,$now]);
    $before=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$uid])[0];
    if($db->query("SELECT id FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND JSON_UNQUOTE(JSON_EXTRACT(after_state,'$._survival.effect_key'))=? LIMIT 1",[$nid,$uid,$key]))return;
    $impact=min(18,max(6,(int)ceil(($beforeHp-$hp)/max(1,$beforeHp)*18)));$after=$before;
    // Repair an old missing memory without undoing later play, reconciliation or staff corrections.
    $eventTime=(int)($context['event_time']??$now);
    if(!empty($context['historical'])&&((int)$before['meaningful']>0||array_sum(array_map(static fn($k)=>abs((int)$before[$k]),array_keys(AI_RELATION_METRICS)))>0||$eventTime<$now-(int)$rules['trauma_memory_days']*86400))$impact=0;
    $current=ai_relation_context_current($nid,$uid);$source=(int)($context['source_id']??0);
    // Reconstructed effects retain an audit receipt, but cannot create a fresh
    // active trauma from a source the staff has already superseded.
    $superseded=!empty($context['historical'])&&$current&&$source>0&&$source<=(int)$current['chat_id'];
    if($superseded)$impact=0;
    foreach(['fear'=>1,'hostility'=>1,'trust'=>-1] as $metric=>$sign)$after[$metric]=max($metric==='trust'?-100:0,min(100,(int)$before[$metric]+$impact*$sign));
    $after['updated_at']=$now;$db->execute('UPDATE ai_relationships SET fear=?,hostility=?,trust=?,updated_at=? WHERE npc_id=? AND player_id=?',[$after['fear'],$after['hostility'],$after['trust'],$now,$nid,$uid]);
    $after['_survival']=['effect_key'=>$key,'source_id'=>(int)($context['source_id']??0),'before_hp'=>$beforeHp,'after_hp'=>$hp,'event_time'=>$eventTime,'historical'=>!empty($context['historical'])];
    ai_relation_event($nid,$uid,$before,$after,'Sopravvissuto a ferita risolta dal gioco: paura, ostilità e diffidenza verso l’aggressore verificato.',$context);
    $db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,epistemic,visible,active,created_at,updated_at) VALUES(?,?,?,'trauma',?,?,'system','verified',?,?,?,?)",[$nid,$uid,(string)($context['chat_key']??''),'È sopravvissuto a una ferita causata da questo personaggio. Il ricordo alimenta paura e risentimento; potrà cercare protezione o giustizia, senza ottenere vendette o poteri automatici.','Esito verificato '.$key.'; fonte #'.(int)($context['source_id']??0).'; salute '.$beforeHp.' → '.$hp.'.',empty($rules['hidden_emotions'])?1:0,$superseded?0:1,$eventTime,$now]);
}
