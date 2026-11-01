<?php
declare(strict_types=1);

/** Technical compatibility is distinct from the recommended security patch level. */
function alphano_php_supported(?string $version = null): bool
{
    $version ??= PHP_VERSION;
    return version_compare($version, '8.4.0', '>=')
        && version_compare($version, '9.0.0', '<')
        && !preg_match('/(?:alpha|beta|RC|dev)/i', $version);
}
function alphano_php_patched(?string $version = null): bool
{
    $version ??= PHP_VERSION;
    if (!alphano_php_supported($version) || version_compare($version, '8.4.27', '<')) return false;
    return !(version_compare($version, '8.5.0', '>=') && version_compare($version, '8.5.12', '<'));
}
function alphano_runtime_warning(?string $version = null): string
{
    $version ??= PHP_VERSION;
    if (!alphano_php_supported($version) || alphano_php_patched($version)) return '';
    return 'Puoi continuare l’installazione con PHP '.$version.'. Ti consigliamo di aggiornare a PHP 8.4.27 o successivo (sul ramo 8.5: almeno 8.5.12), che include le correzioni PHP2Shell. La versione rilevata potrebbe non includerle: verifica con il provider eventuali patch già applicate. L’installazione non aggiorna PHP e non corregge le vulnerabilità del server.';
}
function alphano_runtime_require(): void
{
    if (alphano_php_supported()) return;
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    require_once __DIR__.'/runtime-setup.inc.php';
    alphano_runtime_setup(dirname(__DIR__));
}
