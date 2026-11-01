<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/group-hierarchy.inc.php';
alphano_require_login();
$rawId = $_GET['org'] ?? '';
if (!is_string($rawId) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) alphano_fail('Organizzazione non specificata.', 400);
require_once __DIR__.'/../libs/group-media.inc.php';
$hierarchy = alphano_group_hierarchy('organizzazioni', (int)$rawId, alphano_user());
$hierarchy['header_image']=group_header_image('organizzazioni',(int)$rawId);
require __DIR__ . '/../templates/groups/hierarchy.php';
ClosePage();
