<?php
declare(strict_types=1);
require_once __DIR__.'/appearance.inc.php';
require_once __DIR__.'/theme-bundle.inc.php';
require_once __DIR__.'/branding.inc.php';
require_once __DIR__.'/management.inc.php';

/** Identifica il layout dal controller richiesto, anche per i wrapper storici. */
function alphano_view_id(?string $path = null): string
{
    $path = $path ?? parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH);
    if ($path === '/profili/modifyprofile.php') $path = '/profili/modifyuser.php';
    if ($path === '/' || $path === '/index.html' || $path === '/index.php') return 'homepage';
    if(preg_match('#^/chat/([a-z0-9_-]+)/bacheche/(index|showbacheca|showthread|modify|write|showstorico)\.php$#',$path))return 'bacheche-index';
    if ($path==='/citta/assemblea.php'||preg_match('#^/chat/([a-z0-9_-]+)/assemblea\.php$#', $path)) return 'city-assembly';
    if (preg_match('#^/chat/([a-z0-9_-]+)/(pvt|stanze|dimore)/#', $path)) return 'private-room';
    if (preg_match('#^/chat/([a-z0-9_-]+)/index\.php$#', $path) || $path === '/mappa.php' || $path === '/api/mappa.php') return 'map';
    return trim(preg_replace('/[^a-z0-9]+/i', '-', preg_replace('/\.(php|html)$/', '', $path)), '-');
}

function alphano_theme_assets(): string
{
    $version = alphano_theme_version();
    $ui = json_encode(require dirname(__DIR__) . '/config/ui.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return alphano_favicon_links().'<meta name="alphano-currency" content="'.alphano_escape(alphano_currency_name()).'"><script src="/assets/currency.js?v='.filemtime(dirname(__DIR__).'/assets/currency.js').'"></script><script src="/assets/fallback.js?v='.filemtime(dirname(__DIR__).'/assets/fallback.js').'" defer></script>'.'<script src="/assets/appearance.js?v='.filemtime(dirname(__DIR__).'/assets/appearance.js').'" defer></script>'.'<script type="application/json" id="alphano-ui-config">' . $ui . '</script>'
        . '<script src="/js/page-heading.js?v=' . filemtime(dirname(__DIR__) . '/js/page-heading.js') . '" defer></script>'
        . '<script src="/js/ui.js?v=' . filemtime(dirname(__DIR__) . '/js/ui.js') . '" defer></script>'
        . '<script src="/js/mobile-game.js?v=' . filemtime(dirname(__DIR__) . '/js/mobile-game.js') . '" defer></script>'
        . '<script src="/js/experience.js?v=' . filemtime(dirname(__DIR__) . '/js/experience.js') . '" defer></script>'
        . '<script src="/js/push-notifications.js?v=' . max(filemtime(dirname(__DIR__) . '/js/push-notifications.js'), filemtime(dirname(__DIR__) . '/js/push-client.js')) . '" defer></script>'
        . '<script src="/js/privacy.js?v=' . filemtime(dirname(__DIR__) . '/js/privacy.js') . '" defer></script>'
        . '<script src="/js/activity-ui.js?v=' . filemtime(dirname(__DIR__) . '/js/activity-ui.js') . '" defer></script>'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">'
        . '<link rel="stylesheet" href="/assets/theme.php?v=' . $version . '" data-alphano-theme>';
}

/** Tutte le viste HTML condividono lo stesso ingresso CSS; JSON e PDF restano autonomi. */
function alphano_theme_document(string $html): string
{
    // Ogni modulo usa la revisione corrente dei propri script, anche nei vecchi iframe.
    $html = preg_replace_callback('~(<script\b[^>]*\bsrc=)(["\x27])(/[^"\x27]+\.js(?:\?[^"\x27]*)?)\2~i', static function ($match) {
        $url = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $file = realpath(dirname(__DIR__) . $path);
        if (!$file || !str_starts_with($file, dirname(__DIR__) . '/') || !is_file($file)) return $match[0];
        parse_str($parts['query'] ?? '', $query);
        $query['v'] = filemtime($file);
        $versioned = $path . '?' . http_build_query($query);
        return $match[1] . $match[2] . htmlspecialchars($versioned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $match[2];
    }, $html);
    if (stripos($html, '<body') !== false) {
        $view = alphano_view_id();
        $manifest = require dirname(__DIR__) . '/config/views.php';
        $styles = $manifest[$view] ?? '';
        $page=alphano_appearance_page();$style='';
        foreach(['background','header'] as $kind)if($page[$kind]!=='')$style.='--custom-'.$kind.':url("'.alphano_escape($page[$kind]).'");';
        $attributes=' data-palette="'.alphano_escape(alphano_config('theme')).'" data-sidebar="'.alphano_escape(alphano_config('sidebar_position')).'" data-header="'.alphano_escape(alphano_config('header_position')).'"';
        foreach(['background','header'] as $kind)if($page[$kind]!=='')$attributes.=' data-custom-'.$kind.'="1"';
        // A dedicated style element avoids duplicate style attributes on authored bodies.
        if($style!=='')$html=preg_replace('~</head>~i','<style id="alphano-page-appearance">body[data-palette]{'.$style.'}</style></head>',$html,1);
        $html=preg_replace('/<body\b/i','<body'.$attributes,$html,1);
        $html = preg_replace_callback('/<body\b([^>]*)>/i', static function ($match) use ($view, $styles) {
            return str_contains($match[1], 'data-view=') ? $match[0] : '<body data-view="' . $view . '" data-styles="' . $styles . '"' . $match[1] . '>';
        }, $html, 1);
    }
    if (!str_contains($html, 'data-alphano-theme')) {
        $html = preg_replace('/<meta\b(?=[^>]*name=["\x27]viewport["\x27])[^>]*>/i', '', $html);
        $assets = alphano_theme_assets();
        if (stripos($html, '</head>') !== false) $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);
        else $html = $assets . $html;
    }
    return alphano_management_document(alphano_management_brand($html));
}

/** Legge le giocate storiche come contenuto, escludendo cornici e stili incorporati. */
function alphano_archive_content(string $html): string
{
    require_once __DIR__ . '/libtext.inc.php';
    $html = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
    $html = preg_replace('/<!doctype[^>]*>|<\/?(?:html|body)\b[^>]*>|<link\b[^>]*>/is', '', $html);
    return ProcessText($html);
}

/** Instrada i documenti prima del controller: niente doppie visite o altri effetti GET. */
function alphano_route_document(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $path = parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH);
    $public = ['/credits/restore.php','/install/index.php', '/install/complete.php', '/', '/index.html', '/index.php', '/main.php', '/checkuser.php', '/adduser.php', '/logout.php', '/slogin.php', '/control.php', '/recuperopasswd.php', '/recuperopg.php', '/regole/pdf.php', '/chat/messages.php', '/chat/recordings.php', '/chat/llama3.php'];
    if (in_array($path, $public, true) || str_starts_with($path, '/manuali/') || str_starts_with($path, '/demo/') || str_starts_with($path, '/api/') || !alphano_user()) return;
    header('Location: /main.php?view=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/mappa.php'), true, 303);
    exit;
}
