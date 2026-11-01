<?php
/** Compatibilità degli include storici: il controller usa le destinazioni configurate. */
require_once __DIR__ . '/../pvt/private-core.inc.php';
if (!preg_match('~^/chat/([a-z0-9_-]+)/dimore/[^/]+\.php$~', $_SERVER['SCRIPT_NAME'] ?? '', $route)) { http_response_code(404); exit; }
alphano_private_dispatch('dimore',$route[1],'addLchat');
