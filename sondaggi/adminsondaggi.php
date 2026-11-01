<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libsondaggi.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';




$pgid    = alphano_require_login();

// Solo admin può accedere
if (!IsAdmin($pgid)) {
    alphano_fail('Permesso negato.',403);
}
EnsureAdmin();

$db = ConnectDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();

// 1) Elimina sondaggio
if (!empty($_POST['elimina'])) {
    $toDelete = (int)($_POST['id'] ?? 0);
    if ($toDelete > 0) {
        DeleteSondaggio($toDelete);
    }
}

// 2) Aggiorna sondaggi esistenti
if (!empty($_POST['aggiorna'])) {
    $rawIds = $_POST['ids'] ?? '';
    // implode/explode style: "1;2;5;7"
    // la funzione aggiorna internamente i singoli ID
    AggiornaSondaggi($rawIds, $_POST);
}

// 3) Inserisci nuovo sondaggio
if (!empty($_POST['inserisci'])) {
    $tema     = trim((string)($_POST['tema']     ?? ''));
    $risposte = trim((string)($_POST['risposte'] ?? ''));
    if ($tema !== '' && $risposte !== '') {
        AggiungiSondaggio($tema, $risposte);
    }
}

// Ora riprendo tutti i sondaggi per mostrarli
$sondaggi = [];
$ids      = [];
foreach ($db->query("SELECT * FROM sondaggi ORDER BY id DESC") as $row) {
    $sondaggi[] = $row;
    $ids[]      = $row['id'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Amministrazione Sondaggi &ndash; <?= htmlspecialchars(TitoloSito, ENT_QUOTES) ?></title>

    <script src="/js/global.js"></script>
</head>
<body>
    <!-- Aggiungi un sondaggio -->
    <form method="post" data-section="Nuovo sondaggio">
<table class="column-width-100">
            <tr>
                <td class="Valore align-center" colspan="2">
                    Aggiungi un nuovo sondaggio
                </td>
            </tr>
            <tr>
                <td class="Valore align-center">
                    Tema:<br>
                    <input type="text" name="tema" size="80">
                </td>
                <td class="Valore align-center">
                    Risposte (separate da “;”):<br>
                    <textarea name="risposte" cols="40" rows="4"></textarea>
                </td>
            </tr>
            <tr>
                <td colspan="2" class="align-center">
                    <button type="submit" name="inserisci" value="1">Inserisci</button>
                </td>
            </tr>
        </table>
</form>
    <hr>

    <!-- Modifica sondaggi esistenti -->
    <form method="post" data-section="Modifica sondaggi">
<table class="column-width-100">
            <input type="hidden" name="ids" value="<?= htmlspecialchars(implode(';', $ids), ENT_QUOTES) ?>">
            <tr>
                <td class="Valore align-center" colspan="3">
                    Modifica sondaggi esistenti
                </td>
            </tr>
            <?php foreach ($sondaggi as $dati):
                $sid      = (int)$dati['id'];
                $attivo   = (bool)$dati['attivo'];
                $tema     = stripslashes((string)$dati['tema']);
                $risposte = stripslashes((string)$dati['risposte']);
            ?>
            <tr>
                <td class="Valore align-center">
                    <label>
                        Tema:<br>
                        <input
                          type="text"
                          name="tema:<?= $sid ?>:"
                          value="<?= htmlspecialchars($tema, ENT_QUOTES) ?>"
                          size="60"
                        >
                    </label>
                </td>
                <td class="Valore align-center">
                    <label>
                        <input
                          type="checkbox"
                          name="attivo:<?= $sid ?>:"
                          value="1"
                          <?= $attivo ? 'checked' : '' ?>
                        > Attivo
                    </label>
                </td>
                <td class="Valore align-center">
                    <label>
                        Risposte:<br>
                        <textarea
                          name="risposte:<?= $sid ?>:"
                          cols="30"
                          rows="3"
                        ><?= htmlspecialchars($risposte, ENT_QUOTES) ?></textarea>
                    </label>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3" class="align-center">
                    <button type="submit" name="aggiorna" value="1">Aggiorna tutti</button>
                </td>
            </tr>
        </table>
</form>
    <hr>

    <!-- Elimina un sondaggio -->
    <form method="post" data-section="Elimina sondaggio">
<table class="column-width-100">
            <tr>
                <td class="Valore align-center" colspan="2">
                    Elimina un sondaggio
                </td>
            </tr>
            <tr>
                <td class="Valore align-center">
                    <select name="id">
                        <option value="">– Seleziona tema –</option>
                        <?php foreach ($sondaggi as $dati):
                            $sid  = (int)$dati['id'];
                            $tema = stripslashes((string)$dati['tema']);
                            // tronca a 80 caratteri
                            $label = mb_strlen($tema) > 80
                                   ? mb_substr($tema, 0, 80) . '…'
                                   : $tema;
                        ?>
                        <option value="<?= $sid ?>">
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="align-center">
                    <button type="submit" name="elimina" value="1">Elimina</button>
                </td>
            </tr>
        </table>
        </form>
        <table><tr>
            <td colspan="2" class="align-center">
                <button onclick="location.href='sondaggi.php'">
                    Torna a “Sondaggi”
                </button>
            </td>
        </tr>
    </table>
</body>
</html>
<?php
ClosePage();
