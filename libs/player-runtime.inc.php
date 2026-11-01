<?php
declare(strict_types=1);

/** Shared boundary for the player APIs; domain permissions live here once. */
require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libpg.inc.php';
require_once __DIR__ . '/libtext.inc.php';

function player_api(callable $action): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $result = $action();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $status = (int)$e->getCode();
        if ($status < 400 || $status > 499) {
            error_log('[player API] ' . $e);
            $reference=alphano_error_reference($e,'player-api');
            $status = 500;
        }
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => $status === 500 ? 'Errore interno. Riprova più tardi.' : $e->getMessage()]+(isset($reference)?['reference'=>$reference]:[]), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function player_input(): array
{
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        try { $raw = file_get_contents('php://input'); $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('JSON non valido', 400); }
        if (!is_array($input) || !preg_match('/^\s*\{/', $raw)) throw new RuntimeException('Richiesta non valida', 400);
        return $input;
    }
    return array_merge($_GET, $_POST);
}

function player_text(array $input, string $field, int $limit = 65535, bool $required = false): string
{
    if (isset($input[$field]) && !is_scalar($input[$field])) throw new RuntimeException('Campo non valido: ' . $field, 400);
    $value = trim((string)($input[$field] ?? ''));
    if (strlen($value) > $limit || ($required && $value === '')) throw new RuntimeException('Campo non valido: ' . $field, 400);
    return $value;
}

function player_mutation(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Metodo non consentito', 405);
    alphano_require_csrf(true);
}

function player_board_permissions($db, int $board, array $user): array
{
    if (!$db->query('SELECT id FROM luoghi WHERE id=?', [$board])) throw new RuntimeException('Bacheca non trovata', 404);
    $rules = $db->query('SELECT * FROM diritti WHERE bacheca=?', [(string)$board]);
    if (!$rules) return ['allowed' => true, 'required' => ['Chiunque']];
    $required = [];
    $allowed = !empty($user['admin']);
    foreach ($rules as $rule) {
        if (!empty($rule['all'])) return ['allowed' => true, 'required' => ['Chiunque']];
        foreach (['needadmin'=>['admin','Governatore'], 'needfatepower'=>['fatepower','Master'], 'needadminferiti'=>['adminferite','Curatore'], 'needaccademia'=>['adminaccademia','Accademia']] as $key=>$role) {
            if (!empty($rule[$key])) {
                $required[] = $role[1];
                $allowed = $allowed || !empty($user[$role[0]]);
            }
        }
    }
    return ['allowed' => $allowed, 'required' => array_values(array_unique($required))];
}

function player_require_board($db, int $board, array $user): void
{
    if (!player_board_permissions($db, $board, $user)['allowed']) throw new RuntimeException('Permesso negato', 403);
}

function player_discussion_access(string $type, array $user): bool
{
    if ($type === 'M') $allowed = !empty($user['fatepower']) || !empty($user['admin']);
    elseif ($type === 'A') $allowed = !empty($user['adminaccademia']) || !empty($user['admin']);
    elseif (preg_match('/^Org([1-9][0-9]*)$/D', $type, $m)) $allowed = (int)$user['organizzazione'] === (int)$m[1] || !empty($user['admin']);
    elseif (preg_match('/^Corp([1-9][0-9]*)$/D', $type, $m)) $allowed = (int)$user['corporazione'] === (int)$m[1] || !empty($user['admin']);
    elseif (preg_match('/^Appunti([1-9][0-9]*)$/D', $type, $m)) $allowed = (int)$user['id'] === (int)$m[1];
    else throw new RuntimeException('Tipo non valido', 400);
    if (!$allowed) throw new RuntimeException('Accesso negato', 403);
    return !empty($user['admin']) || ($type === 'M' && !empty($user['fatepower'])) || ($type === 'A' && !empty($user['adminaccademia']))
        || (str_starts_with($type, 'Org') && !empty($user['adminorganizzazione']))
        || (str_starts_with($type, 'Corp') && !empty($user['admincorporazione'])) || str_starts_with($type, 'Appunti');
}

/** Search filters are shared by the HTML catalogue and its JSON endpoint. */
function player_search_query(array $input): array
{
    require_once __DIR__.'/race-presentation.inc.php';
    $where=[]; $params=[];
    foreach (['nome','luogo','razza','online','araldica','caricacorporazione','caricaorganizzazione','categoria','corporazione','organizzazione','al','sesso','etnia','city'] as $key) $filters[$key]=player_text($input,$key,255);
    if ($filters['nome']==='NULL') $where[]='u.nome IS NULL';
    elseif ($filters['nome']!=='') { $where[]='u.nome LIKE ?'; $params[]=$filters['nome'].'%'; }
    if ($filters['razza']==='3') $where[]='u.infetto=1';
    elseif ($filters['razza']==='6') $where[]='u.ancestrale=1';
    elseif ($filters['razza']!=='') { $where[]='u.razza=?'; $params[]=$filters['razza']; }
    foreach (['araldica','organizzazione','corporazione','sesso','etnia','caricaorganizzazione','caricacorporazione'] as $key) {
        if ($filters[$key]!=='') { $where[]='u.'.$key.'=?'; $params[]=$filters[$key]; }
    }
    if ($filters['al']!=='') { $where[]='u.allineamento=?'; $params[]=$filters['al']; }
    if ($filters['luogo']!=='') { $where[]='u.posizione LIKE ?'; $params[]='%'.$filters['luogo'].'%'; }
    if ($filters['online']==='Si') $where[]="u.posizione<>'off'";
    elseif ($filters['online']==='No') $where[]="u.posizione='off'";
    if ($filters['city']!=='') { $where[]='u.araldica IN(SELECT id FROM araldiche WHERE city=?)'; $params[]=$filters['city']; }
    $roles=['Accademia'=>'adminaccademia','Master'=>'fatepower','Governatori'=>'admin','Capi Organizzazione'=>'adminorganizzazione','Capi Corporazione'=>'admincorporazione','Guaritori'=>'adminferite','Resurrettori'=>'adminmorti','Cerimonieri'=>'adminstatosociale','Sindaci'=>'sindaco'];
    if (isset($roles[$filters['categoria']])) $where[]='u.'.$roles[$filters['categoria']].'=1';
    return ['SELECT u.id,u.nome,u.razza,u.vampire_human_form,u.avatar,u.avatar2,'.alphano_race_sql().' AS logical_race,u.sesso,u.etnia,u.allineamento,u.posizione,u.araldica,u.organizzazione,u.caricaorganizzazione,u.corporazione,u.caricacorporazione FROM utenti u'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY u.nome,u.id',$params];
}

/** Canonicalize legacy local media names; never render a missing relative file as a broken URL. */
function player_media_url(mixed $value, int $profile, string $fallback = ''): string
{
    if (!is_scalar($value)) return $fallback;
    $value=trim((string)$value);
    if ($value==='' || preg_match('/[\x00-\x20<>"\x27]/',$value)) return $fallback;
    if (preg_match('#^https?://#i',$value)) return filter_var($value,FILTER_VALIDATE_URL) ? $value : $fallback;
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i',$value) || str_starts_with($value,'//')) return $fallback;
    $path=rawurldecode((string)(parse_url($value,PHP_URL_PATH) ?? ''));
    $relative=str_starts_with($path,'/') ? $path : '/profili/pg/'.$profile.'/'.$path;
    $real=realpath(htmlroot.$relative);
    if (!$real || !str_starts_with($real,htmlroot.'/') || !is_file($real)) return $fallback;
    return $relative;
}
