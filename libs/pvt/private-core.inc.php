<?php
declare(strict_types=1);
/** Stanze private, sedi e dimore: permessi, canoni e inventario condivisi. */
require_once dirname(__DIR__) . '/kernel.inc.php';
require_once dirname(__DIR__) . '/libpg.inc.php';
require_once __DIR__.'/rentals.inc.php';

function alphano_private_month(): int { return ((int)date('Y')-1970)*12+(int)date('n'); }
function alphano_private_paid(int $last): bool { return $last === alphano_private_month() || $last === (int)date('n'); }
function alphano_private_city(string $slug): array
{
    $city = ConnectDB()->query('SELECT c.* FROM city c JOIN world_areas a ON a.city_id=c.id WHERE a.slug=? AND a.enabled=1',[$slug])[0] ?? null;
    if (!$city) throw new DomainException('Città non trovata',404);
    return $city + ['slug'=>$slug];
}
function alphano_private_player(int $id): array
{
    $user = ConnectDB()->query('SELECT * FROM utenti WHERE id=?',[$id])[0] ?? null;
    if (!$user || $user['bloccato'] || (int)$user['imprigionato']>0) throw new DomainException('Non puoi accedere alle stanze private in questo momento',403);
    return $user;
}
/** Non riapre sessioni chiuse: utilizzato anche dalle letture incrementali HTTP. */
function alphano_private_room(string $key,int $pg): ?array
{
    if(str_starts_with($key,'rent_'))return alphano_rental_room($key,$pg);
    if (!preg_match('/^pvt_[a-f0-9]{40}$/D',$key)) return null;
    $grant = $_SESSION['private_rooms'][$key] ?? null;
    if (!$grant || (int)$grant['pg'] !== $pg || (int)$grant['expires']<time()) return null;
    try { $user=alphano_private_player($pg); $city=alphano_private_city($grant['slug']); }
    catch (DomainException) { return null; }
    $record=[]; $kind=$grant['kind'];
    if (in_array($kind,['org','corp'],true)) {
        $table=$kind==='org'?'organizzazioni':'corporazioni';
        $record=ConnectDB()->query("SELECT * FROM $table WHERE id=? AND city=? AND approvato=1",[$grant['id'],$city['id']])[0] ?? [];
        if (!$record || !hash_equals($grant['signature'],hash('sha256',$record['pvt'])) || !alphano_private_paid((int)$record['lastpay'])) return null;
        $title=$city['city'].' – Sede di '.$record['nome'];
    } elseif ($kind==='dimore') {
        $record=ConnectDB()->query('SELECT d.*,i.indirizzo AS via,i.capienza FROM dimore d JOIN indirizzi i ON i.id=d.indirizzo WHERE d.id=? AND d.city=?',[$grant['id'],$city['id']])[0] ?? [];
        if (!$record || !hash_equals($grant['signature'],hash('sha256',$record['password'])) || !alphano_private_paid((int)$record['lastpay'])) return null;
        $title=$city['city'].' – '.$record['via'].' '.$record['numero'];
    } elseif ($kind==='pvt') {
        if ($user['chat'] !== $key) return null;
        $title=$city['city'].' – Stanza privata';
    } else return null;
    return ['chat_key'=>$key,'chat'=>1,'isChatFlag'=>1,'title'=>$title,'position'=>$title,'vampiredamage'=>0,
        'sfondotop'=>$record['sfondotop'] ?? '', 'sfondo'=>$record['sfondo'] ?? '', 'mappa'=>$record['mappa'] ?? '',
        'descrizione'=>$record['descrizione'] ?? '', 'private_kind'=>$kind,'private_slug'=>$grant['slug'],'private_id'=>(int)$grant['id'],
        'private_owner'=>(int)($record['pg'] ?? 0),'private_capacity'=>(int)($record['capienza'] ?? 0),'private_record'=>$record];
}
function alphano_private_grant(string $kind,array $city,int $pg,int $id,string $secret,string $legacy): string
{
    $identity=$kind==='pvt' ? hash('sha256',$secret) : (string)$id;
    $key='pvt_'.substr(hash('sha256',$kind.'|'.$city['id'].'|'.$identity),0,40);
    // Lo storico cambia identificatore, senza cancellare messaggi o sessioni.
    if ($legacy !== '' && $legacy !== $key) {
        foreach (['chat','sessioni','registrazioni','lavori','avvisi','utenti'] as $table) {
            ConnectDB()->execute("UPDATE $table SET chat=? WHERE chat=?",[$key,$legacy]);
        }
    }
    $_SESSION['private_rooms'][$key]=['pg'=>$pg,'slug'=>$city['slug'],'kind'=>$kind,'id'=>$id,'signature'=>hash('sha256',$secret),'expires'=>time()+43200];
    $_SESSION['private_rooms']=array_slice($_SESSION['private_rooms'],-32,null,true);
    $_SESSION['private_current'][$city['slug']][$kind==='org'||$kind==='corp'?'stanze':$kind]=$key;
    return $key;
}
function alphano_private_join(string $type,array $city,int $pg,array $input): string
{
    $db=ConnectDB();$user=alphano_private_player($pg);
    if (empty($input['accept'])) throw new DomainException('Accetta il disclaimer prima di entrare',400);
    $oldGrants = $_SESSION['private_rooms'] ?? [];
    $oldCurrent = $_SESSION['private_current'] ?? [];
    $db->beginTransaction();
    try {
        $user=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$pg])[0];
        $city=array_replace($city,$db->query('SELECT * FROM city WHERE id=? FOR UPDATE',[$city['id']])[0]);
        if ($type==='pvt') {
            $secret=trim((string)($input['pvt'] ?? ''));
            if ($secret==='' || strlen($secret)>100) throw new DomainException('Nome stanza non valido',400);
            $cost=max(0,(int)$city['costopvt']);
            $key='pvt_'.substr(hash('sha256','pvt|'.$city['id'].'|'.hash('sha256',$secret)),0,40);
            // Un reinvio della stessa apertura non addebita una seconda volta.
            if ($user['chat']!==$key || !isset($_SESSION['private_rooms'][$key])) {
                if ((int)$user['denari']<$cost) throw new DomainException(alphano_currency_text('Non hai abbastanza Corone per questa stanza'),409);
                $db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$cost,$pg]);
                $db->execute('UPDATE city SET budget=budget+? WHERE id=?',[$cost,$city['id']]);
            }
            $key=alphano_private_grant('pvt',$city,$pg,0,$secret,'pvt'.$city['city'].base64_encode($secret));
        } elseif ($type==='stanze') {
            $kind=!empty($input['org'])?'org':'corp';$id=(int)($input[$kind] ?? 0);
            $table=$kind==='org'?'organizzazioni':'corporazioni';$costField=$kind==='org'?'costoorg':'costocorp';
            $secret=base64_encode((string)($input['pass'] ?? ''));
            $record=$db->query("SELECT * FROM $table WHERE id=? AND city=? AND approvato=1 FOR UPDATE",[$id,$city['id']])[0] ?? null;
            if (!$record || !hash_equals($record['pvt'],$secret)) throw new DomainException('Sede o password non valida',403);
            if (!alphano_private_paid((int)$record['lastpay'])) {
                $cost=max(0,(int)$city[$costField]);
                if ((int)$record['budget']<$cost) throw new DomainException('Il fondo della sede non copre il canone mensile',409);
                $db->execute("UPDATE $table SET budget=budget-?,lastpay=? WHERE id=?",[$cost,alphano_private_month(),$id]);
                $db->execute('UPDATE city SET budget=budget+? WHERE id=?',[$cost,$city['id']]);
            } else $db->execute("UPDATE $table SET lastpay=? WHERE id=?",[alphano_private_month(),$id]);
            $key=alphano_private_grant($kind,$city,$pg,$id,$record['pvt'],'pvt'.$kind.$id.$record['pvt']);
        } else {
            $secret=base64_encode((string)($input['password'] ?? ''));
            $record=$db->query('SELECT d.*,i.indirizzo AS via,i.costo FROM dimore d JOIN indirizzi i ON i.id=d.indirizzo WHERE d.city=? AND i.indirizzo=? AND d.numero=? FOR UPDATE',[$city['id'],trim((string)($input['indirizzo'] ?? '')),(int)($input['numero'] ?? 0)])[0] ?? null;
            if (!$record || !hash_equals($record['password'],$secret)) throw new DomainException('Dimora o password non valida',403);
            if (!alphano_private_paid((int)$record['lastpay'])) {
                if ((int)$record['pg']!==$pg) throw new DomainException('Il proprietario deve prima pagare il canone mensile',409);
                $cost=max(0,(int)$record['costo']);
                if ((int)$user['denari']<$cost) throw new DomainException(alphano_currency_text('Non hai abbastanza Corone per il canone della dimora'),409);
                $db->execute('UPDATE utenti SET denari=denari-? WHERE id=?',[$cost,$pg]);
                $db->execute('UPDATE city SET budget=budget+? WHERE id=?',[$cost,$city['id']]);
                $db->execute('INSERT INTO logs_dimore(data,pg,city) VALUES (?,?,?)',[time(),$pg,$city['id']]);
                require_once dirname(__DIR__) . '/libposta.inc.php';
                InviaMissiva('Affitto','Ti sono stati scalati '.$cost.alphano_currency_text(' Corone per il canone mensile della tua casa a ').$city['city'],$pg);
            }
            $db->execute('UPDATE dimore SET lastpay=? WHERE id=?',[alphano_private_month(),$record['id']]);
            $key=alphano_private_grant('dimore',$city,$pg,(int)$record['id'],$record['password'],'pvtdimora'.$record['id'].$record['password']);
        }
        $db->execute('UPDATE utenti SET chat=?,posizione=?,lastmove=? WHERE id=?',[$key,$city['city'].' – '.($type==='dimore'?'Dimora':($type==='stanze'?'Sede':'Stanza privata')),time(),$pg]);
        $db->commit();return $key;
    } catch (Throwable $e) { $db->rollBack(); $_SESSION['private_rooms']=$oldGrants; $_SESSION['private_current']=$oldCurrent; throw $e; }
}
function alphano_private_rent(array $city,int $pg,int $address,string $password): void
{
    $db=ConnectDB();
    if ((int)GetPGCity($pg)!==(int)$city['id']) throw new DomainException('Puoi affittare una dimora nella tua città di residenza',403);
    if (trim($password)==='' || strlen($password)>150) throw new DomainException('Password non valida',400);
    $db->beginTransaction();
    try {
        $row=$db->query('SELECT * FROM indirizzi WHERE id=? AND city=? FOR UPDATE',[$address,$city['id']])[0] ?? null;
        if (!$row || (int)$row['disp']<1) throw new DomainException('Indirizzo non più disponibile',409);
        if ($db->query('SELECT id FROM dimore WHERE pg=? AND city=?',[$pg,$city['id']])) throw new DomainException('Possiedi già una dimora in questa città',409);
        $numbers=array_map('intval',array_column($db->query('SELECT numero FROM dimore WHERE indirizzo=? ORDER BY numero',[$address]),'numero'));
        $number=1;foreach($numbers as $taken) { if($taken===$number)++$number;elseif($taken>$number)break; }
        $db->execute('INSERT INTO dimore(pg,city,indirizzo,numero,password,descrizione) VALUES (?,?,?,?,?,?)',[$pg,$city['id'],$address,$number,base64_encode($password),'']);
        $db->execute('UPDATE indirizzi SET disp=disp-1 WHERE id=?',[$address]);
        $db->commit();
    } catch(Throwable $e) { $db->rollBack();throw $e; }
}
function alphano_private_move(array $room,int $pg,string $action,int $item): void
{
    if ($room['private_kind']!=='dimore' || $room['private_owner']!==$pg) throw new DomainException('Solo il proprietario può spostare gli oggetti',403);
    $db=ConnectDB();$db->beginTransaction();
    try {
        $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$pg]);
        if ($action==='movetovetrina') {
            $row=$db->query('SELECT id FROM armadio WHERE id=? AND idpg=? AND idpadre=0 FOR UPDATE',[$item,$pg])[0] ?? null;
            if (!$row) throw new DomainException('Oggetto non trovato nel tuo armadio',404);
            $db->execute('UPDATE armadio SET vetrina=1-vetrina WHERE id=? AND idpg=?',[$item,$pg]);
        } else {
            $toWardrobe=$action==='movetoarmadio';$from=$toWardrobe?'equippg':'armadio';$to=$toWardrobe?'armadio':'equippg';
            $row=$db->query("SELECT * FROM $from WHERE id=? AND idpg=? AND slot='' AND idpadre=0 FOR UPDATE",[$item,$pg])[0] ?? null;
            if (!$row) throw new DomainException('Oggetto non disponibile o non appartenente al tuo personaggio',404);
            $count=(int)$db->query("SELECT COUNT(*) AS n FROM $to WHERE idpg=? AND slot='' AND idpadre=0",[$pg])[0]['n'];
            $limit=$toWardrobe?$room['private_capacity']:24;
            if ($count>=$limit) throw new DomainException('Non ci sono spazi liberi',409);
            $ids=[$item];$pending=[$item];
            while($pending) {
                $ph=implode(',',array_fill(0,count($pending),'?'));
                $children=array_map('intval',array_column($db->query("SELECT id FROM $from WHERE idpg=? AND idpadre IN ($ph) FOR UPDATE",array_merge([$pg],$pending)),'id'));
                $pending=array_values(array_diff($children,$ids));$ids=array_merge($ids,$pending);
            }
            $ph=implode(',',array_fill(0,count($ids),'?'));
            if ($db->query("SELECT id FROM $to WHERE id IN ($ph)",$ids)) throw new DomainException('Un oggetto con questo identificativo è già presente: nessuno spostamento eseguito',409);
            $columns='id,uid,idpg,slot,nome,consumo,idpadre';
            $db->execute("INSERT INTO $to ($columns) SELECT $columns FROM $from WHERE idpg=? AND id IN ($ph)",array_merge([$pg],$ids));
            $db->execute("DELETE FROM $from WHERE idpg=? AND id IN ($ph)",array_merge([$pg],$ids));
            $db->execute('INSERT INTO logs_equip(data,esecutore,origine,obj,azione) VALUES (?,?,?,?,?)',[time(),$pg,$pg,$row['uid'],$toWardrobe?'ARMADIO':'ZAINO']);
        }
        $db->commit();
    } catch(Throwable $e) { $db->rollBack();throw $e; }
}
function alphano_private_update(array $room,int $pg,array $input): void
{
    if ($room['private_kind']!=='dimore' || $room['private_owner']!==$pg) throw new DomainException('Solo il proprietario può modificare la dimora',403);
    $values=[];
    foreach(['sfondotop','sfondo','mappa'] as $field) {
        $value=trim((string)($input[$field] ?? ''));
        if (strlen($value)>255 || ($value!=='' && !preg_match('~^(https?://|/(?!/))~i',$value))) throw new DomainException('Indirizzo immagine non valido',400);
        $values[]=$value;
    }
    $password=(string)($input['password'] ?? '');
    if (strlen($password)>150) throw new DomainException('Password troppo lunga',400);
    $secret=$password!==''?base64_encode($password):$room['private_record']['password'];
    $values[]=(string)($input['descrizione'] ?? '');$values[]=$secret;$values[]=$room['private_id'];$values[]=$pg;
    ConnectDB()->execute('UPDATE dimore SET sfondotop=?,sfondo=?,mappa=?,descrizione=?,password=? WHERE id=? AND pg=?',$values);
    foreach($_SESSION['private_rooms'] as &$grant) {
        if ($grant['kind']==='dimore' && (int)$grant['id']===$room['private_id']) $grant['signature']=hash('sha256',$secret);
    }unset($grant);
}

function alphano_private_dispatch(string $type,string $slug,string $page): void
{
    require_once dirname(__DIR__) . '/libgrafica.inc.php';
    require_once dirname(__DIR__) . '/libtext.inc.php';
    $pg=alphano_require_login();
    try { $city=alphano_private_city($slug); $user=alphano_private_player($pg); }
    catch (DomainException $e) { alphano_fail($e->getMessage(), $e->getCode() ?: 403); }
    $base='/chat/'.$slug.'/'.$type.'/';$message='';$room=null;
    try {
        if ($page==='index') { header('Location: '.$base.'pvt.php');return; }
        if ($_SERVER['REQUEST_METHOD']==='POST') alphano_require_csrf();
        if ($page==='pvt' && $_SERVER['REQUEST_METHOD']==='POST') {
            if (!empty($_POST['indirizzo']) && empty($_POST['accept']) && $type==='dimore' && isset($_POST['password'])) {
                alphano_private_rent($city,$pg,(int)$_POST['indirizzo'],(string)$_POST['password']);$message='Contratto di affitto creato. Puoi entrare nella tua dimora.';
            } elseif (!empty($_POST['accept'])) {
                $key=alphano_private_join($type,$city,$pg,$_POST);
                header('Location: '.$base.'chat.php?room='.rawurlencode($key),true,303);return;
            }
        }
        if (in_array($page,['chat','control','addLchat','showdetails'],true)) {
            $key=(string)($_GET['room'] ?? $_POST['room'] ?? $_SESSION['private_current'][$slug][$type] ?? '');
            $room=alphano_private_room($key,$pg);
            if ($room && ($room['private_slug']!==$slug || ($type==='stanze' ? !in_array($room['private_kind'],['org','corp'],true) : $room['private_kind']!==$type))) $room=null;
            if (!$room) {
                if ($page==='chat') {header('Location: '.$base.'pvt.php');return;}
                throw new DomainException('Apri prima la stanza e verifica la password',403);
            }
            if ($page==='chat' && $_SERVER['REQUEST_METHOD']==='POST') {
                $action=(string)($_POST['azione'] ?? '');
                if (in_array($action,['movetoarmadio','movetozaino','movetovetrina'],true)) alphano_private_move($room,$pg,$action,(int)($_POST['oggetto'] ?? 0));
                elseif (!empty($_POST['aggiorna'])) alphano_private_update($room,$pg,$_POST);
                else throw new DomainException('Azione non valida',400);
                header('Location: '.$base.'chat.php?room='.rawurlencode($key),true,303);return;
            }
            if ($page==='control') { header('Location: /chat/messages.php?chat='.rawurlencode($key).'&initial=1');return; }
            if ($page==='addLchat') {
                if ($_SERVER['REQUEST_METHOD']!=='POST') throw new DomainException('Usa POST per inviare un messaggio',405);
                require_once dirname(__DIR__,2).'/chat/libs/chat-core.php';
                $text=(string)($_POST['frase'] ?? $_POST['msg'] ?? '');
                if (trim($text)==='' || strlen($text)>16000) throw new DomainException('Messaggio non valido',400);
                $parsed=parseClient(['chat'=>$key,'pg'=>$pg,'msg'=>$text,'umore'=>(int)($_POST['umore'] ?? 0),'location'=>(string)($_POST['location'] ?? '')]);
                if ($parsed['html']==='') throw new DomainException('Questo messaggio non può essere inviato',422);
                clearOldMessages($key);$id=insertHtml($key,$parsed['html'],$pg,(int)$parsed['dest'],$parsed['location'],$parsed['lican']);
                header('Content-Type: application/json');echo json_encode(['id'=>$id]);return;
            }
            if ($page==='showdetails') { alphano_private_details($room,$pg,(int)($_POST['id'] ?? $_GET['id'] ?? 0));return; }
        }
    } catch(DomainException $e) {
        http_response_code($e->getCode() ?: 400);$message=$e->getMessage();
        if ($page!=='pvt') { alphano_private_shell($city['city'].' – Stanze private','<p>'.htmlspecialchars($message,ENT_QUOTES).'</p><a href="'.$base.'pvt.php">Torna all’ingresso</a>');return; }
    }
    ob_start();
    if ($message!=='') echo '<p role="status">'.htmlspecialchars($message,ENT_QUOTES).'</p>';
    if ($room) alphano_private_view($room,$pg,$base);
    else alphano_private_entry($type,$city,$pg,$base);
    alphano_private_shell($city['city'].' – '.($type==='dimore'?'Dimore':($type==='stanze'?'Sedi e stanze':'Stanze private')),ob_get_clean());
}
function alphano_private_shell(string $title,string $body): void
{
    $h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$h($title).'</title></head><body><h1>'.$h($title).'</h1>'.$body.'</body></html>';
}
function alphano_private_entry(string $type,array $city,int $pg,string $base): void
{
    $h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$db=ConnectDB();
    echo '<form method="post" action="'.$base.'pvt.php"><fieldset><legend>Ingresso</legend>';
    if ($type==='pvt') echo '<p>Il costo di ingresso è di '.(int)$city['costopvt'].alphano_currency_html(' Corone.</p><label>Nome segreto della stanza <input type="password" name="pvt" required maxlength="100"></label>');
    elseif ($type==='stanze') {
        $user=alphano_private_player($pg);$options=[];
        foreach(['org'=>['organizzazioni','organizzazione'],'corp'=>['corporazioni','corporazione']] as $kind=>[$table,$field]) {
            $own=$db->query("SELECT id,nome,pvt FROM $table WHERE id=? AND city=? AND approvato=1",[(int)$user[$field],$city['id']])[0] ?? null;
            if ($own) $options[]=['kind'=>$kind,'id'=>$own['id'],'name'=>$own['nome'],'password'=>base64_decode($own['pvt'])];
        }
        if ($options) {
            echo '<label>Selezione veloce <select onchange="const o=this.selectedOptions[0];this.form.org.value=o.dataset.kind===\'org\'?o.value:\'\';this.form.corp.value=o.dataset.kind===\'corp\'?o.value:\'\';this.form.pass.value=o.dataset.password||\'\'"><option value="">Scegli la tua sede</option>';
            foreach($options as $option) echo '<option value="'.(int)$option['id'].'" data-kind="'.$option['kind'].'" data-password="'.$h($option['password']).'">'.$h($option['name']).'</option>';
            echo '</select></label>';
        }
        echo '<label>ID Organizzazione <input name="org" type="number" min="1"></label><label>ID Corporazione <input name="corp" type="number" min="1"></label><label>Password della sede <input type="password" name="pass"></label>';
    } else {
        $own=$db->query('SELECT d.numero,d.password,i.indirizzo FROM dimore d JOIN indirizzi i ON i.id=d.indirizzo WHERE d.pg=? AND d.city=?',[$pg,$city['id']])[0] ?? [];
        echo '<label>Via <input name="indirizzo" value="'.$h($own['indirizzo'] ?? '').'" required></label><label>Numero <input type="number" name="numero" min="1" value="'.$h($own['numero'] ?? '').'" required></label><label>Password <input type="password" name="password" value="'.$h(base64_decode($own['password'] ?? '')).'" required></label>';
    }
    echo '<p>'.alphano_private_disclaimer().'</p><label><input type="checkbox" name="accept" value="1" required> Accetto</label><button>Accedi</button></fieldset></form>';
    if ($type==='dimore' && (int)GetPGCity($pg)===(int)$city['id'] && !$db->query('SELECT id FROM dimore WHERE pg=? AND city=?',[$pg,$city['id']])) {
        echo '<fieldset><legend>Nuovo contratto di affitto</legend><table><tr><th>Via</th><th>Canone mensile</th><th>Disponibilità</th><th>Capienza armadio</th><th>Affitta</th></tr>';
        foreach($db->query('SELECT * FROM indirizzi WHERE city=? AND disp>0 ORDER BY indirizzo',[$city['id']]) as $address) {
            echo '<tr><td>'.$h($address['indirizzo']).'</td><td>'.(int)$address['costo'].'</td><td>'.(int)$address['disp'].'</td><td>'.(int)$address['capienza'].'</td><td><form method="post" action="'.$base.'pvt.php"><input type="hidden" name="indirizzo" value="'.(int)$address['id'].'"><label>Password della nuova dimora <input type="password" name="password" required maxlength="150"></label><button>Affitta</button></form></td></tr>';
        }
        echo '</table></fieldset>';
    }
    echo '<p><a href="/mappa.php">Esci e torna alla mappa</a></p>';
}
function alphano_private_view(array $room,int $pg,string $base): void
{
    $h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$key=$room['chat_key'];$db=ConnectDB();
    echo '<iframe class="chat" title="'.$h($room['title']).'" src="/chat/index.php?chat='.rawurlencode($key).'"></iframe>';
    if ($room['private_kind']==='dimore') {
        $owner=$room['private_owner'];
        echo '<details><summary>Arredamento della dimora</summary><div class="items">';
        foreach($db->query('SELECT a.id,a.nome,e.shortDesc,e.immagine FROM armadio a JOIN equip e ON e.id=a.uid WHERE a.idpg=? AND a.vetrina=1 AND a.idpadre=0',[$owner]) as $item) {
            echo '<div class="item"><a href="'.$base.'showdetails.php?room='.rawurlencode($key).'&amp;id='.(int)$item['id'].'" target="_blank"><img src="'.$h($item['immagine']).'" alt="">'.$h($item['nome'] ?: $item['shortDesc']).'</a></div>';
        }echo '</div></details>';
        if ($owner===$pg) {
            echo '<details><summary>Armadio e zaino</summary><p>Capienza dell’armadio: '.$room['private_capacity'].' oggetti. Capienza dello zaino: 24 oggetti.</p>';
            foreach(['equippg'=>'Zaino','armadio'=>'Armadio'] as $table=>$label) {
                echo '<h2>'.$label.'</h2><div class="items">';
                foreach($db->query("SELECT a.id,a.nome,e.shortDesc,e.immagine".($table==='armadio'?',a.vetrina':'')." FROM $table a JOIN equip e ON e.id=a.uid WHERE a.idpg=? AND a.slot='' AND a.idpadre=0",[$pg]) as $item) {
                    echo '<form class="item" method="post"><input type="hidden" name="room" value="'.$h($key).'"><input type="hidden" name="oggetto" value="'.(int)$item['id'].'"><img src="'.$h($item['immagine']).'" alt=""><p>'.$h($item['nome'] ?: $item['shortDesc']).'</p>';
                    if ($table==='equippg') echo '<button name="azione" value="movetoarmadio">Metti nell’armadio</button>';
                    else echo '<button name="azione" value="movetozaino">Metti nello zaino</button><button name="azione" value="movetovetrina">'.($item['vetrina']?'Togli dall’arredamento':'Esponi nell’arredamento').'</button>';
                    echo '</form>';
                }echo '</div>';
            }echo '</details><details><summary>Amministrazione della dimora</summary><form method="post"><input type="hidden" name="room" value="'.$h($key).'"><input type="hidden" name="aggiorna" value="1"><label>Nuova password (lascia vuoto per conservarla)<input type="password" name="password" maxlength="150"></label>';
            foreach(['sfondotop'=>'Immagine di titolo','sfondo'=>'Sfondo','mappa'=>'Mappa'] as $field=>$label) echo '<label>'.$label.' <input name="'.$field.'" value="'.$h($room['private_record'][$field]).'"></label>';
            echo '<label>Descrizione <textarea name="descrizione">'.$h($room['descrizione']).'</textarea></label><button>Salva dimora</button></form></details>';
        }
    }
    echo '<p><a href="'.$base.'pvt.php">Torna all’ingresso</a> · <a href="/mappa.php">Esci e torna alla mappa</a></p>';
}
function alphano_private_details(array $room,int $pg,int $id): void
{
    if ($room['private_kind']!=='dimore') throw new DomainException('Oggetto non disponibile',404);
    $query='SELECT a.*,e.shortDesc,e.immagine,e.`desc` AS descrizione,e.peso,e.taglia,e.durata,e.mani,e.sacro,e.restore,e.idcreatore,e.lavorazione,t.nome AS tipo,t.tipoG FROM armadio a JOIN equip e ON e.id=a.uid LEFT JOIN tipo t ON t.id=e.tipo WHERE a.id=? AND a.idpg=?';
    if ($room['private_owner']!==$pg) $query.=' AND a.vetrina=1';
    $item=ConnectDB()->query($query,[$id,$room['private_owner']])[0] ?? null;
    if (!$item) throw new DomainException('Oggetto non disponibile nella vetrina',404);
    $h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    require_once dirname(__DIR__) . '/libequip.inc.php';
    $details='<h2>'.$h($item['nome']).'</h2><img src="'.$h($item['immagine']).'" alt="" class="u-8d1f4ab85"><div>'.ProcessText($item['descrizione']).'</div><dl>';
    $properties=['Tipo'=>$item['tipo'],'Taglia'=>getSize((int)$item['taglia']),'Peso'=>$item['peso'],'Mani'=>$item['mani'],'Oggetto sacro'=>$item['sacro']?'Sì':'No','Ripristina PF'=>$item['restore'],'Creatore'=>$item['idcreatore']?GetPGFromID((int)$item['idcreatore']):'','Lavorazione'=>$item['lavorazione'],'Qualità'=>getQuality((int)$item['durata']),'Consumo'=>$item['consumo'].'/'.$item['durata']];
    foreach($properties as $label=>$value) $details.='<dt>'.$h($label).'</dt><dd>'.$h($value).'</dd>';
    alphano_private_shell($item['shortDesc'],$details.'</dl>');
}

function alphano_private_disclaimer(): string
{
    return <<<'HTML'
Il sottoscritto accedendo alla zona privata, solleva i gestori del sito da ogni responsabilit&agrave; personale e patrimoniale in osservanza delle leggi vigenti nello stato italiano in tutela del rispetto dei minori, della privacy, di dichiarazioni mendaci e di ogni legge vigente a tutela dei cittadini.<BR>
						Il sottoscritto inoltre nel momento dell'accesso dell'area privata, sotto propria responsabilit&agrave;, accetta le eventuali sanzioni scaturenti da un suo comportamento contrario alle  leggi imperative, di buon costume o comunque di tutela del cittadino italiano nel caso di commissione di un  reato punibile secondo le norme del codice penale o leggi speciali attualmente in vigore nello Stato italiano.<BR>
HTML;
}
