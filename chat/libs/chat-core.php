<?php
declare(strict_types=1);

// ────────────────────────────────────────
//  1) Bootstrap & dipendenze
// ────────────────────────────────────────
require_once __DIR__ . '/../../libs/kernel.inc.php';      // ConnectDB(), costanti…
require_once __DIR__ . '/../../libs/libchat.inc.php';    // getChatImages(), MAX_RIGHE_CHAT, SECS_KEEP_CHAT
require_once __DIR__ . '/../../libs/libpg.inc.php';      // SetPosizione(), GetIDPG(), GetUmore(), LockUser()
require_once __DIR__ . '/../../libs/libtext.inc.php';    // ProcessText(), Gnl2br(), …
require_once __DIR__ . '/../../libs/libmeteo.inc.php';   // GetMeteo()
require_once __DIR__ . '/../../libs/libequip.inc.php';   // Consuma()
require_once __DIR__ . '/../../libs/libblocco.inc.php';  // LockUser()
require_once __DIR__ . '/../../libs/libgrafica.inc.php'; // processRazza(), processAraldica()
require_once __DIR__ . '/../../libs/libxml.inc.php';     // PrintBZXMLFromArray()
require_once __DIR__ . '/chat-logic.inc.php';           // buildChatMessage()

use Alphano\Database\PDOMySQLDriver;

// ────────────────────────────────────────
//  2) Flag danni ai vampiri (definita in local.php)
// ────────────────────────────────────────
$GLOBALS['vampiredamage'] = $GLOBALS['vampiredamage'] ?? false;

// ────────────────────────────────────────
//  3) Singleton PDO (guardato)
// ────────────────────────────────────────
if (! function_exists('db')) {
    function db(): PDOMySQLDriver
    {
        static $db;
        return $db ??= ConnectDB();
    }
}

// ────────────────────────────────────────
//  4) Prune / hard‐limit
// ────────────────────────────────────────
function clearOldMessages(string $chat): void {
    $liveFilter=alphano_chat_live_filter();
    db()->execute("DELETE FROM chat WHERE chat = ? AND ora < ?".$liveFilter,
        [$chat, time() - SECS_KEEP_CHAT]
    );
    // Conserva 149 righe prima dell'inserimento: una sola DELETE, anche dopo un picco.
    $offset = MAX_RIGHE_CHAT - 2;
    $cut = db()->query("SELECT id FROM chat WHERE chat = ?".$liveFilter." ORDER BY id DESC LIMIT 1 OFFSET {$offset}", [$chat]);
    if ($cut) {
        db()->execute('DELETE FROM chat WHERE chat = ? AND id < ?'.$liveFilter, [$chat, (int)$cut[0]['id']]);
    }
}

// ────────────────────────────────────────
//  6) Insert HTML‐ready message
// ────────────────────────────────────────
function insertHtml(
    string $chat,
    string $html,
    int    $pg,
    int    $dest,
    string $location,
    string $lican
): int {
    db()->execute(
        "INSERT INTO chat
           (pg, ora, chat, msg, dest, location, lican)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            $pg,
            time(),
            $chat,
            $html,
            $dest,
            $location !== '' ? $location : null,
            $lican
        ]
    );
    return (int) db()->query("SELECT LAST_INSERT_ID() AS id", [])[0]['id'];
}

// ────────────────────────────────────────
//  7) parseClient: delega a chat-logic.inc.php
// ────────────────────────────────────────
function parseClient(array $in): array
{
    $db   = db();
    $chat = (string)$in['chat'];
    $idpg = (int)$in['pg'];
    $msg  = trim((string)$in['msg']);

    list($html,$dest,$newLoc,$lican) = buildChatMessage(
        $db,
        $idpg,
        $chat,
        $msg,
        (int)($in['umore']    ?? 0),
        (string)($in['location'] ?? ''),
        (bool)$GLOBALS['vampiredamage'],
        (string)($in['position'] ?? $chat)
    );

    return ['html'=>$html,'dest'=>$dest,'location'=>$newLoc,'lican'=>$lican];
}
