<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/kernel.inc.php';
require_once dirname(__DIR__).'/libs/public-guides.inc.php';
require_once dirname(__DIR__).'/libs/rpg.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$legal=require dirname(__DIR__).'/config/legal.php';
echo json_encode(['policyVersion'=>$legal['version'],...alphano_registration_guides(),'creation'=>rpg_creation_catalog()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
