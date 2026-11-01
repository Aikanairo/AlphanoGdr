<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

/********************************************************
 * admin/citta.php
 * Amministrazione Città – PHP 8 + PDO
 ********************************************************/

/* ─────────────────── 1) Bootstrap ─────────────────── */
require_once __DIR__ . '/../libs/kernel.inc.php';       // initSession(), ClosePage()
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libpg.inc.php';       // GetIDPG()
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php'; // ConsegnaStipendiCity()
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libelezioni.inc.php'; // ElectionTime(), VoteTime()
require_once __DIR__ . '/../libs/activity.inc.php';
require_once __DIR__ . '/../libs/admin-governance.inc.php';
require_once __DIR__ . '/../chat/libs/chat-core.php';  // ConnectDB(), InsertIntoChat()



initSession();
$idpg = alphano_require_login();

$db = ConnectDB();

/* ─────────────────── 2) Permessi e dati iniziali ─────────────────── */

$msg     = '';

// Recupero chatRoom, sindaco e araldica
$userRow = $db->query(
    'SELECT chat, admin, sindaco, araldica FROM utenti WHERE id = ?',
    [$idpg]
)[0] ?? null;
if (!$userRow || (empty($userRow['admin'])&&empty($userRow['sindaco']))) {
    http_response_code(403);
    exit('Non possiedi i requisiti necessari a questa amministrazione');
}
$chatRoom    = $userRow['chat'];
$araldica_id = (int)$userRow['araldica'];

// A general administrator can select a city without acquiring its mayoral flag.
$ownCity=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[$araldica_id])[0]['city']??0);
$availableCities=!empty($userRow['admin'])?$db->query('SELECT id,city FROM city ORDER BY city,id'):[];
if(!$ownCity&&!empty($userRow['admin']))$ownCity=(int)($availableCities[0]['id']??0);
$rawCity=$_POST['_city']??$_GET['city']??$ownCity;
$selectedCity=filter_var($rawCity,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$selectedCity||(empty($userRow['admin'])&&$selectedCity!==$ownCity)){http_response_code(403);exit('Mandato non valido per questa città.');}
$cityData=$db->query('SELECT * FROM city WHERE id=?',[$selectedCity])[0]??null;
if (!$cityData) {
    http_response_code(500);
    exit('Errore: dati città non trovati');
}

$pgcity        = (int)$cityData['id'];
$elezioni      = (bool)$cityData['elezioni'];
$costoelezioni = $elezioni
    ? (int)$cityData['costoelezioni']
    : 1;
$electiontime  = ElectionTime($pgcity);
$votetime      = VoteTime($pgcity);

// Recupera solo le dimore orfane e libera le disponibilità una sola volta.
$toFree=EconomiaAtomica(static function() use($db,$pgcity): int {
    $orphans=$db->query('SELECT d.id,d.indirizzo FROM dimore d LEFT JOIN utenti u ON u.id=d.pg LEFT JOIN araldiche a ON a.id=u.araldica WHERE d.city=? AND (a.city IS NULL OR a.city<>?) FOR UPDATE',[$pgcity,$pgcity]);
    $counts=[];
    foreach ($orphans as $row) $counts[(int)$row['indirizzo']]=($counts[(int)$row['indirizzo']] ?? 0)+1;
    foreach ($counts as $address=>$quantity) $db->execute('UPDATE indirizzi SET disp=disp+? WHERE id=? AND city=?',[$quantity,$address,$pgcity]);
    foreach ($orphans as $row) $db->execute('DELETE FROM dimore WHERE id=? AND city=?',[$row['id'],$pgcity]);
    return count($orphans);
});
if ($toFree) $msg="Sono state liberate {$toFree} dimore appartenenti a personaggi che non abitano più in questa città";

/* ─────────────────── 3) Gestione POST ─────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['azione'] ?? '';
    $id=(int)($_POST['id'] ?? 0);
    if ($id>0 && in_array($action,['modifyquartiere','eliminaquartiere','sfratta'],true)) {
        $table=$action==='sfratta' ? 'dimore' : 'indirizzi';
        if (!$db->query("SELECT 1 FROM {$table} WHERE id=? AND city=?",[$id,$pgcity])) { http_response_code(403); exit('Elemento non appartenente alla tua città'); }
    }
    if ($action==='changestipendio' && !$db->query('SELECT 1 FROM araldiche WHERE id=? AND city=?',[(int)($_POST['carica'] ?? 0),$pgcity])) { http_response_code(403); exit('Carica non appartenente alla tua città'); }
    $activityState=static function() use($db,$pgcity,$id): array {
        $state=$db->query('SELECT budget,costopvt,costoorg,costocorp,costoelezioni,SHA2(newstatuto,256) AS statute_hash FROM city WHERE id=?',[$pgcity])[0];
        $state['roles']=$db->query('SELECT id,stipendio FROM araldiche WHERE city=? ORDER BY id',[$pgcity]);
        $state['districts']=$db->query('SELECT id,indirizzo,costo,disp,capienza FROM indirizzi WHERE city=? ORDER BY id',[$pgcity]);
        $state['requests']=$db->query('SELECT idpg FROM richieste WHERE city=? ORDER BY idpg',[$pgcity]);
        $state['homes']=$db->query('SELECT id,pg,indirizzo FROM dimore WHERE city=? ORDER BY id',[$pgcity]);
        $state['statute_votes']=(int)$db->query('SELECT COUNT(*) AS total FROM assemblea WHERE city=?',[$pgcity])[0]['total'];
        return $state;
    };
    $activityBefore=$activityState();
    switch ($action) {

        case 'admitpg':
            $pg    = (int)($_POST['pg'] ?? 0);
            try {
                if(!in_array($_POST['admit']??'',['0','1'],true))throw new DomainException('Scegli se accettare o respingere la richiesta.',422);
                $result=alphano_governance_city_citizenship($idpg,$pg,$pgcity,$_POST['admit']==='1'?'accept':'reject',(string)($_POST['reason']??''));
                $msg.=$result['message'];
            } catch(DomainException $e){http_response_code($e->getCode());$msg.=$e->getMessage();}
            break;

        case 'grantcitizenship':
            try {
                $target=(int)GetPGID(trim((string)($_POST['nomepg']??'')));
                if($target<1)throw new DomainException('Personaggio non trovato. Inserisci il nome completo.',404);
                $result=alphano_governance_city_citizenship($idpg,$target,$pgcity,'grant',(string)($_POST['reason']??''));
                $msg.=$result['message'];
            } catch(DomainException $e){http_response_code($e->getCode());$msg.=$e->getMessage();}
            break;

        case 'changestipendio':
            $carica   = EconomiaIntero($_POST['carica'] ?? '',1);
            $stipendo = EconomiaIntero($_POST['stipendio'] ?? '',1,2147483647);
            if ($carica && $stipendo!==null) {
                try {
                    $db->execute(
                        'UPDATE araldiche SET stipendio = ? WHERE id = ?',
                        [$stipendo, $carica]
                    );
                    $msg .= 'Carica modificata con successo';
                } catch (\Throwable $e) {
                    $msg .= 'Errore nella modifica della carica';
                }
            } else {
                $msg .= 'Parametri non esaurienti';
            }
            break;

        case 'consegnastipendi':
            try { $msg .= ConsegnaStipendiCity($pgcity,$_POST['_request_key'] ?? null); }
            catch (RuntimeException $e) {
                if (!in_array($e->getCode(),[400,409],true)) throw $e;
                http_response_code($e->getCode());$msg .= $e->getMessage();
            }
            break;

        case 'changecostopvt':
            $costPvt  = (int)($_POST['costopvt'] ?? 0);
            $costOrg  = (int)($_POST['costoorg'] ?? 0);
            $costCorp = (int)($_POST['costocorp'] ?? 0);
            $costEle  = (int)($_POST['costoelezioni'] ?? 0);
            if ($costPvt > 0 && $costOrg > 0 && $costCorp > 0 && $costEle > 0) {
                if ($elezioni && ($electiontime || $votetime)) {
                    $costEle = $costoelezioni;
                }
                try {
                    $db->execute(
                        'UPDATE city
                            SET costopvt = ?, costoorg = ?, costocorp = ?, costoelezioni = ?
                          WHERE id = ?',
                        [$costPvt, $costOrg, $costCorp, $costEle, $pgcity]
                    );
                    $msg .= 'Costo modificato con successo';
                } catch (\Throwable $e) {
                    $msg .= 'Errore nella modifica del costo';
                }
            } else {
                $msg .= 'Parametri non esaurienti';
            }
            break;

        case 'adddimora':
        case 'modifyquartiere':
            $indirizzo = trim($_POST['indirizzo'] ?? '');
            $costo     = (int)($_POST['costo'] ?? 0);
            $disp      = (int)($_POST['disp'] ?? 0);
            $capienza  = (int)($_POST['capienza'] ?? 0);
            if ($indirizzo !== '' && $costo > 0 && $disp >= 0 && $capienza > 0) {
                if ($action === 'adddimora') {
                    try {
                        $db->execute(
                            'INSERT INTO indirizzi (indirizzo, costo, disp, capienza, city)
                             VALUES (?, ?, ?, ?, ?)',
                            [$indirizzo, $costo, $disp, $capienza, $pgcity]
                        );
                        $msg .= 'Quartiere creato con successo';
                    } catch (\Throwable $e) {
                        $msg .= 'Errore nella creazione del Quartiere';
                    }
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    try {
                        $db->execute(
                            'UPDATE indirizzi
                                SET indirizzo = ?, costo = ?, disp = ?, capienza = ?
                              WHERE id = ?',
                            [$indirizzo, $costo, $disp, $capienza, $id]
                        );
                        $msg .= 'Quartiere modificato con successo';
                    } catch (\Throwable $e) {
                        $msg .= 'Errore nella modifica del Quartiere';
                    }
                }
            } elseif ($action!=='modifyquartiere' || isset($_POST['indirizzo'])) {
                $msg .= 'Parametri non esaurienti';
            }
            break;

        case 'eliminaquartiere':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $has = $db->query(
                    'SELECT 1 FROM dimore WHERE indirizzo = ?',
                    [$id]
                );
                if (count($has) === 0) {
                    try {
                        $db->execute(
                            'DELETE FROM indirizzi WHERE id = ?',
                            [$id]
                        );
                        $msg .= 'Quartiere eliminato con successo';
                    } catch (\Throwable $e) {
                        $msg .= 'Errore nell\'eliminazione del Quartiere';
                    }
                } else {
                    $msg .= 'Eliminazione annullata: vi sono ancora dimore occupate';
                }
            }
            break;

        case 'sfratta':
            $dimId = (int)($_POST['id'] ?? 0);
            if ($dimId) {
                try {
                    $freed=EconomiaAtomica(static function() use($db,$dimId,$pgcity): bool {
                        $info=$db->query('SELECT indirizzo FROM dimore WHERE id=? AND city=? FOR UPDATE',[$dimId,$pgcity])[0] ?? null;
                        if (!$info) return false;
                        $db->execute(
                            'UPDATE indirizzi SET disp = disp + 1 WHERE id = ? AND city=?',
                            [(int)$info['indirizzo'],$pgcity]
                        );
                        $db->execute(
                            'DELETE FROM dimore WHERE id = ? AND city=?',
                            [$dimId,$pgcity]
                        );
                        return true;
                    });
                    $msg .= $freed ? 'Dimora eliminata con successo' : 'La dimora è già stata liberata';
                } catch (\Throwable $e) {
                    $msg .= 'Errore nell\'eliminazione della Dimora';
                }
            }
            break;

        case 'statuto':
            $text = trim($_POST['statuto'] ?? '');
            if ($text !== '') {
                try {
                    $db->execute('DELETE FROM assemblea WHERE city=?',[$pgcity]);
                    $db->execute(
                        'UPDATE city SET newstatuto = ? WHERE id = ?',
                        [$text, $pgcity]
                    );
                    $msg .= 'Statuto proposto con successo';
                } catch (\Throwable $e) {
                    $msg .= 'Errore nella proposta del nuovo Statuto';
                }
            }
            break;
    }

    $activityAfter=$activityState();
    $activityActions=[
        'changestipendio'=>['budget-stipendi','Stipendio della carica aggiornato'],
        'changecostopvt'=>['costi','Costi cittadini aggiornati'],
        'adddimora'=>['quartieri','Quartiere creato'], 'modifyquartiere'=>['quartieri','Quartiere aggiornato'],
        'eliminaquartiere'=>['quartieri','Quartiere eliminato'], 'sfratta'=>['quartieri','Dimora liberata'],
        'statuto'=>['statuto','Nuovo statuto proposto'],
    ];
    if (isset($activityActions[$action]) && $activityBefore!==$activityAfter && ($action!=='sfratta' || !empty($freed))) {
        [$section,$title]=$activityActions[$action];
        $audiences=[['scope'=>'citta','id'=>$pgcity,'section'=>$section,'role'=>'admin']];
        if ($action==='admitpg') $audiences[]=['scope'=>'personale','id'=>(int)$_POST['pg'],'section'=>'cittadinanza','role'=>'member'];
        if ($action==='sfratta') foreach ($activityBefore['homes'] as $home) {
            if ((int)$home['id']===$id) $audiences[]=['scope'=>'personale','id'=>(int)$home['pg'],'section'=>'dimora','role'=>'member'];
        }
        $summarize=static function(array $state): array {
            foreach (['roles','districts','requests','homes'] as $collection) {
                $state[$collection]=['count'=>count($state[$collection]),'hash'=>hash('sha256',json_encode($state[$collection]))];
            }
            return $state;
        };
        alphano_record_activity('city_'.$action,$idpg,$audiences,$title,$msg,
            ['city'=>$pgcity,'target'=>$id,'before'=>$summarize($activityBefore),'after'=>$summarize($activityAfter)]);
    }

    // Messaggio in chat
    if ($msg !== ''&&!in_array($action,['admitpg','grantcitizenship'],true)) {
        InsertIntoChat(
            $chatRoom,
            '<div class="MasterKey">' . htmlspecialchars($msg) . '</div>'
        );
    }
}

/* ─────────────────── 4) Dati per il rendering ─────────────────── */
// Richieste cittadinanza
$richieste = GetRichiesteCity($pgcity);

// Lista cariche e stipendi
$araldiche = $db->query(
    'SELECT id, nome, stipendio FROM araldiche WHERE city = ? ORDER BY nome',
    [$pgcity]
);
$totStipendi = 0;
$okStipendi  = true;
foreach ($araldiche as &$row) {
    $cnt = $db->query(
        'SELECT COUNT(*) AS num FROM utenti WHERE araldica = ?',
        [(int)$row['id']]
    )[0]['num'] ?? 0;
    $row['tot']   = (int)$cnt;
    $totStipendi += $row['stipendio'] * $row['tot'];
    $okStipendi  = $okStipendi && ($row['stipendio'] > 0);
}
unset($row);

// Dimore per JS
$dimore = $db->query(
    'SELECT d.id, u.nome, d.numero, i.indirizzo, i.costo, d.lastpay
       FROM dimore d
  JOIN utenti u   ON u.id = d.pg
  JOIN indirizzi i ON i.id = d.indirizzo
      WHERE i.city = ?',
    [$pgcity]
);
$jsDimore = [];
foreach ($dimore as $d) {
    $jsDimore[$d['id']] = [
        'indirizzo' => $d['indirizzo'] . ', ' . $d['numero'],
        'costo'     => $d['costo'],
        'lastpay'   => $d['lastpay'] - 1,
    ];
}

// Budget e statuto
$cityInfo = $db->query(
    'SELECT budget, costopvt, costoorg, costocorp, costoelezioni,
            ultimostipendio, statuto, newstatuto
       FROM city WHERE id = ?',
    [$pgcity]
)[0];
$budget        = $cityInfo['budget'];
$costopvt      = $cityInfo['costopvt'];
$costoorg      = $cityInfo['costoorg'];
$costocorp     = $cityInfo['costocorp'];
$costoelezioni = $elezioni
    ? $cityInfo['costoelezioni']
    : 1;
$ultimostipendio = $cityInfo['ultimostipendio'];
$newStatuto      = $cityInfo['newstatuto'] ?? '';
$statuto         = $newStatuto !== ''
    ? $newStatuto
    : ($cityInfo['statuto'] ?? '');

/* ─────────────────── 5) Render HTML ─────────────────── */
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Amministrazione Città – <?=htmlspecialchars(TitoloSito)?></title>

</head>
<body class="ui-page">
<header class="ui-page-heading" data-page-heading data-header-image="/assets/placeholder.svg">
  <span data-icon="city" aria-hidden="true"></span>
  <div><div class="ui-eyebrow">Alphano · Governo cittadino</div><h1><?=htmlspecialchars((string)$cityData['city'])?></h1><p>Cittadinanze, richieste, cariche e risorse della comunità.</p></div>
<?php if(!empty($userRow['admin'])): ?>
<form method="get" class="ui-inline-form"><label>Città da amministrare <select name="city" aria-label="Città da amministrare"><?php foreach($availableCities as $c): ?><option value="<?=(int)$c['id']?>"<?=(int)$c['id']===$pgcity?' selected':''?>><?=htmlspecialchars((string)$c['city'])?></option><?php endforeach; ?></select></label><button type="submit" data-icon="city">Apri città</button></form>
<?php endif; ?>
<nav class="ui-module-toolbar" aria-label="Amministrazioni cittadine"><a class="action-btn" href="/governo/?scope=citta&amp;id=<?=$pgcity?>" data-icon="coins">Governo, bilancio e stipendi</a><a class="action-btn" href="/citta/elezioni.php?city=<?=$pgcity?>" data-icon="crown">Elezioni</a><a class="action-btn" href="#richieste-cittadinanza" data-icon="users">Cittadinanze e richieste</a><a class="action-btn" href="/admin/cariche.php" data-icon="shield">Cariche e cittadini</a><a class="action-btn" href="/admin/bacheche.php" data-icon="messages">Bacheche cittadine</a><a class="action-btn" href="/notifiche/?group=citta" data-icon="scroll">Cronaca del governo</a></nav></header>
<?php if ($msg): ?>
  <div class="flash" role="status"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<div class="container">

  <!-- Richieste di cittadinanza -->
  <section class="panel" id="richieste-cittadinanza">
    <header>Richieste di cittadinanza</header>
    <?php if ($richieste): foreach ($richieste as $r): ?>
      <form method="post"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
        <input type="hidden" name="azione" value="admitpg">
        <input type="hidden" name="pg" value="<?=(int)$r['idpg']?>">
        <div><?=htmlspecialchars((string)GetPGFromID((int)$r['idpg']))?> – <?=FormatTimestamp($r['data'])?></div>
        <label>Motivazione o risposta <input name="reason" maxlength="500" placeholder="Facoltativa, sarà visibile al richiedente"></label>
        <button name="admit" value="1" data-icon="users">Accetta cittadinanza</button>
        <button name="admit" value="0" data-icon="x">Respingi richiesta</button>
      </form>
    <?php endforeach; else: ?>
      <div>Nessuna richiesta pervenuta</div>
    <?php endif; ?>
  </section>

  <form class="panel" method="post" id="concedi-cittadinanza">
    <input type="hidden" name="_city" value="<?=(int)$pgcity?>">
    <input type="hidden" name="azione" value="grantcitizenship">
    <header>Concedi cittadinanza</header>
    <p>Accogli un personaggio nella comunità assegnandogli la carica di ingresso. Per promozioni e incarichi usa Cariche e cittadini.</p>
    <label>Nome del personaggio <input name="nomepg" maxlength="50" required autocomplete="off" placeholder="Nome completo"></label>
    <label>Motivazione <input name="reason" maxlength="500" required placeholder="Indica il motivo della concessione"></label>
    <button type="submit" data-icon="users">Concedi cittadinanza</button>
  </form>

  <!-- Lista Cariche -->
  <form class="panel" id="cariche"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
    <header>Lista Cariche</header>
    <?php foreach ($araldiche as $a): ?>
      <div>
        <?=processAraldica((int)$a['id'])?>
        <?=$a['nome']?>: <?=$a['stipendio']?> <?=alphano_currency_html()?> (<?=$a['tot']?> pg)
      </div>
    <?php endforeach; ?>
  </form>

  <!-- Budget e Stipendi -->
  <form class="panel" method="post" id="budget-stipendi"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
    <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
    <header>Budget e Stipendi</header>
    <label>Budget attuale</label>
    <input disabled value="<?=$budget?> <?=alphano_currency_html()?>">
    <label>Totale stipendi</label>
    <input disabled value="<?=$totStipendi?> <?=alphano_currency_html()?>">
    <label>Ultimo stipendio</label>
    <input disabled
      value="<?=
        ($ultimostipendio ?? 0)
          ? FormatTimestamp($ultimostipendio)
          : 'Nessuno'
      ?>">
    <?php if ($okStipendi && $budget >= $totStipendi): ?>
      <button name="azione" value="consegnastipendi">Consegna Stipendi</button>
    <?php elseif (!$okStipendi): ?>
      <div>Non tutte le cariche hanno uno stipendio impostato</div>
    <?php else: ?>
      <div>Fondi insufficienti per pagare gli stipendi</div>
    <?php endif; ?>
  </form>

  <!-- Modifica Stipendio -->
  <form class="panel" method="post"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
    <header>Modifica Stipendio</header>
    <label>Carica</label>
    <select name="carica" required>
      <option value="">– seleziona –</option>
      <?php foreach ($araldiche as $a): ?>
        <option value="<?=$a['id']?>"><?=htmlspecialchars($a['nome'])?></option>
      <?php endforeach; ?>
    </select>
    <label>Stipendio</label>
    <input type="number" name="stipendio" min="1" max="2147483647" step="1" required>
    <button name="azione" value="changestipendio">Modifica</button>
  </form>

  <!-- Gestione Costi -->
  <form class="panel" method="post" id="costi"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
    <header>Gestione Costi</header>
    <label>Prezzo Privato Comune</label>
    <input type="number" name="costopvt" value="<?=$costopvt?>" required>
    <label>Affitto Organizzazione</label>
    <input type="number" name="costoorg" value="<?=$costoorg?>" required>
	<label>Affitto Corporazione</label>
	<input type="number" name="costocorp" value="<?=$costocorp?>" required>
    <?php if ($elezioni && !($electiontime || $votetime)): ?>
      <label>Costo Proposta Elezioni</label>
      <input type="number" name="costoelezioni" value="<?=$costoelezioni?>" required>
    <?php else: ?>
      <input type="hidden" name="costoelezioni" value="<?=$costoelezioni?>">
    <?php endif; ?>
    <button name="azione" value="changecostopvt">Modifica</button>
  </form>

  <!-- Quartieri Residenziali -->
  <section class="panel" id="quartieri">
    <header>Quartieri Residenziali</header>
    <?php $quartieri=$db->query('SELECT * FROM indirizzi WHERE city=?',[$pgcity]); ?>
    <?php if ($quartieri): foreach ($quartieri as $q): ?>
      <form method="post"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
        <input type="hidden" name="id" value="<?=(int)$q['id']?>">
        <div><?=htmlspecialchars($q['indirizzo'])?> – <?=(int)$q['costo']?> <?=alphano_currency_html()?> – Disp: <?=(int)$q['disp']?> – Cap.: <?=(int)$q['capienza']?></div>
        <button name="azione" value="modifyquartiere">Modifica</button>
        <button name="azione" value="eliminaquartiere">Elimina</button>
      </form>
    <?php endforeach; else: ?>
      <div>Nessun indirizzo disponibile</div>
    <?php endif; ?>
  </section>

  <!-- Aggiungi / Modifica Quartiere -->
  <?php if (isset($_POST['azione'], $_POST['id']) && $_POST['azione'] === 'modifyquartiere'): ?>
    <?php $d = $db->query('SELECT * FROM indirizzi WHERE id = ? AND city=?', [(int)$_POST['id'],$pgcity])[0] ?? []; ?>
    <form class="panel" method="post"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
      <header>Modifica Quartiere</header>
      <input type="hidden" name="azione" value="modifyquartiere">
      <input type="hidden" name="id" value="<?=$d['id']?>">
      <label>Indirizzo</label>
      <input name="indirizzo" value="<?=htmlspecialchars($d['indirizzo'])?>" required>
      <label>Costo</label>
      <input type="number" name="costo" value="<?=$d['costo']?>" required>
      <label>Disponibilità</label>
      <input type="number" name="disp" value="<?=$d['disp']?>" required>
      <label>Capienza</label>
      <input type="number" name="capienza" value="<?=$d['capienza']?>" required>
      <button>Salva</button>
    </form>
  <?php else: ?>
    <form class="panel" method="post"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
      <header>Crea Nuovo Quartiere</header>
      <input type="hidden" name="azione" value="adddimora">
      <label>Indirizzo</label>
      <input name="indirizzo" required>
      <label>Costo</label>
      <input type="number" name="costo" required>
      <label>Disponibilità</label>
      <input type="number" name="disp" required>
      <label>Capienza</label>
      <input type="number" name="capienza" required>
      <button>Aggiungi</button>
    </form>
  <?php endif; ?>

  <!-- Sfratta Abitante -->
  <form class="panel" method="post"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
    <header>Sfratta Abitante</header>
    <label>PG</label>
    <select id="pg-sfratta" name="id" onchange="GetInfo(this.value)" required>
      <option value="">– seleziona –</option>
      <?php foreach ($dimore as $d): ?>
        <option value="<?=$d['id']?>"><?=htmlspecialchars($d['nome'])?></option>
      <?php endforeach; ?>
    </select>
    <label>Indirizzo</label><div id="sfrattaind"></div>
    <label>Ultimo Affitto</label><div id="sfrattaaff"></div>
    <label>Valore Affitto</label><div id="sfrattaval"></div>
    <button name="azione" value="sfratta">Sfratta</button>
  </form>

  <!-- Modifica Statuto -->
  <form class="panel" method="post" id="statuto"><input type="hidden" name="_city" value="<?= (int)$pgcity ?>">
    <header>Modifica Statuto</header>
    <textarea name="statuto" rows="6"><?=htmlspecialchars($statuto)?></textarea>
    <button name="azione" value="statuto">Proponi</button>
  </form>

</div>

<script>
  const dimore = <?= json_encode($jsDimore, JSON_UNESCAPED_UNICODE) ?>;
  function GetInfo(id) {
    const info = dimore[id] || {};
    document.getElementById('sfrattaind').textContent = info.indirizzo || '';
    document.getElementById('sfrattaaff').textContent = info.lastpay >= 0
      ? ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'][info.lastpay]
      : '';
    document.getElementById('sfrattaval').textContent = info.costo || '';
  }
</script>
</body>
</html>
<?php
ClosePage();
