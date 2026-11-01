<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    alphano_fail('Metodo non consentito', 405, true);
}
echo json_encode(['csrfToken' => alphano_csrf_token()]);
