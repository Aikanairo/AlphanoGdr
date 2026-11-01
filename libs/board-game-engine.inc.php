<?php
declare(strict_types=1);
spl_autoload_register(static function($class){$prefix='Onspli\\Chess\\';if(str_starts_with($class,$prefix)){ $file=dirname(__DIR__).'/third_party/onspli-chess/src/'.substr($class,strlen($prefix)).'.php';if(is_file($file))require_once $file; }});
function bg_dead(array $board):bool {
 $pieces=array_filter($board,static fn($p)=>$p!==''&&strtolower($p)!=='k');if(!$pieces)return true;
 if(count($pieces)===1&&in_array(strtolower(array_values($pieces)[0]),['b','n'],true))return true;
 $colors=[];foreach($pieces as $sq=>$p){if(strtolower($p)!=='b')return false;$colors[]=(ord($sq[0])+intval($sq[1]))%2;}return count(array_unique($colors))===1;
}
function bg_engine(string $game,array $moves):array {
 if(count($moves)>512)throw new DomainException('Limite di mosse raggiunto.',409);
 if($game==='dama'){require_once __DIR__.'/draughts-engine.inc.php';return ad_engine($moves);}
 if($game!=='scacchi')throw new DomainException('Gioco non valido.',422);
 $fen=new Onspli\Chess\FEN();$history=[$fen->export_short()];foreach($moves as $san){if(!in_array($san,$fen->get_legal_moves(),true))throw new DomainException('Mossa non consentita.',422);$fen->move($san);$history[]=$fen->export_short();}
 $board=[];for($r=1;$r<=8;$r++)foreach(range('a','h') as $f)$board[$f.$r]=$fen->get_square($f.$r);
 $legal=[];foreach($fen->get_legal_moves() as $san){$after=clone $fen;$after->move($san);$move=new Onspli\Chess\Move($san);$to=$move->get_target();$from='';if($move->get_castling()){$from=$fen->get_active_color()==='w'?'e1':'e8';$to=($move->get_castling()==='O-O'?'g':'c').($fen->get_active_color()==='w'?'1':'8');}else{foreach($board as $sq=>$piece)if($piece!==''&&strtolower($piece)===strtolower($move->get_piece_type())&&($fen->get_active_color()==='w'?ctype_upper($piece):ctype_lower($piece))&&$after->get_square($sq)!==$piece&&$sq!==$to){$from=$sq;break;}}$legal[]=['id'=>$san,'from'=>$from,'to'=>$to,'promotion'=>$move->get_promotion(),'path'=>[$from,$to]];}
 $result=$fen->is_mate()?($fen->get_active_color()==='w'?'black':'white'):null;
 if(!$result&&($fen->is_stalemate()||$fen->is_fifty_move()||bg_dead($board)||count(array_filter($history,static fn($p)=>$p===$fen->export_short()))>=3))$result='draw';
 return ['fen'=>$fen->export(),'board'=>$board,'turn'=>$fen->get_active_color()==='w'?0:1,'check'=>$fen->is_check(),'legal'=>$legal,'result'=>$result];
}
