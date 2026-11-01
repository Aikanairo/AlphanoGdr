<?php
declare(strict_types=1);

// ———————————————————————————
// NON ACCEDERE IN GET
// ———————————————————————————
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('❗ Metodo non consentito');
}

// ———————————————————————————
// MASSIMO DEBUG & ERROR HANDLING
// ———————————————————————————
$root = dirname(__DIR__);
require_once "$root/libs/kernel.inc.php";
require_once "$root/libs/libpg.inc.php";
require_once "$root/libs/libgrafica.inc.php";
require_once "$root/libs/libposta.inc.php";

$idpg = alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
$db   = ConnectDB();

// ———————————————————————————
// PARAMETRI
// ———————————————————————————
$action = $_POST['azione'] ?? '';
// 'delete0' => elimina da mittente, 'delete1' => elimina da destinatario, 'move' => sposta in/out baule
$baule  = isset($_POST['baule']) && $_POST['baule'] === '1' ? 1 : 0;

// raccogliamo tutti gli ID selezionati (campi con valore 's')
$selected = [];
foreach ($_POST as $key => $value) {
    if ($value === 's' && ctype_digit((string)$key)) {
        $selected[] = (int)$key;
    }
}

if (empty($selected)) {
    // nessuna mail selezionata: torniamo indietro
    header('Location: ./');
    exit;
}

// ———————————————————————————
// COSTRUIAMO LE QUERY PARAMETRICHE
// ———————————————————————————
$placeholders = implode(',', array_fill(0, count($selected), '?'));

switch ($action) {
    case 'delete0':
        // segna come eliminate dal mittente
        $sql = "UPDATE mail SET delmitt=1 WHERE id IN ($placeholders) AND idmittente = ?";
        $params = array_merge($selected, [$idpg]);
        break;

    case 'delete1':
        // segna come eliminate dal destinatario
        $sql = "UPDATE mail SET deldest=1 WHERE id IN ($placeholders) AND iddestinatario = ?";
        $params = array_merge($selected, [$idpg]);
        break;

    case 'move':
        // sposta in/out dal baule
        $newBaule = $baule ? 0 : 1;
        $sql = "UPDATE mail SET baule = ? WHERE id IN ($placeholders) AND iddestinatario = ?";
        // baule va come primo parametro
        $params = array_merge([$newBaule], $selected, [$idpg]);
        break;

    default:
        // azione non riconosciuta
        http_response_code(400);
        exit('❗ Azione non valida');
}

// ———————————————————————————
// ESEGUIAMO L’AGGIORNAMENTO
// ———————————————————————————
$db->query($sql, $params);

// ———————————————————————————
// TORNA ALLA LISTA
// ———————————————————————————
header('Location: ./');
exit;
