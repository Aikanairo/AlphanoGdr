<?php
declare(strict_types=1);
require_once __DIR__.'/rpg.inc.php';

/** All opposed tests use the same administered characteristic weights. */
function rpg_combat_score(array $stats,string $test):int {
 $groups=['attack'=>['strength','dexterity','perception'],'defence'=>['dexterity','constitution','willpower'],'mental'=>['charisma','intelligence','willpower'],'resist'=>['willpower','wisdom']];
 if(!isset($groups[$test]))throw new LogicException('Prova di combattimento non riconosciuta.');
 $settings=rpg_settings();$score=0;foreach($groups[$test] as $key)$score+=(int)($stats[$key]??0)*(int)$settings[$test.'_'.$key];return (int)round($score/100);
}
function rpg_combat_has_weapon(string $kind,int $id):bool {
 foreach(ai_game_inventory($kind,$id) as $item)if(in_array($item['slot'],['armadx','armasx'],true)&&(int)$item['consumo']<(int)$item['durata'])return true;return false;
}
function rpg_combat_ability_intent(array $ability):string {
 return match($ability['effect']['type']){'attack'=>'attacco','defence'=>'difesa','mental'=>'potere','heal'=>'cura','perception'=>'osserva',default=>'azione'};
}
function rpg_combat_assert_equipment(string $kind,int $id,array $ability):void {
 $n=(int)($ability['rules']['number']??$ability['human_rules']['number']??0);$held=array_filter(ai_game_inventory($kind,$id),static fn($i)=>in_array($i['slot'],['armadx','armasx'],true)&&(int)$i['consumo']<(int)$i['durata']);
 if($n===2){foreach(ai_game_inventory($kind,$id) as $item)if(in_array($item['slot'],['scudo','armadx','armasx'],true)&&(int)$item['consumo']<(int)$item['durata'])return;}
 if(in_array($n,[1,5,7,8,9],true)){$valid=array_filter($held,static fn($i)=>in_array($n,[1,5],true)?rpg_ranged_family($i)==='':($n===9?rpg_ranged_family($i)==='firearm':rpg_ranged_family($i)!==''));if(!$valid)throw new DomainException('Manca l’arma realmente impugnata richiesta da questa tecnica.',422);$identity=rpg_identity($kind,$id);$allowed=array_filter($valid,static fn($i)=>rpg_weapon_lineage_reason($identity,$i)==='');if(!$allowed)throw new DomainException(rpg_weapon_lineage_reason($identity,reset($valid)),422);}
 if($n===10&&(count($held)>=2||array_filter($held,static fn($i)=>(int)$i['mani']>=2)))throw new DomainException('La presa richiede mani libere.',422);
 if(!empty($ability['effect']['requires_weapon'])&&!rpg_combat_has_weapon($kind,$id))throw new DomainException('Questa abilità richiede un’arma utilizzabile realmente impugnata.',422);
}

/** Named powers in action text use the same reservation as the selector, never a regex-only bonus. */
function rpg_combat_infer_ability(int $id,string $raw):int {
 if(!str_starts_with(trim($raw),'+')&&!ai_quest_intent($raw))return 0;
 if(preg_match('/^(?:\/\/|[$&#%@|])/u',ltrim($raw)))return 0;
 $reading=ai_narrative_parse($raw);$text=mb_strtolower($reading['action']);
 if($text===''||!$reading['intent'])return 0;
 $catalog=rpg_sheet('pg',$id,false)['abilities'];
 foreach($catalog as $a)if(preg_match('/(?<!\p{L})'.preg_quote(mb_strtolower($a['name']),'/').'(?!\p{L})/u',$text))return (int)$a['id'];
 if(preg_match('/\b(?:ipnosi|ipnotizz\p{L}*|dominazione|minore dominazione|controllo emotivo|soggiog\p{L}*)\b/u',$text))foreach($catalog as $a)if($a['key']==='minor_domination')return (int)$a['id'];
 return 0;
}
/** NPCs choose from actual available skills, never from powers invented by the model. */
function rpg_combat_choose_npc(int $id,int $sid,int $round,string $decision):?array {
 if(!in_array($decision,['attacco','difesa','ritirata','aiuto','allenamento'],true))return null;
 $sheet=rpg_sheet('npc',$id,false);$types=in_array($decision,['difesa','ritirata','aiuto'],true)?['defence','heal']:['attack'];$choices=[];
 foreach($sheet['abilities'] as $a){if(!$a['usable']||!in_array($a['effect']['type'],$types,true))continue;if($a['effect']['type']==='heal'&&$sheet['identity']['hit_points']>maxpuntif-30)continue;
  try{rpg_combat_assert_equipment('npc',$id,$a);}catch(DomainException){continue;}
  $last=ai_db()->query('SELECT round_number FROM rpg_ability_uses WHERE owner_kind=\'npc\' AND owner_id=? AND ability_id=? AND session_id=? ORDER BY id DESC LIMIT 1',[$id,$a['id'],$sid])[0]??null;
  if($last&&(int)$last['round_number']+(int)$a['cooldown']+(isset($a['rules'])?1:0)>$round)continue;
  $a['_score']=(int)$a['effect']['bonus']+(int)$a['effect']['damage']/5+(int)$a['effect']['heal']/5;$choices[]=$a;
 }
 usort($choices,static fn($a,$b)=>$b['_score']<=>$a['_score']);
 // Selection is read-only. A cancelled inference must never consume a cooldown.
 return $choices[0]??null;
}
function rpg_combat_settings():array {
 $rules=require __DIR__.'/../config/quest-rules.php';foreach(rpg_settings() as $key=>$value)if(str_starts_with($key,'scene_')&&array_key_exists(substr($key,6),$rules))$rules[substr($key,6)]=$value;return $rules;
}
function rpg_combat_context(array $sheet,float $scale=1,string $action=''):string {
 if($scale<=.5)return rpg_compact_skill_context($sheet,$action,$scale);
 $parts=[];foreach($sheet['stats']??[] as $key=>$value)$parts[]=rpg_stat_name($key).' '.(int)$value;
 $facts=rpg_ai_skill_facts($sheet,$action);$lineage=$facts['lineage'];
 $out="\nCARATTERISTICHE RPG VERIFICATE: ".implode(', ',$parts).'. Stirpe tecnica: '.$lineage['native_name'].'; forma attuale: '.$lineage['form_name'].'. Le identità nascoste non sono conoscenza del PNG. Solo il backend calcola esiti, danni, cure e recuperi.';
 $usable=[];$locked=[];$automatic=[];foreach($facts['owned'] as $a){if($a['usable'])$usable[]=$a['name'];elseif($a['automatic'])$automatic[]=$a['name'];else $locked[]=$a['name'];}
 $out.="\nINTERO REPERTORIO UTILIZZABILE: ".($usable?implode('; ',$usable):'nessuna capacità attiva').'. Non acquisire altri poteri scrivendoli in chat.';
 if($automatic)$out.=' Resistenze automatiche del backend: '.implode('; ',$automatic).'.';
 if($locked)$out.=' Possedute ma NON utilizzabili: '.implode('; ',$locked).'.';
 if(in_array((int)$sheet['identity']['race_id'],[3,6],true))$out.=' Controllo cosciente: '.($lineage['sentient']?'sì':'no, Puro').' · linguaggio umano: '.($lineage['loses_human_language']?'assente':'conservato').'.';
 if($lineage['native_race_id']===6)$out.=' Il Rifiuto della Tecnologia vieta le armi da fuoco anche in forma umana; il Vincolo alla Verità impedisce menzogne su identità, origine e missione.';
 $out.=' Eredità: Originale ← Vampiro; Ancestrale ← Lycan. Nessun passaggio inverso o fra stirpi. Le tecniche umane non annullano malus, forma, equipaggiamento, recuperi o senzienza; bonus dello stesso tipo non si sommano.';
 foreach(array_slice($facts['relevant'],0,$scale<.5?1:2) as $a){$rule=$a['human_rules']??[];$effect=$a['effect'];$out.="\nCAPACITÀ PERTINENTE ".$a['name'].': '.($rule?('attivazione '.$rule['probability'].'%, preparazione '.$rule['activation'].' turno/i, durata '.$rule['duration'].' turno/i; '.$rule['bonus_text'].' '.$rule['requirement_text']):mb_substr(preg_replace('/\s+/u',' ',$a['description']),0,380)).'; recupero '.$a['cooldown'].' giri; effetto backend '.$effect['type'].', bonus '.(int)($effect['bonus']??0).', danno '.(int)($effect['damage']??0).', cura '.(int)($effect['heal']??0).'. Un effetto narrativo non produce automaticamente variazioni numeriche.';}
 $out.=rpg_malus_context($sheet);return $out;
}
function rpg_combat_badge(array $ability):string {
 return '<span class="chat-ability-tag" data-rpg-ability="'.(int)$ability['id'].'"><span aria-hidden="true">✦</span> '.htmlspecialchars($ability['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>';
}
/** Completion rewards share the current quest transaction and have a durable receipt. */
function rpg_combat_quest_award(int $sid,int $uid,int $rounds,int $actions):int {
 $cfg=rpg_settings();if(!$cfg['quest_xp_enabled']||$rounds<$cfg['quest_xp_min_rounds']||$actions<$cfg['quest_xp_min_actions'])return 0;
 // The actor lock also serializes the daily cap across different completed quests.
 rpg_identity('pg',$uid,true);rpg_progress('pg',$uid,true);$key='quest:'.$sid.':completion';
 $old=rpg_receipt('pg',$uid,$key,'grant');if($old)return (int)$old['amount'];
 $daily=(int)getDB()->query("SELECT COALESCE(SUM(amount),0) n FROM rpg_ledger WHERE owner_kind='pg' AND owner_id=? AND entry_type='grant' AND actor_id=0 AND event_key LIKE 'quest:%:completion' AND created_at>?",[$uid,time()-86400])[0]['n'];
 $amount=max(0,min((int)$cfg['quest_xp_max'],(int)$cfg['quest_xp_base']+$rounds*(int)$cfg['quest_xp_per_round'],(int)$cfg['quest_xp_daily_cap']-$daily));
 if($amount)rpg_grant_xp('pg',$uid,$amount,'Partecipazione alla quest #'.$sid.' · '.$rounds.' giri risolti',$key);return $amount;
}

/** Ranged families and ammunition are shared by both quest resolution paths. */
function rpg_ranged_family(array $item):string {
 $label=(string)($item['type']??'');
 if(preg_match('/fucil|pistol|moschett|carabin|trombon|archibug/iu',$label))return 'firearm';
 return preg_match('/balestr|^arco/iu',$label)?'bow':'';
}
function rpg_ranged_ammo(array $ammo,array $weapon):bool {
 $label=(string)($ammo['type']??'');
 return rpg_ranged_family($weapon)==='firearm'?(bool)preg_match('/proiettiliarmafuoco|munizion/iu',$label):(rpg_ranged_family($weapon)==='bow'&&(bool)preg_match('/dard|frecc|quadrell/iu',$label));
}
function rpg_declared_weapon(array $items,string $text,bool $rangedOnly=false):?array {
 $family=preg_match('/fucil|pistol|moschett|carabin|trombon|archibug|\bspar\p{L}*/iu',$text)?'firearm':(preg_match('/balestr|\barco\b|frecci|dard/iu',$text)?'bow':'');
 foreach($items as $item)if(in_array($item['slot'],['armadx','armasx'],true)&&(int)$item['consumo']<(int)$item['durata']&&(!$rangedOnly||rpg_ranged_family($item)!=='')&&($family===''||rpg_ranged_family($item)===$family))return $item;
 return null;
}

function rpg_activation_public_html(array $ability,string $actor):string {
 $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$r=$ability['human_rules'];return '<div class="MasterKey"><section class="human-activation'.($ability['activated']?'':' is-failed').'"><img src="'.$h($ability['image']).'" alt="" width="45" height="45"><strong>'.$h($actor).' · '.$h($ability['name']).'</strong><span>Attivazione '.$ability['activation_roll'].' / 100 · soglia '.$ability['activation_chance'].'% · '.($ability['activated']?'riuscita':'fallita').'.</span><p>'.($ability['activated']?$h($r['bonus_text'].' Richiede: '.$r['requirement_text'].' '.$r['mechanic']):'Nessun bonus attivato. Il recupero si applica anche al tentativo fallito.').'</p><small>Attivazione: '.$r['activation'].' turno/i · durata: '.$r['duration'].' turno/i. Gli effetti finali richiedono le normali prove e condizioni.</small></section></div>';
}

/** The ancestral refusal of firearms also applies while wearing human shape. */
function rpg_weapon_lineage_reason(array $identity,?array $weapon):string {
 return $weapon&&rpg_lineage_id($identity)===6&&rpg_ranged_family($weapon)==='firearm'?'Il Rifiuto della Tecnologia dell’Ancestrale impedisce di usare o ricaricare armi da fuoco, anche in forma umana.':'';
}

/** Central attachment covers ordinary, compact, quest and transformation-reaction prompts. */
function rpg_prompt_context(array $npc,array $job,float $scale):string {
 $out="\nREGIA RPG RISERVATA, ignota al PNG. Non rivelare stirpi, punteggi o poteri segreti. Esiti backend; bonus non cumulabili; niente poteri estranei. Originale←Vampiro; Ancestrale←Lycan.";
 $action=(string)($job['prompt']??'');$out.="\nTU, PNG: ".rpg_combat_context(rpg_sheet('npc',(int)$npc['id'],false),$scale,$action);
 if((int)($job['user_id']??0)>0)$out.="\nINTERLOCUTORE, PG: ".rpg_combat_context(rpg_sheet('pg',(int)$job['user_id'],false),$scale,$action);
 return $out;
}
function rpg_compact_skill_context(array $sheet,string $action,float $scale):string {
 $facts=rpg_ai_skill_facts($sheet,$action);$l=$facts['lineage'];$identity=$sheet['identity'];
 $stats=[];foreach($sheet['stats'] as $key=>$value)$stats[]=rpg_stat_name($key).' '.$value;
 $usable=[];$locked=[];$automatic=[];foreach($facts['owned'] as $a){if($a['usable'])$usable[]=$a['name'];elseif($a['automatic'])$automatic[]=$a['name'];else $locked[]=$a['name'];}
 $out='Stirpe tecnica '.$l['native_name'].'; forma '.$l['form_name'].'. Dati reali: '.implode(', ',$stats).'.';
 $out.=' REPERTORIO UTILIZZABILE: '.($usable?implode('; ',$usable):'nessuno').'.';
 if($locked){$mentioned=array_values(array_filter($locked,static fn($name)=>mb_stripos($action,$name)!==false));$out.=' '.count($locked).' altre capacità BLOCCATE nella forma attuale'.($mentioned?': '.implode('; ',$mentioned):'').'.';}if($automatic)$out.=' Resistenze automatiche: '.implode('; ',$automatic).'.';
 if(in_array($identity['race_id'],[3,6],true))$out.=' Controllo '.($l['sentient']?'cosciente':'istintivo, niente tecniche umane deliberate').'; linguaggio umano '.($l['loses_human_language']?'assente':'conservato').'.';
 if($l['native_race_id']===6)$out.=' Ancestrale: niente armi da fuoco anche in forma umana; verità su identità, origine e missione.';
 foreach(array_slice($facts['relevant'],0,1) as $a){if($scale<.5&&mb_stripos($action,$a['name'])===false)continue;$e=$a['effect'];$h=$a['human_rules']??[];$out.=' Capacità pertinente '.$a['name'].': '.($h?($h['probability'].'%, preparazione '.$h['activation'].' turno/i, durata '.$h['duration'].'; '.$h['bonus_text']):mb_substr(preg_replace('/\s+/u',' ',$a['description']),0,$scale<.5?130:210)).'; backend '.$e['type'].' bonus '.(int)($e['bonus']??0).', danno '.(int)($e['damage']??0).', cura '.(int)($e['heal']??0).'; recupero '.$a['cooldown'].'.';}
 $out.=rpg_compact_malus_context($sheet);return $out;
}

function rpg_compact_malus_context(array $sheet):string {
 $m=$sheet['malus'];$state=array_filter($m['state'],static fn($v,$k)=>!empty($v)&&!str_starts_with((string)$k,'_'),ARRAY_FILTER_USE_BOTH);
 $limits=match($m['family']){'vampire'=>'sole diretto, distruzione fisica e oggetti sacri verificati; nessuna immunità inventata','lycan'=>'argento: ferite persistenti e blocco della rigenerazione','human'=>'mortale, condizioni accertate dal backend',default=>'vincoli della propria razza; nessuna azione fisica per le forme incorporee'};
 $out=' Vulnerabilità: '.$limits.'.';if($m['physical']['penalty']||$m['mental']['penalty'])$out.=' Penalità fisica '.$m['physical']['penalty'].', mentale '.$m['mental']['penalty'].'.';
 if($state)$out.=' Stato: '.rpg_json($state).'.';if($m['command'])$out.=' Comando in vigore: '.mb_substr($m['command']['command']??rpg_json($m['command']),0,200).'.';if($m['corruption'])$out.=' Concessione corruttiva attiva e proporzionata, senza obbedienza assoluta.';
 return $out;
}
