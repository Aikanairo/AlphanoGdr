<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libadmin.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';


use Alphano\Database\PDOMySQLDriver;

alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();

$idpg    = GetIDPG(0, 1) ?: die('<h1>Accesso negato</h1>');
$db      = ConnectDB();

// — Privilegi —
$user = $db->query('SELECT admin, adminaccademia FROM utenti WHERE id = ?', [$idpg])[0] ?? null;
if (!$user['admin'] && !$user['adminaccademia']) {
    die('<h1>Accesso negato</h1><p>Non hai i privilegi necessari a visualizzare questa pagina.</p>');
}
if ((int)$user['admin'] === 1) {
    EnsureAdmin();
}

// — Lista tabelle logs_… —
$tables = [];
foreach ($db->query('SHOW TABLES', []) as $r) {
    $t = reset($r);
    if (str_starts_with($t, 'logs_')) {
        $tables[] = $t;
    }
}

// — Gestione azioni —
$campi   = [];
$righe   = [];
$results = [];
$tabella = $_POST['tabella'] ?? '';

switch ($_POST['aztartarcontrol'] ?? '') {
    case 'getfields':
        if (in_array($tabella, $tables, true)) {
            foreach ($db->query("SHOW FIELDS FROM `$tabella`", []) as $f) {
                $field = $f['Field'];
                $vals  = $db->query("SELECT DISTINCT `$field` FROM `$tabella`", []);
                $campi[$field] = array_column($vals, $field);
                foreach ($campi[$field] as $v) {
                    $righe[] = [$field => $v];
                }
            }
            list($idpgs, $idobjs, $idcitys, $idorgs) = Smista($righe);
        }
        break;

    case 'getdata':
        if (in_array($tabella, $tables, true)) {
            $conds = [];
            $params = [];
            foreach ($db->query("SHOW FIELDS FROM `$tabella`", []) as $f) {
                $field = $f['Field'];
                $vals  = $db->query("SELECT DISTINCT `$field` FROM `$tabella`", []);
                $campi[$field] = array_column($vals, $field);
                foreach ($campi[$field] as $v) {
                    $righe[] = [$field => $v];
                }
                if (!empty($_POST[$field])) {
                    $conds[] = "`$field` = ?";
                    $params[] = (string)$_POST[$field];
                }
            }
            list($idpgs, $idobjs, $idcitys, $idorgs) = Smista($righe);
            $sql = 'SELECT * FROM `' . $tabella . '`'
                 . (count($conds) ? ' WHERE '. implode(' AND ', $conds) : '');
            $results = $db->query($sql . ' ORDER BY data DESC LIMIT 1000', $params);
        }
        break;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Log Viewer – <?= TitoloSito ?></title>


  <script src="/js/global.js"></script>
  <script>
    function Cambia(tabella) {
      const f = document.getElementById('frmFields');
      f.tabella.value = tabella;
      f.submit();
    }
  </script>
</head>
<body>

  <h1 class="Valore">Log Viewer</h1>

  <!-- Form nascosto per SHOW FIELDS -->
  <form id="frmFields" method="POST" class="u-f2892a2e8"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
    <input type="hidden" name="aztartarcontrol" value="getfields">
    <input type="hidden" name="tabella" value="">
  </form>

  <div class="layout">
    <aside class="sidebar">
      <h2 class="Valore">Tabelle di log</h2>
      <?php foreach ($tables as $t): ?>
        <div class="Valore">
          <a href="#" onclick="Cambia('<?= htmlspecialchars($t) ?>')">
            <?= htmlspecialchars($t) ?>
          </a>
        </div>
      <?php endforeach; ?>
    </aside>

    <main class="content">
      <?php if ($tabella): ?>
        <h2 class="Valore">Tabella: <?= htmlspecialchars($tabella) ?></h2>

        <form id="frmFilter" method="POST" class="u-f267e3ca6"><?= '<input type="hidden" name="_csrf" value="' . htmlspecialchars(alphano_csrf_token(), ENT_QUOTES) . '">' ?>
          <input type="hidden" name="aztartarcontrol" value="getdata">
          <input type="hidden" name="tabella" value="<?= htmlspecialchars($tabella) ?>">

          <table class="Valore u-0bc69d14b">
            <tr>
              <?php foreach ($campi as $field => $_): ?>
                <th><?= htmlspecialchars($field) ?></th>
              <?php endforeach; ?>
              <th></th>
            </tr>
            <tr>
              <?php foreach ($campi as $field => $vals): ?>
                <td class="u-72a68ac34">
                  <?php if (count($vals)>1): ?>
                    <select onchange="this.form['<?= $field ?>'].value=this.value">
                      <option value=""></option>
                      <?php foreach ($vals as $v): ?>
                        <option value="<?= htmlspecialchars((string)$v) ?>"
                          <?= (($_POST[$field]??'')===$v ? 'selected':'') ?>>
                          <?= Converti($field, $v, $idpgs, $idobjs, $idcitys, $idorgs, false) ?>
                        </option>
                      <?php endforeach; ?>
                    </select><br>
                  <?php else: ?>
                    <?= Converti($field, $vals[0], $idpgs, $idobjs, $idcitys, $idorgs, false) ?>
                  <?php endif; ?>
                  <input type="text" name="<?= htmlspecialchars($field) ?>"
                         value="<?= htmlspecialchars($_POST[$field] ?? '') ?>">
                </td>
              <?php endforeach; ?>
              <td><button type="submit">Filtra</button></td>
            </tr>
          </table>
        </form>

        <?php if ($results): ?>
          <table class="Valore u-0bc69d14b">
            <tr>
              <?php foreach ($campi as $field => $_): ?>
                <th><?= htmlspecialchars($field) ?></th>
              <?php endforeach; ?>
            </tr>
            <?php foreach ($results as $row): ?>
              <tr>
                <?php foreach ($campi as $field => $_): ?>
                  <td><?= Converti($field, $row[$field], $idpgs, $idobjs, $idcitys, $idorgs, true) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php else: ?>
          <p class="Valore">Nessun dato trovato o nessuna ricerca effettuata.</p>
        <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>

<?php
// — Helpers —

// trasforma codici in etichette, con link JS se $html=true
function Converti(
    string $campo,
    $dato,
    array $idpgs,
    array $idobjs,
    array $idcitys,
    array $idorgs,
    bool $html
): string {
    switch ($campo) {
        case 'esecutore':
        case 'destinatario':
        case 'origine':
        case 'pg':
            $label = $idpgs[$dato] ?? (string)$dato;
            return $html
                 ? "<a href=\"javascript:openProfileId('".$dato."')\">$label</a>"
                 : $label;
        case 'nome':
            return $html
                 ? "<a href=\"javascript:openProfile('".$dato."')\">".htmlspecialchars($dato)."</a>"
                 : htmlspecialchars((string)$dato);
        case 'obj':  return $idobjs[$dato]  ?? (string)$dato;
        case 'data': return FormatTimestamp((int)$dato);
        case 'city': return $idcitys[$dato] ?? (string)$dato;
        case 'org':  return $idorgs[$dato]  ?? (string)$dato;
        default:     return htmlspecialchars((string)$dato);
    }
}

// raccoglie tutti i valori distinti in $righe e costruisce le mappe id→label
function Smista(array $righe): array {
    $db = ConnectDB();
    $idpgs=[]; $idobjs=[]; $idcitys=[]; $idorgs=[];
    foreach ($righe as $r) {
        foreach (['esecutore','destinatario','origine','pg'] as $f) {
            if (!empty($r[$f])) $idpgs[] = $r[$f];
        }
        if (!empty($r['obj']))  $idobjs[]  = $r['obj'];
        if (!empty($r['city'])) $idcitys[] = $r['city'];
        if (!empty($r['org']))  $idorgs[]  = $r['org'];
    }
    $fetchMap = function(array $ids, string $table, string $field, string $labelCol) use ($db) {
        $map = [];
        $ids = array_values(array_unique($ids));
        if (!$ids) return $map;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->query(
            "SELECT `$field`,`$labelCol` FROM `$table` WHERE `$field` IN ($ph)",
            $ids
        );
        foreach ($rows as $row) {
            $map[$row[$field]] = $row[$labelCol];
        }
        return $map;
    };
    return [
        $fetchMap($idpgs,   'utenti',         'id','nome'),
        $fetchMap($idobjs,  'equip',          'id','shortDesc'),
        $fetchMap($idcitys, 'city',           'id','city'),
        $fetchMap($idorgs,  'organizzazioni', 'id','nome'),
    ];
}

ClosePage();
