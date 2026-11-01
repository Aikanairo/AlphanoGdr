<?php
/* Render actual values with the page; JavaScript adds training and live updates. */
require_once __DIR__.'/../../libs/rpg.inc.php';
$progressKind=rpg_kind((string)($sheet['rpg_kind']??(isset($sheet['npc_id'])?'npc':'pg')));
$progressId=(int)($sheet['rpg_id']??$sheet['npc_id']??0);
$progressIdentity=rpg_identity($progressKind,$progressId);
$progressState=rpg_progress($progressKind,$progressId);
$progressStats=rpg_characteristics($progressIdentity,$progressState);
$progressSex=($sheet['design']['sex']??'Maschio')==='Femmina'?'female':'male';
$progressSeal=$sheet['seal']??alphano_profile_race_seal($progressIdentity['race_id'],$progressSex==='female'?'Femmina':'Maschio');
$progressSealLabel=$sheet['seal_label']??rpg_race($progressIdentity['race_id'])['name'];
$progressEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
 <header class="progression-summary">
  <div class="progression-overview"><h2>Caratteristiche e abilità</h2><div class="progression-xp" data-rpg-xp aria-label="Bilancio dell’esperienza"><?php foreach(['PE disponibili'=>$progressState['xp_total']-$progressState['xp_spent'],'Totali'=>$progressState['xp_total'],'Spesi'=>$progressState['xp_spent']] as $label=>$value): ?><span class="progression-xp-item"><small><?=$progressEscape($label)?></small><strong><?=number_format($value,0,',','.')?></strong></span><?php endforeach; ?></div></div>
  <p class="rpg-feedback" data-rpg-feedback role="status" aria-live="polite"></p>
  <div class="progression-stats characteristic-constellation" data-rpg-stats data-stat-render="server" data-sex="<?=$progressSex?>" aria-label="Le otto caratteristiche">
   <div class="constellation-heart"><img class="constellation-seal" src="<?=$progressEscape($progressSeal)?>" alt="<?=$progressEscape($progressSealLabel)?>" width="160" height="160"><span class="constellation-ornament">✧</span></div>
   <?php foreach($progressStats as $index=>$stat): ?><article class="constellation-attribute <?=$index%2?'is-right':'is-left'?>" data-rpg-stat-key="<?=$progressEscape($stat['key'])?>" title="<?=$progressEscape($stat['description'].' · Base '.$stat['base'].' · Iniziali '.$stat['initial'].' · Allenati '.$stat['trained'])?>"><span class="constellation-sigil" aria-hidden="true"><?php if(preg_match('~^/uploads/statistics/[a-z0-9_-]+\.(svg|png|webp)$~D',$stat['icon']??'')): ?><img src="<?=$progressEscape($stat['icon'])?>" alt=""><?php else: ?><span data-icon="<?=$progressEscape($stat['icon']?:'stat-'.$stat['key'])?>"></span><?php endif; ?></span><div class="constellation-text"><h3 class="constellation-label"><?=$progressEscape($stat['name'])?></h3><strong class="constellation-value"><?=(int)$stat['value']?></strong><meter class="constellation-track" min="0" max="100" value="<?=(int)$stat['value']?>" aria-label="<?=$progressEscape($stat['name'].': '.$stat['value'].' su 100')?>"></meter></div></article><?php endforeach; ?>
  </div>
  <div class="progression-initial" data-rpg-initial hidden><span data-icon="sparkles" aria-hidden="true"></span><p><strong>Completa il personaggio</strong><span>Scegli le innate e distribuisci personalmente 20 punti</span></p><div data-rpg-initial-preview hidden></div><button type="button" data-rpg-initial-claim>Scegli e distribuisci</button></div>
 </header>
