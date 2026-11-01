<?php /** Public context stays directly below the six identity medallions. */ ?>
<div class="rpg-profile-context" aria-label="Luogo e arrivo a Alphano">
 <?php if($d['location']): ?><p class="rpg-context-location"><img src="<?=$h(alphano_profile_symbol('place'))?>" alt="" width="28" height="28"><span><small>Luogo</small><strong><?=$h($d['location'])?></strong></span></p><?php endif; ?>
 <?php if(!empty($sheet['registration']['registered_at'])): ?><p class="rpg-registration"><img src="<?=$h(alphano_profile_symbol('arrival'))?>" alt="" width="28" height="28"><span><small>In queste terre dal</small><time datetime="<?=date('c',$sheet['registration']['registered_at'])?>"><?=$h(date('d/m/Y',$sheet['registration']['registered_at']))?></time></span></p><?php if($sheet['registration']['is_new']): ?><span class="rpg-welcome-badge"><span aria-hidden="true">✦</span> Nuovo player · prime 48 ore</span><?php endif; ?><?php endif; ?>
</div>
