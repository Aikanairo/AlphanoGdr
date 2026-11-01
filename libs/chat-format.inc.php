<?php
declare(strict_types=1);

function alphano_chat_gender(string $sex): string {
    return $sex==='Femmina'?'chat-female':($sex==='Maschio'?'chat-male':'chat-neutral');
}

/** Solo testo: mai HTML del giocatore o del modello. I dialoghi restano normali. */
function alphano_chat_text(string $text, string $sex, bool $action=false): string {
    $escape=static fn(string $v)=>nl2br(htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
    $narrate=static fn(string $v)=>'<strong class="chat-action '.alphano_chat_gender($sex).'">'.$escape($v).'</strong>';
    $pattern='/«[^»]*»|“[^”]*”|"[^"\r\n]*"/u';
    preg_match_all($pattern,$text,$quotes,PREG_OFFSET_CAPTURE);
    if ($quotes[0]) {
        $html='';$offset=0;
        foreach ($quotes[0] as [$quote,$position]) {
            $html.=$narrate(substr($text,$offset,$position-$offset)).'<span class="chat-speech">'.$escape($quote).'</span>';
            $offset=$position+strlen($quote);
        }
        return $html.$narrate(substr($text,$offset));
    }
    if ($action) return $narrate($text);
    // Un messaggio senza delimitatori rimane parlato; *gesto* marca un inciso.
    $parts=preg_split('/(\*[^*\r\n]+\*)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE);
    return implode('',array_map(static fn($part)=>str_starts_with($part,'*')&&str_ends_with($part,'*')?$narrate(mb_substr($part,1,-1)):$escape($part),$parts));
}

/** Keep observable actions readable when a wolf form mixes gestures and its own language. */
function alphano_chat_lupine_text(string $text,string $sex,bool $action=false):array {
    require_once __DIR__.'/ai-narrative.inc.php';
    $reading=ai_narrative_parse($text,['channel'=>$action?'action':'speech']);$parts=[];
    foreach($reading['segments'] as $segment){
        $escaped=nl2br(htmlspecialchars($segment['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
        $parts[]=$segment['kind']==='speech'
            ?'<span class="chat-speech" data-language="lupine">«'.$escaped.'»</span>'
            :'<strong class="chat-action '.alphano_chat_gender($sex).'">'.$escaped.'</strong>';
    }
    return ['html'=>implode(' ',$parts),'speech'=>$reading['speech']!==''];
}

function alphano_chat_mask_lupine(string $html):string {
    return preg_replace('~<span\b[^>]*\bdata-language="lupine"[^>]*>.*?</span>~su','<span class="chat-lupine">[un ringhio incomprensibile]</span>',$html)??$html;
}
