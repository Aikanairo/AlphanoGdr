<?php
declare(strict_types=1);

use Alphano\Database\PDOMySQLDriver;

require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/astro.inc.php';
require_once __DIR__.'/alignments.inc.php';
alphano_session();
/**
 * Inizializza la sessione PHP (una sola volta).
 */
function initSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        alphano_session();
    }
}

/**
 * Restituisce l’istanza PDO riutilizzando kernel.inc.php.
 */
function getDB(): PDOMySQLDriver
{
    static $db;
    if (!$db) {
        $db = ConnectDB();
    }
    return $db;
}

/**
 * ---------- AUTENTICAZIONE ----------
 */

/**
 * Esegue il login: verifica credenziali, imposta sessione.
 *
 * @param string $username
 * @param string $password
 * @return bool
 */
function doLogin(string $username, string $password): bool
{
    initSession();
    $db = getDB();
    $rows = $db->query('SELECT id, nome, password FROM utenti WHERE nome = ? LIMIT 1', [$username]);
    if (count($rows) !== 1 || !alphano_password_verify($password, $rows[0]['password'])) return false;
    // Migrazione progressiva: gli account esistenti continuano a funzionare.
    if (password_needs_rehash($rows[0]['password'], PASSWORD_DEFAULT)) {
        $column = $db->query("SHOW COLUMNS FROM utenti LIKE 'password'")[0]['Type'] ?? '';
        if (preg_match('/(?:varchar|char)\((\d+)\)/i', $column, $match) && (int)$match[1] >= 255) {
            $db->execute('UPDATE utenti SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $rows[0]['id']]);
        }
    }
    if (!headers_sent()) session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$rows[0]['id'];
    $_SESSION['user_name'] = $rows[0]['nome'];
    return true;
}

/**
 * Svuota la sessione e disconnette.
 */
function doLogout(): void
{
    initSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}

/**
 * Controlla se l’utente è loggato e restituisce l’ID.
 * Se $requireLogin=true blocca l’esecuzione con 403.
 *
 * @param bool $requireLogin
 * @return int|null
 */
function GetIDPG($requireLogin = true): ?int
{
    // forziamo il cast: se arrivano 0/1 da int → li convertiamo
    $requireLogin = (bool)$requireLogin;
    initSession();
    if (empty($_SESSION['user_id'])) {
        if ($requireLogin) {
            header('HTTP/1.1 403 Forbidden');
            die('Devi essere loggato per accedere');
        }
        return null;
    }
    $id = (int)$_SESSION['user_id'];
    if ($id < 1 || !getDB()->query('SELECT 1 FROM utenti WHERE id = ?', [$id])) {
        unset($_SESSION['user_id'], $_SESSION['user_name']);
        if ($requireLogin) alphano_fail('Sessione scaduta. Effettua nuovamente il login.', 403);
        return null;
    }
    return $id;
}

/**
 * Restituisce il nome del PG a partire dall’ID (o null).
 */
function GetPGFromID(?int $idpg): ?string
{
    if ($idpg === null) {
        return null;
    }
    $db = getDB();
    $rows = $db->query('SELECT nome FROM utenti WHERE id = ? LIMIT 1', [$idpg]);
    return $rows[0]['nome'] ?? null;
}

/**
 * ---------- UTILITÀ TEMPO & LUCE/LUNA ----------
 */





/**
 * ---------- POSIZIONE & STATISTICHE ----------
 */

/**
 * Aggiorna la posizione corrente del PG e tiene traccia dei PF e degli off‐line.
 */
function SetPosizione(string $pos, int $idpg, string $chat = ''): void
{
    if(!empty($_SESSION['support_session_id']))return;
    $db  = getDB();
    $now = time();

    // 1) Mark offline tutti gli utenti fermi da >1h, **escludendo** l'utente corrente
    $cut = $now - 3600;
    $db->execute(
        "UPDATE utenti
            SET posizione = 'off', chat = ''
          WHERE lastmove <= ?
            AND posizione <> 'off'
            AND id <> ?",
        [$cut, $idpg]
    );

    // 2) aggiorno posizione+lastmove per l'utente corrente
    $db->execute(
        'UPDATE utenti
            SET posizione = ?, chat = ?, lastmove = ?
          WHERE id = ?',
        [$pos, $chat, $now, $idpg]
    );

    // 3) Inserisco statistica oraria (una volta al giorno per PG/ora)
    $h = (int)date('H', $now);
    $exists = $db->query(
        'SELECT 1
           FROM logs_statistica
          WHERE pg = ?
            AND ora = ?',
        [$idpg, $h]
    );
    if (count($exists) === 0) {
        $db->execute(
            'INSERT INTO logs_statistica (data, pg, ora)
             VALUES (?, ?, ?)',
            [$now, $idpg, $h]
        );
    }
    require_once __DIR__.'/lycan.inc.php';
    alphano_lycan_sync($idpg);
}


/**
 * Formatta la posizione in testo leggibile.
 */
function GetPosizione(string $pos): string
{
    return match($pos) {
        'off'    => 'Non Connesso',
        'online' => 'Parcheggiato',
        default  => htmlentities($pos, ENT_QUOTES, 'UTF-8'),
    };
}

/**
 * ---------- SEMPLICI LOOKUP ----------
 */
function GetPGID(string $nomepg): ?int
{
    if ($nomepg === '') {
        return null;
    }
    $db = getDB();
    $rows = $db->query('SELECT id FROM utenti WHERE nome = ? LIMIT 1', [$nomepg]);
    return isset($rows[0]['id']) ? (int)$rows[0]['id'] : null;
}

function GetAllineamento(int $idpg): ?string
{
    $db = getDB();
    $rows = $db->query('SELECT allineamento FROM utenti WHERE id = ? LIMIT 1', [$idpg]);
    return $rows[0]['allineamento'] ?? null;
}

function processAl(string $al): string
{
    return alphano_alignment($al)['name']??$al;
}

function GetRazza(int $r): string
{
    if ($r === 0) return 'Non assegnata';
    $db = getDB();
    $rows = $db->query('SELECT nome FROM razze WHERE id = ? LIMIT 1', [$r]);
    return $rows[0]['nome'] ?? 'Razza non configurata';
}

function GetForzaRazza(int $r): ?int
{
    $db = getDB();
    $rows = $db->query('SELECT forza FROM razze WHERE id = ? LIMIT 1', [$r]);
    return isset($rows[0]['forza']) ? (int)$rows[0]['forza'] : null;
}

/**
 * Restituisce un array di [id, nome] per tutte le razze.
 */
function ListaRazze(bool $visibili = false): array
{
    $db = getDB();
    if ($visibili) {
        return $db->query('SELECT * FROM razze ORDER BY nome ASC');
    }
    return $db->query(
        'SELECT razze.*
         FROM razze
         JOIN utenti ON razze.id=utenti.razza
         GROUP BY utenti.razza
         ORDER BY razze.nome ASC'
    );
}


/**
 * ---------- PROTEZIONE IP ----------
 */
function IsLock(string $ip): bool
{
    // No real network addresses are preconfigured in the distributed engine.
    return false;
}

/* =========================================================
 *  UTILITÀ BREVETI BASE64
 * =======================================================*/
function CriptaNome(string $plain): string   { return base64_encode($plain); }
function DecriptaNome(string $crypt): string { return base64_decode($crypt); }

/* =========================================================
 *  SHORTCUT NOME PG & CREDENZIALI ADMIN
 * =======================================================*/
/**
 * Ritorna il nome del PG loggato (o null se guest).
 */
function GetPGName(bool $requireLogin = true): ?string
{
    return GetPGFromID(GetIDPG($requireLogin));
}

/**
 * Con il nuovo sistema salviamo la chiave admin in sessione, non più in cookie criptati.
 */
function GetAdminKey(): ?string
{
    initSession();
    return $_SESSION['admin_key'] ?? null;
}

function GetPGPasswd(): ?string   // hashed o plain, dipende da come la salvi al login
{
    initSession();
    return $_SESSION['user_pass'] ?? null;
}

/* =========================================================
 *  RAZZE • ETNIE • ICONCINE
 * =======================================================*/
/**
 * Produci HTML dell’icona razza + eventuale etnia.
 */
function alphano_race_icon(int $race,string $sex):string
{
    return '/assets/avatar.svg';
}

function processRazza(int $razzaId, string $sesso, int $etniaId = 0): string
{
    if ($razzaId === ALPHANO_RACE_LEGACY_SPIRIT) $razzaId = ALPHANO_RACE_SPIRIT;
    $db     = getDB();
    $razza  = $db->query('SELECT nome FROM razze WHERE id=?', [$razzaId])[0]['nome'] ?? '';
    $etniaT = $etniaId ? ' ' . htmlspecialchars(GetEtnia($etniaId) ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';

    $file = alphano_race_icon($razzaId, $sesso);

    return sprintf(
        '<img src="%s" class="race-emblem" width="26" height="26" alt="%s%s" title="%s%s" />',
        $file,
        htmlentities($razza),
        $etniaT,
        htmlentities($razza),
        $etniaT
    );
}

function GetEtnia(int $id): ?string
{
    $db = getDB();
    return $db->query('SELECT nome FROM etnie WHERE id=?', [$id])[0]['nome'] ?? null;
}

function ListaEtnie(): array
{
    $db = getDB();
    return $db->query('SELECT * FROM etnie ORDER BY nome');
}

/* =========================================================
 *  FIRMA PG & ARALDICHE
 * =======================================================*/
function FirmaPG(int $idpg): string
{
    $db  = getDB();
    $row = $db->query(
        'SELECT nome, firma,
                caricaorganizzazione, caricacorporazione,
                araldica, organizzazione, corporazione
           FROM utenti WHERE id=?',
        [$idpg]
    )[0] ?? [];

    if (!empty($row['firma'])) {
        return "\n\n".$row['firma'];
    }

    $stamp   = [];
    $stamp[] = $row['nome'] ?? '';

    /* Organizzazione */
    if ($row['organizzazione']) {
        $orgName = $db->query('SELECT nome FROM organizzazioni WHERE id=?', [$row['organizzazione']])[0]['nome'] ?? '';
        $carName = $db->query('SELECT nome FROM caricheorganizzazioni WHERE id=?', [$row['caricaorganizzazione']])[0]['nome'] ?? '';
        $stamp[] = sprintf('[i]%s[/i]%s dell’Organizzazione %s',alphano_badge_url('organizzazioni',(int)$row['caricaorganizzazione'],(int)$row['organizzazione']),$carName,$orgName);
    }

    /* Corporazione */
    if ($row['corporazione']) {
        $corpName = $db->query('SELECT nome FROM corporazioni WHERE id=?', [$row['corporazione']])[0]['nome'] ?? '';
        $carName  = $db->query('SELECT nome FROM carichecorporazioni WHERE id=?', [$row['caricacorporazione']])[0]['nome'] ?? '';
        $stamp[]  = sprintf('[i]%s[/i]%s della Corporazione %s',alphano_badge_url('corporazioni',(int)$row['caricacorporazione'],(int)$row['corporazione']),$carName,$corpName);
    }

    /* Araldica */
    $nomeAr = GetAraldica($row['araldica'] ?? 0) ?? '';
    $stamp[] = sprintf('[i]%s[/i]%s',alphano_badge_url('cariche',(int)$row['araldica']),$nomeAr);

    return "\n\n".implode("\n", array_filter($stamp));
}

function GetAraldica(int $id): ?string
{
    $db = getDB();
    return $db->query('SELECT nome FROM araldiche WHERE id=?', [$id])[0]['nome'] ?? null;
}

/* =========================================================
 *  FIRMA PG & ARALDICHE  ─ processAraldica()
 * =======================================================*/

/**
 * Ritorna l’elemento <img> per lo stemma araldico del PG.
 *
 * ✔️ Compatibile PHP 8 (type-hint)
 * ✔️ Se $jsVar è passato, attacca gli handler onmouseover / onmouseout.
 * ✔️ Se non c’è descrizione, usa alt/title con il nome nello stemma.
 *
 * @param int    $id     id della voce in tabella «araldiche»
 * @param string $jsVar  testo JS da mostrare nel tooltip ('' = alt/title)
 * @return string        markup HTML completo
 */
/** Percorso pubblico di uno stemma, con fallback per gli asset storici assenti. */
function alphano_badge_url(string $type, int $id, int $scope = 0): string
{
    if (!in_array($type, ['cariche','organizzazioni','corporazioni'], true)) throw new InvalidArgumentException('Tipo stemma non valido');
    $path = '/uploads/badges/' . $type . '/' . ($scope > 0 ? $scope . '/' : '') . max(0,$id) . '.gif';
    foreach ([$path] as $candidate) {
        foreach ([htmlroot . $candidate, dirname(__DIR__) . $candidate] as $file) {
            if (!is_file($file)) continue;
            // Rank insignia remain editable through the existing GIF upload.
            // Refresh cached art after either a release or an administrator upload.
            return $candidate . '?v=' . filemtime($file);
        }
    }
    return '/assets/placeholder.svg';
}

function processAraldica(int $id, string $jsVar = ''): string
{
    // Recupero nome araldica (title/alt) – ritorno stringa vuota se non esiste
    $nome = GetAraldica($id);
    if ($nome === null) {
        return '';
    }

    // Path immagine (la tua struttura è /uploads/badges/cariche/<id>.gif)
    $src = alphano_badge_url('cariche', $id);

    // Attributi accessibilità + eventuale tooltip JS
    if (preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*(?:\[[0-9]+\])?$/D', $jsVar)) {
        $attr = "onmouseover=\"MostraDescrizione('hint',{$jsVar})\" " .
                "onmouseout=\"NascondiDescrizione('hint')\"";
    } else {
        $safe = htmlentities($nome, ENT_QUOTES | ENT_SUBSTITUTE);
        $attr = "alt=\"{$safe}\" title=\"{$safe}\"";
    }

    // Sempre 25×25 come nel codice originario
    return "<img src=\"{$src}\" width=\"25\" height=\"25\" {$attr} />";
}


function ListaAraldiche(): array
{
    return getDB()->query('SELECT id, nome, city FROM araldiche ORDER BY nome');
}

/* ----------  Add & Modify Araldica  ------------------------------------ */
function AddAraldica(
    string $nome,
    bool   $prima,
    int    $city,
    string $descrizione,
    int    $ramo,
    int    $livello,
    array  $file
): string {
    $db=getDB();
    if(!alphano_badge_scope('araldiche',$city,$ramo) || trim($nome)==='' || $livello<0) return 'Dati della carica non validi';
    $valid=_validaUpload($file);if($valid!==true)return $valid;
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $db->execute('INSERT INTO araldiche (nome,prima,city,ramo,livello,descrizione) VALUES (?,?,?,?,?,?)',[$nome,$prima?1:0,$city,$ramo,$livello,$descrizione]);
        $id=(int)$db->lastInsertId();
        if(!alphano_save_badge($file,'/uploads/badges/cariche/' . $id . '.gif'))throw new RuntimeException('Impossibile salvare immagine');
        if($owned)$db->commit();return 'Carica inserita con successo';
    } catch(Throwable $e) {if($owned && $db->inTransaction())$db->rollBack();return 'Impossibile salvare la carica';}
}

function ModifyAraldica(
    int    $id,
    string $nome,
    bool   $prima,
    int    $city,
    string $descrizione,
    int    $ramo,
    int    $livello,
    array  $file
): string {
    $db=getDB();
    if(!alphano_badge_scope('araldiche',$city,$ramo) || trim($nome)==='' || $livello<0) return 'Dati della carica non validi';
    $valid=_validaUpload($file);if($valid!==true)return $valid;
    if(!$db->query('SELECT id FROM araldiche WHERE id=? AND city=?',[$id,$city]))return 'Carica non trovata';
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $db->execute('UPDATE araldiche SET nome=?,prima=?,city=?,ramo=?,livello=?,descrizione=? WHERE id=? AND city=?',[$nome,$prima?1:0,$city,$ramo,$livello,$descrizione,$id,$city]);
        if(!alphano_save_badge($file,'/uploads/badges/cariche/' . $id . '.gif'))throw new RuntimeException('Impossibile salvare immagine');
        if($owned)$db->commit();return 'Carica aggiornata';
    } catch(Throwable $e) {if($owned && $db->inTransaction())$db->rollBack();return 'Impossibile salvare la carica';}
}

/* =========================================================
 *  CITY – CITTADINANZA
 * =======================================================*/
function GetListaCity(bool $elezioni = false): array
{
    $sql = 'SELECT * FROM city'.($elezioni ? ' WHERE elezioni=1' : '').' ORDER BY city';
    return getDB()->query($sql);
}

function GetListaRamiCity(bool $all, int $city): array
{
    $db  = getDB();
    $sql = $all
        ? 'SELECT * FROM ramiaraldiche ORDER BY livello DESC'
        : 'SELECT * FROM ramiaraldiche WHERE city=? ORDER BY livello DESC';

    $rows = $db->query($sql, $all ? [] : [$city]);
    $out  = [];
    foreach ($rows as $r) {
        $out[$r['city']][$r['id']] = $r;
    }
    return $out;
}

function GetGerarchiaCity(int $city): array
{
    return getDB()->query(
        'SELECT a.*, r.nome AS nomeramo, r.livello
           FROM araldiche a
           JOIN ramiaraldiche r ON r.id=a.ramo
          WHERE a.city=?
          ORDER BY r.livello, a.livello',
        [$city]
    );
}

function MakeRichiestaCity(int $city, int $idpg): void
{
    $db = getDB();
    $db->execute('INSERT INTO richieste (idpg, data, city) VALUES (?,?,?)', [$idpg, time(), $city]);

    $admins = $db->query(
        'SELECT u.id
           FROM utenti u
           JOIN araldiche a ON a.id=u.araldica
          WHERE a.city=? AND u.sindaco=1',
        [$city]
    );
    foreach ($admins as $ad) {
        InviaMissiva(
            'Notifica Richiesta di Cittadinanza',
            'Hai ricevuto una nuova richiesta di ammissione',
            (int)$ad['id']
        );
    }
}

function GetRichiesteCity(int $city): array
{
    return getDB()->query(
        'SELECT idpg, data FROM richieste WHERE city=? ORDER BY data',
        [$city]
    );
}

function GetRichiesteCityPG(int $pg): int
{
    return (int)getDB()->query(
        'SELECT COUNT(*) AS num FROM richieste WHERE idpg=? AND city<>0',
        [$pg]
    )[0]['num'];
}

function GetLastCaricaCity(int $city): ?int
{
    $row = getDB()->query(
        'SELECT id FROM araldiche WHERE city=? AND prima=1 LIMIT 1',
        [$city]
    );
    return $row[0]['id'] ?? null;
}

function GetPGCity(int $idpg): ?int
{
    $row = getDB()->query(
        'SELECT a.city
           FROM utenti u
           JOIN araldiche a ON a.id=u.araldica
          WHERE u.id=?',
        [$idpg]
    );
    return $row[0]['city'] ?? null;
}

/* =========================================================
 *  RICHIESTE (contatori rapidi)
 * =======================================================*/
function GetRichiesteOrgPG(int $pg): int
{
    return (int)getDB()->query(
        'SELECT COUNT(*) AS num FROM richieste WHERE idpg=? AND organizzazione<>0',
        [$pg]
    )[0]['num'];
}

function GetRichiesteCorpPG(int $pg): int
{
    return (int)getDB()->query(
        'SELECT COUNT(*) AS num FROM richieste WHERE idpg=? AND corporazione<>0',
        [$pg]
    )[0]['num'];
}

/* =========================================================
 *  ORGANIZZAZIONI
 * =======================================================*/
function GetOrg(int $id, bool $nessuna = true): string
{
    $row = getDB()->query('SELECT nome FROM organizzazioni WHERE id=?', [$id])[0]['nome'] ?? '';
    return $row ?: ($nessuna ? 'Nessuna' : '');
}

function GetCaricaOrg(int $org, int $carica, bool $appendName = true, string $jsVar = ''): string
{
    $row = getDB()->query(
        'SELECT nome, descrizione FROM caricheorganizzazioni WHERE organizzazione=? AND id=?',
        [$org, $carica]
    )[0] ?? null;
    if (!$row) { return 'Nessuna'; }

    $jsVar = preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*(?:\[[0-9]+\])?$/D', $jsVar) ? $jsVar : '';
    $attr = $jsVar && $row['descrizione']
        ? "onmouseover=\"MostraDescrizione('hint',$jsVar)\" onmouseout=\"NascondiDescrizione('hint')\""
        : 'alt="'.htmlentities($row['nome']).'" title="'.htmlentities($row['nome']).'"';

    $img = sprintf('<img src="%s" width="25" height="25" %s />',
                   alphano_badge_url('organizzazioni', $carica, $org), $attr);

    return $appendName ? $img.'&nbsp;'.htmlspecialchars($row['nome'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $img;
}

function GetOrgMasters(int $org): array
{
    return getDB()->query(
        'SELECT id, nome FROM utenti WHERE organizzazione=? AND adminorganizzazione=1 ORDER BY nome',
        [$org]
    );
}

function ListaOrg(): array
{
    return getDB()->query('SELECT id, nome FROM organizzazioni WHERE approvato=1 ORDER BY nome');
}

function GetListaRamiOrg(int $org): array
{
    $rows = getDB()->query(
        'SELECT id, nome FROM ramiorganizzazioni WHERE organizzazione=? ORDER BY livello DESC',
        [$org]
    );
    $out = [];
    foreach ($rows as $r) { $out[$r['id']] = $r['nome']; }
    return $out;
}

function GetListaCaricheOrg(int $org): array
{
    return getDB()->query(
        'SELECT * FROM caricheorganizzazioni WHERE organizzazione=? ORDER BY nome',
        [$org]
    );
}

function GetGerarchiaOrg(int $org): array
{
    return getDB()->query(
        'SELECT o.id      AS idorg,
                o.nome    AS nomeorg,
                c.id      AS idcarica,
                c.nome    AS nomecarica,
                c.prima,
                c.descrizione,
                c.ramo,
                r.nome    AS nomeramo,
                r.livello
           FROM organizzazioni o
           JOIN caricheorganizzazioni c ON c.organizzazione=o.id
           JOIN ramiorganizzazioni r     ON r.id=c.ramo
          WHERE o.id=?
          ORDER BY r.livello, c.ramo, c.livello',
        [$org]
    );
}

function MakeRichiestaOrg(int $org, int $pg): bool
{
    $db=getDB();
    $db->beginTransaction();
    try {
        $user=$db->query('SELECT id, organizzazione FROM utenti WHERE id=? FOR UPDATE',[$pg])[0]??null;
        $group=$db->query('SELECT id FROM organizzazioni WHERE id=? AND approvato=1',[$org]);
        if (!$user || !$group || (int)$user['organizzazione']!==0 || $db->query('SELECT 1 FROM richieste WHERE idpg=? AND organizzazione=?',[$pg,$org])) { $db->rollBack(); return false; }
        $db->execute('INSERT INTO richieste(idpg,data,organizzazione) VALUES(?,?,?)',[$pg,time(),$org]);
        $admins=$db->query('SELECT id FROM utenti WHERE organizzazione=? AND adminorganizzazione=1',[$org]);
        require_once __DIR__.'/libposta.inc.php';
        foreach($admins as $admin) InviaMissiva('Richiesta di Ammissione','Hai ricevuto una nuova richiesta di ammissione.',(int)$admin['id']);
        $db->commit();return true;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}

function GetRichiesteOrg(int $org): array
{
    return getDB()->query(
        'SELECT idpg, data FROM richieste WHERE organizzazione=? ORDER BY data',
        [$org]
    );
}

function AdmitPGOrg(int $pg, int $org, int $carica, bool $admit): bool
{
    $db=getDB();$owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $user=$db->query('SELECT id,organizzazione FROM utenti WHERE id=? FOR UPDATE',[$pg])[0]??null;
        $rank=$admit?($db->query('SELECT id,ramo FROM caricheorganizzazioni WHERE id=? AND organizzazione=?',[$carica,$org])[0]??null):null;
        if(!$user || ($admit && (!$rank || ((int)$user['organizzazione']!==0 && (int)$user['organizzazione']!==$org))) || (!$admit && (int)$user['organizzazione']!==$org)) { if($owned)$db->rollBack();return false; }
        $db->execute('DELETE FROM richieste WHERE organizzazione=? AND idpg=?',[$org,$pg]);
        $db->execute('UPDATE utenti SET organizzazione=?,caricaorganizzazione=?,ramoorganizzazione=? WHERE id=?',[$admit?$org:0,$admit?$carica:0,$admit?(int)$rank['ramo']:0,$pg]);
        if(!$admit)$db->execute('UPDATE utenti SET adminorganizzazione=0 WHERE id=?',[$pg]);
        if($owned)$db->commit();return true;
    } catch(Throwable $e) { if($owned && $db->inTransaction())$db->rollBack();return false; }
}

function GetLastCaricaOrg(int $org): ?int
{
    $row = getDB()->query(
        'SELECT id FROM caricheorganizzazioni WHERE organizzazione=? AND prima=1 LIMIT 1',
        [$org]
    );
    return $row[0]['id'] ?? null;
}

/* ----------  Add / Modify / Drop CARICHE ORG  --------------------------- */
function AddCaricaOrg(
    int    $org,
    string $nome,
    bool   $prima,
    int    $ramo,
    int    $livello,
    string $descrizione,
    array  $file
): string {
    $db=getDB();
    if(!alphano_badge_scope('organizzazioni',$org,$ramo) || trim($nome)==='' || $livello<0) return 'Dati della carica non validi';
    $valid=_validaUpload($file);if($valid!==true)return $valid;
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $db->execute('INSERT INTO caricheorganizzazioni (nome,prima,organizzazione,ramo,livello,descrizione) VALUES (?,?,?,?,?,?)',[$nome,$prima?1:0,$org,$ramo,$livello,$descrizione]);
        $id=(int)$db->lastInsertId();
        if(!alphano_save_badge($file,'/uploads/badges/organizzazioni/' . $org . '/' . $id . '.gif'))throw new RuntimeException('Impossibile salvare immagine');
        if($owned)$db->commit();return 'Carica organizzazione creata';
    } catch(Throwable $e) {if($owned && $db->inTransaction())$db->rollBack();return 'Impossibile salvare la carica';}
}

function ModifyCaricaOrg(
    int    $org,
    int    $id,
    string $nome,
    bool   $prima,
    int    $ramo,
    int    $livello,
    string $descrizione,
    array  $file
): string {
    $db=getDB();
    if(!alphano_badge_scope('organizzazioni',$org,$ramo) || trim($nome)==='' || $livello<0) return 'Dati della carica non validi';
    $valid=_validaUpload($file);if($valid!==true)return $valid;
    if(!$db->query('SELECT id FROM caricheorganizzazioni WHERE id=? AND organizzazione=?',[$id,$org]))return 'Carica non trovata';
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $db->execute('UPDATE caricheorganizzazioni SET nome=?,prima=?,organizzazione=?,ramo=?,livello=?,descrizione=? WHERE id=? AND organizzazione=?',[$nome,$prima?1:0,$org,$ramo,$livello,$descrizione,$id,$org]);
        if(!alphano_save_badge($file,'/uploads/badges/organizzazioni/' . $org . '/' . $id . '.gif'))throw new RuntimeException('Impossibile salvare immagine');
        if($owned)$db->commit();return 'Carica organizzazione aggiornata';
    } catch(Throwable $e) {if($owned && $db->inTransaction())$db->rollBack();return 'Impossibile salvare la carica';}
}

function DropRamoOrg(int $org, int $ramo): bool
{
    $db = getDB();
    $used = $db->query(
        'SELECT COUNT(*) AS num FROM caricheorganizzazioni WHERE organizzazione=? AND ramo=?',
        [$org, $ramo]
    )[0]['num'];
    if ($used) { return false; }

    $db->execute('DELETE FROM ramiorganizzazioni WHERE organizzazione=? AND id=?', [$org, $ramo]);
    return true;
}

function DropCaricaOrg(int $org, int $id): bool
{
    $db=getDB();
    if($db->query('SELECT 1 FROM utenti WHERE organizzazione=? AND caricaorganizzazione=? LIMIT 1',[$org,$id]))return false;
    $deleted=$db->execute('DELETE FROM caricheorganizzazioni WHERE organizzazione=? AND id=?',[$org,$id]);
    $file=htmlroot.'/uploads/badges/organizzazioni/'.$org.'/'.$id.'.gif';
    if($deleted && is_file($file))unlink($file);
    return $deleted>0;
}

function GetPGInOrg(int $org): array
{
    return getDB()->query(
        'SELECT id, nome FROM utenti WHERE organizzazione=? ORDER BY nome',
        [$org]
    );
}

/* =========================================================
 *  CORPORAZIONI  (stessa logica delle organizzazioni)
 * =======================================================*/
function GetCorporazione(int $id): string
{
    $row = getDB()->query('SELECT nome FROM corporazioni WHERE id=?', [$id])[0]['nome'] ?? '';
    return $row ?: 'Nessuna';
}

function GetCaricaCorp(int $corp, int $carica, bool $appendName = true, string $jsVar = ''): string
{
    $row = getDB()->query(
        'SELECT nome, descrizione FROM carichecorporazioni WHERE corporazione=? AND id=?',
        [$corp, $carica]
    )[0] ?? null;
    if (!$row) { return 'Nessuna'; }

    $jsVar = preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*(?:\[[0-9]+\])?$/D', $jsVar) ? $jsVar : '';
    $attr = $jsVar && $row['descrizione']
        ? "onmouseover=\"MostraDescrizione('hint',$jsVar)\" onmouseout=\"NascondiDescrizione('hint')\""
        : 'alt="'.htmlentities($row['nome']).'" title="'.htmlentities($row['nome']).'"';

    $img = sprintf('<img src="%s" width="25" height="25" %s />',
                   alphano_badge_url('corporazioni', $carica, $corp), $attr);
    return $appendName ? $img.'&nbsp;'.htmlspecialchars($row['nome'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $img;
}

function GetCorpMasters(int $corp): array
{
    return getDB()->query(
        'SELECT id, nome FROM utenti WHERE corporazione=? AND admincorporazione=1 ORDER BY nome',
        [$corp]
    );
}

function ListaCorporazioni(): array
{
    return getDB()->query('SELECT id, nome FROM corporazioni WHERE approvato=1 ORDER BY nome');
}

function GetListaRamiCorp(int $corp): array
{
    $rows = getDB()->query(
        'SELECT id, nome FROM ramicorporazioni WHERE corporazione=? ORDER BY livello DESC',
        [$corp]
    );
    $out = [];
    foreach ($rows as $r) { $out[$r['id']] = $r['nome']; }
    return $out;
}

function GetListaCaricheCorp(int $corp): array
{
    return getDB()->query(
        'SELECT * FROM carichecorporazioni WHERE corporazione=? ORDER BY nome',
        [$corp]
    );
}

function GetGerarchiaCorp(int $corpId)
{
    return getDB()->query(
        <<<SQL
        SELECT
          c.id           AS idcarica,
          c.corporazione AS corporazione,   -- il vero nome del campo
          c.livello,
          c.prima, c.stipendio, c.provvigione,
          c.ramo,
          r.nome         AS nomeramo,
          c.nome         AS nomecarica
        FROM carichecorporazioni AS c
        LEFT JOIN ramicorporazioni AS r
          ON r.id = c.ramo
        WHERE c.corporazione = ?
        ORDER BY c.livello, c.ramo, c.id
        SQL
    , [$corpId]);
}


function MakeRichiestaCorp(int $corp, int $pg): bool
{
    $db=getDB();
    $db->beginTransaction();
    try {
        $user=$db->query('SELECT id, corporazione FROM utenti WHERE id=? FOR UPDATE',[$pg])[0]??null;
        $group=$db->query('SELECT id FROM corporazioni WHERE id=? AND approvato=1',[$corp]);
        if (!$user || !$group || (int)$user['corporazione']!==0 || $db->query('SELECT 1 FROM richieste WHERE idpg=? AND corporazione=?',[$pg,$corp])) { $db->rollBack(); return false; }
        $db->execute('INSERT INTO richieste(idpg,data,corporazione) VALUES(?,?,?)',[$pg,time(),$corp]);
        $admins=$db->query('SELECT id FROM utenti WHERE corporazione=? AND admincorporazione=1',[$corp]);
        require_once __DIR__.'/libposta.inc.php';
        foreach($admins as $admin) InviaMissiva('Richiesta di Ammissione','Hai ricevuto una nuova richiesta di ammissione.',(int)$admin['id']);
        $db->commit();return true;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}

function GetRichiesteCorp(int $corp): array
{
    return getDB()->query(
        'SELECT idpg, data FROM richieste WHERE corporazione=? ORDER BY data',
        [$corp]
    );
}

function AdmitPGCorp(int $pg, int $corp, int $carica, bool $admit): bool
{
    $db=getDB();$owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $user=$db->query('SELECT id,corporazione FROM utenti WHERE id=? FOR UPDATE',[$pg])[0]??null;
        $rank=$admit?($db->query('SELECT id,ramo FROM carichecorporazioni WHERE id=? AND corporazione=?',[$carica,$corp])[0]??null):null;
        if(!$user || ($admit && (!$rank || ((int)$user['corporazione']!==0 && (int)$user['corporazione']!==$corp))) || (!$admit && (int)$user['corporazione']!==$corp)) { if($owned)$db->rollBack();return false; }
        $db->execute('DELETE FROM richieste WHERE corporazione=? AND idpg=?',[$corp,$pg]);
        $db->execute('UPDATE utenti SET corporazione=?,caricacorporazione=?,ramocorporazione=? WHERE id=?',[$admit?$corp:0,$admit?$carica:0,$admit?(int)$rank['ramo']:0,$pg]);
        if(!$admit)$db->execute('UPDATE utenti SET admincorporazione=0 WHERE id=?',[$pg]);
        if($owned)$db->commit();return true;
    } catch(Throwable $e) { if($owned && $db->inTransaction())$db->rollBack();return false; }
}

function GetLastCaricaCorp(int $corp): ?int
{
    $row = getDB()->query(
        'SELECT id FROM carichecorporazioni WHERE corporazione=? AND prima=1 LIMIT 1',
        [$corp]
    );
    return $row[0]['id'] ?? null;
}

/* ----------  Add / Modify / Drop CARICHE CORP  --------------------------- */
function AddCaricaCorp(
    int    $corp,
    string $nome,
    bool   $prima,
    int    $ramo,
    int    $livello,
    string $descrizione,
    float  $stipendio,
    float  $provvigione,
    array  $file
): string {
    $db=getDB();
    if(!alphano_badge_scope('corporazioni',$corp,$ramo) || trim($nome)==='' || $livello<0) return 'Dati della carica non validi';
    $valid=_validaUpload($file);if($valid!==true)return $valid;
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $db->execute('INSERT INTO carichecorporazioni (nome,prima,corporazione,ramo,livello,descrizione,stipendio,provvigione) VALUES (?,?,?,?,?,?,?,?)',[$nome,$prima?1:0,$corp,$ramo,$livello,$descrizione,$stipendio,$provvigione]);
        $id=(int)$db->lastInsertId();
        if(!alphano_save_badge($file,'/uploads/badges/corporazioni/' . $corp . '/' . $id . '.gif'))throw new RuntimeException('Impossibile salvare immagine');
        if($owned)$db->commit();return 'Carica corporazione creata';
    } catch(Throwable $e) {if($owned && $db->inTransaction())$db->rollBack();return 'Impossibile salvare la carica';}
}

function ModifyCaricaCorp(
    int    $corp,
    int    $id,
    string $nome,
    bool   $prima,
    int    $ramo,
    int    $livello,
    string $descrizione,
    float  $stipendio,
    float  $provvigione,
    array  $file
): string {
    $db=getDB();
    if(!alphano_badge_scope('corporazioni',$corp,$ramo) || trim($nome)==='' || $livello<0) return 'Dati della carica non validi';
    $valid=_validaUpload($file);if($valid!==true)return $valid;
    if(!$db->query('SELECT id FROM carichecorporazioni WHERE id=? AND corporazione=?',[$id,$corp]))return 'Carica non trovata';
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try {
        $db->execute('UPDATE carichecorporazioni SET nome=?,prima=?,corporazione=?,ramo=?,livello=?,descrizione=?,stipendio=?,provvigione=? WHERE id=? AND corporazione=?',[$nome,$prima?1:0,$corp,$ramo,$livello,$descrizione,$stipendio,$provvigione,$id,$corp]);
        if(!alphano_save_badge($file,'/uploads/badges/corporazioni/' . $corp . '/' . $id . '.gif'))throw new RuntimeException('Impossibile salvare immagine');
        if($owned)$db->commit();return 'Carica corporazione aggiornata';
    } catch(Throwable $e) {if($owned && $db->inTransaction())$db->rollBack();return 'Impossibile salvare la carica';}
}

function DropRamoCorp(int $corp, int $ramo): bool
{
    $db = getDB();
    $used = $db->query(
        'SELECT COUNT(*) AS num FROM carichecorporazioni WHERE corporazione=? AND ramo=?',
        [$corp, $ramo]
    )[0]['num'];
    if ($used) { return false; }

    $db->execute('DELETE FROM ramicorporazioni WHERE corporazione=? AND id=?', [$corp, $ramo]);
    return true;
}

function DropCaricaCorp(int $corp, int $id): bool
{
    $db=getDB();
    if($db->query('SELECT 1 FROM utenti WHERE corporazione=? AND caricacorporazione=? LIMIT 1',[$corp,$id]))return false;
    $deleted=$db->execute('DELETE FROM carichecorporazioni WHERE corporazione=? AND id=?',[$corp,$id]);
    $file=htmlroot.'/uploads/badges/corporazioni/'.$corp.'/'.$id.'.gif';
    if($deleted && is_file($file))unlink($file);
    return $deleted>0;
}

function GetPGInCorp(int $corp): array
{
    return getDB()->query(
        'SELECT id, nome FROM utenti WHERE corporazione=? ORDER BY nome',
        [$corp]
    );
}

/* =========================================================
 *  UMORI
 * =======================================================*/
function GetListaUmori(): array
{
    return getDB()->query('SELECT id, nome FROM umori ORDER BY nome');
}

function GetUmore(int $id): string
{
    $row = getDB()->query('SELECT nome FROM umori WHERE id=?', [$id])[0] ?? null;
    return $row
        ? sprintf('<img src="/assets/placeholder.svg" alt="%s" title="%s" />',
                  htmlentities($row['nome']), htmlentities($row['nome']))
        : '';
}

/* =========================================================
 *  ADMIN & SICUREZZA
 * =======================================================*/
function EnsureAdmin(): void
{
    // 1) Prendi l’ID del personaggio (o utente) già autenticato
    $idpg = GetIDPG(false);
    if ($idpg === null) {
        // non loggato
        header('Location: /login.php');
        exit;
    }

    // 2) Recupera il driver PDO dal singleton
    /** @var PDOMySQLDriver $db */
    $db = getDB();

    // 3) Leggi il campo `admin` dalla tabella `utenti`
    $row = $db->query(
        'SELECT admin FROM utenti WHERE id = ? LIMIT 1',
        [$idpg]
    )[0] ?? null;

    $isAdmin = $row && ((int)$row['admin'] === 1);

    if (! $isAdmin) {
        // non sei admin, neghi l’accesso
        http_response_code(403);
        exit('Accesso negato: privilegi insufficienti.');
    }
}

function IsAdmin(int $pg): bool
{
    return (bool)(getDB()->query('SELECT admin FROM utenti WHERE id=?', [$pg])[0]['admin'] ?? false);
}

/* =========================================================
 *  ANALISI ACCOUNT (DOPPI) – LOGS
 * =======================================================*/
function TrovaDoppi(
    int  $pg,
    bool $trasferimenti = false,
    bool $ricompense    = false,
    bool $concatena     = false
): array {
    $db  = getDB();

    /* 1) Tutti gli IP usati dal PG */
    $ips = array_column(
        $db->query('SELECT DISTINCT ip FROM logs_ingressi WHERE pg=?', [$pg]),
        'ip'
    );
    if (!$ips) { return []; }

    /* 2) Tutti i PG che sono passati per quegli IP */
    $place = implode(',', array_fill(0, count($ips), '?'));
    $idSet = array_column(
        $db->query("SELECT DISTINCT pg FROM logs_ingressi WHERE ip IN ($place)", $ips),
        'pg'
    );

    $idsTrasf = $idsRic = [];
    if ($trasferimenti) {
        $idsTrasf = array_unique(array_merge(...$db->query(
            "SELECT esecutore, destinatario
               FROM logs_adminpg
              WHERE azione='trasferimento'
                AND esecutore IN (".implode(',', $idSet).")
                AND destinatario IN (".implode(',', $idSet).")"
        )));
    }
    if ($ricompense) {
        $idsRic = array_unique(array_merge(...$db->query(
            "SELECT esecutore, destinatario
               FROM logs_adminpg
              WHERE azione LIKE 'ricompensa(%'
                AND esecutore IN (".implode(',', $idSet).")
                AND destinatario IN (".implode(',', $idSet).")"
        )));
    }

    return $concatena
        ? array_values(array_intersect($idSet, $trasferimenti ? $idsTrasf : $idSet, $ricompense ? $idsRic : $idSet))
        : array_values(array_unique(array_merge($idSet, $idsTrasf, $idsRic)));
}

/* =========================================================
 *  CONTATORE VISITE PROFILO
 * =======================================================*/
function IncrementaVisite(int $visitatore, int $visitato): bool
{
    $db   = getDB();
    $when = strtotime('-1 day');

    $exists = $db->query(
        'SELECT 1 FROM logs_visite WHERE esecutore=? AND destinatario=? AND data>=?',
        [$visitatore, $visitato, $when]
    );
    if ($exists) { return false; }

    $now = time();
    $db->execute(
        'INSERT INTO logs_visite (data, esecutore, destinatario) VALUES (?,?,?)',
        [$now, $visitatore, $visitato]
    );
    $db->execute('UPDATE utenti SET visite = visite + 1 WHERE id=?', [$visitato]);
    return true;
}

/* =========================================================
 *  VALIDATORE GENERICO UPLOAD GIF/PNG/JPG <= 25×25 | <= 50 KB
 * =======================================================*/
function _validaUpload(array $file)
{
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error===UPLOAD_ERR_NO_FILE)return true;
    if($error!==UPLOAD_ERR_OK)return 'Errore upload';
    $path=$file['tmp_name']??'';
    if(!is_string($path) || !is_uploaded_file($path) || filesize($path)>50000)return 'Upload non valido o oltre 50 KB';
    $image=@getimagesize($path);
    if(!$image || $image[0]<1 || $image[1]<1 || $image[0]>25 || $image[1]>25)return 'Immagine non valida (max 25×25)';
    if(!in_array($image[2],[IMAGETYPE_GIF,IMAGETYPE_PNG,IMAGETYPE_JPEG],true))return 'Formato non valido (gif/png/jpg)';
    return true;
}




function alphano_badge_scope(string $type, int $group, int $branch): bool
{
    $map=['araldiche'=>['city','ramiaraldiche','city'],'organizzazioni'=>['organizzazioni','ramiorganizzazioni','organizzazione'],'corporazioni'=>['corporazioni','ramicorporazioni','corporazione']];
    if(!isset($map[$type]))return false;
    [$table,$branches,$column]=$map[$type];
    if(!($type==='araldiche' && $group===0) && !getDB()->query("SELECT id FROM `$table` WHERE id=?",[$group]))return false;
    return $branch===0 || (bool)getDB()->query("SELECT id FROM `$branches` WHERE id=? AND `$column`=?",[$branch,$group]);
}

function alphano_save_badge(array $file, string $relative): bool
{
    $path=htmlroot.$relative;
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) {
        if(is_file($path))return true;
        if(!is_dir(dirname($path)) && !mkdir(dirname($path),0755,true))return false;
        return true;
    }
    if(!is_dir(dirname($path)) && !mkdir(dirname($path),0755,true))return false;
    $image=getimagesize($file['tmp_name']);
    if($image[2]===IMAGETYPE_GIF)return move_uploaded_file($file['tmp_name'],$path);
    $resource=imagecreatefromstring(file_get_contents($file['tmp_name']));
    if(!$resource)return false;
    $saved=imagegif($resource,$path);imagedestroy($resource);return $saved;
}
