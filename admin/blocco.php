<?php
declare(strict_types=1);

// ———————————————————————————
// 1) INCLUDI LIBRERIE
// ———————————————————————————
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libblocco.inc.php';
require_once __DIR__ . '/../libs/libbacheca.inc.php';
require_once __DIR__ . '/../libs/libchat.inc.php';

use Alphano\Database\PDOMySQLDriver;


// ———————————————————————————
// 2) CONNESSIONE
// ———————————————————————————
alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
$db      = ConnectDB();  // PDOMySQLDriver


// ———————————————————————————
// 3) UTENTE & SICUREZZA
// ———————————————————————————
$pgid = GetIDPG(0,1) ?: die('❗ Devi essere loggato');

// **uso query() per leggere**
$userRows = $db->query(
    "SELECT posizione,fatepower,admin,bloccato
       FROM utenti
      WHERE id = ?",
    [$pgid]
);
if (!isset($userRows[0])) {
    die('❗ Utente non trovato');
}
$row = $userRows[0];

$curpos = $_POST['posizione'] ?? $row['posizione'];
if ((int)$row['admin'] === 1) {
    EnsureAdmin();
}
if ((!$row['admin'] && !$row['fatepower']) || $row['bloccato']) {
    die('❗ Non puoi accedere');
}

// ———————————————————————————
// 4) LISTE PER SELECT
// ———————————————————————————
// città
$listacity = [];
foreach ($db->query("SELECT * FROM city", []) as $c) {
    $listacity[$c['id']] = $c;
}
// posizioni distinte
$listapos = array_column(
    $db->query("SELECT DISTINCT posizione FROM utenti ORDER BY posizione", []),
    'posizione'
);
$wantedBoards=[];
foreach ($db->query('SELECT id,location,nome FROM luoghi ORDER BY location,nome') as $board) {
    if (player_board_permissions($db,(int)$board['id'],alphano_user())['allowed']) $wantedBoards[]=$board;
}

// ———————————————————————————
// 5) GESTIONE AZIONE
// ———————————————————————————
$msg = '';
switch ($_POST['azione'] ?? '') {
    case 'blocca':
        $target = GetPGID($_POST['pg'] ?? '');
        if (
            $target
            && $target !== $pgid
            && !empty($_POST['giorno'])
            && !empty($_POST['mese'])
            && !empty($_POST['anno'])
            && !empty($_POST['motivo'])
        ) {
            $fine = mktime(
                0,0,0,
                (int)$_POST['mese'],
                (int)$_POST['giorno'],
                (int)$_POST['anno']
            );
            $ok = LockUser($target, $fine, $_POST['motivo'], $pgid);
            $msg = $ok
                 ? 'Personaggio bloccato con successo'
                 : 'Impossibile bloccare l\'utente selezionato';
        } else {
            $msg = 'Parametri non esaurienti';
        }
        break;

    case 'sblocca':
        $toUnlock = (int)($_POST['pg'] ?? 0);
        if ($toUnlock && $toUnlock !== $pgid) {
            $ok = ForceUnlock($toUnlock, $pgid);
            $msg = $ok
                 ? 'Personaggio sbloccato con successo'
                 : 'Impossibile sbloccare il personaggio selezionato';
        } else {
            $msg = 'Parametri non esaurienti';
        }
        break;

    case 'imprigiona':
        $toJail = GetPGID($_POST['pg'] ?? '');
        $city   = (int)($_POST['city'] ?? 0);
        if ($toJail && $toJail !== $pgid && $city) {
            $ok = Incarcera($toJail, $city, $pgid);
            $msg = $ok
                 ? 'Personaggio imprigionato con successo'
                 : 'Impossibile imprigionare il personaggio selezionato';
        } else {
            $msg = 'Parametri non esaurienti';
        }
        break;

    case 'scarcera':
        $toFree = (int)($_POST['pg'] ?? 0);
        if ($toFree && $toFree !== $pgid) {
            $ok = Scarcera($toFree, $pgid);
            $msg = $ok
                 ? 'Personaggio scarcerato con successo'
                 : 'Impossibile scarcerare il personaggio selezionato';
        } else {
            $msg = 'Parametri non esaurienti';
        }
        break;

    case 'ricercato':
        $wanted = GetPGID($_POST['pg'] ?? '');
        $wantedBoard=(int)($_POST['bacheca'] ?? 0);
        if ($wanted && !empty($_POST['motivo']) && in_array($wantedBoard,array_map(static fn($board)=>(int)$board['id'],$wantedBoards),true)) {
            $ok = InserisciThread((string)$wantedBoard, $pgid, 'Ricercato: '.GetPGFromID((int)$wanted), (string)$_POST['motivo'], false);
            $msg = $ok
                 ? 'Ricercato inserito correttamente'
                 : 'Errore nell\'inserimento del ricercato';
        } else {
            $msg = 'Parametri non esaurienti';
        }
        break;
}

// ———————————————————————————
// 6) DATI PER LA VIEW
// ———————————————————————————
// personaggi in posizione
$pginpos = $db->query(
    "SELECT id,nome
       FROM utenti
      WHERE posizione = ?
        AND bloccato = 0
        AND admin    = 0
      ORDER BY nome",
    [$curpos]
);

// bloccati
if ($row['admin']) {
    $lockedpg = $db->query(
        "SELECT id,nome,lockby,motivo,datasblocco
           FROM utenti
          WHERE bloccato = 1
          ORDER BY nome",
        []
    );
} else {
    $lockedpg = $db->query(
        "SELECT id,nome,lockby,motivo,datasblocco
           FROM utenti
          WHERE bloccato = 1
            AND lockby = ?
          ORDER BY nome",
        [$pgid]
    );
}

// prigionieri
$prigione = [];
foreach ($db->query("SELECT id,nome,imprigionato FROM utenti WHERE imprigionato > 0", []) as $u) {
    $prigione[$u['imprigionato']][] = $u;
}

// ———————————————————————————
// 7) OUTPUT HTML
// ———————————————————————————
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Amministrazione Blocco Utenti <?= TitoloSito ?></title>


    <script src="/js/global.js"></script>

<?php if(count($lockedpg)): ?>
  <script>
    var lockby = {}, motivo = {}, datasblocco = {};
<?php foreach($lockedpg as $d): ?>
    lockby[<?=$d['id']?>]         = '<?= addslashes(GetPGFromID($d['lockby'])) ?>';
    motivo[<?=$d['id']?>]         = '<?= addslashes($d['motivo']) ?>';
    datasblocco[<?=$d['id']?>]    = '<?= FormatTimestamp($d['datasblocco']) ?>';
<?php endforeach; ?>
    function Info(id){
      document.getElementById('lockby').innerText      = lockby[id]      || '';
      document.getElementById('motivo').innerText      = motivo[id]      || '';
      document.getElementById('datasblocco').innerText = datasblocco[id] || '';
    }
  </script>
<?php endif; ?>
</head>
<body>
  <div class="moderation-sections">
<?php if($msg): ?>
    <div class="flash"><?= htmlentities($msg) ?></div>
<?php endif; ?>
    <section data-section="Blocco Utenti"><h2>Blocco Utenti</h2>

      <form method="POST" class="u-5fb9edaa1"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
        <select name="posizione" onchange="this.form.submit()">
<?php foreach($listapos as $p): ?>
          <option value="<?=$p?>"<?= $p===$curpos?' selected':'' ?>><?=$p?></option>
<?php endforeach; ?>
        </select>
      </form>
      <form method="POST" name="frmblocco" class="u-9548f4c53"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
        <input type="hidden" name="azione" value="blocca">
        <select name="pospg" onchange="this.form.pg.value=this.value">
          <option></option>
<?php foreach($pginpos as $u): ?>
          <option><?=htmlspecialchars($u['nome'])?></option>
<?php endforeach; ?>
        </select>
        <input type="text" name="pg" size="10" placeholder="o scrivi nome">
        <br><br>
        Data fine:
        <select name="giorno"><?php for($i=1;$i<=31;$i++): ?>
          <option value="<?=$i?>"><?=$i?></option>
        <?php endfor; ?></select>
        <select name="mese"><?php for($i=1;$i<=12;$i++): ?>
          <option value="<?=$i?>"><?=$i?></option>
        <?php endfor; ?></select>
        <select name="anno"><?php $y=date('Y'); for($i=$y;$i<=$y+4;$i++): ?>
          <option value="<?=$i?>"><?=$i?></option>
        <?php endfor; ?></select>
        <br>
        Motivo: <input type="text" name="motivo" size="40">
        <br><br>
        <button type="submit">Blocca</button>
      </form>


    <!-- Sblocco -->
    </section><section data-section="Sblocco Utenti"><h2>Sblocco Utenti</h2>
<?php if(count($lockedpg)): ?>

      <form method="POST" name="frmsblocco"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
        <input type="hidden" name="azione" value="sblocca">
        <select name="pg" onchange="Info(this.value)">
          <option></option>
<?php foreach($lockedpg as $u): ?>
          <option value="<?=$u['id']?>"><?=htmlspecialchars($u['nome'])?></option>
<?php endforeach; ?>
        </select>
        <span id="lockby"></span>
        <span id="motivo"></span>
        <span id="datasblocco"></span>
        <button type="submit">Sblocca</button>
      </form>

<?php else: ?>
    Nessun personaggio bloccato
<?php endif; ?>

    <!-- Imprigionamento -->
    </section><section data-section="Imprigiona Utenti"><h2>Imprigiona Utenti</h2>

      <form method="POST" name="frmimprigiona"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
        <input type="hidden" name="azione" value="imprigiona">
        <select name="pospg" onchange="this.form.pg.value=this.value">
          <option></option>
<?php foreach($pginpos as $u): ?>
          <option><?=htmlspecialchars($u['nome'])?></option>
<?php endforeach; ?>
        </select>
        <input type="text" name="pg" size="10" placeholder="o scrivi nome">
        <select name="city">
          <option></option>
<?php foreach($listacity as $c): ?>
          <option value="<?=$c['id']?>"><?=htmlspecialchars($c['city'])?></option>
<?php endforeach; ?>
        </select>
        <button type="submit">Imprigiona</button>
      </form>


    <!-- Scarceramento -->
    </section><section data-section="Scarcera Utenti"><h2>Scarcera Utenti</h2>
<?php if(count($prigione)): ?>

      <form method="POST" name="frmscarcera"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
        <input type="hidden" name="azione" value="scarcera">
        <select name="pg">
<?php foreach($prigione as $city=>$group): ?>
          <optgroup label="<?=htmlspecialchars($listacity[$city]['city'] ?? 'Città sconosciuta')?>">
<?php foreach($group as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['nome'])?></option>
<?php endforeach; ?>
          </optgroup>
<?php endforeach; ?>
        </select>
        <button type="submit">Scarcera</button>
      </form>

<?php else: ?>
    Nessun prigioniero
<?php endif; ?>

    <!-- Ricercato -->
    </section><section data-section="Inserisci Ricercato"><h2>Inserisci Ricercato</h2>

      <form method="POST" name="frmricercato"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
        <input type="hidden" name="azione" value="ricercato">
        <label>Personaggio<input type="text" name="pg" size="20" placeholder="nome PG" required></label>
        <label>Pubblica nella bacheca<select name="bacheca" required><option value="">Scegli la destinazione</option>
          <?php foreach ($wantedBoards as $board): ?><option value="<?=(int)$board['id']?>"><?=htmlspecialchars($board['location'].' · '.$board['nome'],ENT_QUOTES)?></option><?php endforeach; ?>
        </select></label>
        <label>Motivazione<textarea name="motivo" rows="3" cols="30" required></textarea></label>
        <br>
        <button type="submit">Inserisci Ricercato</button>
      </form>


  </section></div>
</body>
</html>
<?php ClosePage(); ?>
