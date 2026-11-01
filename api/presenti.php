<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/presence.inc.php';
player_api(static function(): array {
    alphano_require_login(true);
    alphano_presence_touch((int)alphano_user()['id']);
    return alphano_presence();
});
