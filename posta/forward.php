<?php
declare(strict_types=1);

// ———————————————————————————
// MASSIMO DEBUG & ERROR HANDLING
// ———————————————————————————
function h(string|int|null $v): string {
    return htmlentities((string)$v, ENT_QUOTES, 'UTF-8');
}

// ———————————————————————————
// INCLUDI LIBRERIE
// ———————————————————————————
$root = dirname(__DIR__);
require_once "$root/libs/kernel.inc.php";
require_once "$root/libs/libgrafica.inc.php";
require_once "$root/libs/libpg.inc.php";
require_once "$root/libs/libtext.inc.php";
require_once "$root/libs/libposta.inc.php";



// ———————————————————————————
// LOGIN & COLORI
// ———————————————————————————
$idpg    = alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
$db      = ConnectDB();


// ———————————————————————————
// RACCOLTA PARAMETRI
// ———————————————————————————
$msgId   = (int)($_POST['id']     ?? 0);
$oldId   = (int)($_POST['oldid']  ?? 0);
$pgName  = trim((string)($_POST['pgName'] ?? ''));
$send    = ($_POST['send'] ?? '0') === '1';
$subject = trim($_POST['oggetto']  ?? '');
$body    = trim($_POST['messaggio'] ?? '');
$save    = 0; // Le missive inviate restano nella conversazione.
if (isset($_POST['firma']) && $_POST['firma']) {
    $body .= FirmaPG($idpg);
}

// ———————————————————————————
// CARICA IL MESSAGGIO ORIGINALE
// ———————————————————————————
$msg = $db->query('SELECT oggetto,messaggio FROM mail WHERE id = ? AND (idmittente=? OR iddestinatario=?)', [$msgId,$idpg,$idpg])[0] ?? null;
if (!$msg) {
    alphano_fail('Messaggio da inoltrare non trovato.',404);
}
$fwdSubject = str_starts_with($msg['oggetto'], 'FWD:')
    ? substr($msg['oggetto'], 4)
    : $msg['oggetto'];
$fwdBody    = $msg['messaggio'];

// ———————————————————————————
// GESTIONE INVIO
// ———————————————————————————
$errors = [];
$sent   = [];
$failed = [];

if ($send) {
    if ($subject === '') {
        $errors[] = 'Devi inserire un oggetto.';
    }
    // costruiamo la query dei destinatari
    $filters = ["1=1"];
    $params  = [];

    // placeholder di esempio: se l'utente non ha specificato nome, errore
    if ($pgName === '') {
        $errors[] = 'Devi specificare almeno un destinatario.';
    } else {
        // supporto wildcard e lista con ;
        if (str_contains($pgName, ';')) {
            $names = array_map('trim', explode(';', $pgName));
            $in     = implode(',', array_fill(0, count($names), '?'));
            $filters[] = "u.nome IN ($in)";
            $params = array_merge($params, $names);
        } else {
            $filters[] = "u.nome LIKE ?";
            $params[]  = $pgName;
        }
    }

    if (empty($errors)) {
        $sql = 'SELECT u.id,u.nome
                  FROM utenti u
                  JOIN araldiche a ON a.id=u.araldica
                 WHERE ' . implode(' AND ', $filters) . '
                 GROUP BY u.id
                 ORDER BY u.nome ASC';
        $rows = $db->query($sql, $params);

        if (empty($rows)) {
            $errors[] = "Nessun utente corrisponde a “{$pgName}”.";
        } else {
            foreach ($rows as $r) {
                $toId = (int)$r['id'];
                $ok = InviaMissiva($subject, $body, $toId, $idpg, $save);
                if ($ok) {
                    $sent[]   = $r['nome'];
                } else {
                    $failed[] = $r['nome'];
                }
            }
        }
    }
}

// ———————————————————————————
// RISULTATO / FORM INOLTRA
// ———————————————————————————
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title><?= $send ? 'Risultato Inoltro' : 'Inoltra Missiva' ?> – <?= TitoloSito ?></title>

  <script src="/js/global.js"></script>

</head>
<body>
  <div class="message-header"><?= h($send ? 'Risultato Inoltro' : 'Inoltra Missiva') ?></div>
  <div class="container">
    <?php if ($send): ?>
      <?php if ($errors): ?>
        <div class="error">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
        <div class="actions">
          <button onclick="history.back()">Torna indietro</button>
        </div>
      <?php else: ?>
        <h2>Destinatari Raggiunti</h2>
        <?php if ($sent): ?>
          <ul class="list">
            <?php foreach ($sent as $u): ?>
              <li><?= h($u) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p>Nessun destinatario raggiunto.</p>
        <?php endif; ?>

        <h2>Destinatari Non Raggiunti</h2>
        <?php if ($failed): ?>
          <ul class="list">
            <?php foreach ($failed as $u): ?>
              <li><?= h($u) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p>Tutti i destinatari hanno ricevuto il messaggio.</p>
        <?php endif; ?>

        <div class="actions">
          <form action="./" method="GET">
            <button type="submit">Torna alla Posta</button>
          </form>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="send" value="1">
        <input type="hidden" name="id"   value="<?= h($msgId) ?>">
        <?php if ($oldId): ?>
          <input type="hidden" name="oldid" value="<?= h($oldId) ?>">
        <?php endif; ?>

        <table>
          <tr>
            <th>Destinatario</th>
            <td>
              <input type="text" name="pgName" value="<?= h($pgName) ?>" placeholder="Nome o lista separata da ;">
            </td>
          </tr>
          <tr>
            <th>Oggetto</th>
            <td>
              <input type="text" name="oggetto" value="FWD:<?= h($fwdSubject) ?>">
            </td>
          </tr>
          <tr>
            <th>Messaggio</th>
            <td>
              <textarea name="messaggio" rows="8"><?= h($fwdBody) ?></textarea>
            </td>
          </tr>
          <tr>
            <th>Opzioni</th>
            <td>
              <label><input type="checkbox" name="firma" checked> Inserisci firma</label><br>
              <span>Una copia resta nella tua conversazione.</span>
            </td>
          </tr>
        </table>

        <div class="actions">
          <button type="button" onclick="ControllaCampi()">Invia</button>
          <button type="reset">Annulla</button>
          <button type="button" onclick="location.href='index.php'">Torna Indietro</button>
        </div>
      </form>

      <script>
        function ControllaCampi(){
          const form = document.forms[0];
          if (!form.oggetto.value.trim()) {
            form.oggetto.value = prompt("Per favore inserisci un oggetto:");
          }
          if (form.oggetto.value.trim()) {
            form.submit();
          }
        }
      </script>
    <?php endif; ?>
  </div>
</body>
</html>
<?php ClosePage(); ?>
