<?php
declare(strict_types=1);
require_once __DIR__.'/libeconomia.inc.php';

/** Scope identifiers are shared by permissions, balances, roles and notifications. */
function alphano_treasury_scope(string $scope):array {
    return match($scope){
        'citta'=>['table'=>'city','name'=>'city','roles'=>'araldiche','role_scope'=>'city','user_scope'=>'araldica','flag'=>'sindaco','rank'=>'araldica','label'=>'città'],
        'organizzazioni'=>['table'=>'organizzazioni','name'=>'nome','roles'=>'caricheorganizzazioni','role_scope'=>'organizzazione','user_scope'=>'organizzazione','flag'=>'adminorganizzazione','rank'=>'caricaorganizzazione','label'=>'organizzazione'],
        'corporazioni'=>['table'=>'corporazioni','name'=>'nome','roles'=>'carichecorporazioni','role_scope'=>'corporazione','user_scope'=>'corporazione','flag'=>'admincorporazione','rank'=>'caricacorporazione','label'=>'corporazione'],
        default=>throw new DomainException('Ambito non valido.',422)
    };
}
function alphano_treasury_ready():bool {
    static $ready;return $ready??=(bool)ConnectDB()->query("SHOW TABLES LIKE 'treasury_policies'");
}
/** A nested failed payment must not leave writes for its caller to commit. */
function alphano_treasury_atomic(callable $operation):mixed {
    return EconomiaAtomicaResiliente($operation);
}
function alphano_treasury_int(mixed $value,int $min=0,int $max=2147483647):int {
    if(!is_int($value)&&!is_string($value))throw new DomainException('Importo intero non valido.',422);
    $n=EconomiaIntero($value,$min,$max);if($n===null)throw new DomainException('Importo o intervallo non valido.',422);return $n;
}
function alphano_treasury_manager(string $scope,int $id,int $actor,bool $lock=false):bool {
    if($actor<1||$id<1)return false;$s=alphano_treasury_scope($scope);$db=ConnectDB();
    $u=$db->query('SELECT * FROM utenti WHERE id=?'.($lock?' FOR UPDATE':''),[$actor])[0]??[];
    if(!$u||!empty($u['bloccato']))return false;if(!empty($u['admin']))return true;
    if(empty($u[$s['flag']]))return false;
    return $scope==='citta'?(bool)$db->query('SELECT id FROM araldiche WHERE id=? AND city=?',[(int)$u['araldica'],$id]):(int)$u[$s['user_scope']]===$id;
}
function alphano_treasury_entity(string $scope,int $id,bool $lock=false):array {
    $s=alphano_treasury_scope($scope);if($id<1)throw new DomainException('Ente non valido.',422);
    $v=ConnectDB()->query('SELECT id,'.$s['name'].' AS name,budget FROM '.$s['table'].' WHERE id=?'.($lock?' FOR UPDATE':''),[$id])[0]??null;
    if(!$v)throw new DomainException('Ente non disponibile.',404);return $v;
}
function alphano_treasury_url(string $scope,int $id):string{return '/governo/?'.http_build_query(['scope'=>$scope,'id'=>$id]);}
function alphano_treasury_policy(string $scope,int $id,bool $lock=false):array {
    return ConnectDB()->query('SELECT * FROM treasury_policies WHERE scope=? AND scope_id=?'.($lock?' FOR UPDATE':''),[$scope,$id])[0]??['enabled'=>0,'interval_days'=>7,'next_run'=>0,'retry_after'=>0,'revision'=>0];
}
function alphano_treasury_members(string $scope,int $id,bool $lock=false):array {
    $s=alphano_treasury_scope($scope);
    return ConnectDB()->query('SELECT u.id,u.nome,u.denari,r.stipendio FROM utenti u JOIN '.$s['roles'].' r ON r.id=u.'.$s['rank'].' WHERE r.'.$s['role_scope'].'=?'.($scope==='citta'?'':' AND u.'.$s['user_scope'].'=r.'.$s['role_scope']).' ORDER BY u.id'.($lock?' FOR UPDATE':''),[$id]);
}
function alphano_treasury_view(string $scope,int $id,int $viewer):array {
    if(!alphano_treasury_ready())throw new DomainException('Tesoreria in preparazione.',503);
    $s=alphano_treasury_scope($scope);$entity=alphano_treasury_entity($scope,$id);$manager=alphano_treasury_manager($scope,$id,$viewer);$db=ConnectDB();
    $roles=$db->query('SELECT id,nome,stipendio FROM '.$s['roles'].' WHERE '.$s['role_scope'].'=? ORDER BY livello,nome',[$id]);
    $members=alphano_treasury_members($scope,$id);$total=array_sum(array_map('intval',array_column($members,'stipendio')));
    $history=$db->query('SELECT id,kind,actor_id,amount,status,detail,created_at FROM treasury_runs WHERE scope=? AND scope_id=? ORDER BY id DESC LIMIT 50',[$scope,$id]);
    return ['scope'=>$scope,'id'=>$id,'entity'=>$entity,'can_manage'=>$manager,'policy'=>alphano_treasury_policy($scope,$id),'roles'=>$roles,'members'=>count($members),'payroll_total'=>$total,'history'=>$history,'donation'=>$db->query('SELECT * FROM treasury_donations WHERE user_id=? AND scope=? AND scope_id=?',[$viewer,$scope,$id])[0]??null,'server_time'=>time()];
}
/** Run reservation lives inside the transaction owning the balance. */
function alphano_treasury_reserve(string $scope,int $id,string $key,string $kind,int $actor,array $payload):?array {
    if(!preg_match('/^[a-zA-Z0-9:_-]{16,100}$/D',$key))throw new DomainException('Identificativo operazione non valido.',422);
    $db=ConnectDB();$hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    $old=$db->query('SELECT * FROM treasury_runs WHERE scope=? AND scope_id=? AND request_key=? FOR UPDATE',[$scope,$id,$key])[0]??null;
    if($old){if(!hash_equals($old['payload_hash'],$hash)||$old['kind']!==$kind||(int)$old['actor_id']!==$actor)throw new DomainException('Ricevuta già usata con dati diversi.',409);return $old;}
    $db->execute("INSERT INTO treasury_runs(scope,scope_id,request_key,payload_hash,kind,actor_id,status,detail,created_at) VALUES(?,?,?,?,?,?,'pending','',?)",[$scope,$id,$key,$hash,$kind,$actor,time()]);return null;
}
function alphano_treasury_finish(string $scope,int $id,string $key,int $amount,string $detail,string $status='applied'):int {
    $db=ConnectDB();$db->execute('UPDATE treasury_runs SET amount=?,status=?,detail=? WHERE scope=? AND scope_id=? AND request_key=?',[$amount,$status,$detail,$scope,$id,$key]);return (int)$db->query('SELECT id FROM treasury_runs WHERE scope=? AND scope_id=? AND request_key=?',[$scope,$id,$key])[0]['id'];
}
function alphano_treasury_notice(string $scope,int $id,int $actor,string $title,string $detail,int $run):void {
    alphano_record_activity('treasury-event',$actor,[['scope'=>$scope,'id'=>$id,'section'=>'tesoreria','role'=>'admin']],$title,$detail,['run_id'=>$run], 'treasury-run:'.$run.':'.substr(hash('sha256',$title),0,12));
}
function alphano_treasury_payroll(string $scope,int $id,int $actor,string $key,?int $scheduledDue=null,?int $now=null):array {
    $now??=time();return alphano_treasury_atomic(static function()use($scope,$id,$actor,$key,$scheduledDue,$now):array{
        $db=ConnectDB();$s=alphano_treasury_scope($scope);$entity=alphano_treasury_entity($scope,$id,true);
        $memberIds=array_map('intval',array_column(alphano_treasury_members($scope,$id),'id'));if($actor>0)$memberIds[]=$actor;$memberIds=array_values(array_unique($memberIds));sort($memberIds,SORT_NUMERIC);if($memberIds)$db->query('SELECT id FROM utenti WHERE id IN('.implode(',',array_fill(0,count($memberIds),'?')).') ORDER BY id FOR UPDATE',$memberIds);
        if($scheduledDue===null&&!alphano_treasury_manager($scope,$id,$actor,true))throw new DomainException('Mandato non valido.',403);
        if($scheduledDue!==null&&$actor!==0)throw new LogicException('Un pagamento automatico appartiene al sistema.');
        $policy=alphano_treasury_policy($scope,$id,true);
        if($scheduledDue!==null&&(!(int)$policy['enabled']||(int)$policy['next_run']!==$scheduledDue||$scheduledDue>$now||(int)$policy['retry_after']>$now))return ['status'=>'not_due'];
        $old=alphano_treasury_reserve($scope,$id,$key,'payroll',$actor,[$scope,$id,$scheduledDue]);
        if($old&&$old['status']==='applied')return ['status'=>'applied','replayed'=>true];
        $members=alphano_treasury_members($scope,$id,true);$total=0;
        foreach($members as $m){$amount=alphano_treasury_int($m['stipendio']);if((int)$m['denari']>2147483647-$amount||$total>2147483647-$amount)throw new DomainException('Saldo o monte stipendi fuori limite.',409);$total+=$amount;}
        if($total<1||(int)$entity['budget']<$total){
            if($scheduledDue===null)throw new DomainException($total<1?'Imposta almeno uno stipendio positivo.':'Budget insufficiente: nessun addebito eseguito.',409);
            $detail=$total<1?'Nessuno stipendio positivo impostato.':'Budget insufficiente: pagamento rinviato senza addebiti parziali.';
            $run=alphano_treasury_finish($scope,$id,$key,0,$detail,'waiting');$db->execute('UPDATE treasury_policies SET retry_after=? WHERE scope=? AND scope_id=?',[$now+3600,$scope,$id]);
            if(!$old)alphano_treasury_notice($scope,$id,0,'Stipendi da verificare',$detail,$run);
            return ['status'=>'waiting','message'=>$detail];
        }
        $db->execute('UPDATE '.$s['table'].' SET budget=budget-? WHERE id=?',[$total,$id]);
        if($scope!=='organizzazioni')$db->execute('UPDATE '.$s['table'].' SET ultimostipendio=? WHERE id=?',[$now,$id]);
        foreach($members as $m){$amount=(int)$m['stipendio'];if(!$amount)continue;$db->execute('UPDATE utenti SET denari=denari+? WHERE id=?',[$amount,$m['id']]);
            InviaMissiva('Stipendio ricevuto',alphano_currency_text("{$entity['name']} ti ha accreditato {$amount} Corone."),(int)$m['id']);
            alphano_record_activity('treasury-salary',$actor,[['scope'=>'personale','id'=>(int)$m['id'],'section'=>'trasferimenti','role'=>'member']],'Stipendio ricevuto',alphano_currency_text("{$entity['name']}: {$amount} Corone accreditate."),['scope'=>$scope,'scope_id'=>$id,'amount'=>$amount]);
        }
        $detail=alphano_currency_text("{$entity['name']}: pagate {$total} Corone. Budget precedente {$entity['budget']}, nuovo budget ").((int)$entity['budget']-$total).'.';
        $run=alphano_treasury_finish($scope,$id,$key,$total,$detail);
        // A previous insufficient-funds receipt retains its immutable notice.
        alphano_treasury_notice($scope,$id,$actor,'Stipendi erogati',$detail,$run);
        if($scheduledDue!==null){$seconds=(int)$policy['interval_days']*86400;$next=$scheduledDue+(intdiv(max(0,$now-$scheduledDue),$seconds)+1)*$seconds;$db->execute('UPDATE treasury_policies SET next_run=?,retry_after=0 WHERE scope=? AND scope_id=?',[$next,$scope,$id]);}
        return ['status'=>'applied','amount'=>$total,'run_id'=>$run];
    });
}
function alphano_treasury_donate(string $scope,int $id,int $actor,int $amount,string $key,string $reason='Donazione',?int $now=null):array {
    $amount=alphano_treasury_int($amount,1);$now??=time();return alphano_treasury_atomic(static function()use($scope,$id,$actor,$amount,$key,$reason,$now):array{
        $db=ConnectDB();$s=alphano_treasury_scope($scope);$entity=alphano_treasury_entity($scope,$id,true);
        $u=$db->query('SELECT id,nome,denari,bloccato FROM utenti WHERE id=? FOR UPDATE',[$actor])[0]??[];
        if(!$u||!empty($u['bloccato']))throw new DomainException('Personaggio non disponibile.',403);
        $old=alphano_treasury_reserve($scope,$id,$key,'donation',$actor,[$scope,$id,$amount,$reason]);if($old&&$old['status']==='applied')return ['status'=>'applied','replayed'=>true];
        if((int)$u['denari']<$amount||(int)$entity['budget']>2147483647-$amount)throw new DomainException('Fondi insufficienti o saldo fuori limite. Nessun addebito.',409);
        if($scope==='organizzazioni'){
            // Preserve the established oldest-loan-first settlement rule.
            if(!CreaDonazione($actor,(string)$u['nome'],$id,$amount,$reason))throw new RuntimeException('Versamento non completato: transazione annullata.');
        }else{$db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$amount,$actor]);$db->execute('UPDATE '.$s['table'].' SET budget=budget+? WHERE id=?',[$amount,$id]);}
        $detail=alphano_currency_text("{$u['nome']} ha versato {$amount} Corone a {$entity['name']}. ").mb_substr($reason,0,300);$run=alphano_treasury_finish($scope,$id,$key,$amount,$detail);
        alphano_treasury_notice($scope,$id,$actor,'Donazione ricevuta',$detail,$run);InviaMissiva('Ricevuta donazione',alphano_currency_text("Hai versato {$amount} Corone a {$entity['name']}. ").mb_substr($reason,0,300),$actor);
        return ['status'=>'applied','amount'=>$amount,'run_id'=>$run];
    });
}
function alphano_treasury_save(string $scope,int $id,int $actor,array $input):void {
    alphano_treasury_atomic(static function()use($scope,$id,$actor,$input){
        $db=ConnectDB();$s=alphano_treasury_scope($scope);alphano_treasury_entity($scope,$id,true);
        if(!alphano_treasury_manager($scope,$id,$actor,true))throw new DomainException('Mandato non valido.',403);
        $p=alphano_treasury_policy($scope,$id,true);if(alphano_treasury_int($input['revision']??null)!==(int)$p['revision'])throw new DomainException('Impostazioni cambiate: ricarica prima di salvare.',409);
        $enabled=alphano_treasury_int($input['enabled']??0,0,1);$days=alphano_treasury_int($input['interval_days']??7,1,90);$next=$enabled?((!empty($p['enabled'])&&(int)$p['interval_days']===$days)?(int)$p['next_run']:time()+$days*86400):0;
        $roles=$input['roles']??[];if(!is_array($roles)||count($roles)>200)throw new DomainException('Cariche non valide.',422);
        foreach($roles as $role=>$salary){$rid=alphano_treasury_int($role,1);$money=alphano_treasury_int($salary);if(!$db->query('SELECT id FROM '.$s['roles'].' WHERE id=? AND '.$s['role_scope'].'=? FOR UPDATE',[$rid,$id]))throw new DomainException('Carica non appartenente a questo ente.',403);$db->execute('UPDATE '.$s['roles'].' SET stipendio=? WHERE id=?',[$money,$rid]);}
        $db->execute('INSERT INTO treasury_policies(scope,scope_id,enabled,interval_days,next_run,retry_after,revision,updated_by,updated_at) VALUES(?,?,?,?,?,0,1,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),interval_days=VALUES(interval_days),next_run=VALUES(next_run),retry_after=0,revision=revision+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$scope,$id,$enabled,$days,$next,$actor,time()]);
        alphano_record_activity('treasury-policy',$actor,[['scope'=>$scope,'id'=>$id,'section'=>'tesoreria','role'=>'admin']],'Tesoreria aggiornata',$enabled?"Stipendi automatici ogni {$days} giorni.":'Erogazione manuale degli stipendi.',['roles'=>$roles,'enabled'=>$enabled,'interval_days'=>$days]);
    });
}
function alphano_treasury_donation_mandate(string $scope,int $id,int $actor,array $input):void {
    alphano_treasury_atomic(static function()use($scope,$id,$actor,$input){$db=ConnectDB();alphano_treasury_entity($scope,$id,true);$u=$db->query('SELECT id,bloccato FROM utenti WHERE id=? FOR UPDATE',[$actor])[0]??[];if(!$u||!empty($u['bloccato']))throw new DomainException('Personaggio non disponibile.',403);
        $old=$db->query('SELECT * FROM treasury_donations WHERE user_id=? AND scope=? AND scope_id=? FOR UPDATE',[$actor,$scope,$id])[0]??[];
        if(alphano_treasury_int($input['revision']??0)!==(int)($old['revision']??0))throw new DomainException('Mandato cambiato: ricarica i dati.',409);
        $enabled=alphano_treasury_int($input['enabled']??0,0,1);$amount=alphano_treasury_int($input['amount']??$old['amount']??1,1);$days=alphano_treasury_int($input['interval_days']??$old['interval_days']??7,1,90);
        $next=!empty($old['enabled'])&&(int)$old['amount']===$amount&&(int)$old['interval_days']===$days?(int)$old['next_run']:time()+$days*86400;
        $db->execute('INSERT INTO treasury_donations(user_id,scope,scope_id,amount,interval_days,next_run,enabled,revision,updated_at) VALUES(?,?,?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),interval_days=VALUES(interval_days),next_run=VALUES(next_run),enabled=VALUES(enabled),retry_after=0,revision=revision+1,updated_at=VALUES(updated_at)',[$actor,$scope,$id,$amount,$days,$next,$enabled,time()]);
        InviaMissiva($enabled?'Donazione periodica autorizzata':'Donazione periodica sospesa',$enabled?alphano_currency_text("Hai autorizzato {$amount} Corone ogni {$days} giorni. Puoi sospendere il mandato dalla tesoreria."):'Il tuo mandato di donazione periodica è stato sospeso.',$actor);
    });
}
/** An orphan/error before payment still needs a durable backoff, or it owns the batch forever. */
function alphano_treasury_scheduler_error(string $kind,array $expected,Throwable $error,int $now):array {
    return alphano_treasury_atomic(static function()use($kind,$expected,$error,$now):array{
        $db=ConnectDB();$donation=$kind==='donation';
        $row=$donation?($db->query('SELECT * FROM treasury_donations WHERE id=? FOR UPDATE',[(int)$expected['id']])[0]??null):($db->query('SELECT * FROM treasury_policies WHERE scope=? AND scope_id=? FOR UPDATE',[$expected['scope'],(int)$expected['scope_id']])[0]??null);
        if(!$row||empty($row['enabled'])||(int)$row['revision']!==(int)$expected['revision']||(int)$row['next_run']!==(int)$expected['next_run']||(int)$row['next_run']>$now||(int)$row['retry_after']>$now)return ['status'=>'obsolete'];
        $scope=$row['scope'];$id=(int)$row['scope_id'];$actor=$donation?(int)$row['user_id']:0;
        $key=$donation?'recurring-donation:'.$row['id'].':'.$row['revision'].':'.$row['next_run']:'scheduled-payroll:'.$row['next_run'];
        $payload=$donation?[$scope,$id,(int)$row['amount'],'Donazione periodica autorizzata']:[$scope,$id,(int)$row['next_run']];
        $old=alphano_treasury_reserve($scope,$id,$key,$kind,$actor,$payload);
        if($old&&$old['status']==='applied')return ['status'=>'applied','replayed'=>true];
        $permanent=$error instanceof DomainException&&in_array((int)$error->getCode(),[403,404,422],true);
        $detail=$permanent?'Ente o configurazione non disponibile: automazione sospesa senza addebiti.':'Errore temporaneo prima del pagamento: nessun addebito eseguito; nuovo tentativo fra un’ora.';
        $run=alphano_treasury_finish($scope,$id,$key,0,$detail,'failed');
        $set=$permanent?'enabled=0,retry_after=0,revision=revision+1':'retry_after='.($now+3600);
        $db->execute('UPDATE '.($donation?'treasury_donations':'treasury_policies').' SET '.$set.',updated_at=? WHERE '.($donation?'id=?':'scope=? AND scope_id=?'),$donation?[$now,(int)$row['id']]:[$now,$scope,$id]);
        // A vanished entity has no current managers; central administrators must still see the audit.
        $audiences=[['scope'=>'gestione','id'=>0,'section'=>'tesoreria','role'=>'admin']];
        if($donation&&$db->query('SELECT id FROM utenti WHERE id=?',[$actor]))$audiences[]=['scope'=>'personale','id'=>$actor,'section'=>'trasferimenti','role'=>'member'];
        $data=['run_id'=>$run,'scope'=>$scope,'scope_id'=>$id,'kind'=>$kind,'revision'=>(int)$row['revision'],'scheduled_due'=>(int)$row['next_run'],'suspended'=>$permanent];
        alphano_record_activity('treasury-scheduler-error',0,$audiences,$permanent?'Automazione di tesoreria sospesa':'Automazione di tesoreria rinviata',$detail,$data,'treasury-scheduler:'.hash('sha256',json_encode([$data,$permanent?0:$now],JSON_THROW_ON_ERROR)));
        return ['status'=>$permanent?'suspended':'retry','run_id'=>$run];
    });
}
/** Bounded scheduler: one payment per elapsed cycle, no catch-up storm after downtime. */
function alphano_treasury_tick(?int $now=null):array {
    if(!alphano_treasury_ready())return [];$now??=time();$db=ConnectDB();$report=[];
    foreach($db->query('SELECT scope,scope_id,next_run,revision FROM treasury_policies WHERE enabled=1 AND next_run>0 AND next_run<=? AND retry_after<=? ORDER BY next_run LIMIT 10',[$now,$now]) as $p){try{$report[]=alphano_treasury_payroll($p['scope'],(int)$p['scope_id'],0,'scheduled-payroll:'.(int)$p['next_run'],(int)$p['next_run'],$now);}catch(Throwable $e){error_log('[tesoreria] '.$e->getMessage());try{$report[]=alphano_treasury_scheduler_error('payroll',$p,$e,$now);}catch(Throwable $auditError){error_log('[tesoreria rinvio] '.$auditError->getMessage());}}}
    foreach($db->query('SELECT id,scope,scope_id,user_id,next_run,revision FROM treasury_donations WHERE enabled=1 AND next_run<=? AND retry_after<=? ORDER BY next_run LIMIT 20',[$now,$now]) as $p){try{alphano_treasury_atomic(static function()use($p,$db,$now,&$report){alphano_treasury_entity($p['scope'],(int)$p['scope_id'],true);$db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$p['user_id']]);$d=$db->query('SELECT * FROM treasury_donations WHERE id=? FOR UPDATE',[$p['id']])[0]??[];if(!$d||!$d['enabled']||(int)$d['next_run']>$now||(int)$d['retry_after']>$now)return;
            try{$report[]=alphano_treasury_donate($d['scope'],(int)$d['scope_id'],(int)$d['user_id'],(int)$d['amount'],'recurring-donation:'.$d['id'].':'.$d['revision'].':'.$d['next_run'],'Donazione periodica autorizzata',$now);}catch(Throwable $e){if(!$db->inTransaction())throw $e;$key='recurring-donation:'.$d['id'].':'.$d['revision'].':'.$d['next_run'];$reason='Donazione periodica autorizzata';alphano_treasury_reserve($d['scope'],(int)$d['scope_id'],$key,'donation',(int)$d['user_id'],[$d['scope'],(int)$d['scope_id'],(int)$d['amount'],$reason]);$expected=$e instanceof DomainException;$detail=$expected?$e->getMessage():'Servizio temporaneamente non disponibile: nessun addebito eseguito, nuovo tentativo differito.';alphano_treasury_finish($d['scope'],(int)$d['scope_id'],$key,0,$detail,'failed');if($expected){$db->execute('UPDATE treasury_donations SET enabled=0,revision=revision+1 WHERE id=?',[$d['id']]);InviaMissiva('Donazione periodica sospesa',$detail.' Riattivala dalla tesoreria quando lo desideri.',(int)$d['user_id']);$report[]=['status'=>'suspended'];}else{$db->execute('UPDATE treasury_donations SET retry_after=? WHERE id=?',[$now+3600,$d['id']]);error_log('[donazione periodica non addebitata] '.$e->getMessage());$report[]=['status'=>'retry'];}return;}
            $seconds=(int)$d['interval_days']*86400;$next=(int)$d['next_run']+(intdiv(max(0,$now-(int)$d['next_run']),$seconds)+1)*$seconds;$db->execute('UPDATE treasury_donations SET next_run=?,retry_after=0 WHERE id=?',[$next,$d['id']]);
        });}catch(Throwable $e){error_log('[donazione periodica] '.$e->getMessage());try{$report[]=alphano_treasury_scheduler_error('donation',$p,$e,$now);}catch(Throwable $auditError){error_log('[donazione periodica rinvio] '.$auditError->getMessage());}}}
    return $report;
}
