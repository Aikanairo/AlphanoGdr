<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/libaccademia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';



// Verifica login (non obbligatorio) e ottieni ID utente
$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}

// Controllo permessi accademia
$db = getDB();
$user = $db->query('SELECT admin, adminaccademia FROM utenti WHERE id = ?', [$idpg])[0];
$admin = (bool)$user['admin'];
$accademia = (bool)$user['adminaccademia'];
if ($admin) {
    EnsureAdmin();
}
if (!$accademia) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="it"><body class="message-page">';
    echo '<h1>Accesso Negato</h1><p>Non ti è permesso accedere a questa pagina.</p>';
    echo '</body></html>';
    exit;
}

// Determina l'ID della corporazione da visualizzare
$corporazione = (int)($_POST['corp'] ?? $_GET['corp'] ?? 0);
if ($corporazione <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="it"><body class="message-page">';
    echo '<h1>Errore</h1><p>ID Corporazione non valido.</p>';
    echo '</body></html>';
    exit;
}

// Recupera dati della corporazione
$corpData = $db->query(
    'SELECT nome, statuto, newstatuto FROM corporazioni WHERE id = ? LIMIT 1',
    [$corporazione]
)[0] ?? null;
if (!$corpData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="it"><body class="message-page">';
    echo '<h1>Non Trovato</h1><p>Corporazione non trovata.</p>';
    echo '</body></html>';
    exit;
}

// Colore dell'intestazione (se usato altrove)


// --- PREPARA STATUTI PER OUTPUT ---

function renderStatuto(?string $raw): void {
    $raw ??= '';
    // 1) Rimuovo eventuali backslash di escape
    $clean = stripslashes($raw);
    // 2) Decodifico entità HTML (&lt;, &quot; ecc)
    $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
    // 3) (Opzionale) Se vuoi consentire solo alcuni tag:
    //    $clean = strip_tags($clean, '<p><a><strong><em><ul><li><h1><h2><h3><h4>');
    echo alphano_sanitize_html($clean);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlentities($corpData['nome'], ENT_QUOTES, 'UTF-8') ?> – <?= TitoloSito ?></title>


</head>
<body>
<div class="container">
    <h1><?= htmlentities($corpData['nome'], ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="statuti-container">
        <div class="statuto">
            <h2>Vecchio Statuto</h2>
            <?php renderStatuto($corpData['statuto']); ?>
        </div>
        <div class="statuto">
            <h2>Nuovo Statuto</h2>
            <?php renderStatuto($corpData['newstatuto']); ?>
        </div>
    </div>

    <div class="actions">
        <form action="../admin/accademia.php" method="POST">
            <button type="submit">Torna all'accademia</button>
        </form>
    </div>
</div>

<?php ClosePage(); ?>
</body>
</html>
