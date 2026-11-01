<?php
declare(strict_types=1);
require_once __DIR__.'/commerce-thread.inc.php';
require_once __DIR__.'/profile-art.inc.php';

/** Public portraits only, in one query for all participants in this mailbox. */
function alphano_mail_people(array $ids):array {
 $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));$people=[];
 foreach(array_chunk($ids,500) as $chunk)foreach(ConnectDB()->query('SELECT id,nome,razza,vampire_human_form,sesso,avatar,avatar2 FROM utenti WHERE id IN('.implode(',',array_fill(0,count($chunk),'?')).')',$chunk) as $u){$art=alphano_profile_portrait($u);$people[(int)$u['id']]=['id'=>(int)$u['id'],'name'=>$u['nome'],'portrait'=>$art['url'],'fallback'=>$art['default']];}
 return $people;
}
function alphano_mail_person(int $id,array $people):array {
 return $people[$id]??['id'=>$id,'name'=>$id?'Personaggio non disponibile':'Alphano · Sistema','portrait'=>'','fallback'=>''];
}
/** Shared visual category for modern and historical system receipts. */
function alphano_mail_kind(array $mail):string {
 if((int)$mail['idmittente']>0)return 'personal';
 return preg_match('/\[alphano-trade:\d+:\d+\]/',(string)$mail['messaggio'])||preg_match('/(?:trasferimento|trattativa|ordine|acquisto|consegna|proposta di prezzo)/iu',(string)$mail['oggetto'])?'commerce':'system';
}
function alphano_mail_artwork(string $kind):string {
 return $kind==='commerce'?'/assets/placeholder.svg':'/assets/placeholder.svg';
}
function alphano_mail_boxes(array $rows,int $viewer):array {
 $boxes=[[],[],[]];$people=alphano_mail_people([$viewer,...array_column($rows,'idmittente'),...array_column($rows,'iddestinatario')]);$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 foreach($rows as $r){
  $from=alphano_mail_person((int)$r['idmittente'],$people);$to=alphano_mail_person((int)$r['iddestinatario'],$people);$trade=alphano_trade_mail_summary($r,$viewer);
  $kind=alphano_mail_kind($r);
  $entry=['id'=>(int)$r['id'],'data'=>(int)$r['data'],'oggetto'=>$h($r['oggetto']),'letto'=>(bool)$r['letto'],'commerce'=>$trade,'kind'=>$kind,'artwork'=>$kind==='personal'?'':alphano_mail_artwork($kind),'preview'=>alphano_mail_preview((string)$r['messaggio']),'from'=>$from,'to'=>$to];
  if((int)$r['iddestinatario']===$viewer&&!(int)$r['deldest'])$boxes[(int)(bool)$r['baule']][]=$entry+['idmittente'=>$from['id'],'mittente'=>$h($from['name'])];
  if((int)$r['idmittente']===$viewer&&!(int)$r['delmitt'])$boxes[2][]=$entry+['idmittente'=>$to['id'],'mittente'=>$h($to['name'])];
 }
 return $boxes;
}
function alphano_mail_people_html(array $from,array $to):string {
 $h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$html='<div class="mail-people" aria-label="Mittente e destinatario">';
 foreach([$from,$to] as $i=>$p){if($i)$html.='<span class="mail-direction" aria-label="a">→</span>';$html.='<span class="mail-person">';if($p['portrait'])$html.='<img class="mail-portrait" src="'.$h($p['portrait']).'" data-mail-fallback="'.$h($p['fallback']).'" alt="" loading="lazy">';$html.='<span><small>'.($i?'Destinatario':'Mittente').'</small>'.($p['id']?'<a href="/profili/?Id='.$p['id'].'" data-mail-profile>':'').$h($p['name']).($p['id']?'</a>':'').'</span></span>';}
 return $html.'</div>';
}

/** Snapshot of the sender's signature; never trusts posted author or quoted HTML. */
function alphano_mail_signature(int $id):string {
 $db=ConnectDB();$u=$db->query('SELECT nome,firma,organizzazione,caricaorganizzazione,corporazione,caricacorporazione,araldica FROM utenti WHERE id=?',[$id])[0]??[];
 $h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 if(!empty($u['firma']))return '<div class="mail-signature mail-signature-custom">'.ProcessText($u['firma']).'</div>';
 $html='<div class="mail-signature"><strong class="mail-signature-name">'.$h($u['nome']??'').'</strong><div class="mail-signature-roles">';
 foreach(['organizzazione'=>['organizzazioni','caricheorganizzazioni','caricaorganizzazione'],'corporazione'=>['corporazioni','carichecorporazioni','caricacorporazione']] as $key=>[$table,$ranks,$rankKey]){
  if(empty($u[$key]))continue;
  $group=$db->query("SELECT nome FROM $table WHERE id=?",[(int)$u[$key]])[0]['nome']??'';
  if($group==='')continue;
  $rank=$db->query("SELECT nome FROM $ranks WHERE id=?",[(int)$u[$rankKey]])[0]['nome']??'';
  $html.='<span class="mail-signature-role"><img src="'.$h(alphano_badge_url($table,(int)$u[$rankKey],(int)$u[$key])).'" alt=""><span>'.($rank!==''?'<span class="mail-signature-rank">'.$h($rank).'</span>':'').$h($group).'</span></span>';
 }
 if(!empty($u['araldica']))$html.='<span class="mail-signature-role"><img src="'.$h(alphano_badge_url('cariche',(int)$u['araldica'])).'" alt=""><span>'.$h(GetAraldica((int)$u['araldica'])??'').'</span></span>';
 return $html.'</div></div>';
}

/** Read-time upgrade for the old automatic GML signatures. Stored mail is untouched. */
function alphano_mail_render(string $message):string {
 $message=preg_replace('~<small>([^<]*)</small>~u','<span class="mail-signature-rank">$1</span>',$message)??$message;
 $html=ProcessText($message);
 $badge='<img\\s+[^>]*src="/uploads/badges/(?:organizzazioni|corporazioni|cariche)/[0-9/]+\\.gif(?:\\?[^"<>]*)?"[^>]*>[^<]*(?:<br\\s*/?>|$)';
 return preg_replace_callback('~(^|<br\\s*/?>)([\\p{L}\\p{N} _’.\\x27-]{1,70})<br\\s*/?>((?:'.$badge.'){1,3})~u',static function($m){
  $roles=preg_replace_callback('~<img\\s+[^>]*src="/uploads/badges/(organizzazioni|corporazioni|cariche)/([0-9/]+)\\.gif(?:\\?[^"<>]*)?"[^>]*>([^<]*)(?:<br\\s*/?>|$)~u',static function($r){
   $ids=array_map('intval',explode('/',$r[2]));$scope=count($ids)>1?$ids[0]:0;$rank=$ids[count($ids)-1];
   $url=htmlspecialchars(alphano_badge_url($r[1],$rank,$scope),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
   return '<span class="mail-signature-role"><img src="'.$url.'" alt=""><span>'.$r[3].'</span></span>';
  },$m[3]);
  return $m[1].'<div class="mail-signature mail-signature-legacy"><strong class="mail-signature-name">'.$m[2].'</strong><div class="mail-signature-roles">'.$roles.'</div></div>';
 },$html)??$html;
}

/** Explicit reply links only; unrelated letters with the same subject never merge. */
function alphano_mail_conversation(array $current,int $viewer):array {
 $thread=[$current];$seen=[(int)$current['id']=>true];$pair=[(int)$current['idmittente'],(int)$current['iddestinatario']];sort($pair);
 while(count($thread)<25&&preg_match('~^<span class="mail-parent-reference" id="mail-parent-(\d+)"></span>~',(string)$current['messaggio'],$m)){
  $id=(int)$m[1];if(isset($seen[$id]))break;$seen[$id]=true;
  $previous=ConnectDB()->query('SELECT * FROM mail WHERE id=? AND ((idmittente=? AND delmitt=0) OR (iddestinatario=? AND deldest=0))',[$id,$viewer,$viewer])[0]??null;
  if(!$previous)break;$previousPair=[(int)$previous['idmittente'],(int)$previous['iddestinatario']];sort($previousPair);if($pair!==$previousPair)break;
  $thread[]=$previous;$current=$previous;
 }
 $thread=array_reverse($thread);$linked=[];foreach($thread as $letter)$linked[(int)$letter['id']]=$letter;
 // Follow explicit children too, so opening an older letter shows later replies.
 $candidates=ConnectDB()->query('SELECT * FROM mail WHERE ((idmittente=? AND iddestinatario=?) OR (idmittente=? AND iddestinatario=?)) AND ((idmittente=? AND delmitt=0) OR (iddestinatario=? AND deldest=0)) AND messaggio LIKE ? ORDER BY id DESC LIMIT 200',[$pair[0],$pair[1],$pair[1],$pair[0],$viewer,$viewer,'<span class="mail-parent-reference" id="mail-parent-%']);
 do{$added=false;foreach($candidates as $letter){
  if(count($linked)>=25)break;
  if(isset($linked[(int)$letter['id']]))continue;
  if(preg_match('~^<span class="mail-parent-reference" id="mail-parent-(\d+)"></span>~',(string)$letter['messaggio'],$m)&&isset($linked[(int)$m[1]])){$linked[(int)$letter['id']]=$letter;$added=true;}
 }}while($added&&count($linked)<25);
 $thread=array_values($linked);usort($thread,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);return $thread;
}
function alphano_mail_bubble_parts(string $message):array {
 $html=alphano_mail_render($message);$doc=new DOMDocument('1.0','UTF-8');$prior=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($prior);$xpath=new DOMXPath($doc);$parts=['body'=>'','signature'=>'','history'=>''];
 foreach(['mail-quoted-history'=>'history','mail-signature'=>'signature','mail-parent-reference'=>null] as $class=>$part){
  $nodes=iterator_to_array($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]'));
  // Old unstructured letters may contain several authors: preserve their order.
  if($class==='mail-signature'&&count($nodes)>1)continue;
  foreach($nodes as $node){
   if(!$node->parentNode)continue;if($part!==null)$parts[$part].=$doc->saveHTML($node);$node->parentNode->removeChild($node);
  }
 }
 $body=$doc->getElementsByTagName('body')->item(0);if($body)foreach($body->childNodes as $node)$parts['body'].=$doc->saveHTML($node);
 return $parts;
}

/** Plain text only, never copy signatures or attachment markup into list previews. */
function alphano_mail_preview(string $body):string {
 $body=preg_split('~<div class="(?:mail-signature|mail-quoted-history)~',$body,2)[0];
 $body=preg_replace('~\[i\].*?\[/i\]~s','',$body);
 return mb_substr(trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8'))),0,150);
}
function alphano_mail_contact_conversation(array $selected,int $viewer,int $before=0):array {
 $other=(int)$selected['idmittente']===$viewer?(int)$selected['iddestinatario']:(int)$selected['idmittente'];
 if($other<=0)return [$selected];
 $params=[$viewer,$other,$other,$viewer,$viewer,$viewer];$cutoff='';
 if($before>0){$cutoff=' AND id<?';$params[]=$before;}
 $letters=ConnectDB()->query('SELECT * FROM mail WHERE ((idmittente=? AND iddestinatario=?) OR (idmittente=? AND iddestinatario=?)) AND ((idmittente=? AND delmitt=0) OR (iddestinatario=? AND deldest=0))'.$cutoff.' ORDER BY id DESC LIMIT 81',$params);
 return array_reverse($letters);
}

/** A hidden sender copy remains private; expose only the owner's recoverable count. */
function alphano_mail_hidden_sent(int $viewer,int $other):int {
 if($viewer<1||$other<1)return 0;
 return (int)ConnectDB()->query('SELECT COUNT(*) n FROM mail WHERE idmittente=? AND iddestinatario=? AND delmitt=1',[$viewer,$other])[0]['n'];
}

/** Explicit recovery never resends mail or changes the recipient's flags or text. */
function alphano_mail_restore_sent(int $viewer,int $other):int {
 if($viewer<1||$other<1)throw new DomainException('Interlocutore non valido.',422);
 return EconomiaAtomica(static function()use($viewer,$other){
  $d=ConnectDB();$d->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$viewer]);
  $rows=$d->query('SELECT id FROM mail WHERE idmittente=? AND iddestinatario=? AND delmitt=1 FOR UPDATE',[$viewer,$other]);
  if(!$rows)return 0;
  $d->execute('UPDATE mail SET delmitt=0 WHERE idmittente=? AND iddestinatario=? AND delmitt=1',[$viewer,$other]);
  return count($rows);
 });
}
