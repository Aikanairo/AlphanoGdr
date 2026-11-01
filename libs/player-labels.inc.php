<?php
declare(strict_types=1);
/** One server clock and one inclusive/exclusive boundary for the 48-hour welcome. */
function alphano_registration_label(int $registered,?int $now=null):array {
    $now??=time();$new=$registered>0&&$registered<=$now&&$now-$registered<172800;
    return ['registered_at'=>$registered,'is_new'=>$new,'new_until'=>$new?$registered+172800:0];
}
