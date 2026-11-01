<?php
declare(strict_types=1);

// libs/libassemblea.inc.php
// Funzioni per approvazione o respingimento dello statuto cittadino.

require_once __DIR__ . '/kernel.inc.php';   // ConnectDB()
require_once __DIR__ . '/libposta.inc.php'; // InviaMissiva()

/**
 * Approva il nuovo statuto di una città.
 *
 * @param int   $city      ID della città
 * @param int[] $cariche   Array di ID di araldica da cui recuperare i sindaci
 */
function ApprovaStatuto(int $city, array $cariche): void
{
    $db = ConnectDB();

    // 1) Rimuovi tutti i voti
    $db->execute(
        'DELETE FROM assemblea WHERE city = ?',
        [$city]
    );

    // 2) Sposta newstatuto in statuto
    $db->execute(
        'UPDATE city SET statuto = newstatuto WHERE id = ? LIMIT 1',
        [$city]
    );

    // 3) Azzera newstatuto
    $db->execute(
        'UPDATE city SET newstatuto = ? WHERE id = ? LIMIT 1',
        ['', $city]
    );

    // 4) Invia notifica ai sindaci delle araldiche fornite
    if (!empty($cariche)) {
        $placeholders = implode(',', array_fill(0, count($cariche), '?'));
        $rows = $db->query(
            "SELECT id
               FROM utenti
              WHERE araldica IN ($placeholders)
                AND sindaco = 1",
            $cariche
        );
        foreach ($rows as $r) {
            InviaMissiva(
                "Notifica Statuto Approvato",
                "La modifica allo statuto proposta è stata approvata dalla maggioranza della popolazione.",
                (int)$r['id']
            );
        }
    }
}

/**
 * Respingi il nuovo statuto di una città.
 *
 * @param int   $city      ID della città
 * @param int[] $cariche   Array di ID di araldica da cui recuperare i sindaci
 */
function RespingiStatuto(int $city, array $cariche): void
{
    $db = ConnectDB();

    // 1) Rimuovi tutti i voti
    $db->execute(
        'DELETE FROM assemblea WHERE city = ?',
        [$city]
    );

    // 2) Azzera newstatuto
    $db->execute(
        'UPDATE city SET newstatuto = ? WHERE id = ? LIMIT 1',
        ['', $city]
    );

    // 3) Invia notifica ai sindaci delle araldiche fornite
    if (!empty($cariche)) {
        $placeholders = implode(',', array_fill(0, count($cariche), '?'));
        $rows = $db->query(
            "SELECT id
               FROM utenti
              WHERE araldica IN ($placeholders)
                AND sindaco = 1",
            $cariche
        );
        foreach ($rows as $r) {
            InviaMissiva(
                "Notifica Statuto Respinto",
                "La modifica allo statuto proposta è stata respinta dalla maggioranza della popolazione.",
                (int)$r['id']
            );
        }
    }
}
