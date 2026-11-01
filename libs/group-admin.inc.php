<?php
declare(strict_types=1);
require_once __DIR__.'/libeconomia.inc.php';
require_once __DIR__.'/libpg.inc.php';
require_once __DIR__.'/libelezioni.inc.php';

/** Only allowlisted group mutations enter this shared transaction and receipt. */
function alphano_group_admin_action(string $scope, int $group, int $actor, string $action, array $input, array $files = []): ?string
{
    $actions=['admitpg','changecarica','addramo','modifyramo','dropramo','addcarica','modifycarica','dropcarica','setflag','modifypvt','proponi'];
    if (!in_array($scope,['organizzazioni','corporazioni'],true) || !in_array($action,$actions,true)) return null;
    if ($scope==='corporazioni' && $action==='proponi') return null;
    if ($scope==='corporazioni' && $action==='modifycarica' && !isset($input['nome'])) return null;
    if ($action==='modifyramo' && !isset($input['nome'])) return null; // The corporation's edit-form lookup.
    $field=$scope==='organizzazioni'?'organizzazione':'corporazione';
    $prefix=$scope==='organizzazioni'?'organization':'corporation';
    $admin='admin'.$field;$rankField='carica'.$field;$branchField='ramo'.$field;
    $undo=[];
    try {
        return EconomiaAtomica(static function() use($scope,$group,$actor,$action,$input,$files,$field,$prefix,$admin,$rankField,$branchField,&$undo): string {
            $db=getDB();
            EconomiaVerifica((bool)$db->query("SELECT id FROM utenti WHERE id=? AND (({$field}=? AND {$admin}=1) OR admin=1) FOR UPDATE",[$actor,$group]),'Mandato non valido');
            $groupRow=$db->query("SELECT * FROM {$scope} WHERE id=? FOR UPDATE",[$group])[0] ?? null;
            EconomiaVerifica((bool)$groupRow,'Gruppo non valido');
            $payload=$input;$payload['_group']=$group;unset($payload['_csrf'],$payload['csrf_token'],$payload['_request_key']);
            foreach ($files as $key=>$file) if (is_array($file) && is_uploaded_file($file['tmp_name'] ?? '')) $payload['upload_'.$key]=hash_file('sha256',$file['tmp_name']);
            if (!alphano_operation_once($prefix.'-admin-'.$action,$actor,$input['_request_key'] ?? null,$payload)) return 'Operazione già completata';
            $before=[];$after=[];$section='iscritti';$target=0;$personal=false;$changed=false;
            $title='Amministrazione del gruppo';$detail='';
            $remember=static function(int $rank) use($scope,$group,&$undo): string {
                $path=htmlroot.'/uploads/badges/'.$scope.'/'.$group.'/'.$rank.'.gif';
                $undo[$path]=is_file($path)?file_get_contents($path):null;
                return $path;
            };
            if (in_array($action,['admitpg','changecarica'],true)) {
                $target=EconomiaIntero($input['pg'] ?? '',1) ?? 0;
                $user=$db->query("SELECT id,nome,{$field},{$rankField},{$branchField},{$admin} FROM utenti WHERE id=? FOR UPDATE",[$target])[0] ?? null;
                EconomiaVerifica((bool)$user,'Personaggio non valido');
                $before=$user;$personal=true;
                if ($action==='admitpg') {
                    $request=$db->query("SELECT idpg FROM richieste WHERE {$field}=? AND idpg=? FOR UPDATE",[$group,$target]);
                    EconomiaVerifica((bool)$request,'Richiesta non presente');
                    $allow=!empty($input['admit']);
                    if ($allow) {
                        EconomiaVerifica((int)$user[$field]===0,'Il personaggio appartiene già a un gruppo');
                        $rank=$db->query("SELECT id,ramo,nome FROM cariche{$scope} WHERE {$field}=? AND prima=1 ORDER BY id LIMIT 1 FOR UPDATE",[$group])[0] ?? null;
                        EconomiaVerifica((bool)$rank,'Prima carica non impostata');
                        $db->execute("UPDATE utenti SET {$field}=?,{$rankField}=?,{$branchField}=?,{$admin}=0 WHERE id=?",[$group,$rank['id'],$rank['ramo'],$target]);
                    }
                    $db->execute("DELETE FROM richieste WHERE {$field}=? AND idpg=?",[$group,$target]);
                    $title=$allow?'Richiesta di adesione accettata':'Richiesta di adesione rifiutata';
                    $detail=$allow?"{$user['nome']} è stato ammesso in {$groupRow['nome']} con la carica {$rank['nome']}.":"La richiesta di {$user['nome']} di aderire a {$groupRow['nome']} è stata rifiutata.";
                    $changed=true;
                } else {
                    EconomiaVerifica((int)$user[$field]===$group,'Il personaggio non appartiene al gruppo');
                    $rankId=EconomiaIntero($input['carica'] ?? '',0);
                    EconomiaVerifica($rankId!==null,'Carica non valida');
                    if ($rankId>0) {
                        $rank=$db->query("SELECT id,nome,ramo FROM cariche{$scope} WHERE id=? AND {$field}=? FOR UPDATE",[$rankId,$group])[0] ?? null;
                        EconomiaVerifica((bool)$rank,'Carica non appartenente al gruppo');
                        $db->execute("UPDATE utenti SET {$rankField}=?,{$branchField}=? WHERE id=?",[$rankId,$rank['ramo'],$target]);
                        $title='Incarico nel gruppo aggiornato';$detail="{$user['nome']} ora ricopre la carica {$rank['nome']} in {$groupRow['nome']}.";
                    } else {
                        $reset=$scope==='corporazioni'?',admintrasfcorp=0,admincatalogo=0':'';
                        $db->execute("UPDATE utenti SET {$field}=0,{$rankField}=0,{$branchField}=0,{$admin}=0{$reset} WHERE id=?",[$target]);
                        $title='Personaggio espulso dal gruppo';$detail="{$user['nome']} è stato espulso da {$groupRow['nome']}.";
                    }
                }
                $after=$db->query("SELECT id,nome,{$field},{$rankField},{$branchField},{$admin} FROM utenti WHERE id=?",[$target])[0];
                $changed=$changed || $before!==$after;
                if($scope==='corporazioni'&&$action==='changecarica'&&$rankId===0)ClearTrasferimenti($group);
            } elseif (in_array($action,['addramo','modifyramo','dropramo'],true)) {
                $table='rami'.$scope;$id=EconomiaIntero($input['ramo'] ?? 0,0) ?? 0;
                if ($action!=='addramo') {
                    $before=$db->query("SELECT id,nome,livello FROM {$table} WHERE id=? AND {$field}=? FOR UPDATE",[$id,$group])[0] ?? [];
                    EconomiaVerifica((bool)$before,'Ramo non appartenente al gruppo');
                }
                if ($action==='dropramo') {
                    EconomiaVerifica(!$db->query("SELECT id FROM cariche{$scope} WHERE {$field}=? AND ramo=? FOR UPDATE",[$group,$id]),'Il ramo contiene ancora cariche');
                    $db->execute("DELETE FROM {$table} WHERE id=? AND {$field}=?",[$id,$group]);
                    $title='Ramo del gruppo eliminato';$detail="Eliminato il ramo {$before['nome']} di {$groupRow['nome']}.";
                } else {
                    $name=trim((string)($input['nome'] ?? ''));$level=EconomiaIntero($input['livello'] ?? '',0,2147483647);
                    EconomiaVerifica($name!=='' && mb_strlen($name)<=255 && $level!==null,'Nome o livello del ramo non valido');
                    if ($action==='addramo') {$db->execute("INSERT INTO {$table}(nome,{$field},livello) VALUES(?,?,?)",[$name,$group,$level]);$id=(int)$db->lastInsertId();}
                    else $db->execute("UPDATE {$table} SET nome=?,livello=? WHERE id=? AND {$field}=?",[$name,$level,$id,$group]);
                    $after=$db->query("SELECT id,nome,livello FROM {$table} WHERE id=?",[$id])[0];
                    $title=$action==='addramo'?'Ramo del gruppo creato':'Ramo del gruppo aggiornato';$detail="{$groupRow['nome']}: ramo {$name}, livello {$level}.";
                }
                $target=$id;$changed=$before!==$after;
            } elseif (in_array($action,['addcarica','modifycarica','dropcarica'],true)) {
                $table='cariche'.$scope;$id=$action==='addcarica'?0:(EconomiaIntero($input['carica'] ?? '',1) ?? 0);
                if ($id>0) {
                    $before=$db->query("SELECT * FROM {$table} WHERE id=? AND {$field}=? FOR UPDATE",[$id,$group])[0] ?? [];
                    EconomiaVerifica((bool)$before,'Carica non appartenente al gruppo');$remember($id);
                }
                if ($action==='dropcarica') {
                    EconomiaVerifica((bool)$before && !$db->query("SELECT id FROM utenti WHERE {$field}=? AND {$rankField}=? FOR UPDATE",[$group,$id]),'La carica è ancora assegnata');
                    $db->execute("DELETE FROM {$table} WHERE id=? AND {$field}=?",[$id,$group]);
                    $title='Carica del gruppo eliminata';$detail="Eliminata la carica {$before['nome']} di {$groupRow['nome']}.";
                    $path=htmlroot.'/uploads/badges/'.$scope.'/'.$group.'/'.$id.'.gif';if(is_file($path))unlink($path);
                } else {
                    $name=trim((string)($input['nome'] ?? $input['carica'] ?? ''));$branch=EconomiaIntero($input['ramo'] ?? '',0);$level=EconomiaIntero($input['livello'] ?? '',0,2147483647);
                    $description=trim((string)($input['descrizione'] ?? ''));$first=!empty($input['prima']);$file=$files['immagine'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
                    EconomiaVerifica($name!=='' && mb_strlen($name)<=50 && $branch!==null && $level!==null && mb_strlen($description)<=16000,'Dati della carica non validi');
                    EconomiaVerifica((bool)$db->query("SELECT id FROM rami{$scope} WHERE id=? AND {$field}=?",[$branch,$group]),'Ramo non appartenente al gruppo');
                    if ($scope==='organizzazioni') {
                        if ($action==='addcarica') $result=AddCaricaOrg($group,$name,$first,$branch,$level,$description,$file);
                        elseif ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $result=ModifyCaricaOrg($group,$id,$name,$first,$branch,$level,$description,$file);
                        else {$db->execute("UPDATE {$table} SET nome=?,prima=?,ramo=?,livello=?,descrizione=? WHERE id=? AND {$field}=?",[$name,$first?1:0,$branch,$level,$description,$id,$group]);$result='Carica organizzazione aggiornata';}
                        EconomiaVerifica(in_array($result,['Carica organizzazione creata','Carica organizzazione aggiornata'],true),$result);
                    } else {
                        $salary=EconomiaIntero($input['stipendio'] ?? $before['stipendio'] ?? 0,0,2147483647);$commission=EconomiaPercentuale($input['provvigione'] ?? $before['provvigione'] ?? 0);
                        EconomiaVerifica($salary!==null && $commission!==null,'Stipendio o provvigione non validi');
                        if ($action==='addcarica') {
                            if (empty($groupRow['stipendio']))$salary=0;if(empty($groupRow['provvigione']))$commission=0;
                            $result=AddCaricaCorp($group,$name,$first,$branch,$level,$description,(float)$salary,$commission,$file);
                        } elseif ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $result=ModifyCaricaCorp($group,$id,$name,$first,$branch,$level,$description,(float)$salary,$commission,$file);
                        else {$db->execute("UPDATE {$table} SET nome=?,prima=?,ramo=?,livello=?,descrizione=?,stipendio=?,provvigione=? WHERE id=? AND {$field}=?",[$name,$first?1:0,$branch,$level,$description,$salary,$commission,$id,$group]);$result='Carica corporazione aggiornata';}
                        EconomiaVerifica(in_array($result,['Carica corporazione creata','Carica corporazione aggiornata'],true),$result);
                    }
                    if ($action==='addcarica') {$id=(int)$db->lastInsertId();$undo[htmlroot.'/uploads/badges/'.$scope.'/'.$group.'/'.$id.'.gif']=null;}
                    $after=$db->query("SELECT * FROM {$table} WHERE id=? AND {$field}=?",[$id,$group])[0];
                    $title=$action==='addcarica'?'Carica del gruppo creata':'Carica del gruppo aggiornata';$detail="{$groupRow['nome']}: carica {$name}, livello {$level}, ramo #{$branch}.";
                }
                $target=$id;
                $path=htmlroot.'/uploads/badges/'.$scope.'/'.$group.'/'.$id.'.gif';
                $before['immagine']=isset($undo[$path])?hash('sha256',$undo[$path]):null;
                $after['immagine']=is_file($path)?hash_file('sha256',$path):null;
                $changed=$before!==$after;
                if(isset($before['descrizione']))$before['descrizione']=hash('sha256',$before['descrizione']);
                if(isset($after['descrizione']))$after['descrizione']=hash('sha256',$after['descrizione']);
            } elseif ($action==='setflag') {
                $section='flag';$target=EconomiaIntero($input['nome'] ?? '',1) ?? 0;
                $flags=$scope==='organizzazioni'?['adminorganizzazione'=>'adminorg']:['admincorporazione'=>'admincorporazione','admintrasfcorp'=>'admintrasfcorp','admincatalogo'=>'admincatalogo'];
                if($scope==='organizzazioni')foreach(['resurgo'=>'adminmorti','cerimonia'=>'adminstatosociale','ferite'=>'adminferite'] as $capability=>$flag)if(!empty($groupRow[$capability]))$flags[$flag]=$capability;
                $columns=implode(',',array_keys($flags));
                $before=$db->query("SELECT id,nome,{$columns} FROM utenti WHERE id=? AND {$field}=? FOR UPDATE",[$target,$group])[0] ?? [];
                EconomiaVerifica((bool)$before,'Personaggio non appartenente al gruppo');
                require_once __DIR__.'/admin-governance.inc.php';
                $updates=[];foreach($flags as $column=>$key)$updates[$column]=!empty($input[$key])?1:0;
                alphano_governance_apply($actor,$target,$updates,'Delega dal pannello '.$groupRow['nome'],null,$scope,$group);
                $after=$db->query("SELECT id,nome,{$columns} FROM utenti WHERE id=?",[$target])[0];
                $changed=$before!==$after;$personal=true;$title='Privilegi nel gruppo aggiornati';$detail="Aggiornati i privilegi di {$before['nome']} in {$groupRow['nome']}.";
            } elseif ($action==='modifypvt') {
                $section='pvt';$city=EconomiaIntero($input['city'] ?? '',1);$password=trim((string)($input['pvt'] ?? ''));
                EconomiaVerifica($city!==null && (bool)$db->query('SELECT id FROM city WHERE id=?',[$city]) && ($scope!=='organizzazioni' || $password!==''),'Città o password non validi');
                $columns=['city','sito','pvt','sfondotop','sfondo','mappa','descrizione'];$values=[$city,trim((string)($input['sito'] ?? '')),base64_encode($password),trim((string)($input['sfondotop'] ?? '')),trim((string)($input['sfondo'] ?? '')),trim((string)($input['mappa'] ?? '')),trim((string)($input['descrizione'] ?? ''))];
                $before=array_intersect_key($groupRow,array_flip($columns));
                $db->execute("UPDATE {$scope} SET city=?,sito=?,pvt=?,sfondotop=?,sfondo=?,mappa=?,descrizione=? WHERE id=?",[...$values,$group]);
                $after=$db->query('SELECT '.implode(',',$columns)." FROM {$scope} WHERE id=?",[$group])[0];
                $changed=$before!=$after;
                foreach(['pvt','descrizione'] as $secret){$before[$secret]=hash('sha256',(string)$before[$secret]);$after[$secret]=hash('sha256',(string)$after[$secret]);}
                $title='Dati e stanza privata aggiornati';$detail="Aggiornati città, sito e impostazioni della stanza privata di {$groupRow['nome']}.";
            } elseif ($action==='proponi') {
                $section='elezioni';$city=EconomiaIntero($input['city'] ?? '',1) ?? 0;$target=EconomiaIntero($input['pg'] ?? '',1) ?? 0;$program=trim((string)($input['programma'] ?? ''));
                if(alphano_elections_ready())throw new DomainException('La candidatura è una scelta personale: ogni cittadino può presentarla dal registro elettorale della propria città.',409);
                $cityRow=$db->query('SELECT budget,costoelezioni,city FROM city WHERE id=? AND elezioni=1 FOR UPDATE',[$city])[0] ?? null;
                $member=$db->query('SELECT u.id,u.nome FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE u.id=? AND u.organizzazione=? AND a.city=? FOR UPDATE',[$target,$group,$city])[0] ?? null;
                EconomiaVerifica($cityRow && $member && $program!=='' && ElectionTime($city) && !$db->query('SELECT idpg FROM candidati WHERE idpg=? FOR UPDATE',[$target]),'Candidatura non valida, già presente o candidature chiuse');
                $cost=(int)$cityRow['costoelezioni'];
                EconomiaVerifica($cost>=0 && (int)$groupRow['budget']>=$cost && (int)$cityRow['budget']<=2147483647-$cost,'Fondi insufficienti o saldo non valido');
                $db->execute('INSERT INTO candidati(idpg,organizzazione,city,programma) VALUES(?,?,?,?)',[$target,$group,$city,$program]);
                $db->execute('UPDATE organizzazioni SET budget=budget-? WHERE id=?',[$cost,$group]);$db->execute('UPDATE city SET budget=budget+? WHERE id=?',[$cost,$city]);
                $after=['candidato'=>$target,'city'=>$city,'programma'=>mb_substr($program,0,3000),'costo'=>$cost];$changed=true;$personal=true;
                $title='Candidatura proposta';$detail=alphano_currency_text("{$groupRow['nome']} ha candidato {$member['nome']} alle elezioni di {$cityRow['city']}. Costo: {$cost} Corone.");
            }
            if ($changed) {
                $audiences=[['scope'=>$scope,'id'=>$group,'section'=>$section,'role'=>'admin']];
                if($personal && $target>0)$audiences[]=['scope'=>'personale','id'=>$target,'section'=>'profilo','role'=>'member'];
                $eventType=$scope==='corporazioni' && $action==='modifycarica'?'corporation-role-updated':$prefix.'-admin-'.$action;
                alphano_record_activity($eventType,$actor,$audiences,$title,$detail,['gruppo'=>$group,'nome_gruppo'=>$groupRow['nome'],'azione'=>$action,'target'=>$target,'prima'=>$before,'dopo'=>$after]);
            }
            return $changed?$title:'Nessuna modifica necessaria';
        });
    } catch (Throwable $error) {
        foreach($undo as $path=>$contents){if($contents===null){if(is_file($path))unlink($path);}else file_put_contents($path,$contents);}
        if($error instanceof DomainException)return $error->getMessage();
        if(in_array($error->getCode(),[400,409],true))http_response_code($error->getCode());
        else error_log('[gruppi admin] '.$error->getMessage());
        return 'Operazione non completata';
    }
}

/** A proposal retains its founder and records the actual revision in one commit. */
function alphano_group_statute_proposal(string $scope, int $group, int $actor, string $statute, ?string $requestKey = null): bool
{
    if (!in_array($scope,['organizzazioni','corporazioni'],true) || trim($statute)==='' || mb_strlen($statute)>50000) return false;
    $field=$scope==='organizzazioni'?'organizzazione':'corporazione';$prefix=$scope==='organizzazioni'?'organization':'corporation';
    return EconomiaAzioneModulo(static fn(): bool => EconomiaBool(static function() use($scope,$group,$actor,$statute,$requestKey,$field,$prefix): bool {
        $db=getDB();$user=$db->query("SELECT admin,{$field},admin{$field} FROM utenti WHERE id=? FOR UPDATE",[$actor])[0] ?? [];
        EconomiaVerifica(!empty($user['admin']) || ((int)($user[$field] ?? 0)===$group && !empty($user['admin'.$field])),'Mandato non valido');
        if(!alphano_operation_once($prefix.'-statute-proposal',$actor,$requestKey,[$group,$statute]))return true;
        $before=$db->query("SELECT nome,newstatuto,newidmaster,idmaster FROM {$scope} WHERE id=? AND approvato=1 FOR UPDATE",[$group])[0] ?? null;
        EconomiaVerifica((bool)$before,'Gruppo non valido');
        $ok=$scope==='organizzazioni'?SalvaStatutoOrg($group,$actor,$statute):SalvaStatutoCorp($group,$actor,$statute);
        EconomiaVerifica($ok,'Impossibile proporre lo statuto');
        if ((string)$before['newstatuto']!==$statute || (int)$before['newidmaster']!==(int)$before['idmaster']) {
            alphano_record_activity($prefix.'-statute-proposed',$actor,[['scope'=>$scope,'id'=>$group,'section'=>'statuto','role'=>'admin']],
                'Modifica dello statuto proposta',"Una nuova versione dello statuto di {$before['nome']} è stata inviata all’Accademia.",['gruppo'=>$group,'nome_gruppo'=>$before['nome'],'fondatore'=>(int)$before['idmaster'],'revisione_precedente'=>hash('sha256',(string)$before['newstatuto']),'revisione'=>hash('sha256',$statute)]);
        }
        return true;
    }));
}
