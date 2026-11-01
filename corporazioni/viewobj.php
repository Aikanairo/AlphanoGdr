<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/libaccademia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';

use Alphano\Database\PDOMySQLDriver;


$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}

// PDO connection
$db = getDB();

// Fetch and validate object ID
$objid = filter_input(INPUT_GET, 'obj', FILTER_VALIDATE_INT);
if (!$objid) {
    http_response_code(400);
    die('Nessun oggetto selezionato');
}

// Load object details
$row = $db->query(
    'SELECT equip.*, tipo.nome AS tipo
       FROM equip
       JOIN tipo    ON tipo.id = equip.tipo
      WHERE equip.id = ?',
    [$objid]
);
$oggetto = $row[0] ?? null;
if (!$oggetto) {
    http_response_code(404);
    die('Oggetto non trovato');
}

// Helpers
function h(string $s): string { return htmlentities($s, ENT_QUOTES, 'UTF-8'); }


?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dettagli Oggetto – <?= TitoloSito ?></title>

  <script>
    document.addEventListener('DOMContentLoaded', ()=>{
      // ridimensiona eventuali immagini troppo grandi
      document.querySelectorAll('.card-image img').forEach(img=>{
        if(img.naturalWidth>600) img.style.width='100%';
      });
    });
  </script>
</head>
<body>
  <div class="container">
    <h1>Informazioni Oggetto</h1>

    <div class="card">
      <div class="card-image">
        <img src="<?= h($oggetto['immagine']) ?>" alt="Immagine di <?= h($oggetto['shortDesc']) ?>">
      </div>
      <div class="card-content">
        <h2><?= h($oggetto['shortDesc']) ?></h2>
        <p><?= ProcessText($oggetto['desc']) ?></p>

        <ul>
          <li><strong>Tipo:</strong> <?= h($oggetto['tipo']) ?></li>
          <li><strong>Taglia:</strong> <?= h(getSize((int)$oggetto['taglia'])) ?></li>
          <?php if ((float)$oggetto['peso'] > 0): ?>
            <li><strong>Peso:</strong> <?= h((string)$oggetto['peso']) ?> Kg</li>
          <?php endif; ?>
          <?php if ((int)$oggetto['mani'] > 0 && $oggetto['tipoG'] === 'Arma'): ?>
            <li><strong>Mani:</strong> <?= h((string)$oggetto['mani']) ?></li>
          <?php endif; ?>
          <?php if ($oggetto['sacro']): ?>
            <li><strong>Oggetto Sacro</strong></li>
          <?php endif; ?>
          <?php if ((int)$oggetto['restore'] > 0): ?>
            <li><strong>Ripristina PF:</strong> <?= h((string)$oggetto['restore']) ?></li>
          <?php endif; ?>
          <?php if ((int)$oggetto['idcreatore']): ?>
            <li>
              <strong>Creatore:</strong>
              <a href="javascript:openProfileId('<?= h((string)$oggetto['idcreatore']) ?>')">
                <?= h(GetPGFromID((int)$oggetto['idcreatore'])) ?>
              </a>
            </li>
          <?php endif; ?>
          <?php if (strlen($oggetto['lavorazione'])): ?>
            <li><strong>Lavorazione:</strong> <?= h($oggetto['lavorazione']) ?></li>
          <?php endif; ?>
          <?php if ((int)$oggetto['destinatario']): ?>
            <li>
              <strong>Creato per:</strong>
              <?= h(GetPGFromID((int)$oggetto['destinatario'])) ?>
            </li>
          <?php endif; ?>
          <?php if ((int)$oggetto['destorg']): ?>
            <li><strong>Per Organizzazione:</strong> <?= h(GetOrg((int)$oggetto['destorg'])) ?></li>
          <?php endif; ?>
          <li><strong>Qualità:</strong> <?= h(getQuality((int)$oggetto['durata'])) ?></li>
          <li><strong>Durata:</strong> <?= h((string)$oggetto['durata']) ?></li>
        </ul>
      </div>
    </div>

    <h3>Materie Prime</h3>
    <?php $mp = GetMateriePrime($oggetto); ?>
    <?php if ($mp): ?>
      <div class="grid">
        <?php foreach ($mp as $mat => $qty): ?>
          <div class="grid-item">
            <strong><?= h($mat) ?></strong><br>
            <?= (int)$qty ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p>Nessuna materia prima necessaria.</p>
    <?php endif; ?>

    <div class="back">
      <button class="btn" onclick="history.back()">← Indietro</button>
    </div>
  </div>
</body>
</html>
<?php ClosePage(); ?>
