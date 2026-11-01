<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>true,'message'=>'Parametri non validi']);
        exit;
    }
}

// I dettagli tecnici restano nel log; il client riceve JSON valido.
error_reporting(E_ALL);
ini_set('display_errors', 0);

// restituiamo sempre JSON
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ─── SUPPORTO JSON PER fetch(...) ───────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        // anziché fare array_merge, cicliamo e castiamo a stringa
        foreach ($json as $k => $v) {
            if (is_scalar($v)) {
                $_POST[$k] = (string)$v;
            } else {
                // se ti aspetti anche array annidati, puoi far ricorsivo qui
                http_response_code(400); echo json_encode(['success'=>false,'error'=>true,'message'=>'Parametri non validi']); exit;
            }
        }
    }
}


require_once __DIR__ . '/../../libs/kernel.inc.php';
require_once __DIR__ . '/../../libs/libpg.inc.php';
require_once __DIR__ . '/../../libs/libgrafica.inc.php';
require_once __DIR__ . '/../../libs/libeconomia.inc.php';
require_once __DIR__ . '/../../libs/libposta.inc.php';
require_once __DIR__ . '/../../libs/libtext.inc.php';
require_once __DIR__ . '/../../libs/libequip.inc.php';
require_once __DIR__.'/../../libs/item-engine.inc.php';


use function Alphano\Libs\ResizeImage;

try {

    // ─── HELPERS ───────────────────────────────────────────────────────────────
    function respond(array $data): void {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    function buildJsArray(iterable $rows): array {
        $out = [];$stocks=array_column(ConnectDB()->query('SELECT model_id,available FROM equipment_breeding'),'available','model_id');
        foreach ($rows as $i => $row) {
            $materie = function_exists('GetMateriePrime')
                     ? GetMateriePrime($row)
                     : (isset($row['materie']) && is_array($row['materie']) ? $row['materie'] : []);
            $out[] = [
                'pos'          => $i,
                'id'           => (int)    ($row['id']           ?? 0),
                'shortDesc'    => (string) ($row['shortDesc']     ?? $row['shortdesc']     ?? ''),
                'immagine'     => (string) ($row['immagine']     ?? $row['tipo_immagine'] ?? ''),
                'desc'         => trim((string)($row['desc']      ?? $row['descrizione']  ?? '')),
                'tipo'         => (string) ($row['tipo']         ?? ''),
                'available'=>(int)($stocks[$row['id']]??0),'department'=>(string)($row['department']??''),'rules'=>item_spec($row['game_rules']??'{}'),
                'production' => (int)($row['production_id']??0),
                'tipoId'       => (int)($row['tipo_id'] ?? 0),
                'tagliaId'     => (int)($row['taglia'] ?? 0),
                'destorgId'    => (int)($row['destorg'] ?? 0),
                'damage'       => (int)ceil(100*(int)($row['consumo'] ?? 0)/max(1,(int)($row['durata'] ?? 1))),
                'taglia'       => function_exists('getSize')
                                    ? getSize((int) ($row['taglia'] ?? 0))
                                    : (string) ($row['taglia'] ?? ''),
                'peso'         => (float)  ($row['peso']          ?? 0),
                'tipoG'        => (string) ($row['tipoG']        ?? ''),
                'mani'         => (int)    ($row['mani']           ?? 0),
                'sacro'        => !empty($row['sacro']),
                'restore'      => (int)    ($row['restore']        ?? 0),
                'creatore'     => function_exists('GetPGFromID')
                                    ? GetPGFromID((int)($row['idcreatore'] ?? 0))
                                    : '',
                'lavorazione'  => (string) ($row['lavorazione']   ?? ''),
                'destinatario' => !empty($row['destinatario'])
                                    ? (string)GetPGFromID((int)$row['destinatario'])
                                    : '',
                'destorg'      => function_exists('GetOrg')
                                    ? GetOrg((int)($row['destorg'] ?? 0), false)
                                    : '',
                'quality'      => function_exists('getQuality')
                                    ? getQuality((int)($row['durata'] ?? 0))
                                    : '',
                'durata'       => (int)    ($row['durata']         ?? 0),
                'materie'      => isset($row['consumo']) ? array_map(static fn($q)=>(int)ceil((int)$q*min((int)$row['consumo'],(int)$row['durata'])/max(1,(int)$row['durata'])), $materie) : $materie,
                'approvato'    => isset($row['approvato'])
                                    ? (bool)$row['approvato']
                                    : true,
            ];
        }
        return $out;
    }

    // ─── AUTENTICAZIONE ─────────────────────────────────────────────────────────
    $idpg = alphano_require_login(true);
    if ($idpg === null) {
        respond(['error'=>'Non autenticato']);
    }
    $db      = getDB();


    $user = $db->query(
        'SELECT nome, admin, organizzazione, corporazione, admincatalogo, admincorporazione, razza
        FROM utenti WHERE id = ?',
        [$idpg]
    )[0] ?? null;
    if (!$user) {
        respond(['error'=>'Utente non trovato']);
    }


    $nome = $user['nome'];

    $admincatalogo = (bool)$user['admincatalogo'];
    $admincorp     = (bool)$user['admincorporazione'];
    $org           = (int)$user['organizzazione'];


    // ─── DETERMINA CORPORAZIONE E CONTATORI ─────────────────────────────────────
    $count = 0;
    $corp  = trim((string)($_POST['corp'] ?? $_GET['corp'] ?? ''));
    if ($corp === '') {
        $tmp = $db->query(
            'SELECT corporazione, admincatalogo, admincorporazione FROM utenti WHERE id = ?',
            [$idpg]
        )[0];
        $corp          = $tmp['corporazione'];
        $admincatalogo = (bool)$tmp['admincatalogo'];
        $admincorp     = (bool)$tmp['admincorporazione'];
        if ($admincatalogo) {
            $c1    = (int)$db->query('SELECT COUNT(*) AS tot FROM equip WHERE approvato=0 AND idcreatore=?',[$idpg])[0]['tot'];
            $c2    = (int)$db->query('SELECT COUNT(*) AS tot FROM newequip WHERE idcreatore=?',[$idpg])[0]['tot'];
            $count = $c1 + $c2;
        }
    }
    if (!$corp) {
        respond(['error'=>'Nessuna corporazione selezionata']);
    }

    $corp=(int)$corp;
    if (!$db->query('SELECT 1 FROM corporazioni WHERE id=?',[$corp])) { http_response_code(404); respond(['error'=>true,'message'=>'Corporazione non trovata']); }
    $isGlobalAdmin=(bool)$user['admin'];
    $sameCorp=(int)$user['corporazione']===$corp;
    $admincatalogo=$isGlobalAdmin || ($sameCorp && $admincatalogo);
    $admincorp=$isGlobalAdmin || ($sameCorp && $admincorp);
    $count=(int)$db->query('SELECT COUNT(*) AS n FROM equip WHERE approvato=0 AND idcreatore=?',[$idpg])[0]['n']+(int)$db->query('SELECT COUNT(*) AS n FROM newequip WHERE idcreatore=?',[$idpg])[0]['n'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') alphano_require_csrf(true);
    // ─── CARICA TIPI (per <select>) ─────────────────────────────────────────────
    $tipiRaw = $admincatalogo
        ? $db->query(
            'SELECT t.id, t.nome, COALESCE(p.quality,0) AS quality
                FROM tipo t
            LEFT JOIN produzionicorporazioni p
                ON p.produzione = t.subtipo
                AND p.corporazione = ?
              ORDER BY t.nome',
            [$corp]
        )
        : [];
    $tipi = [];
    foreach ($tipiRaw as $r) {
        $tipi[] = [
            'id'      => (int)$r['id'],
            'nome'    => $r['nome'],
            'max'     => (int)$r['quality']
        ];
    }

    // ─── SWITCH AZIONE ─────────────────────────────────────────────────────────
    $azione = (string)($_POST['azione'] ?? 'list');
    if (in_array($azione,['modifica','aggiungi'],true)) {
        if (!$admincatalogo) { http_response_code(403); respond(['success'=>false,'msg'=>'Permessi insufficienti']); }
        if ($azione==='modifica' && !$db->query('SELECT 1 FROM equip WHERE id=? AND corp=?',[(int)($_POST['id'] ?? 0),$corp])) { http_response_code(403); respond(['success'=>false,'msg'=>'Oggetto non appartenente alla corporazione']); }
        $production=$db->query('SELECT p.quality FROM tipo t JOIN produzionicorporazioni p ON p.produzione=t.subtipo AND p.corporazione=? WHERE t.id=?',[$corp,(int)($_POST['tipo'] ?? 0)])[0] ?? null;
        $quality=(int)($production['quality'] ?? 0);
        $maxDurata=QUALITY[$quality]['durata'] ?? 0;
        $invalid=$maxDurata<=0 || !is_numeric($_POST['peso'] ?? null) || (float)str_replace(',','.',(string)$_POST['peso'])<0 || !in_array((int)($_POST['taglia'] ?? -1),array_keys(TAGLIE),true) || !in_array((int)($_POST['mani'] ?? -1),[1,2],true) || (int)($_POST['restore'] ?? 0)<0;
        foreach (MATERIE_PRIME as $material) if (!ctype_digit((string)($_POST[$material] ?? '0'))) $invalid=true;
        if ($invalid) { http_response_code(400); respond(['success'=>false,'msg'=>'Tipo, qualità o proprietà dell’oggetto non validi']); }
    }
    switch ($azione) {

        // ─ ordina ────────────────────────────────────────────────────────────────
        case 'ordina':
            if (in_array((int)$user['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true)) { http_response_code(403); respond(['success'=>false,'msg'=>'Non puoi ordinare oggetti quando sei morto']); }
            $id=CommercioIntero($_POST['id'] ?? null);
            $qta=CommercioIntero($_POST['qta'] ?? null,1,10000);
            if ($id===null || $qta===null) { http_response_code(400); respond(['success'=>false,'msg'=>'Inserisci una quantità intera tra 1 e 10000']); }
            $orderId=null;
            $ok=MakeOrdine($id,$idpg,$corp,$qta,$_POST['_request_key'] ?? null,$orderId);
            respond(['success'=>$ok,'orderId'=>$orderId,'msg'=>$ok ? 'Ordine creato correttamente. Seguilo in Trasferimenti.' : 'Ordine non creato: verifica se è già presente o se l’oggetto è ancora disponibile.']);
            break;

        // ─ ripara ────────────────────────────────────────────────────────────────
        case 'ripara':
            if (in_array((int)$user['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true)) { http_response_code(403); respond(['success'=>false,'msg'=>'Non puoi riparare oggetti quando sei morto']); }
            $id=CommercioIntero($_POST['id'] ?? null);
            if ($id !== null) {
                $message=RiparaOggetto($id,$idpg,$corp);
                respond(['success'=>$message==='Oggetto riparato correttamente','msg'=>$message]);
            }
            respond(['success'=>false,'msg'=>'Parametri non esaurienti']);
            break;

        // ─ modifica ───────────────────────────────────────────────────────────────
        case 'modifica':
            $id        = (int)($_POST['id']        ?? 0);
            $shortDesc = trim((string)($_POST['shortDesc'] ?? ''));
            $desc      = trim((string)($_POST['desc']      ?? ''));
            if ($admincatalogo && $id > 0 && $shortDesc !== '' && $desc !== '' && $count < maxobjinaccademia) {
                // destinazione
                $pers = !empty($_POST['pers']);
                if ($pers && !empty($_POST['destinatario'])) {
                    $destpg  = GetPGID(trim((string)$_POST['destinatario']));
                    $destorg = 0;
                } elseif ($pers && is_numeric($_POST['destorg'] ?? null) && (int)$_POST['destorg']>0) {
                    $destpg  = 0;
                    $destorg = (int)$_POST['destorg'];
                } else {
                    $destpg = $destorg = 0;
                }
                // immagine
                if (!empty($_FILES['immagine']['tmp_name']) && is_uploaded_file($_FILES['immagine']['tmp_name'])) {
                    $path = htmlroot . "/uploads/equipment-pending/{$id}";
                    ResizeImage($_FILES['immagine']['tmp_name'],150,$path);
                    $imgField = "/uploads/equipment-pending/{$id}";
                } else {
                    $imgField = trim((string)($_POST['imgurl'] ?? ''));
                }
                // campi
                $fields = [
                    'game_rules'=>(string)($db->query('SELECT game_rules FROM equip WHERE id=?',[$id])[0]['game_rules']??'{}'),'department'=>(string)($db->query('SELECT department FROM equip WHERE id=?',[$id])[0]['department']??''),
                    'shortDesc'    => $shortDesc,
                    'desc'         => $desc,
                    'peso'         => str_replace(',', '.', trim((string)($_POST['peso'] ?? '0'))),
                    'tipo'         => trim((string)($_POST['tipo'] ?? '')),
                    'taglia'       => trim((string)($_POST['taglia'] ?? '')),
                    'mani'         => (int)($_POST['mani'] ?? 1),
                    'sacro'        => !empty($_POST['sacro']) ? 1 : 0,
                    'restore'      => (int)($_POST['restore'] ?? 0),
                    'lavorazione'  => trim((string)($_POST['lavorazione'] ?? '')),
                    'destinatario' => $destpg,
                    'destorg'      => $destorg,
                    'immagine'     => $imgField,
                    'approvato'    => 1,
                    'durata'       => $maxDurata,
                    'datamodifica' => time(),
                    'idcreatore'   => $idpg,
                    'corp'         => $corp,
                ];
                // aggiungo tutte le materie prime
                foreach (\MATERIE_PRIME as $mp) {
                    $fields[$mp] = (int)($_POST[$mp] ?? 0);
                }

                // elimino vecchi
                $db->execute('DELETE FROM newequip WHERE id = ?', [$id]);
                $db->execute('DELETE FROM accademia WHERE obj = ?', [$id]);

                // inserisco la nuova proposta
                $cols = implode(',', array_map(fn($c)=>"`{$c}`", array_keys($fields)));
                $phs  = implode(',', array_fill(0,count($fields),'?'));
                $vals = array_values($fields);
                $db->execute(
                  "INSERT INTO newequip (`id`,{$cols}) VALUES (?,{$phs})",
                  array_merge([$id], $vals)
                );

                // notifica gli accademici
                $subject = "Modifica proposta oggetto: {$shortDesc}";
                $message = "L'utente {$user['nome']} ha proposto modifiche all'oggetto ID {$id} ({$shortDesc}).\nDescrizione aggiornata: {$desc}";
                $accs = $db->query('SELECT id FROM utenti WHERE adminaccademia = ?', [1]);
                foreach ($accs as $a) {
                    InviaMissiva($subject, $message, (int)$a['id']);
                }

                respond(['success'=>true,'msg'=>'Oggetto modificato correttamente']);
            }
            respond(['success'=>false,'msg'=>'Parametri non esaurienti']);
            break;

        // ─ aggiungi ───────────────────────────────────────────────────────────────
        case 'aggiungi':
            $shortDesc = trim((string)($_POST['shortDesc'] ?? ''));
            $desc      = trim((string)($_POST['desc']      ?? ''));
            if ($admincatalogo && $shortDesc !== '' && $desc !== '' && $count < maxobjinaccademia) {
                $pers = !empty($_POST['pers']);
                if ($pers && !empty($_POST['destinatario'])) {
                    $destpg  = GetPGID(trim((string)$_POST['destinatario']));
                    $destorg = 0;
                } elseif ($pers && is_numeric($_POST['destorg'] ?? null) && (int)$_POST['destorg']>0) {
                    $destpg  = 0;
                    $destorg = (int)$_POST['destorg'];
                } else {
                    $destpg = $destorg = 0;
                }
                if (!empty($_FILES['immagine']['tmp_name']) && is_uploaded_file($_FILES['immagine']['tmp_name'])) {
                    $tempImg = $_FILES['immagine']['tmp_name'];
                }
                $fields = [
                    'shortDesc'     => $shortDesc,
                    'desc'          => $desc,
                    'peso'          => str_replace(',', '.', trim((string)($_POST['peso'] ?? '0'))),
                    'tipo'          => trim((string)($_POST['tipo'] ?? '')),
                    'taglia'        => trim((string)($_POST['taglia'] ?? '')),
                    'mani'          => (int)($_POST['mani'] ?? 1),
                    'sacro'         => !empty($_POST['sacro']) ? 1 : 0,
                    'restore'       => (int)($_POST['restore'] ?? 0),
                    'lavorazione'   => trim((string)($_POST['lavorazione'] ?? '')),
                    'destinatario'  => $destpg,
                    'destorg'       => $destorg,
                    'approvato'     => 0,
                    'durata'        => $maxDurata,
                    'immagine'      => trim((string)($_POST['imgurl'] ?? '')),
                    'datacreazione' => time(),
                    'idcreatore'    => $idpg,
                    'corp'          => $corp,
                ];
                // includo tutte le materie prime
                foreach (\MATERIE_PRIME as $mp) {
                    $fields[$mp] = (int)($_POST[$mp] ?? 0);
                }
                $cols = implode(',', array_map(fn($c)=>"`{$c}`", array_keys($fields)));
                $phs  = implode(',', array_fill(0,count($fields),'?'));
                $vals = array_values($fields);
                $db->execute("INSERT INTO equip ({$cols}) VALUES ({$phs})", $vals);
                $newId = (int)$db->lastInsertID();
                if (isset($tempImg) && $newId > 0) {
                    $path = htmlroot . "/uploads/equipment-pending/{$newId}";
                    ResizeImage($tempImg,150,$path);
                    $db->execute('UPDATE equip SET immagine = ? WHERE id = ?', ["/uploads/equipment-pending/{$newId}", $newId]);
                }

                // notifica gli accademici
                $subject = "Nuova proposta oggetto: {$shortDesc}";
                $message = "L'utente {$user['nome']} ha proposto un nuovo oggetto (ID {$newId}):\n{$desc}";
                $accs = $db->query('SELECT id FROM utenti WHERE adminaccademia = ?', [1]);
                foreach ($accs as $a) {
                    InviaMissiva($subject, $message, (int)$a['id']);
                }

                respond(['success'=>true,'msg'=>'Oggetto creato correttamente']);
            }
            respond(['success'=>false,'msg'=>'Parametri non esaurienti']);
            break;

        // ─ cancellaobj ────────────────────────────────────────────────────────────
        case 'cancellaobj':
            if (!$admincorp) { http_response_code(403); respond(['success'=>false,'msg'=>'Permessi insufficienti']); }
            $id = (int)($_POST['id'] ?? 0);
            if ($admincorp && $id>0 && $db->query('SELECT 1 FROM equip WHERE id=? AND corp=?',[$id,$corp])) {
                $refs = (int)$db->query('SELECT COUNT(*) AS cnt FROM equippg WHERE uid = ?',[$id])[0]['cnt']
                      + (int)$db->query('SELECT COUNT(*) AS cnt FROM armadio WHERE uid = ?',[$id])[0]['cnt'];
                if ($refs>0) {
                    $db->execute('UPDATE equip SET visibile=0 WHERE id = ?',[$id]);
                } else {
                    $db->execute('DELETE FROM equip WHERE id = ?',[$id]);
                }
                foreach (($refs>0 ? [] : ["/uploads/equipment/{$id}","/uploads/equipment-pending/{$id}"]) as $f) {
                    $file = htmlroot . $f;
                    if (file_exists($file)) @unlink($file);
                }
                $db->execute('DELETE FROM newequip WHERE id = ?',[$id]);
                respond(['success'=>true,'msg'=>'Oggetto eliminato correttamente']);
            }
            respond(['success'=>false,'msg'=>'Parametri non esaurienti']);
            break;

        // ─ getMeta ────────────────────────────────────────────────────────────────
        case 'getMeta':
            respond([
                'tipi'           => $tipi,
                'taglie'         => \TAGLIE,
                'organizzazioni' => ListaOrg(),
                'materiePrime'   => \MATERIE_PRIME,
                'magicimg'       => '/assets/placeholder.svg',
                'approvedimg'    => '/assets/placeholder.svg',
                'admincatalogo'  => $admincatalogo,
                'admincorp'      => $admincorp,
            ]);
            break;

        // ─ list (default) ────────────────────────────────────────────────────────
        case 'list':
        default:
            $catalogo        = GetCatalogo((int)$corp,        $admincatalogo);
            $catalogopers    = GetCatalogoPers((int)$corp,    $admincatalogo, $idpg);
            $catalogopersorg = GetCatalogoPersOrg((int)$corp, $admincatalogo, $org);
            $canRepair=EconomiaImpiegato($idpg,$corp);
            $riparazioni=$canRepair ? GetCatalogoDamaged($idpg,$corp) : [];

            respond([
                'catalogo'        => buildJsArray($catalogo),
                'catalogoPers'    => buildJsArray($catalogopers),
                'catalogoPersOrg' => buildJsArray($catalogopersorg),
                'riparazioni'     => buildJsArray($riparazioni),
                'canRepair'       => $canRepair,
                'tipi'            => $tipi,
                'admincatalogo'   => $admincatalogo,
                'admincorp'       => $admincorp,
            ]);
            break;
    }

} catch (\Throwable $e) {
    error_log('[catalogo] '.$e->getMessage());
    $status=in_array($e->getCode(),[400,409],true) ? $e->getCode() : 500;
    http_response_code($status);
    respond([
        'error'   => true,
        'message' => $status<500 ? $e->getMessage() : 'Impossibile completare l’operazione. Riprova.',
    ]);
}
