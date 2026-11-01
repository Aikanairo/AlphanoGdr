<?php
declare(strict_types=1);
/** MySQL 8 named locks accept at most 64 characters, including the namespace. */
function alphano_lock_name(string $scope,string $identity,string $database): string {
    $prefix=preg_replace('/[^a-z0-9]/i','',$scope);
    return 'alphano:'.substr($prefix,0,10).':'.substr(hash('sha256',$scope."\0".$database."\0".$identity),0,44);
}
