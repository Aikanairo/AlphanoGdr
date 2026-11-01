<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__.'/../libs/ai.inc.php';
require_once __DIR__.'/../libs/chat-presentation.inc.php';
require_once __DIR__.'/../libs/chat-controls.inc.php';
$aiScene=$isChat&&!$privateChat?ai_room($chat):null;
$sceneNpcs=$isChat?ai_dialogue_npcs($chat,$idpg):[];
?><!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Configurazione client chat -->
  <script>
    window.chatRoom = <?= json_encode($chat) ?>;
    window.myPgId   = <?= json_encode($idpg) ?>;
    window.chatToken = <?= json_encode(alphano_csrf_token()) ?>;
  </script>

  <title>
    <?= htmlspecialchars($title, ENT_QUOTES) ?>
    <?= $isChat ? ' – GDR Chat' : '' ?>
  </title>

  <!-- CSS principale -->

  <!-- JS client -->
  <script src="/chat/js/chat_http.js?v=<?= filemtime(__DIR__ . '/js/chat_http.js') ?>"></script>
  <script src="/js/global.js"></script>

  <script src="/js/chats.js"></script>
  <?php if($isChat): ?><script src="/js/chat-composer.js?v=<?=filemtime(__DIR__.'/../js/chat-composer.js')?>" defer></script><?php endif; ?>
  <?php if($isChat&&!$privateChat): ?><script src="/js/chat-work.js?v=<?=filemtime(__DIR__.'/../js/chat-work.js')?>" defer></script><?php endif; ?>


</head>
<body>

  <header class="scene-toolbar" data-page-heading>
    <div class="scene-id"><?php if($imgs['scene_thumbnail']!==''): ?><button type="button" class="scene-location-image" data-scene-open="luogo" aria-label="Apri l’immagine di <?=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE)?>" title="Immagine del luogo"><img src="<?=htmlspecialchars($imgs['scene_thumbnail'],ENT_QUOTES|ENT_SUBSTITUTE)?>" alt="" width="80" height="60"></button><?php else: ?><span class="scene-round-icon" data-icon="<?=htmlspecialchars($sceneIcon,ENT_QUOTES)?>" aria-hidden="true"></span><?php endif; ?><div><h1><?=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE)?></h1><p><?=htmlspecialchars($posizione,ENT_QUOTES|ENT_SUBSTITUTE)?> · <?=$isChat?'CHAT IN GIOCO':'LUOGO'?></p></div></div>
    <div id="quest-header-slot" class="quest-header-slot"></div>
    <div class="scene-controls"><nav class="scene-actions" aria-label="Pannelli della chat">
      <?php if($isChat): ?><button type="button" class="scene-tool active" data-view="chat" data-control-label="Chat" data-ui-no-icon aria-label="Chat" title="Chat"><?=alphano_chat_control_art('dialogue')?></button><?php endif; ?>
      <button type="button" class="scene-tool" data-view="luogo" data-control-label="Luogo" data-ui-no-icon aria-label="Luogo" title="Luogo"><?=alphano_chat_control_art('location')?></button>
      <button type="button" class="scene-tool" data-view="descrizione" data-control-label="Descrizione" data-ui-no-icon aria-label="Descrizione" title="Descrizione"><?=alphano_chat_control_art('description')?></button>
      <?php if($isChat): ?><button type="button" class="scene-tool" data-view="presenti" data-control-label="Presenti" data-ui-no-icon aria-label="Presenti" title="Presenti"><?=alphano_chat_control_art('present')?></button><button type="button" class="scene-tool" data-view="scontro" data-control-label="Scontro" data-ui-no-icon aria-label="Scontro" title="Scontro"><?=alphano_chat_control_art('combat')?></button><button type="button" class="scene-tool" data-view="opzioni" data-control-label="Opzioni" data-ui-no-icon aria-label="Opzioni" title="Opzioni"><?=alphano_chat_control_art('options')?></button><button type="button" class="scene-tool" id="chat-expand" data-control-label="Espandi" data-ui-no-icon aria-label="Espandi la chat" title="Espandi la chat"><?=alphano_chat_control_art('expand')?></button><?php endif; ?>
      <?php if($isChat&&!$privateChat):require_once __DIR__.'/../libs/board-games.inc.php';if(bg_ready()): ?><button type="button" class="scene-tool chat-board-games-toggle" id="chat-board-games-open" data-control-label="Scacchi e Dama" aria-label="Scacchi e Dama" title="Scacchi e Dama" data-url="/giochi/?embed=1&amp;chat=<?=rawurlencode($chat)?>" aria-expanded="false" aria-controls="chat-board-games-panel" data-ui-no-icon><?=alphano_chat_control_art('games')?></button><?php endif;endif; ?>
      <?php if($master&&$isChat&&!$privateChat): ?><button type="button" class="scene-tool scene-master-control" id="scene-master-control" data-control-label="Master" data-ui-no-icon aria-label="Apri il pannello Master della giocata" title="Conduci la giocata"><?=alphano_chat_control_art('master')?><span class="scene-master-label">Master</span></button><?php endif; ?>
<?php if($isChat&&(!empty($config['rental_enabled'])||($config['private_kind']??'')==='rental')): ?><button type="button" class="scene-tool" id="rental-open" data-control-label="Camere" data-ui-no-icon aria-label="Camere e affitti" title="Camere e affitti"><?=alphano_chat_control_art('rooms')?></button><?php endif; ?>
      <button type="button" class="scene-tool scene-mobile-menu" id="chat-mobile-menu" data-control-label="Menu" data-ui-no-icon aria-label="Menu e pannelli" aria-expanded="false" title="Menu e pannelli"><?=alphano_chat_control_art('menu')?></button>
    </nav></div>
  </header>
<?php if(($config['private_kind']??'')==='rental'): ?><div class="rental-clock" data-rental-room="<?=htmlspecialchars($chat,ENT_QUOTES)?>" data-rental-companion="<?=htmlspecialchars($config['companion_name']??'',ENT_QUOTES)?>"><span data-icon="book" aria-hidden="true"></span><span id="rental-countdown">Tempo della camera…</span><a class="action-btn" href="/chat/index.php?chat=<?=rawurlencode($config['rental_host'])?>">Torna alla sala comune</a></div><?php endif; ?>
  <!-- Sezione Chat -->
  <?php if ($isChat): ?>
  <section id="chat" class="tab-content active">
    <div id="chatbox" aria-label="Trascrizione della scena"></div>
    <p id="chat-connection-status" role="status" aria-live="polite">Collegamento alla chat…</p>
    <?php if(alphano_config('ai_enabled')):?><p class="privacy-ai-disclosure" data-ai-disclosure>PNG e Master IA sono narratori automatici e possono sbagliare. <a href="/legal/?document=ai" target="_blank" rel="noopener">Regole IA · 18+</a></p><?php endif;?>
    <?php require __DIR__.'/../templates/chat/composer.php'; ?>
    <p id="ai-chat-status" role="status" aria-live="polite"></p>
  </section>
  <?php endif; ?>
  <?php if($isChat&&!$privateChat): ?><dialog id="chat-work-dialog" class="modal-window work-dialog" aria-labelledby="chat-work-title"><header class="modal-header"><img src="/assets/placeholder.svg" alt="" width="52" height="52"><div><small>Alphano · Lavori e raccolta</small><h2 id="chat-work-title">Stai compiendo un’azione lavoro</h2></div><button type="button" id="chat-work-close" aria-label="Chiudi" data-icon="x"></button></header><div class="modal-body"><div id="chat-work-preview"></div><p id="chat-work-warning" class="work-guidance" role="status"></p><footer class="work-dialog-actions"><button type="button" id="chat-work-cancel">Torna alla chat</button><button type="button" id="chat-work-confirm">Conferma e invia il tentativo</button></footer></div></dialog><?php endif; ?>

  <button class="chat-drawer-backdrop" id="chat-drawer-backdrop" aria-label="Chiudi il pannello" hidden></button>
  <aside class="chat-drawer" id="chat-drawer" role="dialog" aria-modal="true" aria-labelledby="chat-drawer-title" aria-label="Informazioni sulla scena" hidden>
    <header class="chat-drawer-head"><h2 id="chat-drawer-title">Luogo</h2><button type="button" id="chat-drawer-close" aria-label="Chiudi pannello" data-icon="x"></button></header><div class="chat-drawer-body">
    <section id="luogo" hidden><h3><?=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE)?></h3><?php if(($imgs['scene_image']?:$imgs['mappa'])!==''): ?><figure class="scene-illustration"><img class="scene-map-image" src="<?=htmlspecialchars($imgs['scene_image']?:$imgs['mappa'],ENT_QUOTES|ENT_SUBSTITUTE)?>" alt="Veduta di <?=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE)?>"><figcaption><?=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE)?></figcaption></figure><?php endif; ?><p><?=nl2br(htmlspecialchars($aiScene['public_setting']??strip_tags($datides),ENT_QUOTES|ENT_SUBSTITUTE))?></p></section>
    <section id="menu" hidden><h3>Menu e pannelli</h3><div class="scene-mobile-links"><button type="button" data-scene-open="luogo" data-ui-no-icon><?=alphano_chat_control_art('location')?>Luogo</button><button type="button" data-scene-open="descrizione" data-ui-no-icon><?=alphano_chat_control_art('description')?>Descrizione</button><button type="button" data-scene-open="presenti" data-ui-no-icon><?=alphano_chat_control_art('present')?>Presenti</button><button type="button" data-scene-open="scontro" data-ui-no-icon><?=alphano_chat_control_art('combat')?>Scontro</button><button type="button" data-scene-open="opzioni" data-ui-no-icon><?=alphano_chat_control_art('options')?>Opzioni</button><button type="button" id="chat-game-menu" data-ui-no-icon><?=alphano_chat_control_art('menu')?>Menu del gioco</button></div></section>
    <section id="presenti" hidden><h3>Presenti nel luogo</h3><div id="scene-presence"></div><a href="/presenti/" class="action-btn">Elenco completo dei presenti</a></section>
    <section id="scontro" hidden><h3>Regia della scena</h3><div id="scene-join-control"></div><div id="scene-combat-detail"></div><?php if($master&&!$privateChat): ?><button type="button" id="scene-takeover" data-icon="crown">Prendi in mano la quest</button><button type="button" id="scene-release" data-icon="scroll">Affida la regia al Master IA</button><button type="button" id="scene-manager" data-icon="settings">Session Manager</button><?php endif; ?></section>
  <!-- Sezione Descrizione -->
  <section id="descrizione" <?= $isChat ? 'hidden' : '' ?>>
    <?php if ($isChat && ($utente['admin'] || $master)): ?>
      <details class="scene-description-editor"><summary>Modifica la descrizione originale del luogo</summary>
      <form method="post" action="?action=control&chat=<?= urlencode($chat) ?>" class="descr-form">
        <textarea name="txtdescrizione"><?= htmlspecialchars($datides, ENT_QUOTES) ?></textarea>
        <button type="submit">Salva descrizione</button>
      </form>
      </details>
    <?php endif; ?>

    <?php if ($datides !== ''): ?>
      <div class="descr-content"><h2>Descrizione del luogo</h2><?= ProcessText($datides) ?></div>
    <?php else: ?>
      <p class="no-descr"><em>Nessuna descrizione.</em></p>
    <?php endif; ?>
    <?php if ($isChat && ($aiScene['public_setting']??'')!==''): ?>
      <section class="scene-world descr-content"><h2>Ambientazione del luogo</h2><p><?=nl2br(htmlspecialchars($aiScene['public_setting'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))?></p></section>
    <?php endif; ?>
    <?php if ($sceneNpcs): ?>
      <section class="scene-cast descr-content"><h2>Personaggi del luogo</h2>
      <?php foreach ($sceneNpcs as $npc): $profile=ai_npc_profile($npc); ?>
        <article class="scene-person"><a href="/profili/png.php?Id=<?=(int)$npc['id']?>" data-npc-profile="<?=(int)$npc['id']?>"><img class="npc-portrait" src="<?=htmlspecialchars($profile['portrait']?:'/assets/placeholder.svg',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" alt="Ritratto di <?=htmlspecialchars($npc['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></a>
          <div><h3><?=ai_profile_link($npc)?></h3><p><?=htmlspecialchars($profile['occupation'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p><p><?=nl2br(htmlspecialchars($profile['appearance'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))?></p><p><?=nl2br(htmlspecialchars($profile['biography'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))?></p><a href="/profili/png.php?Id=<?=(int)$npc['id']?>" data-npc-profile="<?=(int)$npc['id']?>">Leggi il profilo completo</a></div>
        </article>
      <?php endforeach; ?></section>
    <?php endif; ?>
    <?php if ($imgs['mappa'] !== ''): ?>
      <img class="descr-mappa" src="<?= htmlspecialchars($imgs['mappa'], ENT_QUOTES) ?>" alt="Mappa">
    <?php endif; ?>
  </section>

  <!-- Sezione Opzioni -->
  <?php if ($isChat): ?>
  <section id="opzioni" hidden><h3>Preferenze di lettura</h3><div class="chat-reading-options"><label><input type="checkbox" id="optPortrait" checked> Mostra ritratti e cornici</label><label><input type="checkbox" id="optCompact"> Cornici compatte</label><label><input type="checkbox" id="optDark"> Lettura scura</label><label for="optFont">Dimensione testo <output id="optFontValue">16 px</output><input type="range" id="optFont" min="14" max="21" step="1" value="16"></label></div><h3>Emozioni e rapporti</h3><label>Avvisi animati<select data-relation-visibility aria-label="Visibilità delle emozioni in chat"><?php $fx=alphano_experience_settings($idpg)['relationship_effects'];foreach(['private'=>'Solo io · i miei rapporti','public'=>'Condividi con i presenti','off'=>'Disattivati'] as $value=>$label): ?><option value="<?=$value?>"<?=$fx===$value?' selected':''?>><?=$label?></option><?php endforeach; ?></select></label><p>Solo la condivisione esplicita rende visibili ai presenti le tue nuove variazioni. Questa preferenza si ritrova anche sulla mappa e nelle impostazioni di gioco e riservatezza. Disattivare gli effetti non ferma l’evoluzione dei rapporti.</p><p data-relation-preference-status role="status"></p><p>Gli avvisi degli esiti pubblici e dei dadi sono condivisi con i presenti. Disattivare gli effetti nasconde le animazioni sul tuo schermo; i messaggi registrati restano visibili. Durante una quest anche il parlato in gioco usa il tuo turno. Per commenti fuori gioco usa //.</p><h3>Comandi di gioco</h3>
    <div class="opzioni">
      <div class="opzione header"><div>Tipo</div><div>Prefisso</div><div>Descrizione</div></div>
      <div class="opzione"><div>Parlato</div><div>–</div><div>Testo normale</div></div>
      <div class="opzione"><div>Dialogo e gesti</div><div>« » · * *</div><div>Puoi mescolare parole e gesti: il PNG legge anche virgolette, incisi e azioni senza +. In Automatico riconosce il nome o l’interlocutore della scena; puoi anche sceglierlo nel menu. Nessun PNG mantiene il dialogo fra utenti. I bersagli davvero ambigui richiedono un chiarimento.</div></div>
      <div class="opzione"><div>Azione</div><div>+</div><div>
        Anteponi “+”
        <?php if (count($azioniLavoro)): ?>
          <ul>
            <?php foreach ($azioniLavoro as $az): ?>
              <li>+<?= htmlentities($az['azione']) ?> ⇒ <?= GetEquipDesc($az['res']) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div></div>
      <div class="opzione"><div>Sussurro</div><div>$dest;</div><div>Solo tu e destinatario</div></div>
      <div class="opzione"><div>Azione animale</div><div>&amp;nome;</div><div>Azione da animale</div></div>
      <?php if ($master): ?>
        <div class="opzione"><div>Master</div><div>#</div><div>Interventi narrativi</div></div>
      <?php endif; ?>
      <?php if ($canNarrate): ?>
        <div class="opzione"><div>Descr</div><div>%</div><div>Descrizioni ambientali</div></div>
        <div class="opzione"><div>Immagine</div><div>@URL</div><div>Incolla URL immagine</div></div>
      <?php endif; ?>
      <?php if ($master): ?>
        <div class="opzione"><div>Incognito</div><div>|frase</div><div>Anonimo</div></div>
      <?php endif; ?>
      <div class="opzione"><div>Off-Topic</div><div>//</div><div>Fuori ambientazione</div></div>
    </div>
  </section>
  <?php endif; ?>

    </div></aside>
  <!-- Modal Session Manager -->
  <?php if ($master && $isChat && !$privateChat): ?>
  <dialog id="sessionModalOverlay" class="modal-window session-manager-dialog" aria-label="Gestione Master e sessione">
    <header class="modal-header"><h2>Gestione Master e sessione</h2><button type="button" id="sessionModalClose" data-icon="x" aria-label="Chiudi gestione Master"></button></header>
    <iframe id="session-manager-frame" title="Gestione della sessione"></iframe>
  </dialog>
  <?php endif; ?>

<?php if($isChat&&(!empty($config['rental_enabled'])||($config['private_kind']??'')==='rental')): ?>
<dialog id="rental-dialog" class="modal-window rental-dialog" aria-labelledby="rental-title"><header class="modal-header"><h2 id="rental-title">Camere riservate</h2><button type="button" id="rental-close" data-icon="x" aria-label="Chiudi camere"></button></header><div class="modal-body">
<?php if(($config['private_kind']??'')==='rental'): ?><?php if(!empty($config['companion_name'])): ?><p>Questa camera ospita un incontro privato. Il tempo rimanente è indicato sopra la chat; alla scadenza tornerai nella sala comune.</p><button type="button" id="companion-end" data-icon="x">Concludi l’incontro</button><?php else: ?><p>Invita altri giocatori a raggiungere questa camera usando la parola d’ordine nella sala comune. L’accesso non comporta un nuovo pagamento per gli ospiti.</p><?php endif; ?><a class="action-btn" href="/chat/index.php?chat=<?=rawurlencode($config['rental_host'])?>">Ritorna alla sala comune</a>
<?php else: ?><p>Affitta una camera e comunica la parola d’ordine ai tuoi invitati. Tutti potranno giocare nella stessa chat riservata fino alla scadenza.</p><div class="rental-form-grid"><form id="rental-rent" autocomplete="off"><h3>Affitta una camera</h3><label>Nuova parola d’ordine<input type="password" name="word" autocomplete="new-password" minlength="3" maxlength="72" required></label><label>Durata in ore<input type="number" name="hours" value="1" min="1" max="168" step="1" required></label><p><span id="rental-rate"><?=(int)$config['rental_hourly_cost']?></span> <?=alphano_currency_html()?> / ora · Totale: <strong id="rental-total"><?=(int)$config['rental_hourly_cost']?></strong> <?=alphano_currency_html()?></p><button type="submit" data-icon="coins">Affitta ed entra</button></form><form id="rental-join" autocomplete="off"><h3>Raggiungi una camera</h3><label>Parola d’ordine dell’affittuario<input type="password" name="word" autocomplete="off" minlength="3" maxlength="72" required></label><p>Per gli invitati l’ingresso è gratuito. La scadenza resta quella dell’affitto originale.</p><button type="submit" data-icon="users">Entra nella camera</button></form></div><?php if(!empty($config['companion_enabled'])): ?><section id="companion-services" class="companion-services"><h3>Accompagnati con una meretrice</h3><p>Incontro privato di <?=(int)$config['companion_minutes']?> minuti · <?=(int)$config['companion_price']?> <?=alphano_currency_html()?>. Scegli la persona con cui trascorrere questo tempo; la compagnia non impone sentimenti o intimità.</p><button type="button" id="companion-list-open" data-icon="users">Scegli un’accompagnatrice</button><div id="companion-list" aria-live="polite"></div><details><summary>Le mie preferenze adulte</summary><form id="companion-preferences"><label>Età del mio personaggio<input type="number" name="character_age" min="18" max="2000" required></label><label><input type="checkbox" name="romance_enabled"> Consento trame romantiche con personaggi adulti</label><label><input type="checkbox" name="intimacy_enabled"> Consento relazioni adulte non esplicite</label><button type="submit">Salva preferenze</button></form></details></section><?php endif; ?><?php endif; ?><p id="rental-feedback" role="status" aria-live="polite"></p></div></dialog>
<?php endif; ?>
<?php if($isChat&&!$privateChat)require __DIR__.'/../templates/chat/rpg-skills.php'; ?>
<?php if($isChat&&!$privateChat):require_once __DIR__.'/../libs/board-games.inc.php';if(bg_ready()): ?>

<aside id="chat-board-games-panel" class="board-game-dock" hidden aria-label="Giochi del luogo"><header><strong>Il tavolo delle strategie</strong><button type="button" aria-label="Riduci il tavolo"><span aria-hidden="true">−</span><span class="board-return-label">Torna alla chat</span></button></header><iframe title="Scacchi e Dama del luogo"></iframe></aside><script src="/js/chat-board-games.js?v=<?=filemtime(__DIR__.'/../js/chat-board-games.js')?>" defer></script>
<?php endif;endif; ?>
<script src="/js/rpg-chat.js?v=<?=filemtime(dirname(__DIR__).'/js/rpg-chat.js')?>" defer></script>
<script src="/js/companions.js?v=<?=filemtime(dirname(__DIR__).'/js/companions.js')?>" defer></script>
<script src="/js/rentals.js?v=<?=filemtime(dirname(__DIR__).'/js/rentals.js')?>" defer></script>
  <script src="/js/chat-view.js?v=<?=filemtime(__DIR__.'/../js/chat-view.js')?>" defer></script>
  <script src="/js/scene-effects.js?v=<?=filemtime(dirname(__DIR__).'/js/scene-effects.js')?>" defer></script>
  <script src="/js/relation-effects.js?v=<?=filemtime(dirname(__DIR__).'/js/relation-effects.js')?>" defer></script>
  <script src="/chat/js/chat_scene.js?v=<?=filemtime(__DIR__.'/js/chat_scene.js')?>" defer></script>
</body></html>
