<?php
declare(strict_types=1);

/*---------------------------------------------------------------
 *  BOOTSTRAP per tutte le API sotto /api
 *-------------------------------------------------------------*/
header('Content-Type: application/json; charset=utf-8');

// Adatta il percorso se la tua cartella libs non è in ../libs
require __DIR__ . '/../libs/kernel.inc.php';
require __DIR__ . '/../libs/libpg.inc.php';
require __DIR__ . '/../libs/libmeteo.inc.php';
require __DIR__ . '/../libs/libgrafica.inc.php';
require __DIR__ . '/../libs/libtext.inc.php';
require __DIR__ . '/../libs/libelezioni.inc.php';
require __DIR__ . '/../libs/libblocco.inc.php';

use Alphano\Database\PDOMySQLDriver;

/**
 * @return PDOMySQLDriver
 */
function db(): PDOMySQLDriver
{
    static $db;
    return $db ??= ConnectDB();
}

/**
 * Risposta OK in JSON e termina
 * @param array $payload
 * @return never
 */
function ok(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Errore JSON
 * @param string $msg
 * @param int $code
 * @return never
 */
function error(string $msg, int $code = 400): never
{
    http_response_code($code);
    ok(['error' => $msg]);
}
