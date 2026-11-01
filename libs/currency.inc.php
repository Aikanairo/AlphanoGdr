<?php
declare(strict_types=1);
/** Display terminology only: persisted money amounts and protocol keys stay unchanged. */
function alphano_currency_name(): string
{
    $value=function_exists('alphano_config')?alphano_config('currency_name'):(require dirname(__DIR__).'/config/app.php')['currency_name'];
    return is_string($value)&&trim($value)!==''?$value:'Corone';
}
function alphano_currency_text(string $text): string
{
    return preg_replace_callback('/\bcorone\b/iu',static fn()=>alphano_currency_name(),$text);
}
/** Replace only the authored label, without escaping surrounding trusted markup. */
function alphano_currency_html(string $text='Corone'): string
{
    return preg_replace_callback('/\bcorone\b/iu',static fn()=>htmlspecialchars(alphano_currency_name(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$text);
}
