<?php
/** Compatibilità degli include storici: il controller è comune alle tre città. */
require_once __DIR__ . '/../pvt/private-core.inc.php';
if (!preg_match('~^/chat/([a-z0-9_-]+)/stanze/[^/]+\.php$~', $_SERVER['SCRIPT_NAME'] ?? '', $route)) { http_response_code(404); exit; }
alphano_private_dispatch('stanze',$route[1],'pvt');
