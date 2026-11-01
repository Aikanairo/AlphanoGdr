<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/welcome.inc.php';
$actor=alphano_require_login();
if (!alphano_can('admin')) alphano_fail('Accesso riservato agli amministratori.',403);
$error=''; $notice='';
$message=alphano_welcome_message(); $form=$message;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    alphano_require_csrf();
    try {
        $message=alphano_welcome_save($_POST,$actor); $form=$message;
        $notice=$message['enabled']?'Benvenuto salvato. La nuova versione sarà mostrata a tutti gli utenti.':'Benvenuto disattivato.';
    } catch (Throwable $exception) {
        http_response_code($exception instanceof DomainException ? (int)$exception->getCode() : 500);
        $error=$exception instanceof DomainException ? $exception->getMessage() : 'Impossibile salvare il messaggio.';
        if (!($exception instanceof DomainException)) error_log('[Benvenuto] '.$exception);
        foreach (['title','body','revision'] as $key) if (isset($_POST[$key]) && is_string($_POST[$key])) $form[$key]=$_POST[$key];
        $form['enabled']=($_POST['enabled']??'0')==='1';
    }
}
require __DIR__.'/../templates/admin/welcome.php';
