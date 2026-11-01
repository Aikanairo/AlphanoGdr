<?php if (!defined('ALPHANO_SHELL')) exit; ?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=htmlspecialchars(GetPGFromID($idpg) . ' – ' . TitoloSito, ENT_QUOTES | ENT_SUBSTITUTE)?></title>
  <script src="/js/global.js" defer></script>
  <script type="module" src="/js/creation-login.js?v=20261030"></script>
  <script type="module" src="/js/notifications.js?v=<?=filemtime(dirname(__DIR__).'/js/notifications.js')?>"></script>
  <script id="app-script" type="module" src="/js/app.js?v=<?=filemtime(dirname(__DIR__).'/js/app.js')?>" data-idpg="<?=$idpg?>" data-view-url="<?=htmlspecialchars($initialView, ENT_QUOTES | ENT_SUBSTITUTE)?>"></script>
</head>
<body data-view="main">
  <div class="app game-shell">
    <main class="game-content">
      <section class="main-col" aria-label="Luogo e attività">
        <?php if(alphano_can('admin')&&ConnectDB()->query("SELECT step_key FROM engine_setup_steps WHERE status<>'completed' LIMIT 1")):?><aside class="setup-reminder"><a href="/admin/setup.php">Configurazione del gioco da completare · Apri il riepilogo del gestore</a></aside><?php endif;?>
        <?php require __DIR__.'/partials/header.php'; ?>
        <div id="map" class="map-area panel"><p>Caricamento mappa…</p></div>
      </section>
      <?php require __DIR__.'/partials/sidebar.php'; ?>
    </main>
  </div>
</body>
</html>
