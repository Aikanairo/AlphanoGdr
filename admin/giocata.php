<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/player-runtime.inc.php';
require_once __DIR__ . '/../libs/libxml.inc.php';
require_once __DIR__ . '/../libs/libaccademia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';

$idpg = alphano_require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
$viewer = alphano_user();
if (empty($viewer['admin']) && empty($viewer['adminaccademia'])) alphano_fail('Non hai i permessi per accedere.', 403);
$rawId = $_POST['giocata'] ?? $_GET['giocata'] ?? '';
if (!is_string($rawId) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) alphano_fail('Nessuna giocata selezionata.', 400);
$id = (int)$rawId;
$row = ConnectDB()->query('SELECT id,nome,giocata FROM giocate WHERE id=?', [$id])[0] ?? null;
if (!$row) alphano_fail('Giocata non trovata.', 404);
$compressed = base64_decode((string)$row['giocata'], true);
$lines = $compressed !== false ? GetArrayFromBZXML($compressed, 'giocata') : false;
if (!is_array($lines)) alphano_fail('Errore nell’apertura della giocata.', 400);
foreach ($lines as $line) if (!is_scalar($line) && $line !== null) alphano_fail('Formato della giocata non valido.', 400);
$recording = [
    'id' => $id, 'title' => (string)($row['nome'] ?: 'Giocata #' . $id),
    'html' => alphano_archive_content(implode("<br>\n", array_map(static fn(mixed $line): string => (string)$line, $lines))),
    'can_vote' => CanVoteGiocata($idpg, $id),
];
require __DIR__ . '/../templates/academy/recording.php';
ClosePage();
