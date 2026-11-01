<?php
declare(strict_types=1);



/**
 *  /api/news.php
 *  -----------------------------------------------------------------
 *  • GET  /api/news.php                 → elenco notifiche JSON
 *  • POST /api/news.php?action=reset    → azzera ultimauscita e conferma reset
 *  -----------------------------------------------------------------
 *  Dipendenze: vedi /api/bootstrap.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__.'/../libs/manuals.inc.php';
header('Cache-Control: private, no-store');

$idpg = GetIDPG(true);      // interrompe se non loggato
$db   = db();

// ------------------------------------------------------------------
// RESET “ho letto tutto”
// ------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('Metodo non consentito',405);
    alphano_require_csrf(true);
    $now = time();
    $db->execute(
      'UPDATE utenti SET ultimauscita = ? WHERE id = ?',
      [$now, $idpg]
    );
    ok(['reset' => true]);
}

// ------------------------------------------------------------------
// 1) Carica dati utente
// ------------------------------------------------------------------
$row = $db->query(
    'SELECT *
       FROM utenti
      WHERE id = ?',
    [$idpg]
)[0] ?? [];

$ultima         = (int)($row['ultimauscita'] ?? 0);
$maschio        = ($row['sesso'] ?? '') === 'Maschio';

// helper per raccogliere notifiche
$items = [];
$append = static function(string $txt) use (&$items) {
    $items[] = $txt;
};

// ------------------------------------------------------------------
// 2) Primo accesso?
// ------------------------------------------------------------------
if ($ultima === 0) {
    $append($maschio
      ? 'Benvenuto in Alphano! Per giocare meglio consulta le Regole e partecipa al forum.'
      : 'Benvenuta in Alphano! Per giocare meglio consulta le Regole e partecipa al forum.');
    ok($items);
}

// ------------------------------------------------------------------
// 3) SONDAGGI
// ------------------------------------------------------------------
$totalSond = count($db->query('SELECT 1 FROM sondaggi WHERE attivo=1'));
$answered  = count($db->query(
    'SELECT 1
       FROM risondaggi r
       JOIN sondaggi s ON r.idsondaggio = s.id
      WHERE r.idpg = ? AND s.attivo = 1',
    [$idpg]
));
if ($totalSond > $answered) {
    $rem = $totalSond - $answered;
    $append($rem === 1
        ? 'Devi ancora votare il sondaggio in corso.'
        : "Devi ancora votare {$rem} sondaggi in corso.");
}

$since = $ultima;

// Forum, gruppi, Tracce Quest e Accademia sono gestiti esclusivamente da
// api/notifications.php: lettura personale persistente e ACL correnti.
// Nessun riepilogo basato sull'ultimo logout deve ricreare un avviso letto
// o esporre il titolo di una bacheca riservata.

// ------------------------------------------------------------------
// 9) MODIFICHE ALLE REGOLE
// ------------------------------------------------------------------
$rows = $db->query(
    'SELECT *
       FROM regole
      WHERE ultimamodifica > ?',
    [$since]
);
$tmp = array_column(array_filter($rows,static fn($rule)=>alphano_manual_can_read($rule,$row)), 'oggetto');
if ($tmp) {
    $append(count($tmp) > 1
        ? 'Sono state modificate le seguenti sezioni delle Regole di Alphano:'
        : 'È stata modificata la seguente sezione delle Regole di Alphano:');
    $append(implode(', ', $tmp));
}

// Le nuove quest sono consegnate da api/notifications.php con lettura persistente.

// Assemblea ed elezioni sono attività personali persistenti in notifications.php.

// ------------------------------------------------------------------
// 12) Nessun evento?
// ------------------------------------------------------------------
if (empty($items)) {
    $items[] = 'Nessun Nuovo Evento da segnalare.';
}

// ------------------------------------------------------------------
// Output JSON
// ------------------------------------------------------------------
ok($items);
