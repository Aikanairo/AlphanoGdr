<?php
declare(strict_types=1);
function rpg_org_ready():bool {static $v;return $v??=(bool)ConnectDB()->query("SHOW TABLES LIKE 'org_ability_publications'");}
function rpg_org_publications():array {
 if(!rpg_org_ready())return [];$out=[];
 foreach(ConnectDB()->query('SELECT p.*,o.nome,o.approvato FROM org_ability_publications p JOIN organizzazioni o ON o.id=p.org_id') as $r){$s=rpg_decode($r['spec']);$out[(int)$r['ability_id']]=['id'=>(int)$r['org_id'],'name'=>$r['nome'],'enabled'=>(bool)$r['approvato'],'minimum_rank'=>(int)$s['minimum_rank'],'form'=>$s['form'],'revision'=>(int)$r['revision']];}return $out;
}
function rpg_org_membership_reason(array $a,array $identity):string {
 $org=$a['organization']??null;if(!$org)return '';
 if(($identity['kind']??'')!=='pg'||(int)($identity['organization_id']??0)!==$org['id'])return 'Richiede l’appartenenza attuale a '.$org['name'].'. La capacità appresa resta conservata.';
 if((int)($identity['organization_rank_level']??0)<$org['minimum_rank'])return 'Richiede una carica di livello almeno '.$org['minimum_rank'].' in '.$org['name'].'.';return '';
}
function rpg_org_form_reason(array $a,array $identity):string {
 $org=$a['organization'];$native=rpg_lineage_id($identity);$visible=(int)$identity['race_id'];$human=alphano_human_race_id();
 if($org['form']==='human'&&$visible!==$human)return 'La disciplina dell’organizzazione richiede la forma umana.';
 if($org['form']==='native'&&$visible===$human)return 'La disciplina richiede la forma speciale indicata nella proposta approvata.';
 if($visible===3&&empty($identity['sentient']))return 'Il Lycan Puro non esegue discipline organizzative deliberate.';
 if(in_array($visible,[3,6],true)&&!empty($a['effect']['requires_weapon']))return 'La tecnica armata dell’organizzazione richiede mani e postura umane.';
 return '';
}
