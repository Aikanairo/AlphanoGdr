<?php
require_once dirname(__DIR__).'/libs/kernel.inc.php';
alphano_require_login();if(!alphano_can('admin'))alphano_fail('Accesso riservato.',403);header('Location: /admin/world.php',true,303);exit;
