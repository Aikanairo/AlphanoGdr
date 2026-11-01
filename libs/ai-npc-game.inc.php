<?php
declare(strict_types=1);
const AI_NPC_TRAITS=['aggression'=>'Aggressività','courage'=>'Coraggio','impulsiveness'=>'Impulsività','patience'=>'Pazienza','pride'=>'Orgoglio','distrust'=>'Diffidenza','sociability'=>'Socievolezza','empathy'=>'Empatia','compassion'=>'Compassione','loyalty'=>'Lealtà','honesty'=>'Onestà','cruelty'=>'Crudeltà','ambition'=>'Ambizione','greed'=>'Avidità','generosity'=>'Generosità','cunning'=>'Astuzia','diplomacy'=>'Diplomazia','authoritarianism'=>'Autoritarismo','obedience'=>'Obbedienza','vindictiveness'=>'Vendicatività','romanticism'=>'Romanticismo','self_control'=>'Autocontrollo','fighting_spirit'=>'Spirito combattivo','survival_instinct'=>'Istinto di sopravvivenza'];
const AI_NPC_STANCES=['combattivo','difensivo','prudente','noncombattente'];
const AI_NPC_SUPER_RULES=['ordinary_cap'=>20,'elite_cap'=>5,'training_min'=>50,'courage_min'=>60,'stats_min'=>30,'stats_full'=>60,'abilities_full'=>3,'decisive_hp_percent'=>10];
const AI_GAME_SLOTS=['','guanti','elmo','mantello','veste','armasx','armatura','armadx','bracciali','faretra','stivali','scudo','musica','anellosx','collana','anellodx','attrezzi'];
function ai_npc_super_races():array{return array_values(array_filter(rpg_catalog()['races'],static fn($r)=>(!empty($r['special'])||!empty($r['transformation_enabled']))&&!empty($r['enabled'])&&empty($r['human_default'])));}
function ai_npc_super_defaults():array{return ['enabled'=>0,'target_race_ids'=>[],'training'=>0,'important_role'=>0,'ordinary_victory_cap'=>AI_NPC_SUPER_RULES['ordinary_cap'],'elite_victory_cap'=>AI_NPC_SUPER_RULES['elite_cap']];}
function ai_npc_game_defaults():array{return ['super'=>ai_npc_super_defaults(),'traits'=>array_fill_keys(array_keys(AI_NPC_TRAITS),50),'stance'=>'prudente','faction'=>'','rank'=>'','superior_kind'=>'none','superior_id'=>0,'organization_office_id'=>0,'corporation_office_id'=>0,'allies'=>[],'enemies'=>[],'autonomous'=>0,'sentient'=>0,'conditions'=>'','duties'=>''];}
function ai_npc_game(int $id):array{$row=ai_db()->query('SELECT settings FROM ai_npc_game WHERE npc_id=?',[$id])[0]??[];$v=array_replace(ai_npc_game_defaults(),json_decode($row['settings']??'{}',true)?:[]);$v['super']=array_replace(ai_npc_super_defaults(),is_array($v['super'])?$v['super']:[]);$v['traits']=array_replace(ai_npc_game_defaults()['traits'],is_array($v['traits'])?$v['traits']:[]);return $v;}
function ai_npc_game_validate(array $in,int $npc):array{
 if(array_diff(array_keys($in),array_keys(ai_npc_game_defaults())))throw new DomainException('Caratteristica PNG non riconosciuta.',422);
 $v=array_replace(ai_npc_game_defaults(),$in);if(!is_array($v['traits'])||array_diff(array_keys($v['traits']),array_keys(AI_NPC_TRAITS)))throw new DomainException('Tratti non validi.',422);
 $v['super']=ai_npc_super_validate($v['super']);
 $v['traits']=array_replace(ai_npc_game_defaults()['traits'],$v['traits']);foreach(AI_NPC_TRAITS as $k=>$_)$v['traits'][$k]=ai_integer($v['traits'],$k,0,100);
 if(!in_array($v['stance'],AI_NPC_STANCES,true)||!in_array($v['superior_kind'],['none','pg','npc'],true))throw new DomainException('Profilo combattivo o superiore non valido.',422);
 foreach(['faction'=>100,'rank'=>100,'conditions'=>1500,'duties'=>1000] as $k=>$max)$v[$k]=ai_field($v,$k,$max);
 foreach(['autonomous','sentient'] as $k)$v[$k]=ai_integer($v,$k,0,1);
 foreach(['superior_id','organization_office_id','corporation_office_id'] as $k)$v[$k]=ai_integer($v,$k,0,2147483647);
 if($v['superior_kind']==='none')$v['superior_id']=0;
 elseif(!$v['superior_id']||($v['superior_kind']==='npc'&&$v['superior_id']===$npc)||!ai_db()->query('SELECT id FROM '.($v['superior_kind']==='pg'?'utenti':'ai_npcs').' WHERE id=?',[$v['superior_id']]))throw new DomainException('Superiore non disponibile.',422);
 if($v['superior_kind']==='npc'){$seen=[$npc=>true];$next=(int)$v['superior_id'];for($i=0;$i<16&&$next;$i++){if(isset($seen[$next]))throw new DomainException('La gerarchia contiene un ciclo.',422);$seen[$next]=true;$parent=ai_npc_game($next);$next=$parent['superior_kind']==='npc'?(int)$parent['superior_id']:0;}}
 foreach(['organization_office_id'=>'caricheorganizzazioni','corporation_office_id'=>'carichecorporazioni'] as $k=>$table)if($v[$k]&&!ai_db()->query('SELECT id FROM '.$table.' WHERE id=?',[$v[$k]]))throw new DomainException('Carica non disponibile.',422);
 foreach(['allies','enemies'] as $k){if(!is_array($v[$k])||!array_is_list($v[$k])||count($v[$k])>20)throw new DomainException('Massimo 20 fazioni nella lista.',422);$v[$k]=array_values(array_unique(array_map(static function($s){if(!is_string($s)||mb_strlen($s)>100)throw new DomainException('Fazione non valida.',422);return trim($s);},$v[$k])));}
 return $v;
}

/** Specialist status describes preparation, never grants powers, equipment or omniscience. */
function ai_npc_super_validate(mixed $in):array {
 if(!is_array($in)||array_diff(array_keys($in),array_keys(ai_npc_super_defaults())))throw new DomainException('Profilo PNG super non valido.',422);
 $v=array_replace(ai_npc_super_defaults(),$in);
 foreach(['enabled'=>1,'training'=>100,'important_role'=>1,'ordinary_victory_cap'=>AI_NPC_SUPER_RULES['ordinary_cap'],'elite_victory_cap'=>AI_NPC_SUPER_RULES['elite_cap']] as $key=>$max)$v[$key]=ai_integer($v,$key,0,$max);
 if(!is_array($v['target_race_ids'])||!array_is_list($v['target_race_ids'])||count($v['target_race_ids'])>20)throw new DomainException('Seleziona le razze affrontabili dal PNG.',422);
 $allowed=array_column(ai_npc_super_races(),'id');$ids=[];
 foreach($v['target_race_ids'] as $id){$id=rpg_int($id,1,100000,'Razza avversaria');if(!in_array($id,$allowed,true))throw new DomainException('La specializzazione deve indicare una razza speciale attiva.',422);$ids[]=$id;}
 $v['target_race_ids']=array_values(array_unique($ids));sort($v['target_race_ids']);
 if($v['enabled']&&!$v['target_race_ids'])throw new DomainException('Un PNG super richiede almeno una stirpe avversaria.',422);
 return $v;
}
/** Rank names and detached office IDs never imply relevance: the office must belong to the NPC's institution. */
function ai_npc_super_important_role(int $npc,array $game):bool {
 if(!empty($game['super']['important_role']))return true;
 $n=ai_db()->query('SELECT id,profile,chat_key FROM ai_npcs WHERE id=?',[$npc])[0]??[];if(!$n)return false;$profile=ai_npc_profile($n);
 foreach(['organization'=>['caricheorganizzazioni','organizzazione'],'corporation'=>['carichecorporazioni','corporazione']] as $kind=>[$table,$field]){
  if(empty($game[$kind.'_office_id'])||empty($profile[$kind.'_id']))continue;
  if(ai_db()->query("SELECT c.id FROM $table c WHERE c.id=? AND c.$field=? AND c.prima=0 AND c.livello=(SELECT MIN(t.livello) FROM $table t WHERE t.$field=c.$field AND t.prima=0)",[(int)$game[$kind.'_office_id'],(int)$profile[$kind.'_id']]))return true;
 }
 return !empty($profile['heraldry_id'])&&(bool)ai_db()->query('SELECT a.id FROM araldiche a WHERE a.id=? AND a.prima=0 AND a.livello=(SELECT MAX(t.livello) FROM araldiche t WHERE t.city=a.city)',[(int)$profile['heraldry_id']]);
}

/** Reuses the actual RPG sheet and usable inventory; a checkbox alone cannot create a champion. */
function ai_npc_super_capability(int $npc,int $race):array {
 $g=ai_npc_game($npc);$s=$g['super'];$sheet=rpg_sheet('npc',$npc,false);$inventory=ai_game_inventory('npc',$npc);
 $equipped=array_values(array_filter($inventory,static fn($i)=>($i['slot']??'')!==''&&(int)$i['consumo']<(int)$i['durata']));
 $weapon=(bool)array_filter($equipped,static fn($i)=>in_array($i['slot'],['armadx','armasx'],true));
 $protection=(bool)array_filter($equipped,static fn($i)=>in_array($i['slot'],['armatura','scudo'],true));
 $skills=array_values(array_filter($sheet['abilities'],static fn($a)=>!empty($a['usable'])&&in_array($a['effect']['type'],['attack','defence','mental','heal'],true)&&(empty($a['effect']['requires_weapon'])||$weapon)));
 $stats=$sheet['stats'];$physical=((int)$stats['strength']+(int)$stats['dexterity']+(int)$stats['constitution']+(int)$stats['willpower'])/4;
 $targeted=!empty($s['enabled'])&&in_array($race,array_map('intval',$s['target_race_ids']),true);
 $important=ai_npc_super_important_role($npc,$g);
 $readiness=max(0,min((int)$s['training'],(int)$g['traits']['courage'],(int)round(min(1,$physical/AI_NPC_SUPER_RULES['stats_full'])*100),(int)round(min(1,count($skills)/AI_NPC_SUPER_RULES['abilities_full'])*100),$weapon?100:0,$protection?100:50));
 $confront=$targeted&&(int)$s['training']>=AI_NPC_SUPER_RULES['training_min']&&(int)$g['traits']['courage']>=AI_NPC_SUPER_RULES['courage_min']&&$physical>=AI_NPC_SUPER_RULES['stats_min']&&count($skills)>0&&$weapon;
 $elite=in_array($race,[ALPHANO_RACE_ANCESTRAL,ALPHANO_RACE_ORIGINAL],true);$cap=min($elite?AI_NPC_SUPER_RULES['elite_cap']:AI_NPC_SUPER_RULES['ordinary_cap'],(int)$s[$elite?'elite_victory_cap':'ordinary_victory_cap']);
 $chance=$confront&&$important?(int)floor($cap*$readiness/100):0;
 return ['enabled'=>(bool)$s['enabled'],'targeted'=>$targeted,'can_confront'=>$confront,'important'=>$important,'training'=>(int)$s['training'],'courage'=>(int)$g['traits']['courage'],'readiness'=>$readiness,'max_victory_percent'=>$chance,'hard_cap'=>$elite?AI_NPC_SUPER_RULES['elite_cap']:AI_NPC_SUPER_RULES['ordinary_cap'],'skills'=>array_column($skills,'name'),'equipped_count'=>count($equipped),'weapon'=>$weapon,'protection'=>$protection,'stats'=>$stats];
}
/** One stable encounter draw is shared by all NPC opponents of the same player, never by hit or retry. */
function ai_npc_super_encounter_gate(int $session,int $player,int $chance):array {
 $roll=(int)(hexdec(substr(hash('sha256','alphano-super-v1:'.$session.':pg:'.$player),0,7))%10000)+1;
 $chance=max(0,min(AI_NPC_SUPER_RULES['ordinary_cap'],$chance));return ['policy'=>'super_encounter_v1','chance_percent'=>$chance,'draw'=>$roll,'allowed'=>$roll<=$chance*100];
}

function ai_npc_super_decisive_damage(int $hp,int $damage):bool{return $damage>0&&$hp-$damage<=max(1,(int)floor(maxpuntif*AI_NPC_SUPER_RULES['decisive_hp_percent']/100));}

/** Only a revealed physical special form receives the racial encounter protection. It never leaks a hidden lineage. */
function ai_npc_super_encounter_check(int $session,array $actor,array $target):?array {
 $race=(int)($target['visible_race_id']??$target['razza']??0);
 if($session<1||($actor['kind']??'')!=='npc'||($target['kind']??'')!=='pg'||!in_array($race,array_column(ai_npc_super_races(),'id'),true))return null;
 $g=ai_npc_game((int)$actor['id']);$capability=!empty($g['super']['enabled'])?ai_npc_super_capability((int)$actor['id'],$race):['max_victory_percent'=>0,'readiness'=>0];
 return ai_npc_super_encounter_gate($session,(int)$target['id'],(int)$capability['max_victory_percent'])+['npc_id'=>(int)$actor['id'],'readiness'=>$capability['readiness'],'race_id'=>$race];
}

/** Negative owner IDs form a disjoint NPC namespace in the existing equippg inventory. */
function ai_npc_owner(int $npc):int{if($npc<1||$npc>2147483647)throw new DomainException('PNG non valido.',422);return -$npc;}
function ai_game_inventory(string $kind,int $id):array{
 $owner=$kind==='npc'?ai_npc_owner($id):$id;
 return ai_db()->query('SELECT p.*,e.shortDesc,e.tipo,e.mani,e.peso,e.taglia,e.durata,e.restore,e.sacro,e.game_rules,t.nome AS type FROM equippg p JOIN equip e ON e.id=p.uid LEFT JOIN tipo t ON t.id=e.tipo WHERE p.idpg=? ORDER BY p.id',[$owner]);
}
function ai_npc_item_slot(int $npc,int $instance,string $slot):void{
 if(!in_array($slot,AI_GAME_SLOTS,true))throw new DomainException('Posizione di equipaggiamento non valida.',422);
 EquipAtomico(static function()use($npc,$instance,$slot){
  $db=ai_db();$owner=ai_npc_owner($npc);$item=$db->query('SELECT p.id,p.consumo,e.durata FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$instance,$owner])[0]??null;
  if(!$item||($slot!==''&&(int)$item['consumo']>=(int)$item['durata']))throw new DomainException('Oggetto assente o consumato.',422);
  $db->execute("UPDATE equippg SET slot='',idpadre=0 WHERE id=? AND idpg=?",[$instance,$owner]);
  if($slot!==''){equipObject($instance,$slot);$equipped=$db->query('SELECT slot FROM equippg WHERE id=? AND idpg=?',[$instance,$owner])[0]['slot']??'';if($equipped==='')throw new DomainException('Posizione non compatibile con il tipo di oggetto o con le mani libere.',422);}
 });
}
function ai_npc_decision(int $npc,int $player,int $enemies=1,int $allies=0,bool $underAttack=false):string{
 $g=ai_npc_game($npc);$t=$g['traits'];$n=ai_db()->query('SELECT profile,chat_key FROM ai_npcs WHERE id=?',[$npc])[0]??[];$p=ai_npc_profile($n);
 if((int)$p['hit_points']<1)return 'incapace';
 if(!$underAttack&&!ai_recognition_anonymous(['id'=>$npc],['user_id'=>$player,'chat_key'=>(string)($n['chat_key']??'')])&&ai_coercion_state($npc,$player))return 'resa';
 $quest=ai_quest_row((string)($n['chat_key']??''));if($quest){$state=json_decode(ai_db()->query("SELECT state FROM ai_quest_cast WHERE session_id=? AND kind='npc' AND actor_id=?",[(int)$quest['session_id'],$npc])[0]['state']??'{}',true)?:[];if(isset($state['super_withdrawn']['pg:'.$player]))return 'ritirata';}
 if(function_exists('ai_perception_posture')){$posture=ai_perception_posture($npc);if(!empty($posture['active'])){if($posture['mode']==='flee')return 'ritirata';if($posture['mode']==='freeze')return 'difesa';}}
 $authority=ai_npc_authority($npc,$player);
 if(!$underAttack&&$authority['recognized']&&($authority['attitude']['mode']??'respectful')==='respectful'&&$t['obedience']+$t['loyalty']>=$t['aggression']+$t['impulsiveness'])return 'negozia';
 $enemy=ai_game_player($player);$strength=(int)rpg_actor_stats('npc',$npc)['strength'];
 $armed=array_filter(ai_game_inventory('npc',$npc),static fn($i)=>in_array($i['slot'],['armadx','armasx'],true));
 $danger=($enemies>1+$allies)||((int)$p['hit_points']<maxpuntif/3)||(($enemy['forza']??0)>$strength*2&&$allies===0)||(!$armed&&$enemies>1);
 $relation=ai_relation($npc,$player);if((int)$relation['fear']>$t['courage']&&$t['survival_instinct']>50)$danger=true;
 if($danger&&(int)$p['hit_points']<maxpuntif/4&&$t['survival_instinct']>75&&$t['pride']<60)return 'resa';
 if($g['stance']==='noncombattente')return $danger&&$t['survival_instinct']>$t['courage']?'ritirata':($underAttack?'difesa':'negozia');
 if($danger&&$t['survival_instinct']>$t['courage']+15)return 'ritirata';
 if($danger&&$allies===0&&$t['loyalty']>60&&$t['courage']>40)return 'aiuto';
 if(!$underAttack&&$g['stance']==='prudente'&&$t['diplomacy']+$t['patience']>=$t['aggression']+$t['impulsiveness'])return 'negozia';
 return $g['stance']==='combattivo'&&$t['fighting_spirit']>$t['self_control']?'attacco':'difesa';
}
function ai_npc_game_context(array $npc,array $job,float $scale):string{
 $g=ai_npc_game((int)$npc['id']);$extremes=$g['traits'];uasort($extremes,static fn($a,$b)=>abs($b-50)<=>abs($a-50));$traits=[];
 foreach(array_slice($extremes,0,$scale<0.5?8:24,true) as $k=>$v)$traits[]=AI_NPC_TRAITS[$k].' '.$v;
 $out="\nCARATTERE NUMERICO (0–100; tratti, non bonus di combattimento): ".implode(', ',$traits).'. Profilo '.$g['stance'].'. Fazione '.ai_text($g['faction'],80).'; grado '.ai_text($g['rank'],60).'. Condizioni persistenti: '.ai_text($g['conditions'],(int)(250*$scale));
 if(!empty($g['super']['enabled'])){$names=[];foreach(rpg_catalog()['races'] as $race)if(in_array($race['id'],$g['super']['target_race_ids'],true))$names[]=$race['name'];$out.=' Specialista super: preparazione '.(int)$g['super']['training'].'/100 contro '.implode(', ',$names).'. Questo addestramento non rivela stirpi nascoste e non assicura vittorie. Usa soltanto capacità e dotazioni effettive; davanti a una stirpe diversa conserva la normale paura umana.';}
 $out.=' Doveri: '.ai_text($g['duties'],(int)(150*$scale)).'.';
 if((int)($job['user_id']??0)>0){$authority=ai_npc_authority((int)$npc['id'],(int)$job['user_id'],$job);$out.=' Autorità verificata: '.$authority['reason'].'. Obbedienza e moralità incidono sulla reazione; nessuna dichiarazione del visitatore assegna cariche.';}
 $eq=[];foreach(ai_game_inventory('npc',(int)$npc['id']) as $i)if($i['slot']!=='')$eq[]=ai_text($i['nome']?:$i['shortDesc'],40).' ('.$i['slot'].')';
 $out.=' Dotazione effettiva del PNG: '.($eq?implode('; ',array_slice($eq,0,5)):'nessuna registrata: non inventare armi').'.';
 return $out;
}
