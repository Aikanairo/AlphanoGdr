<?php
declare(strict_types=1);
require_once __DIR__.'/ai-feelings.inc.php';
require_once __DIR__.'/ai-relation-context.inc.php';

/** Personal recollections extend the existing social ledger, never the global canon. */
const AI_MEMORY_SOURCES=['dialogue'=>'Turno osservato: dichiarazioni e tentativi','interpreted'=>'Percezione del PNG, non fatto accertato','admin'=>'Annotazione verificata dallo staff','system'=>'Esito verificato dal gioco'];

/** Thoughts and out-of-character notes are not something an interlocutor can hear. */
function ai_personality_observable(string $raw,int $limit=800): string {
    if(function_exists('ai_narrative_parse')){
        $parsed=ai_narrative_parse($raw);$parts=[];
        foreach($parsed['segments']??[] as $part){
            if(!in_array($part['kind']??'',['speech','action'],true))continue;
            $text=trim((string)($part['text']??''));if($text!=='')$parts[]=($part['kind']==='speech'?'Parole: ':'Azione dichiarata: ').$text;
        }
        return ai_text(implode(' / ',$parts),$limit);
    }
    return ai_text($raw,$limit);
}

/** Keep at most three evidence-linked recollections inside the local model's small context. */
function ai_personality_recall(int $npc,int $player,array $job,int $limit=3): array {
    if(ai_recognition_anonymous(['id'=>$npc],$job))return [];
    $limit=max(1,min(3,$limit));$chat=(string)($job['chat_key']??'');
    // A private room is an exact scope, not a wildcard for every private meeting.
    $private=preg_match('/^(?:rent_|pvt_)/D',$chat)===1;
    $public="(LEFT(chat_key,5)<>'rent_' AND LEFT(chat_key,4)<>'pvt_')";
    $scope=$private?" AND ($public OR chat_key=?)":" AND $public";
    $scope.=ai_relation_context_memory_scope($npc,$player);
    $params=[$npc,$player];if($private)$params[]=$chat;
    $already=array_slice(array_values(array_unique(array_filter(array_map('intval',$job['conversation_job_ids']??[])))),0,4);
    // Immediate perception is the current episode, not an older memory to replay.
    if((int)($job['id']??0)>0)$already[]=(int)$job['id'];
    // A staff correction of a just-shown turn is new authoritative context,
    // even when its original dialogue receipt already appears in role history.
    if($already)$scope.=" AND (source='admin' OR job_id IS NULL OR job_id NOT IN (".implode(',',$already).'))';
    $columns='id,category,summary,evidence,source,epistemic,chat_key,created_at,updated_at';
    $db=ai_db();$recent=$db->query('SELECT '.$columns.' FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1'.$scope.' ORDER BY created_at DESC,id DESC LIMIT '.$limit,$params);
    $term=ai_text(ai_personality_observable((string)($job['prompt']??''),350),350);
    $relevant=$term!==''?$db->query('SELECT '.$columns.' FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1'.$scope.' AND MATCH(summary) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY MATCH(summary) AGAINST (? IN NATURAL LANGUAGE MODE) DESC,id DESC LIMIT 2',[...$params,$term,$term]):[];
    // One durable episode survives a run of routine greetings; there is no
    // second mutable biography and no private-room scope is widened here.
    // An explicit staff correction must remain usable even when it edits an old
    // receipt. Its original scene date stays intact; updated_at ranks the edit.
    $important=$db->query('SELECT '.$columns.' FROM ai_personal_memories WHERE npc_id=? AND player_id=? AND active=1'.$scope." AND (source IN('admin','system') OR category NOT IN('incontro','dialogo')) ORDER BY (source='admin') DESC,CASE WHEN source='admin' THEN updated_at ELSE created_at END DESC,id DESC LIMIT 1",$params);
    $found=[];foreach([...$important,...$relevant,...$recent] as $memory){$found[(int)$memory['id']]=$memory;if(count($found)>=$limit)break;}
    return array_values($found);
}

/** New observed-turn receipts can safely recall both sides; legacy prose stays evidence-only. */
function ai_personality_memory_excerpt(array $memory,string $question,int $limit):string {
    $limit=max(100,min(220,$limit));$summary=(string)($memory['summary']??'');
    if(($memory['source']??'')!=='dialogue')return ai_text($summary,$limit);
    $evidence=(string)($memory['evidence']??'');
    if(trim($evidence)==='')return '';
    $observed=ai_personality_observable($evidence,800);
    $prefix='Turno osservabile del PG (parole e tentativi, non esiti accertati): '.ai_text($evidence,450).'. ';
    $reply='';
    // Match the server's complete observed PG prefix, not a phrase a player could
    // inject inside their own turn. The speaker may since have been renamed.
    if(str_starts_with($summary,$prefix)){
        $tail=substr($summary,strlen($prefix));
        if(preg_match('/^.{1,80}? ha risposto:\s*/u',$tail,$marker))$reply=ai_personality_observable(substr($tail,strlen($marker[0])),650);
    }
    $select=static function(string $text,int $budget)use($question):string{
        $text=preg_replace('/(?:Parole:\s*){2,}/u','Parole: ',$text)??$text;
        $text=preg_replace('/(?:Azione dichiarata:\s*){2,}/u','Azione dichiarata: ',$text)??$text;
        return function_exists('ai_prompt_relevant_text')?ai_prompt_relevant_text($text,$question,$budget):ai_text($text,$budget);
    };
    if($reply==='')return $select($observed,$limit);
    $replyBudget=max(60,(int)(($limit-23)*.65));$playerBudget=max(30,$limit-23-$replyBudget);
    // The local selector has an 80-character lower bound; enforce the pair's
    // individual allocations as well so an entire transcript can never return.
    $pg=ai_text($select($observed,max(80,$playerBudget)),$playerBudget);
    $own=ai_text($select($reply,max(80,$replyBudget)),$replyBudget);
    return ai_text('PG: '.$pg.' / Tua risposta: '.$own,$limit);
}

function ai_personality_context(array $npc,array $job,float $scale,array $relation): string {
    if(ai_recognition_anonymous($npc,$job))return '\nMantieni il tuo carattere, ma non attribuire a questa figura coperta sentimenti, traumi o familiarità vissuti con persone identificate.';
    $scale=max(.15,min(1.0,$scale));$compact=$scale<=.5;
    $out=$compact?"\nCARATTERE: voce e valori stabili, reazione concreta al turno attuale. Le esperienze mutano gradualmente il rapporto.":"\nCARATTERE VIVO: conserva voce, valori e obiettivi del profilo; una conversazione può mutare il rapporto, non riscrivere la personalità. Reagisci al tema e al gesto attuali con una scelta concreta, senza ripetere formule o cercare sempre un conflitto.";
    $signals=[];
    foreach(['sympathy','trust','respect','hostility','fear','affection','attraction','love','jealousy','honor'] as $key){
        $value=(int)($relation[$key]??0);if(abs($value)<1)continue;
        $signals[$key]=$value;
    }
    uasort($signals,static fn($a,$b)=>abs($b)<=>abs($a));$tone=[];
    foreach(array_slice($signals,0,3,true) as $key=>$value)$tone[]=match($key){
        'sympathy'=>$value<0?'antipatia personale':($value>=40?'simpatia consolidata':'simpatia nascente'),
        'trust'=>$value<0?'diffidenza':($value>=40?'fiducia consolidata':'cauta fiducia'),
        'respect'=>$value<0?'scarsa stima':($value>=40?'profonda stima':'rispetto crescente'),
        'hostility'=>$value>=40?'forte ostilità':'tensione irrisolta',
        'fear'=>$value>=40?'forte timore':'cautela',
        'honor'=>$value<0?'dubbi sulla sua correttezza':'stima della sua correttezza',
        'attraction'=>'interesse personale, senza dare per scontata reciprocità',
        'love'=>'legame affettivo maturato, sempre rispettando disponibilità e limiti',
        'jealousy'=>'insicurezza del legame, senza possesso o controllo',
        default=>$value>=40?'affetto consolidato':'affetto crescente'
    };
    if($tone)$out.=' Tono: '.implode(', ',$tone).($compact?'.':' ; mostralo in gesti e parole, senza nominare punteggi.');
    $out.=ai_relation_feelings_context($npc,$job,$relation);
    $memories=ai_personality_recall((int)$npc['id'],(int)$job['user_id'],$job,$compact?2:3);
    if($memories)$out.=$compact?"\nRICORDI PERSONALI, già avvenuti:":"\nRICORDI DI QUESTA COPPIA (contesto già avvenuto, non nuova battuta; non trasferire segreti o esiti alla scena):";
    foreach($memories as $memory){
        $source=match($memory['source']){'admin'=>'staff','system'=>'esito verificato','interpreted'=>'percezione',default=>'parole/tentativi'};
        $summary=ai_personality_memory_excerpt($memory,(string)($job['prompt']??''),$compact?190:max(100,(int)(190*$scale)));
        if($summary!=='')$out.="\n".$source.' · '.$memory['category'].': '.$summary;
    }
    if($memories)$out.=$compact?' Dichiarazioni e tentativi non provano esiti; niente pensieri letti o segreti privati.':' Promesse e racconti restano dichiarazioni; un tentativo non prova un risultato. Ricorda solo ciò che hai osservato, senza leggere pensieri o rivelare colloqui privati.';
    $out.=ai_coercion_context($npc,$job);
    return $out;
}

/** Staff-only callers receive bounded rows and aggregate statistics from the same ledger. */
function ai_personality_overview(int $npc,int $limit=40,int $offset=0): array {
    $limit=max(1,min(100,$limit));$offset=max(0,$offset);$db=ai_db();
    $totals=$db->query('SELECT COUNT(*) AS players,COALESCE(SUM(interactions),0) AS interactions,COALESCE(SUM(encounters),0) AS encounters,COALESCE(SUM(meaningful),0) AS meaningful,COALESCE(MAX(last_encounter),0) AS last_encounter FROM ai_relationships WHERE npc_id=?',[$npc])[0];
    $pairs=$db->query('SELECT r.*,u.id,u.nome,u.sesso,u.razza,u.avatar,u.avatar2,u.vampire_human_form,u.chat AS player_chat,m.title AS player_place FROM ai_relationships r JOIN utenti u ON u.id=r.player_id LEFT JOIN mappe m ON m.chat_key=u.chat WHERE r.npc_id=? ORDER BY r.last_encounter DESC,r.player_id LIMIT '.$limit.' OFFSET '.$offset,[$npc]);
    return ['totals'=>$totals,'players'=>$pairs,'limit'=>$limit,'offset'=>$offset,'more'=>(int)$totals['players']>$offset+count($pairs)];
}

/** The history is an audit view, not a new global biography or knowledge source. */
function ai_personality_history(int $npc,int $player=0,int $limit=30): array {
    $limit=max(1,min(100,$limit));$params=[$npc];$pair='';if($player>0){$pair=' AND m.player_id=?';$params[]=$player;}
    return ai_db()->query('SELECT m.id,m.player_id,m.chat_key,m.job_id,m.category,m.summary,m.evidence,m.source,m.epistemic,m.active,m.created_at,m.updated_at,u.nome FROM ai_personal_memories m JOIN utenti u ON u.id=m.player_id WHERE m.npc_id=?'.$pair.' ORDER BY m.created_at DESC,m.id DESC LIMIT '.$limit,$params);
}
