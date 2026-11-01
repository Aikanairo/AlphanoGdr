<?php
require __DIR__.'/bootstrap.php';


$idpg = GetIDPG(true);

db()->execute(
  'INSERT INTO logs_ingressi (data, pg, ip, host)
        VALUES (?, ?, ?, ?)',
  [ time(), $idpg,
    $_SERVER['REMOTE_ADDR'] ?? '',
    gethostbyaddr($_SERVER['REMOTE_ADDR'] ?? '') ]
);

ok(['ok' => true]);
