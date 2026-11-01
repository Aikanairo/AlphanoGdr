<?php
declare(strict_types=1);

/** Only present, explicitly addressed characters join a dialogue. No model routing or AI-to-AI loop. */
function ai_dialogue_participants(array $npcs, array $selected, string $text): array {
    $participants=[$selected];
    // A Master's turn order takes priority over conversational addressing.
    if(ai_game_session($selected['chat_key']))return $participants;
    if(preg_match('/\bnon\s+(?:voglio|chiedo|desidero|serve)\b/iu',$text))return $participants;
    $joint=(bool)preg_match('/\b(?:tu\s+e|voi\s+(?:due|tre|2|3)|entrambi|entrambe|tutti\s+e\s+(?:due|tre|2|3)|insieme\s+a)\b/iu',$text);
    // A plural invitation without a name refers to colleagues of the selected PNG, not every bystander.
    $collective=(bool)preg_match('/\b(?:voi\s+(?:due|tre|2|3)|entrambi|entrambe|tutti\s+e\s+(?:due|tre|2|3)|in\s+(?:due|2))\b/iu',$text)
        &&(bool)preg_match('/\b(?:attacc(?:ate|hiate)|rispond(?:ete|iate)|ven(?:ite|iate)|aiut(?:ate|iate)|parl(?:ate|iate)|combattiamo|alleniamoci)\b/iu',$text);
    $faction=ai_npc_game((int)$selected['id'])['faction'];
    $roles=['sergent[ei]'=>'sergent','capitan[oi]'=>'capitan','guard(?:ia|ie)'=>'guardia','suor[ae]'=>'suor','parroc[oi]'=>'parroc','sacerdot[ei]'=>'sacerdot','ost[ei]'=>'oste','locandier[ei]'=>'locandier','custod[ei]'=>'custod'];
    foreach($npcs as $npc){
        if((int)$npc['id']===(int)$selected['id']||!(int)$npc['active']||(int)ai_npc_profile($npc)['hit_points']<=0)continue;
        $g=ai_npc_game((int)$npc['id']);$role=$g['rank'].' '.ai_npc_profile($npc)['occupation'];
        $named=ai_mentions_npc($text,$npc['name']);$titled=false;
        foreach($roles as $pattern=>$stem)if(mb_stripos($role,$stem)!==false&&preg_match('/\b'.$pattern.'\b/iu',$text)){
            // Ambiguous titles never summon several unrelated characters.
            $matches=array_filter($npcs,static fn($n)=>(int)$n['active']&&mb_stripos(ai_npc_game((int)$n['id'])['rank'].' '.ai_npc_profile($n)['occupation'],$stem)!==false);
            if(count($matches)===1)$titled=true;
        }
        $bothNames=$named&&ai_mentions_npc($text,$selected['name'])&&(bool)preg_match('/\be\b/iu',$text);
        if(($joint&&($named||$titled))||$bothNames||($collective&&$faction!==''&&$g['faction']===$faction))$participants[]=$npc;
        if(count($participants)>=AI_MAX_NPCS)break;
    }
    return $participants;
}

/** The source event identifies the group; all members retain ordinary play-job safety and memories. */
function ai_dialogue_jobs(array $job): array {
    if($job['kind']!=='play'||(int)$job['source_id']<1)return [];
    return ai_db()->query("SELECT j.id,j.npc_id,j.status,j.result,n.name FROM ai_jobs j JOIN ai_npcs n ON n.id=j.npc_id WHERE j.chat_key=? AND j.source_id=? AND j.user_id=? AND j.kind='play' ORDER BY j.id LIMIT ".AI_MAX_NPCS,[$job['chat_key'],(int)$job['source_id'],(int)$job['user_id']]);
}
/** Conversation focus is the authenticated pair; public Master facts enter through the witness ledger. */
function ai_dialogue_history(array $job,int $limit=8):array {
    $limit=max(1,min(12,$limit));$anchor=0;
    if(ai_recognition_anonymous(['id'=>(int)$job['npc_id']],$job)){$view=ai_recognition_view((int)$job['npc_id'],(int)$job['user_id'],$job);$anchor=max((int)$view['scene_start'],(int)$view['mask_source']);}
    $present=ai_relation_context_current((int)$job['npc_id'],(int)$job['user_id']);
    if($present)$anchor=max($anchor,$present['chat_id']+1);
    // NPC replies store their source on the job. Use that same source for both
    // sides of an exchange; a NULL event source must not put a same-second reply
    // before the player's words when the history is restored to chronological order.
    return ai_db()->query("SELECT e.speaker,e.content,e.npc_id,e.job_id,e.created_at FROM ai_events e LEFT JOIN ai_jobs j ON j.id=e.job_id LEFT JOIN chat c ON c.id=e.source_id WHERE e.chat_key=? AND ((e.npc_id=? AND j.user_id=? AND j.source_id<? AND BINARY j.chat_key=BINARY e.chat_key) OR (e.npc_id=0 AND c.pg=? AND c.dest=0 AND BINARY c.chat=BINARY e.chat_key AND EXISTS(SELECT 1 FROM ai_jobs addressed WHERE BINARY addressed.chat_key=BINARY e.chat_key AND addressed.source_id=e.source_id AND addressed.npc_id=? AND addressed.user_id=?))) AND (e.source_id<? OR e.source_id IS NULL) AND e.created_at>=? AND COALESCE(j.source_id,e.source_id,0)>=? ORDER BY e.created_at DESC,COALESCE(j.source_id,e.source_id,0) DESC,e.id DESC LIMIT ".$limit,[$job['chat_key'],(int)$job['npc_id'],(int)$job['user_id'],(int)$job['source_id'],(int)$job['user_id'],(int)$job['npc_id'],(int)$job['user_id'],(int)$job['source_id'],(int)($job['created_at']??time())-1800,$anchor]);
}
function ai_dialogue_context(array $npc,array $job,float $scale): string {
    $jobs=ai_dialogue_jobs($job);if(count($jobs)<2)return '';
    $out="\nDIALOGO A PIÙ VOCI: l'ultima battuta è rivolta a ".implode(', ',array_column($jobs,'name')).'. Ognuno risponde con un messaggio distinto. Tu interpreti soltanto '.$npc['name'].': non scrivere parole o azioni dei colleghi, né parlare al loro posto. Rispondi alla richiesta attuale con il tuo carattere e il tuo grado. Non ricominciare saluti o presentazioni.';
    $previous=array_values(array_filter($jobs,static fn($other)=>(int)$other['id']<(int)$job['id']&&$other['status']==='done'));
    // The context budget belongs to the group, not to each additional witness.
    $perReply=max(50,(int)floor(1200*max(.3,$scale)/max(1,count($previous))));
    foreach($previous as $other){
        $reply=json_decode($other['result']??'{}',true)['testo']??'';
        $out.="\nHa appena parlato ".$other['name'].' (contesto pubblico, non da ricopiare): '.ai_text($reply,min(500,$perReply));
    }
    return $out;
}

/** Remove long sentences copied from recent turns, before the word limit can discard the new answer. */
function ai_dialogue_without_repeats(array $npc,array $job,string $text): string {
    if(!in_array($job['kind'],['play','observe','reaction'],true))return $text;
    $text=str_replace(['\\r\\n','\\n'],["\n","\n"],$text);
    $normalize=static function(string $value):string{
        $value=mb_strtolower(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$value));
    };
    $previous=ai_db()->query('SELECT content FROM ai_events WHERE chat_key=? AND npc_id=? AND (job_id IS NULL OR job_id<>?) AND created_at>=? ORDER BY id DESC LIMIT 3',[$job['chat_key'],(int)$npc['id'],(int)($job['id']??0),(int)($job['created_at']??time())-1800]);
    if(!$previous)return $text;
    foreach($previous as $row){
        preg_match_all('/[\p{L}\p{N}]+/u',$row['content'],$oldWords);
        preg_match_all('/[\p{L}\p{N}]+/u',$text,$newWords,PREG_OFFSET_CAPTURE);
        $old=array_map('mb_strtolower',$oldWords[0]);$new=array_map(static fn($word)=>mb_strtolower($word[0]),$newWords[0]);
        if(count($old)>=14&&array_slice($new,0,count($old))===$old){
            [$last,$offset]=$newWords[0][count($old)-1];
            $text=preg_replace('/^[\s.!?«»“”"]+/u','',substr($text,$offset+strlen($last)));
        }
    }
    $history=array_map(static fn($row)=>' '.$normalize($row['content']).' ',$previous);
    $sentences=preg_split('/(?<=[.!?])(?:[»”"])*(?:\s+|$)/u',$text,-1,PREG_SPLIT_OFFSET_CAPTURE|PREG_SPLIT_NO_EMPTY);
    // Use offsets in the original text so unmatched punctuation, quotes and accents are preserved.
    $remove=[];
    foreach($sentences as $i=>[$sentence,$start]){
        $end=$sentences[$i+1][1]??strlen($text);$fragment=substr($text,$start,$end-$start);$normal=$normalize($fragment);
        if(preg_match_all('/\p{L}+/u',$normal)<8||mb_strlen($normal)<45)continue;
        foreach($history as $old)if(str_contains($old,' '.$normal.' ')){$remove[]=[$start,$end-$start];break;}
    }
    foreach(array_reverse($remove) as [$start,$length])$text=substr_replace($text,'',$start,$length);
    $text=trim(preg_replace('/^\s*[»”]+\s*/u','',$text));
    if(mb_strlen($normalize($text))<30)throw new RuntimeException('Risposta ripetuta: nuovo tentativo senza pubblicare il duplicato.');
    return $text;
}
