<?php
declare(strict_types=1);

/** Retrieval vocabulary only: it never adds facts or rewrites the visitor's turn. */
function ai_background_query(string $question):string {
    $groups=[
        '/\b(?:famigli\p{L}*|parent[ei]|genitor[ei]|padr[ei]|madr[ei]|fratell\p{L}*|sorell\p{L}*|figli\p{L}*|infanzia|cresciut\p{L}*)\b/iu'=>'famiglia genitori padre madre fratello sorella figli infanzia cresciuto',
        '/\b(?:origin\p{L}*|proven\p{L}*|provien\p{L}*|nat[oaie]|nascit\p{L}*|nascest\p{L}*|paese|villaggio)\b|\b(?:da|di)\s+dove\s+(?:vieni|venite|sei|siete)\b/iu'=>'origine provenienza nato nascita paese villaggio cresciuto',
        '/\b(?:mestier\p{L}*|lavor\p{L}*|occupazion\p{L}*|profess\p{L}*|servizi\p{L}*|incaric\p{L}*)\b/iu'=>'mestiere lavoro occupazione professione servizio incarico',
        '/\b(?:amic\p{L}*|affett\p{L}*|am[oi]|amore|innamorat\p{L}*|sposat\p{L}*|mogli\p{L}*|marit\p{L}*|compagn[oaie])\b/iu'=>'amicizia amico amica affetti amore compagno compagna moglie marito',
        '/\b(?:animal\p{L}*|can[ei]|gatt\p{L}*|cavall\p{L}*|compagno\s+animal\p{L}*)\b/iu'=>'animali cane gatto cavallo',
        '/\b(?:età|anni|anzian\p{L}*|giovan\p{L}*)\b/iu'=>'età anni',
        '/\b(?:etni\p{L}*|popolo|cultur\p{L}*)\b/iu'=>'etnia popolo cultura',
    ];
    $query=$question;foreach($groups as $pattern=>$terms)if(preg_match($pattern,$question))$query.=' '.$terms;
    $entry=preg_match('/\b(?:entrar\p{L}*|entr[oaie]|ingress\p{L}*|access\p{L}*|varc\p{L}*|cancell\p{L}*|porton\p{L}*)\b/iu',$question)
        ||(preg_match('/\b(?:passar\p{L}*|pass[oaie])\b/iu',$question)&&!preg_match('/\b(?:tempo|giornat\p{L}*|serat\p{L}*|pomeriggio|insieme|moment\p{L}*)\b/iu',$question));
    if($entry)$query.=' ingresso accesso passaggio varco cancello portone aperto chiuso';
    return $query;
}

/** Public psychology is also an editable temperament, not a decorative profile field. */
function ai_background_persona(array $npc,array $rules,int $budget=320):string {
    $p=ai_npc_profile($npc);$psychology=trim((string)$p['psychology']);
    if($psychology!=='')$npc['personality']=$psychology."\n".(string)($npc['personality']??'');
    return ai_expression_persona($npc,$rules,$budget);
}

/** Own biographical data stays bounded and topical; live pair/scene state has priority. */
function ai_background_context(array $npc,array $job,float $scale=.5):string {
    $reading=ai_narrative_parse((string)($job['prompt']??''));
    $question=$reading['speech']!==''?$reading['speech']:ai_personality_observable((string)($job['prompt']??''));
    $query=ai_background_query($question);$profile=ai_npc_profile($npc);$small=$scale<=.3;$budget=$small?300:440;
    $personal=(bool)preg_match('/\b(?:ricord[oi]|infanzia|famigli\p{L}*|parent[ei]|genitor[ei]|padr[ei]|madr[ei]|fratell\p{L}*|sorell\p{L}*|origin\p{L}*|proven\p{L}*|provien\p{L}*|nat[oaie]|nascit\p{L}*|felic[ei]|vostra\s+storia|tua\s+storia|parlatemi\s+di\s+voi|parlami\s+di\s+te|vi\s+piace|ti\s+piace)\b|\b(?:da|di)\s+dove\s+(?:vieni|venite|sei|siete)\b/iu',$question);
    $fields=[
        'storia pubblica'=>(string)$profile['biography'],
        'storia personale'=>(string)($npc['history']??''),
        'relazioni di sfondo'=>(string)$profile['relationships'],
        'affetti di sfondo'=>(string)$profile['affections'],
        'animali'=>(string)$profile['animals'],
        'condizione sociale'=>(string)$profile['social_status'],
        'note pubbliche'=>(string)$profile['notes'],
        'età dichiarata'=>(int)$profile['age']>0?(int)$profile['age'].' anni':'',
        'etnia'=>(int)$profile['ethnicity_id']>0?(string)GetEtnia((int)$profile['ethnicity_id']):'',
        'aspetto'=>(string)$profile['appearance'],
    ];
    $units=[];
    foreach($fields as $label=>$text){
        if(trim($text)==='')continue;
        $relevant=ai_expression_related($label.' '.$text,$query);
        if(!$relevant&&!($personal&&in_array($label,['storia pubblica','storia personale'],true)))continue;
        $units[]=['label'=>$label,'text'=>$text,'priority'=>$relevant?1:0];
    }
    foreach($profile['details'] as $detail){
        $label=ai_text((string)($detail['label']??''),80);$text=(string)($detail['value']??'');
        if($text!==''&&ai_expression_related($label.' '.$text,$query))$units[]=['label'=>$label,'text'=>$text,'priority'=>1];
    }
    // A matching family/animal fact must not lose its budget to an unrelated biography.
    usort($units,static fn($a,$b)=>$b['priority']<=>$a['priority']);
    $parts=[];$remaining=$budget;$seen=[];
    foreach($units as $unit){
        if($remaining<90)break;
        $size=min($small?190:260,$remaining-mb_strlen($unit['label'])-3);
        $excerpt=ai_prompt_relevant_text($unit['text'],$query,$size);
        $excerpt=ai_expression_excerpt($excerpt,$size);
        $sentences=[];
        foreach(preg_split('/(?<=[.!?;])\s+/u',$excerpt,-1,PREG_SPLIT_NO_EMPTY)?:[] as $sentence){
            $key=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($sentence))??'');
            if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$sentences[]=$sentence;
        }
        $excerpt=implode(' ',$sentences);
        if($excerpt==='')continue;
        $line=$unit['label'].': '.$excerpt;$parts[]=$line;$remaining-=mb_strlen($line)+3;
    }
    if(!$parts)return '';
    return "\nSFONDO PERSONALE PERTINENTE (dati del tuo profilo, non nuovi eventi): ".implode(' / ',$parts).'. Le relazioni attuali, le correzioni dello staff e gli esiti verificati prevalgono sullo sfondo; un affetto biografico non assegna sentimenti al PG presente. Non recitare la scheda e non inventare dettagli mancanti.';
}

/** Both existing duty controls affect ordinary dialogue; occupation alone is not enough. */
function ai_background_duties(array $npc,array $rules,array $game,float $scale=.5):string {
    $occupation=trim((string)ai_npc_profile($npc)['occupation']," .\t\r\n");$parts=[];
    foreach([(string)$rules['duty'],(string)$game['duties']] as $duty){
        $duty=trim($duty," .\t\r\n");if($duty===''||$duty===$occupation||in_array($duty,$parts,true))continue;$parts[]=$duty;
    }
    return $parts?"\nDOVERI DEL PNG: ".ai_expression_excerpt(implode('. ',$parts),$scale<=.3?140:220).'. Il dovere concreto non rende ogni argomento un divieto.':'';
}

/** Compact ordinary replies retain the same private session directions as larger prompts. */
function ai_background_master_context(array $room,array $job,float $scale=.5):string {
    if((int)($job['user_id']??0)<1)return '';$state=ai_game_session((string)$room['chat_key']);if(!$state)return '';
    $g=$state['guidance'];$turn=ai_game_turn($g);
    $out="\nREGIA ATTUALE: fase ".$g['phase'].', giro '.(int)$g['round_number'].'. Gli esiti confermati del Master prevalgono; non risolvere nuovi tentativi o far avanzare il turno.';
    if($turn)$out.=' Turno riservato a '.($turn['kind']==='npc'?'PNG':'PG').' #'.(int)$turn['id'].'.';
    if(trim((string)$g['notes'])!=='')$out.="\nDIRETTIVA DEL MASTER PER LA TUA PROSSIMA RISPOSTA (prioritaria sulla trama generica): ".ai_expression_excerpt((string)$g['notes'],$scale<=.3?140:220).' Applica la direttiva alla tua condotta; se una condizione modifica voce o movimento, mostrane l’effetto concreto. Non citare il Master e non recitare le istruzioni.';
    return $out;
}

/** Recall a visitor's own public account, never import the biography on their sheet.
 * A stored encounter must still point to an authentic turn heard by this NPC.
 * This small topical projection keeps old personal facts from losing their
 * entire budget to the NPC's more recent replies in ordinary history.
 */
function ai_background_player_context(array $npc,array $job,bool $compact=false):string {
    $nid=(int)($npc['id']??0);$uid=(int)($job['user_id']??0);
    if($nid<1||$uid<1)return '';
    $reading=ai_narrative_parse((string)($job['prompt']??''));$question=(string)$reading['speech'];
    if($question==='')return '';
    $personal='/\b(?:famigli\p{L}*|parent[ei]|genitor[ei]|padr[ei]|madr[ei]|fratell\p{L}*|sorell\p{L}*|figli\p{L}*|infanzia|origin\p{L}*|proven\p{L}*|nascit\p{L}*|mestier\p{L}*|lavor\p{L}*|profess\p{L}*|animali|cane|cani|gatto|gatti|cavall\p{L}*)\b/iu';
    $reminder=(bool)preg_match('/\b(?:ricord[\p{L}]*|raccontat[oa]|confidat[oa])\b/iu',$question);
    if(!$reminder&&!preg_match($personal,$question))return '';
    // "Avete fratelli?" asks about the NPC, not the visitor's old biography.
    if(!$reminder&&!preg_match('/\b(?:mio|mia|miei|mie|io|me)\b/iu',$question))return '';
    if(ai_recognition_anonymous($npc,$job))return '';
    $chat=(string)($job['chat_key']??'');$private=preg_match('/^(?:rent_|pvt_)/D',$chat)===1;
    $scope="(LEFT(m.chat_key,5)<>'rent_' AND LEFT(m.chat_key,4)<>'pvt_')";$params=[$nid,$uid];
    if($private){$scope='('.$scope.' OR m.chat_key=?)';$params[]=$chat;}
    $scope.=ai_relation_context_memory_scope($nid,$uid,'m');
    $source=(int)($job['source_id']??0);
    if($source>0){$scope.=' AND j.source_id<?';$params[]=$source;}
    $rows=ai_db()->query("SELECT m.id,c.msg FROM ai_personal_memories m JOIN ai_jobs j ON j.id=m.job_id AND j.npc_id=m.npc_id AND j.user_id=m.player_id JOIN chat c ON c.id=j.source_id AND c.pg=m.player_id AND BINARY c.chat=BINARY m.chat_key WHERE m.npc_id=? AND m.player_id=? AND m.active=1 AND m.source='dialogue' AND j.status='done' AND c.dest=0 AND $scope".(function_exists('alphano_chat_has_archive')&&alphano_chat_has_archive()?' AND c.history_only=0':'').' ORDER BY m.id DESC LIMIT 32',$params);
    $query=ai_background_query($question);$parts=[];$seen=[];$remaining=$compact?230:360;
    foreach($rows as $row){
        $speech=ai_narrative_parse((string)$row['msg'])['speech'];
        if($speech===''||!preg_match($personal,$speech)||!ai_expression_related($speech,$query))continue;
        $excerpt=ai_prompt_relevant_text($speech,$query,min(180,$remaining));
        $excerpt=ai_text($excerpt,$remaining);$key=ai_social_evidence_key($excerpt);
        if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$parts[]='«'.$excerpt.'»';$remaining-=mb_strlen($excerpt)+4;
        if(count($parts)>=2||$remaining<90)break;
    }
    return $parts?"\nRACCONTI PERSONALI GIÀ ASCOLTATI DA QUESTO INTERLOCUTORE: ".implode(' / ',$parts).'. Sono sue dichiarazioni passate, non fatti verificati né nuove battute: se pertinenti puoi ricordare che te le ha raccontate. Non inventare dettagli, leggere pensieri o dedurre segreti dalla scheda. Il contesto attuale definito dallo staff prevale.':'';
}
