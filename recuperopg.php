<?php
declare(strict_types=1);
require_once __DIR__.'/libs/kernel.inc.php';
require_once __DIR__.'/libs/transformations.inc.php';
$identity=$_POST['identity']??$_GET['identity']??'';
if (!is_string($identity)) alphano_fail('Collegamento non valido.',400);
$valid=preg_match('/^(\d+)@([a-f0-9]{64})$/D',$identity,$match);
$message='Il link non è valido oppure è scaduto. Usa il link completo ricevuto via email.';
$backup=null;
if ($valid) {
    $db=ConnectDB();
    $backup=$db->query('SELECT id,data,query,token_hash FROM recuperopg WHERE id=? AND token_hash=? AND data>?',[(int)$match[1],hash('sha256',$match[2]),time()-7*86400])[0]??null;
    if ($backup) $message='Puoi ripristinare il personaggio. Le cariche e i privilegi precedenti verranno revocati.';
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    alphano_require_csrf();
    if (!$backup) alphano_fail($message,400);
    try {
        $db->beginTransaction();
        $locked=$db->query('SELECT id,query FROM recuperopg WHERE id=? AND token_hash=? FOR UPDATE',[$backup['id'],$backup['token_hash']])[0]??null;
        if (!$locked || $db->query('SELECT id FROM utenti WHERE id=?',[$backup['id']])) throw new DomainException('Il personaggio è già stato ripristinato o non è disponibile.');
        set_error_handler(static function ($severity,$message) { throw new DomainException('Il backup non è valido.'); });
        try { $queries=unserialize($locked['query'],['allowed_classes'=>false,'max_depth'=>32]); }
        finally { restore_error_handler(); }
        if (!is_array($queries) || !$queries) throw new DomainException('Il backup non è valido.');
        foreach ($queries as $query) {
            if (!is_string($query) || !preg_match('/^INSERT\s+INTO\s+`?(utenti|equippg|armadio)`?\s*\(/i',$query)) throw new DomainException('Il backup contiene un formato non supportato.');
            $db->execute($query);
        }
        if (!$db->query('SELECT id FROM utenti WHERE id=?',[$backup['id']])) throw new DomainException('Backup del personaggio incompleto.');
        $columns=$db->query('SHOW COLUMNS FROM utenti');$reset=[];
        foreach ($columns as $column) {
            $field=$column['Field'];
            if (str_starts_with($field,'admin') || in_array($field,['fatepower','sindaco','infetto','ancestrale','senziente','vampire_human_form','organizzazione','ramoorganizzazione','caricaorganizzazione','corporazione','ramocorporazione','caricacorporazione','visite','ultimoingresso'],true)) $reset[]="`$field`=0";
        }
        $db->execute('UPDATE utenti SET '.implode(',',$reset).',araldica=1,razza=?,dataregistrazione=? WHERE id=?',[alphano_human_race_id(),time(),$backup['id']]);
        $db->execute('DELETE FROM recuperopg WHERE id=?',[$backup['id']]);
        $db->commit();$backup=null;
        $message='Personaggio ripristinato. Puoi accedere con la tua password.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code($e instanceof DomainException?400:500);
        $message=$e instanceof DomainException?$e->getMessage():'Il recupero non è riuscito. Il backup è stato conservato.';
        if (!$e instanceof DomainException) error_log('[recupero PG] '.$e);
    }
}

$recovery = [
    'title' => 'Recupero personaggio',
    'description' => 'Ripristina il personaggio dal collegamento ricevuto via email.',
    'error' => '', 'message' => $message,
    'mode' => $backup ? 'restore' : 'info', 'identity' => $identity,
];
require __DIR__ . '/templates/account/recovery.php';
ClosePage();
