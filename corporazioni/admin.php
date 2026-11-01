<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

require_once __DIR__ . '/../libs/kernel.inc.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php';
require_once __DIR__ . '/../libs/group-admin.inc.php';
require_once __DIR__.'/../libs/corporation-commerce.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';

$db   = getDB();
$pgid = GetIDPG(0, 1);

$quality = QUALITY;

$actor=alphano_user();
if(!empty($actor['admin'])&&empty($actor['corporazione'])&&!isset($_GET['corp'])&&!isset($_POST['corp'])&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){header('Location: /corporazioni/');exit;}
try{$manageCorp=alphano_corporation_context($actor,$_POST['corp']??$_GET['corp']??null);}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
// --- Recupera dati utente + corporazione ---
$userRows = $db->query(<<<'SQL'
  SELECT
    c.id AS corporazione,
    u.admincorporazione,
    u.organizzazione,
    c.nome        AS nomecorp,
    c.budget      AS budgetcorp,
    c.ultimostipendio,
    c.stipendio,
    c.provvigione,
    c.sito,
    c.city,
    c.pvt,
    c.sfondotop,
    c.sfondo,
    c.mappa,
    c.descrizione
  FROM utenti AS u
  JOIN corporazioni AS c ON c.id = ?
  WHERE u.id = ?
SQL
, [$manageCorp,$pgid]);
$user = $userRows[0] ?? [];
if (empty($user['corporazione'])) {
    http_response_code(403);
    die('<div class="alert alert-danger m-3">Non puoi amministrare una corporazione senza possederne una</div>');
}
if (empty($actor['admin']) && !(bool)$user['admincorporazione']) {
    http_response_code(403);
    die('<div class="alert alert-danger m-3">Non sei amministratore di corporazione</div>');
}

// --- Costanti e variabili principali ---
if (!defined('maxpointvalue')) define('maxpointvalue', 20);

$corpId         = (int)$user['corporazione'];
$nomeCorp       = htmlspecialchars($user['nomecorp']    ?? '', ENT_QUOTES);
$budget         = (int)$user['budgetcorp'];
$ultimoStip     = (int)$user['ultimostipendio'];
$hasStipendio   = (bool)$user['stipendio'];
$hasProvvigione = (bool)$user['provvigione'];
$sitoCorp       = htmlspecialchars($user['sito']        ?? '', ENT_QUOTES);
$cityCorp       = (int)$user['city'];
$pvtRoom        = base64_decode((string)$user['pvt']);
$sfondoTop      = htmlspecialchars($user['sfondotop']  ?? '', ENT_QUOTES);
$sfondoBg       = htmlspecialchars($user['sfondo']      ?? '', ENT_QUOTES);
$mappaRoom      = htmlspecialchars($user['mappa']       ?? '', ENT_QUOTES);
$descrRoom      = htmlspecialchars($user['descrizione'] ?? '', ENT_QUOTES);

// Lista città
$listacity = [];
foreach (GetListaCity() as $r) {
    $listacity[(int)$r['id']] = htmlspecialchars($r['city'], ENT_QUOTES);
}

// Totale stipendi
$stipendiTot = $hasStipendio ? GetStipendi($corpId) : 0;

// Carica tipologie e qualità produzioni
$subtipi = [];
foreach ($db->query("SELECT id, nome FROM subtipi ORDER BY id") as $r) {
    $subtipi[(int)$r['id']] = $r['nome'];
}
$categorie = [];
foreach ($db->query("SELECT subtipo, nome FROM tipo WHERE subtipo>0 ORDER BY nome") as $r) {
    $categorie[(int)$r['subtipo']][] = $r['nome'];
}


// Inizializza messaggio e dati flag
$msg = '';
$datiflag = [];

// --- Gestione POST (tutti i case) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action=(string)$_POST['action'];
    $target=(int)($_POST['pg'] ?? $_POST['nome'] ?? 0);
    if (in_array($action,['changecarica','setflag','visualizzaflag'],true) && !$db->query('SELECT 1 FROM utenti WHERE id=? AND corporazione=?',[$target,$corpId])) {
        http_response_code(403); exit('Il personaggio non appartiene alla tua corporazione');
    }
    if (in_array($action,['modifycarica','dropcarica'],true) && !$db->query('SELECT 1 FROM carichecorporazioni WHERE id=? AND corporazione=?',[(int)($_POST['carica'] ?? 0),$corpId])) {
        http_response_code(403); exit('Carica non appartenente alla tua corporazione');
    }
    if (in_array($action,['addcarica','modifycarica'],true) && isset($_POST['nome'],$_POST['ramo']) && !$db->query('SELECT 1 FROM ramicorporazioni WHERE id=? AND corporazione=?',[(int)$_POST['ramo'],$corpId])) {
        http_response_code(403); exit('Ramo non appartenente alla tua corporazione');
    }
    $groupActionResult=alphano_group_admin_action('corporazioni',$corpId,$pgid,(string)$action,$_POST,$_FILES);
    if ($groupActionResult!==null) $msg=$groupActionResult;
    else switch ($action) {
        case 'admitpg':
            $pg    = (int)($_POST['pg'] ?? 0);
            $allow = (int)($_POST['admit'] ?? 0);
            $last  = GetLastCaricaCorp($corpId);
            if ($pg > 0 && $last) {
                $ok = AdmitPGCorp($pg, $corpId, $last, $allow);
                $msg = $ok
                     ? ($allow
                        ? GetPGFromID($pg) . " ammesso nella corporazione"
                        : GetPGFromID($pg) . " respinto con successo")
                     : "Errore nell'operazione su " . GetPGFromID($pg);
            } else {
                $msg = $last ? "Parametri non esaurienti" : "Prima carica non impostata";
            }
            break;

        case 'dropramo':
            $ramo = (int)($_POST['ramo'] ?? 0);
            $msg = ($ramo > 0 && DropRamoCorp($corpId, $ramo))
                 ? "Ramo cancellato con successo"
                 : "Errore nella cancellazione del ramo";
            break;

        case 'dropcarica':
            $carica = (int)($_POST['carica'] ?? 0);
            $msg    = ($carica > 0 && DropCaricaCorp($corpId, $carica))
                    ? "Carica cancellata con successo"
                    : "Errore nella cancellazione della carica";
            break;

        case 'addramo':
            $nome = trim($_POST['nome'] ?? '');
            $lvl  = intval($_POST['livello'] ?? -1);
            if ($nome !== '' && $lvl >= 0) {
                $ok  = $db->execute(
                    "INSERT INTO ramicorporazioni (nome,corporazione,livello) VALUES (?, ?, ?)",
                    [$nome, $corpId, $lvl]
                );
                $msg = $ok ? "Nuovo ramo inserito correttamente" : "Errore nell'inserimento del nuovo ramo";
            } else {
                $msg = "Parametri non esaurienti";
            }
            break;

        case 'addcarica':
            $nomeCar = trim($_POST['carica'] ?? '');
            $salaryInput=EconomiaIntero($_POST['stipendio'] ?? 0,0,2147483647);
            $commissionInput=EconomiaPercentuale($_POST['provvigione'] ?? 0);
            $levelInput=EconomiaIntero($_POST['livello'] ?? '',0,2147483647);
            $branchInput=EconomiaIntero($_POST['ramo'] ?? '',0,2147483647);
            if ($nomeCar!=='' && $salaryInput!==null && $commissionInput!==null && $levelInput!==null && $branchInput!==null) {
                $_POST['stipendio']=$salaryInput;$_POST['provvigione']=$commissionInput;
                if ($hasStipendio && ! $hasProvvigione) {
                    $_POST['provvigione'] = '0';
                }
                if ($hasProvvigione && ! $hasStipendio) {
                    $_POST['stipendio'] = '0';
                }
                $_POST['stipendio']   = str_replace(',', '.', (string) ($_POST['stipendio']   ?? '0'));
                $_POST['provvigione'] = str_replace(',', '.', (string) ($_POST['provvigione'] ?? '0'));

                $msg = AddCaricaCorp(
                    $corpId,
                    $_POST['carica'],
                    !empty($_POST['prima']),
                    (int)$_POST['ramo'],
                    (int)$_POST['livello'],
                    trim($_POST['descrizione']),
                    (float)$_POST['stipendio'],
                    (float)$_POST['provvigione'],
                    ($_FILES['immagine'] ?? ['error'=>UPLOAD_ERR_NO_FILE])
                );
            } else {
                $msg = "Parametri non esaurienti";
            }
            break;

        case 'changecarica':
            $pg  = (int)($_POST['pg'] ?? 0);
            $car = (int)($_POST['carica'] ?? 0);
            if ($pg > 0 && $car >= 0) {
                $ok = AdmitPGCorp($pg, $corpId, $car, (bool)$car);
                $msg= $ok
                    ? ($car
                       ? "Modifica carica di " . GetPGFromID($pg) . " avvenuta con successo"
                       : "Espulsione di " . GetPGFromID($pg) . " avvenuta con successo")
                    : "Errore nell'operazione su " . GetPGFromID($pg);
            } else {
                $msg = "Parametri non esaurienti";
            }
            break;

        case 'makerichiesta':
            $orgId = EconomiaIntero($_POST['org'] ?? '',1);
            $qta = EconomiaIntero($_POST['quantita'] ?? '',1,2147483647);
            $int = EconomiaIntero($_POST['interessi'] ?? '',0,100);
            if ($orgId && $qta!==null && $int!==null) {
                $ok = EconomiaAzioneModulo(static fn(): bool => CreatePrestito($pgid,$corpId,$orgId,$qta,$int,$_POST['_request_key'] ?? null));
                $msg = $ok ? 'Richiesta creata correttamente' : 'Richiesta non consentita o già inviata';
            } else $msg = 'Inserisci quantità intere positive e interessi tra 0 e 100';
            break;

        case 'adminprestito':
            $pid = EconomiaIntero($_POST['prestito'] ?? '',1);
            $acc = !empty($_POST['accetta']);
            $canc = !empty($_POST['cancella']);
            $qta = EconomiaIntero($_POST['quantita'] ?? '',1,2147483647);
            $int = EconomiaIntero($_POST['interessi'] ?? '',0,100);
            $pag = EconomiaIntero($_POST['pagamento'] ?? '',1);
            if ($pid && ($acc || $canc || $pag!==null || ($qta!==null && $int!==null))) {
                $ok = EconomiaAzioneModulo(static function() use($pid,$pgid,$acc,$canc,$qta,$int,$pag): bool {
                    if ($acc) return AcceptPrestito($pid,$pgid,$_POST['_request_key'] ?? null);
                    if ($canc) return DeletePrestito($pid,$pgid,$_POST['_request_key'] ?? null);
                    if ($pag!==null) return PagaPrestito($pid,$pgid,(float)$pag,$_POST['_request_key'] ?? null);
                    return UpdatePrestito($pid,$pgid,$qta,$int,2,$_POST['_request_key'] ?? null);
                });
                $msg = $ok ? 'Prestito aggiornato correttamente' : 'Operazione non consentita, già inviata o fondi insufficienti';
            } else $msg = 'Inserisci importi interi positivi e interessi tra 0 e 100';
            break;

        case 'visualizzaflag':
            $uid = (int)($_POST['nome'] ?? 0);
            if ($uid > 0) {
                $datiflagRows = $db->query(
                    "SELECT admincorporazione,admintrasfcorp,admincatalogo,adminmorti,adminstatosociale,adminferite
                       FROM utenti
                      WHERE id = ?",
                    [$uid]
                );
                $datiflag = $datiflagRows[0] ?? [];
            } else {
                $msg = "Parametri non esaurienti";
            }
            break;

        case 'setflag':
            $uid = (int)($_POST['nome'] ?? 0);
            if ($uid > 0) {
                $fields = [];
                $vals   = [];
                foreach (['admincorporazione','admintrasfcorp','admincatalogo','adminmorti','adminstatosociale','adminferite'] as $f) {
                    if (in_array($f, ['adminmorti','adminstatosociale','adminferite'])) {
                        // queste tre NON vengono settate dalla form
                        continue;
                    }
                    $fields[] = "$f = ?";
                    $vals[]   = !empty($_POST[$f]) ? 1 : 0;
                }
                $vals[] = $uid;
                $vals[] = $corpId;
                $ok     = $db->execute(
                    "UPDATE utenti SET " . implode(', ',$fields) . " WHERE id = ? AND corporazione = ?",
                    $vals
                );
                $msg    = $ok ? "Flag correttamente impostati" : "Errore nell'assegnazione dei flag";
            }
            break;

        case 'modifyramo':
            $rid  = (int)($_POST['ramo'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $lvl  = intval($_POST['livello'] ?? -1);
            if ($rid>0 && $nome!=='' && $lvl>=0) {
                $ok  = $db->execute(
                    "UPDATE ramicorporazioni SET nome=?, livello=? WHERE id=? AND corporazione=?",
                    [$nome,$lvl,$rid,$corpId]
                );
                $msg = $ok ? "Ramo modificato con successo" : "Errore nella modifica del ramo";
            }
            elseif ($rid>0) {
                $datarows = $db->query(
                    "SELECT * FROM ramicorporazioni WHERE id=? AND corporazione=?",
                    [$rid,$corpId]
                );
                $datiramo = $datarows[0] ?? [];
            } else {
                $msg = "Parametri non esaurienti";
            }
            break;

        case 'modifycarica':
            $cid=EconomiaIntero($_POST['carica'] ?? '',1);
            if ($cid && !isset($_POST['nome'])) {
                $daticarica=$db->query('SELECT * FROM carichecorporazioni WHERE id=? AND corporazione=?',[$cid,$corpId])[0] ?? [];
                break;
            }
            $existing=$cid?$db->query('SELECT * FROM carichecorporazioni WHERE id=? AND corporazione=?',[$cid,$corpId])[0] ?? null:null;
            $name=trim((string)($_POST['nome'] ?? ''));
            $salary=EconomiaIntero($_POST['stipendio'] ?? $existing['stipendio'] ?? 0,0,2147483647);
            $commission=EconomiaPercentuale($_POST['provvigione'] ?? $existing['provvigione'] ?? 0);
            $level=EconomiaIntero($_POST['livello'] ?? '',0,2147483647);
            $branch=EconomiaIntero($_POST['ramo'] ?? '',0,2147483647);
            if (!$existing || $name==='' || $salary===null || $commission===null || $level===null || $branch===null) {
                http_response_code(400);$msg='Inserisci stipendio e livello interi non negativi e provvigione tra 0 e 100';break;
            }
            $first=!empty($_POST['prima']);$description=trim((string)($_POST['descrizione'] ?? ''));
            $file=$_FILES['immagine'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
            $ok=EconomiaAzioneModulo(static fn(): bool => EconomiaBool(static function() use($db,$corpId,$pgid,$cid,$name,$salary,$commission,$level,$branch,$first,$description,$file): bool {
                if (!alphano_operation_once('corporation-role-update',$pgid,$_POST['_request_key'] ?? null,[$corpId,$cid,$name,$salary,$commission,$level,$branch,$first,$description])) return true;
                $before=$db->query('SELECT * FROM carichecorporazioni WHERE id=? AND corporazione=? FOR UPDATE',[$cid,$corpId])[0];
                if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                    $result=ModifyCaricaCorp($corpId,$cid,$name,$first,$branch,$level,$description,(float)$salary,$commission,$file);
                    EconomiaVerifica($result==='Carica corporazione aggiornata',$result);
                } else $db->execute('UPDATE carichecorporazioni SET nome=?,prima=?,stipendio=?,provvigione=?,livello=?,ramo=?,descrizione=? WHERE id=? AND corporazione=?',[$name,$first?1:0,$salary,$commission,$level,$branch,$description,$cid,$corpId]);
                $after=$db->query('SELECT * FROM carichecorporazioni WHERE id=? AND corporazione=?',[$cid,$corpId])[0];
                if ($before!==$after) alphano_record_activity('corporation-role-updated',$pgid,[['scope'=>'corporazioni','id'=>$corpId,'section'=>'iscritti','role'=>'admin']],
                    'Carica corporativa aggiornata',alphano_currency_text("{$name}: stipendio {$salary} Corone, provvigione {$commission}%."),['corporazione'=>$corpId,'carica'=>$cid,'prima'=>$before,'dopo'=>$after]);
                return true;
            }));
            $msg=$ok?'Carica modificata con successo':'Impossibile modificare la carica';
            break;

        case 'consegnastipendi':
            $ok=EconomiaAzioneModulo(static fn(): bool => Consegnastipendi($corpId,$_POST['_request_key'] ?? null));
            $msg=$ok ? 'Stipendi consegnati correttamente' : 'Stipendi non consegnati: controlla fondi, cariche e metodo di pagamento';
            break;

        case 'modifypvt':
            $ct = (int)($_POST['city'] ?? 0);
            if (isset($listacity[$ct])) {
                $enc = base64_encode(trim(stripslashes($_POST['pvt'] ?? '')));
                $ok  = $db->execute(
                    "UPDATE corporazioni
                        SET city=?, sito=?, pvt=?, sfondo=?, sfondotop=?, mappa=?, descrizione=?
                      WHERE id=?",
                    [
                      $ct,
                      trim($_POST['sito'] ?? ''),
                      $enc,
                      trim($_POST['sfondo'] ?? ''),
                      trim($_POST['sfondotop'] ?? ''),
                      trim($_POST['mappa'] ?? ''),
                      trim($_POST['descrizione'] ?? ''),
                      $corpId
                    ]
                );
                $msg = $ok ? "Dati modificati con successo" : "Errore nella modifica dei dati";
            } else {
                $msg = "Parametri non esaurienti";
            }
            break;

        case 'setmetodo':
            $st=!empty($_POST['stipendi'])?1:0;$pv=!empty($_POST['provvigioni'])?1:0;
            if (!$st && !$pv) {$msg='Devi selezionare almeno un metodo di pagamento';break;}
            $ok=EconomiaAzioneModulo(static fn(): bool => EconomiaBool(static function() use($db,$corpId,$pgid,$st,$pv): bool {
                if (!alphano_operation_once('corporation-payment-method',$pgid,$_POST['_request_key'] ?? null,[$corpId,$st,$pv])) return true;
                $old=$db->query('SELECT stipendio,provvigione FROM corporazioni WHERE id=? FOR UPDATE',[$corpId])[0];
                $db->execute('UPDATE corporazioni SET stipendio=?,provvigione=? WHERE id=?',[$st,$pv,$corpId]);
                if ((int)$old['stipendio']!==$st || (int)$old['provvigione']!==$pv) alphano_record_activity('corporation-payment-method-updated',$pgid,[['scope'=>'corporazioni','id'=>$corpId,'section'=>'finanze','role'=>'admin']],
                    'Metodi di pagamento aggiornati',($st?'Stipendi attivi. ':'Stipendi disattivati. ').($pv?'Provvigioni attive.':'Provvigioni disattivate.'),['corporazione'=>$corpId,'prima'=>$old,'dopo'=>['stipendio'=>$st,'provvigione'=>$pv]]);
                return true;
            }));
            $msg=$ok?'Metodi di pagamento impostati correttamente':'Impossibile impostare i metodi di pagamento';
            break;

        case 'setproduction':
            $selection=[];$valid=true;
            foreach ($subtipi as $sid=>$sname) {
                $value=EconomiaIntero($_POST['produzione'.$sid] ?? 0,0);
                if ($value===null || !isset($quality[$value])) {$valid=false;break;}
                $selection[$sid]=$value;
            }
            if (!$valid) {http_response_code(400);$msg='Qualità non valida';break;}
            $ok=EconomiaAzioneModulo(static fn(): bool => ImpostaProduzioniCorp($corpId,$pgid,$selection,$_POST['_request_key'] ?? null));
            $msg=$ok ? 'Produzioni aggiornate con successo' : 'Produzioni non aggiornate: controlla i punti disponibili';
            break;
    }
}

// Le viste sono derivate dal DB dopo le operazioni, così mostrano subito i dati salvati.
$currentCorp=$db->query('SELECT * FROM corporazioni WHERE id=?',[$corpId])[0];
$budget=(int)$currentCorp['budget']; $ultimoStip=(int)$currentCorp['ultimostipendio'];
$hasStipendio=(bool)$currentCorp['stipendio']; $hasProvvigione=(bool)$currentCorp['provvigione'];
$stipendiTot=$hasStipendio ? GetStipendi($corpId) : 0;
$sitoCorp=htmlspecialchars((string)$currentCorp['sito'],ENT_QUOTES); $cityCorp=(int)$currentCorp['city'];
$pvtRoom=base64_decode((string)$currentCorp['pvt']);
$sfondoTop=htmlspecialchars($currentCorp['sfondotop'],ENT_QUOTES); $sfondoBg=htmlspecialchars($currentCorp['sfondo'],ENT_QUOTES);
$mappaRoom=htmlspecialchars($currentCorp['mappa'],ENT_QUOTES); $descrRoom=htmlspecialchars($currentCorp['descrizione'],ENT_QUOTES);
// Carica produzioni correnti
$produzioni = [];
$pointValueLeft = maxpointvalue;
foreach ($db->query("SELECT produzione, quality FROM produzionicorporazioni WHERE corporazione = ?", [$corpId]) as $r) {
    $produzioni[(int)$r['produzione']] = (int)$r['quality'];
    $pointValueLeft -= $quality[(int)$r['quality']]['costo'];
}

// Gerarchie, rami, richieste, membri, cariche, prestiti
$cariche   = [];
foreach (GetGerarchiaCorp($corpId) as $row) {
    $lvl = (int)$row['livello'];
    $ram = (int)$row['ramo'];
    $cid = (int)$row['idcarica'];
    $cariche[$lvl][$ram]['nomeramo']           = $row['nomeramo'] ?? 'Senza ramo';
    $cariche[$lvl][$ram]['cariche'][$cid]      = $row;
}
$rami       = GetListaRamiCorp($corpId);
$richieste  = GetRichiesteCorp($corpId);
$membri     = GetPGInCorp($corpId);
$lc         = GetListaCaricheCorp($corpId);
$lista1     = ListaPrestitiCorp($corpId);          // prestiti inoltrati
$lista2     = ListaPrestitiPGCorp($pgid, $corpId);  // prestiti in entrata


// --- HTML + CSS + JS come nel vecchio file, solo adattato a PDO/PHP 8 ---
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin <?= $nomeCorp ?> • <?= TitoloSito ?></title>

  <script>
    document.addEventListener('DOMContentLoaded', ()=>{
      const tabs = document.querySelectorAll('.tabs a');
      const panes = document.querySelectorAll('.tab-content > div');
      function activate(id){
        tabs.forEach(a=> a.classList.toggle('active', a.getAttribute('href')===('#'+id)));
        panes.forEach(p=> p.classList.toggle('active', p.id===id));
      }
      tabs.forEach(a=> a.addEventListener('click', e=>{
        e.preventDefault();
        activate(a.getAttribute('href').substring(1));
      }));
      const defaultTab = <?= !empty($datiflag) ? "'flag'" : "'iscritti'" ?>;
      const initial=location.hash.slice(1);activate([...panes].some(p=>p.id===initial)?initial:defaultTab);

      const maxpointvalue = <?= maxpointvalue ?>;
      const tipi = [];
      <?php foreach($subtipi as $sid => $sname): ?>
        tipi[<?= $sid ?>] = <?= $produzioni[$sid] ?? 0 ?>;
      <?php endforeach; ?>
      const quality = [];
      <?php foreach($quality as $qk => $qv): ?>
        quality[<?= $qk ?>] = {
          nome: "<?= addslashes($qv['nome']) ?>",
          durata: <?= $qv['durata'] ?>,
          costo: <?= $qv['costo'] ?>
        };
      <?php endforeach; ?>
      const productionCategories=<?=json_encode($categorie,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
      window.ShowDetails=tipo=>{
        const details=document.getElementById('dettagli');
        const categories=productionCategories[tipo]||[];
        details.textContent=categories.length?categories.join(' · '):'Nessuna categoria disponibile per questa produzione.';
      };
      function GetTotalSelection(){
        let n=0; for(let i in tipi) n+=quality[tipi[i]]?.costo||0; return n;
      }
      window.Controlla = (sel,tipo)=>{
        const previous=tipi[tipo]||0;
        tipi[tipo]=quality[Number(sel.value)]?Number(sel.value):0;
        let n=GetTotalSelection();
        if(n>maxpointvalue){
          alert("Hai superato il punteggio massimo");
          tipi[tipo]=previous;sel.value=String(previous);
          n=GetTotalSelection();
        }
        const selected=quality[tipi[tipo]]||quality[0];
        document.getElementById("points").innerText = maxpointvalue-n;
        document.getElementById("dettagli").innerHTML =
          `Nome: ${selected.nome}<br>`+
          `Durata max: ${selected.durata}<br>`+
          `Costo PV: ${selected.costo}`;
        if(maxpointvalue-n===0) alert("Point Value esaurito");
      };
      window.SetProduction = ()=>{
        if(GetTotalSelection()<maxpointvalue){
          if(!confirm("PV residui, confermi?")) return;
        }
        document.getElementById('frmProduction').submit();
      };
      window.ResetProduction=()=>{
        const form=document.getElementById('frmProduction');form.reset();
        form.querySelectorAll('select[name^="produzione"]').forEach(select=>{
          tipi[Number(select.name.slice('produzione'.length))]=Number(select.value)||0;
        });
        document.getElementById('points').innerText=maxpointvalue-GetTotalSelection();
        document.getElementById('dettagli').textContent='Qui compariranno le descrizioni...';
      };
    });
  </script>
</head>
<body>


  <div class="container">
    <header class="ui-page-heading" data-page-heading data-header-image="/assets/placeholder.svg"><span data-icon="shield"></span><div><span class="ui-eyebrow">Alphano · Governo corporativo</span><h1><?= $nomeCorp ?></h1><p>Persone, cariche, finanze e attività della corporazione.</p></div><nav class="ui-module-toolbar"><a class="action-btn" href="/governo/?scope=corporazioni&amp;id=<?=$corpId?>" data-icon="coins">Bilancio e stipendi</a><a class="action-btn" href="/gruppi/identita.php?scope=corporazioni&amp;id=<?=$corpId?>" data-icon="shield">Stendardo e testata</a><a class="action-btn" href="/notifiche/?group=corporazioni" data-icon="scroll">Cronaca</a></nav>
    <?php if($msg!==''): ?>
      <div class="u-96524d740"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <ul class="tabs ui-module-toolbar">
      <li><a href="#iscritti">Iscritti</a></li>
      <li><a href="#finanze">Finanze</a></li>
      <li><a href="#flag">Privilegi</a></li>
      <li><a href="#pvt">Interna</a></li>
      <li><a href="#production">Produzioni</a></li>
    </ul></header>

    <div class="tab-content">
      <!-- ISCRITTI -->
      <div id="iscritti">
        <h4>Richieste di ammissione</h4>
        <?php if(count($richieste)): ?>
          <table>
            <thead><tr><th>Personaggio</th><th>Data</th><th colspan="2">Azione</th></tr></thead>
            <tbody>
              <?php foreach($richieste as $r): ?>
                <tr>
                  <td><a href="#" onclick="openProfileId('<?= $r['idpg'] ?>')"><?= htmlentities(GetPGFromID($r['idpg'])) ?></a></td>
                  <td><?= FormatTimestamp($r['data']) ?></td>
                  <td>
                    <form method="post" class="u-434fc32ec"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
                      <input type="hidden" name="action" value="admitpg">
                      <input type="hidden" name="pg" value="<?= $r['idpg'] ?>">
                      <input type="hidden" name="admit" value="1">
                      <button class="btn">Accetta</button>
                    </form>
                  </td>
                  <td>
                    <form method="post" class="u-434fc32ec"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
                      <input type="hidden" name="action" value="admitpg">
                      <input type="hidden" name="pg" value="<?= $r['idpg'] ?>">
                      <input type="hidden" name="admit" value="0">
                      <button class="btn">Respingi</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>Nessuna richiesta pervenuta.</p>
        <?php endif; ?>

        <hr>
        <h4>Gerarchia della Corporazione <?= $hasStipendio?"(stipendio)":"" ?> <?= $hasProvvigione?"(provvigione)":"" ?></h4>
        <form id="frmGer" method="post" class="u-f2892a2e8"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
          <input type="hidden" name="action">
          <input type="hidden" name="ramo">
          <input type="hidden" name="carica">
        </form>
        <div class="ui-table-scroll" tabindex="0" role="region" aria-label="Gerarchia della corporazione">
        <table class="ui-hierarchy-table">
          <?php foreach($cariche as $lvl => $ramiLivello): ?>
            <tr>
              <?php foreach($ramiLivello as $idRamo=>$gruppo): ?>
                <td>
                  <strong>
                    <a href="#" onclick="document.getElementById('frmGer').action.value='modifyramo';document.getElementById('frmGer').ramo.value='<?= $idRamo ?>';document.getElementById('frmGer').submit();">
                      <?= htmlentities($gruppo['nomeramo']) ?>
                    </a>
                  </strong>
                  <ul class="u-1ac13a3d3">
                    <?php foreach($gruppo['cariche'] as $idCar=>$car):
                      $flags=[]; if($hasStipendio&&($car['stipendio'] ?? 0)>0)$flags[]=($car['stipendio'] ?? 0).alphano_currency_html(" Corone");
                      if($hasProvvigione&&($car['provvigione'] ?? 0)>0)$flags[]=($car['provvigione'] ?? 0)."%";
                      if(($car['prima'] ?? 0))$flags[]='★';
                    ?>
                      <li>
                        <a href="#" onclick="document.getElementById('frmGer').action.value='modifycarica';document.getElementById('frmGer').carica.value='<?= $idCar ?>';document.getElementById('frmGer').submit();">
                          <?=GetCaricaCorp($corpId,(int)$idCar,false)?>
                          <?= htmlentities($car['nomecarica']) ?>
                        </a>
                        <?php if($flags): ?><small>(<?= implode(' | ',$flags) ?>)</small><?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </table>
        </div>

        <!-- Aggiungi/Modifica Ramo -->
        <div class="u-5f6fe1c69">
          <form method="post" class="u-ee3d55bf9"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <h5><?= isset($datiramo)?'Modifica Ramo':'Aggiungi Ramo' ?></h5>
            <input type="hidden" name="action" value="<?= isset($datiramo)?'modifyramo':'addramo' ?>">
            <?php if(isset($datiramo)):?><input type="hidden" name="ramo" value="<?= $datiramo['id'] ?>"><?php endif; ?>
            <label>Nome<input type="text" name="nome" value="<?= $datiramo['nome']??'' ?>"></label>
            <label>Livello<input type="number" name="livello" min="0" value="<?= $datiramo['livello']??0 ?>"></label>
            <button class="btn"><?= isset($datiramo)?'Salva':'Aggiungi' ?></button>
            <?php if(isset($datiramo)):?><a href="#" onclick="location.reload()" class="btn">Annulla</a><?php endif; ?>
          </form>
          <form method="post" class="u-ee3d55bf9"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <h5>Elimina Ramo</h5>
            <input type="hidden" name="action" value="dropramo">
            <label>
              Seleziona
              <select name="ramo">
                <option value="">-- seleziona --</option>
                <?php foreach($rami as $id=>$n):?><option value="<?= $id ?>"><?= htmlentities($n) ?></option><?php endforeach; ?>
              </select>
            </label>
            <button class="btn">Elimina</button>
          </form>
        </div>

        <!-- Aggiungi/Modifica Carica -->
        <div class="u-5fa693f3d">
          <h5><?= isset($daticarica)?'Modifica Carica':'Aggiungi Carica' ?></h5>
          <form method="post" enctype="multipart/form-data"><input type="hidden" name="corp" value="<?=$corpId?>">
            <input type="hidden" name="action" value="<?= isset($daticarica)?'modifycarica':'addcarica' ?>">
            <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <?php if(isset($daticarica)):?><input type="hidden" name="carica" value="<?= $daticarica['id'] ?>"><?php endif; ?>
            <div class="u-f3e26fadb">
              <label class="u-e9a0cdfa2">Icona<input type="file" name="immagine"></label>
              <label class="u-ee3d55bf9">Nome<input type="text" name="<?= isset($daticarica)?'nome':'carica'?>" value="<?= $daticarica['nome']??'' ?>"></label>
              <label class="u-21716f5bd">Prima<input type="checkbox" name="prima" <?= !empty($daticarica['prima'])?'checked':''?>></label>
              <label class="u-55475ae7d">Livello<input type="number" name="livello" min="0" value="<?= $daticarica['livello']??0 ?>"></label>
              <label class="u-4ae7557d5">
                Ramo<select name="ramo">
                  <?php foreach($rami as $id=>$n):?><option value="<?= $id ?>" <?= isset($daticarica)&&$daticarica['ramo']==$id?'selected':''?>><?= htmlentities($n) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="u-ee3d55bf9">Descrizione<textarea rows="2" name="descrizione"><?= $daticarica['descrizione']??''?></textarea></label>
            </div>
            <div class="u-eca1aa8a9">
              <?php if($hasStipendio):?><label>Stipendio<input type="number" name="stipendio" min="0" max="2147483647" step="1" value="<?= $daticarica['stipendio']??'' ?>"></label><?php endif;?>
              <?php if($hasProvvigione):?><label>Provvigione<input type="number" name="provvigione" min="0" max="100" step="0.01" value="<?= $daticarica['provvigione']??'' ?>"></label><?php endif;?>
            </div>
            <button class="btn"><?= isset($daticarica)?'Salva Carica':'Aggiungi Carica' ?></button>
            <?php if(isset($daticarica)): ?>
              <button type="submit" name="action" value="dropcarica" class="btn">Cancella Carica</button>
            <?php endif; ?>
          </form>
        </div>

        <hr>
        <h4>Componenti della Corporazione</h4>
        <?php if(count($membri)): ?>
          <form method="post" class="u-26161947e"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <input type="hidden" name="action" value="changecarica">
            <label class="u-ee3d55bf9">Personaggio
              <select name="pg">
                <option value="">-- Personaggio --</option>
                <?php foreach($membri as $r):?>
                  <option value="<?= $r['id'] ?>"><?= htmlentities($r['nome']) ?></option>
                <?php endforeach;?>
              </select>
            </label>
            <label class="u-ee3d55bf9">Carica
              <select name="carica">
                <?php foreach($lc as $c):?>
                  <option value="<?= $c['id'] ?>"><?= htmlentities($c['nome']) ?></option>
                <?php endforeach;?>
                <option value="0">Espelli</option>
              </select>
            </label>
            <button class="btn">Modifica</button>
          </form>
        <?php else: ?>
          <p>Nessun personaggio in questa corporazione.</p>
        <?php endif; ?>
      </div>

      <!-- FINANZE -->
      <div id="finanze">
        <h4>Budget attuale: <?= $budget ?> <?=alphano_currency_html()?></h4>
        <h5>Metodi di pagamento</h5>
        <form method="post"><input type="hidden" name="corp" value="<?=$corpId?>">
          <input type="hidden" name="action" value="setmetodo">
          <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
          <label><input type="checkbox" name="stipendi" value="1" <?= $hasStipendio?'checked':''?>> Stipendi</label>
          <label><input type="checkbox" name="provvigioni" value="1" <?= $hasProvvigione?'checked':''?>> Provvigioni</label>
          <button class="btn">Applica</button>
        </form>

        <?php if($hasStipendio): ?>
          <h5>Stipendi</h5>
          <form method="post"><input type="hidden" name="corp" value="<?=$corpId?>">
            <input type="hidden" name="action" value="consegnastipendi">
            <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <table>
              <tr><th>Ultima consegna</th><th>Ammontare</th><th>Azione</th></tr>
              <tr>
                <td><?= $ultimoStip===0?'Nessuno':FormatTimestamp($ultimoStip) ?></td>
                <td><?= $stipendiTot ?> <?=alphano_currency_html()?></td>
                <td><?php if($budget>= $stipendiTot):?><button class="btn">Consegna</button><?php else:?><span class="u-3e06b323c">Fondi insufficienti</span><?php endif;?></td>
              </tr>
            </table>
          </form>
        <?php endif; ?>

        <h5>Prestiti inoltrati</h5>
        <table>
          <thead><tr><th>Data</th><th>Da</th><th>Org</th><th>Interlocutore</th><th>Quantità</th><th>Interessi</th><th>Stato</th><th>Rimanente</th></tr></thead>
          <tbody>
            <?php foreach($lista1 as $d):
              $rim = (int)$d['stato']===5 ? CalcolaInteressi((int)$d['quantita'],(int)$d['interessi'],(int)$d['data'])-(int)$d['restituiti'] : ((int)$d['stato']<5 ? (int)$d['quantita'] : 0);
            ?>
              <tr>
                <td><?= FormatTimestamp($d['data']) ?></td>
                <td><?= htmlentities(GetPGFromID($d['pgcorp'])) ?></td>
                <td><?= htmlentities(GetOrg($d['org'])) ?></td>
                <td><?= $d['pgorg']?htmlentities(GetPGFromID($d['pgorg'])):'Nessuno' ?></td>
                <td><?= $d['quantita'] ?></td>
                <td><?= $d['interessi'] ?>%</td>
                <td><?= ProcessStato($d['stato']) ?></td>
                <td><?= $rim ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h5>Gestisci i tuoi prestiti</h5>
        <p>Le controproposte devono essere accettate dall’altra parte. Gli interessi maturano dal finanziamento, per mesi interi.</p>
        <?php foreach ($lista2 as $d): $state=(int)$d['stato']; if ($state>=6) continue; ?>
          <article class="ui-panel">
            <h6>Prestito #<?=(int)$d['id']?> · <?=htmlspecialchars(GetOrg((int)$d['org']),ENT_QUOTES)?></h6>
            <p><?=ProcessStato($state)?> · <?=(int)$d['quantita']?> <?=alphano_currency_html()?> · <?=(int)$d['interessi']?>% al mese</p>
            <?php if ($state<5): ?>
              <form method="post" class="ui-form"><input type="hidden" name="corp" value="<?=$corpId?>">
                <input type="hidden" name="action" value="adminprestito">
                <input type="hidden" name="prestito" value="<?=(int)$d['id']?>">
                <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
                <?php if ($state===3): ?>
                  <label>Quantità <input type="number" name="quantita" min="1" max="2147483647" step="1" required value="<?=(int)$d['quantita']?>"></label>
                  <label>Interessi mensili % <input type="number" name="interessi" min="0" max="100" step="1" required value="<?=(int)$d['interessi']?>"></label>
                  <button class="btn" type="submit" name="accetta" value="1">Accetta le condizioni</button>
                  <button class="btn" type="submit">Invia controproposta</button>
                <?php endif; ?>
                <button class="btn" type="submit" name="cancella" value="1" formnovalidate>Ritira richiesta</button>
              </form>
            <?php elseif ($state===5): $residual=max(0,CalcolaInteressi((int)$d['quantita'],(int)$d['interessi'],(int)$d['data'])-(int)$d['restituiti']); ?>
              <form method="post" class="ui-form"><input type="hidden" name="corp" value="<?=$corpId?>">
                <input type="hidden" name="action" value="adminprestito">
                <input type="hidden" name="prestito" value="<?=(int)$d['id']?>">
                <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
                <p>Già restituiti: <?=(int)$d['restituiti']?> <?=alphano_currency_html()?>. Debito residuo: <?=$residual?> <?=alphano_currency_html()?>.</p>
                <label>Rimborso <input type="number" name="pagamento" min="1" max="<?=min($budget,$residual)?>" step="1" required value="<?=min($budget,$residual)?>"></label>
                <button class="btn" type="submit" <?=$budget<1?'disabled':''?>>Rimborsa</button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>

        <h5>Crea una richiesta di Prestito</h5>
        <form method="post"><input type="hidden" name="corp" value="<?=$corpId?>">
          <input type="hidden" name="action" value="makerichiesta">
          <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
          <label>Organizzazione
            <select name="org" required>
              <option value="">-- seleziona --</option>
              <?php foreach(ListaOrg() as $o): if($o['id']!=$user['organizzazione']): ?>
                <option value="<?= $o['id'] ?>"><?= htmlentities($o['nome']) ?></option>
              <?php endif; endforeach;?>
            </select>
          </label>
          <label>Quantità<input type="number" name="quantita" min="1" max="2147483647" step="1" required></label>
          <label>Interessi<input type="number" name="interessi" min="0" max="100" step="1" value="0" required></label>
          <button class="btn">Invia</button>
        </form>
      </div>

      <!-- PRIVILEGI -->
      <div id="flag">
        <h4>Privilegi</h4>
        <p>Per ottenere l'abilitazione di una Flag non abilitata, invia una richiesta ad un Governatore.</p>
        <form id="frmFlag" method="post"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
          <input type="hidden" name="action" value="<?= empty($datiflag)?'visualizzaflag':'setflag' ?>">
          <label>Personaggio
            <select name="nome">
              <option value="">-- seleziona --</option>
              <?php foreach(GetPGInCorp($corpId) as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($_POST['nome'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                  <?= htmlentities($u['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="btn">
            <?= empty($datiflag) ? 'Visualizza Flag' : 'Salva Flag' ?>
          </button>

          <?php if(!empty($datiflag)): ?>
            <fieldset class="u-885ca2788">
              <legend>Modifica privilegi</legend>
              <?php foreach (['admincorporazione'=>'Amministratore','admintrasfcorp'=>'Ordini','admincatalogo'=>'Catalogo'] as $f=>$label): ?>
                <label><input type="checkbox" name="<?= $f ?>" <?= !empty($datiflag[$f])?'checked':'' ?>> <?= $label ?></label>
              <?php endforeach; ?>
              <div class="u-f4214b080">
                <button onclick="document.getElementById('frmFlag').action.value='setflag'" class="btn">Esegui</button>
                <button type="reset" class="btn">Annulla</button>
              </div>
            </fieldset>
          <?php endif; ?>
        </form>
      </div>

      <!-- INTERNA -->
      <div id="pvt">
        <h4>Interna</h4>
        <form id="frmPvt" method="post"><input type="hidden" name="corp" value="<?=$corpId?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
          <input type="hidden" name="action" value="modifypvt">
          <label>Codice Corporazione<input type="text" readonly value="<?= $corpId ?>"></label>
          <label>Città della Corporazione
            <select name="city">
              <?php foreach($listacity as $id=>$c): ?>
                <option value="<?= $id ?>" <?= $cityCorp==$id?'selected':'' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Password Stanza<input type="text" name="pvt" value="<?= htmlspecialchars($pvtRoom,ENT_QUOTES) ?>"></label>
          <label>Immagine Top<input type="text" name="sfondotop" value="<?= $sfondoTop ?>"></label>
          <label>Immagine Sfondo<input type="text" name="sfondo" value="<?= $sfondoBg ?>"></label>
          <label>Mappa<input type="text" name="mappa" value="<?= $mappaRoom ?>"></label>
          <label>Descrizione<textarea name="descrizione"><?= $descrRoom ?></textarea></label>
          <label>Sito Corporazione<input type="text" name="sito" value="<?= $sitoCorp ?>"></label>
          <div class="u-f932c5a58">
            <button type="submit" class="btn">Modifica</button>
            <button type="reset" class="btn">Annulla</button>
          </div>
        </form>
      </div>

      <!-- PRODUZIONI -->
      <div id="production">
        <h4>Produzioni</h4>
        <p class="Descrizione">Le produzioni della Corporazione devono essere coerenti allo statuto</p>
        <div class="ui-production-layout">
          <form id="frmProduction" method="post"><input type="hidden" name="corp" value="<?=$corpId?>">
            <input type="hidden" name="action" value="setproduction">
          <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <div class="ui-table-scroll" tabindex="0" role="region" aria-label="Qualità delle produzioni">
                <table id="production-categories" class="ui-data-table">
                  <thead><tr><th scope="col">Categorie</th><th scope="col">Qualità</th></tr></thead><tbody>
                  <?php foreach($subtipi as $sid=>$sname): ?>
                    <tr>
                      <td><a href="javascript:ShowDetails(<?= $sid ?>)"><?= htmlentities($sname) ?></a></td>
                      <td>
                        <select name="produzione<?= $sid ?>" onchange="Controlla(this,<?= $sid ?>)">
                          <?php foreach($quality as $qk=>$qv): ?>
                            <option value="<?= $qk ?>" <?= ($produzioni[$sid]??0)==$qk?'selected':'' ?>>
                              <?= htmlentities($qv['nome']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
            </div>
          </form>
          <aside class="ui-production-summary" aria-label="Riepilogo delle produzioni">
            <h3>Point Value</h3>
            <p>Punti rimanenti: <strong id="points" aria-live="polite"><?= $pointValueLeft ?></strong></p>
            <h3>Dettagli</h3>
            <p id="dettagli" aria-live="polite">Qui compariranno le descrizioni...</p>
          </aside>
        </div>
        <div class="ui-row-actions">
          <button type="button" onclick="SetProduction()" class="btn">Applica Modifiche</button>
          <button type="button" onclick="ResetProduction()" class="btn">Annulla Modifiche</button>
        </div>
      </div>

    </div><!-- /.tab-content -->
  </div><!-- /.container -->
</body>
</html>

<?php ClosePage(); ?>
