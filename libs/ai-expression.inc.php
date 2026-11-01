<?php
declare(strict_types=1);

/** Prompt excerpts are complete sentences where possible, otherwise complete UTF-8 words. */
function ai_expression_excerpt(string $text,int $budget):string {
    if($budget<1)return '';
    $text=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
    if(mb_strlen($text)<=$budget)return $text;
    $sentences=preg_split('/(?<=[.!?;])\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[$text];$complete='';
    foreach($sentences as $sentence){
        $candidate=$complete===''?$sentence:$complete.' '.$sentence;
        if(mb_strlen($candidate)>$budget)break;
        $complete=$candidate;
    }
    if($complete!=='')return $complete;
    $cut=mb_substr($text,0,$budget);$next=mb_substr($text,$budget,1);
    if($next!==''&&!preg_match('/\s|[.!?;,):]/u',$next)&&preg_match('/[\p{L}\p{N}]$/u',$cut))$cut=preg_replace('/(?:^|\s)\S*$/u','',$cut)??'';
    $cut=preg_replace('/[\s,;:–—-]+$/u','',$cut)??'';
    // Do not leave a dangling qualification such as "senza " or "non ".
    $tail='/\s+(?:e|o|ma|con|senza|di|del|dello|della|dei|degli|delle|a|al|allo|alla|ai|agli|alle|da|dal|dallo|dalla|dai|dagli|dalle|in|nel|nello|nella|nei|negli|nelle|su|sul|sullo|sulla|per|tra|fra|un|uno|una|il|lo|la|i|gli|le|non|che|si|è)$/iu';
    while(preg_match($tail,$cut))$cut=rtrim(preg_replace($tail,'',$cut)??''," ,;:");
    return $cut;
}

/** One temperament description, deduplicated without throwing away qualifying words. */
function ai_expression_persona(array $npc,array $rules,int $budget=320):string {
    if($budget<1)return '';
    $normalize=static fn(string $text):string=>trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text))??'');
    $profile=is_array($npc['profile']??null)?$npc['profile']:(json_decode((string)($npc['profile']??'{}'),true)?:[]);
    $occupation=$normalize((string)($profile['occupation']??''));$name=$normalize((string)($npc['name']??''));$units=[];$position=0;
    foreach([[(string)($npc['personality']??''),40],[(string)($rules['honor_code']??''),35],[(string)($npc['character_text']??''),25]] as [$field,$priority]){
        $field=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($field),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
        foreach(preg_split('/(?<=[.!?])\s+/u',$field,-1,PREG_SPLIT_NO_EMPTY)?:[] as $sentence){
            $sentence=trim($sentence);$key=$normalize($sentence);if($key==='')continue;
            // The surrounding prompt already supplies name and occupation.
            if($key===$occupation||$key===$name||($occupation!==''&&$key===$name.' '.$occupation))continue;
            $score=$priority+(preg_match('/\b(?:senza|non|mai|detesta|rifiuta|rispetta|evita|arroganza|bullismo|compassione|generos\p{L}*|pazien\p{L}*)\b/iu',$sentence)?15:0);
            if(preg_match('/\b(?:per prendere tempo|abitud\p{L}*|tic)\b/iu',$sentence))$score-=25;
            $duplicate=false;
            foreach($units as &$old){
                // Punctuation/case differences and complete overlapping descriptions
                // collapse to the richer unit; "senza arroganza" must survive.
                if(str_contains(' '.$old['key'].' ',' '.$key.' ')){$old['score']=max($old['score'],$score);$duplicate=true;break;}
                if(str_contains(' '.$key.' ',' '.$old['key'].' ')){$old=['text'=>$sentence,'key'=>$key,'score'=>max($old['score'],$score),'position'=>$old['position']];$duplicate=true;break;}
            }
            unset($old);
            if(!$duplicate)$units[]=['text'=>$sentence,'key'=>$key,'score'=>$score,'position'=>$position++];
        }
    }
    if(($rules['voice_example']??'')!==''&&function_exists('ai_voice_register')){
        $register=ai_voice_register((string)$rules['voice_example']);
        // Keep only the derived register, never the concrete example or its scene.
        $register=trim(preg_split('/(?<=[.!?])\s+/u',$register,2)[0]??'');
        if($register!=='')$units[]=['text'=>'Voce: '.$register,'key'=>$normalize($register),'score'=>45,'position'=>$position++];
    }
    usort($units,static fn($a,$b)=>[$b['score'],-$b['position']]<=>[$a['score'],-$a['position']]);
    $chosen=[];$used=0;
    foreach($units as $unit){
        $size=mb_strlen($unit['text']);if($used+$size+($chosen?1:0)>$budget)continue;
        $chosen[]=$unit;$used+=$size+($used?1:0);
    }
    // Only when no complete personality unit fits do we fall back to whole words.
    if(!$chosen&&$units)return ai_expression_excerpt($units[0]['text'],$budget);
    return implode(' ',array_column($chosen,'text'));
}

/** Biographical colour and unrelated canon should not become the current topic. */
function ai_expression_related(string $text,string $question):bool {
    $stop=array_fill_keys(['sono','come','avete','avere','avrei','vorrei','voglio','potete','posso','possiamo','siete','visto','vedere','dove','quando','quale','quali','questo','questa','quello','quella','della','delle','dello','degli','dalla','dalle','allo','alla','alle','nella','nelle','nello','anche','ancora','vicino','buonasera','buongiorno','grazie','salve','favore','sapere','chiedere','qualcosa'],true);
    preg_match_all('/\p{L}{4,}/u',mb_strtolower($question),$matches);
    $plain=mb_strtolower($text);
    foreach($matches[0] as $word){
        if(isset($stop[$word]))continue;
        $stem=mb_substr($word,0,mb_strlen($word)>5?5:mb_strlen($word));
        if(preg_match('/(?<!\p{L})'.preg_quote($stem,'/').'\p{L}*/u',$plain))return true;
    }
    return false;
}


/** A bounded language heuristic, including short conventional Italian replies. */
function ai_expression_italian(string $text):bool {
    if(preg_match('/[\x{3400}-\x{9fff}\x{0400}-\x{04ff}]/u',$text))return false;
    $words=preg_match_all('/\p{L}+(?:[’\x27]\p{L}+)*/u',$text);
    if($words<1)return false;
    preg_match_all('/\b(?:il|lo|la|gli|le|un|una|di|da|ha|del|della|che|non|si|è|con|per|nel|sul|dal|dallo|dalla|dalle|al|allo|alla|alle|sotto|tra|ma|e|a)\b/ui',$text,$markers);
    if(count($markers[0])>=max(2,min(5,(int)ceil($words/10))))return true;
    return $words<=12&&(bool)preg_match('/\b(?:grazie|prego|buonasera|buongiorno|salve|ditemi|ascolto|entrate|guardate|accomodatevi|volentieri|certamente|arrivederci|capisco|scusatemi|va\s+bene)\b/iu',$text);
}

/** Select the topic before clipping: even a short dossier may contain unrelated restrictions. */
function ai_expression_knowledge(string $text,string $question,int $budget=350):string {
    $sentences=preg_split('/(?<=[.!?;])\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
    $topics=[['music','melodi','suon','violin','canzon'],['allogg','stanza','stanze','camera','camere','dormir'],['proprietar','possess','appartien','sacca','borsa']];
    $matches=static function(string $sentence)use($question,$topics):bool{
        if(ai_expression_related($sentence,$question))return true;
        foreach($topics as $topic){$pattern='/'.implode('|',$topic).'/iu';if(preg_match($pattern,$question)&&preg_match($pattern,$sentence))return true;}
        return false;
    };
    $chosen=[];
    foreach($sentences as $i=>$sentence){
        if(!$matches($sentence))continue;
        $chosen[$i]=$sentence;
        // Preserve the uncertainty qualifying the preceding observed fact.
        $next=$sentences[$i+1]??'';
        if(preg_match('/^(?:non\s+(?:sa|conosce|ha\s+visto|ricorda)|ignora|ma\b|tuttavia\b|però\b)/iu',$next))$chosen[$i+1]=$next;
    }
    if(!$chosen)return ai_prompt_relevant_text($text,$question,$budget);
    ksort($chosen);
    return ai_prompt_relevant_text(implode(' ',$chosen),$question,$budget);
}

/** Narrative sampling follows the verified model profile; structured verdicts keep their own settings. */
function ai_expression_sampling(string $model,string $kind):array {
    static $registry=null;
    if($registry===null)$registry=json_decode((string)file_get_contents(__DIR__.'/../config/ai-models.json'),true,32,JSON_THROW_ON_ERROR);
    $sampling=['temperature'=>$kind==='npc-turn'?.4:.55,'top_p'=>.9,'top_k'=>40,'min_p'=>0.0,'repeat_penalty'=>1.0,'repeat_last_n'=>256];
    foreach($registry as $profile){
        if(($profile['alias']??'')!==$model)continue;
        $recommended=$profile['narrative_sampling']??$profile['recommended_sampling']??[];
        foreach(['temperature'=>[0.0,1.5],'top_p'=>[.1,1.0],'top_k'=>[1,100]] as $key=>[$min,$max]){
            $value=$recommended[$key]??null;
            if(is_int($value)||is_float($value))$sampling[$key]=max($min,min($max,$value));
        }
        $sampling['top_k']=(int)$sampling['top_k'];break;
    }
    $local=function_exists('ai_local_model_pin')?(ai_local_model_pin()['settings']??[]):[];
    foreach(['temperature','top_p','top_k','min_p','repeat_penalty'] as $key)if(isset($local[$key]))$sampling[$key]=$local[$key];
    return $sampling;
}

/** Constrain syntax only; meaning, identity, agency and equipment still need the usual guards. */
function ai_expression_format(array $npc,array $job):array {
    if(($job['kind']??'')!=='npc-turn')return [];
    $name=trim(preg_replace('/\s+/u',' ',(string)($npc['name']??''))??'');
    if($name===''||mb_strlen($name)>100)return [];
    $prefix=json_encode($name.' ',JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return ['grammar'=>'root ::= '.$prefix.' action (speech action?)?' ."\n".'action ::= [^«»<>\x00-\x1F]+' ."\n".'speech ::= "«" [^«»<>\x00-\x1F]+ "»"' ."\n"];
}

/** Feedback is a bounded instruction, never a database/network error copied into a prompt. */
function ai_expression_retry_hint(string $error,?array $perception=null):?string {
    foreach([
        '/^(?:Manca la reazione di paura|La paura personale|La replica ignora il pericolo|La reazione nega una metamorfosi|Mantieni la postura persistente|Il PNG è ancora scosso|Il profilo impone)/u'=>'La scena richiede paura visibile nel tuo corpo: tremore, fiato corto o voce spezzata. Non limitarti a cautela, verifica o voce ferma. Rispondi al pericolo attuale mantenendo soltanto i tuoi gesti.',
        '/^(?:Il PNG tratta una presa|Il turno PNG richiede una nuova stesura)/u'=>'Descrivi solo i tuoi tentativi: nessuna presa già chiusa, parata riuscita o posizione raggiunta se il Master non le ha accertate. Conserva anche lo stato emotivo e le ferite già presenti.',
        '/^(?:La correzione nega|Il PNG contraddice la propria identità|Il PNG ha copiato un appellativo)/u'=>'Conserva nome e sesso del PNG e del visitatore. Rispondi alla svista del visitatore; non copiarne il saluto e non invertirne i ruoli.',
        '/^(?:La risposta non supera il controllo italiano|Risposta fuori formato o lingua|Risposta narrativa non valida|Risposta incompleta|Risposta ripetuta)/u'=>'Scrivi un turno breve e completo in italiano: tuoi gesti in terza persona, parole tra «». Rispondi al punto attuale senza copiare frasi precedenti.',
        '/^Riscrittura necessaria:/u'=>'Rispondi concretamente alla domanda attuale del visitatore, usando solo fatti e ricordi verificati di questa coppia; non imporre esiti o inventare ostacoli.'
    ] as $pattern=>$hint)if(preg_match($pattern,trim($error))||trim($error)==='Riscrittura necessaria: '.$hint){
        // The retry may run after reassurance or a Master edit. Its old rejection
        // is not authority to restore panic that the current scene has resolved.
        if($perception!==null&&str_starts_with($hint,'La scena richiede paura visibile')&&empty($perception['shock'])&&(int)($perception['scene_state']['fear_intensity']??0)<60)return null;
        return $hint;
    }
    return null;
}
