<?php
declare(strict_types=1);

/** Explicit private opt-in for a specific local database; a forged Host cannot enable it. */
function ai_local_model_config():?array {
    if(!alphano_config('ai_enabled'))return null;
    $path=(string)alphano_config('ai_local_control_file');
    if($path===''||!is_file($path))return null;
    $host=parse_url((string)alphano_config('base_url'),PHP_URL_HOST);
    if(!in_array($host,['localhost','127.0.0.1','[::1]','::1'],true))return null;
    if(PHP_SAPI!=='cli'){
        if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))return null;
        if(!preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/D',$_SERVER['HTTP_HOST']??''))return null;
    }
    $all=json_decode((string)file_get_contents($path),true);
    $value=$all['environments'][(string)alphano_config('db_db')]??null;
    if(!is_array($value)||empty($value['enabled'])||!preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~D',$value['url']??''))return null;
    foreach(['state_file','token_file'] as $key)if(!is_string($value[$key]??null)||!str_starts_with($value[$key],'/'))return null;
    return $value;
}

/** One immutable model pin per worker job, including template, tokenizer and generation. */
function ai_local_model_pin(bool $reset=false):?array {
    static $read=false,$pin=null;
    if($reset){$read=false;$pin=null;}
    if($read)return $pin;$read=true;
    $config=ai_local_model_config();if(!$config||!is_file($config['state_file']))return null;
    $value=json_decode((string)file_get_contents($config['state_file']),true);
    if(!is_array($value)||!preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~D',$value['url']??'')||!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D',$value['model']??''))return null;
    return $pin=$value;
}
function ai_model_name():string{return (string)(ai_local_model_pin()['model']??alphano_config('ai_model'));}
function ai_model_context():int{return max(1536,min(4096,(int)(ai_local_model_pin()['settings']['context']??alphano_config('ai_context'))));}
function ai_local_model_call(string $method,string $path,?array $body=null):array {
    if(!alphano_config('ai_enabled'))throw new DomainException('Supporto IA disabilitato.',503);
    $config=ai_local_model_config();if(!$config)throw new DomainException('Cambio modello disponibile soltanto nell’ambiente locale abilitato.',404);
    if(!in_array($method,['GET','POST'],true)||!in_array($path,['/status','/switch'],true))throw new LogicException('Operazione locale non prevista.');
    $token=is_readable($config['token_file'])?trim((string)file_get_contents($config['token_file'])):'';
    if($token==='')throw new DomainException('Controllore locale non configurato.',503);
    $curl=curl_init($config['url'].$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>3000,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]]);
    if($method==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
    $raw=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($raw===false)throw new DomainException('Controllore locale non raggiungibile. Il modello attuale resta attivo.',503);
    $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if($code!==200)throw new DomainException($value['error']??'Cambio modello non riuscito: modello attuale conservato.',in_array($code,[400,409,422,503],true)?$code:503);
    return $value;
}
