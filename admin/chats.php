<?php
/************************************************************
 *  admin/chats.php – Amministrazione Chat (PHP 8, PDO)
 *  luglio 2025
 ************************************************************/
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libblocco.inc.php';
require_once __DIR__ . '/../libs/libchat.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';

use Alphano\Database\PDOMySQLDriver;


/*──────────────────────────────────────────────────────────
  Debug flag                                                 */
function isDebug(): bool {
    return isset($_GET['debug']) && ($_GET['debug']==='1' || strcasecmp($_GET['debug'],'on')===0);
}

/*──────────────────────────────────────────────────────────
  HTML-escape helper                                         */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

/*──────────────────────────────────────────────────────────
  Sessione & permessi                                        */
alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
initSession();
$idpg = GetIDPG(false);

// carico permessi
$perm = db()->query(
    "SELECT admin, fatepower, adminaccademia FROM utenti WHERE id = ?",
    [$idpg]
)[0] ?? ['admin'=>0,'fatepower'=>0,'adminaccademia'=>0];

$IS_ADMIN     = (bool)$perm['admin'];
$IS_MASTER    = (bool)$perm['fatepower'];
$IS_ACCADEMIA = (bool)$perm['adminaccademia'];

if ($IS_ADMIN) {
    EnsureAdmin();
} elseif (! $IS_MASTER && ! $IS_ACCADEMIA) {
    die('Non puoi accedere a questa pagina');
}



/**
 * Restituisce la lista di stanze chat:
 *  1) chat_key da mappe (solo record con chat=1)
 *  2) nomi da tabella `chat`
 *  3) nomi da `utenti.chat`
 *
 * @param bool $admin se true include anche pvt
 * @return string[] elenco univoco, ordinato
 */
function listaChat(bool $admin): array {
    $db    = db();
    $chats = [];

    // 1) chat_key dalla tabella mappe
    $rows0 = $db->query(
        "SELECT chat_key AS chat
           FROM mappe
          WHERE chat = 1"
    );
    foreach ($rows0 as $r) {
        $chats[] = $r['chat'];
    }

    // 2) nomi da tabella chat
    $sql1 = $admin
        ? "SELECT DISTINCT chat FROM chat ORDER BY chat"
        : "SELECT DISTINCT chat FROM chat WHERE chat NOT LIKE '%pvt%' ORDER BY chat";
    foreach ($db->query($sql1, []) as $r) {
        $chats[] = $r['chat'];
    }

    // 3) nomi da tabella utenti
    $sql2 = $admin
        ? "SELECT DISTINCT chat FROM utenti WHERE chat<>'' ORDER BY chat"
        : "SELECT DISTINCT chat FROM utenti WHERE chat<>'' AND chat NOT LIKE '%pvt%' ORDER BY chat";
    foreach ($db->query($sql2, []) as $r) {
        $chats[] = $r['chat'];
    }

    // unifica, ordina e restituisci
    $chats = array_unique($chats);
    sort($chats, SORT_STRING|SORT_FLAG_CASE);
    return $chats;
}

$LISTA_CHAT = listaChat($IS_ADMIN);

/*──────────────────────────────────────────────────────────
  Variabili form                                              */
$P    = $_POST;
$act  = $P['action_main'] ?? '';
$st   = trim($P['stanza'] ?? '');
$msg  = '';
$lv   = [];  // azioni-lavoro
$md   = [];  // record in modifica
$av   = [];  // avviso stanza

/*──────────────────────────────────────────────────────────
  Gestione azioni                                             */
switch ($act) {
 case 'save_board_games':
  require_once __DIR__.'/../libs/board-games.inc.php';
  if(!$IS_ADMIN)alphano_fail('Riservato alla gestione.',403);
  bg_save_room($idpg,$st,array_map(static fn($key)=>isset($_POST[$key]),array_combine(['scacchi','dama','coins','items','materials'],['scacchi','dama','coins','items','materials'])));
  $msg='Giochi e poste del luogo aggiornati.';break;


  // Svuota intera chat
  case 'svuota_chat':
    if ($st && ($IS_ADMIN || $IS_MASTER)) {
      $msg = SvuotaChat($idpg, $st)
           ? "✅ Chat “{$st}” svuotata."
           : "❌ Errore nello svuotamento di “{$st}”.";
    } else {
      $msg = "Seleziona una chat valida.";
    }
    break;

  // Rimuovi TUTTI i PG da stanza
  case 'rimuovi_tutti_pg':
    if ($st && ($IS_ADMIN || $IS_MASTER)) {
      db()->execute("UPDATE utenti SET posizione='off', chat='' WHERE chat=?", [$st]);
      $msg = "✅ Tutti i PG sono stati tolti da “{$st}”.";
    } else {
      $msg = "Seleziona una chat valida.";
    }
    break;

  // Rimuovi singolo PG
  case 'rimuovi_pg':
    $pid = (int)($P['pg_id'] ?? 0);
    if ($pid && ($IS_ADMIN || $IS_MASTER)) {
      db()->execute("UPDATE utenti SET posizione='off', chat='' WHERE id=?", [$pid]);
      $msg = "✅ PG rimosso.";
    } else {
      $msg = "PG non valido o permessi insufficienti.";
    }
    $st = $P['stanza'] ?? '';
    break;

  // Work edits have a single permission-checked and audited home.
  case 'show_lavori': case 'add_lavoro': case 'edit_lavoro': case 'save_lavoro': case 'del_lavoro':
    if(!$IS_ADMIN&&!$IS_ACCADEMIA)alphano_fail('La gestione dei lavori è riservata ad amministratori e responsabili dell’Accademia.',403);
    header('Location: /admin/lavori.php?chat='.rawurlencode($st ?: (string)($P['chat']??'')));exit;

  // Mostra avviso stanza
  case 'show_avviso':
    if ($st && $IS_ADMIN) {
      $av = db()->query("SELECT * FROM avvisi WHERE chat=? LIMIT 1", [$st])[0] ?? [];
    }
    break;

  // Salva/cancella avviso
  case 'save_avviso':
    $chat  = trim($P['chat'] ?? '');
    $txt   = trim($P['txt']  ?? '');
    if ($chat && $IS_ADMIN && mb_strlen($txt)<=255) {
      db()->beginTransaction();
      db()->execute("DELETE FROM avvisi WHERE chat=?", [$chat]);
      if ($txt !== '') {
        db()->execute("INSERT INTO avvisi(chat,testo) VALUES(?,?)", [$chat,$txt]);
        $msg = "✅ Avviso salvato.";
      } else {
        $msg = "✅ Avviso cancellato.";
      }
      db()->commit();
      $st = $chat;
      $av = db()->query("SELECT * FROM avvisi WHERE chat=? LIMIT 1", [$st])[0] ?? [];
    } else {
      $msg = "Parametro avviso mancante.";
    }
    break;
}

/*──────────────────────────────────────────────────────────*/
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Admin Chat – <?=h(TitoloSito)?></title>

</head>
<body>

<?php if(isDebug()): ?>
  <div class="debug">
DEBUG $_POST:
<?=print_r($P,true)?>
<?php if($md) echo "\nDEBUG \$md:\n".print_r($md,true); ?>
  </div>
<?php endif; ?>

<?php if($msg!==''): ?>
  <table><tr><td class="Val"><?=h($msg)?></td></tr></table>
<?php endif; ?>


<?php if($IS_ADMIN&&$st!==''):require_once __DIR__.'/../libs/board-games.inc.php';if(bg_ready()):$boardRoom=bg_room($st); ?>
<section class="bg-stats-card"><h2>Giochi da tavolo del luogo</h2><p>Disponibilità e poste consentite. Le regole generali si configurano nella <a href="/admin/giochi.php">gestione centralizzata dei giochi</a>.</p><form method="post"><input type="hidden" name="action_main" value="save_board_games"><input type="hidden" name="stanza" value="<?=h($st)?>"><?php foreach(['scacchi'=>'Scacchi','dama'=>'Dama italiana','coins'=>alphano_currency_text('Poste in Corone'),'items'=>'Poste in oggetti','materials'=>'Poste in materiali'] as $k=>$label): ?><label class="bg-check"><input type="checkbox" name="<?=$k?>" value="1" <?=!empty($boardRoom[$k])?'checked':''?>><?=htmlspecialchars($label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></label><?php endforeach; ?><button>Salva giochi del luogo</button></form></section>
<?php endif;endif; ?>
<!-- FORM Principale -->
<form id="main" method="post"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
  <input type="hidden" name="action_main">
  <table>
    <tr><th>Seleziona chat</th><th>Operazioni</th></tr>
    <tr>
      <td>
        <select name="stanza" onchange="setMain('select_chat')">
          <option value=""></option>
          <?php foreach($LISTA_CHAT as $c): ?>
            <option value="<?=h($c)?>" <?=$c===$st?'selected':''?>><?=h($c)?></option>
          <?php endforeach;?>
        </select>
      </td>
      <td>
        <button type="button" class="red-trash" onclick="setMain('svuota_chat')">🗑️ Svuota chat</button><br>
        <button type="button" onclick="setMain('rimuovi_tutti_pg')">🧑‍🤝‍🧑 Rimuovi tutti PG</button><br>
        <button type="button" onclick="setMain('show_avviso')">✉️ Avviso stanza</button><br>
        <?php if($IS_ADMIN||$IS_ACCADEMIA): ?><button type="button" onclick="setMain('show_lavori')">Lavori e materie prime</button><?php endif; ?>
      </td>
    </tr>
  </table>
</form>
<script>
function setMain(a){
  let f=document.getElementById('main');
  f['action_main'].value=a;
  f.submit();
}
</script>

<?php if($st): ?>
  <h2 data-section="Presenti">PG in “<?=h($st)?>”</h2>
  <?php $pgs = db()->query("SELECT id,nome FROM utenti WHERE chat=? ORDER BY nome", [$st]); ?>
  <?php if(count($pgs)): ?>
    <table>
      <tr><th>Nome</th><th>Rimuovi</th></tr>
      <?php foreach($pgs as $p): ?>
        <tr>
          <td><?=h($p['nome'])?></td>
          <td>
            <form method="post" class="u-434fc32ec"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
              <input type="hidden" name="action_main" value="rimuovi_pg">
              <input type="hidden" name="stanza" value="<?=h($st)?>">
              <input type="hidden" name="pg_id" value="<?=h($p['id'])?>">
              <button type="submit">❌</button>
            </form>
          </td>
        </tr>
      <?php endforeach;?>
    </table>
  <?php endif;?>
<?php endif; ?>

<?php if($IS_ADMIN||$IS_ACCADEMIA): ?><section><h2>Lavori e materie prime</h2><p>Attività, probabilità, materiali, quote e storico sono riuniti nel pannello dedicato.</p><a class="action-btn" href="/admin/lavori.php?chat=<?=rawurlencode($st)?>">Apri Lavori e materie prime</a></section><?php endif; ?>

<?php if($av || ($IS_ADMIN && $st && $act==='show_avviso')): ?>
  <h2 data-section="Avviso" <?= $act==='show_avviso' ? 'data-ui-open' : '' ?>>Avviso per “<?=h($st)?>”</h2>
  <form method="post"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
    <input type="hidden" name="action_main" value="save_avviso">
    <input type="hidden" name="chat" value="<?=h($st)?>">
    <table>
      <tr><td>Testo (HTML ok)</td><td><textarea name="txt" rows="3"><?=h($av['testo'] ?? '')?></textarea></td></tr>
      <tr><td colspan="2" class="u-72a68ac34"><button type="submit">💾 Salva</button></td></tr>
    </table>
  </form>
<?php endif; ?>

</body>
</html>
