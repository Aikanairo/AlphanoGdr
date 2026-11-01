<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/libeconomia.inc.php';
require_once __DIR__.'/libequip.inc.php';

/** Staff can select a corporation explicitly; members can only use their own mandate. */
function alphano_corporation_context(array $user,mixed $requested=null):int {
 if(!alphano_config('corporations_enabled'))throw new DomainException('Corporazioni e produzione di oggetti disabilitate dalla gestione.',403);
 $own=(int)($user['corporazione']??0);$id=$requested===null||$requested===''?$own:filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$id)throw new DomainException('Seleziona una corporazione.',($requested===null||$requested==='')&&empty($user['admin'])?403:400);
 if(empty($user['admin'])&&(int)$id!==$own)throw new DomainException('Non puoi gestire questa corporazione.',403);
 if(!ConnectDB()->query('SELECT id FROM corporazioni WHERE id=?',[$id]))throw new DomainException('Corporazione non trovata.',404);
 return (int)$id;
}
function alphano_material_market(int $seller):array {
 $db=ConnectDB();
 $items=$db->query("SELECT MIN(p.id) AS instance,e.id,e.shortDesc,e.materiaprima,e.immagine,COUNT(*) AS quantity FROM equippg p JOIN equip e ON e.id=p.uid JOIN tipo t ON t.id=e.tipo WHERE p.idpg=? AND p.slot='' AND p.idpadre=0 AND t.tipoG='risorsa' AND e.materiaprima<>'' GROUP BY e.id,e.shortDesc,e.materiaprima,e.immagine ORDER BY e.shortDesc",[$seller]);
 foreach($items as &$i)$i['image']=alphano_equipment_image($i['immagine']);unset($i);
 $buyers=$db->query("SELECT DISTINCT c.id AS corp,c.nome,u.id,u.nome AS buyer FROM corporazioni c JOIN utenti u ON (u.corporazione=c.id AND (u.admincorporazione=1 OR u.admintrasfcorp=1)) OR (u.id=c.idmaster AND u.admin=1) WHERE c.approvato=1 AND u.id<>? AND u.bloccato=0 ORDER BY c.nome,u.nome",[$seller]);
 return ['items'=>$items,'buyers'=>$buyers];
}
function alphano_offer_material(int $seller,int $instance,int $corp,int $buyer,int $qty,int $total,string $request):bool {
 return EconomiaAtomica(static function()use($seller,$instance,$corp,$buyer,$qty,$total,$request):bool{
  if($seller===$buyer||$qty<1||$qty>10000||!CommercioPrezzoValido((float)$total))throw new DomainException('Quantità, prezzo o responsabile non validi.',422);
  if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$request))throw new DomainException('Richiesta non valida.',400);
  $db=ConnectDB();$staff=$db->query('SELECT id FROM utenti WHERE id=? AND bloccato=0',[$buyer]);
  if(!$staff||!EconomiaImpiegato($buyer,$corp)||!$db->query('SELECT id FROM corporazioni WHERE id=? AND approvato=1',[$corp]))throw new DomainException('Responsabile non disponibile.',422);
  if(!alphano_operation_once('material-offer',$seller,$request,[$instance,$corp,$buyer,$qty,$total]))return true;
  $r=$db->query("SELECT e.id,e.shortDesc,e.materiaprima FROM equippg p JOIN equip e ON e.id=p.uid JOIN tipo t ON t.id=e.tipo WHERE p.id=? AND p.idpg=? AND p.slot='' AND p.idpadre=0 AND t.tipoG='risorsa' FOR UPDATE",[$instance,$seller])[0]??null;
  if(!$r||!in_array($r['materiaprima'],MATERIE_PRIME,true))throw new DomainException('Materia prima non disponibile nella tua borsa.',409);
  $stock=$db->query("SELECT id FROM equippg WHERE uid=? AND idpg=? AND slot='' AND idpadre=0 FOR UPDATE",[$r['id'],$seller]);
  if(count($stock)<$qty)throw new DomainException('Non possiedi la quantità richiesta.',409);
  if($db->query('SELECT id FROM trasferimenti WHERE pg=? AND pgcorp=? AND corp=? AND obj=? AND verso=1 AND stato<5',[$seller,$buyer,$corp,$instance]))throw new DomainException('Hai già una proposta aperta per questo materiale e responsabile.',409);
  $db->execute('INSERT INTO trasferimenti(data,corp,pgcorp,pg,obj,nomeobj,qta,prezzo,verso,stato) VALUES(?,?,?,?,?,?,?,?,1,3)',[time(),$corp,$buyer,$seller,$instance,$r['shortDesc'],$qty,$total]);
  $trade=['id'=>(int)$db->lastInsertId(),'corp'=>$corp,'pg'=>$seller,'pgcorp'=>$buyer,'obj'=>$instance,'nomeobj'=>$r['shortDesc'],'qta'=>$qty,'prezzo'=>$total,'verso'=>1];
  CommercioAttivita('purchase-proposal',$seller,$trade,'Materie prime offerte alla corporazione',alphano_currency_text("Offerti {$qty}× {$r['shortDesc']} per {$total} Corone totali. Beni e denaro cambieranno proprietario soltanto all’accettazione."),[],['stato'=>3,'qta'=>$qty,'prezzo'=>$total],['iniziativa'=>'venditore']);
  return true;
 });
}
