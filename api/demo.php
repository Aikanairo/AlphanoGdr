<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try{
    alphano_session();$method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='POST'){
        alphano_require_csrf(true);$in=alphano_request_json();
        if(($in['action']??'')==='enter')$out=['visitor'=>ai_demo_enter($in)];
        elseif(($in['action']??'')==='send'){$visit=ai_demo_visit();if(!$visit)throw new DomainException('Entra nella demo per scrivere.',401);$out=ai_demo_send($visit,$in);}
        else throw new DomainException('Azione non disponibile.',422);
    }elseif($method==='GET'){
        $visit=ai_demo_visit();$out=['visitor'=>$visit?['name'=>$visit['name'],'sex'=>$visit['sex']]:null,'turns'=>[]];
        if(($_GET['poll']??'')!=='1')$out['rooms']=ai_demo_rooms();
        $chat=is_string($_GET['chat']??null)?$_GET['chat']:'';
        if($visit&&$chat!==''){
            $rows=ai_db()->query('SELECT t.id,t.npc_id,t.prompt,t.result,t.created_at,n.name,j.status FROM ai_demo_turns t LEFT JOIN ai_jobs j ON j.id=t.job_id LEFT JOIN ai_npcs n ON n.id=t.npc_id WHERE t.visit_id=? AND t.chat_key=? ORDER BY t.id DESC LIMIT 60',[$visit['id'],$chat]);
            foreach(array_reverse($rows) as $t)$out['turns'][]=['id'=>(int)$t['id'],'npc_id'=>(int)$t['npc_id'],'text'=>$t['prompt'],'reply'=>$t['result'],'npc_name'=>$t['name'],'time'=>(int)$t['created_at'],'status'=>$t['status']??'none'];
        }
    }else{header('Allow: GET, POST');throw new DomainException('Metodo non disponibile.',405);}
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode()>=400?$e->getCode():422,true);}
catch(Throwable $e){error_log('[Demo] '.$e->getMessage());alphano_fail('La demo è temporaneamente occupata. Riprova tra poco.',503,true);}
