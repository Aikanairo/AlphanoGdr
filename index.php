<?php
declare(strict_types=1);
require_once __DIR__.'/libs/kernel.inc.php';
require_once __DIR__.'/libs/credits.inc.php';
if(alphano_user()&&!isset($_SESSION['alphano_login_error'])&&!(isset($_GET['preview'])&&alphano_can('admin'))){header('Location: /main.php',true,303);exit;}
header('Cache-Control: no-store');
$failedLogin=$_SESSION['alphano_login_error']??null;unset($_SESSION['alphano_login_error']);
$loginFailed=is_array($failedLogin)&&is_string($failedLogin['username']??null);
$loginName=$loginFailed?$failedLogin['username']:'';
$home=alphano_appearance()['home']??[];
$pinned=array_flip($home['manuals']??[]);
$homeManuals=$pinned?array_values(array_filter(alphano_appearance_public_manuals(),static fn($m)=>isset($pinned[(int)$m['id']]))):[];
$csrf=alphano_csrf_token();$config=require __DIR__.'/config/app.php';
require_once __DIR__.'/libs/rpg.inc.php';$stats=rpg_catalog()['stats'];
$config['initial_race']=alphano_human_race_id();
$config['registration_enabled']=$config['registration_enabled']&&$config['initial_race']>0;
$banner=($home['banner']??'')?:alphano_appearance_page('/')['header'];
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><title><?=alphano_escape(TitoloSito)?></title><meta name="description" content="<?=alphano_escape(mb_substr(($home['intro']??'')?:$config['game_description'],0,180))?>"><link rel="preload" as="image" href="<?=alphano_escape($banner)?>"><script src="/assets/portal.js?v=<?=substr(hash_file('sha256',__DIR__.'/assets/portal.js'),0,12)?>" defer></script></head>
<body class="portal portal-homepage">
<a class="portal-skip" href="#accesso">Vai all’accesso</a>
<header class="portal-masthead"><div class="portal-inner">
  <a class="portal-brand" href="/" aria-label="<?=alphano_escape(TitoloSito)?> · Homepage">
    <img class="portal-logo portal-brand-logo" src="/favicon.png" alt="<?=alphano_escape(TitoloSito)?>" width="44" height="44"><span class="sr-only"><?=alphano_escape(TitoloSito)?></span>

  </a>
  <nav aria-label="Navigazione della homepage"><a href="#scopri">Il gioco</a><a href="/manuali/">Manuali</a><a class="portal-nav-access" href="#accesso">Accedi <span aria-hidden="true">↗</span></a></nav>
</div></header>
<?php if(alphano_config('public_demo')):?><aside class="portal-demo" aria-label="Demo pubblica"><div class="portal-inner"><p><strong>Demo pubblica · AlphanoGdr V1.0</strong> Prova il gioco con <code>test / test</code> o la gestione completa con <code>admin / admin</code>. Le modifiche sono condivise: non inserire dati personali.</p><nav aria-label="Prova i cinque colori"><?php foreach(alphano_themes() as $key=>$label):?><a href="/?preview=1&amp;palette=<?=alphano_escape($key)?>" <?=$config['theme']===$key?'aria-current="true"':''?>><?=alphano_escape($label)?></a><?php endforeach;?></nav></div></aside><?php endif;?>
<main class="portal-wrap">
  <section class="portal-hero" aria-labelledby="portal-title">
    <img class="portal-banner" src="<?=alphano_escape($banner)?>" alt="" width="1672" height="941" fetchpriority="high">
    <div class="portal-hero-layout portal-inner">
      <div class="portal-hero-copy">
        <p class="eyebrow"><span aria-hidden="true">✦</span> Una storia condivisa</p>
        <h1 id="portal-title"><?php if(!empty($home['headline'])):?><?=alphano_escape($home['headline'])?><?php else:?><img class="portal-hero-logo" src="<?=alphano_escape(alphano_brand_logo())?>" alt="<?=alphano_escape(TitoloSito)?>" width="760" height="380"><?php endif;?></h1>
        <p class="portal-lead">Il prossimo capitolo<br>comincia da te.</p>
        <p class="portal-intro"><?=nl2br(alphano_escape(($home['intro']??'')?:$config['game_description']))?></p>
        <div class="portal-hero-links">
          <?php if($config['registration_enabled']):?><a class="button portal-primary" href="#registrazione">Crea il tuo personaggio <span aria-hidden="true">→</span></a><?php else:?><a class="button portal-primary" href="/manuali/">Scopri il mondo <span aria-hidden="true">→</span></a><?php endif;?>
          <a class="portal-text-link" href="#scopri">Lasciati ispirare <span aria-hidden="true">↓</span></a>
        </div>
        <p class="portal-hero-note">Un personaggio. Infinite possibilità.</p>
      </div>
      <aside class="portal-access" id="accesso" aria-labelledby="portal-access-title">
        <p class="eyebrow">Bentornato</p><h2 id="portal-access-title">Entra nel mondo</h2><p>La tua storia ti aspetta.</p>
        <?php if($loginFailed):?><div class="portal-login-error" id="portal-login-error" role="alert" tabindex="-1"><span data-icon="shield" aria-hidden="true"></span><div><strong>Accesso non riuscito</strong><p>Personaggio o password non validi. Controlla i dati e riprova.</p></div></div><?php endif;?>
        <form method="post" action="/checkuser.php"><input type="hidden" name="_csrf" value="<?=$csrf?>"><label>Personaggio<input name="username" value="<?=alphano_escape($loginName)?>"<?=$loginFailed?' aria-invalid="true" aria-describedby="portal-login-error"':''?> autocomplete="username" required maxlength="20" placeholder="Il nome del tuo personaggio"></label><label>Password<input type="password" name="password"<?=$loginFailed?' aria-invalid="true" aria-describedby="portal-login-error"':''?> autocomplete="current-password" required placeholder="La tua password"></label><button type="submit">Accedi <span aria-hidden="true">→</span></button></form>
        <a class="portal-recovery" href="/recuperopasswd.php">Hai dimenticato la password?</a>
        <div class="portal-access-new"><?php if($config['registration_enabled']):?><span>È la tua prima visita?</span><a href="#registrazione">Comincia la tua storia →</a><?php else:?><p>Le registrazioni sono momentaneamente chiuse.</p><a href="/manuali/">Consulta le guide →</a><?php endif;?></div>
      </aside>
    </div>
    <div class="portal-hero-caption" aria-hidden="true"><span>Immagina · Esplora · Racconta</span><span>✦</span></div>
  </section>
  <div class="portal-inner portal-content">
    <section class="portal-project" id="scopri" aria-labelledby="portal-project-title">
      <div><p class="eyebrow">Il progetto</p><h2 id="portal-project-title">Un motore, mondi diversi.</h2><p>Esplora il codice su GitHub e scopri Gothik.it, un esempio di gioco attivo con un’ambientazione e una veste grafica personalizzate.</p></div>
      <nav aria-label="Progetto e gioco di esempio"><a class="button secondary" href="https://github.com/Aikanairo/AlphanoGdr" target="_blank" rel="noopener noreferrer">Repository GitHub <span aria-hidden="true">↗</span></a><a class="button secondary" href="https://gothik.it/" target="_blank" rel="noopener noreferrer">Scopri Gothik.it <span aria-hidden="true">↗</span></a></nav>
    </section>
    <section class="portal-manuals" id="manuali" aria-labelledby="portal-manuals-title">
      <div class="portal-library-art"><img src="<?=alphano_escape(($home['scene_library']??'')?:alphano_default_art('header-library'))?>" alt="Un libro aperto nella biblioteca del mondo" width="1672" height="941" loading="lazy"><span aria-hidden="true" data-icon="book"></span></div>
      <div class="portal-library-copy"><p class="eyebrow">La biblioteca</p><h2 id="portal-manuals-title">Ogni avventura<br>ha un primo capitolo.</h2><p>Scopri l’ambientazione, consulta le regole e trova le risposte per iniziare a giocare.</p>
        <?php if($homeManuals):?><div class="portal-manual-grid"><?php foreach($homeManuals as $manual):?><a class="portal-manual-card" href="/manuali/?section=<?=(int)$manual['id']?>"><div><h3><?=alphano_escape($manual['oggetto'])?></h3><?php if($manual['summary']):?><p><?=alphano_escape(mb_substr($manual['summary'],0,180))?></p><?php endif;?></div><span aria-hidden="true">↗</span></a><?php endforeach;?></div><?php endif;?>
        <a class="portal-text-link" href="/manuali/">Esplora tutti i manuali <span aria-hidden="true">→</span></a>
      </div>
    </section>
    <?php if($config['registration_enabled']):?>
    <details class="portal-registration" id="registrazione"><summary><span><span class="eyebrow">La tua avventura comincia qui</span><strong>Crea il tuo personaggio</strong><span>Prepara la scheda e fai il tuo primo passo nel mondo.</span></span><span class="portal-registration-toggle" aria-hidden="true">+</span></summary><div class="portal-registration-body"><p>Razza iniziale: <?=alphano_escape(ConnectDB()->query('SELECT nome FROM razze WHERE id=?',[$config['initial_race']])[0]['nome']??'')?></p>
    <form method="post" action="/adduser.php"><input type="hidden" name="_csrf" value="<?=$csrf?>"><label>Nome (3–20 lettere, numeri o trattino basso)<input name="pgName" pattern="[A-Za-z][A-Za-z0-9_]{2,19}" required maxlength="20" autocomplete="username"></label><label>Email<input name="mail" type="email" required maxlength="100" autocomplete="email"></label><label>Password (almeno 12 caratteri)<input type="password" name="passwd" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>Conferma password<input type="password" name="passwdConfirm" autocomplete="new-password" required></label><label>Genere del personaggio<select name="sesso"><option>Maschio</option><option>Femmina</option></select></label><?php if($config['ethnicities_enabled']):?><label>Etnia<select name="ethnicity" required><option value="">Scegli un’etnia</option><?php foreach(ConnectDB()->query('SELECT id,nome FROM etnie ORDER BY nome') as $e):?><option value="<?=(int)$e['id']?>"><?=alphano_escape($e['nome'])?></option><?php endforeach;?></select></label><?php endif;?><p>Distribuisci <?=(int)$config['initial_points']?> punti fra le caratteristiche (oltre alla base della razza).</p><div class="stat-allocation"><?php foreach($stats as $i=>$s):?><label><?=alphano_escape($s['name'])?><input type="number" name="allocation[<?=$s['key']?>]" value="<?=intdiv($config['initial_points'],8)+($i<$config['initial_points']%8?1:0)?>" min="0" max="<?=(int)$config['initial_points']?>" required></label><?php endforeach;?></div><label class="check"><input type="checkbox" name="adult" value="1" required>Confermo di avere almeno 18 anni.</label><label class="check"><input type="checkbox" name="terms" value="1" required>Accetto le <a href="/legal/?type=terms">condizioni</a> e ho letto l’<a href="/legal/?type=privacy">informativa privacy</a>.</label><button>Crea personaggio</button></form>
    </div></details>
    <?php endif;?>
  </div>
</main>
<aside class="portal-community" aria-label="Community ufficiale del motore AlphanoGDR"><div class="portal-inner"><a class="portal-community-link" href="https://t.me/alphanoGDR" target="_blank" rel="noopener noreferrer"><span class="portal-community-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path d="M21.5 3.5 18 20l-6.1-4.5-3.1 2.9.5-5.1L18 5.8 7.1 12.6 2.5 11z" fill="currentColor"/></svg></span><span class="portal-community-copy"><strong>Community ufficiale · AlphanoGDR</strong><small>Richieste, segnalazioni di bug e guide nel gruppo Telegram del motore.</small></span><span class="portal-community-arrow" aria-hidden="true">↗</span></a></div></aside>
<footer class="portal-footer"><div class="portal-inner"><div><strong><?=alphano_escape(TitoloSito)?></strong><p>Una storia condivisa, un capitolo alla volta.</p><?=alphano_render_credit('home')?></div><nav aria-label="Informazioni e assistenza"><a href="https://github.com/Aikanairo/AlphanoGdr" target="_blank" rel="noopener noreferrer">Codice sorgente</a><a href="/manuali/">Manuali</a><a href="/legal/?type=privacy">Privacy</a><a href="/legal/?type=cookies">Cookie</a><a href="/legal/?type=terms">Condizioni</a><a href="/legal/?type=credits">Crediti e licenza</a><?php if($config['support_email']!==''):?><a href="mailto:<?=alphano_escape($config['support_email'])?>">Assistenza</a><?php endif;?></nav><a class="portal-back-top" href="#portal-title" aria-label="Torna in cima">↑</a></div></footer>
<dialog class="portal-modal" id="portal-account-modal" aria-label="Assistenza accesso"><button class="portal-modal-close" type="button" aria-label="Chiudi finestra">×</button><div class="portal-modal-content"></div></dialog>
</body></html>
