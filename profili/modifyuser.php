<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
require_once __DIR__ . '/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
require_once __DIR__.'/../libs/experience.inc.php';
require_once __DIR__.'/../libs/profile-contacts.inc.php';
require_once __DIR__.'/../libs/profile-symbols.inc.php';






function h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$idpg = alphano_require_login();
$db = getDB();
$row = alphano_user();
// One mapping shared by direct saves and the historical JSON backup format.
$profileFields = [
    'avatar'=>'urlavatar', 'avatar2'=>'urlavatar2', 'banner'=>'urlbanner', 'banner2'=>'urlbanner2', 'musica'=>'urlmusica', 'musica2'=>'urlmusica2',
    'motto'=>'txtmotto', 'motto2'=>'txtmotto2', 'animali'=>'txtanimali', 'amicizie'=>'txtamici',
    'affetti'=>'txtaffetti', 'note'=>'txtnote', 'firma'=>'txtfirma', 'psicologia'=>'txtpsicologia',
    'storiapg'=>'txtstoria', 'contatti'=>'txtcontatti', 'provincia'=>'provincia', 'telegram'=>'telegram', 'discord'=>'discord',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    alphano_require_csrf();
    $input = $_POST;
    if (isset($input['azione']) && !is_string($input['azione'])) alphano_fail('Azione non valida.', 400);
    $action = $input['azione'] ?? '';
    if ($action === 'cancel') {
        header('Location: ./?Id=' . $idpg);
        exit;
    }
    foreach (['txtpass', 'current_password'] as $passwordField) if (isset($input[$passwordField]) && !is_string($input[$passwordField])) alphano_fail('Password non valida.', 400);
    if ($action === 'loadfile') {
        $upload = $_FILES['fileprofilo'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > min(MaxUploadFileSize, 1048576)
            || !is_uploaded_file($upload['tmp_name'] ?? '') || strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION)) !== 'json') {
            alphano_fail('Carica un file JSON valido (massimo 1 MB).', 400);
        }
        try { $decoded=json_decode(ltrim(file_get_contents($upload['tmp_name']), "\xEF\xBB\xBF"), true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { alphano_fail('Il backup JSON non è valido.',400); }
        if (!isset($decoded['profilo']) || !is_array($decoded['profilo'])) alphano_fail('Il backup non contiene un profilo.',400);
        $input = $decoded['profilo'];
        // Backups from the old controller used txtamicizie/txtstoriapg/txtprovincia.
        foreach (['txtamicizie'=>'txtamici','txtstoriapg'=>'txtstoria','txtprovincia'=>'provincia'] as $old=>$new) {
            if (isset($input[$old]) && !isset($input[$new])) $input[$new]=$input[$old];
        }
    }
    if ($action === 'savefile') {
        $export=[];
        foreach ($profileFields as $field=>$key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) alphano_fail('Valore non valido per '.$key,400);
            $export[$key]=(string)($input[$key] ?? $row[$field] ?? '');
            if(in_array($field,['telegram','discord'],true)){try{$export[$key]=alphano_profile_contact_value($field,$export[$key]);}catch(DomainException $e){alphano_fail($e->getMessage(),400);}}
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="profilo_'.$idpg.'.json"');
        echo json_encode(['version'=>2,'profilo'=>$export],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $sets=[]; $params=[];
    foreach ($profileFields as $field=>$key) {
        if (!array_key_exists($key,$input)) continue;
        if (!is_scalar($input[$key])) alphano_fail('Valore non valido per '.$key,400);
        $value=(string)$input[$key];
        if(in_array($field,['telegram','discord'],true)){
            if(!alphano_profile_contacts_ready())alphano_fail('Contatti temporaneamente non disponibili.',503);
            try{$value=alphano_profile_contact_value($field,$value);}catch(DomainException $e){alphano_fail($e->getMessage(),400);}
        }
        if (strlen($value)>100000) alphano_fail('Testo troppo lungo.',400);
        if (in_array($field,['avatar','avatar2','banner','banner2','musica','musica2'],true)) {
            $value=trim($value);
            if (strlen($value)>255 || ($value!=='' && preg_match('/[\x00-\x20<>"\x27]/',$value))
                || ($value!=='' && preg_match('/^[a-z][a-z0-9+.-]*:/i',$value) && !preg_match('#^https?://#i',$value))) alphano_fail('Indirizzo immagine o audio non valido.',400);
        }
        if (in_array($field,['provincia','contatti'],true) && strlen($value)>($field==='provincia'?50:255)) alphano_fail('Campo troppo lungo: '.$key,400);
        $sets[]='`'.$field.'`=?'; $params[]=$value;
    }
    if (!empty($input['txtpass']) && $action !== 'loadfile') {
        $password=(string)$input['txtpass'];
        if (strlen($password)<8 || strlen($password)>200) alphano_fail('La password deve contenere almeno 8 caratteri.',400);
        if (!alphano_password_verify((string)($input['current_password'] ?? ''), $row['password'])) alphano_fail('La password attuale non è corretta.',400);
        $sets[]='password=?'; $params[]=password_hash($password,PASSWORD_DEFAULT);
    }
    if (empty($row['upload'])) {
        foreach (['filemusica','filemusica2'] as $key) if (($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) alphano_fail('Caricamento file non abilitato per questo profilo.',403);
    }
    {
        foreach (['avatar'=>'fileavatar','avatar2'=>'fileavatar2','banner'=>'filebanner','banner2'=>'filebanner2','musica'=>'filemusica','musica2'=>'filemusica2'] as $field=>$key) {
            if(str_starts_with($field,'musica')&&empty($row['upload']))continue;
            $file=$_FILES[$key] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            if (($file['error'] ?? -1)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>MaxUploadFileSize || !is_uploaded_file($file['tmp_name'] ?? '')) alphano_fail('Caricamento non riuscito o file troppo grande.',400);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $types=(str_starts_with($field,'avatar')||str_starts_with($field,'banner')) ? ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'] : ['audio/mpeg'=>'mp3','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/wav'=>'wav','audio/x-wav'=>'wav'];
            if (!isset($types[$mime])) alphano_fail('Formato file non consentito.',400);
            if(str_starts_with($mime,'image/')){$dimensions=@getimagesize($file['tmp_name']);if(!$dimensions||$dimensions[0]*$dimensions[1]>36000000)alphano_fail('Immagine non valida o troppo grande (massimo 36 megapixel).',400);}
            $directory=htmlroot.'/profili/pg/'.$idpg;
            if (!is_dir($directory) && !mkdir($directory,0755,true)) throw new RuntimeException('Impossibile creare la cartella del profilo');
            $filename=$field.'-'.bin2hex(random_bytes(8)).'.'.$types[$mime];
            if (!move_uploaded_file($file['tmp_name'],$directory.'/'.$filename)) throw new RuntimeException('Caricamento non riuscito');
            $sets[]='`'.$field.'`=?'; $params[]='/profili/pg/'.$idpg.'/'.$filename;
        }
    }
    $db->beginTransaction();
    try {
        if ($sets) { $params[]=$idpg; $db->execute('UPDATE utenti SET '.implode(',',$sets).' WHERE id=?',$params); }
        if (isset($input['experience_present'])) {
            $patch=[];
            foreach (['map_effects','map_lighting','ambient_sound','notification_sound'] as $key) {
                if (isset($input[$key]) && $input[$key] !== '1') alphano_fail('Preferenza non valida.',400);
                $patch[$key]=isset($input[$key]);
            }
            foreach (['ambient_volume','notification_volume'] as $key) {
                $value=filter_var($input[$key]??null,FILTER_VALIDATE_INT);
                if ($value===false) throw new DomainException('Volume non valido.');
                $patch[$key]=$value;
            }
            $patch['sound_theme']=$input['sound_theme']??'';
            if(isset($input['relationship_effects']))$patch['relationship_effects']=$input['relationship_effects'];
            if(isset($input['chess_skin']))$patch['chess_skin']=$input['chess_skin'];
            foreach(alphano_email_categories() as $key=>$_)$patch['email_'.$key]=isset($input['email_'.$key]);
            alphano_experience_save($idpg,$patch);
        }
        $db->commit();
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); if($e instanceof DomainException)alphano_fail($e->getMessage(),400);throw $e; }
    header('Location: ./?Id='.$idpg);
    exit;
}
$experienceSettings=alphano_experience_settings($idpg);
$dati=$row;
$doppiRaw=TrovaDoppi($idpg) ?: []; $doppi=[];
if ($doppiRaw) foreach ($db->query('SELECT id,nome FROM utenti WHERE id IN ('.implode(',',array_fill(0,count($doppiRaw),'?')).')',$doppiRaw) as $r) $doppi[(int)$r['id']]=$r['nome'];
$province = [
  "Agrigento (AG)","Alessandria (AL)","Ancona (AN)","Aosta (AO)","Arezzo (AR)",
  "Ascoli Piceno (AP)","Asti (AT)","Avellino (AV)","Bari (BA)","Belluno (BL)",
  "Benevento (BN)","Bergamo (BG)","Biella (BI)","Bologna (BO)","Bolzano (BZ)",
  "Brescia (BS)","Brindisi (BR)","Cagliari (CA)","Caltanissetta (CL)","Campobasso (CB)",
  "Caserta (CE)","Catania (CT)","Catanzaro (CZ)","Chieti (CH)","Como (CO)",
  "Cosenza (CS)","Cremona (CR)","Crotone (KR)","Cuneo (CN)","Enna (EN)",
  "Ferrara (FE)","Firenze (FI)","Foggia (FG)","Forlì-Cesena (FC)","Frosinone (FR)",
  "Genova (GE)","Gorizia (GO)","Grosseto (GR)","Imperia (IM)","Isernia (IS)",
  "La Spezia (SP)","L'Aquila (AQ)","Latina (LT)","Lecce (LE)","Lecco (LC)",
  "Livorno (LI)","Lodi (LO)","Lucca (LU)","Macerata (MC)","Mantova (MN)",
  "Massa Carrara (MS)","Matera (MT)","Messina (ME)","Milano (MI)","Modena (MO)",
  "Napoli (NA)","Novara (NO)","Nuoro (NU)","Oristano (OR)","Padova (PD)",
  "Palermo (PA)","Parma (PR)","Pavia (PV)","Perugia (PG)","Pesaro e Urbino (PS)",
  "Pescara (PE)","Piacenza (PC)","Pisa (PI)","Pistoia (PT)","Pordenone (PN)",
  "Potenza (PZ)","Prato (PO)","Ragusa (RG)","Ravenna (RA)","Reggio Calabria (RC)",
  "Reggio Emilia (RE)","Rieti (RI)","Rimini (RN)","Roma (RM)","Rovigo (RO)",
  "Salerno (SA)","Sassari (SS)","Savona (SV)","Siena (SI)","Siracusa (SR)",
  "Sondrio (SO)","Taranto (TA)","Teramo (TE)","Terni (TR)","Torino (TO)",
  "Trapani (TP)","Trento (TN)","Treviso (TV)","Trieste (TS)","Udine (UD)",
  "Varese (VA)","Venezia (VE)","Verbania (VB)","Vercelli (VC)","Verona (VR)",
  "Vibo Valentia (VV)","Vicenza (VI)","Viterbo (VT)"
];
$editorArt=alphano_profile_banner(array_replace($dati,['vampire_human_form'=>0]));
$editorTransformation=alphano_transformation_state($dati);
$editorHasAltForm=in_array($editorTransformation['kind'],['lycan','vampire'],true);
$editorAltForm=$editorTransformation['kind']==='vampire'?'Forma umana':'Forma licantropo';
$editorPortrait = alphano_profile_portrait($dati)['url'];
$editorVisibleRace=alphano_visible_race($dati);
$editorIdentity = ProcessRazza($editorVisibleRace, (string)$dati['sesso']) . ' ' . h(implode(' · ', array_filter([GetRazza($editorVisibleRace), GetEtnia((int)$dati['etnia']), ProcessAl((string)$dati['allineamento'])])));
$editorMemberships = [
    'Organizzazione' => h(GetOrg((int)$dati['organizzazione'])),
    'Carica di organizzazione' => GetCaricaOrg((int)$dati['organizzazione'], (int)$dati['caricaorganizzazione']),
    'Corporazione' => h(GetCorporazione((int)$dati['corporazione'])),
    'Carica di corporazione' => GetCaricaCorp((int)$dati['corporazione'], (int)$dati['caricacorporazione']),
];
require __DIR__ . '/../templates/profiles/edit.php';
ClosePage();
