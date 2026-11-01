<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';

const ALPHANO_WELCOME_TITLE = 'Benvenuto su Alphano';
const ALPHANO_WELCOME_BODY = 'Benvenuto nella comunità. Lo staff può personalizzare questo messaggio.';

/** The announcement and its read revision are shared by the shell and administration. */
function alphano_welcome_message(int $user = 0): array
{
    $row = ConnectDB()->query('SELECT w.*,COALESCE(r.revision,0) AS read_revision FROM game_welcome w LEFT JOIN game_welcome_receipts r ON r.user_id=? WHERE w.id=1',[$user])[0];
    $contact = ConnectDB()->query("SELECT id,nome FROM utenti WHERE admin=1 ORDER BY id LIMIT 1")[0] ?? null;
    return [
        'enabled'=>(bool)$row['enabled'], 'title'=>$row['title'], 'body'=>$row['body'],
        'revision'=>(int)$row['revision'], 'updated_at'=>(int)$row['updated_at'],
        'pending'=>$user>0 && (bool)$row['enabled'] && (int)$row['read_revision']<(int)$row['revision'],
        'contact_url'=>$contact ? '/posta/writeto.php?'.http_build_query(['idpg'=>(int)$contact['id'],'pgName'=>$contact['nome']],'','&',PHP_QUERY_RFC3986) : null,
        'contact_label'=>'Scrivi alla gestione',
    ];
}

function alphano_welcome_read(int $user, int $revision): array
{
    $db=ConnectDB(); $db->beginTransaction();
    try {
        $row=$db->query('SELECT revision,enabled FROM game_welcome WHERE id=1 FOR UPDATE')[0];
        if (!(bool)$row['enabled'] || $revision!==(int)$row['revision']) throw new DomainException('Il messaggio di benvenuto è cambiato. Riceverai la versione aggiornata.',409);
        $db->execute('INSERT INTO game_welcome_receipts(user_id,revision,read_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE revision=GREATEST(revision,VALUES(revision)),read_at=VALUES(read_at)',[$user,$revision,time()]);
        $message=alphano_welcome_message($user); $db->commit(); return $message;
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}

function alphano_welcome_save(array $input, int $actor): array
{
    if (!alphano_can('admin')) throw new DomainException('Accesso riservato agli amministratori.',403);
    foreach (['title','body','revision','enabled'] as $key) if (isset($input[$key]) && !is_string($input[$key])) throw new DomainException('Parametri non validi.',422);
    $title=trim($input['title']??''); $body=trim(str_replace(["\r\n","\r"],"\n",$input['body']??''));
    $revision=filter_var($input['revision']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $enabled=$input['enabled']??'0';
    if ($title==='' || mb_strlen($title)>120 || $body==='' || mb_strlen($body)>3000 || $revision===false || !in_array($enabled,['0','1'],true)) throw new DomainException('Inserisci un titolo entro 120 caratteri e un messaggio entro 3000 caratteri.',422);
    $db=ConnectDB(); $db->beginTransaction();
    try {
        $before=$db->query('SELECT * FROM game_welcome WHERE id=1 FOR UPDATE')[0];
        if ((int)$before['revision']!==$revision) throw new DomainException('Un altro amministratore ha aggiornato il benvenuto. Ricarica la pagina prima di salvare.',409);
        if ($before['title']!==$title || $before['body']!==$body || (int)$before['enabled']!==(int)$enabled) {
            $next=$revision+1; $now=time();
            $db->execute('UPDATE game_welcome SET title=?,body=?,enabled=?,revision=?,updated_by=?,updated_at=? WHERE id=1',[$title,$body,(int)$enabled,$next,$actor,$now]);
            $after=['title'=>$title,'body'=>$body,'enabled'=>(int)$enabled,'revision'=>$next];
            $db->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,qta,descr,chat) VALUES(?,?,0,'benvenuto',?,?,?)",[$now,$actor,$next,'Messaggio di benvenuto '.($enabled==='1'?'pubblicato':'disattivato').' · versione '.$next,json_encode(['before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        }
        $message=alphano_welcome_message(); $db->commit(); return $message;
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}
