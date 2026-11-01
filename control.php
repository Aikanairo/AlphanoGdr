<?php
declare(strict_types=1);

/*
 |------------------------------------------------------------------
 |  CONTROL FRAME – MENU & METEO
 |------------------------------------------------------------------
 |  Tutto quello che serve alla cornice superiore della GUI.
 |------------------------------------------------------------------
*/

require __DIR__.'/libs/kernel.inc.php';
require __DIR__.'/libs/libpg.inc.php';
require __DIR__.'/libs/libblocco.inc.php';
require __DIR__.'/libs/libmeteo.inc.php';
require __DIR__.'/libs/libgrafica.inc.php';
require __DIR__.'/libs/libtext.inc.php';
require_once __DIR__.'/libs/media-assets.inc.php';

use Alphano\Database\PDOMySQLDriver;



/* -----------------------------------------------------------------
 *  1) Login & connessione
 * ----------------------------------------------------------------*/
$pgid = GetIDPG(true);

/** @var PDOMySQLDriver $db */
$db = ConnectDB();

/* --- logging silenzioso degli accessi ---------------------------------*/
try {
    $db->execute(
        'INSERT INTO logs_ingressi (data, pg, ip, host)
         VALUES (?, ?, ?, ?)',
        [
            time(),
            $pgid,
            $_SERVER['REMOTE_ADDR'] ?? '',
            gethostbyaddr($_SERVER['REMOTE_ADDR'] ?? '')
        ]
    );
} catch (Throwable $e) {
    /* niente panico se fallisce – evitiamo di rompere il flusso */
}

/* -----------------------------------------------------------------
 *  2) Mail statistics
 * ----------------------------------------------------------------*/
$ora     = (int)date('H');
$leggere = (int)$db->query(
              'SELECT COUNT(*) AS n
                 FROM mail
                WHERE letto=0 AND baule=0 AND deldest=0 AND iddestinatario=?',
              [$pgid]
          )[0]['n'];
$tot     = (int)$db->query(
              'SELECT COUNT(*) AS n
                 FROM mail
                WHERE baule=0 AND deldest=0 AND iddestinatario=?',
              [$pgid]
          )[0]['n'];

/* -----------------------------------------------------------------
 *  3) Dati PG + Meteo
 * ----------------------------------------------------------------*/
$meteo = GetMeteo();
$user  = $db->query('SELECT * FROM utenti WHERE id=?', [$pgid])[0];

/* --- unlock automatico -------------------------------------------*/
if ($user['bloccato']) {
    UnLockUser($pgid);
}

/* -----------------------------------------------------------------
 *  4) Luna & Lycan
 * ----------------------------------------------------------------*/
$luna   = FaseLunare();
$lican  = (LicanTime() && $luna === 'Luna Piena') ? 1 : 0;

/* -----------------------------------------------------------------
 *  5) Costruisco la chiave “param” – serve a capire se cambiare menu
 * ----------------------------------------------------------------*/
$param = implode(';', [
    $leggere,                 // 0
    $tot,                     // 1
    $ora,                     // 2
    (int)$user['bloccato'],   // 3
    $lican,                   // 4
    $user['razza'],           // 5
    $user['fatepower'],       // 6
    $user['adminferite'],     // 7
    $user['adminmorti'],      // 8
    $user['admin'],           // 9
    $user['adminaccademia'],  // 10
    $user['organizzazione'],  // 11
    $user['adminorganizzazione'], // 12
    $user['corporazione'],    // 13
    $user['admincorporazione'],   // 14
    $user['sindaco'],         // 15
    $user['adminstatosociale'],   // 16
    $user['ancestrale'],      // 17
]);

[$alba, $tramonto] = SorgeCala();
$sorgecala = LicanTime()
    ? 'Il sole sorge alle '.date('H:i', $alba)
    : 'Il sole tramonta alle '.date('H:i', $tramonto);

/* -----------------------------------------------------------------
 *  6) Output
 * ----------------------------------------------------------------*/
?><!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Control – <?= TitoloSito ?></title>

  <script>
    /*------------------------------------------
     *  RELOAD “silenzioso” di questo frame
     *----------------------------------------*/
    function DoReload () {
      location.href = '/control.php?param=<?= rawurlencode($param) ?>';
    }

    /*------------------------------------------
     *  onLoad – aggiorna header & (se serve) menu
     *----------------------------------------*/
    function ReloadPage () {


      /* 1. Aggiorno meteo / orologio nel frame padre */
      parent.UpdateMTop(
        "<?= addslashes($sorgecala) ?>",
        <?= abs($meteo['cielo']) ?>,
        "<?= addslashes($cielo[$meteo['cielo']]) ?>",
        <?= abs($meteo['tempo']) ?>,
        "<?= addslashes(GetDescrTempo($meteo['tempo'], $meteo['temperatura'])) ?>",
        <?= abs($meteo['vento']) ?>,
        "<?= addslashes($vento[$meteo['vento']]) ?>",
        "<?= $meteo['temperatura'] ?>",
        "<?= addslashes($luna) ?>",
        <?= DaysToFullMoon() ?>,
        <?= $leggere ?>,
        <?= json_encode(alphano_weather_icons($meteo,$luna,time()<$alba||time()>=$tramonto),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>
      );

      /* 2. Verifico se devo rigenerare il menu */
      const paramPHP = "<?= addslashes($param) ?>";
      const paramGET = <?= isset($_GET['param']) ? '"'.addslashes($_GET['param']).'"' : 'null' ?>;

      // DEBUG – attiva se serve
      // console.log('[control] paramPHP:', paramPHP, '| paramGET:', paramGET);

      if (paramPHP !== paramGET) {

        <?php if ($user['bloccato']): ?>
          /*----------------------------------------------------------
           *  Utente bloccato → niente menu, solo avviso
           *--------------------------------------------------------*/
          parent.document.getElementById('spaziomenu').classList.add('is-blocked');
          parent.document.getElementById('spaziomenu').innerHTML =
            "<?= addslashes(
                  ($user['sesso']==='Maschio' ? 'Sei stato bloccato' : 'Sei stata bloccata')
                  . ($user['lockby'] ? ' da '.htmlentities(GetPGFromID($user['lockby'])) : '')
                  . ' fino al ' . FormatTimestamp($user['datasblocco'])
                  . '<br>' . ProcessText($user['motivo'])
              ) ?>";
        <?php else: ?>
          /*----------------------------------------------------------
           *  Costruisco l’array vocimenu
           *--------------------------------------------------------*/
          const tmp = [];

          /* --- voci base --------------------------------------------------*/
          tmp.push("/posta/,GPosta,600,600,Posta (<?= $leggere ?>/<?= $tot ?>)");
          tmp.push("/profili/?Id=<?= $pgid ?>,GProfilo,600,500,Profilo");
          tmp.push("/equip/equip.php?pgid=<?= $pgid ?>,GEquip,750,700,Equipaggiamento");

          /* --- trasferimenti (no fantasmi/zombie) ------------------------*/
          <?php if ($user['razza'] != 7 && $user['razza'] != 8): ?>
            tmp.push("/trasferimenti/,GTrans,600,350,Trasferimenti");
          <?php endif; ?>

          /* --- admin ferite / anime / morti ------------------------------*/
          <?php if ($user['fatepower'] || $user['adminferite'] || $user['adminmorti']): ?>
            tmp.push("/admin/ferite.php,GFA,600,600,Admin Ferite");
          <?php endif; ?>

          /* --- master quest ---------------------------------------------*/
          <?php if ($user['fatepower']): ?>
            tmp.push("/discussioni/?tipo=M,GQuest,800,600,Tracce Quest");
            tmp.push("/admin/blocco.php,GBlocco,700,400,Blocco Utenti");
          <?php endif; ?>

          /* --- chat admin -----------------------------------------------*/
          <?php if ($user['admin'] || $user['fatepower'] || $user['adminaccademia']): ?>
            tmp.push("/admin/chats.php,GChats,1000,300,Admin Chat");
          <?php endif; ?>

          /* --- accademia -------------------------------------------------*/
          <?php if ($user['adminaccademia']): ?>
            tmp.push("/admin/accademia.php,GAcc,800,500,Accademia");
            tmp.push("/discussioni/?tipo=A,GDiscAcc,800,600,Discussioni Acc.");
            tmp.push("/admin/log.php,GLog,800,600,Log Viewer");
          <?php endif; ?>

          /* --- organizzazione -------------------------------------------*/
          <?php if ($user['organizzazione']): ?>
            tmp.push("/discussioni/?tipo=Org<?= $user['organizzazione'] ?>,GDiscOrg,800,600,Bacheca Org");
            <?php if ($user['adminorganizzazione']): ?>
              tmp.push("/organizzazioni/admin.php,GOrgAdm,1000,500,Admin Org");
            <?php endif; ?>
          <?php endif; ?>

          /* --- corporazione ---------------------------------------------*/
          <?php if ($user['corporazione']): ?>
            tmp.push("/discussioni/?tipo=Corp<?= $user['corporazione'] ?>,GDiscCorp,800,600,Bacheca Corp");
            <?php if ($user['admincorporazione']): ?>
              tmp.push("/corporazioni/admin.php,GCorpAdm,1000,500,Admin Corp");
            <?php endif; ?>
            tmp.push("/corporazioni/catalogo.php,GCatalogo,1000,500,Catalogo");
            tmp.push("/corporazioni/trasferimenti.php,GCorp,1000,500,Corporazione");
          <?php endif; ?>

          /* --- sindaco / città ------------------------------------------*/
          <?php if ($user['sindaco']): ?>
            tmp.push("/admin/city.php,GCity,650,600,Admin Città");
          <?php endif; ?>

          /* --- araldiche / bacheche -------------------------------------*/
          <?php if ($user['admin'] || $user['sindaco']): ?>
            tmp.push("/admin/cariche.php,GCariche,1000,600,Admin Araldiche");
            tmp.push("/admin/bacheche.php,GBach,800,500,Admin Bacheche");
          <?php endif; ?>

          /* --- utenti ----------------------------------------------------*/
          <?php if ($user['admin']): ?>
            tmp.push("/admin/utenti.php,GUtenti,800,600,Admin Utenti");
          <?php elseif ($user['adminstatosociale']): ?>
            tmp.push("/admin/utenti.php,GUtenti,650,200,Admin Stato Soc.");
          <?php endif; ?>

          /* --- ancestrali -----------------------------------------------*/
          <?php if ($user['ancestrale']): ?>
            tmp.push("/admin/ancestrali.php,GAnc,200,100,Trasformazione");
          <?php endif; ?>

          /* --- lican (infetto fuori lycan-time) -------------------------*/
          <?php if ($user['infetto'] && !$lican): ?>
            tmp.push("/admin/lican.php,GLican,200,100,Trasformazione");
          <?php endif; ?>

          /* --- OFF-Game tools -------------------------------------------*/
          tmp.push("/vari/menuvarie.php,GOFF,800,600,Strumenti OFF");

          /* --- fallback di sicurezza ------------------------------------*/
          if (!tmp.length) {
            console.warn('[control] vocimenu vuoto – fallback');
            tmp.push("#,GDBG,400,200,Nessuna voce ricevuta");
          }

          /* --- passo tutto al parent ------------------------------------*/
          try {
            parent.vocimenu = tmp;
            // DEBUG
            // console.table(tmp);
          } catch (e) {
            console.error('[control] impossibile settare parent.vocimenu', e);
          }
        <?php endif; /* fine NON bloccato */ ?>

      } /* fine if param diverso */
    } /* fine ReloadPage */
  </script>
</head>

<body onload="ReloadPage()">
  <!-- frame quasi vuoto: tutto è gestito via JS -->
</body>
</html>
<?php ClosePage();
