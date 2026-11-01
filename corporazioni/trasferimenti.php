<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';
require_once __DIR__.'/../libs/corporation-commerce.inc.php';
alphano_require_login();
$tradeUser=alphano_user();
if(!empty($tradeUser['admin'])&&empty($tradeUser['corporazione'])&&!isset($_GET['corp'])){header('Location: /corporazioni/');exit;}
try{$selectedCorp=alphano_corporation_context($tradeUser,$_GET['corp']??null);}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}

// autenticazione
$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}

// recupero dati utente + corp
$db = getDB();
$user = $db->query(
    'SELECT c.id AS corp_id, u.nome AS pgname, c.nome AS nomecorp, c.budget
       FROM utenti u
  LEFT JOIN corporazioni c ON c.id = ?
      WHERE u.id = ?',
    [$selectedCorp,$idpg]
)[0] ?? null;

if (!$user || !$user['corp_id']) {
    die("Non puoi amministrare una corporazione senza possederne una");
}

$corp       = (int)$user['corp_id'];
$pgname     = $user['pgname'];
$nomecorp   = $user['nomecorp'];
$budget     = (float)$user['budget'];
$admintrasf = (bool)$db->query(
    'SELECT (admin=1 OR admintrasfcorp=1 OR admincorporazione=1) AS admintrasfcorp FROM utenti WHERE id = ?',
    [$idpg]
)[0]['admintrasfcorp'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($nomecorp) ?> – <?= TitoloSito ?></title>

  <script src="/js/global.js" defer></script>
  <script src="/js/corporazioni/trasferimenti.js" defer></script>

</head>
<body data-corporation="<?=$corp?>">
  <p><strong>Budget della corporazione:</strong> <span id="corporate-trade-balance"><?= (int)$budget ?></span> <?=alphano_currency_html()?></p>
  <p id="corporate-trade-feedback" class="notice" role="status" aria-live="polite" hidden></p>
  <nav class="ui-tablist" role="tablist" aria-label="Commercio della corporazione">
    <button type="button" id="mpersonal" role="tab" aria-controls="personal">Acquisti dai giocatori</button>
    <button type="button" id="mstacq" role="tab" aria-controls="stacq">Storico acquisti</button>
    <?php if ($admintrasf): ?>
      <button type="button" id="mcorp" role="tab" aria-controls="corp">Ordini clienti</button>
      <button type="button" id="mstven" role="tab" aria-controls="stven">I miei ordini</button>
    <?php endif; ?>
    <button type="button" id="mproduzione" role="tab" aria-controls="produzione">Magazzino e consegne</button>
    <button type="button" id="mstorico" role="tab" aria-controls="storico">Storico vendite</button>
  </nav>
  <div class="ui-form">
    <section id="personal" class="ui-tabpanel" role="tabpanel" aria-labelledby="mpersonal"></section>
    <section id="stacq" class="ui-tabpanel" role="tabpanel" aria-labelledby="mstacq" hidden></section>
    <?php if ($admintrasf): ?>
      <section id="corp" class="ui-tabpanel" role="tabpanel" aria-labelledby="mcorp" hidden></section>
      <section id="stven" class="ui-tabpanel" role="tabpanel" aria-labelledby="mstven" hidden></section>
    <?php endif; ?>
    <section id="produzione" class="ui-tabpanel" role="tabpanel" aria-labelledby="mproduzione" hidden></section>
    <section id="storico" class="ui-tabpanel" role="tabpanel" aria-labelledby="mstorico" hidden></section>
  </div>
</body>
</html>
