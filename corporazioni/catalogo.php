<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';



// ─── AUTENTICAZIONE ─────────────────────────────────────────
$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}
$db      = getDB();


// ─── UTENTE & PERMESSI ─────────────────────────────────────
$user = $db->query(
    'SELECT nome, organizzazione, corporazione, admin, admincatalogo, admincorporazione
       FROM utenti WHERE id = ?',
    [$idpg]
)[0] ?? null;
if (!$user) die("Utente non trovato");


$nome    = $user['nome'];
$orgId   = (int)$user['organizzazione'];
$orgName = GetOrg($orgId, false);
$corpId  = isset($_GET['corp']) ? (int)$_GET['corp'] : 0;
if (!$corpId) die("Nessuna corporazione selezionata");

// definisco i permessi evitando warning se i campi non esistono
$admincatalogo = (bool)$user['admin'] || ((int)$user['corporazione']===$corpId && (bool)($user['admincatalogo'] ?? false));
$admincorp = (bool)$user['admin'] || ((int)$user['corporazione']===$corpId && (bool)($user['admincorporazione'] ?? false));

// ─── DATI PER JS ────────────────────────────────────────────
$catalogueBanner=(string)($db->query('SELECT sfondotop FROM corporazioni WHERE id=?',[$corpId])[0]['sfondotop']??'');
$taglie         = \TAGLIE;
$organizzazioni = ListaOrg();            // array di [ ['id'=>..,'nome'=>..], … ]
$materie        = \MATERIE_PRIME;        // array di nomi materie
$magicimg       = '/assets/placeholder.svg';
$approvedimg    = '/assets/placeholder.svg';
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Catalogo di <?= htmlspecialchars(GetCorporazione($corpId)) ?> – <?= TitoloSito ?></title>


</head>
<body class="ui-page catalogue-market">
<main class="catalogue-workspace">

<header class="ui-page-heading" data-page-heading<?php if($catalogueBanner!==''): ?> data-header-image="<?=htmlspecialchars($catalogueBanner,ENT_QUOTES)?>"<?php endif; ?>><span data-icon="coins"></span><div><span class="ui-eyebrow">Il mercato delle botteghe</span><h1><?=htmlspecialchars(GetCorporazione($corpId),ENT_QUOTES)?></h1><p>Manufatti su commissione, materiali reali, consegne registrate.</p></div><a class="action-btn" href="/corporazioni/materie.php" data-icon="coins">Vendi materie prime</a><?php if($admincorp): ?><a class="action-btn" href="/corporazioni/trasferimenti.php?corp=<?=$corpId?>" data-icon="scroll">Ordini e magazzino</a><?php endif; ?>
<nav class="ui-tablist" role="tablist" aria-label="Catalogo della corporazione"><button type="button" role="tab" id="Catalogo">Vetrina</button><button type="button" role="tab" id="CatalogoPers">Su misura</button><button type="button" role="tab" id="CatalogoPersOrg">Per organizzazione</button><button type="button" role="tab" id="Riparazioni">Riparazioni</button></nav>
<div id="controls" class="ui-heading-control"><?php if($admincatalogo):?><a class="action-btn catalogue-admin-icon" href="/corporazioni/oggetti-admin.php?corp=<?=$corpId?>" data-icon="settings" aria-label="Proprietà oggetti" title="Proprietà oggetti"></a><?php endif;?><label><input type="checkbox" id="chkShowAll"> Mostra anche le revisioni</label><button id="btnAdd" class="catalogue-admin-icon" data-icon="plus" aria-label="Nuovo oggetto" title="Nuovo oggetto"></button><button id="btnBack" class="catalogue-admin-icon" data-icon="back" aria-label="Corporazioni" title="Corporazioni"></button></div>
<div class="catalogue-market-tools ui-heading-control"><label>Cerca<input type="search" id="catalogue-search" placeholder="Nome, tipo o descrizione…"></label><label>Reparto<select id="catalogue-department"><option value="0">Tutti i reparti</option><option value="15">Armi bianche e da taglio</option><option value="2">Armi da fuoco</option><option value="5">Armature e scudi</option></select></label><span id="catalogue-count" role="status"></span></div></header>
<p id="catalogo-feedback" class="notice" role="status" hidden></p>
  <!-- LISTA E DETTAGLIO -->
  <div class="catalogue-layout">
    <section id="lista" aria-label="Oggetti del catalogo"></section>
    <section id="corpo" aria-label="Dettaglio oggetto" aria-live="polite"></section>
  </div>
</main>

  <!-- SCRIPTS -->
  <script src="/js/corporazioni/catalogo.js" defer></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      Catalogo.init({
        corp:           <?= json_encode($corpId,        JSON_UNESCAPED_UNICODE) ?>,
        nome:           <?= json_encode($nome,          JSON_UNESCAPED_UNICODE) ?>,
        organizzazione: <?= json_encode($orgName,       JSON_UNESCAPED_UNICODE) ?>,
        magicimg:       <?= json_encode($magicimg,      JSON_UNESCAPED_UNICODE) ?>,
        approvedimg:    <?= json_encode($approvedimg,   JSON_UNESCAPED_UNICODE) ?>,
        taglie:         <?= json_encode($taglie,        JSON_UNESCAPED_UNICODE) ?>,
        organizzazioni: <?= json_encode($organizzazioni,JSON_UNESCAPED_UNICODE) ?>,
        materie:        <?= json_encode($materie,       JSON_UNESCAPED_UNICODE) ?>,
        admincatalogo:  <?= json_encode($admincatalogo, JSON_UNESCAPED_UNICODE) ?>,
        admincorp:      <?= json_encode($admincorp,     JSON_UNESCAPED_UNICODE) ?>,
        // tipi e relative “max” verranno riempiti dall’API:
        tipi:           []
      });
    });
  </script>
</body>
</html>
