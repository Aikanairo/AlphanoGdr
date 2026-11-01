<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/libtext.inc.php';
require_once __DIR__.'/ai.inc.php';
require_once __DIR__.'/chat-presentation.inc.php';

/** Atomic removal from the public archive; game consequences and original evidence remain intact. */
function alphano_delete_session(int $id):void {
 $admin=alphano_user();if(!$admin||empty($admin['admin']))alphano_fail('Solo gli amministratori possono cancellare le sessioni.',403);
 $db=ConnectDB();$db->beginTransaction();
 try{
  $session=$db->query('SELECT * FROM sessioni WHERE id=? FOR UPDATE',[$id])[0]??null;
  if(!$session)throw new DomainException('Sessione non trovata.',404);
  if(!$db->query('SELECT session_id FROM session_archive_deletions WHERE session_id=?',[$id])){
   if((int)$session['run'])throw new DomainException('Chiudi la sessione dal Session Manager prima di cancellarla.',409);
   $db->execute('INSERT INTO session_archive_deletions(session_id,deleted_by,admin_name,session_title,chat_key,deleted_at,snapshot) VALUES(?,?,?,?,?,?,?)',[$id,(int)$admin['id'],$admin['nome'],$session['nome'],$session['chat'],time(),json_encode($session,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
   $db->execute('UPDATE quest_notifications SET read_at=COALESCE(read_at,?) WHERE session_id=?',[time(),$id]);
  }
  $db->commit();
 }catch(Throwable $e){$db->rollBack();throw $e;}
}

/** Adapt old HTML transcripts to the same public message model used by the live chat. */
function alphano_session_replay(string $html,array $session):array {
 $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($old);
 $xp=new DOMXPath($doc);$root=$xp->query('//*[@id="chatbox"]')->item(0)??$doc->getElementsByTagName('body')->item(0)??$doc->documentElement;
 $groups=[];$buffer='';$flush=static function()use(&$groups,&$buffer){if(trim(strip_tags($buffer))!==''||str_contains($buffer,'<img'))$groups[]=$buffer;$buffer='';};
 foreach($root->childNodes as $node){
  if($node instanceof DOMComment)continue;
  if($node instanceof DOMElement){
   if(in_array(strtolower($node->tagName),['script','style','head','meta','link'],true)||str_contains(' '.$node->getAttribute('class').' ',' session-info '))continue;
   if(strtolower($node->tagName)==='br'){$flush();continue;}
   $class=' '.$node->getAttribute('class').' ';
   if(str_contains($class,' riga ')){$flush();$buffer=alphano_node_inner($node);$flush();continue;}
   if(str_contains($class,' MasterKey ')||str_contains($class,' ai-narration ')){$flush();$buffer=$doc->saveHTML($node);$flush();continue;}
  }
  $buffer.=$doc->saveHTML($node);
 }
 $flush();$out=[];
 foreach($groups as $raw){
  $part=new DOMDocument();$old=libxml_use_internal_errors(true);$part->loadHTML('<?xml encoding="UTF-8"><div>'.$raw.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($old);$px=new DOMXPath($part);$pg=0;
  foreach($px->query('//a') as $a){$href=$a->getAttribute('href');
   if(preg_match('~^javascript:\s*(?:openProfileId|OpenScheda|ApriScheda)\(\s*[\x27\"]?(\d+)[\x27\"]?\s*\);?$~i',$href,$m)){$a->setAttribute('href','/profili/?Id='.$m[1]);if(trim($a->textContent)!==''){$pg=(int)$m[1];$a->setAttribute('class',trim($a->getAttribute('class').' chat-character-name'));}}
   elseif(!str_contains($href,'png.php')&&preg_match('~/profili/(?:index.php)?\?Id=(\d+)~i',$href,$m)&&trim($a->textContent)!=='')$pg=(int)$m[1];
  }
  $source=alphano_node_inner($part->getElementsByTagName('div')->item(0));
  $clock=$px->query('//time')->item(0);$stamp=(int)$session['datain'];
  if($clock&&strtotime($clock->getAttribute('datetime'))!==false)$stamp=strtotime($clock->getAttribute('datetime'));
  elseif(preg_match('/\b([0-2]\d:[0-5]\d)\b/',$part->textContent,$m))$stamp=strtotime(date('Y-m-d',$stamp).' '.$m[1]);
  $view=alphano_chat_view(['id'=>0,'pg'=>$pg,'dest'=>0,'msg'=>$source]);
  $view['body']=alphano_sanitize_html($view['body']??$source);$view['msg']=alphano_sanitize_html($source);$view['sent_at']=$stamp;$view['scene_label']=$session['location_label']??$session['chat'];
  $out[]=$view;
 }
 return $out;
}

/** Admin batch close: serialize with the Session Manager and AI room, announce once via durable outbox. */
function alphano_close_open_sessions():int {
 $admin=alphano_user();if(!$admin||empty($admin['admin']))alphano_fail('Solo gli amministratori possono chiudere tutte le sessioni.',403);
 $db=ConnectDB();$closed=0;
 foreach($db->query('SELECT id,chat FROM sessioni WHERE run=1 ORDER BY id') as $candidate){
  $guard=alphano_session_guard($candidate['chat']);
  if(!(int)($db->query('SELECT GET_LOCK(?,5) AS locked',[$guard])[0]['locked']??0))throw new DomainException('Una sessione è occupata. Riprova per chiudere quelle rimaste in corso.',409);
  try{
   $db->beginTransaction();
   $db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$candidate['chat']]);
   $session=$db->query('SELECT * FROM sessioni WHERE id=? FOR UPDATE',[(int)$candidate['id']])[0]??null;
   if(!$session||(int)$session['run']!==1){$db->commit();continue;}
   $sid=(int)$session['id'];$reason='Sessione «'.$session['nome'].'» chiusa dall’amministratore '.$admin['nome'].'.';
   $quest=$db->query('SELECT status FROM ai_quests WHERE session_id=?',[$sid])[0]??null;
   if($quest&&$quest['status']!=='closed')ai_scene_close($sid,$reason,(int)$admin['id']);
   else{
    $db->execute('UPDATE sessioni SET run=0,dataout=?,masterout=? WHERE id=?',[time(),(int)$admin['id'],$sid]);
    ai_quest_publish($sid,'session:'.$sid.':admin-closed',$reason.' La giocata è conclusa; il resoconto resta consultabile.');
    ai_quest_audit($sid,'admin_closed',['reason'=>$reason],(int)$admin['id']);
   }
   $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Sessione terminata dall’amministratore' WHERE session_id=? AND status IN('queued','running')",[time(),$sid]);
   $db->commit();$closed++;
   // Recover a missing incremental log from retained chat, excluding outbox rows that the sync will append.
   $dir=rtrim(htmlroot,'/').'/sessione/log';if(!is_dir($dir))@mkdir($dir,0755,true);
   $file=$dir.'/'.$sid.'.html';
   if(!is_file($file)){
    $messages=$db->query('SELECT c.msg FROM chat c WHERE c.chat=? AND c.ora BETWEEN ? AND ? AND NOT EXISTS(SELECT 1 FROM ai_quest_outbox o WHERE o.chat_id=c.id AND o.log_synced=0) ORDER BY c.id',[$session['chat'],(int)$session['datain'],time()]);
    $handle=@fopen($file,'x');if($handle){fwrite($handle,implode("<br>\n",array_column($messages,'msg'))."<br>\n");fclose($handle);}
   }
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  finally{$db->query('SELECT RELEASE_LOCK(?)',[$guard]);}
 }
 ai_quest_sync_logs();return $closed;
}
