<?php
declare(strict_types=1);

require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libposta.inc.php';
require_once __DIR__ . '/libequip.inc.php';
require_once __DIR__ . '/operations.inc.php';
require_once __DIR__ . '/activity.inc.php';

use Alphano\Database\PDOMySQLDriver;

/** Tutte le scritture di un movimento condividono la stessa transazione. */
function EconomiaAtomica(callable $operation): mixed
{
    $pdo = getDB()->getConnection();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $result = $operation();
        if ($own) $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
/** DB-only operations may retry a deadlock only when owning the entire transaction. */
function EconomiaAtomicaResiliente(callable $operation): mixed
{
    $db=getDB();$own=!$db->inTransaction();$attempt=0;
    do {
        $savepoint='economia_'.bin2hex(random_bytes(6));
        if($own)$db->beginTransaction();else $db->execute('SAVEPOINT '.$savepoint);
        try {
            $result=$operation();
            if($own)$db->commit();else $db->execute('RELEASE SAVEPOINT '.$savepoint);
            return $result;
        } catch(Throwable $e) {
            if($db->inTransaction()) {
                if($own)$db->rollBack();
                else {$db->execute('ROLLBACK TO SAVEPOINT '.$savepoint);$db->execute('RELEASE SAVEPOINT '.$savepoint);}
            }
            $deadlock=$e instanceof PDOException&&((string)$e->getCode()==='40001'||in_array((int)($e->errorInfo[1]??0),[1205,1213],true));
            if(!$own||!$deadlock||++$attempt>=3)throw $e;
        }
    }while(true);
}
function EconomiaBool(callable $operation): bool
{
    try { return (bool)EconomiaAtomica($operation); }
    catch (DomainException $e) { return false; }
    catch (RuntimeException $e) {
        if (in_array($e->getCode(),[400,409],true)) throw $e;
        error_log('[economia] '.$e->getMessage()); return false;
    }
    catch (Throwable $e) { error_log('[economia] '.$e->getMessage()); return false; }
}
function EconomiaVerifica(bool $condition, string $message): void
{
    if (!$condition) throw new DomainException($message);
}
function EconomiaImpiegato(int $pg, int $corp): bool
{
    return (bool)getDB()->query('SELECT 1 FROM utenti WHERE id=? AND (admin=1 OR (corporazione=? AND (admintrasfcorp=1 OR admincorporazione=1)))', [$pg,$corp]);
}


/**
 * Trasferisce denaro da un PG a un altro, registra log e invia notifiche.
 */
function TrasferisciSoldi(int $mittente, string $nomeMittente, int $dposseduti, int $destinatario, int $soldi, string $motivo = '', ?string $requestKey = null): bool
{
    if ($soldi<=0 || $mittente===$destinatario) return false;
    return EconomiaBool(static function() use($mittente,$destinatario,$soldi,$motivo,$requestKey): bool {
        if (!alphano_operation_once('money-transfer',$mittente,$requestKey,[$destinatario,$soldi,$motivo])) return true;
        $db=getDB();
        $rows=$db->query('SELECT id,nome,denari FROM utenti WHERE id IN (?,?) ORDER BY id FOR UPDATE',[$mittente,$destinatario]);
        $users=array_column($rows,null,'id');
        EconomiaVerifica(isset($users[$mittente],$users[$destinatario]) && (int)$users[$mittente]['denari'] >= $soldi && (int)$users[$destinatario]['denari']<=PHP_INT_MAX-$soldi,'Fondi insufficienti o destinatario assente');
        $db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$soldi,$mittente]);
        $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$soldi,$destinatario]);
        $db->execute('INSERT INTO logs_adminpg(azione,esecutore,destinatario,qta,data,descr,chat) VALUES(?,?,?,?,?,?,?)',['trasferimento',$mittente,$destinatario,$soldi,time(),$motivo,'']);
        InviaMissiva('Notifica di Trasferimento',$users[$mittente]['nome'].alphano_currency_text(" ti ha dato {$soldi} Corone").($motivo!=='' ? ", motivazione: {$motivo}" : '.'),$destinatario);
        alphano_record_activity('money-transferred',$mittente,[
            ['scope'=>'personale','id'=>$mittente,'section'=>'trasferimenti','role'=>'member'],
            ['scope'=>'personale','id'=>$destinatario,'section'=>'trasferimenti','role'=>'member'],
        ],alphano_currency_text('Trasferimento di Corone'),$users[$mittente]['nome'].alphano_currency_text(" ha trasferito {$soldi} Corone a ").$users[$destinatario]['nome'].($motivo!=='' ? ". Motivazione: {$motivo}" : '.'),['mittente'=>$mittente,'destinatario'=>$destinatario,'importo'=>$soldi,'motivo'=>$motivo]);
        return true;
    });
}

/**
 * Restituisce la descrizione di uno stato prestito/trasferimento.
 */
function ProcessStato(int $stato): string
{
    return match($stato) {
        0 => 'Non ancora preso in carico',
        1 => 'Preso in carico',
        2 => 'Controproposta della corporazione',
        3 => 'Controproposta dell’organizzazione',
        4 => 'Controproposta della corporazione',
        5 => 'Accettato',
        6 => 'Estinto',
        default => 'Stato sconosciuto',
    };
}

/**
 * Elenco prestiti per un'organizzazione.
 */
function ListaPrestitiOrg(int $org): array
{
    return getDB()->query(
        'SELECT * FROM prestiti WHERE org = ? ORDER BY data ASC',
        [$org]
    );
}

/**
 * Elenco prestiti a carico del PG nell'organizzazione.
 */
function ListaPrestitiPGOrg(int $pg, int $org): array
{
    return getDB()->query(
        'SELECT * FROM prestiti
         WHERE org = ?
           AND (pgorg = ? OR (pgorg>0 AND NOT EXISTS(SELECT 1 FROM utenti u WHERE u.id=prestiti.pgorg AND u.organizzazione=prestiti.org AND u.adminorganizzazione=1)))
           AND stato < 6
         ORDER BY data ASC',
        [$org, $pg]
    );
}

/**
 * Elenco prestiti per una corporazione.
 */
function ListaPrestitiCorp(int $corp): array
{
    return getDB()->query(
        'SELECT * FROM prestiti WHERE corp = ? ORDER BY data ASC',
        [$corp]
    );
}

/**
 * Elenco prestiti a carico del PG nella corporazione.
 */
function ListaPrestitiPGCorp(int $pg, int $corp): array
{
    return getDB()->query(
        'SELECT * FROM prestiti WHERE corp = ? AND (pgcorp = ? OR NOT EXISTS(SELECT 1 FROM utenti u WHERE u.id=prestiti.pgcorp AND u.corporazione=prestiti.corp AND u.admincorporazione=1)) ORDER BY data ASC',
        [$corp, $pg]
    );
}

/** Importi di modulo: mai troncare decimali, esponenti o valori fuori intervallo. */
function EconomiaIntero(mixed $value, int $min = 0, int $max = PHP_INT_MAX): ?int
{
    if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) return null;
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
    return $number === false ? null : $number;
}

function EconomiaPercentuale(mixed $value): ?float
{
    if (!is_scalar($value)) return null;
    $number=str_replace(',','.',(string)$value);
    if (!preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D',$number)) return null;
    $rate=(float)$number;
    return is_finite($rate) && $rate>=0 && $rate<=100 ? $rate : null;
}

/** I moduli HTML conservano il pannello anche dopo un conflitto di reinvio. */
function EconomiaAzioneModulo(callable $operation): bool
{
    try { return (bool)$operation(); }
    catch (RuntimeException $e) {
        if (!in_array($e->getCode(),[400,409],true)) throw $e;
        http_response_code($e->getCode());
        return false;
    }
}

/** Il mandato deve essere ancora valido, anche per chi gestiva già il prestito. */
function PrestitoResponsabileValido(array $loan, string $side): bool
{
    $field=$side==='org'?'organizzazione':'corporazione';
    $flag=$side==='org'?'adminorganizzazione':'admincorporazione';
    return (bool)getDB()->query("SELECT id FROM utenti WHERE id=? AND {$field}=? AND {$flag}=1",[(int)$loan['pg'.$side],(int)$loan[$side]]);
}

function PrestitoInterlocutore(array &$loan, int $pg): string
{
    $db=getDB();
    $user=$db->query('SELECT organizzazione,corporazione,adminorganizzazione,admincorporazione FROM utenti WHERE id=? FOR UPDATE',[$pg])[0] ?? null;
    if (!$user) return '';
    foreach (['org'=>['organizzazione','adminorganizzazione'],'corp'=>['corporazione','admincorporazione']] as $side=>[$field,$flag]) {
        if ((int)$user[$field]!==(int)$loan[$side] || empty($user[$flag])) continue;
        if ((int)$loan['pg'.$side]===$pg) return $side;
        // A new mandate can recover an orphaned assignment, never take a valid one.
        if ((int)$loan['pg'.$side]>0 && !PrestitoResponsabileValido($loan,$side)) {
            $db->execute('UPDATE prestiti SET pg'.$side.'=? WHERE id=?',[$pg,$loan['id']]);
            $loan['pg'.$side]=$pg;
            return $side;
        }
    }
    return '';
}

/** Non consegna dettagli riservati a responsabili che hanno perso il mandato. */
function PrestitoMissiva(array $loan, string $side, string $title, string $detail): void
{
    $field=$side==='org'?'organizzazione':'corporazione';
    $flag=$side==='org'?'adminorganizzazione':'admincorporazione';
    $owner=(int)$loan['pg'.$side];
    $recipients=PrestitoResponsabileValido($loan,$side)?[['id'=>$owner]]:getDB()->query("SELECT id FROM utenti WHERE {$field}=? AND {$flag}=1",[(int)$loan[$side]]);
    foreach ($recipients as $recipient) InviaMissiva($title,$detail,(int)$recipient['id']);
}

function PrestitoMessaggio(array $loan, string $event): string
{
    $db=getDB();
    $org=$db->query('SELECT nome FROM organizzazioni WHERE id=?',[$loan['org']])[0]['nome'] ?? 'Organizzazione';
    $corp=$db->query('SELECT nome FROM corporazioni WHERE id=?',[$loan['corp']])[0]['nome'] ?? 'Corporazione';
    return alphano_currency_text("Prestito #{$loan['id']}: {$corp} / {$org}. {$event}\nImporto: {$loan['quantita']} Corone. Interesse mensile semplice: {$loan['interessi']}%.");
}

/** Storico condiviso, scritto nella stessa transazione del movimento. */
function PrestitoEvento(array $loan, int $actor, string $event, string $title, string $detail, array $data = []): void
{
    alphano_record_activity('loan-'.$event,$actor,[
        ['scope'=>'organizzazioni','id'=>(int)$loan['org'],'section'=>'finanze','role'=>'admin'],
        ['scope'=>'corporazioni','id'=>(int)$loan['corp'],'section'=>'finanze','role'=>'admin'],
    ],$title,PrestitoMessaggio($loan,$detail),['loan_id'=>(int)$loan['id'],'quantita'=>(int)$loan['quantita'],'interessi'=>(int)$loan['interessi'],'stato'=>(int)($loan['stato'] ?? 0),'restituiti'=>(int)($loan['restituiti'] ?? 0),...$data]);
}

/** Crea la proposta della corporazione, prima del finanziamento. */
function CreatePrestito(int $pgcorp, int $corp, int $org, int $quantita, int $interessi, ?string $requestKey = null): bool
{
    if ($quantita<=0 || $quantita>2147483647 || $interessi<0 || $interessi>100) return false;
    return EconomiaBool(static function() use($pgcorp,$corp,$org,$quantita,$interessi,$requestKey): bool {
        if (!alphano_operation_once('loan-create',$pgcorp,$requestKey,[$corp,$org,$quantita,$interessi])) return true;
        $db=getDB();
        $user=$db->query('SELECT organizzazione FROM utenti WHERE id=? AND corporazione=? AND admincorporazione=1 FOR UPDATE',[$pgcorp,$corp])[0] ?? null;
        EconomiaVerifica($user && (int)$user['organizzazione']!==$org && (bool)$db->query('SELECT id FROM corporazioni WHERE id=?',[$corp]) && (bool)$db->query('SELECT id FROM organizzazioni WHERE id=? AND approvato=1',[$org]),'Organizzazione o mandato non valido');
        $db->execute('INSERT INTO prestiti(data,pgcorp,corp,org,quantita,interessi) VALUES(?,?,?,?,?,?)',[time(),$pgcorp,$corp,$org,$quantita,$interessi]);
        $loan=['id'=>(int)$db->lastInsertId(),'org'=>$org,'corp'=>$corp,'quantita'=>$quantita,'interessi'=>$interessi];
        PrestitoEvento($loan,$pgcorp,'requested','Nuova richiesta di prestito','La corporazione richiede un finanziamento.',['stato'=>0]);
        return true;
    });
}

/** Ogni controproposta passa il turno all'altro interlocutore. */
function UpdatePrestito(int $id, int $pg, int $quantita, int $interessi, int $stato, ?string $requestKey = null): bool
{
    if ($quantita<=0 || $quantita>2147483647 || $interessi<0 || $interessi>100 || !in_array($stato,[2,3,4,5],true)) return false;
    if ($stato===5) return AcceptPrestito($id,$pg,$requestKey);
    return EconomiaBool(static function() use($id,$pg,$quantita,$interessi,$stato,$requestKey): bool {
        if (!alphano_operation_once('loan-proposal',$pg,$requestKey,[$id,$quantita,$interessi,$stato])) return true;
        $db=getDB();
        $loan=$db->query('SELECT * FROM prestiti WHERE id=? AND stato BETWEEN 1 AND 4 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica((bool)$loan,'Prestito non negoziabile');
        $side=PrestitoInterlocutore($loan,$pg);
        $current=(int)$loan['stato'];
        EconomiaVerifica(($side==='org' && in_array($current,[1,2,4],true) && $stato===3) || ($side==='corp' && $current===3 && in_array($stato,[2,4],true)),'Non è il tuo turno');
        EconomiaVerifica($quantita!==(int)$loan['quantita'] || $interessi!==(int)$loan['interessi'],'La proposta è invariata');
        $db->execute('UPDATE prestiti SET quantita=?,interessi=?,stato=? WHERE id=?',[$quantita,$interessi,$stato,$id]);
        $oldQuantity=(int)$loan['quantita'];$oldInterest=(int)$loan['interessi'];
        $loan['quantita']=$quantita;$loan['interessi']=$interessi;
        PrestitoEvento($loan,$pg,'proposal','Controproposta di prestito','Nuove condizioni da valutare.',['stato'=>$stato,'stato_precedente'=>$current,'quantita_precedente'=>$oldQuantity,'interessi_precedenti'=>$oldInterest]);
        PrestitoMissiva($loan,$side==='org'?'corp':'org','Controproposta di prestito',PrestitoMessaggio($loan,'Nuova controproposta: verifica e accetta oppure modifica le condizioni.'));
        return true;
    });
}

/** Prende in carico una nuova richiesta, senza muovere fondi. */
function PrendiInCarico(int $id, int $pg, ?string $requestKey = null): bool
{
    return EconomiaBool(static function() use($id,$pg, $requestKey): bool {
        if (!alphano_operation_once('loan-claim',$pg,$requestKey,[$id])) return true;
        $db=getDB();
        $loan=$db->query('SELECT * FROM prestiti WHERE id=? AND stato=0 AND pgorg=0 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica((bool)$loan,'Richiesta già presa in carico');
        $user=$db->query('SELECT id FROM utenti WHERE id=? AND organizzazione=? AND adminorganizzazione=1 AND corporazione<>? FOR UPDATE',[$pg,$loan['org'],$loan['corp']]);
        EconomiaVerifica((bool)$user,'Mandato non valido');
        $db->execute('UPDATE prestiti SET pgorg=?,stato=1 WHERE id=?',[$pg,$id]);
        PrestitoEvento($loan,$pg,'claimed','Prestito preso in carico','Un amministratore dell’organizzazione ha preso in carico la richiesta.',['stato'=>1]);
        PrestitoMissiva($loan,'corp','Prestito preso in carico',PrestitoMessaggio($loan,'La richiesta è stata presa in carico dall’organizzazione.'));
        return true;
    });
}

/** Accetta le condizioni dell'altra parte e accredita una sola volta. */
function AcceptPrestito(int $id, int $pg, ?string $requestKey = null): bool
{
    return EconomiaBool(static function() use($id,$pg, $requestKey): bool {
        if (!alphano_operation_once('loan-accept',$pg,$requestKey,[$id])) return true;
        $db=getDB();
        $loan=$db->query('SELECT * FROM prestiti WHERE id=? AND stato BETWEEN 1 AND 4 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica($loan && (int)$loan['quantita']>0 && (int)$loan['quantita']<=2147483647 && (int)$loan['interessi']>=0 && (int)$loan['interessi']<=100,'Prestito non valido o già accettato');
        $side=PrestitoInterlocutore($loan,$pg);$state=(int)$loan['stato'];
        EconomiaVerifica(($side==='org' && in_array($state,[1,2,4],true)) || ($side==='corp' && $state===3),'Le condizioni devono essere accettate dall’altra parte');
        $org=$db->query('SELECT budget FROM organizzazioni WHERE id=? FOR UPDATE',[$loan['org']])[0] ?? null;
        $corp=$db->query('SELECT budget FROM corporazioni WHERE id=? FOR UPDATE',[$loan['corp']])[0] ?? null;
        $amount=(int)$loan['quantita'];
        EconomiaVerifica($org && $corp && (int)$org['budget']>=$amount && (int)$corp['budget']<=PHP_INT_MAX-$amount,'Fondi insufficienti o saldo fuori limite');
        $db->execute('UPDATE organizzazioni SET budget=budget-? WHERE id=?',[$amount,$loan['org']]);
        $db->execute('UPDATE corporazioni SET budget=budget+? WHERE id=?',[$amount,$loan['corp']]);
        $db->execute('UPDATE prestiti SET stato=5,data=? WHERE id=?',[time(),$id]);
        PrestitoEvento($loan,$pg,'accepted','Prestito accettato','Finanziamento accreditato al budget corporativo.',['stato'=>5,'org_budget_before'=>(int)$org['budget'],'org_budget_after'=>(int)$org['budget']-$amount,'corp_budget_before'=>(int)$corp['budget'],'corp_budget_after'=>(int)$corp['budget']+$amount]);
        PrestitoMissiva($loan,$side==='org'?'corp':'org','Prestito accettato e accreditato',PrestitoMessaggio($loan,alphano_currency_text('Accreditate le Corone al budget della corporazione. Gli interessi decorrono da oggi.')));
        return true;
    });
}

/** Rifiuto atomico: un reinvio non produce altre missive. */
function DeletePrestito(int $id, int $pg, ?string $requestKey = null): bool
{
    return EconomiaBool(static function() use($id,$pg, $requestKey): bool {
        if (!alphano_operation_once('loan-reject',$pg,$requestKey,[$id])) return true;
        $db=getDB();
        $loan=$db->query('SELECT * FROM prestiti WHERE id=? AND stato<5 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica((bool)$loan,'Prestito non eliminabile');
        $side=PrestitoInterlocutore($loan,$pg);
        EconomiaVerifica($side!=='','Mandato non valido');
        $other=$side==='org'?(int)$loan['pgcorp']:(int)$loan['pgorg'];
        if ($other>0) PrestitoMissiva($loan,$side==='org'?'corp':'org','Richiesta di prestito rifiutata',PrestitoMessaggio($loan,'La richiesta è stata rifiutata.'));
        PrestitoEvento($loan,$pg,'rejected','Prestito rifiutato','La richiesta è stata rifiutata.',['stato_precedente'=>(int)$loan['stato']]);
        $db->execute('DELETE FROM prestiti WHERE id=?',[$id]);
        return true;
    });
}

/** Interessi semplici, maturati soltanto per mesi interi. */
function CalcolaInteressi(int $num, int $intPercent, int $timestamp): int
{
    $start=(new DateTimeImmutable())->setTimestamp(max(0,min(time(),$timestamp)));
    $elapsed=$start->diff(new DateTimeImmutable());
    $months=$elapsed->y*12+$elapsed->m;
    $principal=max(0,$num);$rate=max(0,$intPercent);
    // Le quantità dei prestiti sono limitate a INT32: il prodotto resta intero.
    return $principal+intdiv($principal*$rate*$months,100);
}

/** Rimborso parziale/totale dal budget corporativo, con saldo corrente sotto lock. */
function PagaPrestito(int $id, int $pgcorp, float $pagamento, ?string $requestKey = null): bool
{
    if (!is_finite($pagamento) || $pagamento<1 || $pagamento>=PHP_INT_MAX || floor($pagamento)!==$pagamento) return false;
    return EconomiaBool(static function() use($id,$pgcorp,$pagamento, $requestKey): bool {
        if (!alphano_operation_once('loan-repay',$pgcorp,$requestKey,[$id,$pagamento])) return true;
        $db=getDB();
        $loan=$db->query('SELECT * FROM prestiti WHERE id=? AND stato=5 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica($loan && PrestitoInterlocutore($loan,$pgcorp)==='corp','Prestito o mandato non valido');
        $remaining=CalcolaInteressi((int)$loan['quantita'],(int)$loan['interessi'],(int)$loan['data'])-(int)$loan['restituiti'];
        $amount=min($remaining,(int)$pagamento);
        EconomiaVerifica($amount>0,'Importo non valido');
        $org=$db->query('SELECT budget FROM organizzazioni WHERE id=? FOR UPDATE',[$loan['org']])[0] ?? null;
        $corp=$db->query('SELECT budget FROM corporazioni WHERE id=? FOR UPDATE',[$loan['corp']])[0] ?? null;
        EconomiaVerifica($org && $corp && (int)$corp['budget']>=$amount && (int)$org['budget']<=PHP_INT_MAX-$amount,'Fondi insufficienti o saldo fuori limite');
        $db->execute('UPDATE prestiti SET restituiti=restituiti+?,stato=? WHERE id=?',[$amount,$remaining===$amount?6:5,$id]);
        $db->execute('UPDATE organizzazioni SET budget=budget+? WHERE id=?',[$amount,$loan['org']]);
        $db->execute('UPDATE corporazioni SET budget=budget-? WHERE id=?',[$amount,$loan['corp']]);
        PrestitoEvento($loan,$pgcorp,'repaid',$remaining===$amount?'Prestito estinto':'Rimborso parziale del prestito',alphano_currency_text("Ricevute {$amount} Corone. Residuo: ").($remaining-$amount).alphano_currency_text(' Corone.'),['stato'=>$remaining===$amount?6:5,'importo'=>$amount,'residuo'=>$remaining-$amount,'restituiti'=>(int)$loan['restituiti']+$amount,'org_budget_before'=>(int)$org['budget'],'org_budget_after'=>(int)$org['budget']+$amount,'corp_budget_before'=>(int)$corp['budget'],'corp_budget_after'=>(int)$corp['budget']-$amount]);
        PrestitoMissiva($loan,'org','Rimborso prestito',PrestitoMessaggio($loan,alphano_currency_text("Rimborsate {$amount} Corone. Debito residuo: ").($remaining-$amount).alphano_currency_text(' Corone.').($remaining===$amount?' Prestito estinto.':'')));
        return true;
    });
}

/** Importi/quantità arrivano dalle form come stringhe: mai troncare decimali o esponenti. */
function CommercioIntero(mixed $value, int $min = 1, int $max = 2147483647): ?int
{
    if (!is_int($value) && !is_string($value)) return null;
    $value=(string)$value;
    if (!preg_match('/^[0-9]+$/D',$value)) return null;
    $trimmed=ltrim($value,'0');
    if (strlen($trimmed)>10) return null;
    $number=(int)$value;
    return $number >= $min && $number <= $max ? $number : null;
}

/** I prezzi sono Corone intere, come i saldi persistiti. */
function CommercioPrezzoValido(float $price, int $quantity = 1): bool
{
    return is_finite($price) && $price >= 1 && floor($price)===$price
        && $quantity >= 1 && $quantity <= 10000 && $price <= intdiv(2147483647,$quantity);
}

/** Ricevuta immutabile, nella stessa transazione dei beni e del denaro. */
function CommercioAttivita(string $type,int $actor,array $trade,string $title,string $detail,array $before,array $after,array $extra=[],?string $eventKey=null): void
{
    $audiences=[['scope'=>'corporazioni','id'=>(int)$trade['corp'],'section'=>'trasferimenti','role'=>'trade']];
    if ((int)($trade['pg'] ?? 0)>0) $audiences[]=['scope'=>'personale','id'=>(int)$trade['pg'],'section'=>'trasferimenti','role'=>'member'];
    $event=alphano_record_activity($type,$actor,$audiences,$title,$detail,array_merge([
        'order_id'=>(int)$trade['id'],'stato'=>(int)$after['stato'],'verso'=>(int)$trade['verso'],
        'qta'=>(int)($after['qta'] ?? $trade['qta']),'prezzo'=>(int)($after['prezzo'] ?? $trade['prezzo']),
        'oggetto'=>(string)$trade['nomeobj'],'prima'=>$before,'dopo'=>$after,
    ],$extra),$eventKey);
    $db=ConnectDB();$current=$db->query('SELECT * FROM trasferimenti WHERE id=?',[(int)$trade['id']])[0]??$trade;
    $recipients=[(int)($current['pg']??0),(int)($current['pgcorp']??0)];
    if(empty($current['pgcorp']))foreach($db->query('SELECT id FROM utenti WHERE corporazione=? AND (admintrasfcorp=1 OR admincorporazione=1) AND bloccato=0',[(int)$trade['corp']]) as $member)$recipients[]=(int)$member['id'];
    $marker='[alphano-trade:'.(int)$trade['id'].':'.$event.']';
    foreach(array_unique($recipients) as $recipient){
        if($recipient<1||$recipient===$actor)continue;
        if($db->query('SELECT id FROM mail WHERE iddestinatario=? AND idmittente=0 AND messaggio LIKE ? LIMIT 1',[$recipient,'%'.$marker.'%']))continue;
        InviaMissiva($title.' · #'.(int)$trade['id'],$detail."\n\nApri questa missiva per consultare lo stato aggiornato e rispondere alla trattativa.\n".$marker,$recipient);
    }
}

/**
 * Catalogo generale (visibile/equip approvati) per una corporazione.
 */
function GetCatalogo(int $corp, bool $all = false): array
{
    $db     = getDB();
    $allSql = $all ? '' : 'AND equip.approvato = 1';

    $sql = <<<SQL
SELECT
  equip.*,
  equip.tipo AS tipo_id,
  tipo.subtipo AS production_id,
  tipo.nome AS tipo
FROM equip
JOIN tipo
  ON tipo.id = equip.tipo
WHERE equip.corp          = ?
  $allSql
  AND equip.destinatario  = 0
  AND equip.destorg       = 0
  AND equip.visibile      = 1
ORDER BY equip.shortDesc
SQL;

    return $db->query($sql, [$corp]);
}
/**
 * Catalogo personale per PG (oggetti destinati).
 */
function GetCatalogoPers(int $corp, bool $all = false, ?int $pg = null): array
{
    $db  = getDB();
    if ($all) {
        $destSql = "AND equip.destinatario <> 0";
        // qui servono due volte $corp: uno per equip.corp e uno per p.corporazione
        $params = [$corp, $corp];
    } else {
        $destSql = "AND equip.approvato = 1 AND equip.destinatario = ?";
        // in ordine: equip.corp = ?, destinatario = ?, p.corporazione = ?
        $params = [$corp, $pg, $corp];
    }
    return $db->query(
        "SELECT equip.*,equip.tipo AS tipo_id, tipo.subtipo AS production_id,
  tipo.nome AS tipo
           FROM equip
           JOIN tipo ON tipo.id = equip.tipo
           JOIN produzionicorporazioni p ON p.produzione = tipo.subtipo
          WHERE equip.corp = ?
            $destSql
            AND equip.destorg = 0
            AND equip.visibile = 1
            AND p.corporazione = ?
          ORDER BY equip.shortDesc",
        $params
    );
}

/**
 * Catalogo personale per Organizzazione (oggetti destinati a org).
 */
function GetCatalogoPersOrg(int $corp, bool $all = false, ?int $org = null): array
{
    $db = getDB();
    if ($org !== null || $all) {
        if ($all) {
            $destSql = "AND equip.destorg <> 0";
            $params  = [$corp, $corp];
        } else {
            $destSql = "AND equip.approvato = 1 AND equip.destorg = ?";
            $params  = [$corp, $org, $corp];
        }
        return $db->query(
            "SELECT equip.*,equip.tipo AS tipo_id, tipo.subtipo AS production_id,
  tipo.nome AS tipo
               FROM equip
               JOIN tipo ON tipo.id = equip.tipo
               JOIN produzionicorporazioni p ON p.produzione = tipo.subtipo
              WHERE equip.corp = ?
                $destSql
                AND equip.destinatario = 0
                AND equip.visibile = 1
                AND p.corporazione = ?
              ORDER BY equip.shortDesc",
            $params
        );
    }
    return [];
}

/**
 * Catalogo oggetti “danneggiati” in possesso del PG.
 */
function GetCatalogoDamaged(int $idpg, int $corp): array
{
    return getDB()->query(
        "SELECT equip.*,equip.tipo AS tipo_id, tipo.subtipo AS production_id,
  tipo.nome AS tipo, equippg.id AS id, equippg.consumo AS consumo
           FROM equip
           JOIN tipo ON tipo.id = equip.tipo
           JOIN equippg ON equippg.uid = equip.id
           JOIN produzionicorporazioni p ON p.produzione = tipo.subtipo
          WHERE equip.corp = ?
            AND equippg.idpg = ?
            AND equippg.consumo > 0
            AND equip.visibile = 1
            AND p.corporazione = ?
          ORDER BY equip.shortDesc",
        [$corp, $idpg, $corp]
    );
}

/**
 * Stato di un trasferimento (offerta/compravendita).
 */
function StatoTransf(int $stato, int $verso): string
{
    $dest = $verso === 1 ? 'PG' : 'Corporazione';
    $acq  = $verso === 1 ? 'Corporazione' : 'PG';
    return match($stato) {
        0 => 'Non ancora preso in carico',
        1 => 'Preso in carico',
        2 => $verso === 1 ? 'In attesa del prezzo del venditore' : 'Proposta del cliente: risponde la corporazione',
        3 => $verso === 1 ? 'Proposta del venditore: risponde la corporazione' : 'Proposta della corporazione: risponde il cliente',
        4 => $verso === 1 ? 'Proposta della corporazione: risponde il venditore' : 'In contrattazione',
        5 => 'Accettato',
        6 => 'Respinto',
        7 => 'Consegnato',
        default => 'Stato sconosciuto',
    };
}


/**
 * Avvia un’offerta di acquisto di un oggetto, con quantità variabile.
 *
 * @param int   $equippgId  L’ID dell’istanza in zaino (equippg.id)
 * @param int   $dest       L’ID del player destinatario (venditore)
 * @param int   $pgcorp     L’ID del tuo PG (acquirente, la corporazione)
 * @param int   $corp       L’ID della tua corporazione
 * @param int   $qta        La quantità di oggetti da acquistare
 * @return bool             True se l’inserimento è andato a buon fine
 */
function buyObject(int $equippgId, int $dest, int $pgcorp, int $corp, int $qta = 1, ?string $requestKey = null): bool
{
    if ($pgcorp===$dest || $qta<1 || $qta>10000 || !EconomiaImpiegato($pgcorp,$corp)) return false;
    return EconomiaBool(static function() use($equippgId,$dest,$pgcorp,$corp,$qta,$requestKey): bool {
        $db=getDB();
        if (!alphano_operation_once('purchase-create',$pgcorp,$requestKey,['instance'=>$equippgId,'seller'=>$dest,'corp'=>$corp,'qta'=>$qta])) return true;
        $row=$db->query('SELECT e.uid,p.shortDesc FROM equippg e JOIN equip p ON p.id=e.uid WHERE e.id=? AND e.idpg=? FOR UPDATE',[$equippgId,$dest])[0] ?? null;
        EconomiaVerifica((bool)$row,'Oggetto assente');
        EconomiaVerifica(!$db->query('SELECT 1 FROM trasferimenti t JOIN equippg e ON e.id=t.obj WHERE t.pgcorp=? AND t.pg=? AND e.uid=? AND t.verso=1 AND t.stato<5',[$pgcorp,$dest,$row['uid']]),'Offerta già presente');
        $count=(int)$db->query('SELECT COUNT(*) AS n FROM equippg WHERE uid=? AND idpg=?',[$row['uid'],$dest])[0]['n'];
        EconomiaVerifica($count >= $qta,'Quantità non disponibile');
        $db->execute('INSERT INTO trasferimenti(data,corp,pgcorp,pg,obj,nomeobj,verso,stato,qta,prezzo) VALUES(?,?,?,?,?,?,1,2,?,0)',[time(),$corp,$pgcorp,$dest,$equippgId,$row['shortDesc'],$qta]);
        $trade=['id'=>(int)$db->lastInsertId(),'corp'=>$corp,'pg'=>$dest,'verso'=>1,'nomeobj'=>$row['shortDesc'],'qta'=>$qta,'prezzo'=>0];
        CommercioAttivita('purchase-created',$pgcorp,$trade,'Richiesta di acquisto',"La corporazione richiede {$qta}× {$row['shortDesc']}. Il venditore deve proporre un prezzo totale.",[],['stato'=>2,'qta'=>$qta,'prezzo'=>0]);
        return true;
    });
}



/**
 * Elenco offerte in corso per un PG.
 */
function GetTransazioniPG(int $idpg): array
{
    return getDB()->query(
        'SELECT  t.*,
                 u.nome AS nomecorp,         -- nome dell’impiegato (se c’è)
                 c.nome AS interlocutore     -- nome della corporazione
           FROM  trasferimenti   AS t
           JOIN  corporazioni    AS c  ON c.id = t.corp
           LEFT JOIN utenti      AS u  ON u.id = t.pgcorp
          WHERE  t.pg    = ?
            AND  t.stato < 5
            AND  t.verso = 1
          ORDER BY t.obj',
        [$idpg]
    );
}



/**
 * Cronologia offerte concluse per un PG.
 */
function GetCronologiaPG(int $idpg): array
{
    return getDB()->query(
        'SELECT t.*, c.nome AS nomecorp, u.nome AS interlocutore
           FROM trasferimenti t
           JOIN corporazioni c ON c.id = t.corp
           LEFT JOIN utenti u ON u.id = t.pgcorp
          WHERE t.pg = ?
            AND t.stato >= 5
            AND t.nascostopg = 0
          ORDER BY t.data ASC',
        [$idpg]
    );
}

/**
 * Aggiorna lo stato di un trasferimento offerta.
 */
// libs/libeconomia.inc.php

/**
 * Aggiorna un trasferimento (offerta) includendo prezzo, quantità e stato.
 */
function UpdateTransf(int $id, int $pg, float $prezzo, int $stato, ?int $qta = null): bool
{
    // Per gli acquisti di istanze il prezzo è il totale dell'intera offerta.
    if (!CommercioPrezzoValido($prezzo) || ($qta!==null && ($qta<1 || $qta>10000))) return false;
    return EconomiaBool(static function() use($id,$pg,$prezzo,$stato,$qta): bool {
        $db=getDB();
        $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND verso=1 AND stato<5 AND (pg=? OR pgcorp=?) FOR UPDATE',[$id,$pg,$pg])[0] ?? null;
        EconomiaVerifica($d && EconomiaImpiegato((int)$d['pgcorp'],(int)$d['corp']),'Trattativa non modificabile');
        $owner=$pg===(int)$d['pg'];
        EconomiaVerifica($owner ? in_array((int)$d['stato'],[2,4],true) && $stato===3 : (int)$d['stato']===3 && $stato===4,'Attendi la risposta della controparte');
        $quantity=$qta ?? (int)$d['qta'];
        $item=$db->query('SELECT uid FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[$d['obj'],$d['pg']])[0] ?? null;
        EconomiaVerifica((bool)$item,'Oggetto non disponibile');
        $available=$db->query('SELECT id FROM equippg WHERE uid=? AND idpg=? FOR UPDATE',[$item['uid'],$d['pg']]);
        EconomiaVerifica(count($available)>=$quantity,'Quantità non disponibile');
        $db->execute('UPDATE trasferimenti SET prezzo=?,qta=?,stato=? WHERE id=?',[(int)$prezzo,$quantity,$stato,$id]);
        CommercioAttivita('purchase-proposal',$pg,$d,'Nuova proposta di acquisto',"{$d['nomeobj']}: {$quantity} pezzi per ".(int)$prezzo.alphano_currency_text(' Corone totali.'),['stato'=>(int)$d['stato'],'qta'=>(int)$d['qta'],'prezzo'=>(int)$d['prezzo']],['stato'=>$stato,'qta'=>$quantity,'prezzo'=>(int)$prezzo],['totale'=>(int)$prezzo]);
        return true;
    });
}

/**
 * Offerte in corso per la corporazione.
 */
function GetTransazioniCorp(int $corp, int $idpg): array
{
    return getDB()->query(
        'SELECT t.*, u.nome AS interlocutore
           FROM trasferimenti t
           JOIN utenti u ON u.id = t.pg
          WHERE t.pgcorp = ?
            AND t.corp = ?
            AND t.stato < 5
            AND t.verso = 1',
        [$idpg, $corp]
    );
}

/**
 * Storico acquisti per la corporazione.
 */
function GetStoricoAcqCorp(int $corp): array
{
    return getDB()->query(
        'SELECT t.*, u.nome AS interlocutore
           FROM trasferimenti t
           JOIN utenti u ON u.id = t.pg
          WHERE t.corp = ?
            AND t.stato >= 5
            AND t.verso = 1
            AND t.nascostocorp = 0
          ORDER BY t.data ASC',
        [$corp]
    );
}

/**
 * Storico vendite per la corporazione.
 */
function GetStoricoVenCorp(int $corp): array
{
    return getDB()->query(
        'SELECT t.*, u.nome AS interlocutore
           FROM trasferimenti t
           JOIN utenti u ON u.id = t.pg
          WHERE t.corp = ?
            AND t.stato >= 5
            AND t.verso = 2
            AND t.nascostocorp = 0
          ORDER BY t.data ASC',
        [$corp]
    );
}

/**
 * Elenco ordini in corso (verso=2) per la corporazione.
 */
function GetOrdiniCorp(int $corp): array
{
    return getDB()->query(
        'SELECT t.*, u1.nome AS interlocutore, u2.nome AS nomepgcorp
           FROM trasferimenti t
           JOIN utenti u1 ON u1.id = t.pg
           LEFT JOIN utenti u2 ON u2.id = t.pgcorp
          WHERE t.corp = ?
            AND (t.stato < 5 OR (t.stato=5 AND t.pgcorp=0))
            AND t.verso = 2
          GROUP BY t.id',
        [$corp]
    );
}

/**
 * Elenco ordini PG della corporazione.
 */
function GetOrdiniPGCorp(int $corp, int $idpg): array
{
    return getDB()->query(
        'SELECT t.*, u.nome AS interlocutore
           FROM trasferimenti t
           JOIN utenti u ON u.id = t.pg
          WHERE t.corp = ?
            AND t.verso = 2
            AND t.pgcorp = ?
            AND t.stato <= 5',
        [$corp, $idpg]
    );
}

/**
 * PG prende in carico un ordine (verso=2).
 */
function PrendiInCaricoOrdine(int $id, int $pg): bool
{
    return EconomiaBool(static function() use($id,$pg): bool {
        $db=getDB();
        $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND verso=2 AND stato IN (0,5) AND pgcorp=0 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica($d && EconomiaImpiegato($pg,(int)$d['corp']) && (int)$d['pg']!==$pg,'Ordine non assegnabile');
        $newState=(int)$d['stato']===5 ? 5 : 1;
        $db->execute('UPDATE trasferimenti SET pgcorp=?,stato=? WHERE id=?',[$pg,$newState,$id]);
        CommercioAttivita('order-assigned',$pg,$d,'Ordine preso in carico',"Un responsabile segue l’ordine di {$d['qta']}× {$d['nomeobj']}.".($newState===5 ? ' Prezzo e quantità già concordati: pronto per la consegna.' : ' In attesa della sua proposta di prezzo.'),['stato'=>(int)$d['stato'],'responsabile'=>0],['stato'=>$newState,'responsabile'=>$pg]);
        return true;
    });
}

/**
 * Finalizza un trasferimento (verso = 1), gestendo sia materie prime che manufatti,
 * rispettando la quantità richiesta.
 *
 * @param int $id  ID del trasferimento (trasferimenti.id)
 * @param int $pg  ID dell'impiegato che sta accettando
 * @return string Messaggio di esito (o “Exception: …” in caso di errore)
 */
/**
 * Conclude una compravendita “verso = 1”.
 * - scala il budget della corporazione
 * - accredita il venditore
 * - inserisce materie prime / aggiorna magazzino
 * - elimina esattamente $qtaRich istanze dall’inventario del venditore
 * - riallinea o respinge le altre offerte aperte sullo stesso prototipo
 *
 * Ritorna una stringa d’esito o “Exception: …” in caso di errore.
 */
function FinalizeTransf(int $id, int $pg): string
{
    try {
        return EconomiaAtomica(static function() use($id,$pg): string {
            $db=getDB();
            $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND verso=1 AND stato BETWEEN 1 AND 4 AND (pgcorp=? OR pg=?) FOR UPDATE',[$id,$pg,$pg])[0] ?? null;
            EconomiaVerifica((bool)$d,'Il trasferimento non esiste o non è a tuo carico');
            $corp=(int)$d['corp']; $seller=(int)$d['pg']; $qta=(int)$d['qta']; $price=(int)$d['prezzo'];
            EconomiaVerifica($qta>0 && $qta<=10000 && CommercioPrezzoValido((float)$price) && EconomiaImpiegato((int)$d['pgcorp'],$corp),'Trasferimento non valido');
            EconomiaVerifica($pg===$seller ? (int)$d['stato']===4 : (int)$d['stato']===3,'Puoi accettare soltanto una proposta della controparte');
            $item=$db->query('SELECT e.uid,p.* FROM equippg e JOIN equip p ON p.id=e.uid WHERE e.id=? AND e.idpg=? FOR UPDATE',[(int)$d['obj'],$seller])[0] ?? null;
            EconomiaVerifica((bool)$item,'Istanza oggetto non trovata');
            $uid=(int)$item['uid'];
            $stock=$db->query('SELECT id FROM equippg WHERE uid=? AND idpg=? ORDER BY id FOR UPDATE',[$uid,$seller]);
            EconomiaVerifica(count($stock)>=$qta,'Il venditore non possiede abbastanza pezzi');
            $budget=$db->query('SELECT budget FROM corporazioni WHERE id=? FOR UPDATE',[$corp])[0] ?? null;
            EconomiaVerifica($budget && (int)$budget['budget']>=$price,'Fondi insufficienti per la corporazione');
            $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$seller]);
            $db->execute('UPDATE corporazioni SET budget=budget-? WHERE id=?',[$price,$corp]);
            $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$price,$seller]);
            $materia=(string)($item['materiaprima'] ?? '');
            if ($materia!=='') EconomiaVerifica(InserisciMateria($materia,$corp,$qta),'Materia prima non valida');
            else foreach (GetMateriePrime($item) ?: [] as $mat=>$units) {
                EconomiaVerifica((int)$units>=0,'Materie prime non valide');
                if ((int)$units>0) EconomiaVerifica(InserisciMateria($mat,$corp,(int)$units*$qta),'Magazzino non aggiornabile');
            }
            $others=$db->query('SELECT t.* FROM trasferimenti t JOIN equippg e ON e.id=t.obj WHERE t.verso=1 AND t.stato<5 AND t.pg=? AND e.uid=? AND t.id<>? FOR UPDATE',[$seller,$uid,$id]);
            $deleted=array_slice(array_column($stock,'id'),0,$qta);
            $ph=implode(',',array_fill(0,count($deleted),'?'));
            $db->execute("UPDATE equippg SET idpadre=0,slot='' WHERE idpg=? AND idpadre IN ($ph)",array_merge([$seller],$deleted));
            $db->execute("DELETE FROM equippg WHERE idpg=? AND id IN ($ph)",array_merge([$seller],$deleted));
            $db->execute('UPDATE trasferimenti SET stato=5 WHERE id=?',[$id]);
            CommercioAttivita('purchase-completed',$pg,$d,'Acquisto completato',alphano_currency_text("La corporazione ha acquistato {$qta}× {$d['nomeobj']} per {$price} Corone totali. Materiali recuperati in magazzino."),['stato'=>(int)$d['stato']],['stato'=>5],['totale'=>$price,'venditore'=>(int)$d['pg'],'materie_recuperate'=>$materia!=='' ? [$materia=>$qta] : array_map(static fn($n)=>(int)$n*$qta,GetMateriePrime($item) ?: [])]);
            $remaining=array_slice(array_column($stock,'id'),$qta); $left=count($remaining);
            foreach ($others as $other) {
                if (!$left) $db->execute('UPDATE trasferimenti SET stato=6 WHERE id=?',[$other['id']]);
                else $db->execute('UPDATE trasferimenti SET obj=?,qta=? WHERE id=?',[in_array($other['obj'],$remaining) ? $other['obj'] : $remaining[0],min((int)$other['qta'],$left),$other['id']]);
                CommercioAttivita('purchase-availability-changed',$pg,$other,'Disponibilità aggiornata','La disponibilità degli oggetti è cambiata: controlla la quantità e lo stato aggiornati della trattativa.',['stato'=>(int)$other['stato'],'qta'=>(int)$other['qta']],['stato'=>$left?(int)$other['stato']:6,'qta'=>$left?min((int)$other['qta'],$left):(int)$other['qta']]);
            }
            return 'Trasferimento avvenuto correttamente';
        });
    } catch (DomainException $e) { return 'Exception: '.$e->getMessage(); }
    catch (Throwable $e) { error_log('[FinalizeTransf] '.$e->getMessage()); return 'Exception: Impossibile completare il trasferimento'; }
}





/**
 * Rifiuta un trasferimento (verso=1).
 */
function RejectTransf(int $id, int $pg): bool
{
    return EconomiaBool(static function() use($id,$pg): bool {
        $db=getDB();
        $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND (pg=? OR pgcorp=?) AND verso=1 AND stato<5 FOR UPDATE',[$id,$pg,$pg])[0] ?? null;
        EconomiaVerifica((bool)$d,'Trasferimento non annullabile');
        $db->execute('UPDATE trasferimenti SET stato=6 WHERE id=?',[$id]);
        CommercioAttivita('purchase-cancelled',$pg,$d,'Acquisto annullato',"La trattativa per {$d['qta']}× {$d['nomeobj']} è stata annullata. Nessun pagamento.",['stato'=>(int)$d['stato']],['stato'=>6]);
        return true;
    });
}

/**
 * Crea un ordine (verso=2).
 */
function MakeOrdine(int $obj, int $pg, int $corp, int $qta = 1, ?string $requestKey = null, ?int &$orderId = null): bool
{
    if ($qta<1 || $qta>10000) return false;
    return EconomiaBool(static function() use($obj,$pg,$corp,$qta,$requestKey,&$orderId): bool {
        $db=getDB();
        $eventKey=$requestKey!==null && $requestKey!=='' ? 'commerce-order:'.$pg.':'.$requestKey : null;
        if (!alphano_operation_once('order-create',$pg,$requestKey,['obj'=>$obj,'corp'=>$corp,'qta'=>$qta])) {
            $receipt=$db->query('SELECT data_json FROM activity_events WHERE event_key=?',[$eventKey])[0] ?? null;
            $orderId=(int)(json_decode($receipt['data_json'] ?? '{}',true)['order_id'] ?? 0);
            EconomiaVerifica($orderId>0,'Ricevuta ordine non disponibile');
            return true;
        }
        $user=$db->query('SELECT organizzazione FROM utenti WHERE id=? FOR UPDATE',[$pg])[0] ?? null;
        $item=$db->query('SELECT e.shortDesc,e.destinatario,e.destorg FROM equip e JOIN tipo t ON t.id=e.tipo JOIN produzionicorporazioni p ON p.produzione=t.subtipo AND p.corporazione=e.corp WHERE e.id=? AND e.corp=? AND e.approvato=1 AND e.visibile=1',[$obj,$corp])[0] ?? null;
        EconomiaVerifica($user && $item && ((int)$item['destinatario']===0 || (int)$item['destinatario']===$pg) && ((int)$item['destorg']===0 || (int)$item['destorg']===(int)$user['organizzazione']),'Oggetto non ordinabile');
        EconomiaVerifica(!$db->query('SELECT 1 FROM trasferimenti WHERE pg=? AND obj=? AND corp=? AND verso=2 AND stato<5',[$pg,$obj,$corp]),'Ordine già presente');
        $db->execute('INSERT INTO trasferimenti(data,corp,pg,obj,qta,nomeobj,verso,stato) VALUES(?,?,?,?,?,?,2,0)',[time(),$corp,$pg,$obj,$qta,$item['shortDesc']]);
        $orderId=(int)$db->lastInsertId();
        CommercioAttivita('order-created',$pg,['id'=>$orderId,'corp'=>$corp,'pg'=>$pg,'verso'=>2,'qta'=>$qta,'prezzo'=>0,'nomeobj'=>$item['shortDesc']],'Nuovo ordine cliente',"Richiesti {$qta}× {$item['shortDesc']}. La corporazione deve prendere in carico e formulare il prezzo.",[],['stato'=>0,'qta'=>$qta,'prezzo'=>0],[],$eventKey);
        return true;
    });
}

/**
 * Elenco ordini PG (verso=2).
 */
function GetOrdiniPG(int $idpg): array
{
    return getDB()->query(
        'SELECT t.*, c.nome AS nomecorp, u.nome AS interlocutore
           FROM trasferimenti t
           JOIN corporazioni c ON c.id = t.corp
           LEFT JOIN utenti u ON u.id = t.pgcorp
          WHERE t.pg = ?
            AND t.stato < 5
            AND t.verso = 2
          GROUP BY t.id',
        [$idpg]
    );
}

/**
 * Finalizza un ordine (verso=2).
 */
function FinalizeOrdine(int $id, int $pg): string
{
    $ok=EconomiaBool(static function() use($id,$pg): bool {
        $db=getDB();
        $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND (pg=? OR pgcorp=?) AND verso=2 AND stato BETWEEN 1 AND 4 FOR UPDATE',[$id,$pg,$pg])[0] ?? null;
        EconomiaVerifica($d && CommercioPrezzoValido((float)$d['prezzo'],(int)$d['qta']) && EconomiaImpiegato((int)$d['pgcorp'],(int)$d['corp']),"L'ordine selezionato non esiste o non è a tuo carico");
        EconomiaVerifica($pg===(int)$d['pg'] ? (int)$d['stato']===3 : (int)$d['stato']===2,'Puoi accettare soltanto una proposta della controparte');
        $db->execute('UPDATE trasferimenti SET stato=5 WHERE id=?',[$id]);
        CommercioAttivita('order-agreed',$pg,$d,'Ordine concordato',alphano_currency_text("Concordati {$d['qta']}× {$d['nomeobj']} a {$d['prezzo']} Corone ciascuno. Pagamento alla consegna."),['stato'=>(int)$d['stato']],['stato'=>5],['totale'=>(int)$d['qta']*(int)$d['prezzo']]);
        return true;
    });
    return $ok ? 'Ordine accettato con successo' : "L'ordine selezionato non è accettabile o non è a tuo carico";
}

/** Un ordine concordato può essere annullato fino alla consegna: nessuna risorsa è ancora spesa. */
function RejectOrdine(int $id, int $pg): bool
{
    return EconomiaBool(static function() use($id,$pg): bool {
        $db=getDB();
        $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND verso=2 AND stato BETWEEN 0 AND 5 FOR UPDATE',[$id])[0] ?? null;
        EconomiaVerifica((bool)$d,'Ordine non annullabile');
        $owner=$pg===(int)$d['pg'];
        $employee=EconomiaImpiegato($pg,(int)$d['corp']) && ((int)$d['pgcorp']===$pg || (int)$d['pgcorp']===0);
        EconomiaVerifica($owner || $employee,'Ordine non a tuo carico');
        $db->execute('UPDATE trasferimenti SET stato=6 WHERE id=?',[$id]);
        CommercioAttivita('order-cancelled',$pg,$d,'Ordine annullato',"Annullato l’ordine di {$d['qta']}× {$d['nomeobj']} prima della consegna. Nessun addebito né consumo di materie.",['stato'=>(int)$d['stato']],['stato'=>6]);
        return true;
    });
}

/** La corporazione formula il primo prezzo; poi le proposte alternano le parti. */
function UpdateOrdine(int $id, int $pg, float $prezzo, int $qta, int $stato): bool
{
    if (!CommercioPrezzoValido($prezzo,$qta)) return false;
    return EconomiaBool(static function() use($id,$pg,$prezzo,$qta,$stato): bool {
        $db=getDB();
        $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND verso=2 AND stato BETWEEN 1 AND 4 AND (pg=? OR pgcorp=?) FOR UPDATE',[$id,$pg,$pg])[0] ?? null;
        EconomiaVerifica($d && EconomiaImpiegato((int)$d['pgcorp'],(int)$d['corp']),'Ordine non modificabile');
        $owner=$pg===(int)$d['pg'];
        EconomiaVerifica($owner ? (int)$d['stato']===3 && $stato===2 : in_array((int)$d['stato'],[1,2],true) && $stato===3,'Attendi la risposta della controparte');
        $db->execute('UPDATE trasferimenti SET prezzo=?,qta=?,stato=? WHERE id=?',[(int)$prezzo,$qta,$stato,$id]);
        CommercioAttivita('order-proposal',$pg,$d,'Nuova proposta per l’ordine',"{$d['nomeobj']}: {$qta} pezzi a ".(int)$prezzo.alphano_currency_text(' Corone ciascuno.'),['stato'=>(int)$d['stato'],'qta'=>(int)$d['qta'],'prezzo'=>(int)$d['prezzo']],['stato'=>$stato,'qta'=>$qta,'prezzo'=>(int)$prezzo],['totale'=>$qta*(int)$prezzo]);
        return true;
    });
}

/**
 * Magazzino di materie prime di una corporazione.
 */
function GetMagazzino(int $corp): array
{
    global $dbequipar;
    $db   = getDB();
    $rows = $db->query('SELECT materia, qta FROM equipcorp WHERE corp = ?', [$corp]);

    // se $dbequipar non è un array, uso solo le materie presenti
    $keys = MATERIE_PRIME;
    $materie = array_fill_keys($keys, 0);

    foreach ($rows as $r) {
        $materia = (string)$r['materia'];
        if ($keys === [] || in_array($materia, $keys, true)) {
            $materie[$materia] = (int)$r['qta'];
        }
    }

    return $materie;
}


/**
 * Elenco consegne (verso=2, stato=5) per PG/corp.
 */
function GetConsegne(int $corp, int $pg): array
{
    return getDB()->query(
        'SELECT t.*, u.nome AS interlocutore, COALESCE(NULLIF(e.immagine,\'\'),tipo.immagine) AS immagine
           FROM trasferimenti t
           JOIN utenti u ON u.id = t.pg
           LEFT JOIN equip e ON e.id=t.obj
           LEFT JOIN tipo ON tipo.id=e.tipo
          WHERE t.corp = ?
            AND t.pgcorp = ?
            AND t.verso = 2
            AND t.stato = 5',
        [$corp, $pg]
    );
}

/**
 * Verifica se si può consegnare (materie prime sufficienti).
 */
function CanConsegnare(array $req, array $mag): bool
{
    foreach ($req as $mat => $qta) {
        if (($mag[$mat] ?? 0) < $qta) {
            return false;
        }
    }
    return true;
}


/**
 * Consegna merce per un ordine (verso=2), scalando prima
 * le materie prime dal magazzino della corporazione.
 *
 * @param int $id    ID del trasferimento (ordine)
 * @param int $pg    ID del giocatore che consegna
 * @param int $corp  ID della corporazione
 * @return string    Messaggio di esito (successo o errore)
 */
function Consegna(int $id, int $pg, int $corp): string
{
    try {
        return EconomiaAtomica(static function() use($id,$pg,$corp): string {
            $db=getDB();
            $d=$db->query('SELECT * FROM trasferimenti WHERE id=? AND stato=5 AND verso=2 AND corp=? AND pgcorp=? FOR UPDATE',[$id,$corp,$pg])[0] ?? null;
            EconomiaVerifica($d && EconomiaImpiegato($pg,$corp),"L'oggetto non è consegnabile");
            $qty=(int)$d['qta']; $price=(int)$d['prezzo']; $total=$qty*$price;
            EconomiaVerifica(CommercioPrezzoValido((float)$price,$qty),'Quantità o prezzo non validi');
            $item=$db->query('SELECT e.* FROM equip e JOIN tipo t ON t.id=e.tipo JOIN produzionicorporazioni p ON p.produzione=t.subtipo AND p.corporazione=e.corp WHERE e.id=? AND e.corp=? AND e.approvato=1',[$d['obj'],$corp])[0] ?? null;
            EconomiaVerifica((bool)$item,'Oggetto non disponibile');
            $buyer=$db->query('SELECT denari FROM utenti WHERE id=? FOR UPDATE',[$d['pg']])[0] ?? null;
            EconomiaVerifica($buyer && (int)$buyer['denari']>=$total,"L'acquirente non ha fondi sufficienti");
            $info=$db->query('SELECT budget,provvigione,stipendio FROM corporazioni WHERE id=? FOR UPDATE',[$corp])[0] ?? null;
            EconomiaVerifica((bool)$info,'Corporazione assente');
            $commission=0;
            if (!empty($info['provvigione'])) {
                $role=$db->query('SELECT c.provvigione FROM utenti u JOIN carichecorporazioni c ON c.id=u.caricacorporazione AND c.corporazione=u.corporazione WHERE u.id=? AND u.corporazione=?',[$pg,$corp])[0] ?? null;
                EconomiaVerifica($role && (float)$role['provvigione']>=0 && (float)$role['provvigione']<=100,'Provvigioni non impostate correttamente');
                $commission=(int)floor((float)$role['provvigione']*$total/100);
            } else EconomiaVerifica(!empty($info['stipendio']),'Provvigioni non impostate correttamente');
            require_once __DIR__.'/item-engine.inc.php';item_stock_delivery($item,$qty);
            $mag=array_column($db->query('SELECT materia,qta FROM equipcorp WHERE corp=? FOR UPDATE',[$corp]),'qta','materia');
            foreach (GetMateriePrime($item) ?: [] as $mat=>$unit) {
                $need=(int)$unit*$qty;
                EconomiaVerifica($need>=0 && ($mag[$mat] ?? 0)>=$need,'Non disponi delle materie prime');
                if ($need>0) $db->execute('UPDATE equipcorp SET qta=qta-? WHERE corp=? AND materia=?',[$need,$corp,$mat]);
            }
            $db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$total,$d['pg']]);
            if ($commission>0) $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$commission,$pg]);
            $db->execute('UPDATE corporazioni SET budget=budget+? WHERE id=?',[$total-$commission,$corp]);
            for ($i=0;$i<$qty;$i++) $db->execute('INSERT INTO equippg(uid,idpg) VALUES(?,?)',[$d['obj'],$d['pg']]);
            $db->execute('UPDATE trasferimenti SET stato=7 WHERE id=?',[$id]);
            CommercioAttivita('order-delivered',$pg,$d,'Ordine consegnato e pagato',alphano_currency_text("Consegnati {$qty}× {$d['nomeobj']}. Addebitate {$total} Corone al cliente; provvigione {$commission} Corone."),['stato'=>5],['stato'=>7],['totale'=>$total,'provvigione'=>$commission,'entrata_corporazione'=>$total-$commission,'materie_consumate'=>array_map(static fn($n)=>(int)$n*$qty,GetMateriePrime($item) ?: [])]);
            return 'Consegna andata a buon fine';
        });
    } catch (DomainException $e) { return $e->getMessage(); }
    catch (Throwable $e) { error_log('[Consegna] '.$e->getMessage()); return 'Errore nella consegna'; }
}


/** Produzioni e visibilità del catalogo cambiano insieme, oppure restano intatte. */
function ImpostaProduzioniCorp(int $corp, int $actor, array $selection, ?string $requestKey = null): bool
{
    return EconomiaBool(static function() use($corp,$actor,$selection,$requestKey): bool {
        if (!alphano_operation_once('corporation-production',$actor,$requestKey,[$corp,$selection])) return true;
        $db=getDB();
        EconomiaVerifica((bool)$db->query('SELECT id FROM utenti WHERE id=? AND (admin=1 OR (corporazione=? AND admincorporazione=1)) FOR UPDATE',[$actor,$corp]),'Mandato non valido');
        EconomiaVerifica((bool)$db->query('SELECT id FROM corporazioni WHERE id=? FOR UPDATE',[$corp]),'Corporazione non valida');
        $allowed=array_column($db->query('SELECT id FROM subtipi'),'id');$points=0;
        foreach ($selection as $subtype=>$quality) {
            EconomiaVerifica(in_array((int)$subtype,array_map('intval',$allowed),true) && is_int($quality) && isset(QUALITY[$quality]),'Produzione o qualità non valida');
            $points+=(int)QUALITY[$quality]['costo'];
        }
        EconomiaVerifica($points>0 && $points<=maxpointvalue,'Punti produzione non validi');
        $before=$db->query('SELECT produzione,quality FROM produzionicorporazioni WHERE corporazione=? ORDER BY produzione FOR UPDATE',[$corp]);
        $db->query('SELECT id FROM equip WHERE corp=? ORDER BY id FOR UPDATE',[$corp]);
        $db->execute('DELETE FROM produzionicorporazioni WHERE corporazione=?',[$corp]);
        foreach ($selection as $subtype=>$quality) if ($quality>0) $db->execute('INSERT INTO produzionicorporazioni(corporazione,produzione,quality) VALUES(?,?,?)',[$corp,(int)$subtype,$quality]);
        $items=$db->query('SELECT e.id,e.durata,p.quality FROM equip e JOIN tipo t ON t.id=e.tipo JOIN produzionicorporazioni p ON p.produzione=t.subtipo AND p.corporazione=e.corp WHERE e.corp=? AND e.visibile=1',[$corp]);
        foreach ($items as $item) {
            $limit=(int)QUALITY[(int)$item['quality']]['durata'];
            if ((int)$item['durata']>$limit) $db->execute('UPDATE equip SET durata=? WHERE id=?',[$limit,$item['id']]);
        }
        $hidden=$db->execute('UPDATE equip e JOIN tipo t ON t.id=e.tipo LEFT JOIN produzionicorporazioni p ON p.produzione=t.subtipo AND p.corporazione=e.corp SET e.visibile=0 WHERE e.corp=? AND e.visibile=1 AND p.produzione IS NULL',[$corp]);
        $after=$db->query('SELECT produzione,quality FROM produzionicorporazioni WHERE corporazione=? ORDER BY produzione',[$corp]);
        if ($before!==$after || $hidden>0) alphano_record_activity('corporation-production-updated',$actor,[['scope'=>'corporazioni','id'=>$corp,'section'=>'production','role'=>'admin']],
            'Produzioni corporative aggiornate',"Assegnati {$points} punti produzione. Oggetti nascosti perché non più producibili: {$hidden}.",['corporazione'=>$corp,'prima'=>$before,'dopo'=>$after,'punti'=>$points,'oggetti_nascosti'=>$hidden]);
        return true;
    });
}

/**
 * Calcola il totale stipendi dovuti per la corporazione.
 */
function GetStipendi(int $corp): int
{
    $rows = getDB()->query(
        'SELECT SUM(c.stipendio) AS tot
           FROM carichecorporazioni c
           JOIN utenti u ON u.corporazione = c.corporazione
                          AND u.caricacorporazione = c.id
          WHERE c.corporazione = ?',
        [$corp]
    );
    return (int)($rows[0]['tot'] ?? 0);
}

/**
 * Eroga stipendi e invia notifiche.
 */
function Consegnastipendi(int $corp, ?string $requestKey = null): bool
{
    require_once __DIR__.'/treasury.inc.php';
    if(alphano_treasury_ready())return EconomiaBool(static fn():bool=>alphano_treasury_payroll('corporazioni',$corp,(int)($_SESSION['user_id']??0),$requestKey?:bin2hex(random_bytes(16)))['status']==='applied');
    $actor=(int)($_SESSION['user_id'] ?? 0);
    return EconomiaBool(static function() use($corp,$requestKey,$actor): bool {
        if (!alphano_operation_once('corporation-payroll',$actor,$requestKey,[$corp])) return true;
        $db=getDB();
        EconomiaVerifica((bool)$db->query('SELECT id FROM utenti WHERE id=? AND (admin=1 OR (corporazione=? AND admincorporazione=1)) FOR UPDATE',[$actor,$corp]),'Mandato non valido');
        $organization=$db->query('SELECT budget,stipendio,nome FROM corporazioni WHERE id=? FOR UPDATE',[$corp])[0] ?? null;
        $members=$db->query('SELECT u.id,u.denari,c.stipendio FROM utenti u JOIN carichecorporazioni c ON c.id=u.caricacorporazione AND c.corporazione=u.corporazione WHERE u.corporazione=? ORDER BY u.id FOR UPDATE',[$corp]);
        $total=0;$paid=0;
        foreach ($members as $m) {
            $amount=(int)$m['stipendio'];
            EconomiaVerifica($amount>=0 && $amount<=PHP_INT_MAX-$total && (int)$m['denari']<=PHP_INT_MAX-$amount,'Stipendio o saldo non valido');
            $total+=$amount;if($amount>0)$paid++;
        }
        EconomiaVerifica($organization && !empty($organization['stipendio']) && $total>0 && (int)$organization['budget']>=$total,'Fondi insufficienti o stipendi non impostati');
        $db->execute('UPDATE corporazioni SET budget=budget-?,ultimostipendio=? WHERE id=?',[$total,time(),$corp]);
        foreach ($members as $m) {
            $amount=(int)$m['stipendio'];if($amount<1)continue;
            $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$amount,$m['id']]);
            InviaMissiva('Stipendio corporativo ricevuto',alphano_currency_text("Hai ricevuto {$amount} Corone di stipendio da {$organization['nome']}."),(int)$m['id']);
            alphano_record_activity('corporation-salary-received',$actor,[['scope'=>'personale','id'=>(int)$m['id'],'section'=>'trasferimenti','role'=>'member']],
                'Stipendio corporativo ricevuto',alphano_currency_text("{$organization['nome']} ha accreditato {$amount} Corone di stipendio."),['corporazione'=>$corp,'importo'=>$amount,'denari_prima'=>(int)$m['denari'],'denari_dopo'=>(int)$m['denari']+$amount]);
        }
        alphano_record_activity('corporation-payroll',$actor,[['scope'=>'corporazioni','id'=>$corp,'section'=>'finanze','role'=>'admin']],
            'Stipendi corporativi consegnati',alphano_currency_text("{$organization['nome']}: erogate {$total} Corone a {$paid} personaggi."),['corporazione'=>$corp,'totale'=>$total,'destinatari'=>$paid,'budget_prima'=>(int)$organization['budget'],'budget_dopo'=>(int)$organization['budget']-$total]);
        return true;
    });
}

/**
 * Pulisce trasferimenti orfani o obsoleti per una corporazione.
 */
function ClearTrasferimenti(int $corp): bool
{
    if ($corp<=0) return true;
    return EconomiaBool(static function() use($corp): bool {
        $db=getDB();
        $rows=$db->query('SELECT t.* FROM trasferimenti t WHERE t.corp=? AND t.stato BETWEEN 0 AND 5 ORDER BY t.id FOR UPDATE',[$corp]);
        foreach ($rows as $trade) {
            $customer=(bool)$db->query('SELECT id FROM utenti WHERE id=?',[$trade['pg']]);
            $assigned=(int)$trade['pgcorp'];
            $validEmployee=$assigned>0 && EconomiaImpiegato($assigned,$corp);
            if ((int)$trade['verso']===2) {
                if (!$customer) {
                    $db->execute('UPDATE trasferimenti SET stato=6 WHERE id=?',[$trade['id']]);
                    CommercioAttivita('order-cancelled',0,$trade,'Ordine non più disponibile','Il cliente non è più disponibile. Nessun addebito.',['stato'=>(int)$trade['stato']],['stato'=>6]);
                } elseif ($assigned>0 && !$validEmployee) {
                    // Preserve an agreement already accepted by the customer.
                    $state=(int)$trade['stato']===5 ? 5 : 0;
                    $price=$state===5 ? (int)$trade['prezzo'] : 0;
                    $db->execute('UPDATE trasferimenti SET pgcorp=0,stato=?,prezzo=? WHERE id=?',[$state,$price,$trade['id']]);
                    CommercioAttivita('order-unassigned',0,$trade,'Ordine da riassegnare','Il precedente responsabile non ha più il mandato. Un nuovo responsabile deve prendere in carico l’ordine.'.($state===5 ? ' Il prezzo concordato è conservato.' : ''),['stato'=>(int)$trade['stato'],'responsabile'=>$assigned,'prezzo'=>(int)$trade['prezzo']],['stato'=>$state,'responsabile'=>0,'prezzo'=>$price]);
                }
            } elseif ((int)$trade['stato']<5) {
                $item=(bool)$db->query('SELECT id FROM equippg WHERE id=? AND idpg=?',[$trade['obj'],$trade['pg']]);
                if (!$customer || !$validEmployee || !$item) {
                    $db->execute('UPDATE trasferimenti SET stato=6 WHERE id=?',[$trade['id']]);
                    CommercioAttivita('purchase-cancelled',0,$trade,'Trattativa non più disponibile','L’oggetto o il mandato del responsabile non è più disponibile. Nessun pagamento.',['stato'=>(int)$trade['stato']],['stato'=>6]);
                }
            }
        }
        return true;
    });
}


/**
 * Eroga stipendi per una città intera.
 */
function ConsegnaStipendiCity(int $city, ?string $requestKey = null): string
{
    require_once __DIR__.'/treasury.inc.php';
    if(alphano_treasury_ready()){
        $ok=EconomiaBool(static fn():bool=>alphano_treasury_payroll('citta',$city,(int)($_SESSION['user_id']??0),$requestKey?:bin2hex(random_bytes(16)))['status']==='applied');
        return $ok?'Stipendi consegnati correttamente':'Fondi insufficienti o mandato non valido';
    }
    $actor=(int)($_SESSION['user_id'] ?? 0);
    $ok=EconomiaBool(static function() use($city,$requestKey,$actor): bool {
        if (!alphano_operation_once('city-payroll',$actor,$requestKey,[$city])) return true;
        $db=getDB();
        EconomiaVerifica((bool)$db->query('SELECT u.id FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE u.id=? AND a.city=? AND u.sindaco=1 FOR UPDATE',[$actor,$city]),'Mandato non valido');
        $row=$db->query('SELECT budget,city FROM city WHERE id=? FOR UPDATE',[$city])[0] ?? null;
        $members=$db->query('SELECT u.id,u.denari,a.stipendio FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE a.city=? ORDER BY u.id FOR UPDATE',[$city]);
        $total=0;$paid=0;
        foreach ($members as $m) {
            $amount=(int)$m['stipendio'];
            EconomiaVerifica($amount>=0 && $amount<=PHP_INT_MAX-$total && (int)$m['denari']<=PHP_INT_MAX-$amount,'Stipendio o saldo non valido');
            $total+=$amount;if($amount>0)$paid++;
        }
        EconomiaVerifica($row && $total>0 && (int)$row['budget']>=$total,'Fondi insufficienti o stipendi non impostati');
        $db->execute('UPDATE city SET budget=budget-?,ultimostipendio=? WHERE id=?',[$total,time(),$city]);
        foreach ($members as $m) {
            $amount=(int)$m['stipendio'];if($amount<1)continue;
            $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$amount,$m['id']]);
            InviaMissiva('Stipendio cittadino ricevuto',alphano_currency_text("Hai ricevuto {$amount} Corone di stipendio da {$row['city']}."),(int)$m['id']);
            alphano_record_activity('city-salary-received',$actor,[['scope'=>'personale','id'=>(int)$m['id'],'section'=>'trasferimenti','role'=>'member']],
                'Stipendio cittadino ricevuto',alphano_currency_text("{$row['city']} ha accreditato {$amount} Corone di stipendio."),['city'=>$city,'importo'=>$amount,'denari_prima'=>(int)$m['denari'],'denari_dopo'=>(int)$m['denari']+$amount]);
        }
        alphano_record_activity('city_consegnastipendi',$actor,[['scope'=>'citta','id'=>$city,'section'=>'budget-stipendi','role'=>'admin']],
            'Stipendi cittadini consegnati',alphano_currency_text("{$row['city']}: erogate {$total} Corone a {$paid} cittadini."),['city'=>$city,'totale'=>$total,'destinatari'=>$paid,'budget_prima'=>(int)$row['budget'],'budget_dopo'=>(int)$row['budget']-$total]);
        return true;
    });
    return $ok ? 'Stipendi consegnati correttamente' : 'Fondi insufficienti o stipendi non validi';
}

/**
 * Crea un prestito PG da parte di un'organizzazione.
 */
function CreaPrestitoPG(int $org, int $pg, int $denari, ?string $requestKey = null): bool
{
    if ($denari<=0 || $denari>2147483647) return false;
    $actor=(int)($_SESSION['user_id'] ?? 0);
    return EconomiaBool(static function() use($org,$pg,$denari,$requestKey,$actor): bool {
        if (!alphano_operation_once('loan-pg',$actor,$requestKey,[$org,$pg,$denari])) return true;
        $db=getDB();
        $budget=$db->query('SELECT budget,nome FROM organizzazioni WHERE id=? FOR UPDATE',[$org])[0] ?? null;
        EconomiaVerifica((bool)$db->query('SELECT id FROM utenti WHERE id=? AND organizzazione=? AND adminorganizzazione=1 AND bloccato=0 FOR UPDATE',[$actor,$org]),'Mandato non valido');
        $user=$db->query('SELECT id,nome,denari FROM utenti WHERE id=? AND organizzazione=? FOR UPDATE',[$pg,$org])[0] ?? null;
        EconomiaVerifica($budget && $user && (int)$budget['budget']>=$denari && (int)$user['denari']<=PHP_INT_MAX-$denari,'Fondi insufficienti o membro assente');
        $db->execute('INSERT INTO prestitipg(data,org,pg,qta) VALUES(?,?,?,?)',[time(),$org,$pg,$denari]);
        $loanId=(int)$db->lastInsertId();
        $db->execute('UPDATE organizzazioni SET budget=budget-? WHERE id=?',[$denari,$org]);
        $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$denari,$pg]);
        $detail=alphano_currency_text("Hai ricevuto {$denari} Corone di prestito da {$budget['nome']}. Puoi rimborsarle dalla sezione Trasferimenti, donando alla stessa organizzazione.");
        InviaMissiva('Prestito ricevuto',$detail,$pg);
        alphano_record_activity('loan-pg-created',$actor,[
            ['scope'=>'organizzazioni','id'=>$org,'section'=>'prestiti','role'=>'admin'],
            ['scope'=>'personale','id'=>$pg,'section'=>'trasferimenti','role'=>'member'],
        ],'Prestito a personaggio erogato',alphano_currency_text("{$budget['nome']} ha concesso un prestito di {$denari} Corone a {$user['nome']}. Il rimborso avviene tramite un versamento alla stessa organizzazione dalla sezione Trasferimenti."),['pg_loan_id'=>$loanId,'destinatario'=>$pg,'importo'=>$denari,'org_budget_before'=>(int)$budget['budget'],'org_budget_after'=>(int)$budget['budget']-$denari,'pg_denari_before'=>(int)$user['denari'],'pg_denari_after'=>(int)$user['denari']+$denari]);
        return true;
    });
}

/** Le donazioni rimborsano prima i prestiti più vecchi, poi accrescono il budget. */
function CreaDonazione(int $idpg, string $nomePg, int $org, int $soldi, string $motivo = '', ?string $requestKey = null): bool
{
    if ($soldi<=0) return false;
    return EconomiaBool(static function() use($idpg,$org,$soldi,$motivo,$requestKey): bool {
        if (!alphano_operation_once('donation',$idpg,$requestKey,[$org,$soldi,$motivo])) return true;
        $db=getDB();
        $organization=$db->query('SELECT budget,nome FROM organizzazioni WHERE id=? FOR UPDATE',[$org])[0] ?? null;
        $user=$db->query('SELECT denari,nome FROM utenti WHERE id=? FOR UPDATE',[$idpg])[0] ?? null;
        EconomiaVerifica($organization && $user && (int)$user['denari']>=$soldi && (int)$organization['budget']<=PHP_INT_MAX-$soldi,'Fondi insufficienti o organizzazione assente');
        $remaining=$soldi;$debito=0;$receipts=[];
        foreach ($db->query('SELECT id,qta FROM prestitipg WHERE org=? AND pg=? ORDER BY data,id FOR UPDATE',[$org,$idpg]) as $loan) {
            $amount=(int)$loan['qta'];$debito+=$amount;
            if ($remaining<=0) continue;
            $receipts[]=['pg_loan_id'=>(int)$loan['id'],'debito_precedente'=>$amount,'rimborso'=>min($amount,$remaining),'debito_residuo'=>max(0,$amount-$remaining)];
            if ($amount<=$remaining) $db->execute('DELETE FROM prestitipg WHERE id=?',[$loan['id']]);
            else $db->execute('UPDATE prestitipg SET qta=qta-? WHERE id=?',[$remaining,$loan['id']]);
            $remaining-=min($remaining,$amount);
        }
        $repaid=min($debito,$soldi);
        $db->execute('UPDATE organizzazioni SET budget=budget+? WHERE id=?',[$soldi,$org]);
        $db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$soldi,$idpg]);
        $db->execute('INSERT INTO logs_adminpg(azione,esecutore,destinatario,qta,data,descr,chat) VALUES(?,?,?,?,?,?,?)',['donazione',$idpg,$org,$soldi,time(),$motivo,'']);
        $detail=alphano_currency_text("{$user['nome']} ha versato {$soldi} Corone a {$organization['nome']}. Rimborso prestiti: {$repaid} Corone. Donazione: {$remaining} Corone. Debito residuo: ").max(0,$debito-$soldi).alphano_currency_text(' Corone.').($motivo!=='' ? " Motivazione: {$motivo}" : '');
        foreach ($db->query('SELECT id FROM utenti WHERE organizzazione=? AND adminorganizzazione=1',[$org]) as $admin) InviaMissiva($repaid>0?'Rimborso prestito PG':'Donazione ricevuta',$detail,(int)$admin['id']);
        alphano_record_activity('organization-payment',$idpg,[
            ['scope'=>'organizzazioni','id'=>$org,'section'=>$repaid>0?'prestiti':'finanze','role'=>'admin'],
            ['scope'=>'personale','id'=>$idpg,'section'=>'trasferimenti','role'=>'member'],
        ],$repaid>0?'Rimborso prestito a personaggio':'Donazione all’organizzazione',$detail,['importo'=>$soldi,'rimborso'=>$repaid,'donazione'=>$remaining,'debito_residuo'=>max(0,$debito-$soldi),'motivo'=>$motivo,'prestiti'=>$receipts,'org_budget_before'=>(int)$organization['budget'],'org_budget_after'=>(int)$organization['budget']+$soldi,'pg_denari_before'=>(int)$user['denari'],'pg_denari_after'=>(int)$user['denari']-$soldi]);
        return true;
    });
}


/**
 * Aggiunge materie prime al magazzino corporativo.
 */
function InserisciMateria(string $materia, int $corp, int $qta = 1): bool
{
    global $dbequipar;
    // se $dbequipar non è un array, lo consideriamo vuoto
    $haystack = MATERIE_PRIME;

    // se materia vuota o non consentita, esci
    if ($qta<1 || $qta>2147483647 || $materia === '' || !in_array($materia, $haystack, true)) {
        return false;
    }

    $db = getDB();
    // Verifica esistenza record
    $rows = $db->query(
        'SELECT 1 FROM equipcorp WHERE corp = ? AND materia = ?',
        [$corp, $materia]
    );
    if (!empty($rows)) {
        // Update
        return $db->execute(
            'UPDATE equipcorp
                SET qta = qta + ?
              WHERE corp = ? AND materia = ?',
            [$qta, $corp, $materia]
        ) > 0;
    } else {
        // Insert
        return $db->execute(
            'INSERT INTO equipcorp (corp, materia, qta)
             VALUES (?, ?, ?)',
            [$corp, $materia, $qta]
        ) > 0;
    }
}


/** Ripristina un oggetto proprio usando le materie della propria corporazione. */
function RiparaOggetto(int $instance, int $pg, int $corp): string
{
    try {
        return EconomiaAtomica(static function() use($instance,$pg,$corp): string {
            $db=getDB();
            EconomiaVerifica(EconomiaImpiegato($pg,$corp),'La riparazione richiede un responsabile della tua corporazione');
            $item=$db->query('SELECT e.*,p.consumo,p.id AS instance FROM equippg p JOIN equip e ON e.id=p.uid JOIN tipo t ON t.id=e.tipo JOIN produzionicorporazioni production ON production.produzione=t.subtipo AND production.corporazione=e.corp WHERE p.id=? AND p.idpg=? AND e.corp=? AND e.approvato=1 AND p.consumo>0 FOR UPDATE',[$instance,$pg,$corp])[0] ?? null;
            EconomiaVerifica((bool)$item,'Oggetto non riparabile');
            $ratio=min((int)$item['consumo'],(int)$item['durata'])/max(1,(int)$item['durata']);
            $mag=array_column($db->query('SELECT materia,qta FROM equipcorp WHERE corp=? FOR UPDATE',[$corp]),'qta','materia');
            foreach (GetMateriePrime($item) ?: [] as $mat=>$units) {
                $need=(int)ceil((int)$units*$ratio);
                EconomiaVerifica($need>=0 && ($mag[$mat] ?? 0)>=$need,'Non disponi delle materie prime');
                if ($need>0) $db->execute('UPDATE equipcorp SET qta=qta-? WHERE corp=? AND materia=?',[$need,$corp,$mat]);
            }
            $db->execute('UPDATE equippg SET consumo=0 WHERE id=? AND idpg=?',[$instance,$pg]);
            $db->execute("INSERT INTO logs_equip(data,esecutore,origine,obj,azione) VALUES(?,?,?,?,'RIPARA')",[time(),$pg,$pg,$item['id']]);
            alphano_record_activity('equipment-repaired',$pg,[['scope'=>'corporazioni','id'=>$corp,'section'=>'catalogo','role'=>'trade'],['scope'=>'personale','id'=>$pg,'section'=>'trasferimenti','role'=>'member']],'Oggetto riparato',"Riparato {$item['shortDesc']}: consumo {$item['consumo']} → 0. Materie prelevate dal magazzino.",['instance_id'=>$instance,'oggetto'=>$item['shortDesc'],'prima'=>['consumo'=>(int)$item['consumo']],'dopo'=>['consumo'=>0],'materie_consumate'=>array_map(static fn($n)=>(int)ceil((int)$n*$ratio),GetMateriePrime($item) ?: [])]);
            return 'Oggetto riparato correttamente';
        });
    } catch (DomainException $e) { return $e->getMessage(); }
    catch (Throwable $e) { error_log('[riparazione] '.$e->getMessage()); return 'Errore nella riparazione'; }
}
