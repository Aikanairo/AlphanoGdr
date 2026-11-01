<?php
declare(strict_types=1);
require_once __DIR__ . '/libs/kernel.inc.php';
require_once __DIR__ . '/libs/libpg.inc.php';
$idpg = alphano_require_login();
$initialView = $_GET['view'] ?? '';
if (!is_string($initialView) || ($initialView !== '' && (!str_starts_with($initialView, '/') || str_starts_with($initialView, '//') || (preg_match('/[[:cntrl:] ]/', $initialView) || str_contains($initialView, chr(92))) || parse_url($initialView, PHP_URL_PATH) === '/main.php'))) $initialView = '';
define('ALPHANO_SHELL', true);
require __DIR__ . '/templates/shell.php';
