<?php
declare(strict_types=1);
require_once __DIR__.'/branding.inc.php';

/** Standalone feedback also works before configuration, sessions and database are available. */
function alphano_status_document(string $title, string $message, string $action = '/', string $actionLabel = 'Torna alla home', ?array $form = null): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $palette = 'blue';
    foreach (['settings', 'appearance'] as $file) {
        $path = dirname(__DIR__).'/private/'.$file.'.json';
        $settings = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        if (is_array($settings) && in_array($settings['theme'] ?? '', ['blue','black','brown','red','purple'], true)) $palette = $settings['theme'];
    }
    $formHtml = '';
    if ($form !== null) {
        $formHtml = '<form class="runtime-setup-form" method="post" action="'.$escape($form['action']).'"><input type="hidden" name="_csrf" value="'.$escape($form['csrf']).'"><label class="runtime-consent"><input type="checkbox" name="'.$escape($form['checkbox']).'" value="1" required><span>'.$escape($form['consent']).'</span></label><button type="submit">'.$escape($form['button']).'</button></form>';
    }
    $version = substr(hash_file('sha256', dirname(__DIR__).'/assets/portal.css'), 0, 12);
    return '<!doctype html><html lang="it"><head>'.alphano_favicon_links().'<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$escape($title).' · AlphanoGdr</title><link rel="stylesheet" href="/assets/engine.css"><link rel="stylesheet" href="/assets/portal.css?v='.$version.'"></head><body class="portal account-recovery-page" data-palette="'.$palette.'"><a class="account-recovery-brand" href="/">AlphanoGdr<span>Il tuo mondo, una storia condivisa</span></a><main class="account-recovery-card" aria-labelledby="status-title"><header class="account-recovery-heading" data-page-heading><div class="ui-eyebrow">AlphanoGdr · Informazioni</div><h1 id="status-title">'.$escape($title).'</h1><p>Ti aiutiamo a continuare.</p></header><div class="account-recovery"><p class="account-recovery-message" role="alert">'.$escape($message).'</p>'.$formHtml.'<nav class="account-recovery-actions"><a class="button status-action" href="'.$escape($action).'">'.$escape($actionLabel).'</a></nav></div></main></body></html>';
}

function alphano_status_page(string $title,string $message,string $action='/',string $actionLabel='Torna alla home',?array $form=null):void
{echo alphano_status_document($title,$message,$action,$actionLabel,$form);}
