<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/libeconomia.inc.php';

/** Read current permissions/state, never permissions inferred from a mail link. */
function alphano_trade_thread(int $id,int $viewer,bool $lock=false):array {
 $db=ConnectDB();$t=$db->query('SELECT * FROM trasferimenti WHERE id=?'.($lock?' FOR UPDATE':''),[$id])[0]??null;
 if(!$t)throw new DomainException('Trattativa non disponibile.',404);
 $owner=(int)$t['pg']===$viewer;$staff=EconomiaImpiegato($viewer,(int)$t['corp']);$assigned=(int)$t['pgcorp']===$viewer;
 if(!$owner&&!$staff)throw new DomainException('Non puoi consultare questa trattativa.',403);
 $u=$db->query('SELECT razza,bloccato FROM utenti WHERE id=?',[$viewer])[0]??[];
 $can=!empty($u)&&empty($u['bloccato'])&&!in_array((int)$u['razza'],[4,7,8],true);$s=(int)$t['stato'];$order=(int)$t['verso']===2;$actions=[];
 if($can&&$order){
  if(!$owner&&$staff&&!(int)$t['pgcorp']&&in_array($s,[0,5],true))$actions[]='take';
  if(($owner&&$s===3)||(!$owner&&$assigned&&in_array($s,[1,2],true)))$actions[]='propose';
  if(($owner&&$s===3)||(!$owner&&$assigned&&$s===2))$actions[]='accept';
  if(!$owner&&$assigned&&$s===5)$actions[]='deliver';
  if($s<=5&&($owner||($staff&&($assigned||!(int)$t['pgcorp']))))$actions[]='cancel';
 }elseif($can&&!$order&&$s<5){
  if(($owner&&in_array($s,[2,4],true))||(!$owner&&$assigned&&$s===3))$actions[]='propose';
  if(($owner&&$s===4)||(!$owner&&$assigned&&$s===3))$actions[]='accept';
  if($owner||$assigned)$actions[]='cancel';
 }
 $revision=hash('sha256',implode(':',array_map(static fn($k)=>(string)$t[$k],['id','verso','stato','pgcorp','qta','prezzo'])));
 $image=$db->query($order?'SELECT immagine FROM equip WHERE id=?':'SELECT e.immagine FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=?',[(int)$t['obj']])[0]['immagine']??'';
 if(!$image)$image=$db->query('SELECT immagine FROM equip WHERE shortDesc=? AND (corp=? OR corp=0) ORDER BY id DESC LIMIT 1',[$t['nomeobj'],$t['corp']])[0]['immagine']??'';
 return ['image'=>alphano_equipment_image(rawurldecode($image)),'id'=>$id,'revision'=>$revision,'order'=>$order,'state'=>$s,'status'=>StatoTransf($s,(int)$t['verso']),
  'corporation'=>(string)($db->query('SELECT nome FROM corporazioni WHERE id=?',[$t['corp']])[0]['nome']??'Corporazione'),
  'item'=>(string)$t['nomeobj'],'quantity'=>(int)$t['qta'],'price'=>(int)$t['prezzo'],'total'=>$order?(int)$t['qta']*(int)$t['prezzo']:(int)$t['prezzo'],
  'customer'=>GetPGFromID((int)$t['pg']),'responsible'=>(int)$t['pgcorp']?GetPGFromID((int)$t['pgcorp']):'Da assegnare','actions'=>$actions,
  'url'=>$owner?'/trasferimenti/?trade='.$id:'/corporazioni/trasferimenti.php?corp='.(int)$t['corp'].'&trade='.$id,
  'corp'=>(int)$t['corp'],'owner'=>$owner];
}
function alphano_trade_thread_act(int $id,int $viewer,array $input):array {
 return EconomiaAtomica(static function()use($id,$viewer,$input):array{
  $t=alphano_trade_thread($id,$viewer,true);$action=$input['action']??'';
  if(!is_string($input['revision']??null)||!hash_equals($t['revision'],$input['revision']))throw new DomainException('La proposta è cambiata. Controlla i valori aggiornati prima di rispondere.',409);
  if(!in_array($action,$t['actions'],true))throw new DomainException('Questa azione non è più disponibile o non spetta a te.',409);
  if($action==='propose'){
   $q=CommercioIntero($input['quantity']??null,1,10000);$p=CommercioIntero($input['price']??null,1,2147483647);
   if($q===null||$p===null||!CommercioPrezzoValido((float)$p,$t['order']?$q:1))throw new DomainException('Inserisci quantità e prezzo interi validi.',422);
   $ok=$t['order']?UpdateOrdine($id,$viewer,(float)$p,$q,$t['owner']?2:3):UpdateTransf($id,$viewer,(float)$p,$t['owner']?3:4,$q);
  }elseif($action==='take')$ok=PrendiInCaricoOrdine($id,$viewer);
  elseif($action==='cancel')$ok=$t['order']?RejectOrdine($id,$viewer):RejectTransf($id,$viewer);
  else{$message=$action==='deliver'?Consegna($id,$viewer,$t['corp']):($t['order']?FinalizeOrdine($id,$viewer):FinalizeTransf($id,$viewer));$ok=in_array($message,['Consegna andata a buon fine','Ordine accettato con successo','Trasferimento avvenuto correttamente'],true);}
  if(!$ok)throw new DomainException($message??'Operazione non completata: verifica disponibilità, saldo e materie prime.',409);
  return alphano_trade_thread($id,$viewer);
 });
}

/** Current next step is shared by mail, the chronicle and the inline card. */
function alphano_trade_summary(array $t):array {
 $actions=$t['actions'];$state=$t['state'];
 if($state===6){$key='cancelled';$label='Trattativa annullata';$next='Nessuna azione richiesta';}
 elseif($state===7||(!$t['order']&&$state===5)){$key='completed';$label=$t['order']?'Consegnato e pagato':'Acquisto completato';$next='Transazione conclusa';}
 elseif(in_array('deliver',$actions,true)){$key='delivery';$label='Ordine concordato';$next='Consegna l’oggetto';}
 elseif(in_array('take',$actions,true)){$key='action';$label='Da assegnare';$next='Prendi in carico';}
 elseif(in_array('accept',$actions,true)){$key='action';$label='Proposta ricevuta';$next='Rispondi alla proposta';}
 elseif(in_array('propose',$actions,true)){$key='action';$label='In trattativa';$next='Invia la tua proposta';}
 else{$key='waiting';$label=$state===5?'Ordine concordato':'In attesa';$next=$state===5?'Attendi la consegna':($t['responsible']==='Da assegnare'?'Attendi la presa in carico':'Attendi la controparte');}
 return ['id'=>$t['id'],'key'=>$key,'label'=>$label,'next'=>$next,'image'=>$t['image'],'item'=>$t['item'],'state'=>$state,'revision'=>$t['revision']];
}
function alphano_trade_current_summary(int $id,int $viewer):?array {
 static $cache=[];$key=$viewer.':'.$id;
 if(!array_key_exists($key,$cache)){try{$cache[$key]=alphano_trade_summary(alphano_trade_thread($id,$viewer));}catch(DomainException){$cache[$key]=null;}}
 return $cache[$key];
}
function alphano_trade_mail_summary(array $mail,int $viewer):?array {
 if((int)$mail['idmittente']!==0||!preg_match('/\[alphano-trade:(\d+):(\d+)\]/',(string)$mail['messaggio'],$m))return null;
 return alphano_trade_current_summary((int)$m[1],$viewer);
}
function alphano_trade_notice_summaries(array $items,int $viewer):array {
 foreach($items as &$item)if(!empty($item['data']['order_id']))$item['commerce']=alphano_trade_current_summary((int)$item['data']['order_id'],$viewer);
 unset($item);return $items;
}
