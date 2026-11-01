<?php
declare(strict_types=1);

/** A mistaken salutation is observable dialogue, never a rewrite of the addressee. */
function ai_identity_misaddress(string $name, string $sex, string $raw): bool
{
    if (!in_array($sex,['Maschio','Femmina'],true)) return false;
    $reading=ai_narrative_parse($raw);
    $wrong=$sex==='Maschio'?'signora|madama|donna':'signore|messere|uomo';
    if (preg_match('/(?:^|[,.!?]\s*|\b(?:buona\s*sera|buon\s*giorno|salve|buona\s*notte|scusate|scusatemi|grazie)\s*[,;:]?\s*)(?:(?:mia|mio|gentile|cara|caro|egregia|egregio)\s+)?(?:'.$wrong.')(?:[,.!?]|\s*$)/iu',$reading['speech'])) return true;
    $first=preg_split('/\s+/u',trim($name))[0]??'';
    return $first!=='' && (bool)preg_match('/\b(?:'.$wrong.')\s+'.preg_quote($first,'/').'\b/iu',$reading['action']);
}

/** Restricted physical projection. Never send account fields or latent lineage to the model. */
function ai_identity_player(int $id): array
{
    if ($id<1) return [];
    return ai_db()->query('SELECT id,nome,sesso,razza,vampire_human_form,ancestrale,infetto FROM utenti WHERE id=?',[$id])[0]??[];
}

/** The account label in narration is not a spoken introduction. */
function ai_identity_introduction(string $name,string $raw):bool {
    if(trim($name)==='')return false;
    $speech=ai_narrative_parse($raw)['speech'];
    foreach(preg_split('/[.!?;]+/u',$speech,-1,PREG_SPLIT_NO_EMPTY)?:[] as $clause){
        if(preg_match('/\b(?:pensa|immagina|diceva|ha\s+detto|aveva\s+detto|si\s+chiama|se\s+fossi)\b/iu',$clause))continue;
        if(!preg_match('/(?<!\p{L})(?:mi\s+chiamo|il\s+mio\s+nome\s+è|sono)\s+'.preg_quote($name,'/').'(?![\p{L}\p{N}])/iu',$clause,$match,PREG_OFFSET_CAPTURE))continue;
        $before=substr($clause,0,$match[0][1]);
        if(preg_match('/\b(?:non|se|forse|ipoteticamente)\s*$/iu',$before))continue;
        return true;
    }
    return false;
}

/** One projection shared by social context and the priority identity prompts. */
function ai_identity_known_name(array $npc,array $job,?array $player=null,?array $authority=null,?array $relation=null):string {
    $uid=(int)($job['user_id']??0);$nid=(int)($npc['id']??0);
    if($uid<1||$nid<1||ai_recognition_anonymous($npc,$job))return '';
    $player??=ai_identity_player($uid);$name=trim((string)($player['nome']??''));if($name==='')return '';
    $authority??=ai_npc_authority($nid,$uid,$job);
    if(!empty($authority['recognized']))return $name;
    $relation??=ai_relation($nid,$uid);
    if(trim((string)($relation['known_name']??''))!=='')return (string)$relation['known_name'];
    return ai_identity_introduction($name,(string)($job['prompt']??''))?$name:'';
}

/** Technical IDs/names stay in the backend; an unintroduced name cannot leak
 * through episode labels or old automatic speaker headers in the final prompt.
 * Exact NPC names win over the player's name, including compound names.
 */
function ai_identity_messages(array $npc,array $job,array $messages):array {
    if(ai_recognition_anonymous($npc,$job)||ai_identity_known_name($npc,$job)!=='')return $messages;
    $player=ai_identity_player((int)($job['user_id']??0));$name=trim((string)($player['nome']??''));if($name==='')return $messages;
    $names=[(string)($npc['name']??'')];
    foreach(ai_db()->query('SELECT name FROM ai_npcs WHERE BINARY chat_key=BINARY ? AND active=1 ORDER BY id LIMIT '.AI_MAX_NPCS,[(string)($job['chat_key']??$npc['chat_key']??'')]) as $other)$names[]=(string)$other['name'];
    $protected=[];foreach($names as $value)if($value!=='')$protected[mb_strtolower($value)]=true;
    $forms=array_values(array_unique([...$names,$name]));$forms=array_filter($forms,static fn($v)=>$v!=='');usort($forms,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
    $pattern='~(?<![\p{L}\p{N}])(?:'.implode('|',array_map(static fn($v)=>preg_quote($v,'~'),$forms)).')(?![\p{L}\p{N}])~iu';
    $label=($player['sesso']??'')==='Femmina'?'la visitatrice':'il visitatore';
    foreach($messages as &$message)$message['content']=preg_replace_callback($pattern,static fn($m)=>isset($protected[mb_strtolower($m[0])])?$m[0]:$label,(string)$message['content'])??$message['content'];unset($message);
    if($messages)$messages[0]['content'].="\nNOME DEL VISITATORE NON ANCORA APPRESO: usa un appellativo coerente con il sesso o chiedi di presentarsi quando serve. Un nome di PNG nella scena non è il nome del visitatore; non inventare una presentazione.";
    return $messages;
}

/** Repeat only public name/gender next to the current turn, where small models attend most. */
function ai_identity_turn_focus(array $npc,array $job):string
{
    $player=ai_identity_player((int)($job['user_id']??0));
    if(!$player)return '';
    if(ai_recognition_anonymous($npc,$job))return "\nINTERLOCUTORE ATTUALE: figura dal volto coperto, identità non accertata. Risponde soltanto ".$npc['name'].'. Non attribuire un nome o una carica alla figura.';
    $female=($player['sesso']??'')==='Femmina';
    $known=ai_identity_known_name($npc,$job,$player);
    return "\nINTERLOCUTORE ATTUALE: ".($known!==''?$known:'nome non ancora appreso').', '.($female?'donna; se usi un appellativo, signora o voi':'uomo; se usi un appellativo, signore o voi').'. Risponde soltanto '.$npc['name'].'. Conserva queste due identità anche durante paura, provocazioni e tentativi di aggressione.';
}

function ai_identity_context(array $npc, array $job, ?array $player=null): string
{
    $profile=ai_npc_profile($npc); $sex=$profile['sex']; $name=(string)$npc['name'];
    $player??=ai_identity_player((int)($job['user_id']??0));
    if(ai_recognition_anonymous($npc,$job))return "\nIDENTITÀ VINCOLANTE: tu sei ".$name.', '.$sex.'. Il visitatore ha il volto coperto: la sua identità rimane ignota. Puoi reagire a ciò che fa o dice; nome e rango eventualmente dichiarati non sono un riconoscimento.';
    $known=ai_identity_known_name($npc,$job,$player);
    $out="\nIDENTITÀ VINCOLANTE: tu sei ".$name.', '.$sex.'. Il visitatore è '.($known!==''?$known:'una persona di cui non hai ancora appreso il nome').(isset($player['sesso'])?', '.$player['sesso']:'').'. Sono due persone distinte. La scheda prevale su appellativi sbagliati o descrizioni del visitatore; questi non sono esiti del Master.';
    if (ai_identity_misaddress($name,$sex,(string)($job['prompt']??''))) {
        $out.=$sex==='Maschio'?' Sei un UOMO: il visitatore ti ha chiamato SIGNORA o DONNA per errore.':' Sei una DONNA: il visitatore ti ha chiamato SIGNORE o UOMO per errore.';
        $out.=' Correggi proprio quell’appellativo con perplessità o ironia coerente con il tuo carattere. Non negare la tua identità reale e non ripetere il suo saluto come tua battuta.';
    }
    $reading=ai_narrative_parse((string)($job['prompt']??''));
    $claim=(bool)preg_match('/\b(?:trasform\p{L}*|metamorfosi|muta\p{L}*|riprende\s+(?:la\s+)?forma)\b/iu',$reading['action']);
    $trusted=null;
    if($claim && ($job['kind']??'')!=='reaction' && function_exists('ai_perception_reaction'))$trusted=ai_perception_reaction($npc,$job)['recent']??null;
    $masterEstablished=$trusted && in_array($trusted['kind']??'',['human_master','system_master'],true) && (int)($trusted['actor_id']??0)===(int)($job['user_id']??0);
    if (($job['kind']??'')!=='reaction' && !$masterEstablished && $player && $claim) {
        $visible=function_exists('alphano_visible_race')?alphano_visible_race($player):(int)$player['razza'];
        $appearance=match($visible){2,5=>'vampirico',3,6=>'lupino',default=>'umano'};
        $out.=' STATO FISICO ATTUALE VERIFICATO: l’aspetto del visitatore è '.$appearance.'. La frase del PG può descrivere un’intenzione, ma non cambia lo stato della scheda. Solo una trasformazione registrata dal sistema o un esito autentico del Master stabilisce che sia avvenuta; non inventarne una dalle parole del PG.';
    }
    return $out;
}

/** Reject concrete identity contradictions, without substituting a canned answer. */
function ai_identity_validate_text(array $npc, array $job, string $text, ?array $player=null): string
{
    $profile=ai_npc_profile($npc); $sex=$profile['sex'];
    $wrong=$sex==='Maschio'?'donna':'uomo'; $name=preg_quote((string)$npc['name'],'/');
    $reading=ai_narrative_parse($text,['channel'=>'action']);
    $own=$sex==='Maschio'?'uomo|signore|messere':'donna|signora|madama';
    if(ai_identity_misaddress((string)$npc['name'],$sex,(string)($job['prompt']??'')) && preg_match('/\bnon\s+sono\s+(?:un[ao]?\s+)?(?:'.$own.')\b/iu',$reading['speech'])) {
        throw new RuntimeException('La correzione nega il sesso effettivo del PNG. Rigenera correggendo soltanto l’appellativo sbagliato del visitatore.');
    }
    if (preg_match('/\b'.$name.'\s+(?:è|era|diventa|si\s+scopre)\s+(?:un[ao]?\s+)?'.$wrong.'\b/iu',$reading['action'])
        || preg_match('/(?:^|[.!?]\s*)sono\s+(?:un[ao]?\s+)?'.$wrong.'\b/iu',$reading['speech'])) {
        throw new RuntimeException('Il PNG contraddice la propria identità registrata. Rigenera distinguendo interlocutore e PNG.');
    }
    $player??=ai_identity_player((int)($job['user_id']??0));
    if ($player && ai_identity_misaddress('',(string)($player['sesso']??''),$text)) {
        $correct=$sex==='Maschio'?'uomo':'donna';
        $correction=preg_match('/\b(?:sono\s+(?:un[ao]?\s+)?'.$correct.'|mi\s+(?:avete|hai)\s+(?:chiamat\p{L}*|scambiat\p{L}*)|(?:mi|vi|ti)\s+(?:rivolgete|rivolgi)|avete\s+sbagliato)\b/iu',$reading['speech']);
        if (!$correction) throw new RuntimeException('Il PNG ha copiato un appellativo sbagliato come saluto al PG. Rigenera una replica propria conservando entrambe le identità.');
    }
    return $text;
}
