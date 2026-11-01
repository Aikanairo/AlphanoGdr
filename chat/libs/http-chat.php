<?php
declare(strict_types=1);

require_once __DIR__ . '/../../libs/kernel.inc.php';
require_once __DIR__ . '/../../libs/libpg.inc.php';
require_once __DIR__ . '/../../libs/pvt/private-core.inc.php';
require_once __DIR__.'/../../libs/chat-history.inc.php';

/** Convalida la stanza sulla configurazione già usata dalla pagina chat. */
function httpChatRoom(string $chat, ?int $idpg = null): array
{
    $room = getDB()->query(
        'SELECT chat_key, position, vampiredamage FROM mappe WHERE chat_key = ? AND chat = 1 AND public_enabled=1 AND (area_id IS NULL OR area_id IN(SELECT id FROM world_areas WHERE enabled=1)) LIMIT 1',
        [$chat]
    )[0] ?? null;
    if (!$room && ($private = alphano_private_room($chat, $idpg ?? (int)($_SESSION['user_id'] ?? 0)))) return $private;
    if (!$room) {
        throw new DomainException('Chat inesistente', 404);
    }
    return $room;
}

/** Letture brevi: nessuna attesa e nessun lock sulla sessione PHP durante le query. */
function httpChatHistory(string $chat, int $since, int $viewer, bool $initial, int $viewerRace = 0): array
{
    $db = getDB();
    $liveFilter=alphano_chat_live_filter();$archiveColumn=alphano_chat_has_archive()?',history_only':',0 AS history_only';
    if ($initial) {
        // Fissa il limite prima della lettura: un arrivo concorrente verrà letto al giro dopo.
        $cursor = (int)($db->query('SELECT MAX(id) AS id FROM chat WHERE chat = ?', [$chat])[0]['id'] ?? 0);
        $rows = $db->query(
            'SELECT id, pg, dest, msg, lican, location, ora'.$archiveColumn.' FROM chat WHERE chat = ?'.$liveFilter.' AND id <= ? AND ora > ? ORDER BY id DESC LIMIT 50',
            [$chat, $cursor, time() - 7200]
        );
        $rows = array_reverse($rows);
        $more = false;
    } else {
        $rows = $db->query(
            'SELECT id, pg, dest, msg, lican, location, ora'.$archiveColumn.' FROM chat WHERE chat = ? AND id > ? ORDER BY id ASC LIMIT 101',
            [$chat, $since]
        );
        $more = count($rows) > 100;
        $rows = array_slice($rows, 0, 100);
        $cursor = $rows ? (int)$rows[count($rows) - 1]['id'] : $since;
    }
    // Le risposte IA precedenti al profilo ricevono lo stesso header delle nuove,
    // senza riscrivere i messaggi conservati né i diari già salvati.
    require_once __DIR__.'/../../libs/ai.inc.php';
    $legacyIds=[];
    foreach ($rows as $row) if (!(int)$row['pg'] && !str_contains($row['msg'],'chat-character-header') && str_contains($row['msg'],'data-ai-job=')) $legacyIds[]=(int)$row['id'];
    $legacy=[];
    if ($legacyIds) foreach ($db->query("SELECT id,npc_id,result,message_id,finished_at FROM ai_jobs WHERE chat_key=? AND status='done' AND kind='play' AND message_id IN (".implode(',',array_fill(0,count($legacyIds),'?')).')',[$chat,...$legacyIds]) as $job) $legacy[(int)$job['message_id']]=$job;
    require_once __DIR__.'/../../libs/chat-presentation.inc.php';
    $messages = [];
    foreach ($rows as $row) {
        if(!empty($row['history_only']))continue;
        if (isset($legacy[(int)$row['id']])) {
            try { $row['msg']=ai_message_html($legacy[(int)$row['id']]); }
            catch (Throwable $e) { error_log('[Master IA storico] '.$e->getMessage()); }
        }
        // Anche i sussurri non visibili fanno avanzare il cursore, senza esporne il testo.
        if ((int)$row['dest'] !== 0 && (int)$row['dest'] !== $viewer && (int)$row['pg'] !== $viewer) {
            continue;
        }
        if ((int)$row['lican'] === 1 && !in_array($viewerRace,[ALPHANO_RACE_LYCAN,ALPHANO_RACE_ANCESTRAL],true)) {
            require_once __DIR__.'/../../libs/chat-format.inc.php';
            if(str_contains($row['msg'],'data-language="lupine"'))$row['msg']=alphano_chat_mask_lupine($row['msg']);
            else{
                // Historical rows have no segmented markup: keep their original privacy rule.
                $name = htmlspecialchars(GetPGFromID((int)$row['pg']) ?? 'Qualcuno', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $row['msg'] = '<span class="String">' . $name . ' emette un ringhio sommesso.</span>';
            }
        }
        $messages[] = ['id' => (int)$row['id'], 'msg' => $row['msg'], 'pg' => (int)$row['pg'], 'location' => $row['location'] ?? '', 'sent_at'=>(int)$row['ora'], ...alphano_chat_view($row)];
    }
    return ['messages' => $messages, 'lastId' => $cursor, 'more' => $more];
}
