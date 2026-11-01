<?php
declare(strict_types=1);
require __DIR__ . '/../libs/kernel.inc.php';
require __DIR__ . '/../libs/libpg.inc.php';
require __DIR__ . '/../libs/sendmail.inc.php';

use Alphano\Database\PDOMySQLDriver;

initSession();
$idpg = GetIDPG(false);
$db   = ConnectDB();

// Controllo permessi admin
$res     = $db->query("SELECT adminaccademia FROM utenti WHERE id = ?", [$idpg]);
$isAdmin = $idpg && alphano_can('adminaccademia');

// Pulisce le segnalazioni chiuse da >30gg
$db->query("DELETE FROM bug WHERE data < ? AND stato IN (2,3)", [time() - 30*86400]);

// Configurazione
$sezioni = [1=>'Sistema',2=>'Contenuti',3=>'Miglioramenti'];
$stati   = [0=>'Pendente',1=>'Preso in Carico',2=>'Corretto',3=>'Respinto'];

$msg     = '';
$results = null;

// Attivazione via link email
if (!empty($_GET['id']) && str_contains($_GET['id'],'@')) {
    [$bugId,$hash] = explode('@', $_GET['id'], 2) + [null,null];
    if ($bugId && $hash) {
        $r    = $db->query("SELECT descrizione FROM bug WHERE id=? AND attivato=0", [$bugId]);
        $desc = $r[0]['descrizione'] ?? '';
        if ($desc && md5($desc) === $hash) {
            $db->query("UPDATE bug SET attivato=1 WHERE id=?", [$bugId]);
            $msg = "Segnalazione #{$bugId} attivata. Grazie!";
        } else {
            $msg = "Link di attivazione non valido o scaduto.";
        }
    }
}

// Gestione POST
if ($_SERVER['REQUEST_METHOD']==='POST') {
    alphano_require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'admin') {
        if (!$isAdmin) alphano_fail('Permesso negato.',403);
        $bugId=(int)($_POST['id'] ?? 0);
        $subaction=(string)($_POST['subaction'] ?? '');
        if ($subaction === 'prendiincarico') {
            $db->execute('UPDATE bug SET stato=1,admin=? WHERE id=? AND attivato=1 AND stato=0',[$idpg,$bugId]);
            $msg='Segnalazione presa in carico.';
        } else alphano_fail('Azione non valida.',400);
    }
    if ($action==='search') {
        // where dinamico
        $where=[]; $params=[];
        $where[]="attivato=1";
        if (!empty($_POST['descrizione'])) {
            $terms=explode(' ',trim($_POST['descrizione']));
            $clauses=[];
            foreach($terms as $i=>$t){
                $clauses[] = "descrizione LIKE ?";
                $params[]  = "%{$t}%";
            }
            $where[] = '('.implode(' AND ',$clauses).')';
        }
        if (!empty($_POST['sezione']) && isset($sezioni[$_POST['sezione']])) {
            $where[]  = "sezione=?";
            $params[] = (int)$_POST['sezione'];
        }
        if (isset($_POST['stato']) && $_POST['stato']!=='') {
            $where[]  = "stato=?";
            $params[] = (int)$_POST['stato'];
        }
        $sql = "SELECT * FROM bug WHERE ".implode(' AND ',$where)." ORDER BY id DESC";
        $results = $db->query($sql,$params);
        if (!$results) $msg="Nessuna segnalazione trovata.";
    }
    if ($action==='insert') {
        $email = filter_var($_POST['email'] ?? '',FILTER_VALIDATE_EMAIL);
        $pg    = $idpg;
        $sec   = (int)($_POST['sezione']??0);
        $desc  = trim($_POST['descrizione']??'');
        if (($pg||$email)&&isset($sezioni[$sec])&&$desc!==''&&strlen($desc)<=100000) {
            $att = $pg?1:0;
            $db->query(
              "INSERT INTO bug(idpg,email,data,attivato,sezione,descrizione)
               VALUES(?,?,?,?,?,?)",
              [$pg ?? 0,$email ?: '',time(),$att,$sec,$desc]
            );
            $newId = (int)$db->lastInsertId();
            if ($att) {
                $msg="Segnalazione inviata. Grazie!";
            } else {
                $h    = md5($desc);
                $link = (isset($_SERVER['HTTPS'])?'https':'http')
                      ."://{$_SERVER['HTTP_HOST']}/bug/index.php?id={$newId}@{$h}";
                SendBugActivation($email,$link);
                $msg="Controlla la tua email per attivare la segnalazione.";
            }
        } else {
            $msg="Compila tutti i campi obbligatori.";
        }
    }
}
?><!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Segnalazione Bug – <?=htmlspecialchars(TitoloSito)?></title>
  <!-- Carica un unico CSS globale che contiene anche i bug-card styles -->

  <!-- Carica un unico JS globale che gestisce menu, news, card, ecc. -->
  <script src="/js/main.js" defer></script>
</head>
<body>

<header>
  <h1>Segnalazione Bug</h1>
  <?php if($msg): ?>
    <div class="flash"><?=htmlspecialchars($msg)?></div>
  <?php endif; ?>
</header>

<!-- ATTENZIONE: main DEVE avere class="wrapper" -->
<main class="wrapper">

  <!-- CARD: ricerca -->
  <section class="card">
    <h2>Cerca Segnalazioni</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="search">

      <label>
        Descrizione
        <input type="text"
               name="descrizione"
               placeholder="Parole chiave"
               value="<?=htmlspecialchars($_POST['descrizione']??'')?>">
      </label>

      <label>
        Sezione
        <select name="sezione">
          <option value="">Tutte</option>
          <?php foreach($sezioni as $k=>$v): ?>
            <option value="<?=$k?>"<?= (($_POST['sezione']??'')==$k?' selected':'')?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Stato
        <select name="stato">
          <option value="">Tutti</option>
          <?php foreach($stati as $k=>$v): ?>
            <option value="<?=$k?>"<?= (($_POST['stato']??'')==="$k"?' selected':'')?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button class="btn">Cerca</button>
    </form>

    <?php if(is_array($results)): ?>
      <div class="results">
        <?php foreach($results as $b): ?>
          <article class="result">
            <div class="r-header">
              <span>#<?= $b['id'] ?></span>
              <span><?= date('d/m/Y H:i',$b['data']) ?></span>
            </div>
            <p class="r-desc"><?= nl2br(htmlspecialchars($b['descrizione'])) ?></p>
            <div class="r-meta">
              <span><?= $sezioni[$b['sezione']] ?></span> |
              <span><?= $stati[$b['stato']] ?></span>
              <?php if($isAdmin && $b['stato']==='0'): ?>
                <form method="post" class="inline" onsubmit="return confirm('Prendi in carico?')">
                  <input type="hidden" name="action" value="admin">
                  <input type="hidden" name="subaction" value="prendiincarico">
                  <input type="hidden" name="id" value="<?=$b['id']?>">
                  <button type="submit" class="btn btn-primary">Prendi</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- CARD: inserimento -->
  <section class="card">
    <h2>Nuova Segnalazione</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="insert">
      <?php if(!$idpg): ?>
        <label>
          Email*
          <input type="email" name="email" required placeholder="tuo@indirizzo.it">
        </label>
      <?php endif; ?>

      <label>
        Sezione*
        <select name="sezione" required>
          <option value="">– seleziona –</option>
          <?php foreach($sezioni as $k=>$v): ?>
            <option value="<?=$k?>"><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="full">
        Descrizione*
        <textarea name="descrizione" rows="4" required placeholder="Descrivi il bug..."></textarea>
      </label>

      <button class="btn btn-primary">Invia Segnalazione</button>
    </form>
  </section>

</main>

<footer>
  &copy; <?=date('Y')?> <?=htmlspecialchars(TitoloSito)?>
</footer>

</body>
</html>
