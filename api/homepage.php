<?php
/**
 * /api/homepage.php
 *
 * Raccoglie le informazioni necessarie alla homepage e le restituisce in JSON.
 */
declare(strict_types=1);

namespace Alphano\Api;

require_once __DIR__ . '/../libs/kernel.inc.php';

use Alphano\Database\PDOMySQLDriver;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

// 1. HTTP HEADERS
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 2. HELPERS
if (!function_exists(__NAMESPACE__ . '\html')) {
    function html(string $raw): string
    {
        return htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists(__NAMESPACE__ . '\nl2br')) {
    function nl2br(string $raw): string
    {
        return str_replace(["\r\n", "\n", "\r"], '<br>', $raw);
    }
}

try {
    // 3. CONNECT DB
    /** @var PDOMySQLDriver $db */
    $db = ConnectDB();

    // 4. DATA GATEWAYS

    /**
     * 4.1 Bacheca (news, changelog, ricercati)
     *    SQL: SELECT oggetto, messaggio FROM bacheche
     *         WHERE bacheca = ? ORDER BY id DESC LIMIT <limit>
     */
    function fetchBacheca(PDOMySQLDriver $db, int $categoria, int $limit = 5): array
    {
        $limit = max(1, $limit);
        $sql = "
            SELECT oggetto, messaggio
              FROM bacheche
             WHERE bacheca = ?
          ORDER BY id DESC
             LIMIT {$limit}
        ";
        // il driver fa binding della singola variabile
        $rows = $db->query($sql, [$categoria]);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'oggetto'   => html((string)$r['oggetto']),
                'messaggio' => html((string)$r['messaggio']),
            ];
        }
        return $out;
    }

    /**
     * 4.2 Sondaggi attivi e conteggio voti
     *    - Recupera id, tema, risposte
     *    - Per ogni id: conta totali e per opzione
     */
    function fetchSondaggi(PDOMySQLDriver $db): array
    {
        $out = [];
        // 4.2.a Legge i sondaggi attivi
        $polls = $db->query(
            "SELECT id, tema, risposte
               FROM sondaggi
              WHERE attivo = 1
           ORDER BY id DESC"
        );
        foreach ($polls as $p) {
            $id      = (int)$p['id'];
            $options = preg_split('/\R/u', trim((string)$p['risposte']));

            // 4.2.b Conteggio totale voti
            $tot = (int)$db->query(
                "SELECT COUNT(*) AS c
                   FROM risondaggi
                  WHERE idsondaggio = ?",
                [$id]
            )[0]['c'];

            // 4.2.c Conteggio per ogni risposta
            $risp = [];
            foreach ($options as $i => $text) {
                $count = (int)$db->query(
                    "SELECT COUNT(*) AS c
                       FROM risondaggi
                      WHERE idsondaggio = ?
                        AND idrisposta   = ?",
                    [$id, $i + 1]
                )[0]['c'];
                $risp[] = [
                    'testo'  => nl2br(html($text)),
                    'valore' => $count,
                ];
            }

            $out[] = [
                'titolo'   => html((string)$p['tema']),
                'risptot'  => $tot,
                'risposte' => $risp,
            ];
        }
        return $out;
    }

    /**
     * 4.3 Etnie + mappa id→nome
     *    SQL: SELECT id, nome, descrizione FROM etnie ORDER BY nome
     */
    function fetchEtnie(PDOMySQLDriver $db): array
    {
        $rows   = $db->query(
            "SELECT id, nome, descrizione
               FROM etnie
           ORDER BY nome"
        );
        $detail = [];
        $map    = [];

        foreach ($rows as $r) {
            $id   = (int)$r['id'];
            $name = html((string)$r['nome']);
            $detail[$id] = [
                'nome'        => $name,
                'descrizione' => html((string)$r['descrizione']),
            ];
            $map[] = ['id' => $id, 'nome' => $name];
        }
        return [$detail, $map];
    }

    /**
     * 4.4 Statistiche utenti
     *    - tot utenti
     *    - iscritti ultimi 7 gg
     *    - top 6 visite
     */
    function fetchStatistiche(PDOMySQLDriver $db): array
    {
        $stats = [];

        // 4.4.a Totale utenti
        $stats['tot'] = (int)$db->query(
            "SELECT COUNT(*) AS n FROM utenti"
        )[0]['n'];

        // 4.4.b Utenti ultimi 7 giorni
        $since = GeneratePastTimestamp(7);
        $stats['lastweek'] = (int)$db->query(
            "SELECT COUNT(*) AS n
               FROM utenti
              WHERE dataregistrazione > ?
                AND ultimoingresso > 0",
            [$since]
        )[0]['n'];

        // 4.4.c Top 6 profili visitati
        $vis = $db->query(
            "SELECT id, nome, razza, sesso
               FROM utenti
              WHERE visite > 0
           ORDER BY visite DESC, nome ASC
              LIMIT 6"
        );
        $stats['visite'] = [];
        foreach ($vis as $u) {
            $stats['visite'][] = [
                'id'    => (int)$u['id'],
                'nome'  => html((string)$u['nome']),
                'razza' => (int)$u['razza'],
                'sesso' => strtolower($u['sesso'][0] ?? 'm'),
            ];
        }

        return $stats;
    }

    // 5. BUILD PAYLOAD
    [$etnieDett, $etnieNomi] = fetchEtnie($db);
    $payload = [
        'news'        => fetchBacheca($db,  9),
        'changelog'   => fetchBacheca($db, 22),
        'ricercati'   => fetchBacheca($db, 20),
        'sondaggi'    => fetchSondaggi($db),
        'etnie'       => $etnieDett,
        'nomietnie'   => $etnieNomi,
        'statistiche' => fetchStatistiche($db),
    ];

    // 6. OUTPUT JSON
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
catch (\Throwable $e) {
    // In caso di qualsiasi errore
    http_response_code(500);
    error_log('[homepage] ' . $e);
    echo json_encode(['error' => 'Dati momentaneamente non disponibili.']);
    exit;
}
