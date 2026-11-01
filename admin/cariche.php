<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

require __DIR__ . '/../libs/kernel.inc.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require __DIR__ . '/../libs/libpg.inc.php';
require __DIR__ . '/../libs/libgrafica.inc.php';
require __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/activity.inc.php';



// ─── Inizializzazione ────────────────────────────────────────────────
$db      = ConnectDB();

$pgid    = GetIDPG(0, 1);

// Prendo flag admin / sindaco
$row     = $db->query("SELECT admin,sindaco FROM utenti WHERE id=?", [$pgid])[0] ?? [];
$admin   = (bool)$row['admin'];
$sindaco = (bool)$row['sindaco'];

// Controllo permessi
if ($admin) {
    EnsureAdmin();
    $idcity = 0;             // Admin vede tutti i rami
}
elseif ($sindaco) {
    // trovo la città di cui è sindaco
    $r = $db->query(
      "SELECT ct.id,ct.city
         FROM utenti u
         JOIN araldiche a ON a.id=u.araldica
         JOIN city ct ON ct.id=a.city
        WHERE u.id=?",
      [$pgid]
    )[0] ?? [];
    $idcity   = (int)($r['id']   ?? 0);
    $nomecity =        $r['city'] ?? '';
    if($idcity<1){http_response_code(403);exit('Mandato cittadino non valido.');}
}
else {
    http_response_code(403);
    die("Non puoi amministrare le araldiche");
}

// Lista delle città (per i select)
$listacity = [];
foreach ($db->query("SELECT id,city FROM city ORDER BY city") as $c) {
    $listacity[(int)$c['id']] = $c['city'];
}

// ─── Carico i rami ───────────────────────────────────────────────────
$rami = GetListaRamiCity($admin, $idcity);

// Se sindaco, carico i PG della sua città
$pgcity = [];
if ($sindaco) {
    foreach ($db->query(
      "SELECT u.id,u.nome
         FROM utenti u
         JOIN araldiche a ON a.id=u.araldica
        WHERE a.city=?
     ORDER BY u.nome",
      [$idcity]
    ) as $p) {
        $pgcity[(int)$p['id']] = $p['nome'];
    }
}

// ─── Gestione form POST ─────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$admin) {
        $action=(string)($_POST['azione'] ?? '');
        $subject=(int)($_POST['soggetto'] ?? 0);
        $table=str_contains($action,'ramo') ? 'ramiaraldiche' : 'araldiche';
        if ($subject>0 && !$db->query("SELECT 1 FROM {$table} WHERE id=? AND city=?",[$subject,$idcity])) {
            http_response_code(403); exit('Elemento non appartenente alla tua città');
        }
        if ($action==='changecarica' && (!$db->query('SELECT 1 FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE u.id=? AND a.city=?',[(int)($_POST['pg'] ?? 0),$idcity]) || !$db->query('SELECT 1 FROM araldiche WHERE id=? AND city=?',[(int)($_POST['carica'] ?? 0),$idcity]))) {
            http_response_code(403); exit('Personaggio o carica non appartenenti alla tua città');
        }
        $_POST['city']=$idcity; $_POST['cityramo']=$idcity;
    }
    if (in_array($_POST['azione'] ?? '',['addcarica','modifycarica2'],true) && !$db->query('SELECT 1 FROM ramiaraldiche WHERE id=? AND city=?',[(int)($_POST['ramo'] ?? 0),(int)($_POST['city'] ?? 0)])) {
        http_response_code(400); exit('Il ramo deve appartenere alla città della carica');
    }
    $activityAction=(string)($_POST['azione'] ?? '');
    $activitySubject=(int)($_POST['soggetto'] ?? 0);
    $activityTable=str_contains($activityAction,'ramo')?'ramiaraldiche':'araldiche';
    $activityCity=(int)($db->query("SELECT city FROM {$activityTable} WHERE id=?",[$activitySubject])[0]['city'] ?? 0);
    if (!$activityCity) $activityCity=(int)($activityAction==='addramo' ? ($_POST['cityramo'] ?? 0) : ($_POST['city'] ?? 0));
    if ($activityAction==='changecarica') $activityCity=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[(int)($_POST['carica'] ?? 0)])[0]['city'] ?? 0);
    $activityPlayer=$activityAction==='changecarica' ? ($admin ? (int)GetPGID((string)($_POST['nomepg'] ?? '')) : (int)($_POST['pg'] ?? 0)) : 0;
    $activityState=static function() use($db,$activityCity,$activityPlayer,$activitySubject): array {
        $state=['roles'=>$db->query('SELECT id,nome,city,prima,ramo,livello,descrizione FROM araldiche WHERE city=? ORDER BY id',[$activityCity]),
            'branches'=>$db->query('SELECT id,nome,city,livello FROM ramiaraldiche WHERE city=? ORDER BY id',[$activityCity]),
            'player'=>$activityPlayer ? ($db->query('SELECT araldica FROM utenti WHERE id=?',[$activityPlayer])[0] ?? null) : null];
        $badge=rtrim(htmlroot,'/').'/uploads/badges/cariche/'.$activitySubject.'.gif';
        $state['badge_hash']=$activitySubject && is_file($badge) ? hash_file('sha256',$badge) : null;
        return $state;
    };
    $activityBefore=$activityState();
    // checkbox “prima”
    $prima = !empty($_POST['prima']) ? 1 : 0;
    if ($prima) {
        $cnt = $db->query(
          "SELECT COUNT(*) AS tot FROM araldiche WHERE city=? AND prima=1 AND id<>?",
          [$admin ? (int)($_POST['city'] ?? 0) : $idcity,(int)($_POST['soggetto'] ?? 0)]
        )[0]['tot'] ?? 0;
        if ($cnt) $prima = 0;
    }

    switch ($_POST['azione'] ?? '') {

      // ─ Assegna una carica a PG ───────────────────────────────────
      case 'changecarica':
        $pgName = $admin
                ? trim($_POST['nomepg'] ?? '')
                : (int)($_POST['pg'] ?? 0);
        $cid    = (int)($_POST['carica'] ?? 0);

        if ($pgName && $cid && ($admin || $sindaco) && $db->query('SELECT 1 FROM araldiche WHERE id=?',[$cid])) {
            require_once __DIR__.'/../libs/admin-governance.inc.php';
            $target=$admin?(int)GetPGID((string)$pgName):(int)$pgName;
            try{alphano_governance_apply((int)$pgid,$target,['araldica'=>$cid],'Assegnazione dal pannello araldiche',null,'citta',$idcity);$msg='Araldica assegnata con successo';}
            catch(DomainException $e){http_response_code($e->getCode());$msg=$e->getMessage();}
        } else {
            $msg = "Parametri non esaurienti";
        }
        break;

      // ─ Elimina una carica ───────────────────────────────────────
      case 'dropcarica':
        $cid = (int)($_POST['soggetto'] ?? 0);
        if ($cid && $db->query('SELECT 1 FROM utenti WHERE araldica=? LIMIT 1',[$cid])) {
            $msg='La carica è ancora assegnata: assegna prima un’altra araldica ai personaggi';
        } elseif ($cid) {
            $db->execute("DELETE FROM araldiche WHERE id=? LIMIT 1", [$cid]);
            @unlink(rtrim(htmlroot, '/') . "/uploads/badges/cariche/{$cid}.gif");
            $db->execute("UPDATE utenti SET araldica=0 WHERE araldica=?", [$cid]);
            $msg = "Carica cancellata con successo";
        }
        break;

      // ─ Mostra form modifica carica ──────────────────────────────
      case 'modifycarica':
        $modify = $db->query(
          "SELECT * FROM araldiche WHERE id=?",
          [(int)($_POST['soggetto'] ?? 0)]
        )[0] ?? null;
        break;

      // ─ Salva modifica / aggiunge nuova carica ────────────────────
      case 'modifycarica2':
      case 'addcarica':
        $fn = $_POST['azione']==='modifycarica2'
            ? 'ModifyAraldica'
            : 'AddAraldica';
        $args=[
          (string)($_POST['araldica'] ?? ''), (bool)$prima,
          (int)($_POST['city'] ?? 0), (string)($_POST['descrizione'] ?? ''),
          (int)($_POST['ramo'] ?? 0), (int)($_POST['livellocarica'] ?? 0),
          $_FILES['immagine'] ?? ['error'=>UPLOAD_ERR_NO_FILE]
        ];
        if ($fn==='ModifyAraldica') array_unshift($args,(int)($_POST['soggetto'] ?? 0));
        $msg=$fn(...$args);
        break;

      // ─ Elimina un ramo ─────────────────────────────────────────
      case 'dropramo':
        $rid = (int)($_POST['soggetto'] ?? 0);
        if ($rid && $db->query('SELECT 1 FROM araldiche WHERE ramo=? LIMIT 1',[$rid])) {
            $msg='Il ramo contiene ancora araldiche';
        } elseif ($rid) {
            $db->execute("DELETE FROM ramiaraldiche WHERE id=? LIMIT 1", [$rid]);
            $msg = "Ramo cancellato con successo";
        }
        break;

      // ─ Mostra form modifica ramo ────────────────────────────────
      case 'modifyramo':
        $modifyramo = $db->query(
          "SELECT * FROM ramiaraldiche WHERE id=?",
          [(int)($_POST['soggetto'] ?? 0)]
        )[0] ?? null;
        break;

      // ─ Salva modifica / aggiungi ramo ───────────────────────────
      case 'modifyramo2':
      case 'addramo':
        $sql = $_POST['azione']==='modifyramo2'
             ? "UPDATE ramiaraldiche SET nome=?,city=?,livello=? WHERE id=?"
             : "INSERT INTO ramiaraldiche (nome,city,livello) VALUES (?,?,?)";
        $params = $_POST['azione']==='modifyramo2'
                ? [$_POST['nome'], (int)$_POST['cityramo'], (int)$_POST['livelloramo'], (int)$_POST['soggetto']]
                : [$_POST['nome'], (int)$_POST['cityramo'], (int)$_POST['livelloramo']];
        $db->query($sql, $params);
        $msg = $_POST['azione']==='modifyramo2' ? "Ramo modificato con successo" : "Ramo inserito con successo";
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activityAfter=$activityState();
    $activityTitles=['changecarica'=>'Araldica assegnata','dropcarica'=>'Araldica eliminata','modifycarica2'=>'Araldica aggiornata','addcarica'=>'Araldica creata',
        'dropramo'=>'Ramo araldico eliminato','modifyramo2'=>'Ramo araldico aggiornato','addramo'=>'Ramo araldico creato'];
    if ($activityCity>0 && isset($activityTitles[$activityAction]) && $activityBefore!==$activityAfter) {
        $section=str_contains($activityAction,'ramo')?'rami-araldici':'araldiche';
        $audiences=[['scope'=>'citta','id'=>$activityCity,'section'=>$section,'role'=>'admin']];
        if ($activityPlayer>0) $audiences[]=['scope'=>'personale','id'=>$activityPlayer,'section'=>'araldica','role'=>'member'];
        $summarize=static function(array $state): array {
            foreach (['roles','branches'] as $key) $state[$key]=['count'=>count($state[$key]),'hash'=>hash('sha256',json_encode($state[$key]))];
            return $state;
        };
        alphano_record_activity('heraldry_'.$activityAction,(int)$pgid,$audiences,$activityTitles[$activityAction],$msg,
            ['city'=>$activityCity,'subject'=>$activitySubject,'player'=>$activityPlayer,'before'=>$summarize($activityBefore),'after'=>$summarize($activityAfter)]);
    }
}

// ─── Rileggo tutto per la vista ────────────────────────────────────
// elenco araldiche
$araldiche = $db->query(
    $admin
  ? "SELECT a.*, r.nome AS ramo
       FROM araldiche a
       LEFT JOIN ramiaraldiche r ON r.id=a.ramo AND r.city=a.city
   ORDER BY a.nome"
  : "SELECT a.*, r.nome AS ramo
       FROM araldiche a
       LEFT JOIN ramiaraldiche r ON r.id=a.ramo AND r.city=a.city
      WHERE a.city=?
   ORDER BY a.nome",
    $admin ? [] : [$idcity]
);

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Amministrazione Araldiche – <?=htmlspecialchars(TitoloSito,ENT_QUOTES)?></title>


  <script>
    function doAzione(azione, soggetto='') {
      const f = document.forms['frm'];
      f.azione.value = azione;
      f.soggetto.value = soggetto;
      f.submit();
    }
  </script>
</head>
<body>
  <div class="container">
    <main class="content">

      <?php if ($msg): ?>
        <div class="flash"><?=htmlspecialchars($msg,ENT_QUOTES)?></div>
      <?php endif; ?>

      <!-- ═══ Assegna Carica ════════════════════════════════════════════ -->
      <section>
        <h2>Assegna Araldica a PG</h2>
        <form id="frm" name="frm" method="post" enctype="multipart/form-data">
          <input type="hidden" name="azione">
          <input type="hidden" name="soggetto">
          <table class="panel-table">
            <tr>
              <th>Personaggio</th>
              <th>Carica</th>
              <th>Azione</th>
            </tr>
            <tr>
              <td>
                <?php if ($admin): ?>
                  <input type="text" name="nomepg" placeholder="Nome PG">
                <?php else: ?>
                  <select name="pg">
                    <option value="">– seleziona PG –</option>
                    <?php foreach ($pgcity as $pid=>$pn): ?>
                      <option value="<?=$pid?>"><?=htmlspecialchars($pn,ENT_QUOTES)?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </td>
              <td>
                <select name="carica">
                  <option value="">– seleziona araldica –</option>
                  <?php foreach ($araldiche as $a): ?>
                    <option value="<?=$a['id']?>"><?=htmlspecialchars($a['nome'],ENT_QUOTES)?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><button type="button" onclick="doAzione('changecarica')">Assegna</button></td>
            </tr>
          </table>
        </form>
      </section>

      <!-- ═══ Elenco Araldiche ═════════════════════════════════════════ -->
      <section>
        <h2 id="araldiche">Lista Araldiche</h2>
        <table class="panel-table">
          <tr>
            <th>Icona</th><th>Araldica</th><th>Prima</th><th>Città</th>
            <th>Stipendio</th><th>Descrizione</th><th>Ramo</th><th>Livello</th><th>Azioni</th>
          </tr>
          <?php foreach ($araldiche as $a): ?>
          <tr>
            <td><?=processAraldica((int)$a['id'])?></td>
            <td><?=htmlspecialchars($a['nome'],ENT_QUOTES)?></td>
            <td><?=$a['prima']? '✔️':''?></td>
            <td><?=htmlspecialchars($listacity[$a['city']] ?? '',ENT_QUOTES)?></td>
            <td><?= (int)$a['stipendio'] ?> <?=alphano_currency_html()?></td>
            <td><?=ProcessText($a['descrizione'])?></td>
            <td><?=htmlspecialchars((string)($a['ramo']??'Senza ramo'),ENT_QUOTES)?></td>
            <td><?= (int)$a['livello'] ?></td>
            <td>
              <button onclick="doAzione('modifycarica','<?=$a['id']?>')">Modifica</button><br>
              <button onclick="if(confirm('Sei sicuro?'))doAzione('dropcarica','<?=$a['id']?>')">Elimina</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </section>

      <!-- ═══ Aggiungi / Modifica Araldica ═══════════════════════════════ -->
      <?php
        // se ho cliccato “modifycarica” ho $modify, altrimenti default empty
        $m = $modify ?? [
          'id'=>0,'nome'=>'','prima'=>0,'city'=>($sindaco?$idcity:0),
          'descrizione'=>'','ramo'=>0,'livello'=>0
        ];
      ?>
      <section>
        <h2><?= !empty($modify) ? 'Modifica Araldica' : 'Aggiungi Araldica' ?></h2>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="azione" value="<?= !empty($modify)?'modifycarica2':'addcarica' ?>">
          <input type="hidden" name="soggetto" value="<?=$m['id']?>">
          <table class="panel-table">
            <tr><th>Icona</th><td><input type="file" name="immagine"></td></tr>
            <tr><th>Nome</th><td><input type="text" name="araldica" required value="<?=htmlspecialchars($m['nome'],ENT_QUOTES)?>"></td></tr>
            <tr><th>Prima</th><td><input type="checkbox" name="prima" <?=$m['prima']?'checked':''?>></td></tr>
            <tr><th>Città</th>
              <td>
                <?php if ($admin): ?>
                  <select name="city">
                    <option value="">–</option>
                    <?php foreach($listacity as $cid=>$cn): ?>
                      <option value="<?=$cid?>" <?=$cid==$m['city']?'selected':''?>><?=htmlspecialchars($cn,ENT_QUOTES)?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <?=$nomecity?><input type="hidden" name="city" value="<?=$idcity?>">
                <?php endif; ?>
              </td>
            </tr>
            <tr><th>Descrizione</th><td><textarea name="descrizione" rows="3"><?=htmlspecialchars($m['descrizione'],ENT_QUOTES)?></textarea></td></tr>
            <tr><th>Ramo</th>
              <td>
                <select name="ramo" required>
                  <option value="">– seleziona ramo –</option>
                  <?php foreach($rami as $city=>$group): ?>
                    <optgroup label="<?=htmlspecialchars($listacity[$city]??'',ENT_QUOTES)?>">
                      <?php foreach($group as $rid=>$rinfo): ?>
                      <option value="<?=$rid?>" <?=$rid==$m['ramo']?'selected':''?>>
                        <?=htmlspecialchars($rinfo['nome'],ENT_QUOTES)?>
                      </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr><th>Livello</th><td><input type="number" name="livellocarica" value="<?=$m['livello']?>" required></td></tr>
            <tr><td colspan="2" class="u-72a68ac34">
              <button type="submit"><?= !empty($modify)?'Salva Modifiche':'Aggiungi Carica' ?></button>
            </td></tr>
          </table>
        </form>
      </section>

      <!-- ═══ Elenco Rami ════════════════════════════════════════════════ -->
      <section>
        <h2 id="rami-araldici">Lista Rami</h2>
        <table class="panel-table">
          <tr><th>Nome</th><th>Città</th><th>Livello</th><th>Azioni</th></tr>
          <?php foreach($rami as $city=>$group): ?>
            <?php foreach($group as $rid=>$rinfo): ?>
            <tr>
              <td><?=htmlspecialchars($rinfo['nome'],ENT_QUOTES)?></td>
              <td><?=htmlspecialchars($listacity[$rinfo['city']]??'',ENT_QUOTES)?></td>
              <td><?= (int)$rinfo['livello'] ?></td>
              <td>
                <button onclick="doAzione('modifyramo','<?=$rid?>')">Modifica</button>
                <button onclick="if(confirm('Eliminare ramo?'))doAzione('dropramo','<?=$rid?>')">Elimina</button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </table>
      </section>

      <!-- ═══ Aggiungi / Modifica Ramo ═══════════════════════════════════ -->
      <?php
        $r = $modifyramo ?? ['id'=>0,'nome'=>'','city'=>($sindaco?$idcity:0),'livello'=>0];
      ?>
      <section>
        <h2><?= !empty($modifyramo)?'Modifica Ramo':'Aggiungi Ramo' ?></h2>
        <form method="post">
          <input type="hidden" name="azione" value="<?= !empty($modifyramo)?'modifyramo2':'addramo' ?>">
          <input type="hidden" name="soggetto" value="<?=$r['id']?>">
          <table class="panel-table">
            <tr><th>Nome</th><td><input type="text" name="nome" required value="<?=htmlspecialchars($r['nome'],ENT_QUOTES)?>"></td></tr>
            <tr><th>Città</th>
              <td>
                <?php if ($admin): ?>
                  <select name="cityramo" required>
                    <option value="">–</option>
                    <?php foreach($listacity as $cid=>$cn): ?>
                      <option value="<?=$cid?>" <?=$cid==$r['city']?'selected':''?>><?=htmlspecialchars($cn,ENT_QUOTES)?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <?=$nomecity?><input type="hidden" name="cityramo" value="<?=$idcity?>">
                <?php endif; ?>
              </td>
            </tr>
            <tr><th>Livello</th><td><input type="number" name="livelloramo" value="<?=$r['livello']?>" required></td></tr>
            <tr><td colspan="2" class="u-72a68ac34">
              <button type="submit"><?= !empty($modifyramo)?'Salva Modifiche':'Aggiungi Ramo' ?></button>
            </td></tr>
          </table>
        </form>
      </section>

    </main>
  </div>
</body>
</html>

<?php ClosePage(); ?>
