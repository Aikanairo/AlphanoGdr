<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/../kernel.inc.php';
require_once __DIR__ . '/../libgrafica.inc.php';
require_once __DIR__ . '/../libpg.inc.php';
require_once __DIR__ . '/../libposta.inc.php';
require_once __DIR__ . '/../libbacheca.inc.php';
require_once __DIR__ . '/../libtext.inc.php';
$idpg=alphano_require_login();
foreach (array_merge($_GET,$_POST) as $value) if (!is_scalar($value)) alphano_fail('Parametri non validi.',400);

function bacheca_legacy_board(): int {
    $board=(int)($_POST['bc'] ?? $_GET['bc'] ?? 0);
    if ($board<1) alphano_fail('Bacheca non trovata.',404);
    player_require_board(getDB(),$board,alphano_user());
    return $board;
}

function bacheca_legacy_render(int $board=0,int $thread=0,string $location='',string $message=''): void {
    $user=alphano_user();
    $forum=['mode'=>'board','title'=>'Bacheche','apiBaseUrl'=>'/api/bacheche',
        'currentUserId'=>(int)$user['id'],'isAdmin'=>!empty($user['admin']),'csrfToken'=>alphano_csrf_token(),
        'initialBoard'=>$board,'initialThread'=>$thread,'initialLocation'=>$location,'message'=>$message];
    require dirname(__DIR__,2).'/templates/forum/index.php';
}
