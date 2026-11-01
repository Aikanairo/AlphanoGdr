<?php
declare(strict_types=1);

require_once __DIR__ . '/kernel.inc.php';  // per ConnectDB()

/**
 * Verifica se un PG ha già votato un sondaggio.
 */
function CanVote(int $pgid, int $sondaggio): bool
{
    $db = ConnectDB();
    if ($pgid <= 0) return false;
    return !empty($db->query('SELECT s.id FROM sondaggi s WHERE s.id=? AND s.attivo=1 AND NOT EXISTS (SELECT 1 FROM risondaggi r WHERE r.idpg=? AND r.idsondaggio=s.id)', [$sondaggio,$pgid]));
}

/**
 * Registra un voto di un PG per un sondaggio.
 */
function Vote(int $idrisp, int $sondaggio, int $pgid): void
{
    $db = ConnectDB();
    if ($pgid <= 0) throw new RuntimeException('Utente non valido', 400);
    $db->beginTransaction();
    try {
        // Lock the poll itself: the existing schema has no unique vote key.
        $poll = $db->query('SELECT attivo,risposte FROM sondaggi WHERE id=? FOR UPDATE', [$sondaggio])[0] ?? null;
        if (!$poll || empty($poll['attivo'])) throw new RuntimeException('Sondaggio non disponibile', 400);
        if ($idrisp < 1 || $idrisp > count(explode(';', $poll['risposte']))) throw new RuntimeException('Risposta non valida', 400);
        if ($db->query('SELECT 1 FROM risondaggi WHERE idsondaggio=? AND idpg=?', [$sondaggio,$pgid])) {
            $db->rollBack();
            return;
        }
        $db->execute('INSERT INTO risondaggi(idrisposta,idsondaggio,idpg) VALUES(?,?,?)', [$idrisp,$sondaggio,$pgid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

/**
 * Aggiunge un nuovo sondaggio attivo.
 */
function AggiungiSondaggio(string $tema, string $risposte): void
{
    if ($tema === '' || $risposte === '') {
        return;
    }
    $db = ConnectDB();
    $db->execute(
        "INSERT INTO sondaggi (attivo, tema, risposte) VALUES (1, ?, ?)",
        [$tema, $risposte]
    );
}

/**
 * Aggiorna sondaggi esistenti.
 *
 * @param string $ids  stringa di ID separati da ';'
 * @param array  $arr  array POST con chiavi "tema:ID:", "risposte:ID:", "attivo:ID:"
 */
function AggiornaSondaggi(string $ids, array $arr): void
{
    $db = ConnectDB();
    foreach (explode(';', $ids) as $id) {
        $tid = trim($id);
        if ($tid === '') {
            continue;
        }
        $keyTema  = "tema:{$tid}:";
        $keyRispo = "risposte:{$tid}:";
        if (! isset($arr[$keyTema], $arr[$keyRispo])) {
            continue;
        }
        $tema     = $arr[$keyTema];
        $risposte = $arr[$keyRispo];
        $attivo   = !empty($arr["attivo:{$tid}:"]) ? 1 : 0;

        $db->execute(
            "UPDATE sondaggi
                SET attivo   = ?,
                    tema     = ?,
                    risposte = ?
              WHERE id = ?",
            [$attivo, $tema, $risposte, $tid]
        );
    }
}

/**
 * Elimina un sondaggio e tutti i suoi voti.
 */
function DeleteSondaggio(int $id): void
{
    $db = ConnectDB();
    $db->execute(
        "DELETE FROM sondaggi WHERE id = ?",
        [$id]
    );
    $db->execute(
        "DELETE FROM risondaggi WHERE idsondaggio = ?",
        [$id]
    );
}

/**
 * Restituisce tutti i sondaggi attivi con percentuali di voto.
 *
 * @return array struttura:
 * [
 *   sondaggioId => [
 *     'titolo'  => string,
 *     'risptot' => int,
 *     'risposte'=> [
 *       rispostaId => [
 *         'testo'  => string,
 *         'valore' => int|float (percentuale)
 *       ],
 *       …
 *     ]
 *   ],
 *   …
 * ]
 */
function GetLastSondaggi(): array
{
    $db  = ConnectDB();
    $out = [];

    // Prelevo tutti i sondaggi attivi
    $rows = $db->query(
        "SELECT id, tema, risposte
           FROM sondaggi
          WHERE attivo = 1"
    );

    foreach ($rows as $sonda) {
        $sid        = (int)$sonda['id'];
        $out[$sid]  = [
            'titolo'   => stripslashes((string)$sonda['tema']),
            'risptot'  => 0,
            'risposte' => []
        ];

        // Splitting risposte e inizializzazione
        $opts = explode(';', (string)$sonda['risposte']);
        foreach ($opts as $idx => $text) {
            $rid = $idx + 1;
            $out[$sid]['risposte'][$rid] = [
                'testo'  => htmlentities(stripslashes($text), ENT_QUOTES),
                'valore' => 0
            ];
        }

        // Raggruppo conteggi voti per risposta
        $votes = $db->query(
            "SELECT idrisposta, COUNT(*) AS cnt
               FROM risondaggi
              WHERE idsondaggio = ?
              GROUP BY idrisposta",
            [$sid]
        );
        foreach ($votes as $v) {
            $rid = (int)$v['idrisposta'];
            $cnt = (int)$v['cnt'];
            $out[$sid]['risptot'] += $cnt;
            if (isset($out[$sid]['risposte'][$rid])) {
                $out[$sid]['risposte'][$rid]['valore'] = $cnt;
            }
        }

        // Calcolo percentuali
        $total = $out[$sid]['risptot'];
        if ($total > 0) {
            foreach ($out[$sid]['risposte'] as &$resp) {
                $resp['valore'] = round(($resp['valore'] * 100) / $total, 2);
            }
            unset($resp);
        }
    }

    return $out;
}

/**
 * Ritorna la descrizione "Positivo", "Negativo" o "Neutrale"
 * in base al valore numerico passato.
 */
function ProcessParere(int|float $parere): string
{
    if ($parere < 0) {
        return 'Negativo';
    }
    if ($parere > 0) {
        return 'Positivo';
    }
    return 'Neutrale';
}
