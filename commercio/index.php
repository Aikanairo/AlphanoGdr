<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/commerce-thread.inc.php';
$uid=alphano_require_login();$id=CommercioIntero($_GET['id']??null,1,2147483647);
if(!$id)alphano_fail('Trattativa non valida.',400);
try{alphano_trade_thread($id,$uid);}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trattativa #<?=$id?> · Alphano</title><script type="module" src="/js/commerce-thread.js"></script></head><body class="ui-page"><main class="commerce-thread-page"><section data-commerce-thread="<?=$id?>" aria-label="Trattativa commerciale">Caricamento della trattativa…</section></main></body></html>
<?php ClosePage(); ?>
