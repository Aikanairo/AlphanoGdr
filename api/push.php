<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/web-push.inc.php';
player_api(static function (): array {
    $user = alphano_require_login(true);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $ready = alphano_push_ready();
        return ['configured'=>$ready,'public_key'=>$ready ? alphano_push_keys()['publicKey'] : null,'csrfToken'=>alphano_csrf_token()];
    }
    player_mutation(); $input = player_input();
    $action = player_text($input,'action',32,true);
    if ($action === 'subscribe') {
        if (!is_array($input['subscription'] ?? null)) throw new RuntimeException('Dispositivo non valido.',400);
        alphano_push_subscribe($user,$input['subscription']);
    } elseif ($action === 'unsubscribe') {
        $hash = hash('sha256',alphano_push_endpoint($input['endpoint'] ?? null));
        alphano_push_disable($user,$hash);
        if (($_SESSION['push_endpoint_hash'] ?? '') === $hash) unset($_SESSION['push_endpoint_hash']);
    } elseif ($action === 'status') {
        $hash = hash('sha256',alphano_push_endpoint($input['endpoint'] ?? null));
        $enabled = (bool)ConnectDB()->query("SHOW TABLES LIKE 'web_push_subscriptions'") && (bool)ConnectDB()->query('SELECT id FROM web_push_subscriptions WHERE user_id=? AND endpoint_hash=? AND enabled=1',[$user,$hash]);
        // Remember the current device after a new login, without silently re-enabling it.
        if ($enabled) $_SESSION['push_endpoint_hash'] = $hash;
        return ['success'=>true,'enabled'=>$enabled];
    } else throw new RuntimeException('Operazione non valida.',400);
    return ['success'=>true];
});
