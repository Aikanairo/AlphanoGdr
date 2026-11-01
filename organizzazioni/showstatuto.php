<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

/**
 * statuto.php
 * Visualizza e modifica lo statuto di un'organizzazione
 */

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__.'/../libs/group-media.inc.php';        // initSession(), ClosePage()
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/group-admin.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';         // GetIDPG(), EnsureAdmin()
require_once __DIR__ . '/../libs/libtext.inc.php';       // ProcessText()
require_once __DIR__ . '/../libs/libaccademia.inc.php';  // SalvaStatutoOrg(), GetStatutoOrg(), GetTitolo()
require_once __DIR__ . '/../libs/libposta.inc.php';      // eventualmente per notifiche



initSession();
$idpg = GetIDPG(false, true);
if (!$idpg) {
    header('Location: /login.php');
    exit;
}


$msg     = '';

// Leggo org da POST o GET
$org    = filter_input(INPUT_POST,  'org',    FILTER_VALIDATE_INT)
        ?? filter_input(INPUT_GET,   'org',    FILTER_VALIDATE_INT);
$modify = filter_input(INPUT_POST,  'modify', FILTER_VALIDATE_BOOLEAN);
$stat   = filter_input(INPUT_POST,  'statuto', FILTER_UNSAFE_RAW) ?? '';

if ($org === false || $org === null) {
    http_response_code(400);
    exit('Organizzazione non specificata.');
}

$db = ConnectDB();

// Controllo permessi
$user = $db->query(
    'SELECT admin, adminaccademia, organizzazione, adminorganizzazione
       FROM utenti
      WHERE id = ?',
    [$idpg]
)[0] ?? [];
$admin     = (bool)($user['admin'] ?? false);
$accademia = (bool)($user['adminaccademia'] ?? false);
$adminorg  = (int)$user['organizzazione'] === (int)$org && (bool)$user['adminorganizzazione'];

if ($admin) {
    EnsureAdmin();
}

// Salvataggio statuto
if ($modify && ($admin || $adminorg) && strlen($stat) > 0) {
    if (alphano_group_statute_proposal('organizzazioni',$org,$idpg,$stat,$_POST['_request_key'] ?? null)) {
        $msg = 'Statuto inviato all\'accademia';
    }
}

// Carico il testo (in visualizzazione o per modifica)
$statuto = GetStatutoOrg($org, (bool)$modify);

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars(GetTitolo($statuto), ENT_QUOTES) ?> – <?= TitoloSito ?></title>


<?php if ($modify && ($admin || $adminorg)): ?>
  <script src="/js/gml.js"></script>
  <script>
    function Invia() {
      if (document.statutoForm.statuto.value.trim().length) {
        document.statutoForm.submit();
      } else {
        alert('Devi inserire uno statuto');
      }
    }
  </script>
<?php endif ?>
</head>
<body><header class="ui-page-heading" data-page-heading data-header-image="<?=htmlspecialchars(group_header_image('organizzazioni',(int)$org),ENT_QUOTES)?>"><span data-icon="shield"></span><div><h1><?=htmlspecialchars(GetOrg((int)$org,false),ENT_QUOTES)?></h1><p>Statuto e identità del gruppo.</p></div></header>
  <?php if ($msg): ?>
    <div class="panel"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
  <?php endif ?>

  <?php if ($modify && ($admin || $adminorg)): ?>
    <div class="panel">
      <h1>Modifica dello Statuto</h1>
      <div class="Descrizione">
        Ogni modifica allo statuto implica la riesaminazione da parte dell'Accademia.<br>
        La proposta conserva il fondatore attuale dell’organizzazione.
      </div>
    </div>
    <form name="statutoForm" method="post">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="org"    value="<?= $org ?>">
      <input type="hidden" name="modify" value="1">
      <script>CreateGMLHelper("document.statutoForm.statuto")</script>
      <textarea name="statuto"><?= htmlspecialchars($statuto, ENT_QUOTES) ?></textarea>
      <div>
        <button type="button" class="btn" onclick="Invia()">Salva</button>
        <button type="button" class="btn" onclick="document.statutoForm.reset()">Annulla</button>
      </div>
    </form>
  <?php else: ?>
    <div class="Valore"><?= ProcessText($statuto) ?></div>
    <?php if ($admin || $adminorg): ?>
      <form method="post">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
        <input type="hidden" name="org"    value="<?= $org ?>">
        <input type="hidden" name="modify" value="1">
        <button type="submit" class="btn">Modifica</button>
      </form>
    <?php endif ?>
  <?php endif ?>

  <div>
    <button class="btn" onclick="location.href='index.php'">
      Ritorna alla lista delle Organizzazioni
    </button>
    <?php if ($accademia): ?>
      <form action="../admin/accademia.php" method="post" class="u-434fc32ec">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
        <button class="btn">Vai all'Accademia</button>
      </form>
    <?php endif ?>
  </div>
</body>
</html>
<?php
ClosePage();
