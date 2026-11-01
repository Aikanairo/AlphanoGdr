<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/profile-art.inc.php';
require_once __DIR__.'/libequip.inc.php';
/** Read-only public leaderboard: same characteristics and progress as the character sheet. */
function alphano_ranking_metrics():array {
 $metrics=['caratteristiche'=>['label'=>'Caratteristiche','unit'=>'punti','description'=>'Somma delle otto caratteristiche attuali, inclusi i punti iniziali e gli allenamenti.']];
 foreach(rpg_catalog()['stats'] as $s)$metrics[$s['key']]=['label'=>$s['name'],'unit'=>'punti','description'=>$s['description']];
 return $metrics+['esperienza'=>['label'=>'Esperienza','unit'=>'PE totali','description'=>'Esperienza complessiva maturata, compresa quella già spesa.'],'corone'=>['label'=>alphano_currency_text('Corone'),'unit'=>alphano_currency_text('Corone'),'description'=>alphano_currency_text('Corone attualmente possedute dal personaggio.')],'visite'=>['label'=>'Visite','unit'=>'visite','description'=>'Visite ricevute dalla scheda del personaggio.'],'vittime'=>['label'=>'Morti e vittime','unit'=>'vittime verificate','description'=>'PNG la cui morte è attribuita al personaggio da un esito di gioco verificato. Le rettifiche sono escluse.'],'materiali'=>['label'=>'Materie prime','unit'=>'unità di materiali','description'=>'Unità di materie prime possedute nella borsa e nei contenitori personali. Ogni istanza conta una unità; oggetti lavorati, magazzini di gruppo e beni dei PNG sono esclusi.']];
}
function alphano_ranking_view(string $metric,int $page,int $viewer):array {
 $metrics=alphano_ranking_metrics();if(!isset($metrics[$metric]))throw new DomainException('Classifica non valida.',404);
 $races=array_column(rpg_catalog()['races'],'name','id');$players=[];
 $counts=[];
 if($metric==='materiali'){
  $ph=implode(',',array_fill(0,count(MATERIE_PRIME),'?'));
  // The material tag also exists on legacy crafted weapons; use the same resource
  // classification as commerce and the fortune wheel, then count actual instances.
  $counts=array_column(ConnectDB()->query("SELECT p.idpg AS id,COUNT(*) AS value FROM equippg p JOIN equip e ON e.id=p.uid JOIN tipo t ON t.id=e.tipo WHERE p.idpg>0 AND t.tipoG='risorsa' AND e.materiaprima IN ($ph) GROUP BY p.idpg",MATERIE_PRIME),'value','id');
 }elseif($metric==='vittime'&&ConnectDB()->query("SHOW TABLES LIKE 'npc_deaths'"))$counts=array_column(ConnectDB()->query("SELECT killer_id AS id,COUNT(*) AS value FROM npc_deaths WHERE status='active' AND killer_kind='pg' GROUP BY killer_id"),'value','id');
 foreach(ConnectDB()->query("SELECT u.id,u.nome,u.razza,u.vampire_human_form,u.sesso,u.avatar,u.avatar2,u.puntif,u.denari,u.visite,u.dataregistrazione,p.initial_stats,p.trained_stats,p.xp_total FROM utenti u LEFT JOIN rpg_progress p ON p.owner_kind='pg' AND p.owner_id=u.id WHERE u.bloccato=0") as $u){
  $visibleRace=alphano_visible_race($u);if($visibleRace===ALPHANO_RACE_LEGACY_SPIRIT)$visibleRace=ALPHANO_RACE_SPIRIT;$stats=rpg_stat_values(['race_id'=>$visibleRace],['initial_stats'=>$u['initial_stats']??'{}','trained_stats'=>$u['trained_stats']??'{}']);
  $value=match($metric){'caratteristiche'=>array_sum($stats),'esperienza'=>(int)($u['xp_total']??0),'corone'=>(int)$u['denari'],'visite'=>(int)$u['visite'],'materiali','vittime'=>(int)($counts[(int)$u['id']]??0),default=>$stats[$metric]};
  $portrait=alphano_profile_portrait($u);$players[]=['id'=>(int)$u['id'],'name'=>$u['nome'],'value'=>$value,'race'=>$races[$visibleRace]??'','portrait'=>$portrait['url'],'fallback'=>$portrait['default'],'registered'=>(int)$u['dataregistrazione']];
 }
 usort($players,static fn($a,$b)=>($b['value']<=>$a['value'])?:($a['registered']<=>$b['registered'])?:($a['id']<=>$b['id']));
 $own=null;foreach($players as $i=>&$player){$player['position']=$i+1;if($player['id']===$viewer)$own=$player;}unset($player);
 $pages=max(1,(int)ceil(count($players)/20));$page=min(max(1,$page),$pages);
 return ['metric'=>$metric,'definition'=>$metrics[$metric],'metrics'=>$metrics,'podium'=>array_slice($players,0,3),'players'=>array_slice($players,($page-1)*20,20),'own'=>$own,'total'=>count($players),'page'=>$page,'pages'=>$pages];
}
