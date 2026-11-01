<?php
declare(strict_types=1);
require_once __DIR__.'/lycan.inc.php';
/** Use the exact midnight phase boundaries consumed by the lunar synchronizer, including DST. */
function alphano_next_lunar_boundary(int $now,bool $full):?int {
 for($day=1;$day<=62;$day++){$at=strtotime('+'.$day.' days',strtotime('today',$now));if((FaseLunare($at)==='Luna Piena')===$full)return $at;}
 return null;
}
function alphano_metamorphosis(array $user,?int $now=null):?array {
 $now??=time();if((int)$user['puntif']<=0||!empty($user['bloccato']))return null;
 $pending=ConnectDB()->query("SELECT ready_at FROM lineage_members WHERE player_id=? AND kind='vampire' AND status='pending'",[(int)$user['id']])[0]??null;
 if($pending)return ['kind'=>'vampire','label'=>'Trasformazione programmata','detail'=>'Trasformazione in vampiro tra','deadline'=>(int)$pending['ready_at'],'server_time'=>$now,'due'=>'La trasformazione è imminente…'];
 $state=alphano_lycan_state($user,$now);
 if(!$state['eligible']||$state['ancestral'])return null;
 $wolf=$state['fullMoon'];$deadline=alphano_next_lunar_boundary($now,!$wolf);
 return ['kind'=>'lycan','label'=>'Trasformazione periodica','detail'=>$wolf?'Ritorno alla forma umana tra':'Trasformazione alla Luna Piena tra','deadline'=>$deadline,'server_time'=>$now,'due'=>'La metamorfosi è imminente…'];
}
