<?php
declare(strict_types=1);
/** Italian draughts, 32-square board. Pure PHP: no executable or Python runtime. */
function ad_coords(int $n):array{$r=intdiv($n-1,4);return [$r,(($n-1)%4)*2+($r%2===0?1:0)];}
function ad_square(int $r,int $c):?int{return $r<0||$r>7||$c<0||$c>7||($r+$c)%2===0?null:$r*4+intdiv($c,2)+1;}
function ad_white(string $p):bool{return strtolower($p)==='w';}
function ad_captures(array $board,int $square,string $piece,array $path=[],array $taken=[],array $kings=[]):array{
 $path=$path?:[$square];[$r,$c]=ad_coords($square);$out=[];$king=ctype_upper($piece);$directions=$king?[-1,1]:(ad_white($piece)?[-1]:[1]);
 foreach($directions as $dr)foreach([-1,1] as $dc){$over=ad_square($r+$dr,$c+$dc);$to=ad_square($r+2*$dr,$c+2*$dc);if(!$over||!$to||!isset($board[$over])||isset($board[$to])||in_array($over,$taken,true)||ad_white($board[$over])===ad_white($piece)||(!$king&&ctype_upper($board[$over])))continue;
  // Captured pieces remain blockers until the complete capture sequence ends.
  $next=$board;unset($next[$square]);$next[$to]=$piece;$newPath=[...$path,$to];$newTaken=[...$taken,$over];$newKings=[...$kings,ctype_upper($board[$over])?1:0];
  $children=ad_captures($next,$to,$piece,$newPath,$newTaken,$newKings);if($children)$out=array_merge($out,$children);else $out[]=['path'=>$newPath,'taken'=>$newTaken,'kings'=>$newKings,'king'=>$king];
 }
 return $out;
}
function ad_legal(array $board,bool $white):array{
 $captures=[];$steps=[];foreach($board as $square=>$piece){if(ad_white($piece)!==$white)continue;$captures=array_merge($captures,ad_captures($board,(int)$square,$piece));[$r,$c]=ad_coords((int)$square);foreach(ctype_upper($piece)?[-1,1]:($white?[-1]:[1]) as $dr)foreach([-1,1] as $dc){$to=ad_square($r+$dr,$c+$dc);if($to&&!isset($board[$to]))$steps[]=['path'=>[(int)$square,$to],'taken'=>[]];}}
 if(!$captures)return $steps;
 // Mandatory priorities: most pieces, king over man, most kings, earliest king.
 $rank=static function($m){$first=array_search(1,$m['kings'],true);return [count($m['taken']),(int)$m['king'],array_sum($m['kings']),$first===false?-99:-$first];};
 $best=max(array_map($rank,$captures));return array_values(array_filter($captures,static fn($m)=>$rank($m)===$best));
}
function ad_fen(array $board,bool $white):string{$groups=['w'=>[],'b'=>[]];ksort($board);foreach($board as $n=>$p)$groups[strtolower($p)][]=(ctype_upper($p)?'K':'').$n;return ($white?'W':'B').':W'.implode(',',$groups['w']).':B'.implode(',',$groups['b']);}
function ad_engine(array $moves):array{
 $board=[];for($i=1;$i<=12;$i++)$board[$i]='b';for($i=21;$i<=32;$i++)$board[$i]='w';$white=true;$quiet=0;$history=[ad_fen($board,true)];
 foreach($moves as $text){$legal=ad_legal($board,$white);$chosen=null;foreach($legal as $move){$key=implode($move['taken']?'x':'-',$move['path']);if($key===$text){$chosen=$move;break;}}
  if(!$chosen)throw new DomainException('Mossa non consentita dalla Dama italiana.',422);$from=$chosen['path'][0];$to=end($chosen['path']);$piece=$board[$from];unset($board[$from]);foreach($chosen['taken'] as $n)unset($board[$n]);$quiet=$chosen['taken']||ctype_lower($piece)?0:$quiet+1;
  if(($piece==='w'&&$to<=4)||($piece==='b'&&$to>=29))$piece=strtoupper($piece);$board[$to]=$piece;$white=!$white;$history[]=ad_fen($board,$white);
 }
 $fen=ad_fen($board,$white);$legal=ad_legal($board,$white);$result=!$legal?($white?'black':'white'):($quiet>=80||count(array_filter($history,static fn($f)=>$f===$fen))>=3?'draw':null);$out=[];foreach($legal as $move){$path=array_map('strval',$move['path']);$out[]=['id'=>implode($move['taken']?'x':'-',$path),'from'=>$path[0],'to'=>end($path),'path'=>$path];}
 return ['fen'=>$fen,'board'=>(object)$board,'turn'=>$white?0:1,'legal'=>$out,'result'=>$result];
}
