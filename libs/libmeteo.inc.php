<?php
// libs/libmeteo.inc.php

declare(strict_types=1);

use Alphano\Database\PDOMySQLDriver;

/**
 * Array di configurazione per temperatura, tempo, cielo e vento.
 */
// The module can be included from a function (preferences/environment API).
$GLOBALS['temperature'] = [
    // inverno
    [
        "max"  =>  5,
        "min"  => -20,
        -1     => ["add" => 3, "less" => -2],
        1      => ["add" => 2, "less" => -3],
    ],
    // primavera
    [
        "max"  => 20,
        "min"  => -10,
        -1     => ["add" => 5, "less" => -4],
        1      => ["add" => 4, "less" => -3],
    ],
    // estate
    [
        "max"  => 35,
        "min"  =>   0,
        -1     => ["add" => 4, "less" => -1],
        1      => ["add" => 5, "less" => -3],
    ],
    // autunno
    [
        "max"  => 20,
        "min"  => -10,
        -1     => ["add" => 4, "less" => -4],
        1      => ["add" => 5, "less" => -5],
    ],
];

// Descrizioni di tempo, cielo e vento
$GLOBALS['tempo'] = [-2=>"Pioggia",-1=>"Tempo Stazionario",0=>"Tempo Stazionario",1=>"Tempo Stazionario",2=>"Pioggia"];
$GLOBALS['cold'] = [-2=>"Neve",-1=>"Tempo Stazionario",0=>"Tempo Stazionario",1=>"Tempo Stazionario",2=>"Neve"];
$GLOBALS['cielo'] = [-2=>"Tuoni e Fulmini",-1=>"Cielo Nuvoloso",0=>"Cielo Sgombro",1=>"Cielo Nuvoloso",2=>"Tuoni e Fulmini"];
$GLOBALS['vento'] = [
    -4=>"Vento Forte",-3=>"Vento",-2=>"Brezza Leggera",-1=>"Vento Assente",
     0=>"Nebbia",1=>"Vento Assente",2=>"Brezza Leggera",3=>"Vento",4=>"Vento Forte"
];

/**
 * Applica un piccolo “drift” casuale allo stato meteo corrente.
 */
function GetNewMeteo(array $dati): array
{
    global $temperature;
    $stagione = GetStagione();
    $notte    = LicanTime();

    // drift temperatura
    $t = $dati["temperatura"] > 0 ? 1 : -1;
    $minAdd  = $temperature[$stagione][$t]["less"];
    $maxAdd  = $temperature[$stagione][$t]["add"];
    $dati["temperatura"] += rand($minAdd, $maxAdd);
    $dati["temperatura"] = max($dati["temperatura"], $temperature[$stagione]["min"]);
    $dati["temperatura"] = min($dati["temperatura"], $temperature[$stagione]["max"]);

    // drift tempo, cielo, vento
    $dati["tempo"]  = max(-2, min(2, $dati["tempo"]  + rand(-1,1)));
    $dati["cielo"]  = max(-2, min(2, $dati["cielo"]  + rand(-1,1)));
    $dati["vento"]  = max(-4, min(4, $dati["vento"]  + rand(-1,1)));

    return $dati;
}

/**
 * Restituisce l’attuale riga di meteo, aggiornandola ogni ora.
 */
function GetMeteo(): array
{
    global $db, $cielo, $vento, $temperature;
    if (!isset($db)) {
        /** @var PDOMySQLDriver $db */
        $db = ConnectDB();
    }

    $ora      = (int) date("H");
    $now      = time();
    $stagione = GetStagione();

    // carico tutte le righe
    $rows = $db->query("SELECT * FROM meteo");
    $count = count($rows);

    if ($count === 1) {
        // riga unica: drift ogni ora
        $dati = $rows[0];
        if ($ora !== (int)$dati["ora"]) {
            $dati = GetNewMeteo($dati);
            $db->execute(
                "UPDATE meteo
                 SET ora = ?, temperatura = ?, tempo = ?, cielo = ?, vento = ?
                 LIMIT 1",
                [$ora, $dati["temperatura"], $dati["tempo"], $dati["cielo"], $dati["vento"]]
            );
            $db->execute(
                "INSERT INTO logs_meteo (data, azione) VALUES (?, 'Aggiornamento')",
                [$now]
            );
        }
    } elseif ($count === 0) {
        // tabella vuota: inserisco un nuovo record
        $dati = [
            "temperatura" => rand($temperature[$stagione]["min"], $temperature[$stagione]["max"]),
            "tempo"       => rand(-2,2),
            "cielo"       => rand(-2,2),
            "vento"       => rand(-3,3),
            "ora"         => $ora
        ];
        $db->execute(
            "INSERT INTO meteo (ora, temperatura, tempo, cielo, vento)
             VALUES (?, ?, ?, ?, ?)",
            [$ora, $dati["temperatura"], $dati["tempo"], $dati["cielo"], $dati["vento"]]
        );
        $db->execute(
            "INSERT INTO logs_meteo (data, azione) VALUES (?, 'Inserimento (Tabella Vuota)')",
            [$now]
        );
    } else {
        // troppi record: resetto a uno solo
        $dati = $rows[0];
        $db->execute("DELETE FROM meteo");
        $dati = GetNewMeteo($dati);
        $db->execute(
            "INSERT INTO meteo (ora, temperatura, tempo, cielo, vento)
             VALUES (?, ?, ?, ?, ?)",
            [$ora, $dati["temperatura"], $dati["tempo"], $dati["cielo"], $dati["vento"]]
        );
        $db->execute(
            "INSERT INTO logs_meteo (data, azione) VALUES (?, 'Inserimento (Troppi record)')",
            [$now]
        );
    }

    // restituisco l’array $dati
    return $dati;
}

/**
 * Ritorna la stagione corrente (0=inverno,1=primavera,2=estate,3=autunno).
 */
function GetStagione(): int
{
    switch ((int)date("n")) {
        case 12:
        case 1:
        case 2:  return 0;
        case 3:
        case 4:
        case 5:  return 1;
        case 6:
        case 7:
        case 8:  return 2;
        default: return 3;
    }
}

/**
 * Descrive il tempo in base a temperatura e indice meteo.
 */
function GetDescrTempo(int $meteo, int $temperatura): string
{
    global $tempo, $cold;
    return ($temperatura > 0) ? $tempo[$meteo] : $cold[$meteo];
}
?>
