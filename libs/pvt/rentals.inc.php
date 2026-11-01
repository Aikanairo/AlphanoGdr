<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/kernel.inc.php';
class AlphanoRentalExpired extends DomainException {
 public function __construct(public readonly array $rental){parent::__construct('L’oste ti invita a lasciare la camera: il tempo dell’affitto è terminato.',410);}
}
function alphano_rental_expire(?string $key=null):void {
 require_once dirname(__DIR__).'/companions.inc.php';companionship_release_expired($key);
 $db=ConnectDB();$rows=$db->query('SELECT id FROM chat_rentals WHERE closed_at=0 AND expires_at<=?'.($key!==null?' AND chat_key=?':''),$key!==null?[time(),$key]:[time()]);
 foreach($rows as $r){$db->beginTransaction();try{$room=$db->query('SELECT * FROM chat_rentals WHERE id=? FOR UPDATE',[$r['id']])[0];if($room['closed_at']){$db->commit();continue;}
  $host=$db->query('SELECT title FROM mappe WHERE chat_key=?',[$room['host_key']])[0]['title']??'Sala comune';
  $html='<div class="MasterKey"><strong>Fine dell’affitto</strong><p>L’oste ti invita a lasciare la camera. Il tempo dell’affitto è terminato: ritorni nella sala comune.</p></div>';
  $db->execute('INSERT INTO chat(chat,ora,msg,pg,dest) VALUES(?,?,?,0,0)',[$room['chat_key'],time(),$html]);
  $db->execute('INSERT INTO registrazionichat(registrazione,testo) SELECT id,? FROM registrazioni WHERE chat=?',[$html,$room['chat_key']]);
  $db->execute('UPDATE utenti SET chat=?,posizione=?,lastmove=? WHERE chat=?',[$room['host_key'],$host,time(),$room['chat_key']]);
  $db->execute('UPDATE chat_rentals SET closed_at=?,word_lookup=NULL WHERE id=?',[time(),$room['id']]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
}
function alphano_rental_info(string $key,int $pg):?array {
 if(!preg_match('/^rent_[a-f0-9]{40}$/D',$key))return null;
 alphano_rental_expire($key);
 return ConnectDB()->query('SELECT r.*,m.title AS host_title,m.scene_image,m.scene_thumbnail,m.scene_icon,n.name AS companion_name,a.kind AS companion_kind FROM chat_rentals r JOIN chat_rental_members p ON p.rental_id=r.id AND p.player_id=? LEFT JOIN mappe m ON m.chat_key=r.host_key LEFT JOIN companion_appointments a ON a.rental_id=r.id LEFT JOIN ai_npcs n ON n.id=a.npc_id WHERE r.chat_key=?',[$pg,$key])[0]??null;
}
function alphano_rental_room(string $key,int $pg):?array {
 $r=alphano_rental_info($key,$pg);if(!$r)return null;if($r['closed_at'])throw new AlphanoRentalExpired(['host_key'=>$r['host_key'],'expires_at'=>(int)$r['expires_at']]);
 $u=ConnectDB()->query('SELECT bloccato,imprigionato FROM utenti WHERE id=?',[$pg])[0]??null;if(!$u||$u['bloccato']||$u['imprigionato'])return null;
 $title=($r['host_title']??'Locanda').' · Camera '.$r['id'];
 return ['chat_key'=>$key,'chat'=>1,'isChatFlag'=>1,'title'=>$title,'position'=>$title,'vampiredamage'=>0,'sfondotop'=>'','sfondo'=>'','mappa'=>'','scene_image'=>$r['scene_image']??'','scene_thumbnail'=>$r['scene_thumbnail']??'','scene_icon'=>'book','descrizione'=>'Una camera riservata, protetta dalla parola d’ordine scelta dall’affittuario. Le conversazioni sono visibili soltanto agli ospiti entrati nella camera.','private_kind'=>'rental','private_id'=>(int)$r['id'],'rental_expires'=>(int)$r['expires_at'],'rental_host'=>$r['host_key'],'companion_name'=>$r['companion_name']??''];
}
function alphano_rental_lookup(string $word):string {$secret=ConnectDB()->query('SELECT lookup_secret FROM chat_rental_settings WHERE id=1')[0]['lookup_secret'];return hash_hmac('sha256',$word,$secret);}
function alphano_rental_enter(int $pg,string $host,string $word,string $action,int $hours,string $request):array {
 if(!in_array($action,['rent','join'],true)||strlen($word)<3||strlen($word)>72||trim($word)!==$word||preg_match('/[\x00-\x1f]/',$word))throw new DomainException('Scegli una parola d’ordine da 3 a 72 caratteri, senza spazi iniziali o finali.',422);
 if($action==='rent'&&($hours<1||$hours>168||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request)))throw new DomainException('Durata o identificativo dell’affitto non valido (da 1 a 168 ore).',422);
 alphano_rental_expire();$db=ConnectDB();$db->beginTransaction();
 try{
  $u=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$pg])[0]??null;if(!$u||$u['bloccato']||$u['imprigionato'])throw new DomainException('Non puoi affittare o raggiungere una camera in questo momento.',403);
  // A durable receipt resolves a retry even if the first response was lost after moving the player.
  $old=$action==='rent'?($db->query('SELECT * FROM chat_rentals WHERE owner_id=? AND request_key=?',[$pg,$request])[0]??null):null;
  if($old){if($old['host_key']!==$host||$old['closed_at'])throw new DomainException('Richiesta già usata o affitto terminato.',409);$db->commit();return ['chat_key'=>$old['chat_key'],'expires_at'=>(int)$old['expires_at'],'total_cost'=>(int)$old['total_cost']];}
  if($u['chat']!==$host)throw new DomainException('Raggiungi prima la sala comune per accedere alle camere.',403);
  $place=$db->query('SELECT * FROM mappe WHERE chat_key=? AND chat=1 FOR UPDATE',[$host])[0]??null;if(!$place||!$place['rental_enabled'])throw new DomainException('Questo luogo non offre camere in affitto.',409);
  $lookup=alphano_rental_lookup($word);$room=$db->query('SELECT * FROM chat_rentals WHERE host_key=? AND word_lookup=? AND closed_at=0 FOR UPDATE',[$host,$lookup])[0]??null;
  if($action==='rent'){
   if($room)throw new DomainException('Questa parola d’ordine è già in uso: entra nella camera oppure scegline un’altra.',409);
   $cost=(int)$place['rental_hourly_cost']*$hours;if((int)$u['denari']<$cost)throw new DomainException(alphano_currency_text('Non hai abbastanza Corone per la durata scelta.'),409);
   $key='rent_'.bin2hex(random_bytes(20));$expires=time()+$hours*3600;
   $db->execute('INSERT INTO chat_rentals(chat_key,host_key,owner_id,word_hash,word_lookup,hours,hourly_cost,total_cost,started_at,expires_at,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$key,$host,$pg,password_hash($word,PASSWORD_DEFAULT),$lookup,$hours,(int)$place['rental_hourly_cost'],$cost,time(),$expires,$request]);$id=(int)$db->lastInsertId();
   $db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$cost,$pg]);
   $db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,?,?,?,0,?)',['affitto-camera',time(),$host,$pg,$pg,$cost,$hours.' ore · tariffa '.$place['rental_hourly_cost'].alphano_currency_text(' corone/ora')]);
   $room=['id'=>$id,'chat_key'=>$key,'expires_at'=>$expires,'total_cost'=>$cost];
  }else{
   $db->execute('INSERT IGNORE INTO chat_rental_limits(player_id,window_start,attempts) VALUES(?,?,0)',[$pg,time()]);$lim=$db->query('SELECT * FROM chat_rental_limits WHERE player_id=? FOR UPDATE',[$pg])[0];$count=time()-(int)$lim['window_start']<300?(int)$lim['attempts']:0;
   if($count>=10)throw new DomainException('Troppi tentativi. Attendi qualche minuto prima di riprovare.',429);
   $db->execute('UPDATE chat_rental_limits SET attempts=?,window_start=? WHERE player_id=?',[$count+1,$count?(int)$lim['window_start']:time(),$pg]);
   if(!$room||!password_verify($word,$room['word_hash'])){$db->commit();throw new DomainException('Parola d’ordine non valida o camera scaduta.',403);}
  }
  companionship_assert_rental_join((int)$room['id'],$pg);
  $db->execute('INSERT IGNORE INTO chat_rental_members(rental_id,player_id,joined_at) VALUES(?,?,?)',[$room['id'],$pg,time()]);
  $db->execute('UPDATE utenti SET chat=?,posizione=?,lastmove=? WHERE id=?',[$room['chat_key'],$place['title'].' · Camera '.$room['id'],time(),$pg]);$db->commit();
  return ['chat_key'=>$room['chat_key'],'expires_at'=>(int)$room['expires_at'],'total_cost'=>$action==='rent'?(int)$room['total_cost']:0];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
