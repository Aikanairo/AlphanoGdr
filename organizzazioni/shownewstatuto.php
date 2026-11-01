<?php
declare(strict_types=1);

/********************************************************
 * admin/showstatuti.php
 * Visualizza Vecchio e Nuovo Statuto – PHP 8 + PDO
 ********************************************************/

/* ─────────────────── 1) Bootstrap ─────────────────── */
require_once __DIR__ . '/../libs/kernel.inc.php';       // initSession(), ClosePage()
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';       // GetIDPG()
require_once __DIR__ . '/../libs/libtext.inc.php';     // ProcessText()
require_once __DIR__ . '/../libs/libaccademia.inc.php';// EnsureAdmin(), …
require_once __DIR__ . '/../libs/libposta.inc.php';    // (se serve)



initSession();
$idpg = GetIDPG(false, true);
if (!$idpg) {
    header('Location: /login.php');
    exit;
}

$db      = ConnectDB();


/* ─────────────────── 2) Recupera ID organ­i­zazione ─────────────────── */
$org = 0;
if (!empty($_POST['org'])) {
    $org = (int)$_POST['org'];
} elseif (!empty($_GET['org'])) {
    $org = (int)$_GET['org'];
}

/* ─────────────────── 3) Controlli permessi ─────────────────── */
// Prendo flag admin e adminaccademia
$user = $db->query(
    'SELECT admin, adminaccademia FROM utenti WHERE id = ?',
    [$idpg]
)[0] ?? null;

$isAdmin     = !empty($user['admin']);
$isAccademia = !empty($user['adminaccademia']);

if ($isAdmin) {
    EnsureAdmin();
}
if (!$isAccademia) {
    http_response_code(403);
    exit('Non ti è permesso accedere');
}

/* ─────────────────── 4) Recupera dati organizzazione ─────────────────── */
$orgData = $db->query(
    'SELECT nome, statuto, newstatuto FROM organizzazioni WHERE id = ?',
    [$org]
)[0] ?? null;

if (!$orgData) {
    http_response_code(404);
    exit('Organizzazione non trovata');
}

/* ─────────────────── 5) Render HTML ─────────────────── */
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=htmlspecialchars($orgData['nome'])?> – <?=htmlspecialchars(TitoloSito)?></title>


</head>
<body>
  <table>
    <tr>
      <th class="Valore column-width-50">Vecchio statuto</th>
      <th class="Valore column-width-50">Nuovo statuto</th>
    </tr>
    <tr>
      <td class="Valore"><?=ProcessText((string)$orgData['statuto'])?></td>
      <td class="Valore"><?=ProcessText((string)$orgData['newstatuto'])?></td>
    </tr>
    <tr>
      <td colspan="2">
        <form action="../admin/accademia.php" method="post">
          <button type="submit" class="u-118a0d8c2">
            Torna all'accademia
          </button>
        </form>
      </td>
    </tr>
  </table>
</body>
</html>
<?php
ClosePage();
