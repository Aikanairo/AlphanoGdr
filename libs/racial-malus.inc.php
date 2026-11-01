<?php
declare(strict_types=1);
/** Manual v2 racial weaknesses. Conditions are recorded by staff or verified scene resolution. */
function rpg_racial_family(int $race):string {return in_array($race,[2,5],true)?'vampire':(in_array($race,[3,6],true)?'lycan':($race===alphano_human_race_id()?'human':'other'));}
function rpg_racial_catalog(int $race):array {return array_values(array_filter(rpg_human_manual()['malus'],static fn($m)=>$m['family']===rpg_racial_family($race)&&!($race===5&&$m['number']===8)));}
function rpg_malus_fields():array {return ['corruption_offer'=>['Offerta corruttiva credibile accertata',0,1],'decisive_desire'=>['Offerta che soddisfa un desiderio documentato',0,1],'wound'=>['Ferita fisica (0 nessuna, 1 lieve, 2 grave)',0,2],'fatigue'=>['Turni di sforzo intenso senza riposo',0,5],'sunlight'=>['Esposizione diretta consecutiva (turni)',0,4],'indirect_light'=>['Luce indiretta intensa',0,1],'sacred'=>['Sacro (0 nessuno, 1 croce, 2 reliquia, 3 acqua santa)',0,3],'silver_contact'=>['Argento a contatto',0,1],'silver_wound'=>['Ferita d’argento (0 nessuna, 1 lieve, 2 grave)',0,2],'silver_embedded'=>['Argento trattenuto nella ferita',0,1],'dismembered'=>['Smembramento completo',0,1],'destroyed'=>['Combustione totale accertata',0,1]];}
function rpg_malus_state(string $kind,int $id):array {if(!rpg_creation_ready())return [];return rpg_decode(ConnectDB()->query('SELECT conditions FROM rpg_malus_state WHERE owner_kind=? AND owner_id=?',[$kind,$id])[0]['conditions']??'{}');}
function rpg_malus_evaluate(int $race,array $state,string $test='physical'):array {
 $family=rpg_racial_family($race);$groups=[];$active=[];$incapacitated=false;$dead=false;$blocked=false;
 $add=static function(string $group,int $penalty,int $number)use(&$groups,&$active){if($penalty>0){$groups[$group]=max($groups[$group]??0,$penalty);$active[]=$number;}};
 if($family==='human'){
  if($test==='physical'){$add('wound',match((int)($state['wound']??0)){2=>25,1=>10,default=>0},1);$add('fatigue',($state['fatigue']??0)>=5?20:(($state['fatigue']??0)>=3?10:0),4);}
 }elseif($family==='vampire'){
  $sun=(int)($state['sunlight']??0);$dead=$sun>=4||!empty($state['destroyed']);$incapacitated=$sun>=3||!empty($state['dismembered'])||$dead;
  $add('sunlight',$sun>=2?35:($sun===1?20:(!empty($state['indirect_light'])?10:0)),5);
  if($test==='mental')$add('sacred',match((int)($state['sacred']??0)){3=>25,2=>20,1=>15,default=>0},7);
 }elseif($family==='lycan'){
  if($test==='physical')$add('wound',match((int)($state['silver_wound']??0)){2=>30,1=>15,default=>0},9);
  if(in_array($test,['physical','mental'],true))$add('silver_contact',!empty($state['silver_contact'])||!empty($state['silver_after'])?15:0,10);
  if(!empty($state['silver_embedded'])&&!empty($state['silver_wound'])){$blocked=true;if($test==='physical')$add('silver_embedded',10,11);}
 }
 return ['penalty'=>array_sum($groups),'groups'=>$groups,'active'=>array_values(array_unique($active)),'incapacitated'=>$incapacitated,'dead'=>$dead,'regeneration_blocked'=>$blocked];
}
function rpg_racial_sheet(string $kind,int $id,array $identity):array {
 $race=(int)($identity['native_race_id']??$identity['race_id']);$state=rpg_malus_state($kind,$id);$command=null;$corruption=null;$source='Condizioni registrate dallo staff';
 if(rpg_creation_ready()&&ConnectDB()->query("SHOW TABLES LIKE 'ai_quest_cast'")){$row=ConnectDB()->query("SELECT c.state,g.round_number FROM ai_quest_cast c JOIN ai_quests q ON q.session_id=c.session_id LEFT JOIN ai_session_guidance g ON g.session_id=c.session_id WHERE c.kind=? AND c.actor_id=? AND c.active=1 AND q.status='active' ORDER BY q.created_at DESC LIMIT 1",[$kind,$id])[0]??[];$scene=rpg_decode($row['state']??'{}');$corruption=$scene['corruption']??null;if($corruption&&(int)$corruption['until']<(int)($row['round_number']??1))$corruption=null;$command=$scene['dominated']??null;if($command&&(int)$command['until']<(int)($row['round_number']??1))$command=null;if(isset($scene['racial_malus'])){$state=array_replace($state,$scene['racial_malus']);$source='Condizioni dell’ultima quest attiva';}}
 $state=rpg_malus_wounds($identity,$state);$result=rpg_malus_evaluate($race,$state);return ['race_id'=>$race,'family'=>rpg_racial_family($race),'catalog'=>rpg_racial_catalog($race),'state'=>$state,'command'=>$command,'corruption'=>$corruption,'source'=>$source,'physical'=>$result,'mental'=>rpg_malus_evaluate($race,$state,'mental'),'fields'=>rpg_malus_fields()];
}
function rpg_save_malus(string $kind,int $id,mixed $input,string $reason,int $actor):array {
 if(!is_array($input)||array_diff(array_keys($input),array_keys(rpg_malus_fields())))throw new DomainException('Condizioni razziali non valide.',422);$values=[];foreach(rpg_malus_fields() as $key=>[$label,$min,$max])$values[$key]=rpg_int($input[$key]??0,$min,$max,$label);$reason=rpg_text($reason,500,true);
 return rpg_atomic(function()use($kind,$id,$values,$reason,$actor){$identity=rpg_identity($kind,$id,true);$before=rpg_malus_state($kind,$id);if(!empty($before['silver_contact'])&&empty($values['silver_contact']))$values['silver_after']=1;ConnectDB()->execute('INSERT INTO rpg_malus_state(owner_kind,owner_id,conditions,reason,actor_id,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE conditions=VALUES(conditions),reason=VALUES(reason),actor_id=VALUES(actor_id),updated_at=VALUES(updated_at)',[$kind,$id,rpg_json($values),$reason,$actor,time()]);
 // An explicit staff edit replaces old counters in every active scene for this actor.
 foreach(ConnectDB()->query("SELECT c.session_id,c.state FROM ai_quest_cast c JOIN ai_quests q ON q.session_id=c.session_id WHERE c.kind=? AND c.actor_id=? AND q.status='active' FOR UPDATE",[$kind,$id]) as $row){$s=rpg_decode($row['state']);$s['racial_malus']=$values;ConnectDB()->execute('UPDATE ai_quest_cast SET state=? WHERE session_id=? AND kind=? AND actor_id=?',[rpg_json($s),$row['session_id'],$kind,$id]);}
 rpg_admin_audit($actor,'save_malus',$kind.':'.$id,$before,['conditions'=>$values,'reason'=>$reason]);return rpg_sheet($kind,$id);});
}
/** A single resistance roll, persisted in the verdict. Only the relevant acquired skill applies. */
function rpg_domination_resolution(int $sourceRace,int $targetRace,array $owned,int $roll,array $chances=[]):array {
 $duration=$sourceRace===5?5:3;$skill=$sourceRace===5?'human_v2_52':'human_v2_51';$eligible=$targetRace===alphano_human_race_id()||($sourceRace===5&&$targetRace===2);
 $chance=$targetRace===alphano_human_race_id()&&in_array($skill,$owned,true)?(int)($chances[$skill]??($sourceRace===5?40:60)):0;
 return ['eligible'=>$eligible,'resistance'=>$chance,'roll'=>$chance?$roll:null,'resisted'=>$eligible&&$chance>0&&$roll<=$chance,'duration'=>$duration];
}
/** Wound severity follows recorded injuries/HP; an ordinary lycan wound is never relabelled silver. */
function rpg_malus_wounds(array $actor,array $state):array {
 $race=(int)($actor['native_race_id']??$actor['razza']??$actor['race_id']??1);$wound=(string)($actor['ferite']??$actor['wounds']??'');$hp=(int)($actor['puntif']??$actor['hit_points']??1000);$severe=$hp<=500||preg_match('/grave|gravissima|profonda/iu',$wound);$present=trim($wound)!=='';
 if(rpg_racial_family($race)==='human'&&$present)$state['wound']=max((int)($state['wound']??0),$severe?2:1);
 if(rpg_racial_family($race)==='lycan'&&$present&&preg_match('/argent/iu',$wound))$state['silver_wound']=max((int)($state['silver_wound']??0),$severe?2:1);
 return $state;
}
function rpg_malus_scene(array $actor,array $state,int $round,string $intent):array {
 $m=rpg_malus_wounds($actor,array_replace(rpg_malus_state($actor['kind'],(int)$actor['id']),$state['racial_malus']??[]));
 if(($m['_round']??0)>=$round)return $m;
 if(!empty($m['sunlight'])&&isset($m['_round']))$m['sunlight']=min(4,(int)$m['sunlight']+1);
 if(!empty($m['sacred'])&&$m['sacred']>=2){$m['_sacred_until']??=$round+1;if($round>$m['_sacred_until'])$m['sacred']=0;}
 if(!empty($m['silver_after'])){$m['_silver_until']??=$round;if($round>$m['_silver_until'])$m['silver_after']=0;}
 if(in_array($intent,['assalto','attacco','arresto','fuga','ritirata','allenamento'],true))$m['fatigue']=min(5,(int)($m['fatigue']??0)+1);
 // Only an actual sustained effort adds fatigue; unrelated speech never does.
 $m['_round']=$round;return $m;
}
function rpg_malus_context(array $sheet):string {
 $m=$sheet['malus']??[];if(!$m)return '';$lines=[];foreach($m['catalog'] as $rule)$lines[]=$rule['name'].': '.$rule['effect'];return "\nREGOLE RAZZIALI V2, PREVALENTI SUI VECCHI ESEMPI: ".implode(' ',$lines).' Condizioni accertate: '.rpg_json($m['state']).'. Comando soprannaturale attualmente registrato (da tentare in gioco, senza esito automatico): '.rpg_json($m['command']??[]).'. Valutazione di un’offerta corruttiva accertata: '.rpg_json($m['corruption']??[]).'. Penalità attive fisiche '.$m['physical']['penalty'].' p.p., mentali '.$m['mental']['penalty'].' p.p. Il modello narra soltanto: sole, argento e condizioni permanenti devono essere verificati dal backend o dal Master; non inventarli.';
}
