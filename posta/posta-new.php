<?php
declare(strict_types=1);

// Compatibility route: one mailbox and one message composer own every operation.
require_once __DIR__ . '/../libs/kernel.inc.php';
alphano_require_login();
foreach (array_merge($_GET, $_POST) as $value) {
    if (!is_scalar($value)) alphano_fail('Parametri della posta non validi.', 400);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    alphano_require_csrf();
    $action = (string)($_POST['azione'] ?? '');
    if ($action === 'forward') {
        $_POST['send'] = '1';
        require __DIR__ . '/writeto.php';
        exit;
    }
    if (in_array($action, ['delete0', 'delete1', 'move'], true)) {
        require __DIR__ . '/deletesel.php';
        exit;
    }
    require __DIR__ . '/index.php';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, POST');
    alphano_fail('Metodo non consentito.', 405);
}
$query = http_build_query($_GET);
header('Location: /posta/' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
