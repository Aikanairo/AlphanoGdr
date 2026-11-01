<?php
declare(strict_types=1);
/** Archive browser for data belonging to this installation. No source-game import parser. */
function gh_ready():bool {return (bool)ConnectDB()->query("SHOW TABLES LIKE 'historical_sessions'");}

function gh_page():never {
 $db=ConnectDB();$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$historical=true;
 if(!gh_ready()){$historyRows=[];$total=0;$page=1;$pages=1;$query='';$character='';$characterNames=[];require __DIR__.'/../templates/sessions/historical.php';exit;}
 $id=$_GET['historic_id']??'';if(!is_string($id)||$id!==''&&(!ctype_digit($id)||(int)$id<1))alphano_fail('Sessione storica non valida.',400);
 $pageRaw=$_GET['page']??'1';if(!is_string($pageRaw)||!ctype_digit($pageRaw)||(int)$pageRaw<1)alphano_fail('Pagina non valida.',400);$page=min(10000,(int)$pageRaw);
 if($id!==''){
  $saved=$db->query('SELECT * FROM historical_sessions WHERE id=?',[(int)$id])[0]??null;if(!$saved)alphano_fail('Sessione storica non trovata.',404);
  $session=['nome'=>$saved['title'],'location_label'=>$saved['location_label'],'datain'=>$saved['started_at'],'dataout'=>null,'source_name'=>$saved['source_name'],'creator'=>$saved['creator'],'header_present'=>$saved['header_present'],'source_sha256'=>$saved['source_sha256'],'masters'=>json_decode($saved['metadata_json'],true)['masters']??[]];
  $all=json_decode($saved['messages_json'],true,512,JSON_THROW_ON_ERROR);$messageTotal=count($all);$pages=max(1,(int)ceil($messageTotal/100));$page=min($page,$pages);$replay=array_slice($all,($page-1)*100,100);$historyId=(int)$id;
  require __DIR__.'/../templates/sessions/log.php';exit;
 }
 $query=$_GET['q']??'';$character=$_GET['character']??'';
 if(!is_string($query)||mb_strlen($query)>150||!is_string($character)||mb_strlen($character)>150)alphano_fail('Ricerca non valida.',400);
 $characterNames=[];foreach($db->query('SELECT participants_json FROM historical_sessions') as $row)foreach(json_decode($row['participants_json'],true,512,JSON_THROW_ON_ERROR) as $name)$characterNames[$name]=true;
 $characterNames=array_keys($characterNames);natcasesort($characterNames);
 $params=[];$conditions=[];
 if($query!==''){$conditions[]='(title LIKE ? OR location_label LIKE ? OR creator LIKE ? OR participants_json LIKE ?)';$params=array_fill(0,4,'%'.$query.'%');}
 if($character!==''){$conditions[]='JSON_CONTAINS(participants_json, ?)';$params[]=json_encode($character,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
 $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
 $total=(int)$db->query('SELECT COUNT(*) n FROM historical_sessions'.$where,$params)[0]['n'];$pages=max(1,(int)ceil($total/30));$page=min($page,$pages);
 $historyRows=$db->query('SELECT id,source_name,title,location_label,started_at,creator,header_present,message_count,participants_json FROM historical_sessions'.$where.' ORDER BY started_at IS NULL,started_at DESC,id DESC LIMIT 30 OFFSET '.(($page-1)*30),$params);
 require __DIR__.'/../templates/sessions/historical.php';exit;
}
