<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';

/** Configurable forms belong to a race; no reserved race IDs or inherited lore. */
function alphano_transformation_ready():bool {return true;}
function alphano_human_race_id(bool $refresh=false):int {
    return (int)(ConnectDB()->query('SELECT race_id FROM rpg_races WHERE human_default=1 AND enabled=1 AND special=0 ORDER BY race_id LIMIT 1')[0]['race_id']??0);
}
function alphano_native_race(array $user):int {return (int)($user['razza']??$user['native_race_id']??$user['race_id']??0);}
function alphano_visible_race(array $user):int {return (int)($user['razza']??$user['race_id']??0);}
function alphano_race_language_ready(bool $refresh=false):bool {return true;}
function alphano_race_loses_human_language(int $race,bool $refresh=false):bool {return (bool)(ConnectDB()->query('SELECT loses_human_language FROM rpg_races WHERE race_id=?',[$race])[0]['loses_human_language']??false);}
function alphano_loses_human_language(array $user):bool {return !empty($user['alternate_form'])&&alphano_race_loses_human_language(alphano_visible_race($user));}
function alphano_transformation_race_label(int $race):string {return (string)(ConnectDB()->query('SELECT nome FROM razze WHERE id=?',[$race])[0]['nome']??'Non assegnata');}
function alphano_transformation_default_messages(int $race):array {return ['special'=>'{nome} assume la forma alternativa di {razza}.','human'=>'{nome} ritorna alla propria forma ordinaria.'];}
function alphano_transformation_config(int $race,bool $refresh=false):array {
    $d=alphano_transformation_default_messages($race);$r=ConnectDB()->query('SELECT * FROM rpg_races WHERE race_id=?',[$race])[0]??[];
    return ['enabled'=>!empty($r['enabled'])&&!empty($r['transformation_enabled']),'human_text'=>trim((string)($r['transformation_human_text']??''))?:$d['human'],'special_text'=>trim((string)($r['transformation_special_text']??''))?:$d['special'],'stats'=>json_decode($r['transformation_stats']??'{}',true)?:[]];
}
function alphano_transformation_state(array $user,?int $now=null):array {
    $race=alphano_native_race($user);$config=alphano_transformation_config($race);$alive=(int)($user['puntif']??$user['hit_points']??0)>0&&empty($user['bloccato']);$enabled=$alive&&$config['enabled'];$form=!empty($user['alternate_form'])?'native':'human';
    return ['eligible'=>$alive&&$race>0,'enabled'=>$enabled,'visible'=>$enabled,'canChoose'=>$enabled,'canTransform'=>$enabled,'ancestral'=>false,'fullMoon'=>false,'form'=>$form,'nextForm'=>$form==='human'?'native':'human','availableForms'=>$enabled?['human','native']:[],'race'=>$race,'native_race_id'=>$race,'visible_race_id'=>$race,'human_race_id'=>$race,'kind'=>'custom','phase'=>'','loses_human_language'=>alphano_loses_human_language($user),'label'=>$form==='human'?'Assumi la forma alternativa':'Ritorna alla forma ordinaria','description'=>$enabled?'Il cambiamento usa statistiche e messaggi definiti dalla gestione.':'Trasformazione non abilitata per questo personaggio.'];
}
function alphano_transformation_in_chat(array $user,int $now):bool {
    $chat=(string)($user['chat']??'');if($chat===''||(int)($user['lastmove']??0)<$now-3600||in_array((string)($user['posizione']??''),['','online','off'],true))return false;
    if(preg_match('/^(?:rent_|pvt_)[a-f0-9]{40}$/D',$chat))return true;
    return (bool)ConnectDB()->query('SELECT 1 FROM mappe WHERE chat_key=? AND chat=1 AND public_enabled=1 LIMIT 1',[$chat]);
}
function alphano_transformation_sync(int $id,?string $requested=null,?int $now=null):array {
    $now??=time();$db=ConnectDB();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        $user=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$id])[0]??null;if(!$user)throw new DomainException('Personaggio non trovato.',404);
        if($requested!==null&&!in_array($requested,['human','native'],true))throw new DomainException('Forma non valida.',422);
        $state=alphano_transformation_state($user,$now);
        if($requested!==null&&!$state['enabled'])throw new DomainException('Trasformazione non disponibile.',403);
        $changed=$requested!==null&&$state['form']!==$requested;
        if($changed){
            $db->execute('UPDATE utenti SET alternate_form=?,ultimatrasformazione=? WHERE id=?',[(int)($requested==='native'),$now,$id]);$user['alternate_form']=(int)($requested==='native');
            $db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,?,?,0,0,?)',['metamorfosi',$now,(string)($user['chat']??''),$id,$id,'Forma '.$state['form'].' → '.$requested]);
            if(alphano_transformation_in_chat($user,$now)){
                require_once __DIR__.'/libchat.inc.php';$config=alphano_transformation_config($state['native_race_id']);$text=strtr($requested==='human'?$config['human_text']:$config['special_text'],['{nome}'=>(string)$user['nome'],'{razza}'=>alphano_transformation_race_label($state['native_race_id']),'{forma}'=>$requested==='human'?'ordinaria':'alternativa']);
                InsertIntoChat((string)$user['chat'],'<div class="MasterKey scene-note transformation-event"><strong>Trasformazione</strong><p>'.nl2br(htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p></div>',['kind'=>'transformation','actor_id'=>$id,'native_race_id'=>$state['native_race_id'],'visible_race_id'=>$state['native_race_id'],'form'=>$requested,'text'=>$text]);
            }
        }
        if($own)$db->commit();return alphano_transformation_state($user,$now)+['changed'=>$changed];
    }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}
function alphano_transformation_tick(?int $now=null):int {return 0;}
