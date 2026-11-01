<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
alphano_require_login();
header('Cache-Control: no-store');
$suggestion = bin2hex(random_bytes(12));
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Suggerimento password</title></head>
<body class="ui-page">
<header class="ui-page-heading" data-page-heading><span data-icon="settings" aria-hidden="true"></span><div><div class="ui-eyebrow">Accesso e sicurezza</div><h1>Suggerimento password</h1><p>Copia la password nel campo Nuova password del tuo profilo.</p></div></header>
<main class="ui-reading"><p><code><?=htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8')?></code></p></main>
<nav class="ui-list-footer ui-row-actions" aria-label="Azioni"><a class="action-btn" data-icon="settings" href="/profili/modifyuser.php">Modifica profilo</a><a class="action-btn" data-icon="check" href="/profili/suggpass.php">Genera un’altra password</a></nav>
</body></html>
<?php ClosePage();
