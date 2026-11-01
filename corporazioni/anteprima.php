<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';


use Alphano\Database\PDOMySQLDriver;

GetIDPG(0);
$pdo = ConnectDB();

// 1) Carica la mappa dei tipi
$tipi = array_column($pdo->query('SELECT id, nome FROM tipo'), 'nome', 'id');

// 2) Costruisci l’array $oggetto a partire da POST
$oggetto = [];
$oggetto['immagine'] = filter_input(INPUT_POST, 'imgurl', FILTER_SANITIZE_URL) ?? '';
$params = $_POST;
unset($params['azione'], $params['imgurl']);
foreach ($params as $k => $v) {
    $oggetto[$k] = is_string($v) ? stripslashes($v) : $v;
}

// 3) Estrarre e sanificare i campi
$shortDesc     = htmlspecialchars($oggetto['shortDesc'] ?? '', ENT_QUOTES, 'UTF-8');
$desc          = nl2br(htmlspecialchars($oggetto['desc'] ?? '', ENT_QUOTES, 'UTF-8'));
$tipoLabel     = $tipi[(int)($oggetto['tipo'] ?? 0)] ?? '';
$taglia        = getSize((int)($oggetto['taglia'] ?? 0));
$peso          = (int)($oggetto['peso'] ?? 0);
$mani          = (int)($oggetto['mani'] ?? 0);
$tipoG         = htmlspecialchars($oggetto['tipoG'] ?? '', ENT_QUOTES, 'UTF-8');
$sacro         = !empty($oggetto['sacro']);
$restore       = (int)($oggetto['restore'] ?? 0);
$idCreatore    = (int)($oggetto['idcreatore'] ?? 0);
$lavorazione   = htmlspecialchars($oggetto['lavorazione'] ?? '', ENT_QUOTES, 'UTF-8');
$destinatario  = htmlspecialchars($oggetto['destinatario'] ?? '', ENT_QUOTES, 'UTF-8');
$destorg       = (int)($oggetto['destorg'] ?? 0);
$durata        = (int)($oggetto['durata'] ?? 0);
$qualityLabel  = htmlspecialchars(getQuality($durata), ENT_QUOTES, 'UTF-8');

// 4) Materie prime
$mp      = GetMateriePrime($oggetto) ?: [];
$materie = array_keys($mp);
$valori  = array_values($mp);

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Anteprima Oggetto • <?= TitoloSito ?></title>


</head>
<body>
  <div class="container-fluid py-4">
    <h1 class="mb-4">Anteprima Oggetto</h1>
    <div class="table-responsive">
      <table class="table table-dark table-bordered">
        <tr class="table-secondary text-center">
          <th colspan="4">Informazioni Oggetto</th>
        </tr>
        <tr>
          <td class="text-center"><?= $shortDesc ?></td>
          <td class="text-center">
            <img
              src="<?= htmlspecialchars($oggetto['immagine'], ENT_QUOTES) ?>"
              alt="Immagine Oggetto"
              onload="if(this.width>150)this.width=150">
          </td>
          <td><?= $desc ?></td>
          <td>
            <strong>Tipo:</strong> <?= $tipoLabel ?><br>
            <strong>Taglia:</strong> <?= $taglia ?><br>
            <?php if ($peso > 0): ?><strong>Peso:</strong> <?= $peso ?> Kg<br><?php endif; ?>
            <?php if ($mani > 0 && $tipoG === 'Arma'): ?><strong>Mani:</strong> <?= $mani ?><br><?php endif; ?>
            <?php if ($sacro): ?><img src="<?= $magicimg ?>" alt=""> Oggetto Sacro<br><?php endif; ?>
            <?php if ($restore > 0): ?><strong>Ripristina PF:</strong> <?= $restore ?><br><?php endif; ?>
            <?php if ($idCreatore):
              $nome = GetPGFromID($idCreatore);
            ?>
              <strong>Creatore:</strong>
              <a href="#" onclick="openProfileId('<?= $idCreatore ?>')">
                <?= htmlspecialchars($nome, ENT_QUOTES) ?>
              </a><br>
            <?php endif; ?>
            <?php if ($lavorazione): ?><strong>Lavorazione:</strong> <?= $lavorazione ?><br><?php endif; ?>
            <?php if (!empty($oggetto['pers'])): ?>
              <?php if ($destinatario): ?><strong>Creato per:</strong> <?= $destinatario ?><br><?php endif; ?>
              <?php if ($destorg): ?><strong>Creato per Org:</strong> <?= htmlspecialchars(GetOrg($destorg), ENT_QUOTES) ?><br><?php endif; ?>
            <?php endif; ?>
            <strong>Qualità:</strong> <?= $qualityLabel ?><br>
            <strong>Durata:</strong> <?= $durata ?><br>
          </td>
        </tr>
        <tr>
          <td colspan="4">
            <table class="table table-dark">
              <tr>
                <?php foreach ($materie as $m): ?>
                  <th class="text-center"><?= htmlspecialchars($m, ENT_QUOTES) ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <?php foreach ($valori as $v): ?>
                  <td class="text-center"><?= htmlspecialchars((string)$v, ENT_QUOTES) ?></td>
                <?php endforeach; ?>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>
  </div>

</body>
</html>
<?php
ClosePage();
