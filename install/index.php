<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/branding.inc.php';
$root=dirname(__DIR__);
if(is_file($root.'/config/local.php')||is_file($root.'/private/installed.lock')){
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&is_file($root.'/config/local.php')){
  require_once $root.'/libs/kernel.inc.php';
  header('Cache-Control: no-store');
  header('Location: '.(alphano_can('admin')?'/install/complete.php':'/'),true,303);exit;
 }
 http_response_code(404);header('Content-Type: text/html; charset=utf-8');require_once $root.'/libs/status-page.inc.php';alphano_status_page('Installazione già completata', 'La configurazione iniziale è stata chiusa. Accedi con il tuo account per modificare il gioco dal pannello del gestore.');exit;
}
require_once $root.'/libs/runtime-requirements.inc.php';
alphano_runtime_require();
require_once $root.'/libs/credits-guard.inc.php';
alphano_credits_guard();
require __DIR__.'/installer.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");header('Referrer-Policy: no-referrer');
session_name('alphano_setup_'.substr(hash('sha256',$root),0,12));session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>alphano_https_context()]);$_SESSION['setup_csrf']??=bin2hex(random_bytes(32));
$error='';$defaults=require $root.'/config/app.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!is_string($_POST['_csrf']??null)||!hash_equals($_SESSION['setup_csrf'],$_POST['_csrf'])){http_response_code(403);$error='Sessione scaduta. Ricarica la pagina.';}
 else{try{
  $input=array_replace($defaults,$_POST);$input['registration_enabled']=false;
  alphano_install($input);
  // Only the verified bootstrap admin enters the authenticated, resumable wizard.
  $_SESSION=[];session_destroy();session_write_close();
  session_name('alphano_'.substr(hash('sha256',$root),0,16));session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>alphano_https_context((string)$input['base_url'])]);session_regenerate_id(true);$_SESSION['user_id']=1;$_SESSION['csrf_token']=bin2hex(random_bytes(32));
  session_write_close();
  header('Location: /install/complete.php',true,303);exit;
 }catch(DomainException $e){http_response_code(422);$error=$e->getMessage();}}
}
$detectedHost=preg_match('/^[a-z0-9.-]+(?::[0-9]+)?$/iD',$_SERVER['HTTP_HOST']??'')?$_SERVER['HTTP_HOST']:'localhost';
$values=array_replace($defaults,['base_url'=>(alphano_https_context()?'https':'http').'://'.$detectedHost,'db_host'=>'localhost','db_name'=>'','admin_name'=>'Admin','mail_transport'=>'disabled','support_email'=>'','mail_from'=>''],array_filter($_POST,static fn($k)=>!in_array($k,['db_pass','admin_password','admin_password_confirm','smtp_pass'],true),ARRAY_FILTER_USE_KEY));
function field(string $name,string $label,string $type='text',bool $required=true):void{global $values;echo '<label>'.alphano_escape($label).'<input name="'.alphano_escape($name).'" type="'.$type.'" '.($required?'required ':'').($name==='currency_name'?'maxlength="60" ':'').'value="'.alphano_escape($type==='password'?'':($values[$name]??'')).'" '.($type==='password'?'autocomplete="new-password"':'').'></label>';}
?>
<!doctype html><html lang="it"><head><?=alphano_favicon_links()?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installa AlphanoGdr <?=alphano_escape(trim(file_get_contents($root.'/VERSION')))?> </title><link rel="stylesheet" href="/assets/engine.css"><link rel="stylesheet" href="/assets/portal.css?v=<?=substr(hash_file('sha256',$root.'/assets/portal.css'),0,12)?>"></head><body class="setup setup-install" data-palette="blue"><main class="setup-wrap"><p class="eyebrow">ALPHANO GDR <?=alphano_escape(trim(file_get_contents($root.'/VERSION')))?> · AGPL-3.0</p><h1>Il tuo mondo comincia qui.</h1><p>Passaggio 1 · Impostazioni generali e account del gestore</p>

<?php if($error):?><p class="error" role="alert"><?=alphano_escape($error)?></p><?php endif;?>
<?php if($warning=alphano_runtime_warning()):?><aside class="runtime-warning" role="note" aria-labelledby="runtime-warning-title"><span class="runtime-warning-symbol" aria-hidden="true">!</span><div><p class="eyebrow">Requisito compatibile · Aggiornamento consigliato</p><h2 id="runtime-warning-title">Puoi continuare l’installazione</h2><p><?=alphano_escape($warning)?></p><a href="https://www.php.net/archive/2026.php" target="_blank" rel="noopener noreferrer">Leggi l’avviso ufficiale PHP ↗</a></div></aside><?php endif;?>
<details<?=in_array(false,alphano_install_requirements(),true)?' open':''?>><summary>Requisiti del server</summary><ul><?php foreach(alphano_install_requirements() as $label=>$ok):?><li><?=$ok?'✓':'✕'?> <?=alphano_escape($label)?></li><?php endforeach;?></ul><p>Compatibilità: PHP 8.4 o successivo. Aggiornamenti di sicurezza consigliati: PHP 8.4.27+ oppure 8.5.12+.</p></details>

<form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?=alphano_escape($_SESSION['setup_csrf'])?>">
<?php if(alphano_runtime_warning()!==''):?><label class="runtime-consent"><input type="checkbox" name="accept_php_risk" value="1" required <?=($_POST['accept_php_risk']??'')==='1'?'checked':''?>><span>Ho letto l’avviso, comprendo che il server potrebbe non includere tutte le correzioni di sicurezza e accetto il rischio di continuare l’installazione con questa versione PHP.</span></label><?php endif;?>
<fieldset><legend>Licenza GNU AGPL-3.0 e controllo dei crediti</legend><p>Software libero di Alfano Gaetano. Il pacchetto include un controllo locale dei crediti con sospensione tecnica e ripristino. Puoi modificare o rimuovere controllo e collegamenti secondo la AGPL; il blocco non è un accertamento di violazione. Non imponiamo un backlink promozionale. Se offri online una versione modificata, devi offrire ai tuoi utenti il relativo Sorgente Corrispondente secondo la sezione 13.</p><a href="/install/license.php" target="_blank" rel="noopener">Leggi la licenza completa e la descrizione del controllo ↗</a><input type="hidden" name="license_hash" value="<?=hash_file('sha256',$root.'/LICENSE')?>"><label class="runtime-consent"><input type="checkbox" name="accept_license" value="1" required <?=($_POST['accept_license']??'')==='1'?'checked':''?>><span>Ho letto la GNU AGPL-3.0 e la descrizione del controllo modificabile dei crediti. La conferma non aggiunge restrizioni ai diritti concessi dalla licenza.</span></label></fieldset>
<fieldset class="setup-demo"><legend>Pacchetto di esempio</legend><label class="check"><input type="checkbox" name="sample_data" value="1" <?=($_SERVER['REQUEST_METHOD']!=='POST'||($_POST['sample_data']??'')==='1')?'checked':''?>><span>Includi i dati di esempio</span></label><p>Mappa visiva già attiva, con sfondo del mondo, sottomappa di City Alphano e quattro chat già posizionate: Piazza, Locanda, Giardino e Biblioteca. Include razza Umana, etnia Italiana, cariche Cittadino e Sindaco (il gestore iniziale), 23 materie prime e attività dimostrative di raccolta. Tutto si può personalizzare; le registrazioni partono chiuse.</p></fieldset>
<fieldset><legend>Database dedicato</legend><p>Prepara un database completamente vuoto. Le tabelle esistenti non vengono sovrascritte.</p><p>Su AlterVista: host <code>localhost</code>, utente del tuo account, database <code>my_nomeaccount</code>; la password MySQL può essere vuota.</p><?php field('db_host','Host MySQL');field('db_port','Porta MySQL','number');field('db_name','Nome database');field('db_user','Utente database');field('db_pass','Password database','password',false);field('db_socket','Socket Unix (facoltativo)','text',false);?></fieldset>
<fieldset><legend>Identità del gioco</legend><?php field('game_name','Nome del gioco');field('currency_name','Nome della valuta (es. Euro, Oro, Sterline)');field('game_description','Descrizione');field('base_url','URL del dominio','url');field('owner_name','Nome del gestore');field('support_email','Email del gestore / assistenza (facoltativa)','email',false);?></fieldset>
<fieldset><legend>Account amministratore completo</legend><?php field('admin_name','Nome amministratore');field('admin_password','Password amministratore (12–72 caratteri)','password');field('admin_password_confirm','Conferma password','password');?><p>Conserva queste credenziali: consentono di modificare tutte le impostazioni anche dopo aver concluso la procedura.</p></fieldset>
<details><summary>Email (facoltative, modificabili dopo)</summary><fieldset><legend>Consegna delle email</legend><label>Modalità<select name="mail_transport"><?php foreach(['disabled'=>'Disabilitata per ora','smtp'=>'SMTP','mail'=>'PHP mail() del provider (AlterVista)','log'=>'Prova: salva in private/mail'] as $v=>$label):?><option value="<?=$v?>" <?=$values['mail_transport']===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><?php field('mail_from','Mittente','email',false);field('smtp_host','Server SMTP','text',false);field('smtp_port','Porta SMTP','number');field('smtp_user','Utente SMTP','text',false);field('smtp_pass','Password SMTP','password',false);?><label>Sicurezza<select name="smtp_security"><option value="starttls">STARTTLS</option><option value="smtps">TLS implicito</option><option value="none">Nessuna (solo prove locali)</option></select></label><p>Benvenuto, recupero password e notifiche useranno questa configurazione. Nessun messaggio viene inviato durante l’installazione. Per il recupero password occorre attivare un trasporto funzionante.</p></fieldset></details>
<p>Il gioco parte con registrazioni chiuse. Se deselezioni il pacchetto di esempio, non vengono create razze, etnie, città, chat o risorse. L’intelligenza artificiale è disabilitata. Le fasi successive includono il tema, le statistiche e le informative da personalizzare prima dell’apertura.</p><button type="submit">Crea la struttura e continua</button></form></main></body></html>
