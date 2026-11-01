<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
/** Historical merge evidence stays durable without being replayed in the live room. */
function alphano_chat_has_archive():bool {
    static $ready=null;return $ready??=(bool)ConnectDB()->query("SHOW COLUMNS FROM chat LIKE 'history_only'");
}
function alphano_chat_live_filter():string {return alphano_chat_has_archive()?' AND history_only=0':'';}
