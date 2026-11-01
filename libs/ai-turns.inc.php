<?php
declare(strict_types=1);
class AIQuestTurnException extends DomainException {
    public function __construct(string $message){parent::__construct($message,409);}
}

/** One authoritative description for chat status and rejected actions. No model decides the queue. */
function ai_quest_turn_state(array $quest, ?array $guidance=null): array {
    $guidance??=ai_game_guidance((int)$quest['session_id']);
    $order=json_decode($guidance['turn_order'],true)?:[];
    $cast=ai_db()->query('SELECT kind,actor_id,active,state FROM ai_quest_cast WHERE session_id=?',[(int)$quest['session_id']]);
    $active=[];$joining=[];
    foreach($cast as $member){
        $key=$member['kind'].':'.$member['actor_id'];
        if($member['active'])$active[$key]=true;
        elseif((int)(json_decode($member['state'],true)['join_round']??PHP_INT_MAX)<=(int)$guidance['round_number']+1)$joining[]=['kind'=>$member['kind'],'id'=>(int)$member['actor_id']];
    }
    $queue=[];$remaining=[];
    foreach($order as $index=>$member){
        if(!isset($active[$member['kind'].':'.$member['id']]))continue;
        if($quest['mode']==='human'&&($member['kind']==='npc'||(int)$member['id']===(int)($quest['conductor_id']??0)))continue;
        $entry=['kind'=>$member['kind'],'id'=>(int)$member['id'],'name'=>ai_quest_name($member['kind'],(int)$member['id'])];
        $queue[]=$entry;
        if($index>=(int)$guidance['turn_index'])$remaining[]=$entry;
    }
    $next=$queue;
    foreach($joining as $member)$next[]=[...$member,'name'=>ai_quest_name($member['kind'],$member['id'])];
    if((json_decode($quest['policy']??'{}',true)['initiative']??'entry')==='force')usort($next,static fn($a,$b)=>(int)(ai_quest_actor($b['kind'],$b['id'])['forza']??0)<=>(int)(ai_quest_actor($a['kind'],$a['id'])['forza']??0));
    $paused=(bool)$guidance['paused'];$status=$quest['status'];
    $turn=(!$paused&&$status==='active')?($remaining[0]['name']??null):null;
    $label=$paused?'Regia sospesa dal Master':match($status){
        'review'=>$quest['mode']==='human'?'Giro completato · esito al Master umano':'Giro completato · in attesa di convalida del Master',
        'resolving'=>'Giro completato · il Master IA sta preparando il verdetto',
        'attention'=>'Intervento del Master richiesto',
        'closed'=>'Quest conclusa',
        default=>$turn?'Tocca a '.$turn:'In attesa del prossimo turno',
    };
    return ['turn'=>$turn,'phase_label'=>$label,'queue'=>$queue,'remaining'=>$status==='active'?$remaining:[],'next_queue'=>$next];
}

function ai_quest_wait_message(array $quest,array $guidance,int $viewer=0): string {
    $state=ai_quest_turn_state($quest,$guidance);
    $names=static fn(array $queue)=>implode(' → ',array_column($queue,'name'));
    if($guidance['paused'])$message='La regia è sospesa dal Master.';
    elseif($quest['status']==='review')$message='Il giro '.(int)$guidance['round_number'].' è completato. Si attende la convalida del Master: in questa fase non tocca a nessun partecipante.';
    elseif($quest['status']==='resolving')$message='Il giro '.(int)$guidance['round_number'].' è completato. Il Master IA sta preparando il verdetto: attendi prima di inviare una nuova azione.';
    elseif($quest['status']==='attention')$message='La quest richiede l’intervento del Master prima di continuare.';
    elseif($state['remaining']){
        $before=$state['remaining'];
        foreach($before as $index=>$member)if($member['kind']==='pg'&&$member['id']===$viewer){$before=array_slice($before,0,$index);break;}
        $message='Attendi il tuo turno. '.($before?'Prima tocca a: '.$names($before).'. ':'').'Coda del giro: '.$names($state['remaining']).'.';
    }
    else $message='La regia deve assegnare il prossimo turno prima di continuare.';
    $next=$names($state['next_queue']);
    if($next!=='')$message.=' Ordine del prossimo giro, dopo il verdetto: '.$next.'.';
    return $message.' Puoi comunicare fuori gioco con //.';
}
