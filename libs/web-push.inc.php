<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';

/** Web Push is optional. Private keys and capability URLs never leave this boundary. */
function alphano_push_keys(): ?array
{
    $path = (string)alphano_config('push_vapid_file');
    $real = $path !== '' ? realpath($path) : false;
    if (!$real || !is_file($real) || !is_readable($real) || filesize($real) > 4096) return null;
    foreach (array_unique([dirname(__DIR__), htmlroot]) as $root) {
        if (str_starts_with($real, rtrim((string)realpath($root), '/').'/')) return null;
    }
    if ((fileperms($real) & 0077) !== 0) return null;
    try {
        $keys = json_decode((string)file_get_contents($real), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($keys) || !is_string($keys['subject'] ?? null)
            || !preg_match('~^(mailto:[^\s@]+@[^\s@]+|https://[^\s]+)$~D', $keys['subject'])) return null;
        if (strlen(alphano_push_decode($keys['publicKey'] ?? '')) !== 65 || strlen(alphano_push_decode($keys['privateKey'] ?? '')) !== 32) return null;
        return array_intersect_key($keys, array_flip(['subject','publicKey','privateKey']));
    } catch (Throwable) { return null; }
}

function alphano_push_decode(mixed $value): string
{
    if (!is_string($value) || strlen($value)>256 || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) throw new RuntimeException('Chiave del dispositivo non valida.', 400);
    $bytes = base64_decode(strtr($value, '-_', '+/'), true);
    if ($bytes === false) throw new RuntimeException('Chiave del dispositivo non valida.', 400);
    return $bytes;
}

function alphano_push_ready(): bool
{
    foreach (['curl','mbstring','openssl'] as $extension) if (!extension_loaded($extension)) return false;
    if (!in_array('prime256v1', openssl_get_curve_names() ?: [], true) || !in_array('aes-128-gcm', openssl_get_cipher_methods(), true)) return false;
    if (!alphano_push_keys() || !is_file(dirname(__DIR__).'/vendor/autoload.php')) return false;
    require_once dirname(__DIR__).'/vendor/autoload.php';
    return class_exists(\Minishlink\WebPush\WebPush::class)
        && (bool)ConnectDB()->query("SHOW TABLES LIKE 'web_push_subscriptions'");
}

/** Restrict outbound destinations to browser vendors, never arbitrary user URLs (SSRF). */
function alphano_push_endpoint(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) throw new RuntimeException('Dispositivo non valido.', 400);
    $url = parse_url($value); $host = strtolower($url['host'] ?? '');
    $allowed = $host === 'fcm.googleapis.com' || $host === 'fcmregistrations.googleapis.com'
        || str_ends_with($host, '.push.services.mozilla.com')
        || str_ends_with($host, '.notify.windows.com') || str_ends_with($host, '.push.apple.com');
    if (!$allowed || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
        || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443) || empty($url['path'])) throw new RuntimeException('Servizio push del browser non riconosciuto.', 400);
    return $value;
}

function alphano_push_subscription(array $input): array
{
    $endpoint = alphano_push_endpoint($input['endpoint'] ?? null);
    if (!is_array($input['keys'] ?? null)) throw new RuntimeException('Chiavi del dispositivo non valide.', 400);
    $p256dh = $input['keys']['p256dh'] ?? null; $auth = $input['keys']['auth'] ?? null;
    $point = alphano_push_decode($p256dh);
    if (strlen($point) !== 65 || $point[0] !== "\x04" || strlen(alphano_push_decode($auth)) !== 16) throw new RuntimeException('Chiavi del dispositivo non valide.', 400);
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
    if (!@openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n")) throw new RuntimeException('Chiave del dispositivo non valida.', 400);
    return compact('endpoint','p256dh','auth');
}

function alphano_push_subscribe(int $user, array $input): void
{
    if (!alphano_push_ready()) throw new RuntimeException('Le notifiche del browser non sono ancora disponibili.', 503);
    $sub = alphano_push_subscription($input); $hash = hash('sha256', $sub['endpoint']); $now = time(); $db = ConnectDB();
    $db->beginTransaction();
    try {
        $account = $db->query('SELECT id,bloccato FROM utenti WHERE id=? FOR UPDATE', [$user])[0] ?? null;
        if (!$account || !empty($account['bloccato'])) throw new RuntimeException('Accesso non consentito.', 403);
        $existing = $db->query('SELECT * FROM web_push_subscriptions WHERE endpoint_hash=? FOR UPDATE', [$hash])[0] ?? null;
        if ((!$existing || (int)$existing['user_id']!==$user || !$existing['enabled']) && (int)$db->query('SELECT COUNT(*) AS n FROM web_push_subscriptions WHERE user_id=? AND enabled=1', [$user])[0]['n'] >= 10) throw new RuntimeException('Hai già dieci dispositivi attivi. Disattivane uno prima di aggiungerne un altro.', 429);
        $same = $existing && (int)$existing['user_id']===$user && $existing['enabled'] && hash_equals(hash('sha256',alphano_push_keys()['publicKey']),$existing['vapid_hash']);
        $latest = $same ? (int)$existing['last_mail_id'] : (int)$db->query('SELECT COALESCE(MAX(id),0) AS id FROM mail WHERE iddestinatario=?', [$user])[0]['id'];
        $db->execute('INSERT INTO web_push_subscriptions(user_id,endpoint_hash,endpoint,p256dh,auth,vapid_hash,last_mail_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth=VALUES(auth),vapid_hash=VALUES(vapid_hash),enabled=1,last_mail_id=VALUES(last_mail_id),failures=?,available_at=?,updated_at=VALUES(updated_at)', [$user,$hash,$sub['endpoint'],$sub['p256dh'],$sub['auth'],hash('sha256',alphano_push_keys()['publicKey']),$latest,$now,$now,$same ? (int)$existing['failures'] : 0,$same ? (int)$existing['available_at'] : 0]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    alphano_session(); $_SESSION['push_endpoint_hash'] = $hash;
}

function alphano_push_disable(int $user, string $hash): void
{
    if (preg_match('/^[a-f0-9]{64}$/D', $hash) && ConnectDB()->query("SHOW TABLES LIKE 'web_push_subscriptions'")) {
        ConnectDB()->execute('UPDATE web_push_subscriptions SET enabled=0,updated_at=? WHERE user_id=? AND endpoint_hash=?', [time(),$user,$hash]);
    }
}

/** Factory is injectable only from trusted PHP tests, never from HTTP input. */
function alphano_push_transport(array $keys, array $clientOptions = []): \Minishlink\WebPush\WebPush
{
    require_once dirname(__DIR__).'/vendor/autoload.php';
    return new \Minishlink\WebPush\WebPush(['VAPID'=>$keys], ['TTL'=>300,'urgency'=>'normal'], 5,
        array_replace(['allow_redirects'=>false,'connect_timeout'=>3,'verify'=>true], $clientOptions));
}

/** Poll all mail writers, coalescing a burst once per device. No private content in payload. */
function alphano_push_publish(?callable $sender = null): array
{
    $result = ['sent'=>0,'expired'=>0,'retry'=>0,'skipped'=>0];
    if (!alphano_push_ready()) return $result;
    $db = ConnectDB(); $lock = 'alphano_push_'.substr(hash('sha256',(string)alphano_config('db_db')),0,32);
    if (!(int)$db->query('SELECT GET_LOCK(?,0) AS acquired', [$lock])[0]['acquired']) return $result;
    try {
        $keys = alphano_push_keys(); $transport = $sender ? null : alphano_push_transport($keys); $now = time();
        $ids = $db->query('SELECT s.id FROM web_push_subscriptions s JOIN utenti u ON u.id=s.user_id WHERE s.enabled=1 AND s.available_at<=? AND (u.bloccato<>0 OR s.vapid_hash<>? OR EXISTS(SELECT 1 FROM mail m WHERE m.iddestinatario=s.user_id AND m.id>s.last_mail_id AND m.letto=0 AND m.deldest=0 AND m.baule=0)) ORDER BY s.available_at,s.id LIMIT 25', [$now,hash('sha256',$keys['publicKey'])]);
        foreach ($ids as $entry) {
            $db->beginTransaction();
            try {
                // Never lock the player row while waiting on the remote push service.
                $sub = $db->query('SELECT * FROM web_push_subscriptions WHERE id=? FOR UPDATE', [$entry['id']])[0] ?? null;
                if (!$sub || !$sub['enabled'] || (int)$sub['available_at']>$now) { $db->commit(); continue; }
                $account = $db->query('SELECT bloccato FROM utenti WHERE id=?', [$sub['user_id']])[0] ?? null;
                if (!$account || $account['bloccato'] || !hash_equals(hash('sha256',$keys['publicKey']),$sub['vapid_hash'])) {
                    $db->execute('UPDATE web_push_subscriptions SET enabled=0 WHERE id=?', [$sub['id']]); $db->commit(); $result['skipped']++; continue;
                }
                $latest = (int)$db->query('SELECT COALESCE(MAX(id),0) AS id FROM mail WHERE iddestinatario=? AND id>? AND letto=0 AND deldest=0 AND baule=0', [$sub['user_id'],$sub['last_mail_id']])[0]['id'];
                if (!$latest) { $db->execute('UPDATE web_push_subscriptions SET available_at=? WHERE id=?', [$now+15,$sub['id']]); $db->commit(); continue; }
                try {
                    alphano_push_endpoint($sub['endpoint']);
                    require_once __DIR__.'/experience.inc.php';
                    $audio = alphano_experience_settings((int)$sub['user_id']);
                    $payload = json_encode(['type'=>'mail','event'=>'mail:'.$latest,'url'=>'/posta/','silent'=>!$audio['notification_sound'] || !$audio['notification_volume']],JSON_THROW_ON_ERROR);
                    if ($sender) $status = (int)$sender($sub,$payload);
                    else {
                        $report = $transport->sendOneNotification(\Minishlink\WebPush\Subscription::create(['contentEncoding'=>'aes128gcm','endpoint'=>$sub['endpoint'],'keys'=>['p256dh'=>$sub['p256dh'],'auth'=>$sub['auth']]]), $payload, ['topic'=>'alphano-mail']);
                        $status = $report->getResponse()?->getStatusCode() ?? 0;
                    }
                } catch (Throwable) { $status = 0; } // No exception details: they can contain capability URLs.
                if ($status>=200 && $status<300) {
                    $db->execute('UPDATE web_push_subscriptions SET last_mail_id=?,failures=0,available_at=?,updated_at=? WHERE id=?', [$latest,$now+15,$now,$sub['id']]); $result['sent']++;
                } elseif (in_array($status,[404,410],true)) {
                    $db->execute('UPDATE web_push_subscriptions SET enabled=0,updated_at=? WHERE id=?', [$now,$sub['id']]); $result['expired']++;
                } else {
                    $failures = min(12,(int)$sub['failures']+1); $delay = min(3600,15*(2**$failures));
                    $db->execute('UPDATE web_push_subscriptions SET failures=?,available_at=?,updated_at=? WHERE id=?', [$failures,$now+$delay,$now,$sub['id']]); $result['retry']++;
                }
                $db->commit();
            } catch (Throwable $e) { $db->rollBack(); throw $e; }
        }
    } finally { $db->query('SELECT RELEASE_LOCK(?)', [$lock]); }
    return $result;
}
