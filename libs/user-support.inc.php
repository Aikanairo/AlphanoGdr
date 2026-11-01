<?php
declare(strict_types=1);
/** Support identity belongs to the server session, never to a request parameter. */
function alphano_support_audit(array $support,string $action,int $status=200):void {
 $path=(string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
 $input=$_POST;if(str_contains($_SERVER['CONTENT_TYPE']??'','application/json'))$input=json_decode(file_get_contents('php://input'),true)??[];
 $fields=['fields'=>array_values(array_filter(array_keys($input),static fn($k)=>!preg_match('/pass|token|csrf|secret|mail/i',(string)$k)))];
 if(is_string($input['action']??null)&&preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$input['action']))$fields['operation']=$input['action'];
 foreach(['id','pg','target','item','order_id','trade_id','opponent'] as $key){$v=$input[$key]??$_GET[$key]??null;if(is_scalar($v)&&ctype_digit((string)$v))$fields['ids'][$key]=(int)$v;}
 ConnectDB()->execute('INSERT INTO support_audit(support_id,actor,target,action,method,path,fields_json,http_status,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[(int)$support['id'],(int)$support['actor'],(int)$support['target'],$action,substr($_SERVER['REQUEST_METHOD']??'CLI',0,10),substr($path,0,500),json_encode($fields),$status,time()]);
}
function alphano_support_finish(string $reason='manual'):void {
 $id=(int)($_SESSION['support_session_id']??0);if(!$id)return;
 $db=ConnectDB();$s=$db->query('SELECT * FROM support_sessions WHERE id=?',[$id])[0]??null;
 unset($_SESSION['support_session_id'],$_SESSION['csrf_token'],$_SESSION['push_endpoint_hash']);
 if(!$s){unset($_SESSION['user_id'],$_SESSION['user_name']);return;}
 $db->execute('UPDATE support_sessions SET ended_at=?,end_reason=? WHERE id=? AND ended_at=0',[time(),$reason,$id]);alphano_support_audit($s,'end:'.$reason);
 $actor=$db->query('SELECT id,nome,bloccato FROM utenti WHERE id=?',[$s['actor']])[0]??null;
 if($actor&&!$actor['bloccato']){$_SESSION['user_id']=(int)$actor['id'];$_SESSION['user_name']=$actor['nome'];}else{unset($_SESSION['user_id'],$_SESSION['user_name']);}
 if(!headers_sent())session_regenerate_id(true);
}
function alphano_support_guard():void {
 static $checked=false;if($checked)return;$checked=true;
 if(empty($_SESSION['support_session_id']))return;
 $db=ConnectDB();$s=$db->query('SELECT s.*,a.admin,a.bloccato AS actor_blocked,t.bloccato AS target_blocked,t.admin AS target_admin FROM support_sessions s JOIN utenti a ON a.id=s.actor JOIN utenti t ON t.id=s.target WHERE s.id=?',[(int)$_SESSION['support_session_id']])[0]??null;
 if(!$s||$s['ended_at']||!$s['admin']||$s['actor_blocked']||$s['target_blocked']||$s['target_admin']||(int)$s['expires_at']<=time()||(int)($_SESSION['user_id']??0)!==(int)$s['target']){alphano_support_finish('expired-or-revoked');header('Location: /main.php',true,303);exit;}
 $GLOBALS['alphano_support_active']=$s;
 register_shutdown_function(static function()use($s){alphano_support_audit($s,'request',http_response_code()?:200);});
 // Assistance may inspect the account, never silently replace its credentials or privileges.
 $path=(string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&$path!=='/admin/assistenza.php'){
  $keys=implode(' ',array_keys($_POST));
  if(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')){try{$input=json_decode(file_get_contents('php://input'),true)??[];$keys.=' '.implode(' ',array_keys($input));}catch(Throwable){}}
  $sensitive=false;foreach($input??$_POST as $key=>$value)if(preg_match('/^(?:txtpass|current_password|password|passwd|email|mail|newpass|admin|fatepower)$/i',(string)$key)&&$value!==''&&$value!==null)$sensitive=true;
  if($sensitive||preg_match('#^/(?:admin/|recuperopasswd|adduser|api/registration|api/auth)#',$path))alphano_fail('Operazione riservata al titolare dell’account. Termina l’assistenza per tornare al tuo account.',403,str_starts_with($path,'/api/'));
 }
}
function alphano_support_start(int $target,string $reason):void {
 if(!empty($_SESSION['support_session_id']))throw new DomainException('Termina prima la sessione di assistenza corrente.',409);
 $actor=alphano_user();if(!$actor||empty($actor['admin']))throw new DomainException('Riservato agli amministratori.',403);
 $reason=trim($reason);if(mb_strlen($reason)<10||mb_strlen($reason)>1000)throw new DomainException('Descrivi il motivo dell’assistenza (da 10 a 1000 caratteri).',422);
 $db=ConnectDB();$u=$db->query('SELECT id,nome,admin,bloccato FROM utenti WHERE id=?',[$target])[0]??null;
 if(!$u||$target===(int)$actor['id']||$u['admin']||$u['bloccato'])throw new DomainException('Account non disponibile per l’assistenza: scegli un altro giocatore non bloccato e privo del ruolo amministratore.',422);
 $db->execute('INSERT INTO support_sessions(actor,target,reason,started_at,expires_at) VALUES(?,?,?,?,?)',[$actor['id'],$target,$reason,time(),time()+1800]);$id=(int)$db->lastInsertId();
 $s=['id'=>$id,'actor'=>$actor['id'],'target'=>$target];alphano_support_audit($s,'start');
 session_regenerate_id(true);unset($_SESSION['csrf_token'],$_SESSION['push_endpoint_hash']);
 $_SESSION['support_session_id']=$id;$_SESSION['user_id']=$target;$_SESSION['user_name']=$u['nome'];
}
function alphano_support_banner():string {
 $s=$GLOBALS['alphano_support_active']??null;if(!$s||($_SERVER['HTTP_SEC_FETCH_DEST']??'')==='iframe')return '';
 $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$u=ConnectDB()->query('SELECT nome FROM utenti WHERE id=?',[$s['target']])[0]??[];
 return '<aside class="support-banner" role="status"><strong>Assistenza utenti · '.$h($u['nome']??'Account').'</strong><span>Sessione #'.(int)$s['id'].' · termina alle '.date('H:i',(int)$s['expires_at']).' · operazioni registrate</span><form method="post" action="/admin/assistenza.php" target="_top"><input type="hidden" name="_csrf" value="'.$h(alphano_csrf_token()).'"><button name="action" value="stop">Torna al tuo account</button></form></aside>';
}

/** Close abandoned support receipts even when the browser never returns. */
function alphano_support_expire():int {
 $db=ConnectDB();$count=0;foreach($db->query('SELECT * FROM support_sessions WHERE ended_at=0 AND expires_at<=? ORDER BY id LIMIT 100',[time()]) as $s){
  if($db->execute("UPDATE support_sessions SET ended_at=expires_at,end_reason='expired' WHERE id=? AND ended_at=0",[$s['id']])){alphano_support_audit($s,'end:expired');$count++;}
 }return $count;
}
