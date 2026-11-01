<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libchat.inc.php';
require_once __DIR__ . '/../libs/libchat_imgs.inc.php';
require_once __DIR__.'/../libs/session-archive.inc.php';
require_once __DIR__.'/../libs/media-assets.inc.php';






$idpg = alphano_require_login();

initSession();
$idpg    = GetIDPG(false);
$db      = ConnectDB();


if (IsAdmin($idpg)) {
    EnsureAdmin();
}

$isSessionAdmin=IsAdmin($idpg);
foreach(array_merge($_GET,$_POST) as $value)if(!is_string($value))alphano_fail('Parametri non validi.',400);
// accetta GET e POST
$azione = $_REQUEST['azione'] ?? '';
$id     = (string)($_REQUEST['id'] ?? '');
if ($id !== '' && !ctype_digit($id)) { http_response_code(400); exit('Sessione non valida'); }

if($azione==='storico'){require_once __DIR__.'/../libs/historical-sessions.inc.php';gh_page();}

if($id!==''&&$db->query('SELECT session_id FROM session_archive_deletions WHERE session_id=?',[$id])&&$azione!=='cancella')alphano_fail('Questa sessione è stata rimossa dall’archivio.',410);
switch ($azione) {
    case 'cancella':
        if(!$isSessionAdmin)alphano_fail('Solo gli amministratori possono cancellare le sessioni.',403);
        if($_SERVER['REQUEST_METHOD']!=='POST')alphano_fail('Usa il comando di cancellazione nell’archivio.',405);
        alphano_require_csrf();
        if($id===''||(int)$id<1)alphano_fail('Sessione non valida.',400);
        try{alphano_delete_session((int)$id);}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
        $_SESSION['session_archive_notice']=['title'=>'Sessione cancellata','message'=>'Sessione rimossa dall’archivio. La cancellazione è stata registrata.'];
        header('Location: /sessione/viewlog.php',true,303);exit;
    case 'chiudi_in_corso':
        if(!$isSessionAdmin)alphano_fail('Solo gli amministratori possono chiudere tutte le sessioni.',403);
        if($_SERVER['REQUEST_METHOD']!=='POST')alphano_fail('Usa il pulsante nell’archivio.',405);
        alphano_require_csrf();
        try{$closed=alphano_close_open_sessions();}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
        $_SESSION['session_archive_notice']=['title'=>'Sessioni chiuse','message'=>$closed?'Sessioni chiuse: '.$closed.'. La chiusura è stata annunciata nelle rispettive chat.':'Non ci sono sessioni in corso da chiudere.'];
        header('Location: /sessione/viewlog.php',true,303);exit;
    case 'cancellazioni':
        if(!$isSessionAdmin)alphano_fail('Registro riservato agli amministratori.',403);
        $deletions=$db->query('SELECT * FROM session_archive_deletions ORDER BY deleted_at DESC,session_id DESC');
        require __DIR__.'/../templates/sessions/deletions.php';exit;
    case 'aprilog':
        if ($id === '') {
            exit('Sessione non specificata');
        }
        $session=$db->query('SELECT s.*,COALESCE(m.title,s.chat) AS location_label FROM sessioni s LEFT JOIN mappe m ON m.chat_key=s.chat WHERE s.id=?',[$id])[0]??null;
        if(!$session)alphano_fail('Sessione non trovata.',404);
        $file = rtrim(htmlroot, '/') . "/sessione/log/{$id}.html";
        if (!is_file($file)) $file = rtrim(htmlroot, '/') . "/sessione/log/{$id}.htm";
        if (!is_file($file) || !filesize($file)) {
            exit('Log non trovato o vuoto');
        }

        // chat name
        $tmp  = $db->query("SELECT COALESCE(m.title,s.chat) AS chat FROM sessioni s LEFT JOIN mappe m ON m.chat_key=s.chat WHERE s.id=?", [$id]);
        $chat = is_array($tmp) ? ($tmp[0]['chat'] ?? '')
               : ($tmp instanceof PDOStatement ? (string)$tmp->fetchColumn() : '');

        $replay=alphano_session_replay(file_get_contents($file),$session);
        require __DIR__.'/../templates/sessions/log.php';
        exit;

    case 'aggiungiparere':
    case 'modificaparere':
    case 'pareri':
        require_once __DIR__ . '/pareri_view.php';
        exit;

    default:
        // prendo sessioni
        $q = $db->query("SELECT s.*, m.scene_thumbnail, COALESCE(m.title, s.chat) AS location_label FROM sessioni s LEFT JOIN mappe m ON m.chat_key=s.chat LEFT JOIN session_archive_deletions d ON d.session_id=s.id WHERE d.session_id IS NULL ORDER BY s.id DESC", []);
        $rows = is_array($q) ? $q
              : ($q instanceof PDOStatement ? $q->fetchAll(PDO::FETCH_ASSOC) : []);

        foreach($rows as &$row){$image=(string)($row['scene_thumbnail']??'');$row['location_thumbnail']=$image!==''&&alphano_media_file($image)?$image:'';}unset($row);
        $archiveNotice=$_SESSION['session_archive_notice']??null;unset($_SESSION['session_archive_notice']);
        require __DIR__.'/../templates/sessions/index.php';
        exit;
}
