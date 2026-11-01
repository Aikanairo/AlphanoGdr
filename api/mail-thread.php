<?php
declare(strict_types=1);require_once __DIR__.'/../libs/player-runtime.inc.php';require_once __DIR__.'/../libs/libposta.inc.php';require_once __DIR__.'/../libs/libeconomia.inc.php';require_once __DIR__.'/../libs/mail-presentation.inc.php';
player_api(static function(){
 $uid=alphano_require_login(true);$d=ConnectDB();$in=player_input();$action=(string)($in['action']??'read');$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 if($_SERVER['REQUEST_METHOD']==='GET'&&$action==='people'){$q=player_text($in,'q',80);return $d->query('SELECT id,nome FROM utenti WHERE id<>? AND bloccato=0 AND nome LIKE ? ORDER BY nome LIMIT 25',[$uid,'%'.$q.'%']);}
 if($_SERVER['REQUEST_METHOD']!=='GET'){
  player_mutation();$id=(int)($in['id']??0);
  if($action==='restore_sent'){
   $other=filter_var($in['person']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($other===false)throw new DomainException('Interlocutore non valido.',422);
   return ['success'=>true,'restored'=>alphano_mail_restore_sent($uid,$other)];
  }
  if(in_array($action,['delete','archive'],true)){$r=$d->query('SELECT * FROM mail WHERE id=? AND (idmittente=? OR iddestinatario=?)',[$id,$uid,$uid])[0]??null;if(!$r)throw new DomainException('Missiva non accessibile.',403);if($action==='archive'){if((int)$r['iddestinatario']!==$uid)throw new DomainException('Puoi archiviare solo le missive ricevute.',403);$d->execute('UPDATE mail SET baule=? WHERE id=?',[(int)!$r['baule'],$id]);}else{if((int)$r['idmittente']===$uid)$d->execute('UPDATE mail SET delmitt=1 WHERE id=?',[$id]);if((int)$r['iddestinatario']===$uid)$d->execute('UPDATE mail SET deldest=1 WHERE id=?',[$id]);}return ['success'=>true];}
  if($action!=='send')throw new DomainException('Operazione non valida.',422);$other=(int)($in['person']??0);if($other<1||!$d->query('SELECT id FROM utenti WHERE id=? AND bloccato=0',[$other]))throw new DomainException('Destinatario non disponibile.',422);
  $subject=player_text($in,'subject',255,true);$message=player_text($in,'message',30000,true);$parent=(int)($in['parent']??0);if($parent){$r=$d->query('SELECT id FROM mail WHERE id=? AND ((idmittente=? AND iddestinatario=?) OR(idmittente=? AND iddestinatario=?))',[$parent,$uid,$other,$other,$uid])[0]??null;if(!$r)throw new DomainException('Riferimento alla risposta non valido.',403);}
  return EconomiaAtomica(function()use($uid,$other,$subject,$message,$parent,$in,$d){
   $key=player_text($in,'request_key',80,true);if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$key))throw new DomainException('Identificativo invio non valido.',422);
   $d->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$uid]);if(!alphano_operation_once('mail-compose',$uid,$key,[$other,$subject,$message,$parent,!empty($in['signature']),!empty($in['save'])]))return ['success'=>true,'duplicate'=>true];
   if((int)$d->query('SELECT COUNT(*) n FROM mail WHERE idmittente=? AND data>?',[$uid,time()-60])[0]['n']>=15)throw new DomainException('Troppe missive in un minuto. Attendi un momento.',429);
   $body=ProcessText($message);if(!empty($in['signature']))$body.=alphano_mail_signature($uid);if($parent)$body='<span class="mail-parent-reference" id="mail-parent-'.$parent.'"></span>'.$body;
   if(!InviaMissiva($subject,$body,$other,$uid,0))throw new RuntimeException('Invio non completato');return ['success'=>true,'id'=>(int)$d->lastInsertId()];
  });
 }
 $other=(int)($in['person']??0);$letter=(int)($in['id']??0);$before=max(0,(int)($in['before']??0));
 if($letter){$r=$d->query('SELECT * FROM mail WHERE id=? AND ((idmittente=? AND delmitt=0) OR(iddestinatario=? AND deldest=0))',[$letter,$uid,$uid])[0]??null;if(!$r)throw new DomainException('Missiva non disponibile.',403);$other=(int)$r['idmittente']===$uid?(int)$r['iddestinatario']:(int)$r['idmittente'];}
 if($other>0){$selected=['idmittente'=>$uid,'iddestinatario'=>$other];$conversation=alphano_mail_contact_conversation($selected,$uid,$before);}else{$conversation=isset($r)?[$r]:[];}
 $more=count($conversation)>80;if($more)array_shift($conversation);$read=[];foreach($conversation as $m)if((int)$m['iddestinatario']===$uid)$read[]=(int)$m['id'];if($read)$d->execute('UPDATE mail SET letto=1 WHERE iddestinatario=? AND id IN('.implode(',',array_fill(0,count($read),'?')).')',[$uid,...$read]);
 $mailPeople=alphano_mail_people([$uid,$other,...array_column($conversation,'idmittente'),...array_column($conversation,'iddestinatario')]);$idpg=$uid;ob_start();require __DIR__.'/../templates/mail/thread-messages.php';$html=ob_get_clean();
 $trade=[];foreach($conversation as $m)if((int)$m['idmittente']===0&&preg_match('/\[alphano-trade:(\d+):(\d+)\]/',(string)$m['messaggio'],$match))$trade[]=(int)$match[1];
 return ['person'=>alphano_mail_person($other,$mailPeople),'html'=>$html,'more'=>$more,'before'=>$conversation?(int)$conversation[0]['id']:0,'last_id'=>$conversation?(int)end($conversation)['id']:0,'subject'=>$conversation?(string)end($conversation)['oggetto']:'Missiva','trade_ids'=>$trade,'hidden_sent'=>$other>0?alphano_mail_hidden_sent($uid,$other):0];
});
