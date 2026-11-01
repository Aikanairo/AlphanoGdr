<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/city-directory.inc.php';
alphano_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    alphano_fail('Metodo non consentito.', 405);
}
$rawId = $_GET['id'] ?? '0';
$section = $_GET['sezione'] ?? 'araldiche';
if (!is_scalar($rawId) || !is_string($section)
    || filter_var((string)$rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
    || !in_array($section, ['statuto', 'araldiche', 'cittadini'], true)) {
    alphano_fail('Città o sezione non valida.', 400);
}
$cityDirectory = alphano_city_directory(alphano_user(), (int)$rawId);
if ((int)$rawId > 0 && $cityDirectory['selected'] === null) alphano_fail('Città non trovata.', 404);
require __DIR__ . '/../templates/cities/index.php';
ClosePage();
