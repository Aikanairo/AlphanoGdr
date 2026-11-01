<?php
declare(strict_types=1);
/** One authority for acquisition, inherited ownership and current-form use. */
function rpg_lineage_policy():array {static $v;return $v??=json_decode(file_get_contents(__DIR__.'/../config/ability-lineages.json'),true,64,JSON_THROW_ON_ERROR);}
function rpg_ability_origin(array $a):array {
 // Authored powers cannot be opened to a foreign lineage by widening race_ids.
 static $origins;if($origins===null){$origins=[];foreach(['rpg-seed.json','racial-abilities-20261026.json'] as $file)foreach(json_decode(file_get_contents(__DIR__.'/../config/'.$file),true)['abilities'] as $entry)$origins[$entry['key']]=$entry['race_ids'];}
 $key=$a['key']??$a['ability_key']??'';if(rpg_human_rule($key))return [alphano_human_race_id()];
 return array_map('intval',$origins[$key]??$a['race_ids']);
}
function rpg_lineage_id(array $identity):int {return (int)($identity['native_race_id']??$identity['lineage_race_id']??$identity['race_id']);}
function rpg_lineage_contains(int $race,array $origins):bool {
 if(in_array($race,$origins,true))return true;$parent=(int)(rpg_lineage_policy()['parents'][$race]??0);return $parent>0&&in_array($parent,$origins,true);
}
function rpg_heritage_reason(array $a,int $lineage):string {
 $r=rpg_human_rule($a['key']);if(!$r)return 'Questa capacità non appartiene al patrimonio umano trasferibile.';
 if(!$r['initial']&&!in_array($r['number'],rpg_lineage_policy()['human_heritage_learnable'],true))return 'Capacità riservata agli Umani, esclusa dal patrimonio trasferibile.';
 if(in_array($r['number'],rpg_lineage_policy()['human_only'],true))return 'Resistenza propria della condizione umana: non trasferibile alle stirpi speciali.';
 if($lineage===6&&isset(rpg_lineage_policy()['ancestral_excluded'][$r['number']]))return rpg_lineage_policy()['ancestral_excluded'][$r['number']];
 return '';
}
function rpg_ability_lineage_reason(array $a,array $identity):string {
 $native=rpg_lineage_id($identity);if(str_starts_with($a['key'],'org_')){if(empty($a['organization']))return 'Disciplina organizzativa non pubblicata dall’Accademia.';if(!$a['organization']['enabled'])return 'Organizzazione sospesa.';return rpg_lineage_contains($native,array_map('intval',$a['race_ids']))?'':'La razza effettiva non è compatibile con questa disciplina organizzativa.';}$origins=rpg_ability_origin($a);$human=alphano_human_race_id();$configured=array_map('intval',$a['race_ids']);
 if($origins===[$human]&&$native!==$human&&in_array($native,rpg_lineage_policy()['human_heritage_races'],true)){
  if(!in_array($human,$configured,true))return 'Il catalogo ha sospeso la competenza umana.';return rpg_heritage_reason($a,$native);
 }
 if(!rpg_lineage_contains($native,$origins))return 'Riservata alla stirpe di origine e ai suoi ranghi superiori: la forma umana non cambia la stirpe.';
 if(!rpg_lineage_contains($native,$configured))return 'Questa capacità non è abilitata per la tua stirpe nel catalogo.';
 return '';
}
function rpg_ability_form_reason(array $a,array $identity):string {
 $reason=rpg_ability_lineage_reason($a,$identity);if($reason!=='')return $reason;if(isset($a['organization']))return rpg_org_form_reason($a,$identity);
 $native=rpg_lineage_id($identity);$visible=(int)$identity['race_id'];$human=alphano_human_race_id();$origins=rpg_ability_origin($a);$rule=rpg_human_rule($a['key']);
 if($origins!==[$human]&&in_array($native,[2,3,5,6],true)&&$visible!==$native)return 'Richiede la forma speciale della tua stirpe; l’abilità resta posseduta.';
 if(!$rule||!in_array($visible,[3,6],true))return '';
 if($visible===3&&empty($identity['sentient']))return 'La forma lupina Pura non può eseguire tecniche umane deliberate; torna umano o acquisisci Senzienza.';
 if(in_array($rule['number'],rpg_lineage_policy()['human_speech'],true))return alphano_race_loses_human_language($visible)?'La forma attuale non conserva il linguaggio umano.':'Questa tecnica sociale richiede la forma umana.';
 if(!in_array($rule['number'],rpg_lineage_policy()['lycan_controlled_body'],true))return 'Richiede mani, strumenti o postura della forma umana; la conoscenza è conservata.';
 return '';
}
function rpg_ability_access(array $a,array $identity):array {
 if(isset($a['organization'])){$o=$a['organization'];return ['origins'=>$a['race_ids'],'heritage'=>false,'inherited'=>false,'learning_race_id'=>rpg_lineage_id($identity),'label'=>'Disciplina · '.$o['name'],'note'=>'Riservata ai membri attuali, carica di livello almeno '.$o['minimum_rank'].'. Forma: '.['human'=>'umana','native'=>'speciale','both'=>'entrambe'][$o['form']].'. Revisione approvata '.$o['revision'].'.'];}
 $human=alphano_human_race_id();$native=rpg_lineage_id($identity);$origins=rpg_ability_origin($a);$heritage=$origins===[$human]&&in_array($native,rpg_lineage_policy()['human_heritage_races'],true);
 return ['origins'=>$origins,'heritage'=>$heritage,'inherited'=>!in_array($native,$origins,true)&&rpg_lineage_contains($native,$origins),'learning_race_id'=>$heritage?$human:$native,
 'label'=>$heritage?'Patrimonio umano':(in_array($native,$origins,true)?'Capacità della propria stirpe':(rpg_lineage_contains($native,$origins)?'Eredità della stirpe inferiore':'Capacità incompatibile con la stirpe')),
 'note'=>$heritage?'Si apprende con la base umana e i progressi personali; costo, attivazione e recupero restano quelli del catalogo.':'La stirpe determina l’accesso, la forma attuale determina l’uso.'];
}
function rpg_lineage_sheet(array $identity):array {
 $native=rpg_lineage_id($identity);$human=alphano_human_race_id();return ['version'=>rpg_lineage_policy()['version'],'native_race_id'=>$native,'native_name'=>rpg_race($native)['name'],'form_name'=>rpg_race((int)$identity['race_id'])['name'],'sentient'=>(bool)$identity['sentient'],'loses_human_language'=>alphano_race_loses_human_language((int)$identity['race_id']),
 'heritage'=>in_array($native,rpg_lineage_policy()['human_heritage_races'],true),'parent_race_id'=>rpg_lineage_policy()['parents'][$native]??null,'balance'=>rpg_lineage_policy()['balance'],
 'summary'=>$native===$human?'Quattro innate iniziali e tecniche umane. I poteri delle stirpi speciali non sono apprendibili.':(in_array($native,rpg_lineage_policy()['human_heritage_races'],true)?'Conoscenze e PE restano del personaggio. Le capacità si apprendono soltanto dalla propria stirpe, dai suoi ranghi inferiori e dal patrimonio umano compatibile.':'Sono disponibili soltanto le capacità della propria razza, con i limiti della forma attuale.')];
}

/** Catalogue editing may tune mechanics, but cannot mix or reverse authored bloodlines. */
function rpg_validate_lineage_assignment(string $key,array $races):void {
 $origins=rpg_ability_origin(['key'=>$key,'race_ids'=>$races]);$human=alphano_human_race_id();
 $families=[];foreach($origins as $race)$families[]=match($race){2,5=>'vampire',3,6=>'lycan',default=>'race:'.$race};
 if(count(array_unique($families))>1)throw new DomainException('Un’abilità non può unire stirpi diverse. Configura una competenza per ciascuna stirpe.',422);
 foreach($races as $race)if(!rpg_lineage_contains($race,$origins))throw new DomainException('Non puoi assegnare un potere a una stirpe estranea o a un rango inferiore. Il patrimonio umano viene condiviso automaticamente secondo le sue regole.',422);
}
/** The engine sees every owned skill. Prompt detail is selected by actual declared intent. */
function rpg_ai_skill_facts(array $sheet,string $action=''):array {
 $roster=[];$details=[];$fold=mb_strtolower($action);$words=preg_split('/[^\p{L}\p{N}]+/u',$fold,-1,PREG_SPLIT_NO_EMPTY);
 foreach($sheet['abilities']??[] as $a){if(!$a['owned'])continue;
  $roster[]=['id'=>$a['id'],'name'=>$a['name'],'usable'=>$a['usable'],'automatic'=>(bool)($a['automatic']??false),'reason'=>$a['usable']?'':$a['reason']];
  $score=mb_stripos($action,$a['name'])!==false&&$action!==''?100:0;$text=mb_strtolower($a['name'].' '.$a['description']);foreach($words as $w)if(mb_strlen($w)>4&&str_contains($text,$w))$score++;
  if(!$a['usable'])continue;$detail=['id'=>$a['id'],'name'=>$a['name'],'effect'=>$a['effect'],'cooldown'=>$a['cooldown'],'requirements'=>$a['requirements'],'description'=>$a['description']];
  if(isset($a['rules']))$detail['human_rules']=array_intersect_key($a['rules'],array_flip(['probability','activation','duration','bonus_text','requirement_text','mechanic']));
  $details[]=['score'=>$score,'detail'=>$detail];
 }
 usort($details,static fn($a,$b)=>$b['score']<=>$a['score']);
 return ['policy'=>rpg_lineage_policy()['version'],'lineage'=>$sheet['lineage']??rpg_lineage_sheet($sheet['identity']),'stats'=>$sheet['stats'],'conditions'=>array_intersect_key($sheet['malus']??[],array_flip(['state','command','corruption'])),'owned'=>$roster,'relevant'=>array_column(array_slice($details,0,$action===''?0:3),'detail'),
 'authority'=>'Solo capacità possedute e utilizzabili nella forma attuale. Il testo descrittivo non crea bonus tecnici: applica soltanto effetti, tiri e conseguenze convalidati dal backend. Le discipline narrative richiedono valutazione del Master; nessun danno o potere aggiuntivo inventato.'];
}
