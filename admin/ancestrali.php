<?php
declare(strict_types=1);

/********************************************************
 * admin/ancestrali.php
 * Pannello Ancestrali – trasformazioni, morso & infettare
 * PHP 8 – luglio 2025
 ********************************************************/

/* ===== 1) Bootstrap ===== */
require_once __DIR__ . '/../libs/kernel.inc.php';     // initSession(), ConnectDB()
require_once __DIR__ . '/../libs/libpg.inc.php';      // GetIDPG()
require_once __DIR__ . '/../libs/libchat.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__.'/../libs/lycan.inc.php';
require_once __DIR__.'/../libs/transformations.inc.php';



alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
initSession();
$idpg = GetIDPG(false);
if (!$idpg) {
    header('Location:/login.php');
    exit;
}
$db = ConnectDB();
// carico il tuo record
$user = $db->query(
    "SELECT *
       FROM utenti
      WHERE id = ?",
    [$idpg]
)[0] ?? null;
$transformation=$user?alphano_transformation_state($user):[];
$canGrant=$user&&alphano_lycan_can_grant($user);
if (!$user || (empty($transformation['visible'])&&!$canGrant)) {
    http_response_code(403);
    exit('Accesso negato');
}
$chatRoom   = $user['chat'];
$playerName = $user['nome'];


/* ===== 3) Transizioni validate anche sul server ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['azione'] ?? '');
    $fields = ['infetta'=>'pg_i','senzienza'=>'pg_s','ancestrale'=>'pg_a'];
    $db->beginTransaction();
    try {
        $user = $db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE', [$idpg])[0];
        $current=alphano_transformation_state($user);
        $who = htmlspecialchars($playerName, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        if ($action === 'trasforma') {
            if(empty($current['canChoose']))throw new DomainException('La trasformazione non può essere scelta da questo personaggio.',403);
            $form=$_POST['form']??$current['nextForm'];
            if(!is_string($form))throw new DomainException('Forma non valida.',422);
            alphano_transformation_sync($idpg,$form);
            $html=null; // The shared transition already wrote the announcement.
        } elseif (isset($fields[$action])) {
            if(!alphano_lycan_can_grant($user))throw new DomainException('Questi doni appartengono soltanto alla stirpe ancestrale.',403);
            if($chatRoom===''||$user['chat']!==$chatRoom)throw new InvalidArgumentException('Entra nella stessa chat del personaggio prima di usare i doni ancestrali.');
            $target = (int)($_POST[$fields[$action]] ?? 0);
            $t = $db->query('SELECT * FROM utenti WHERE id=? AND chat=? FOR UPDATE', [$target,$chatRoom])[0] ?? null;
            if (!$t || $target === $idpg) throw new InvalidArgumentException('Scegli un personaggio presente nella stessa chat');
            $name = htmlspecialchars($t['nome'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
            if ($action === 'infetta') {
                if($db->query('SELECT id FROM sessioni WHERE chat=? AND run=1 LIMIT 1',[$chatRoom]))throw new InvalidArgumentException('La scena è gestita dal Master: dichiara in chat il tentativo di mordere e trasmettere l’infezione. Il contagio segue il morso riuscito, senza saltare i turni.');
                alphano_lycan_infect($user,$target,$chatRoom,$playerName.' trasmette il contagio con un morso ancestrale.',$idpg);
                $html = "$who affonda i denti nella carne di <strong>$name</strong> e vi trasmette la maledizione. Il corpo resta umano, ma la prima Luna Piena imporrà la metamorfosi in Licantropo, contro la sua volontà. Se la Luna è già piena, il richiamo agisce subito.";
            } elseif ($action === 'senzienza') {
                if (!$t['infetto'] || $t['senziente']) throw new InvalidArgumentException('Il personaggio non può ricevere la senzienza');
                $db->execute('UPDATE utenti SET senziente=1 WHERE id=?', [$target]);
                $html = "$who ha concesso la <strong>senzienza</strong> a <strong>$name</strong>.";
            } else {
                if ($t['ancestrale']) throw new InvalidArgumentException('Il personaggio è già ancestrale');
                $db->execute('UPDATE utenti SET ancestrale=1 WHERE id=?', [$target]);
                $html = "$who ha donato l'<strong>ancestralità</strong> a <strong>$name</strong>.";
            }
        } else throw new InvalidArgumentException('Azione non valida');
        if ($html!==null) InsertIntoChat($chatRoom, '<DIV class="MasterKey">'.$html.'</DIV>');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $status=$e instanceof DomainException&&($action==='trasforma'||$e->getCode()===403)?(int)$e->getCode():400;
        http_response_code($status>=400&&$status<=499?$status:400);exit(htmlspecialchars($e->getMessage(), ENT_QUOTES));
    }
    header('Location: /admin/ancestrali.php'); exit;
}

/* ===== 4) Ricarica utente & candidati ===== */
$user = $db->query(
    "SELECT * FROM utenti WHERE id=?",
    [$idpg]
)[0];
$transformation=alphano_transformation_state($user);
$canGrant=alphano_lycan_can_grant($user);

// Standard human form plus legacy humans; no existing lineage or infection.
$toInf = $canGrant&&$chatRoom!==''?$db->query(
    "SELECT id,nome FROM utenti
       WHERE chat=?
         AND razza IN(1,?)
         AND infetto=0
         AND senziente=0
         AND ancestrale=0
         AND puntif>0 AND bloccato=0
         AND id<>?",
    [$chatRoom,alphano_human_race_id(),$idpg]
):[];

// candidati Senzienza
$toSZ = $canGrant&&$chatRoom!==''?$db->query(
    "SELECT id,nome FROM utenti WHERE chat=? AND infetto=1 AND senziente=0",
    [$chatRoom]
):[];

// candidati Ancestralità
$toAN = $canGrant&&$chatRoom!==''?$db->query(
    "SELECT id,nome FROM utenti WHERE chat=? AND ancestrale=0",
    [$chatRoom]
):[];
?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>La metamorfosi – <?=htmlspecialchars($playerName,ENT_QUOTES)?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

</head>
<body>

  <h1>La metamorfosi</h1>

  <!-- Trasformazione -->
  <section class="panel">
    <div class="title">Forma attuale: <?=$transformation['form']==='human'?'umana':'nativa'?></div>
    <p><?=htmlspecialchars($transformation['description'],ENT_QUOTES)?></p>
    <?php if(!empty($transformation['canChoose'])): ?>
    <form method="post"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
      <input type="hidden" name="azione" value="trasforma">
      <input type="hidden" name="form" value="<?=htmlspecialchars($transformation['nextForm'],ENT_QUOTES)?>">
      <button type="submit" data-icon="moon"><?=htmlspecialchars($transformation['label'],ENT_QUOTES)?></button>
    </form>
    <?php endif; ?>
  </section>

  <?php if($canGrant&&$chatRoom===''): ?><section class="panel"><h2>I doni della stirpe ancestrale</h2><p>Entra in una chat per usare i doni ancestrali sui personaggi presenti.</p><a class="action-btn" href="/mappa.php" data-icon="compass">Scegli un luogo</a></section><?php endif; ?>

  <!-- Il potere appartiene alla stirpe ancestrale in entrambe le forme. -->
  <?php if (count($toInf)>0): ?>
  <section class="panel">
    <div class="title">Mordere &amp; Infettare</div>
    <p>Trasmetti la maledizione a un umano: alla prima Luna Piena diventerà un Licantropo senza poterlo scegliere. Durante una sessione dichiara il morso in chat e attendi l’esito del Master.</p>
    <form method="post"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
      <input type="hidden" name="azione" value="infetta">
      <label for="pg_i">Scegli PG:</label>
      <select id="pg_i" name="pg_i" required>
        <option value="">– seleziona –</option>
        <?php foreach($toInf as $r):?>
          <option value="<?= (int)$r['id']?>"><?=htmlspecialchars($r['nome'],ENT_QUOTES)?></option>
        <?php endforeach;?>
      </select>
      <button type="submit">Mordi &amp; Infetta</button>
    </form>
  </section>
  <?php endif;?>

  <!-- Concedi Senzienza -->
  <?php if(count($toSZ)>0): ?>
  <section class="panel">
    <div class="title">Concedi Senzienza</div>
    <form method="post"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
      <input type="hidden" name="azione" value="senzienza">
      <label for="pg_s">Scegli PG:</label>
      <select id="pg_s" name="pg_s" required>
        <option value="">– seleziona –</option>
        <?php foreach($toSZ as $r):?>
          <option value="<?= (int)$r['id']?>"><?=htmlspecialchars($r['nome'],ENT_QUOTES)?></option>
        <?php endforeach;?>
      </select>
      <button type="submit">Dai Senzienza</button>
    </form>
  </section>
  <?php endif;?>

  <!-- Concedi Ancestralità -->
  <?php if(count($toAN)>0): ?>
  <section class="panel">
    <div class="title">Concedi Ancestralità</div>
    <form method="post"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
      <input type="hidden" name="azione" value="ancestrale">
      <label for="pg_a">Scegli PG:</label>
      <select id="pg_a" name="pg_a" required>
        <option value="">– seleziona –</option>
        <?php foreach($toAN as $r):?>
          <option value="<?= (int)$r['id']?>"><?=htmlspecialchars($r['nome'],ENT_QUOTES)?></option>
        <?php endforeach;?>
      </select>
      <button type="submit">Dai Ancestralità</button>
    </form>
  </section>
  <?php endif;?>

</body>
</html>
