<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/manuals.inc.php';

player_api(function():array {
    $public=($_GET['public']??'')==='1';
    if($public){
        // Older cached readers submit POST even for reads. They still receive
        // only the guest projection; the public action gate below forbids writes.
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
        $id=0;$user=alphano_manual_guest();$in=$method==='POST'?player_input():$_GET;
    }else{$id=alphano_require_login(true);$user=alphano_manual_user($id);$in=player_input();}
    $db=ConnectDB();
    $action=player_text($in,'azione',40);
    if($action==='read'){$out=['success'=>true,'rule'=>alphano_manual_payload(alphano_manual_find(player_text($in,'chapter',100),(int)($in['id']??0),$user),$user)];return $public?alphano_manual_public_payload($out):$out;}
    if($action===''){$out=alphano_manual_data($user,false);return $public?alphano_manual_public_payload($out):$out;}
    if($public)throw new DomainException('Consultazione pubblica in sola lettura.',405);
    player_mutation();
    if(empty($user['admin'])&&empty($user['adminaccademia']))throw new DomainException('Permessi di redazione richiesti.',403);
    $db->beginTransaction();
    try {
        $before=null;$target=0;
        if(in_array($action,['update-section','delete-section','move-section'],true)){
            $target=(int)($in['sezione_edit']??$in['id']??0);
            $before=$db->query('SELECT * FROM regole WHERE id=? FOR UPDATE',[$target])[0]??null;
            if(!$before||!alphano_manual_can_edit($before,$user))throw new DomainException('Capitolo non modificabile.',403);
            if(!hash_equals(alphano_manual_revision($before),player_text($in,'revision',64,true)))throw new DomainException('Il capitolo è cambiato. Riaprilo prima di salvare per conservare le modifiche più recenti.',409);
        }
        if(str_ends_with($action,'group')&&$action!=='insert-group'){
            $target=(int)($in['gruppo_edit']??$in['id']??0);
            $before=$db->query('SELECT * FROM sezioniregole WHERE id=? FOR UPDATE',[$target])[0]??null;
            if(!$before)throw new DomainException('Raccolta non trovata.',404);
            $children=$db->query('SELECT * FROM regole WHERE sezione=? FOR UPDATE',[$target]);
            if($action==='delete-group')$before['chapters']=$children;
            foreach($children as $r)if(!alphano_manual_can_edit($r,$user))throw new DomainException('La raccolta contiene guide riservate ad altri incarichi.',403);
        }
        switch($action){
            case 'insert-group':
                $db->execute('INSERT INTO sezioniregole(nome,pos) VALUES(?,?)',[player_text($in,'nome_gruppo',255,true),(int)$db->query('SELECT COALESCE(MAX(pos),0)+1 AS p FROM sezioniregole')[0]['p']]);$target=(int)$db->lastInsertId();break;
            case 'update-group':
                $db->execute('UPDATE sezioniregole SET nome=? WHERE id=?',[player_text($in,'nome_edit_gruppo',255,true),$target]);break;
            case 'delete-group':
                if(!empty($before['manual_key']))throw new DomainException('Le raccolte della guida centrale si possono rinominare, ma non eliminare.',409);
                $db->execute('DELETE FROM regole WHERE sezione=?',[$target]);$db->execute('DELETE FROM sezioniregole WHERE id=?',[$target]);break;
            case 'insert-section':
            case 'update-section':
                $group=(int)($before['sezione']??$in['gruppo_select']??0);
                if(!$db->query('SELECT id FROM sezioniregole WHERE id=? FOR UPDATE',[$group]))throw new DomainException('Raccolta non disponibile.',404);
                foreach($action==='insert-section'?$db->query('SELECT * FROM regole WHERE sezione=?',[$group]):[] as $r)if(!alphano_manual_can_edit($r,$user))throw new DomainException('Questa raccolta è riservata alla redazione amministrativa.',403);
                $audience=player_text($in,'audience',24)?:($before['audience']??'public');
                if(!array_key_exists($audience,alphano_manual_audiences())||!alphano_manual_role($audience,$user)||(empty($user['admin'])&&!in_array($audience,['public','academy'],true)))throw new DomainException('Destinatari non consentiti.',403);
                $definition=alphano_manual_definition((string)($before['manual_key']??''));
                if($definition&&$definition['audience']!=='public'&&$audience!==$definition['audience'])throw new DomainException('La destinazione di questa guida riservata è fissata dal suo incarico.',422);
                $title=player_text($in,$action==='insert-section'?'titolo_sezione':'titolo_edit',255,true);
                $body=alphano_sanitize_html(player_text($in,'spiegazione_edit',500000,true));
                $image=alphano_manual_image(player_text($in,'image',255)?:($before['image']??'/assets/placeholder.svg'));
                $summary=player_text($in,'summary',1200);
                if($action==='insert-section'){
                    $pos=(int)$db->query('SELECT COALESCE(MAX(pos),0)+1 AS p FROM regole WHERE sezione=?',[$group])[0]['p'];
                    $db->execute('INSERT INTO regole(sezione,pos,oggetto,spiegazione,ultimamodifica,audience,image,summary) VALUES(?,?,?,?,?,?,?,?)',[$group,$pos,$title,$body,time(),$audience,$image,$summary]);$target=(int)$db->lastInsertId();
                }else $db->execute('UPDATE regole SET oggetto=?,spiegazione=?,ultimamodifica=?,audience=?,image=?,summary=? WHERE id=?',[$title,$body,time(),$audience,$image,$summary,$target]);
                break;
            case 'delete-section':
                if(!empty($before['manual_key']))throw new DomainException('I capitoli della guida centrale si modificano dalla redazione; non possono essere eliminati.',409);
                $db->execute('DELETE FROM regole WHERE id=?',[$target]);break;
            case 'move-section':
                $dir=player_text($in,'dir',4);if(!in_array($dir,['up','down'],true))throw new DomainException('Direzione non valida.',422);
                $rows=$db->query('SELECT * FROM regole WHERE sezione=? ORDER BY pos,id FOR UPDATE',[(int)$before['sezione']]);
                foreach($rows as $r)if(!alphano_manual_can_edit($r,$user))throw new DomainException('Raccolta non modificabile.',403);
                $ids=array_map('intval',array_column($rows,'id'));$index=array_search($target,$ids,true);$other=$index+($dir==='up'?-1:1);
                if(isset($ids[$other]))[$ids[$index],$ids[$other]]=[$ids[$other],$ids[$index]];
                foreach($ids as $position=>$rid)$db->execute('UPDATE regole SET pos=? WHERE id=?',[$position+1,$rid]);break;
            default:throw new DomainException('Operazione non riconosciuta.',400);
        }
        $after=$db->query('SELECT * FROM '.(str_ends_with($action,'group')?'sezioniregole':'regole').' WHERE id=?',[$target])[0]??null;
        $db->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,descr,chat) VALUES(?,?,0,'manuali',?,?)",[time(),$id,$action.' #'.$target,json_encode(['before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
    return alphano_manual_data($user,false);
});
