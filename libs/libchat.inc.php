<?php
declare(strict_types=1);

/* =========================================================================
 *  libchat.inc.php – funzioni condivise per il motore delle chat
 *  Funzioni PHP 8 con PDO.
 * =========================================================================
 *  Dipendenze:
 *    • kernel.inc.php  → costanti, ConnectDB(), htmlroot …
 *    • libpg.inc.php   → funzioni gestione PG
 * ========================================================================= */

require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libpg.inc.php';
require_once __DIR__.'/chat-history.inc.php';

use Alphano\Database\PDOMySQLDriver;

/* --------------------------------------------------------------------------
 *  COSTANTI DI CONFIGURAZIONE
 * ------------------------------------------------------------------------ */
const MAX_RIGHE_CHAT = 150;        // righe da conservare
const SECS_KEEP_CHAT = 86400;      // prune dopo 24 h

/* --------------------------------------------------------------------------
 *  PDO singleton
 * ------------------------------------------------------------------------ */
if (!function_exists('db')) {
    function db()
    {
        static $db;
        return $db ??= ConnectDB();   // ← resta compatibile col vecchio codice
    }
}


// Catalogo immagini inizialmente vuoto, configurabile dal gestore.
$GLOBALS['arrimgs'] = [];
include __DIR__ . '/libchat_imgs.inc.php';
/** helper retro-compatibile: restituisce l’array completo */
function getChatImages(): array
{
    return $GLOBALS['arrimgs'];
}

/* --------------------------------------------------------------------------
 *  InsertIntoChat()
 * ------------------------------------------------------------------------ */
function InsertIntoChat(string $chat, string $html, array $metadata=[]): int
{
    $html = stripslashes($html);
    $db=db();$own=!$db->inTransaction();$handle=null;$locked=false;$originalSize=null;
    if ($own) $db->beginTransaction();
    try {
        $sceneMaster=(bool)preg_match('/\b(?:MasterKey|DescrKey)\b/',$html);
        if($sceneMaster){
            require_once __DIR__.'/ai-perception.inc.php';
            // Serialize authoritative announcements with NPC publication before
            // assigning a chat ID. Otherwise an older in-flight reply could get
            // a later ID and appear to ignore an as-yet-uncommitted metamorphosis.
            if(ai_perception_ready())$db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$chat]);
        }
        $db->execute('INSERT INTO chat (ora,chat,msg) VALUES (?,?,?)',[time(),$chat,$html]);
        $source=(int)$db->lastInsertId();
        $row=$db->query("SELECT id FROM sessioni WHERE chat=? AND run='1' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$chat]);
        foreach ($db->query('SELECT id FROM registrazioni WHERE chat=?',[$chat]) as $registration) {
            $db->execute('INSERT INTO registrazionichat (registrazione,testo) VALUES (?,?)',[$registration['id'],$html]);
        }
        // The source precedes its immediate NPC reactions in every transcript.
        if($sceneMaster){
            ai_scene_capture_system($chat,$source,$html,$metadata);
        }

        // Tutte le query devono riuscire prima di aggiungere testo al file.
        if ($row) {
            $logFile=rtrim(htmlroot,'/').'/sessione/log/'.$row[0]['id'].'.html';
            $handle=@fopen($logFile,'c+b');
            if ($handle===false) throw new RuntimeException('Impossibile aprire il registro della sessione');
            $locked=@flock($handle,LOCK_EX);
            if (!$locked) throw new RuntimeException('Impossibile bloccare il registro della sessione');
            $stat=@fstat($handle);
            if (!$stat || (($stat['mode'] & 0170000)!==0100000)) throw new RuntimeException('Registro della sessione non valido');
            $originalSize=(int)$stat['size'];
            // Alcuni stream locali non implementano questa opzione: i controlli
            // su scrittura e flush restano obbligatori in entrambi i casi.
            @stream_set_write_buffer($handle,0);
            if (@fseek($handle,0,SEEK_END)!==0) throw new RuntimeException('Impossibile aggiornare il registro della sessione');
            $content=$html."<br>\n";$offset=0;$length=strlen($content);
            while ($offset<$length) {
                $written=@fwrite($handle,substr($content,$offset));
                if ($written===false || $written===0) throw new RuntimeException('Scrittura del registro della sessione incompleta');
                $offset+=$written;
            }
            if (!@fflush($handle)) throw new RuntimeException('Impossibile salvare il registro della sessione');
        }
        if ($own) $db->commit();
    } catch (Throwable $error) {
        // Un append parziale viene rimosso mantenendo il lock sullo stesso file.
        $restored=true;
        if (is_resource($handle) && $locked && $originalSize!==null) $restored=@ftruncate($handle,$originalSize) && @fflush($handle);
        if ($own && $db->inTransaction()) $db->rollBack();
        if (!$restored) throw new RuntimeException('Ripristino del registro della sessione non riuscito',0,$error);
        throw $error;
    } finally {
        if (is_resource($handle)) {
            if ($locked) @flock($handle,LOCK_UN);
            @fclose($handle);
        }
    }
    return $source;
}

/* --------------------------------------------------------------------------
 *  ClearChat()
 * ------------------------------------------------------------------------ */
function ClearChat(string $chat): void
{
    $liveFilter=alphano_chat_live_filter();
    /* 1) cancella righe troppo vecchie */
    db()->execute(
        "DELETE FROM chat WHERE chat = ? AND ora < ?".$liveFilter,
        [$chat, time() - SECS_KEEP_CHAT]
    );

    /* 2) hard-limit: max MAX_RIGHE_CHAT */
    $row = db()->query(
        "SELECT COUNT(*) AS n, MIN(id) AS minid
           FROM chat WHERE chat = ?".$liveFilter,
        [$chat]
    )[0];
    $n     = (int)$row['n'];
    $minid = (int)$row['minid'];

    while ($n >= MAX_RIGHE_CHAT) {
        db()->execute(
            "DELETE FROM chat WHERE chat = ? AND id = ?".$liveFilter." LIMIT 1",
            [$chat, $minid]
        );

        $row = db()->query(
            "SELECT COUNT(*) AS n, MIN(id) AS minid
               FROM chat WHERE chat = ?".$liveFilter,
            [$chat]
        )[0];
        $n     = (int)$row['n'];
        $minid = (int)$row['minid'];
    }

    /* 3) se la chat è vuota pulizie extra */
    if ($n === 0) {
        db()->execute(
            "UPDATE utenti SET chat = '', posizione = 'off' WHERE chat = ?",
            [$chat]
        );
        db()->execute(
            "UPDATE sessioni SET run = '0', dataout = ?
              WHERE chat = ? AND run = '1'",
            [time(), $chat]
        );

        foreach (db()->query(
            "SELECT id FROM registrazioni WHERE chat = ?",
            [$chat]
        ) as $r) {
            db()->execute(
                "DELETE FROM registrazionichat WHERE registrazione = ?",
                [$r['id']]
            );
        }
        db()->execute("DELETE FROM registrazioni WHERE chat = ?", [$chat]);
    }
}

/* --------------------------------------------------------------------------
 *  DumpChat()
 * ------------------------------------------------------------------------ */
function DumpChat(string $chat): string
{
    $out = '';
    foreach (db()->query(
        "SELECT msg FROM chat WHERE chat = ? ORDER BY id",
        [$chat]
    ) as $r) {
        $out .= $r['msg'] . "<br>\n";
    }
    return $out;
}

/* --------------------------------------------------------------------------
 *  processAllineamento()
 * ------------------------------------------------------------------------ */
function processAllineamento(string $all): string
{
    return match ($all[1]) {
        'B' => '#006600',
        'M' => '#FF0000',
        default => '#3300FF',
    };
}

/* --------------------------------------------------------------------------
 *  GetAzioniLavoro()
 * ------------------------------------------------------------------------ */
function GetAzioniLavoro(string $chat): array
{
    return db()->query(
        "SELECT MAX(prob) AS prob, res, azione
           FROM lavori
          WHERE chat = ?
          GROUP BY azione
          ORDER BY azione",
        [$chat]
    );
}

/** Compatibility adapters publish neutral events; configured races use transformations.inc.php. */
function LicanMetamorphosis(string $chat, string $nome, string $sesso, bool $verso): void
{
    $name=htmlspecialchars($nome,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    InsertIntoChat($chat,'<div class="Fato">'.$name.($verso?' cambia forma.':' ritorna alla forma iniziale.').'</div>');
}
function AncestralMetamorphosis(string $chat, string $nome, string $sesso, bool $verso): void
{
    LicanMetamorphosis($chat,$nome,$sesso,$verso);
}
function FrasiLican(int $pg): string
{
    return htmlspecialchars(GetPGFromID($pg),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').' non può parlare nella forma attuale.';
}
function VampireDamage(string $chat, string $nome, string $sesso, bool $morto): void
{
    $name=htmlspecialchars($nome,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    InsertIntoChat($chat,'<div class="Fato">'.$name.($morto?' non ha più punti ferita.':' subisce danni ambientali.').'</div>');
}

/* --------------------------------------------------------------------------
 *  showmessaggiotemp()
 * ------------------------------------------------------------------------ */
function showmessaggiotemp(string $chat): string
{
    $row = db()->query(
        "SELECT testo FROM avvisi WHERE chat = ? LIMIT 1",
        [$chat]
    );
    return $row ? (string)$row[0]['testo'] : '';
}

/* --------------------------------------------------------------------------
 *  Alias SetPosizione()  (retro-compatibilità: alcuni script lo chiamano
 *  senza il namespace ed è definito originariamente in libpg.inc.php)
 * ------------------------------------------------------------------------ */
if (!function_exists('SetPosizione')) {
    function SetPosizione(string $posizione, int $idpg, string $chat = ''): void
    {
        \SetPosizione($posizione, $idpg, $chat);
    }
}
