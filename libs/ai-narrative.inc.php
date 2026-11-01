<?php
declare(strict_types=1);

/**
 * Bounded, deterministic interpretation of player prose. This is evidence for the
 * dialogue model and rules engine, never an authorization to invent consequences.
 * No database, model, clock or private-memory access belongs in this module.
 */
/** Present bite attempt; merely showing teeth, a past bite or a wish is not one. */
function ai_narrative_bite_pattern():string {
    return '(?:affonda|affondo|affondare|affondando)\s+(?:(?:le|i|delle|dei|sue|suoi|proprie|propri|mie|miei|acuminate|acuminati|lunghe|lunghi)\s+){0,4}(?:zanne|denti)';
}
function ai_narrative_action_cue(string $text): bool {
    $text=preg_replace('/\bs[’\x{27}](?=\p{L})/iu','si ',$text)??$text;
    if(preg_match('/\b'.ai_narrative_bite_pattern().'\b/iu',$text))return true;
    return (bool)preg_match('/\b(?:si\s+(?:avvicina|allontana|scosta|volta|gira|ferma|alza|siede|china|inchina|scaglia|scaraventa|avventa|difende|libera|accascia|gratta|sporge|appoggia|accosta|inginocchia|ritrae|affaccia|scrolla|protende|liscia|tocca|sistema)|mi\s+(?:avvicino|allontano|volto|fermo|alzo|siedo|chino|difendo|libero|arrendo)|(?:tenta|cerca)\s+di|sorride|sorrido|annuisce|annuisco|scuote|scuoto|inarca|inarco|aggrotta|aggrotto|schiude|schiudo|arriccia|arriccio|inclina|inclino|incrocia|incrocio|accenna|accenno|ride|rido|sospira|sospiro|sbuffa|sbuffo|tace|taccio|arrossisce|arrossisco|deglutisce|deglutisco|indica|indico|accarezza|accarezzo|inspira|inspiro|espira|espiro|osserva|osservo|guarda|guardo|abbassa|abbasso|solleva|sollevo|stringe|stringo|afferra|afferro|afferrando|allunga|allungo|lascia|lascio|arretra|arretro|balza|balzo|avanza|avanzo|cammina|cammino|entra|entro|esce|esco|sferra|sferro|colpisce|colpisco|attacca|attacco|aggredisce|aggredisco|morde|mordo|azzanna|azzanno|pugnala|spara|scocca|sfodera|estrae|ruba|rubo|arresta|arresto|fugge|fuggo|scappa|scappo|dice|dico|chiede|chiedo|domanda|risponde|rispondo|mormora|sussurra|grida|urla|porge|porgo|attende|attendo|ricarica|ricarico|usa|uso|utilizza|utilizzo|prende|prendo|posa|poso|beve|bevo|sviene|crolla|sente|avverte|respira|rivolge|proferisce|proferire)\b/iu',$text);
}

/** A third-person capability claim may continue narration, but never proves it true. */
function ai_narrative_descriptive_claim(string $text):bool {
    return (bool)preg_match('/^(?:(?:la\s+(?:sua|propria)\s+(?:natura|stirpe|razza))|(?:il\s+(?:suo|proprio)\s+(?:corpo|sangue|lignaggio)))\b[^.!?]{0,90}\b(?:lo|la)\s+rende\s+(?:immune|resistente|invulnerabile|insensibile)\b/iu',trim($text));
}

/** A GDR phrase beginning "a voce alta"/"i piedi" is not an HTML a/i element. */
function ai_narrative_html_tag(string $token):?array {
    if(!preg_match('~^<(/?)([a-z][\w:-]*)(\s[^>]*|/?)>$~iu',$token,$m))return null;
    $tag=mb_strtolower($m[2]);
    if(!in_array($tag,['a','b','i','em','strong','span','div','p','br','img','font','small','u','s','section','blockquote','speech','dialogue','parlato','azione','action','act','talk'],true))return null;
    $tail=trim($m[3]);
    if($tail!==''&&$tail!=='/'&&!preg_match('/(?:^|\s)[\w:-]+\s*=/u',$tail)&&!preg_match('/^(?:hidden|disabled|checked|selected|open|reversed|multiple)(?:\s|\/?$)/iu',$tail))return null;
    return ['tag'=>$tag,'closing'=>$m[1]==='/','attributes'=>$tail];
}

/** Plain transcript preserving historical <spoken words> notation, never HTML. */
function ai_narrative_plain(string $raw):string {
    $raw=html_entity_decode(mb_substr($raw,0,12000),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $raw=preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~isu','',$raw)??$raw;
    $raw=preg_replace_callback('~<[^<>\n]{1,500}>~u',static function(array $m):string {
        if(ai_narrative_html_tag($m[0])!==null)return ' ';
        // A tag with attributes is actual markup; simple GDR brackets are prose.
        if(preg_match('/\b[\w:-]+\s*=|^<\//u',$m[0]))return '';
        return $m[0];
    },$raw)??$raw;
    return trim(preg_replace('/\s+/u',' ',$raw)??$raw);
}

/** Subjunctive wishes, negations, recollections and reported outcomes are not new acts. */
function ai_narrative_clause_intent(string $text): ?string {
    $v=mb_strtolower(trim($text));$v=preg_replace('/\bs[’\x{27}](?=\p{L})/u','si ',$v)??$v;
    if($v===''||preg_match('/^(?:\/\/|[$#%@|])/u',$v))return null;
    if(preg_match('/\b(?:non\s+(?:voglio|intendo)\s+(?:più\s+)?(?:combattere|continuare|allenarmi)|mi fermo|si ferma|smette|smetto|mi arrendo|si arrende|fermiamoci|terminiamo|interromp(?:e|o)\s+(?:l[’\x{27}]|il\s+)(?:attacco|assalto|combattimento)|cess(?:a|o)\s+(?:l[’\x{27}]|il\s+)(?:attacco|assalto|combattimento)|finisce l.allenamento|abbassa (?:le armi|la guardia))\b|^basta(?:[,.!]|\s*$)/u',$v))return 'fine';
    if(preg_match('/\b(?:allenamento|alleniamoci|esercitazione)\b/u',$v)&&preg_match('/\b(?:voglio|chiedo|facciamo|iniziamo|alleniamoci|invita|propone)\b/u',$v))return 'allenamento';
    if(preg_match('/\b(?:ordina|chiede|invita|propone|implora|minaccia|promette|dice|risponde|proferisce)\b.{0,90}\b(?:di\s+)?(?:attaccare|colpire|mordere|uccidere|arrestare|rubare)\b/u',$v))return 'azione';
    if(preg_match('/\b(?:vede|guarda|osserva|sente|ascolta|nota)\b.{0,90}\b(?:che|mentre|come)\b.{0,60}\b(?:attacca|colpisce|morde|azzanna|spara|pugnala|'.ai_narrative_bite_pattern().')\b/u',$v))return 'azione';
    // Keep uncertainty local to this clause: a later "ma colpisce" is assessed independently.
    $hypothesis=preg_replace('/\banche\s+se\b/u','sebbene',$v)??$v;
    if(preg_match('/\b(?:se|qualora|vorrei|vorrebbe|potrei|potrebbe|avrei|avrebbe|farei|farebbe|attaccherei|colpirei|morderei|ucciderei|sarebbe|racconta|ricorda|immagina|pensa|sogna|riferisce|riporta|diceva|ha detto)\b/u',$hypothesis))return null;
    // Negation applies to its verbs, not an earlier independent manoeuvre:
    // "afferra il busto, senza colpirlo né ferirlo" still declares a grapple.
    $verb='(?:attacc\p{L}*|aggred\p{L}*|colpi\p{L}*|mord\p{L}*|azzann\p{L}*|affond\p{L}*|romp\p{L}*|spezz\p{L}*|strangol\p{L}*|sgozz\p{L}*|feri\p{L}*|uccid\p{L}*|ruba\p{L}*|arrest\p{L}*|fugg\p{L}*|scapp\p{L}*|sferra\p{L}*|afferra\p{L}*|us\p{L}*|invoc\p{L}*)';
    $negative='/\b(?:non|senza|evita\s+di|rinuncia\s+a)\s+(?:(?:vuole|voglio|desidera|desidero|intende|intendo|tenta|cerca|di|si|lo|la|li|le|gli|affatto|mai|ancora|più)\s+){0,5}'.$verb.'(?:\s+(?:né|e|o)\s+'.$verb.')*\b/u';
    $v=trim(preg_replace($negative,' ',$v)??$v);
    if(preg_match('/\b'.ai_narrative_bite_pattern().'\b/u',$v))return 'assalto';
    // A noun such as "l'attacco" is not the first-person verb "attacco".
    $v=preg_replace('/\b(?:l[’\x{27}]|(?:un|il|questo|quell[oa])\s+)(?:attacco|morso)\b/u','la manovra',$v)??$v;
    if(preg_match('/\b(?:lascia|lascio|rilascia|rilascio|scioglie|sciolgo|allenta|allento|molla|mollo)\b.{0,65}\b(?:presa|braccio|braccia|polso|collo|mano|mani|catturato|prigioniero)\b/u',$v))return 'rilascio';
    if(preg_match('/\b(?:ricarica|ricarico)\b/u',$v))return 'ricarica';
    if(preg_match('/\b(?:si difende|mi difendo|tenta di difendersi|cerca di difendersi|para|paro|schiva|schivo|alza (?:lo scudo|la guardia))\b/u',$v))return 'difesa';
    if(preg_match('/\b(?:fugge|fuggo|scappa|scappo|fuggire|allontanarsi|si allontana|mi allontano|ritirarsi|si ritira|mi ritiro|divincola\p{L}*|svincola\p{L}*|liberarsi dalla presa)\b/u',$v))return 'fuga';
    if(preg_match('/\b(?:mantiene|mantengo|continua a mantenere|continua a trattenere)\b.{0,65}\b(?:presa|trattenut\p{L}*|busto|pols\p{L}*)\b/u',$v))return preg_match('/\bsping\p{L}*\b/u',$v)?'assalto':'azione';
    if(preg_match('/\b(?:spinge|spingo|spingere|spingerlo|spingerla|spingerli|spingerle)\b/u',$v))return 'assalto';
    if(preg_match('/\b(?:usa|uso|utilizza|utilizzo|invoca|invoco|tenta).{0,55}(?:ipnosi|dominazione|persuasione|potere|controllo emotivo|sguardo)\b/u',$v))return 'potere';
    // Present-tense performance/attempt, not the noun "morso" or past participles.
    foreach(['assalto'=>'/\b(?:attacca|attacco|attaccare|attaccarlo|attaccarla|assale|assalgo|aggredisce|aggredisco|aggredire|colpisce|colpisco|colpire|colpirlo|colpirla|trafigge|ferisce|ferire|rompe|spezza|strangola|sgozza|pugnala|spara|scocca|uccidere|morde|mordo|mordere|morderlo|morderla|mordendolo|mordendola|azzanna|azzanno|azzannare|azzannarlo|azzannarla|immobilizza|immobilizzo|immobilizzare|afferra|afferro|afferrando|afferrare|afferrargli|afferrarle|si scaglia|si scaraventa|si avventa|sferra|sferro|(?:colpir|morder|afferrar|azzannar|immobilizzar|attaccar|romper|spezzar|strangolar|sgozzar|ferir|uccider)(?:lo|la|li|le))\b/u','furto'=>'/\b(?:ruba|rubo|rapina|borseggia|rubare)\b/u','arresto'=>'/\b(?:arresta|arresto|sequestra|incatena|lega|arrestare)\b/u'] as $intent=>$pattern) {
        if(preg_match($pattern,$v)&&!preg_match('/\b(?:vuole|voglio|desidera|desidero|minaccia\s+di|promette\s+di|chiede\s+di|ordina\s+di)\b/u',$v))return $intent;
    }
    return ai_narrative_action_cue($v)?'azione':null;
}

/** Classify free prose, also splitting unquoted speech before a narrated gesture. */
function ai_narrative_free_segments(string $text,string $prior='auto',string $source='prose'): array {
    $text=trim($text);if($text==='')return [];
    if(preg_match('/^(?:\/\/|[$#%@|])/u',$text))return [['kind'=>'ooc','text'=>$text,'source'=>$source,'confidence'=>1.0]];
    $parts=preg_split('/(?<=[.!?;])\s+|\s+(?=(?:detto\s+(?:ciò\s+)?|poi\s+)(?:si\s+(?:scaglia|scaraventa|avventa|avvicina|allontana|volta)|tenta\s+di|cerca\s+di))|,\s+(?=(?:sorride|annuisce|abbassa|solleva|si\s+(?:volta|limita))\b)|\s+(?=ma\s+(?:tenta|cerca|si|attacca|attacco|colpisce|colpisco)\b)/iu',$text,-1,PREG_SPLIT_NO_EMPTY);
    $out=[];$previousKind='';
    foreach($parts?:[$text] as $part) {
        $part=trim($part);if($part==='')continue;
        $thought=(bool)preg_match('/\b(?:pensa(?:\s+tra\s+sé)?|pensando|immagina|ricorda|sogna|tra\s+sé)\b/iu',$part);
        $cue=ai_narrative_action_cue($part)||(bool)preg_match('/\bsi limita\b/iu',$part);
        // In a narrated sequence, "La sua natura lo rende immune" is the
        // player's descriptive claim, not a new utterance. Keep this narrow:
        // first-person dialogue and isolated unmarked statements stay speech;
        // explicit quotes/talk tags are already protected by the caller.
        if(!$cue&&$previousKind==='action'&&ai_narrative_descriptive_claim($part))$cue=true;
        if($prior!=='action'&&preg_match('/^(?:perché|come|che|chi|dove|quando|quanto|cosa|potete|potreste|puoi|vorresti|volete)\b.+\?\s*$/iu',$part))$cue=false;
        $kind=$thought?'thought':(($prior==='action'||$cue)?'action':'speech');
        $out[]=['kind'=>$kind,'text'=>$part,'source'=>$source,'confidence'=>$thought?.88:($cue?.88:($prior==='action'?.86:.75))];
        $previousKind=$kind;
    }
    return $out;
}

/**
 * Quotes, emotes and historical GDR angle brackets may be nested in either channel.
 * Only real HTML is removed: <grazie> is dialogue and must survive strip_tags().
 * options.channel can carry the sending channel but never overrides inner evidence.
 */
function ai_narrative_parse(string $raw,array $options=[]): array {
    $raw=mb_substr($raw,0,12000);
    $text=html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~isu','',$text)??$text;
    $leading=trim($text);$prior=str_starts_with($leading,'+')?'action':(in_array($options['channel']??'',['action','speech'],true)?$options['channel']:'auto');
    if(str_starts_with($leading,'+'))$text=preg_replace('/^\s*\+\s*/u','',$text)??$text;
    if(($options['channel']??'')==='ooc'||preg_match('/^(?:\/\/|[$#%@|])/u',trim($text)))return ['segments'=>[['kind'=>'ooc','text'=>trim($text),'source'=>'prefix','confidence'=>1.0]],'speech'=>'','action'=>'','thought'=>'','intent'=>null,'acts'=>[],'requires_action_clarification'=>false];
    $tokens=preg_split('/(<+[^<>\n]{1,500}>+|>+[^<>\n]{1,1800}<+|«|»|“|”|"|(?<![\p{L}\p{N}])[\x{27}‘]|[\x{27}’](?![\p{L}\p{N}])|\*{1,2}|\(|\)|\[|\])/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)?:[];
    $stack=[];$segments=[];$buffer='';
    $flush=static function()use(&$buffer,&$segments,&$stack,$prior):void {
        if(trim($buffer)===''){$buffer='';return;}
        $frame=end($stack);$kind=$frame['kind']??'auto';$source=$frame['source']??'prose';
        if(in_array($kind,['speech','thought','ooc'],true))$segments[]=['kind'=>$kind,'text'=>trim($buffer),'source'=>$source,'confidence'=>.98];
        else foreach(ai_narrative_free_segments($buffer,$kind==='action'?'action':$prior,$source) as $s)$segments[]=$s;
        $buffer='';
    };
    foreach($tokens as $tokenIndex=>$token) {
        if(str_starts_with($token,'>')&&str_ends_with($token,'<')){
            $protected=$stack[count($stack)-1]['kind']??'';$flush();$body=preg_replace('/^>+|<+$/u','',$token)??$token;
            if(in_array($protected,['thought','ooc'],true))$segments[]=['kind'=>$protected,'text'=>$body,'source'=>'gdr-angle-inverse','confidence'=>.98];
            else foreach(ai_narrative_parse($body)['segments'] as $s){$s['source']='gdr-angle-inverse';$segments[]=$s;}continue;
        }
        if(str_starts_with($token,'<')&&str_ends_with($token,'>')) {
            if(($html=ai_narrative_html_tag($token))!==null) {
                $tag=$html['tag'];$flush();
                if($html['closing']){if(($stack[count($stack)-1]['close']??'')==='</'.$tag.'>')array_pop($stack);continue;}
                if(in_array($tag,['br','p','div'],true))$buffer.=' ';
                $class=preg_match('/\b(?:class|data-kind)\s*=\s*["\x{27}]([^"\x{27}]*)/iu',$token,$cm)?mb_strtolower($cm[1]):'';
                $kind=in_array($tag,['speech','dialogue','parlato','talk'],true)||preg_match('/(?:chat-speech|\bparlato\b|\bstring\b)/u',$class)?'speech':(in_array($tag,['azione','action','act'],true)||preg_match('/(?:chat-action|\bazioni?\b)/u',$class)?'action':'');
                if(!in_array($tag,['br','img'],true)&&!str_ends_with($token,'/>'))$stack[]=['close'=>'</'.$tag.'>','kind'=>$kind!==''?$kind:($stack[count($stack)-1]['kind']??'auto'),'source'=>'html'];
                continue;
            }
            // <osserva l'uomo> and <grazie> are both used in real logs.
            $protected=$stack[count($stack)-1]['kind']??'';$flush();
            $body=preg_replace('/^<+|>+$/u','',$token)??$token;
            if(in_array($protected,['thought','ooc'],true))$segments[]=['kind'=>$protected,'text'=>$body,'source'=>'gdr-angle','confidence'=>.98];
            else foreach(ai_narrative_parse($body)['segments'] as $s){$s['source']='gdr-angle';$segments[]=$s;}continue;
        }
        $top=end($stack);
        if($top&&$token===$top['close']){$flush();array_pop($stack);continue;}
        // "sull' entrata" is an elision, not an opening quote hiding the next ].
        if(in_array($token,["'",'’'],true)&&preg_match('/[\p{L}\p{N}]$/u',$buffer)){$buffer.=$token;continue;}
        $pairs=['«'=>'»','“'=>'”','"'=>'"',"'"=>"'",'‘'=>'’','*'=>'*','**'=>'**','('=>')','['=>']'];
        if(isset($pairs[$token])) {
            $before=$buffer;$flush();
            $kind=in_array($token,['*','**','(','['],true)?'action':'speech';
            if(in_array($top['kind']??'',['thought','ooc'],true))$kind=$top['kind'];
            if($kind==='speech'&&preg_match('/\b(?:pensa|pensando|immagina|ricorda|sogna|tra\s+sé)\b[^.!?]*$/iu',$before))$kind='thought';
            if(in_array($token,['(','['],true)&&preg_match('/^\s*(?:off|ooc|fuori\s+gioco)\b/iu',$tokens[$tokenIndex+1]??''))$kind='ooc';
            $stack[]=['close'=>$pairs[$token],'kind'=>$kind,'source'=>in_array($token,['*','**'],true)?'emote':(in_array($token,['(','['],true)?'aside':'quote')];continue;
        }
        if(in_array($token,['»','”',')',']','’'],true)){$flush();continue;}
        $buffer.=$token;
    }
    $flush();$speech=[];$action=[];$thought=[];$acts=[];
    foreach($segments as $s) {
        if($s['kind']==='speech')$speech[]=$s['text'];elseif($s['kind']==='action')$action[]=$s['text'];elseif($s['kind']==='thought')$thought[]=$s['text'];
        if($s['kind']==='action')foreach(preg_split('/\s+(?:ma|poi|quindi|per\s+poi)\s+|[;.!?]+/iu',$s['text'],-1,PREG_SPLIT_NO_EMPTY)?:[] as $clause){$intent=ai_narrative_clause_intent($clause);if($intent===null&&preg_match('/\bsi limita\b/iu',$clause))$intent='azione';if($intent!==null)$acts[]=['intent'=>$intent,'text'=>trim($clause),'confidence'=>$s['confidence']];}
    }
    $intent=null;
    foreach($acts as $act)if($act['intent']!=='azione'){$intent=$act['intent'];break;}
    if($intent===null&&$action&&$acts)$intent='azione';
    // Requests to train/stop are meaningful spoken choices, never spoken attacks.
    if($intent===null)foreach($speech as $s){$v=ai_narrative_clause_intent($s);if(in_array($v,['allenamento','fine'],true)){$intent=$v;break;}}
    return ['segments'=>array_slice($segments,0,120),'speech'=>implode(' ',$speech),'action'=>implode(' ',$action),'thought'=>implode(' ',$thought),'intent'=>$intent,'acts'=>$acts,'requires_action_clarification'=>false];
}

function ai_narrative_actor_key(array $actor):string{return ($actor['kind']??'npc').':'.(int)($actor['id']??0);}
function ai_narrative_mentions(string $text,string $name):bool{return $name!==''&&(bool)preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name,'/').'(?![\p{L}\p{N}])/iu',$text);}

/** A named but unavailable object cannot silently become the selected bystander. */
function ai_narrative_unresolved_object(string $focus,bool $hasRole):bool {
    $generic=['il','lo','la','gli','lui','lei','loro','voi','tu','te','ti','vi','suo','sua','suoi','sue','un','uno','una','con','contro','verso','sul','sulla','sullo','alla','alle','allo','collo','testa','spalla','spalle','braccio','braccia','polso','polsi','mano','mani','petto','fianco','fianchi','torace','viso','gola','gambe','gamba','denti','artigli','pugno','pugni','calcio','calci','gomito','ginocchio','corpo','presa','scudo','spada','avversario','avversaria','bersaglio','interlocutore','interlocutrice','usa','uso','utilizza','utilizzo','invoca','invoco','tenta','cerca','arco','balestra','dardo','freccia','lancia','pugnale','fucile','pistola','moschetto','carabina','sciabola','guardia','guardie','sergente','capitano','uomo','donna','vampiro','vampira','licantropo','licantropa','lupo','lupa','qualcuno','chiunque','ancora','forte','piano','davanti','dietro','addosso','avanti'];
    preg_match_all('/(?<![\p{L}\p{N}])\p{Lu}[\p{L}\p{M}]{2,}/u',$focus,$proper,PREG_OFFSET_CAPTURE);
    foreach($proper[0] as [$word,$offset]){
        if(in_array(mb_strtolower($word),$generic,true))continue;
        // A known office may be qualified by a place (titolo e territorio).
        if($hasRole&&preg_match('/\b(?:di|del|dello|della|da|dal|dalla)\s*$/iu',substr($focus,0,$offset)))continue;
        return true;
    }
    if($hasRole)return false;
    // Lowercase character names are common. Check only a simple direct object,
    // leaving methods/body locations, pronouns and ordinary modifiers to context.
    $object=preg_replace('/^\s*(?:(?:contro|verso|su|a|ad|al|allo|alla|il|lo|la|un|uno|una)\s+|[lL][’\x{27}])*/u','',trim($focus))??trim($focus);
    if(preg_match('/^([\p{L}\p{M}]{3,})(?:\s+(?:con|al|alla|sul|sulla|per)\b.*)?[.!?]*$/u',$object,$m)){
        $word=mb_strtolower($m[1]);
        if(!in_array($word,$generic,true)&&!preg_match('/(?:mente|ando|endo)$/u',$word))return true;
    }
    return false;
}

/** Pure resolver. Every candidate and every remembered reference must still be present. */
function ai_narrative_resolve(array $analysis,array $present,array $context=[]):array {
    $none=static fn(string $reason='nessun_riferimento')=>['status'=>'none','target'=>null,'confidence'=>0.0,'reason'=>$reason,'candidates'=>[]];
    $actors=[];foreach($present as $a)if((int)($a['id']??0)>0&&($a['active']??true)&&($a['present']??true)&&ai_narrative_actor_key($a)!==($context['actor']??''))$actors[ai_narrative_actor_key($a)]=$a;
    if(!$actors)return $none('nessun_personaggio_presente');
    $resolved=static fn(array $a,float $confidence,string $reason)=>['status'=>'resolved','target'=>$a,'confidence'=>$confidence,'reason'=>$reason,'candidates'=>[ai_narrative_actor_key($a)]];
    $ambiguous=static fn(array $matches,string $reason)=>['status'=>'ambiguous','target'=>null,'confidence'=>0.0,'reason'=>$reason,'candidates'=>array_keys($matches)];
    $purpose=$context['purpose']??'address';$training=($analysis['intent']??'')==='allenamento';
    $text=$purpose==='action'?($analysis['action']??''):trim(($analysis['action']??'').' '.($analysis['speech']??''));
    if($purpose==='action'&&$training)$text=trim($text.' '.($analysis['speech']??''));
    if(trim($text)==='')return $none('solo_pensiero_o_fuori_gioco');
    $focus=$text;
    if($purpose==='address'&&preg_match('/\b(?:dice|dico|chiede|chiedo|domanda|risponde|rispondo|mormora|sussurra|grida|urla|si rivolge|parla)\s+(?:a|ad|con)\s+([^,;.!?]+)/iu',$analysis['action']??'',$direct))$focus=$direct[1];
    if($purpose==='action') {
        $acts=array_values(array_filter($analysis['acts']??[],static fn($a)=>in_array($a['intent'],['assalto','arresto','furto','potere','rilascio'],true)));
        if(!$acts)$acts=array_values(array_filter($analysis['acts']??[],static fn($a)=>$a['intent']==='azione'));
        if(!$acts&&$training)$acts=[['intent'=>'allenamento','text'=>$text]];
        if(!$acts)return $none('nessun_atto_diretto');
        $focus=$acts[0]['text'];
        if(($acts[0]['intent']??'')==='potere')$focus=preg_split('/\b(?:di|per)\s+(?:attacc|uccid|feri)/iu',$focus)[0];
        elseif(preg_match('/\b(?:si scaglia|si scaraventa|si avventa|attacc\p{L}*|aggred\p{L}*|colpi\p{L}*|mord\p{L}*|azzann\p{L}*|afferra\p{L}*|sferra\p{L}*|spara|scocca|'.ai_narrative_bite_pattern().')\b/iu',$focus,$verb,PREG_OFFSET_CAPTURE)){
            $prefix=substr($focus,0,$verb[0][1]);$focus=substr($focus,$verb[0][1]+strlen($verb[0][0]));
            // "si avvicina a Petru e tenta di afferrargli il busto": the
            // clitic retains the named approach target. A new direct object wins.
            if(preg_match('/(?:gli|le|lo|la|li)$/iu',$verb[0][0])&&preg_match('/\bsi\s+(?:avvicina|accosta)\s+(?:a|ad)\s+(.+?)\s+(?:e|per|quindi)\b/iu',$prefix,$antecedent)
                &&!preg_match('/(?<![\p{L}\p{N}])\p{Lu}[\p{L}\p{M}]{2,}/u',$focus))$focus.=' '.$antecedent[1];
        }
    }
    // Full names disambiguate identical first names. A mention in quoted dialogue cannot redirect an attack.
    $matches=[];foreach($actors as $key=>$a)if(ai_narrative_mentions($focus,(string)($a['name']??'')))$matches[$key]=$a;
    if(count($matches)>1){$longest=max(array_map(static fn($a)=>mb_strlen((string)$a['name']),$matches));$matches=array_filter($matches,static fn($a)=>mb_strlen((string)$a['name'])===$longest);}
    if(!$matches)foreach($actors as $key=>$a){$first=preg_split('/\s+/u',trim((string)($a['name']??'')))[0]??'';if(mb_strlen($first)>=3&&ai_narrative_mentions($focus,$first))$matches[$key]=$a;}
    if(count($matches)===1)return $resolved(reset($matches),.99,'nome_nel_gesto_o_dialogo');
    if(count($matches)>1)return $ambiguous($matches,'più_nomi_presenti');
    $roles=[];
    foreach($actors as $key=>$a) {
        $aliases=array_merge((array)($a['aliases']??[]),(array)($a['titles']??[]));
        foreach(['guardia'=>'guardie','sergente'=>'sergenti','sacerdote'=>'sacerdoti','parroco'=>'parroci','suora'=>'suore'] as $singular=>$plural)if(in_array($singular,$aliases,true))$aliases[]=$plural;
        $race=(int)($a['race_id']??$a['razza']??0);
        if(in_array($race,[2,5,7],true))$aliases=array_merge($aliases,['vampiro','vampira','vampiri']);
        if(in_array($race,[3,6],true))$aliases=array_merge($aliases,['licantropo','licantropa','lupo','lupa']);
        if($race===1)$aliases=array_merge($aliases,['umano','umana']);
        $gender=mb_strtolower((string)($a['gender']??$a['sex']??''));if($gender==='maschio')$aliases[]='uomo';elseif($gender==='femmina')$aliases[]='donna';
        foreach($aliases as $alias)if(is_string($alias)&&mb_strlen($alias)>=3&&ai_narrative_mentions($focus,$alias)){$roles[$key]=$a;break;}
    }
    if($purpose==='action'&&in_array($analysis['intent']??'',['assalto','furto','arresto','potere'],true)&&ai_narrative_unresolved_object($focus,(bool)$roles))return $none('oggetto_esplicito_non_presente');
    if(count($roles)===1)return $resolved(reset($roles),.93,'titolo_o_descrizione_univoca');
    // "La guardia" can still identify the guard selected or already engaged;
    // the dropdown/history may narrow a shared title, never contradict a name.
    $sharedRole=count($roles)>1;if($sharedRole)$actors=$roles;
    $selected=$context['selected']??'';if(is_array($selected))$selected=ai_narrative_actor_key($selected);if(is_int($selected))$selected='npc:'.$selected;
    if(isset($actors[$selected]))return $resolved($actors[$selected],.98,'selezione_presente');
    $pronoun=(bool)preg_match('/\b(?:lo|la|gli|le|lui|lei|tu|voi|te|vi|ti|avversario|interlocutore)\b|(?:morder|azzannar|colpir|afferrar)(?:lo|la)\b/iu',$text);
    if($selected!==''&&$selected!=='npc:0'&&($purpose==='action'||$pronoun))return $none('destinatario_selezionato_assente');
    $references=$purpose==='action'?['last_target','last_interlocutor']:['last_interlocutor','last_target'];
    foreach($references as $reference){$key=$context[$reference]??'';if(is_array($key))$key=ai_narrative_actor_key($key);if($key&&($pronoun||$purpose==='address'||$reference==='last_target')){if(!isset($actors[$key]))return $none('riferimento_precedente_assente');return $resolved($actors[$key],$reference==='last_target'?.9:.84,$reference==='last_target'?'continuità_bersaglio':'continuità_interlocutore');}}
    $opponents=[];foreach($context['opponents']??[] as $key){if(is_array($key))$key=ai_narrative_actor_key($key);if(isset($actors[$key]))$opponents[$key]=$actors[$key];}
    if($purpose==='action'&&count($opponents)===1)return $resolved(reset($opponents),.9,'unico_avversario_attivo');
    if(count($actors)===1)return $resolved(reset($actors),$purpose==='action'?.84:.75,'unico_presente');
    return $purpose==='action'||$sharedRole?$ambiguous($actors,$sharedRole?'descrizione_condivisa':'bersaglio_non_distinguibile'):$none();
}
