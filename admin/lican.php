<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/lycan.inc.php';
$id=alphano_require_login();$state=alphano_lycan_sync($id);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    alphano_require_csrf();
    try {
        $form=$state['canChoose']?($state['form']==='wolf'?'human':'wolf'):'wolf';
        alphano_lycan_sync($id,$form);
        header('Location: /admin/lican.php',true,303);exit;
    } catch (DomainException $e) {alphano_fail($e->getMessage(),$e->getCode());}
}
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>La metamorfosi</title></head><body>
<header class="ui-page-heading" data-page-heading><span data-icon="moon" aria-hidden="true"></span><div><h1>La metamorfosi</h1><p>Il richiamo della stirpe.</p></div></header>
<section class="ui-surface"><h2><?= $state['form']==='wolf'?'Forma lupina':'Forma umana' ?></h2><p>Fase corrente: <strong><?=htmlspecialchars($state['phase'],ENT_QUOTES)?></strong></p>
<?php if(!$state['eligible']): ?><p>Questa metamorfosi è riservata ai licantropi viventi.</p>
<?php else: ?><p><?=htmlspecialchars($state['description'],ENT_QUOTES)?></p>
<?php if($state['canChoose']): ?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(alphano_csrf_token(),ENT_QUOTES)?>"><input type="hidden" name="azione" value="trasforma"><button type="submit" data-icon="moon"><?=htmlspecialchars($state['label'],ENT_QUOTES)?></button></form>
<?php elseif($state['fullMoon']): ?><p>La trasformazione automatica è attiva. Non puoi interromperla prima della fine della Luna Piena.</p>
<?php else: ?><p>La forma lupina tornerà automaticamente nei giorni di Luna Piena.</p><?php endif; ?>
<?php endif; ?></section></body></html>
