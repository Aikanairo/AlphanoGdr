<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__.'/../libs/group-media.inc.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/group-admin.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';
require_once __DIR__ . '/../libs/libaccademia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/group-hierarchy.inc.php';



// Funzione ausiliaria per visualizzare lo statuto come HTML
function renderStatuto(string $raw): void {
    // Rimuovo eventuali backslash di escape
    $clean = stripslashes($raw);
    // Decodifico entità HTML (&lt;, &quot; ecc)
    $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
    // Se vuoi limitare i tag, usa strip_tags() qui:
    // $clean = strip_tags($clean, '<p><a><strong><em><ul><li><h1><h2><h3><h4>');
    echo alphano_sanitize_html($clean);
}

// Verifica login
$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}

$db = getDB();
$user = $db->query(
    'SELECT admin, adminaccademia, corporazione, admincorporazione FROM utenti WHERE id = ?',
    [$idpg]
)[0];

$admin     = (bool)$user['admin'];
$accademia = (bool)$user['adminaccademia'];
$corpId    = (int)($_POST['corp'] ?? $_GET['corp'] ?? 0);
$admincorp = $corpId > 0
           && $corpId === (int)$user['corporazione']
           && (bool)$user['admincorporazione'];
$hierarchy = alphano_group_hierarchy('corporazioni', $corpId, $user);

if ($admin) {
    EnsureAdmin();
}

$statuto = $_POST['statuto'] ?? '';
$modify  = isset($_POST['modify']) || isset($_GET['modify']);

// Messaggio di conferma
$msg = '';

// Salva modifica statuto
if (
    $corpId > 0
    && $statuto !== ''
    && ($admin || $admincorp)
    && isset($_POST['statuto'])
) {
    if (alphano_group_statute_proposal('corporazioni',$corpId,$idpg,$statuto,$_POST['_request_key'] ?? null)) {
        $msg = 'Lo statuto è stato inviato all\'accademia.';
    }
}

// Carica statuto corrente (nuovo se in modify)
if ($corpId > 0) {
    $statuto = GetStatutoCorp($corpId, $modify);
}

// Se è admin accademia, carica produzioni
$produzioni = [];
if ($accademia && $corpId > 0) {
    $rows = $db->query(
        'SELECT sp.nome AS nome, pc.quality
           FROM produzionicorporazioni pc
           JOIN subtipi sp ON sp.id = pc.produzione
          WHERE pc.corporazione = ?
          ORDER BY sp.nome',
        [$corpId]
    );
    foreach ($rows as $r) {
        $produzioni[$r['nome']] = (int)$r['quality'];
    }
}


// $quality è definito in libequip.inc.php
global $quality;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlentities(GetTitolo($statuto), ENT_QUOTES, 'UTF-8') ?> – <?= TitoloSito ?></title>
    <script src="/js/corporation-dossier.js" defer></script>

    <?php if ($modify && ($admin || $admincorp)): ?>
    <script src="/js/gml.js" defer></script>
    <?php endif; ?>

    <?php if ($modify && ($admin || $admincorp)): ?>
    <script>
        function sendEdit() {
            const field = document.querySelector('textarea[name="statuto"]');
            const txt = field.value.trim();
            if (!txt) {
                alert("Devi inserire uno statuto");
                return;
            }
            field.form.requestSubmit();
        }
    </script>
    <?php endif; ?>
</head>
<?php $h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<body class="corporation-dossier">
<header class="ui-page-heading corporation-dossier-heading" data-page-heading data-header-image="<?=$h(group_header_image('corporazioni',$corpId))?>">
    <span data-icon="shield" aria-hidden="true"></span>
    <div><div class="ui-eyebrow">Alphano · Corporazioni</div><h1><?=$h($hierarchy['name'])?></h1><p>La bottega, le sue cariche e i suoi membri.</p></div>
    <nav class="corporation-dossier-nav ui-heading-control" aria-label="Scheda della corporazione">
        <a class="action-btn" data-corporation-section="cariche" data-icon="users" href="?corp=<?=$corpId?>#cariche">Cariche e membri</a>
        <a class="action-btn" data-corporation-section="statuto" data-icon="book" href="?corp=<?=$corpId?>#statuto">Statuto</a>
        <a class="action-btn" data-icon="coins" href="./catalogo.php?corp=<?=$corpId?>">Catalogo</a>
        <?php if ($admin || $admincorp): ?>
        <form method="GET"><input type="hidden" name="corp" value="<?=$corpId?>"><input type="hidden" name="modify" value="1"><button type="submit" data-icon="settings">Modifica statuto</button></form>
        <?php endif; ?>
        <a class="action-btn" data-icon="back" href="./">Corporazioni</a>
        <?php if ($accademia): ?><a class="action-btn" data-icon="book" href="../admin/accademia.php">Accademia</a><?php endif; ?>
    </nav>
</header>
<main class="container corporation-dossier-content">
    <?php if ($msg): ?><p class="flash" role="status"><?=$h($msg)?></p><?php endif; ?>
    <?php if ($modify && ($admin || $admincorp)): ?>
        <section class="editor">
            <h2>Modifica statuto</h2>
            <p>Ogni modifica viene inviata all’accademia per la revisione.</p>
            <form method="POST">
                <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
                <input type="hidden" name="corp" value="<?=$corpId?>">
                <input type="hidden" name="modify" value="1">
                <textarea name="statuto" aria-label="Testo dello statuto"><?=$h($statuto)?></textarea>
                <div class="actions"><button type="button" onclick="sendEdit()">Salva</button><button type="reset">Annulla</button></div>
            </form>
        </section>
    <?php else: ?>
        <section id="cariche" class="corporation-ranks" aria-labelledby="corporation-ranks-title">
            <h2 id="corporation-ranks-title" class="ui-visually-hidden">Cariche e membri</h2>
            <?php $chart=$hierarchy['chart'];require __DIR__.'/../templates/shared/hierarchy-chart.php'; ?>
        </section>
        <section id="statuto" class="corporation-statute" aria-labelledby="corporation-statute-title"><h2 id="corporation-statute-title">Statuto</h2><div class="view"><?php renderStatuto($statuto); ?></div></section>
        <?php if ($accademia): ?>
            <section class="productions"><h2>Produzioni selezionate</h2>
                <?php if ($produzioni): ?><ul><?php foreach ($produzioni as $nome => $q): ?><li><?=$h($nome)?> — <?=$h($quality[$q]['nome'] ?? 'N/A')?></li><?php endforeach; ?></ul><?php else: ?><p>Nessuna produzione selezionata.</p><?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php ClosePage(); ?>
</body>
</html>
