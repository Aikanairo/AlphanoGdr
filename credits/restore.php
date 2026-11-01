<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!is_file($root.'/config/local.php')){
 require_once $root.'/libs/status-page.inc.php';http_response_code(503);
 alphano_status_page('Ripristino prima dell’installazione','Non esiste ancora un account del gestore con cui autenticare il ripristino. Ricarica dal pacchetto originale i componenti dei crediti indicati nella documentazione, poi riapri l’installatore. Nessun database è stato modificato.','https://github.com/Aikanairo/AlphanoGdr','Apri il pacchetto del progetto');exit;
}
require_once $root.'/libs/kernel.inc.php';
require_once $root.'/libs/credits-guard.inc.php';
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
$user=alphano_user();if(isset($_GET['login']))unset($_SESSION['alphano_login_error']);$error='';$restored=$_SESSION['credits_restored']??[];unset($_SESSION['credits_restored']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 alphano_require_csrf();
 if(!$user||empty($user['admin']))alphano_fail('Il ripristino richiede un account amministratore completo.',403);
 if(($_POST['confirm_restore']??'')!=='1'){$error='Conferma l’inserimento dei crediti nelle pagine indicate.';http_response_code(422);}
 else{try{$_SESSION['credits_restored']=alphano_credits_restore();header('Location: /credits/restore.php?restored=1',true,303);exit;}catch(Throwable $e){http_response_code(422);$error=$e instanceof DomainException?$e->getMessage():'Ripristino non riuscito: ricarica il pacchetto originale.';error_log('[Credits recovery] '.$e->getMessage());}}
}
$issues=alphano_credits_issues();$csrf=alphano_csrf_token();
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Ripristina i crediti · AlphanoGdr</title></head><body class="setup setup-install"><main class="setup-wrap"><header data-page-heading><h1>Ripristina i crediti</h1><p>Controllo locale incluso nel pacchetto AGPL-3.0. Puoi modificarlo o rimuoverlo secondo la licenza; il blocco tecnico non costituisce un accertamento di violazione.</p></header>
<?php if($error):?><p class="error" role="alert"><?=alphano_escape($error)?></p><?php endif;?>
<?php if(isset($_GET['login'])):?><p class="error" role="alert">Accesso non riuscito. Controlla personaggio e password del gestore.</p><?php endif;?>
<?php if(!$user):?><section><h2>Accedi come gestore</h2><p>Solo un amministratore completo può attivare il ripristino dei crediti. La password non viene conservata nel riepilogo.</p><form method="post" action="/checkuser.php"><input type="hidden" name="_csrf" value="<?=$csrf?>"><input type="hidden" name="credit_recovery" value="1"><label>Personaggio<input name="username" autocomplete="username" maxlength="20" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button>Accedi al ripristino</button></form></section>
<?php elseif(empty($user['admin'])):?><p class="error" role="alert">L’account corrente non è un amministratore completo. Chiedi al gestore di effettuare il ripristino.</p>
<?php elseif($issues):?><section><h2>Pagine da integrare</h2><ul class="credits-recovery-list"><?php foreach($issues as $path):?><li><?=alphano_escape(['home'=>'Homepage','equipment'=>'Equipaggiamento','legal'=>'Informazioni legali'][$path]??$path)?></li><?php endforeach;?></ul><p>Il ripristino attiva un credito centrale visibile nelle pagine indicate, solo se manca quello personalizzato. Non riscrive file PHP, HTML o CSS e conserva tutte le personalizzazioni. Salva privatamente lo stato precedente del ripristino; database, account, upload e credenziali restano invariati.</p><form method="post"><input type="hidden" name="_csrf" value="<?=$csrf?>"><label class="runtime-consent"><input type="checkbox" name="confirm_restore" value="1" required><span>Confermo l’inserimento automatico dei crediti mancanti, conservando il mio layout.</span></label><button>Ripristina i crediti</button></form></section>
<?php else:?><section class="notice" role="status"><h2>Crediti disponibili · Accesso disponibile</h2><p><?=$restored?'Ripristino attivato. Visita le pagine: il credito viene integrato senza sostituire il tuo codice.':'Non risultano crediti mancanti nelle pagine verificate. Le modifiche al codice e alla grafica sono consentite.'?></p></section><nav class="credits-recovery-actions"><a class="button" href="/?preview=1">Vai alla homepage</a><a class="button secondary" href="/admin/setup.php">Pannello del gestore</a></nav><?php endif;?>
<p><a href="/install/license.php">Leggi la licenza</a> · <a href="https://gothik.it/" target="_blank" rel="noopener noreferrer">Gothik.it</a></p></main></body></html>
