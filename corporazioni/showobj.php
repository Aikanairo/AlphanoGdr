<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/libaccademia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';



// Verifica login e permessi
$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}

$db = getDB();
$user = $db->query('SELECT admin, adminaccademia FROM utenti WHERE id = ?', [$idpg])[0];
if ((bool)$user['admin']) {
    EnsureAdmin();
}
if (!(bool)$user['adminaccademia']) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="it"><body class="message-page">';
    echo '<h1>Accesso Negato</h1><p>Non hai i privilegi di accesso.</p>';
    echo '</body></html>';
    exit;
}

// ID oggetto da POST
$objid = isset($_POST['obj']) ? (int)$_POST['obj'] : 0;
if ($objid <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="it"><body class="message-page">';
    echo '<h1>Errore</h1><p>Nessun oggetto selezionato.</p>';
    echo '</body></html>';
    exit;
}

// Recupera oggetto originale e modificato
$object    = $db->query(
    'SELECT e.*, t.nome AS tipo FROM equip e JOIN tipo t ON t.id=e.tipo WHERE e.id=?',
    [$objid]
)[0] ?? null;
$newObject = $db->query(
    'SELECT ne.*, t.nome AS tipo FROM newequip ne JOIN tipo t ON t.id=ne.tipo WHERE ne.id=?',
    [$objid]
)[0] ?? null;

// Se nessuno dei due esiste, errore
if (!$object && !$newObject) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="it"><body class="message-page">';
    echo '<h1>Non Trovato</h1><p>Oggetto non trovato.</p>';
    echo '</body></html>';
    exit;
}


?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dettagli Oggetto – <?= TitoloSito ?></title>


</head>
<body>
<div class="container">
    <h1>Dettagli Oggetto (ID <?= $objid ?>)</h1>

    <div class="panels">
        <?php if ($object): ?>
        <section class="panel">
            <h2>Originale</h2>
            <p class="field label"><?= htmlentities($object['shortDesc']) ?></p>
            <img src="<?= htmlentities($object['immagine']) ?>" alt="Immagine">
            <div class="field"><?= ProcessText($object['desc']) ?></div>
            <div class="field"><span class="label">Tipo:</span> <?= htmlentities($object['tipo']) ?></div>
            <div class="field"><span class="label">Taglia:</span> <?= htmlentities(getSize((int)$object['taglia'])) ?></div>
            <?php if (!empty($object['peso'])): ?>
            <div class="field"><span class="label">Peso:</span> <?= $object['peso'] ?> Kg</div>
            <?php endif; ?>
            <?php if ((int)$object['mani'] > 0 && $object['tipoG'] === 'Arma'): ?>
            <div class="field"><span class="label">Mani:</span> <?= (int)$object['mani'] ?></div>
            <?php endif; ?>
            <?php if ($object['sacro']): ?>
            <div class="field"><img src="<?= MAGIC_IMG ?>" alt="Sacro"> Oggetto Sacro</div>
            <?php endif; ?>
            <?php if ((int)$object['restore'] > 0): ?>
            <div class="field"><span class="label">Ripristina PF:</span> <?= (int)$object['restore'] ?></div>
            <?php endif; ?>
            <?php if (!empty($object['idcreatore'])): ?>
            <div class="field"><span class="label">Creatore:</span>
                <a href="javascript:openProfileId('<?= (int)$object['idcreatore'] ?>')">
                    <?= htmlentities(GetPGFromID((int)$object['idcreatore'])) ?>
                </a>
            </div>
            <?php endif; ?>
            <?php if (!empty($object['lavorazione'])): ?>
            <div class="field"><span class="label">Lavorazione:</span> <?= htmlentities($object['lavorazione']) ?></div>
            <?php endif; ?>
            <?php if (!empty($object['destinatario'])): ?>
            <div class="field"><span class="label">Per:</span>
                <?= htmlentities(GetPGFromID((int)$object['destinatario'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($object['destorg'])): ?>
            <div class="field"><span class="label">Organizzazione:</span>
                <?= htmlentities(GetOrg((int)$object['destorg'])) ?></div>
            <?php endif; ?>
            <div class="field"><span class="label">Qualità:</span>
                <?= htmlentities(getQuality((int)$object['durata'])) ?></div>
            <div class="field"><span class="label">Durata:</span> <?= (int)$object['durata'] ?></div>
            <?php if ((int)$object['datacreazione']): ?>
            <div class="field"><span class="label">Creato il:</span>
                <?= FormatTimestamp((int)$object['datacreazione']) ?></div>
            <?php endif; ?>
            <?php if ((int)$object['datamodifica']): ?>
            <div class="field"><span class="label">Modificato il:</span>
                <?= FormatTimestamp((int)$object['datamodifica']) ?></div>
            <?php endif; ?>

            <?php
            $primes = GetMateriePrime($object);
            $items = [];
            foreach ($primes as $k => $v) {
                if (!is_numeric($k) && (int)$v > 0) {
                    $items[$k] = (int)$v;
                }
            }
            if ($items):
            ?>
            <div class="primes">
                <?php foreach ($items as $mat => $val): ?>
                <div class="prime-item">
                    <div><?= htmlentities($mat) ?></div>
                    <div><?= $val ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($newObject): ?>
        <section class="panel">
            <h2>Modificato</h2>
            <p class="field label"><?= htmlentities($newObject['shortDesc']) ?></p>
            <img src="<?= htmlentities($newObject['immagine']) ?>" alt="Immagine">
            <div class="field"><?= ProcessText($newObject['desc']) ?></div>
            <div class="field"><span class="label">Tipo:</span> <?= htmlentities($newObject['tipo']) ?></div>
            <div class="field"><span class="label">Taglia:</span> <?= htmlentities(getSize((int)$newObject['taglia'])) ?></div>
            <?php if (!empty($newObject['peso'])): ?>
            <div class="field"><span class="label">Peso:</span> <?= $newObject['peso'] ?> Kg</div>
            <?php endif; ?>
            <?php if ((int)$newObject['mani'] > 0 && $newObject['tipoG'] === 'Arma'): ?>
            <div class="field"><span class="label">Mani:</span> <?= (int)$newObject['mani'] ?></div>
            <?php endif; ?>
            <?php if ($newObject['sacro']): ?>
            <div class="field"><img src="<?= MAGIC_IMG ?>" alt="Sacro"> Oggetto Sacro</div>
            <?php endif; ?>
            <?php if ((int)$newObject['restore'] > 0): ?>
            <div class="field"><span class="label">Ripristina PF:</span> <?= (int)$newObject['restore'] ?></div>
            <?php endif; ?>
            <?php if (!empty($newObject['idcreatore'])): ?>
            <div class="field"><span class="label">Creatore:</span>
                <?= htmlentities(GetPGFromID((int)$newObject['idcreatore'])) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($newObject['lavorazione'])): ?>
            <div class="field"><span class="label">Lavorazione:</span> <?= htmlentities($newObject['lavorazione']) ?></div>
            <?php endif; ?>
            <?php if (!empty($newObject['destinatario'])): ?>
            <div class="field"><span class="label">Per:</span>
                <?= htmlentities(GetPGFromID((int)$newObject['destinatario'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($newObject['destorg'])): ?>
            <div class="field"><span class="label">Organizzazione:</span>
                <?= htmlentities(GetOrg((int)$newObject['destorg'])) ?></div>
            <?php endif; ?>
            <div class="field"><span class="label">Qualità:</span>
                <?= htmlentities(getQuality((int)$newObject['durata'])) ?></div>
            <div class="field"><span class="label">Durata:</span> <?= (int)$newObject['durata'] ?></div>
            <?php if ((int)$newObject['datacreazione']): ?>
            <div class="field"><span class="label">Creato il:</span>
                <?= FormatTimestamp((int)$newObject['datacreazione']) ?></div>
            <?php endif; ?>
            <?php if ((int)$newObject['datamodifica']): ?>
            <div class="field"><span class="label">Modificato il:</span>
                <?= FormatTimestamp((int)$newObject['datamodifica']) ?></div>
            <?php endif; ?>

            <?php
            $primesNew = GetMateriePrime($newObject);
            $itemsNew = [];
            foreach ($primesNew as $k => $v) {
                if (!is_numeric($k) && (int)$v > 0) {
                    $itemsNew[$k] = (int)$v;
                }
            }
            if ($itemsNew):
            ?>
            <div class="primes">
                <?php foreach ($itemsNew as $mat => $val): ?>
                <div class="prime-item">
                    <div><?= htmlentities($mat) ?></div>
                    <div><?= $val ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <div class="vote">
        <?php if (CanVoteObj($idpg, $objid)): ?>
        <form action="../admin/accademia.php" method="POST">
            <input type="hidden" name="azione" value="vota">
            <input type="hidden" name="obj" value="<?= $objid ?>">
            <label>
                Voto:
                <select name="voto">
                    <option value="">--</option>
                    <option value="1">Positivo</option>
                    <option value="0">Negativo</option>
                </select>
            </label>
            <label>
                Motivo:
                <input type="text" name="motivo" placeholder="Motivo...">
            </label>
            <button type="submit">Invia Voto</button>
        </form>
        <?php else: ?>
        <p>Hai già votato per questo oggetto.</p>
        <?php endif; ?>
    </div>

    <div class="back">
        <form action="../admin/accademia.php" method="GET">
            <button type="submit">Torna all'accademia</button>
        </form>
    </div>
</div>
<?php ClosePage(); ?>
