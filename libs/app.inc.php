<?php
declare(strict_types=1);

require_once __DIR__.'/runtime-requirements.inc.php';
require_once __DIR__.'/http-context.inc.php';
require_once __DIR__.'/database-lock.inc.php';
alphano_runtime_require();
require_once __DIR__.'/credits-guard.inc.php';
alphano_credits_guard();

if (PHP_SAPI !== 'cli' && !is_file(dirname(__DIR__).'/config/local.php')) {header('Location: /install/',true,303);exit;}
require_once __DIR__ . '/engine-settings.inc.php';
require_once __DIR__ . '/view.inc.php';
require_once __DIR__ . '/privacy.inc.php';

const ALPHANO_RACE_HUMAN = 1;
const ALPHANO_RACE_VAMPIRE = 2;
const ALPHANO_RACE_LYCAN = 3;
const ALPHANO_RACE_SPIRIT = 4;
const ALPHANO_RACE_ORIGINAL = 5;
const ALPHANO_RACE_ANCESTRAL = 6;
const ALPHANO_RACE_DEAD_VAMPIRE = 7;
const ALPHANO_RACE_LEGACY_SPIRIT = 8;

function alphano_config(string $key): mixed
{
    static $config;
    $config ??= (static function (): array { return require dirname(__DIR__) . '/config/app.php'; })();
    if (!array_key_exists($key, $config)) throw new InvalidArgumentException('Configurazione sconosciuta: ' . $key);
    return $config[$key];
}

function alphano_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            session_name('alphano_'.substr(hash('sha256',dirname(__DIR__)),0,16));
            session_set_cookie_params([
                'httponly' => true, 'samesite' => 'Lax', 'path' => '/',
                'secure' => alphano_https_context((string)alphano_config('base_url')),
            ]);
        }
        session_start();
    }
    if(!empty($_SESSION['support_session_id'])){require_once __DIR__.'/user-support.inc.php';alphano_support_guard();}
}

function alphano_user(): ?array
{
    alphano_session();
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) return null;
    return ConnectDB()->query('SELECT * FROM utenti WHERE id = ? LIMIT 1', [$id])[0] ?? null;
}

function alphano_can(string $flag): bool
{
    $user = alphano_user();
    return $user !== null && (!empty($user['admin']) || !empty($user[$flag]));
}

function alphano_fail(string $message, int $status, bool $json = false, ?string $code = null): never
{
    http_response_code($status);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]+($code!==null?['code'=>$code]:[]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        require_once __DIR__.'/status-page.inc.php';
        header('Cache-Control: no-store');
        $title = match ($status) {
            401, 403 => 'Accesso da verificare',
            404 => 'Pagina non disponibile',
            429 => 'Un momento di attesa',
            default => 'Non è stato possibile continuare',
        };
        alphano_status_page($title, $message);
    }
    exit;
}

function alphano_require_login(bool $json = false): int
{
    $user = alphano_user();
    if (!$user) alphano_fail('Devi effettuare il login.', $json ? 401 : 403, $json);
    $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'';
    if((str_starts_with($path,'/corporazioni/')||str_starts_with($path,'/api/corporazioni/'))&&!alphano_config('corporations_enabled'))alphano_fail('Corporazioni e produzione di oggetti disabilitate dalla gestione.',403,$json);
    if(str_starts_with($path,'/organizzazioni/')&&!alphano_config('organizations_enabled'))alphano_fail('Organizzazioni disabilitate dalla gestione.',403,$json);
    if (!empty($user['bloccato'])) alphano_fail('Il personaggio è bloccato.', 403, $json);
    return (int)$user['id'];
}

function alphano_csrf_token(): string
{
    alphano_session();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function alphano_request_json(): array
{
    static $decoded;
    if ($decoded === null) {
        $raw = file_get_contents('php://input');
        $decoded = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new InvalidArgumentException('Corpo JSON non valido');
    }
    return $decoded;
}

function alphano_require_csrf(bool $json = false): void
{
    alphano_session();
    $input = $_POST;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        try { $input = alphano_request_json(); }
        catch (Throwable) { alphano_fail('Richiesta JSON non valida.', 400, $json); }
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_csrf'] ?? $input['csrf_token'] ?? $input['token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        alphano_fail('Sessione scaduta: ricarica la pagina e riprova.', 403, $json, 'csrf_expired');
    }
}

function alphano_password_verify(string $password, string $hash): bool
{
    return preg_match('/^[a-f0-9]{32}$/iD', $hash)
        ? hash_equals(strtolower($hash), md5($password)) : password_verify($password, $hash);
}

/** Protezione condivisa per le form storiche e i client fetch, senza cambiare l'interfaccia. */
function alphano_html_runtime(): void
{
    if (PHP_SAPI === 'cli') return;
    $token = alphano_csrf_token();
    ob_start(static function (string $html) use ($token): string {
        foreach (headers_list() as $header) {
            if (preg_match('/^Content-Type:\s*(application\/json|application\/pdf|image\/)/i', $header)) return $html;
        }
        if (!preg_match('/<(?:!doctype\s+html|html)\b/i', $html)) return alphano_credits_filter_html($html);
        alphano_privacy_headers();
        $html = alphano_theme_document($html);
        $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $html = preg_replace_callback('/<form\b[^>]*>/i', static function ($match) use ($escaped) {
            $tag = $match[0];
            if (preg_match('/\baction\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $tag, $action)) {
                $url = trim(str_replace(["\t", "\r", "\n"], '', html_entity_decode(($action[1] ?? '') ?: (($action[2] ?? '') ?: ($action[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $url = str_replace('\\', '/', $url);
                if (str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
                    $requestScheme = (alphano_https_context((string)alphano_config('base_url'))) ? 'https' : 'http';
                    $target = parse_url(str_starts_with($url, '//') ? $requestScheme . ':' . $url : $url);
                    $origin = parse_url(alphano_canonical_origin((string)alphano_config('base_url')));
                    if (!$target || !in_array(strtolower($target['scheme'] ?? ''), ['http','https'], true)
                        || strcasecmp($target['host'] ?? '', $origin['host'] ?? '') !== 0
                        || strtolower($target['scheme']) !== $requestScheme
                        || ($target['port'] ?? ($requestScheme === 'https' ? 443 : 80)) !== ($origin['port'] ?? ($requestScheme === 'https' ? 443 : 80))) return $tag;
                }
            }
            return preg_match('/\bmethod\s*=\s*["\x27]?post\b/i', $tag)
                ? $tag . '<input type="hidden" name="_csrf" value="' . $escaped . '">' : $tag;
        }, $html);
        $assets='';
        if (!str_contains($html, '/js/character-markers.js')) $assets.='<script src="/js/character-markers.js?v='.filemtime(dirname(__DIR__).'/js/character-markers.js').'" defer></script>';
        if (!str_contains($html, '/js/board-game-notices.js')) $assets.='<script src="/js/board-game-notices.js?v='.filemtime(dirname(__DIR__).'/js/board-game-notices.js').'" defer></script>';
        if (!str_contains($html, '/js/presence-games.js')) $assets.='<script src="/js/presence-games.js?v='.filemtime(dirname(__DIR__).'/js/presence-games.js').'" defer></script>';
        if (!str_contains($html, '/js/commerce-ui.js')) $assets.='<script src="/js/commerce-ui.js?v='.filemtime(dirname(__DIR__).'/js/commerce-ui.js').'" defer></script>';
        if (!str_contains($html, '/js/ui-dialog.js')) $assets.='<script src="/js/ui-dialog.js?v='.filemtime(dirname(__DIR__).'/js/ui-dialog.js').'" defer></script>';
        if (!str_contains($html, '/js/runtime.js')) {
            $assets .= '<meta name="csrf-token" content="' . $escaped . '">'
                . '<script src="/js/runtime.js?v=' . filemtime(dirname(__DIR__) . '/js/runtime.js') . '" defer></script>';
        }
        if($assets!==''){
            if (stripos($html, '</head>') !== false) $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);
            else $html .= $assets;
        }
        if(!empty($GLOBALS['alphano_support_active']))$html=str_ireplace('</body>',alphano_support_banner().'</body>',$html);
        return alphano_credits_filter_html($html);
    });
}

date_default_timezone_set((string)alphano_config('timezone'));
if(PHP_SAPI!=='cli'){
 $route=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'';
 if((str_starts_with($route,'/corporazioni/')||str_starts_with($route,'/api/corporazioni/'))&&!alphano_config('corporations_enabled'))alphano_fail('Corporazioni e produzione di oggetti disabilitate dalla gestione.',403,str_starts_with($route,'/api/'));
 if(str_starts_with($route,'/organizzazioni/')&&!alphano_config('organizations_enabled'))alphano_fail('Organizzazioni disabilitate dalla gestione.',403);
}
alphano_html_runtime();

/** Ponti espliciti per i risultati PEAR ancora consumati dai template storici. */
function LegacyFetchRow(array &$rows): array|false
{
    $row = current($rows);
    if ($row === false) return false;
    next($rows);
    return $row;
}

function LegacyNumRows(array $rows): int { return count($rows); }

if (!class_exists('PEAR', false)) {
    class PEAR { public static function isError(mixed $value): bool { return $value instanceof Throwable; } }
}

// Errori inattesi: diagnostica nel log e risposta coerente con il tipo dell'endpoint.
if (PHP_SAPI !== 'cli') set_exception_handler(static function (Throwable $exception): never {
    error_log('[Alphano] ' . $exception);
    $reference=alphano_error_reference($exception,'document');
    while (ob_get_level()) ob_end_clean();
    $json = false;
    foreach (headers_list() as $header) if (stripos($header, 'Content-Type: application/json') === 0) $json = true;
    alphano_fail('Operazione non completata. Riprova tra poco. Codice assistenza: '.$reference, 500, $json);
});
