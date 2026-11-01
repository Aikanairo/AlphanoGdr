<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/kernel.inc.php';
require_once __DIR__ . '/../../libs/libchat.inc.php';

header('Content-Type: application/json');
$chat = $_GET['c'] ?? '';
$data = GetListaUmori();           // da libchat.inc.php
// restituisci solo id + nome
$out = array_map(fn($u)=>[
  'id'=>(int)$u['id'],'nome'=>$u['nome']
], $data);
echo json_encode($out);
