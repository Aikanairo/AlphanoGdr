<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';

/** Reserve a submitted operation inside the same transaction as its money/items. */
function alphano_operation_once(string $scope,int $actor,?string $key,array $payload): bool
{
    if($key===null||$key==='')return true; // Compatibility with existing clients.
    if($actor<1||!preg_match('/^[a-zA-Z0-9:_-]{1,64}$/D',$scope)||!preg_match('/^[a-zA-Z0-9:_-]{16,100}$/D',$key))throw new RuntimeException('Identificativo operazione non valido.',400);
    $db=ConnectDB();
    if(!$db->inTransaction())throw new LogicException('La prenotazione deve condividere la transazione dell’operazione.');
    $canonical=static function(array $value)use(&$canonical):array{
        if(!array_is_list($value))ksort($value);
        foreach($value as &$item)if(is_array($item))$item=$canonical($item);
        unset($item);return $value;
    };
    $hash=hash('sha256',json_encode($canonical($payload),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
    $created=$db->execute('INSERT INTO game_operation_keys(actor,operation_scope,request_key,payload_hash,created_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE request_key=VALUES(request_key)',[$actor,$scope,$key,$hash,time()]);
    if($created)return true;
    $old=$db->query('SELECT payload_hash FROM game_operation_keys WHERE actor=? AND operation_scope=? AND request_key=? FOR UPDATE',[$actor,$scope,$key])[0]??null;
    if(!$old||!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('Questa operazione è già stata usata con dati diversi. Ricarica il modulo.',409);
    return false;
}
