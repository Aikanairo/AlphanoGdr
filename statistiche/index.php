<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';



// ─── Bootstrap ───────────────────────────────────────────────────────
$db     = ConnectDB();
$pgid   = GetIDPG(false, true);


// ─── 1. Personaggi ──────────────────────────────────────────────────
// Totali e attuali
$tab   = $db->query("SHOW TABLE STATUS LIKE 'utenti'")[0] ?? [];
$max   = (int)($db->query('SELECT COUNT(*) AS n FROM utenti')[0]['n'] ?? 0);
$tot   = (int)(($tab['Auto_increment'] ?? 1) - 1);
// Nuovi 7gg e cancellati 7gg
$since7 = GeneratePastTimestamp(7);
$reg7   = $db->query("SELECT COUNT(*) AS num FROM utenti WHERE dataregistrazione>=? AND ultimoingresso>0", [$since7])[0]['num'] ?? 0;
$del7   = $db->query("SELECT COUNT(*) AS num FROM recuperopg")[0]['num'] ?? 0;
// Maschi, femmine, online
$numM  = $db->query("SELECT COUNT(*) AS num FROM utenti WHERE sesso='Maschio'")[0]['num'] ?? 0;
$maschi= round(100 * $numM / max($max,1), 2);
$femmine= 100 - $maschi;
$online= $db->query("SELECT COUNT(*) AS num FROM utenti WHERE posizione!='off'")[0]['num'] ?? 0;

// ─── 2. Chat ────────────────────────────────────────────────────────
$pos = [];
$q = "SELECT chat,posizione,COUNT(*) AS tot
        FROM utenti
       WHERE chat<>''".(IsAdmin($pgid)?"":" AND chat NOT LIKE 'pvt%'")."
    GROUP BY chat,posizione";
foreach ($db->query($q) as $d) {
    $place = str_replace(" (Spettatore)","",$d['posizione']);
    if (strpos($d['posizione'], "(Spettatore)")!==false) {
        $pos[$place]['spett'] = $d['tot'];
    } else {
        $pos[$place]['tot']   = $d['tot'];
    }
    $pos[$place]['sessione'] = count($db->query(
        "SELECT 1 FROM sessioni WHERE run=1 AND chat=?",
        [$d['chat']]
    )) > 0;
}

// ─── 3. Razze ───────────────────────────────────────────────────────
$razze = [];
foreach ($db->query(
    "SELECT razza,COUNT(*) AS tot
       FROM utenti
      WHERE infetto=0 AND ancestrale=0
   GROUP BY razza"
) as $d) {
    $k = (int)$d['razza'];
    $razze[$k] = round(100 * $d['tot'] / max($max,1), 2);
}
// licantropi (3) e ancestrali (6)
foreach ([[3,'infetto'], [6,'ancestrale']] as [$k,$col]) {
    $n = $db->query("SELECT COUNT(*) AS tot FROM utenti WHERE $col=1")[0]['tot'] ?? 0;
    $razze[$k] = ($razze[$k] ?? 0) + round(100 * $n / max($max,1), 2);
}

// ─── 4. Etnie ───────────────────────────────────────────────────────
$etnie = [];
foreach ($db->query(
    "SELECT etnia,COUNT(*) AS tot
       FROM utenti
      WHERE etnia>0
   GROUP BY etnia"
) as $d) {
    $k = (int)$d['etnia'];
    $etnie[$k] = round(100 * $d['tot'] / max($max,1), 2);
}

// ─── 5. Allineamenti ─────────────────────────────────────────────────
$alline = [];
foreach ($db->query(
    "SELECT allineamento,COUNT(*) AS tot
       FROM utenti
   GROUP BY allineamento"
) as $d) {
    $k = (string)$d['allineamento'];
    // ProcessAl expects string
    $label = ProcessAl((string)$k);
    $alline[$label] = round(100 * $d['tot'] / max($max,1), 2);
}

// ─── 6. Organizzazioni, Corporazioni, Città ─────────────────────────
$org = [];
foreach ($db->query(
    "SELECT o.id,o.nome,COUNT(u.id) AS tot
       FROM utenti u
       JOIN organizzazioni o ON o.id=u.organizzazione
      WHERE o.approvato=1
   GROUP BY u.organizzazione"
) as $d) {
    $org[] = [
        'id'   => $d['id'],
        'nome' => $d['nome'],
        'pct'  => round(100 * $d['tot'] / max($max,1), 2),
    ];
}

$corp = [];
foreach ($db->query(
    "SELECT c.id,c.nome,COUNT(u.id) AS tot
       FROM utenti u
       JOIN corporazioni c ON c.id=u.corporazione
      WHERE c.approvato=1
   GROUP BY u.corporazione"
) as $d) {
    $corp[] = [
        'id'   => $d['id'],
        'nome' => $d['nome'],
        'pct'  => round(100 * $d['tot'] / max($max,1), 2),
    ];
}

$city = [];
foreach ($db->query(
    "SELECT ct.id,ct.city AS nome,COUNT(u.id) AS tot
       FROM utenti u
       JOIN araldiche a ON a.id=u.araldica
       JOIN city ct ON ct.id=a.city
   GROUP BY ct.id"
) as $d) {
    $city[] = [
        'id'   => $d['id'],
        'nome' => $d['nome'],
        'pct'  => round(100 * $d['tot'] / max($max,1), 2),
    ];
}

// ─── 7. Messaggi in bacheca ────────────────────────────────────────
$res = $db->query("SELECT DISTINCT(bacheca) AS b FROM bacheche ORDER BY bacheca");
$numb = count($res);
$msgs = $rpls = 0;
foreach ($res as $r) {
    $b = $r['b'];
    $msgs += count($db->query("SELECT 1 FROM bacheche WHERE replyid=0 AND bacheca=?", [$b]));
    $rpls += count($db->query("SELECT 1 FROM bacheche WHERE replyid<>0 AND bacheca=?", [$b]));
}

// ─── 8. Visite Profili ─────────────────────────────────────────────
$visite = $db->query(
    "SELECT id,nome,razza,sesso,visite
       FROM utenti
      WHERE visite>0
   ORDER BY visite DESC
      LIMIT 5"
);

// ─── 9. Oggi Registrati ────────────────────────────────────────────
$oggi = $db->query(
    "SELECT id,nome,razza,sesso
       FROM utenti
      WHERE dataregistrazione>=?
   ORDER BY nome",
    [GeneratePastTimestamp(0)]
);

// ─── 10. Da Cancellare ─────────────────────────────────────────────
$canc = $db->query(
    "SELECT id,nome,razza,sesso
       FROM utenti
      WHERE (((ultimoingresso<=? AND vacanza<>'1') OR ultimoingresso<=?)
             OR (ultimoingresso=0 AND dataregistrazione<=?))
        AND admin<>'1'
   ORDER BY nome",
    [GeneratePastTimestamp(59), GeneratePastTimestamp(89), GeneratePastTimestamp(6)]
);

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Statistiche – <?=htmlspecialchars(TitoloSito,ENT_QUOTES)?></title>

</head>
<body>
  <div class="container">
    <nav class="nav">
      <ul>
        <li><a href="#personaggi">Personaggi</a></li>
        <li><a href="#chat">Chat</a></li>
        <li><a href="#razze">Razze</a></li>
        <li><a href="#etnie">Etnie</a></li>
        <li><a href="#allineamenti">Allineamenti</a></li>
        <li><a href="#organizzazioni">Organizzazioni</a></li>
        <li><a href="#corporazioni">Corporazioni</a></li>
        <li><a href="#citta">Città</a></li>
        <li><a href="#messaggi">Messaggi</a></li>
        <li><a href="#visite">Visite Profili</a></li>
        <li><a href="#oggi">Oggi Registrati</a></li>
        <li><a href="#cancellare">Da Cancellare</a></li>
      </ul>
    </nav>
    <main class="content">

      <h2 id="personaggi">Personaggi</h2>
      <table class="ui-data-table">
        <tr><td class="Descrizione">Totale da inizio:</td><td class="Valore"><?=$tot?></td></tr>
        <tr><td class="Descrizione">Attualmente registrati:</td><td class="Valore"><?=$max?></td></tr>
        <tr><td class="Descrizione">Registrati ultimi 7 gg:</td><td class="Valore"><?=$reg7?></td></tr>
        <tr><td class="Descrizione">Cancellati ultimi 7 gg:</td><td class="Valore"><?=$del7?></td></tr>
        <tr><td class="Descrizione"><a href="/ricerca/?sesso=Maschio">Maschi</a>:</td><td class="Valore"><?=$maschi?>%</td></tr>
        <tr><td class="Descrizione"><a href="/ricerca/?sesso=Femmina">Femmine</a>:</td><td class="Valore"><?=$femmine?>%</td></tr>
        <tr><td class="Descrizione"><a href="/ricerca/?online=Si">Online</a>:</td><td class="Valore"><?=$online?></td></tr>
      </table>

      <h2 id="chat">Chat</h2>
      <table class="ui-data-table">
        <?php if($pos): foreach($pos as $place=>$info): ?>
        <tr>
          <td class="Descrizione">
            <?php if($info['sessione']): ?><span class="ui-cell-meta"><strong>Quest in corso</strong></span><?php endif; ?>
            <a href="/ricerca/?luogo=<?=urlencode($place)?>">
              <?=htmlspecialchars($place,ENT_QUOTES)?>
            </a>
          </td>
          <td class="Valore">
            <?=($info['tot']??0)?> gioc.
            <?= isset($info['spett']) ? '/ '.$info['spett'].' spett.' : '' ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="2" class="Valore">Nessuna chat</td></tr>
        <?php endif; ?>
      </table>

      <h2 id="razze">Razze</h2>
      <table class="ui-data-table">
        <?php foreach($razze as $k=>$pct): ?>
        <tr>
          <td class="Descrizione">
            <a href="/ricerca/?razza=<?=$k?>"><?=htmlspecialchars(GetRazza($k),ENT_QUOTES)?></a>:
          </td>
          <td class="Valore"><?=$pct?>%</td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="etnie">Etnie</h2>
      <table class="ui-data-table">
        <?php foreach($etnie as $k=>$pct): ?>
        <tr>
          <td class="Descrizione">
            <a href="/ricerca/?etnia=<?=$k?>"><?=htmlspecialchars(GetEtnia($k),ENT_QUOTES)?></a>:
          </td>
          <td class="Valore"><?=$pct?>%</td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="allineamenti">Allineamenti</h2>
      <table class="ui-data-table">
        <?php foreach($alline as $label=>$pct): ?>
        <tr>
          <td class="Descrizione"><?=$label?>:</td>
          <td class="Valore"><?=$pct?>%</td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="organizzazioni">Organizzazioni</h2>
      <table class="ui-data-table">
        <?php foreach($org as $o): ?>
        <tr>
          <td class="Descrizione">
            <a href="/ricerca/?organizzazione=<?=$o['id']?>">
              <?=htmlspecialchars($o['nome'],ENT_QUOTES)?>
            </a>:
          </td>
          <td class="Valore"><?=$o['pct']?>%</td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="corporazioni">Corporazioni</h2>
      <table class="ui-data-table">
        <?php foreach($corp as $c): ?>
        <tr>
          <td class="Descrizione">
            <a href="/ricerca/?corporazione=<?=$c['id']?>">
              <?=htmlspecialchars($c['nome'],ENT_QUOTES)?>
            </a>:
          </td>
          <td class="Valore"><?=$c['pct']?>%</td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="citta">Città</h2>
      <table class="ui-data-table">
        <?php foreach($city as $c): ?>
        <tr>
          <td class="Descrizione">
            <a href="/ricerca/?city=<?=$c['id']?>">
              <?=htmlspecialchars($c['nome'],ENT_QUOTES)?>
            </a>:
          </td>
          <td class="Valore"><?=$c['pct']?>%</td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="messaggi">Messaggi</h2>
      <table class="ui-data-table">
        <tr><td class="Descrizione">Discussioni (<?=$numb?> bacheche):</td><td class="Valore"><?=$msgs?></td></tr>
        <tr><td class="Descrizione">Risposte:</td><td class="Valore"><?=$rpls?></td></tr>
      </table>

      <h2 id="visite">Visite Profili</h2>
      <table class="ui-data-table">
        <?php foreach($visite as $v): ?>
        <tr>
          <td class="Descrizione">
            <?=processRazza($v['razza'],$v['sesso'])?>
            <a href="#" onclick="openProfileId('<?=$v['id']?>')">
              <?=htmlspecialchars($v['nome'],ENT_QUOTES)?>
            </a>:
          </td>
          <td class="Valore"><?=$v['visite']?></td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="oggi">Oggi Registrati</h2>
      <table class="ui-data-table">
        <?php foreach($oggi as $o): ?>
        <tr>
          <td class="Valore">
            <?=processRazza($o['razza'],$o['sesso'])?>
            <a href="#" onclick="openProfileId('<?=$o['id']?>')">
              <?=htmlspecialchars($o['nome'],ENT_QUOTES)?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>

      <h2 id="cancellare">Da Cancellare</h2>
      <table class="ui-data-table">
        <?php foreach($canc as $c): ?>
        <tr>
          <td class="Valore">
            <?=processRazza($c['razza'],$c['sesso'])?>
            <a href="#" onclick="openProfileId('<?=$c['id']?>')">
              <?=htmlspecialchars($c['nome'],ENT_QUOTES)?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>

    </main>
  </div>
</body>
</html>
<?php ClosePage(); ?>
