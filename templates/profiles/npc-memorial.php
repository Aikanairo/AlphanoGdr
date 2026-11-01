<?php
$h=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$death=$death??['npc_name'=>$npc['name'],'portrait'=>$profile['portrait']?:'/assets/placeholder.svg','death_at'=>0,'place_name'=>$npc['title'],'circumstance'=>'Decesso registrato. Le circostanze non sono documentate.','story'=>alphano_npc_mortality_plain((string)$profile['biography'],2500),'killer_kind'=>'unknown','killer_id'=>0,'killer_name'=>''];
$day=(int)$death['death_at']>0?date('d/m/Y',(int)$death['death_at']):'Data non documentata';
$canCorrect=!empty($viewer['admin'])&&!empty($death['id'])&&str_starts_with((string)($death['source_key']??''),'admin:'.$npc['id'].':')&&($death['effect_key']??'')==='';
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?=$h(alphano_csrf_token())?>"><title>In memoria di <?=$h($death['npc_name'])?></title><link rel="stylesheet" href="/css/theme/theme.css?v=<?=filemtime(dirname(__DIR__,2).'/css/theme/theme.css')?>"><?php if($canCorrect): ?><script src="/js/npc-memorial.js" defer></script><?php endif; ?></head>
<body class="ui-page"><main class="npc-memorial" aria-label="Memoriale di <?=$h($death['npc_name'])?>">
 <figure class="npc-memorial-stone">
  <img class="npc-memorial-background" src="/assets/placeholder.svg" alt="Lapide gotica illuminata da candele">
  <img class="npc-memorial-portrait" src="<?=$h($death['portrait'])?>" alt="Ritratto di <?=$h($death['npc_name'])?>">
  <figcaption><span>In memoria di</span><strong><?=$h($death['npc_name'])?></strong><time<?=$death['death_at']?' datetime="'.date('c',(int)$death['death_at']).'"':''?>><?=$h($day)?></time><small><?=$h($death['place_name'])?></small></figcaption>
 </figure>
 <article class="npc-memorial-record"><span class="rpg-eyebrow">Alphano · Archivio dei caduti</span><h1><?=$h($death['npc_name'])?></h1><p class="npc-memorial-status">Personaggio deceduto · memoriale permanente</p>
  <section><h2>La sua storia</h2><p><?=nl2br($h($death['story']?:'La sua presenza e gli incontri vissuti restano custoditi nelle cronache del gioco.'))?></p></section>
  <section><h2>L’ultimo capitolo</h2><dl><div><dt>Data</dt><dd><?=$h($day)?><?php if($death['death_at']): ?> · <?=$h(date('H:i',(int)$death['death_at']))?><?php endif; ?></dd></div><div><dt>Luogo</dt><dd><?=$h($death['place_name'])?></dd></div></dl><p><?=nl2br($h($death['circumstance']))?></p>
   <?php if(in_array($death['killer_kind'],['pg','npc'],true)&&(int)$death['killer_id']>0): ?><p>Responsabilità accertata: <a href="<?=$death['killer_kind']==='pg'?'/profili/?Id=':'/profili/png.php?Id='?><?=(int)$death['killer_id']?>"><?=$h($death['killer_name'])?></a>.</p><?php else: ?><p class="muted">Responsabilità non accertata. Il registro non attribuisce questa morte a un giocatore.</p><?php endif; ?>
  </section>
  <p class="npc-memorial-notice">Questo personaggio non partecipa più alle scene. Il suo profilo, la storia e i legami rimangono nell’archivio.</p>
  <nav aria-label="Navigazione del memoriale"><a class="button" href="/chat/index.php?chat=<?=rawurlencode($npc['chat_key'])?>">Il luogo della sua storia</a><?php if(!empty($viewer['admin'])): ?><a class="button" href="/admin/master-ia.php?chat=<?=rawurlencode($npc['chat_key'])?>&amp;npc=<?=(int)$npc['id']?>">Archivio amministrativo</a><?php endif; ?></nav>
  <?php if($canCorrect): ?><details class="npc-memorial-correction"><summary>Rettifica una registrazione amministrativa errata</summary><p>Questa morte è stata registrata dal pannello amministrativo. Indica i punti ferita corretti e una motivazione: la rettifica rimarrà nel registro. Il PNG resterà inattivo finché non lo riattiverai dal suo pannello.</p><form data-npc-death-correction data-chat="<?=$h($npc['chat_key'])?>" data-npc="<?=(int)$npc['id']?>" data-death="<?=(int)$death['id']?>"><label>Punti ferita da ripristinare <input name="hit_points" type="number" min="1" max="<?=maxpuntif?>" step="1" required placeholder="Da 1 a <?=maxpuntif?>"></label><label>Motivo della rettifica <textarea name="reason" maxlength="1000" rows="3" required></textarea></label><label class="npc-memorial-confirmation"><input name="confirmed" type="checkbox" required> Confermo che si tratta di un errore amministrativo e voglio rettificare questa registrazione.</label><button type="submit" class="button">Registra la rettifica</button><p role="status" aria-live="polite" data-correction-feedback></p></form></details><?php endif; ?>
 </article>
</main></body></html>
