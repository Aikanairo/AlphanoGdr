<?php
declare(strict_types=1);
/** Small bounded chess opponent built on the same server-side rules as human games. */
function bg_computer_info(array $m):?array {
 $c=rpg_decode($m['terms_json'])['computer']??null;
 return (int)$m['b']<0&&is_array($c)&&-(int)$m['b']===(int)($c['npc_id']??0)?$c:null;
}
function bg_computer_npcs(string $chat):array {
 return array_map(static function($n){$p=rpg_decode($n['profile']);$image=(string)($p['portrait']??'');return ['id'=>(int)$n['id'],'name'=>$n['name'],'portrait'=>str_starts_with($image,'/')&&!str_starts_with($image,'//')?$image:'/assets/placeholder.svg'];},ConnectDB()->query('SELECT id,name,profile FROM ai_npcs WHERE chat_key=? AND active=1 ORDER BY name',[$chat]));
}
function bg_computer_evaluate(Onspli\Chess\FEN $fen):int {
 $score=0;$values=['p'=>100,'n'=>320,'b'=>330,'r'=>500,'q'=>900,'k'=>0];
 for($r=1;$r<=8;$r++)foreach(range('a','h') as $f){$p=$fen->get_square($f.$r);if($p==='')continue;$type=strtolower($p);$center=(int)(7-abs(ord($f)-100.5)-abs($r-4.5));$bonus=in_array($type,['n','b','p'],true)?$center*3:0;$score+=(ctype_upper($p)?1:-1)*($values[$type]+$bonus);}
 return ($fen->get_active_color()==='w'?1:-1)*$score;
}
function bg_computer_search(Onspli\Chess\FEN $fen,int $depth,int $alpha,int $beta,float $deadline):int {
 if(microtime(true)>$deadline)throw new RuntimeException('search-budget');
 $legal=$fen->get_legal_moves();if(!$legal)return $fen->is_check()?-100000-$depth:0;
 if(!$depth)return bg_computer_evaluate($fen);
 usort($legal,static fn($a,$b)=>(int)str_contains($b,'x')<=>(int)str_contains($a,'x'));
 foreach($legal as $san){$next=clone $fen;$next->move($san);$score=-bg_computer_search($next,$depth-1,-$beta,-$alpha,$deadline);$alpha=max($alpha,$score);if($alpha>=$beta)break;}return $alpha;
}
function bg_computer_choose(array $state,int $level=2):string {
 $fen=new Onspli\Chess\FEN($state['fen']);$legal=array_column($state['legal'],'id');if(!$legal)throw new RuntimeException('Nessuna mossa per il PNG.');
 $deadline=microtime(true)+.45;$best=$legal[0];$bestScore=-PHP_INT_MAX;
 // Every candidate gets a one-ply score before deeper analysis consumes the budget.
 $scores=[];foreach($legal as $san){if($scores&&microtime(true)>$deadline)break;$next=clone $fen;$next->move($san);$scores[$san]=$next->is_mate()?100000:-bg_computer_evaluate($next);}
 arsort($scores);$best=(string)array_key_first($scores);if(reset($scores)>=100000)return $best;
 if($level===1)return $best;
 foreach($scores as $san=>$initial){try{$next=clone $fen;$next->move($san);$score=-bg_computer_search($next,1,-200000,200000,$deadline);}catch(RuntimeException $e){if($e->getMessage()!=='search-budget')throw $e;break;}if($score>$bestScore){$bestScore=$score;$best=$san;}}
 return $best;
}
function bg_computer_finish(array &$m,int $winner,string $reason,bool $void):void {
 $d=ConnectDB();if($d->query('SELECT id FROM board_game_escrow WHERE game_id=? LIMIT 1',[$m['id']]))throw new RuntimeException('Poste non consentite per una partita di allenamento.');
 $now=time();$st=rpg_decode($m['state_json']);$key=$st['turn']===0?'clock_white':'clock_black';$m[$key]=max(0,(int)$m[$key]-max(0,bg_now()-(int)$m['turn_at']));
 $result=['computer'=>true,'winner'=>$winner,'reason'=>$reason,'duration'=>max(0,$now-(int)$m['started_at']),'plies'=>count(rpg_decode($m['moves_json'])),'prizes'=>[],'intelligence'=>[(int)$m['a']=>0],'elo'=>[(int)$m['a']=>0],'rated'=>false,'void'=>$void];
 $m['status']=$void?'cancelled':'finished';$m['winner']=$winner;$m['reason']=$reason;$m['finished_at']=$now;$m['result_json']=rpg_json($result);$m['version']++;
 $d->execute('UPDATE board_games SET status=?,winner=?,reason=?,finished_at=?,result_json=?,version=?,clock_white=?,clock_black=? WHERE id=?',[$m['status'],$winner,$reason,$now,$m['result_json'],$m['version'],$m['clock_white'],$m['clock_black'],$m['id']]);
 bg_audit((int)$m['id'],0,'computer-settled',$result);bg_notify($m,'Allenamento con il PNG concluso: '.$reason.'. Nessun premio e nessuna variazione ELO.');bg_public_notice($m,'Allenamento al tavolo #'.$m['id'].' concluso: '.$reason.'. Nessun premio o punto ELO.');
}
function bg_computer_reply(array &$m):void {
 $c=bg_computer_info($m);if(!$c||$m['status']!=='active')return;$state=rpg_decode($m['state_json']);$turn=$state['turn']===0?(int)$m['white_id']:((int)$m['white_id']===(int)$m['a']?(int)$m['b']:(int)$m['a']);
 if($turn!==(int)$m['b'])return;
 $move=bg_computer_choose($state,(int)$c['level']);bg_expire($m);if($m['status']==='active')bg_commit_move($m,(int)$m['b'],$move);
}
function bg_computer_start(int $uid,array $in):array {
 $d=ConnectDB();$chat=player_text($in,'chat',100,true);bg_actor($uid,$chat);$settings=bg_settings();$room=bg_room($chat);
 if(!$settings['enabled']||!$settings['scacchi']||!$room['scacchi'])throw new DomainException('Gli scacchi non sono abilitati in questo luogo.',403);
 $npcId=rpg_int($in['npc']??0,1,PHP_INT_MAX,'PNG');$npcs=array_column(bg_computer_npcs($chat),null,'id');$npc=$npcs[$npcId]??null;if(!$npc)throw new DomainException('Scegli un PNG presente in questo luogo.',422);
 $level=rpg_int($in['level']??2,1,2,'Difficoltà');$color=(string)($in['color']??'white');if(!in_array($color,['white','black','random'],true))throw new DomainException('Colore non valido.',422);
 $total=rpg_int($in['total']??$settings['default_total'],$settings['min_total'],$settings['max_total'],'Tempo totale');
 $request=player_text($in,'request_key',80,true);if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request))throw new DomainException('Identificativo non valido.',422);
 $signature=['npc'=>$npcId,'level'=>$level,'color'=>$color,'total'=>$total];
 return EconomiaAtomicaResiliente(function()use($d,$uid,$chat,$npc,$npcId,$level,$color,$total,$request,$signature,$settings){
  $d->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$uid]);
  if($old=$d->query('SELECT * FROM board_games WHERE a=? AND request_key=?',[$uid,$request])[0]??null){$audit=$d->query("SELECT details_json FROM board_game_audit WHERE game_id=? AND action='computer-start' ORDER BY id LIMIT 1",[$old['id']])[0]??null;if($old['chat_key']!==$chat||!$audit||rpg_decode($audit['details_json'])!==$signature)throw new DomainException('Identificativo già utilizzato con condizioni diverse.',409);return bg_view($old,$uid);}
  if($d->query("SELECT id FROM board_games WHERE status='active' AND (a=? OR b=?) LIMIT 1 FOR UPDATE",[$uid,$uid]))throw new DomainException('Hai già una partita attiva: concludila prima di allenarti.',409);
  if((int)$d->query('SELECT COUNT(*) n FROM board_games WHERE a=? AND created_at>?',[$uid,time()-300])[0]['n']>=5)throw new DomainException('Troppi inviti: attendi qualche minuto.',429);
  $b=-$npcId;$white=match($color){'white'=>$uid,'black'=>$b,default=>random_int(0,1)?$uid:$b};
  $terms=['total'=>$total,'per_move'=>0,'increment'=>0,'color'=>$color,'rated'=>false,'draw'=>true,'stakes'=>[$uid=>['coins'=>0,'assets'=>[]],$b=>['coins'=>0,'assets'=>[]]],'computer'=>['npc_id'=>$npcId,'name'=>$npc['name'],'portrait'=>$npc['portrait'],'level'=>$level]];
  $state=bg_engine('scacchi',[]);$d->execute("INSERT INTO board_games(game_key,chat_key,a,b,proposer,status,terms_json,state_json,moves_json,white_id,clock_white,clock_black,turn_at,created_at,started_at,result_json,a_net,request_key) VALUES('scacchi',?,?,?,?,'active',?,?,'[]',?,?,?,?,?,?,'{}',?,?)",[$chat,$uid,$b,$uid,rpg_json($terms),rpg_json($state),$white,$total*1000,$total*1000,bg_now(),time(),time(),hash('sha256',$_SERVER['REMOTE_ADDR']??''),$request]);
  $m=$d->query('SELECT * FROM board_games WHERE id=?',[(int)$d->lastInsertId()])[0];bg_audit((int)$m['id'],$uid,'computer-start',$signature);bg_public_notice($m,'Inizia un allenamento a scacchi tra '.bg_actor($uid,$chat)['nome'].' e '.$npc['name'].' (PNG · computer). Nessuna posta, ricompensa o ELO.');bg_computer_reply($m);return bg_view($m,$uid);
 });
}
