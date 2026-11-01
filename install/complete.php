<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!is_file($root.'/config/local.php')){header('Location: /install/',true,303);exit;}
require_once $root.'/libs/setup.inc.php';
alphano_require_login();
$user=alphano_user();
if(empty($user['admin']))alphano_fail('Riepilogo riservato al gestore del gioco.',403);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');alphano_fail('Apri il riepilogo senza inviare nuovamente il modulo.',405);}
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$settings=require $root.'/config/app.php';
$steps=alphano_setup_status();
$pending=count(array_filter($steps,static fn($status)=>$status==='pending'));
$nextText=$pending===1?'Resta una sezione facoltativa da configurare. Puoi completarla, saltarla o riprenderla quando vuoi dal pannello del gestore.':($pending>1?'Restano '.$pending.' sezioni facoltative da configurare. Puoi completarle, saltarle o riprenderle quando vuoi dal pannello del gestore.':'Puoi modificare le impostazioni e consultare il riepilogo dal pannello del gestore.');
$mail=['disabled'=>'Da configurare','log'=>'Modalità di prova','smtp'=>'SMTP configurato','mail'=>'Email del provider'];
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Installazione completata · <?=alphano_escape($settings['game_name'])?></title><script src="/assets/install-complete.js?v=<?=substr(hash_file('sha256',$root.'/assets/install-complete.js'),0,12)?>" defer></script><noscript><meta http-equiv="refresh" content="15;url=/?preview=1"></noscript></head>
<body class="setup setup-install"><main class="setup-wrap setup-complete" data-install-complete>
<header class="setup-complete-heading" data-page-heading><span class="setup-success-mark" aria-hidden="true">✓</span><p class="eyebrow">AlphanoGdr <?=alphano_escape(trim(file_get_contents($root.'/VERSION')))?> · Tutto pronto</p><h1>Installazione completata</h1><p role="status">Il motore è stato installato correttamente. Il tuo mondo può cominciare.</p></header>
<section class="setup-complete-summary" aria-labelledby="completion-summary-title"><h2 id="completion-summary-title">Il riepilogo del tuo gioco</h2><dl><div><dt>Nome del gioco</dt><dd><?=alphano_escape($settings['game_name'])?></dd></div><div><dt>Homepage</dt><dd><?=alphano_escape($settings['base_url'])?></dd></div><div><dt>Account amministratore</dt><dd><?=alphano_escape($user['nome'])?></dd></div><div><dt>Dati iniziali</dt><dd><?=!empty($settings['demo_data_installed'])?'Pacchetto di esempio incluso':'Struttura personalizzata dal gestore'?></dd></div><div><dt>Email</dt><dd><?=alphano_escape($mail[$settings['mail_transport']]??'Da verificare')?></dd></div></dl><ul><li>✓ Database e struttura pronti</li><li>✓ Impostazioni generali salvate</li><li>✓ Account del gestore attivo</li><li>✓ Installazione iniziale chiusa</li></ul></section>
<aside class="setup-complete-next"><h2>Il prossimo capitolo</h2><p><?=alphano_escape($nextText)?></p><p>Il pulsante “Configura il gioco” apre chat, razze, città, gruppi e template.</p></aside>
<nav class="setup-complete-actions" aria-label="Continua dopo l’installazione"><a class="button" href="/?preview=1">Vai alla homepage <span aria-hidden="true">→</span></a><a class="button secondary" href="/admin/setup.php">Configura il gioco</a></nav>
<p class="setup-complete-countdown">Ti portiamo alla homepage tra <span data-complete-seconds>15</span> secondi. <button type="button" data-complete-pause hidden>Resta sul riepilogo</button></p>
</main></body></html>
