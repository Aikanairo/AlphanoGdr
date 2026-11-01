<?php
declare(strict_types=1);

use Alphano\Database\PDOMySQLDriver;

/** The daily IP quota is waived only for a loopback request to a locally configured site.
 * Never trust Host or forwarded headers alone: the live base URL keeps the quota on.
 */
function alphano_registration_is_local(): bool
{
    $localHosts=['localhost','127.0.0.1','::1'];
    $configured=trim(strtolower((string)parse_url((string)alphano_config('base_url'),PHP_URL_HOST)),'[]');
    $requested=trim(strtolower((string)parse_url('http://'.(string)($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)),'[]');
    return in_array($configured,$localHosts,true)
        && in_array($requested,$localHosts,true)
        && in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1','::ffff:127.0.0.1'],true);
}

/**
 * Restituisce true se il carattere passato è
 * un “carattere valido” (alfanumerico o accentato).
 */
function validkey(string $lettera): bool
{
    $ord = ord($lettera);

    // cifre
    if ($ord >= 48 && $ord <= 57) {
        return true;
    }
    // A–Z
    if ($ord >= 65 && $ord <= 90) {
        return true;
    }
    // a–z
    if ($ord >= 97 && $ord <= 122) {
        return true;
    }
    // lettere accentate ISO-8859-1
    if (($ord >= 192 && $ord <= 214) ||
        ($ord >= 216 && $ord <= 221) ||
        ($ord >= 224 && $ord <= 246) ||
        ($ord >= 248 && $ord <= 253)
    ) {
        return true;
    }
    // pochi simboli ammessi
    return in_array($ord, [32, 138, 142, 154, 158, 159, 255], true);
}

/**
 * Controlla che tutta la stringa sia composta
 * da caratteri “validi” e non abbia spazi iniziali/finali.
 */
function validname(string $nome): bool
{
    if ($nome !== trim($nome) || $nome === '') {
        return false;
    }
    $len = mb_strlen($nome, '8bit');
    for ($i = 0; $i < $len; $i++) {
        if (!validkey($nome[$i])) {
            return false;
        }
    }
    return true;
}

/**
 * Verifica che:
 *  - il nome non contenga parole proibite (tabella `nomivietati`)
 *  - e sia composto solo da caratteri validi.
 */
function valido(string $nome): bool
{
    // recupera il driver PDO
    $db = ConnectDB(); /** @var PDOMySQLDriver $db */

    // rimuove eventuali backslash
    $clean = stripslashes($nome);

    // prende tutti i nomi vietati
    $vietati = $db->query("SELECT nome FROM nomivietati");

    // controlla ciascun nome proibito
    foreach ($vietati as $row) {
        // stripos è case-insensitive
        if (stripos($clean, (string)$row['nome']) !== false) {
            return false;
        }
    }

    // infine, controlla la sintassi
    return validname($clean);
}
