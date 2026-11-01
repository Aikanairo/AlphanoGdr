<?php
/**
 *  libequip.inc.php – Gestione equipaggiamento (porting PDO / PHP 8)
 *  © Alphano GdR – revisione 2025-06-30
 */

declare(strict_types=1);

/** Uniforma i percorsi storici delle immagini senza dipendere dall'URL del modulo. */
function alphano_equipment_image(string $source, string $slot = 'attrezzi'): string
{
    $slots=['guanti','elmo','mantello','veste','armasx','armatura','armadx','bracciali','faretra','stivali','scudo','musica','anellosx','collana','anellodx','attrezzi'];
    $fallback = '/assets/equipment/'.(in_array($slot,$slots,true)?$slot:'attrezzi').'.jpg';
    $source = trim($source);
    if (preg_match('#^https?://#i', $source)) return $source;
    $path = '/' . ltrim($source, './');
    if ($source !== '' && !str_contains($path, '..') && (preg_match('#^/uploads/(?:equipment|equipment-pending)/#', $path)||preg_match('#^/assets/(?:equipment/[a-z]+\.jpg|materials/[a-z]+\.webp)$#D',$path))
        && (is_file(htmlroot . $path) || is_file(dirname(__DIR__) . $path))) return $path;
    return $fallback;
}

require_once __DIR__ . '/kernel.inc.php';   // costanti + ConnectDB()
require_once __DIR__ . '/libpg.inc.php';    // GetPGFromID(), LockUser…
require_once __DIR__ . '/libposta.inc.php';  // InviaMissiva()

use Alphano\Database\PDOMySQLDriver;

/*──────────────────────────────────────────────────────────────*/
/*                 COSTANTI “STATICHE”                         */
/*──────────────────────────────────────────────────────────────*/
const TAGLIE = ['Minuta', 'Piccola', 'Media', 'Grande', 'Gigante'];

const MATERIE_PRIME = [
    'argento','canapa','carbone','cera','cotone','cuoio','diamante',
    'erbe','ferro','legno','oro','piombo','platino','rame',
    'salnitro','seta','silicio','zolfo','acqua','vetro','cereali','carne','miele'
];

const QUALITY = [
    0 => ['durata'=>0,   'costo'=>0, 'nome'=>'Nessuna Produzione'],
    1 => ['durata'=>30,  'costo'=>1, 'nome'=>'Scarsa'           ],
    2 => ['durata'=>75,  'costo'=>2, 'nome'=>'Sufficiente'      ],
    3 => ['durata'=>120, 'costo'=>3, 'nome'=>'Buona'            ],
    4 => ['durata'=>165, 'costo'=>4, 'nome'=>'Discreta'         ],
    5 => ['durata'=>210, 'costo'=>5, 'nome'=>'Distinta'         ],
    6 => ['durata'=>255, 'costo'=>6, 'nome'=>'Ottima'           ],
    7 => ['durata'=>300, 'costo'=>7, 'nome'=>'Perfetta'         ],
];


/**
 *  Alcuni file «storici» (newcorp.php ecc.) si aspettano:
 *    – la variabile globale $quality
 *    – la costante MAX_POINT_VALUE
 *  Le esportiamo a partire dalle costanti già definite,
 *  così il resto del progetto resta intatto.
 */
$quality = QUALITY;                 // ora esiste anche come variabile globale

if (!defined('MAX_POINT_VALUE')) {  // viene usata nei calcoli del PV
    // in kernel.inc.php esiste già la vecchia costante «maxpointvalue»
    if (defined('maxpointvalue')) {
        define('MAX_POINT_VALUE', maxpointvalue);
    } else {
        define('MAX_POINT_VALUE', 0);   // fallback di sicurezza
    }
}

/*──────────────────────────────────────────────────────────────*/
/*                      PDO singleton                          */
/*──────────────────────────────────────────────────────────────*/
/* … singleton già presente altrove … */
if (!function_exists('db')) {
    function db(): PDOMySQLDriver
    {
        static $db;
        return $db ??= ConnectDB();
    }
}

/*======================================================================
 *                    FUNZIONI DI UTILITÀ GENERALI
 *====================================================================*/
/** Serializza le modifiche dell'inventario e conserva log e ripristino PF insieme. */
function EquipAtomico(callable $operation): mixed
{
    $pdo=db()->getConnection();
    $own=!$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $result=$operation();
        if ($own) $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function getQuality(int $durata): string
{
    foreach (QUALITY as $q) {
        if ($durata <= $q['durata']) return $q['nome'];
    }
    return QUALITY[array_key_last(QUALITY)]['nome'];
}

function getSize(int $id): string
{
    return TAGLIE[$id] ?? '';
}

/*======================================================================
 *                      CONSUMO / DURATA / BREAK
 *====================================================================*/
function Consuma(int $pg, int $obj, int $quantita = 1): bool
{
    if ($quantita<=0) return false;
    return EquipAtomico(static function() use($pg,$obj,$quantita): bool {
        $db=db();
        $item=$db->query('SELECT p.consumo,e.durata FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$obj,$pg])[0] ?? null;
        if (!$item) return false;
        if ((int)$item['consumo']+$quantita >= (int)$item['durata']) {
            $db->execute("UPDATE equippg SET idpadre=0,slot='' WHERE idpadre=? AND idpg=?",[$obj,$pg]);
            $db->execute('DELETE FROM equippg WHERE id=? AND idpg=?',[$obj,$pg]);
        } else $db->execute('UPDATE equippg SET consumo=consumo+? WHERE id=? AND idpg=?',[$quantita,$obj,$pg]);
        return true;
    });
}

/*======================================================================
 *                          DESCRIZIONI
 *====================================================================*/
function GetEquipDesc(int $uid): string
{
    $row = db()->query(
        'SELECT shortDesc FROM equip WHERE id = ? LIMIT 1',
        [$uid]
    );
    return $row ? (string)$row[0]['shortDesc'] : '';
}

function GetShortDesc(int $equippgId): string
{
    $row = db()->query(
        "SELECT equip.shortDesc
           FROM equippg
           JOIN equip ON equip.id = equippg.uid
          WHERE equippg.id = ? LIMIT 1",
        [$equippgId]
    );
    return $row ? (string)$row[0]['shortDesc'] : '';
}

/*======================================================================
 *                      PESO / AFFATICAMENTO
 *====================================================================*/
function getPesoTotale(int $pgid): float
{
    $row = db()->query(
        "SELECT COALESCE(SUM(equip.peso),0) AS pesotot
           FROM equippg
           JOIN equip ON equip.id = equippg.uid
           JOIN tipo  ON tipo.id  = equip.tipo
          WHERE equippg.idpg = ?
            AND tipo.subtipo != 1
",
        [$pgid]
    )[0];
    return (float)$row['pesotot'];
}

function GetAffaticamento(float $peso, int $forza): string
{
    return match (true) {
        $peso < 0.20 * $forza => 'Nessun Problema di Fatica',
        $peso < 0.40 * $forza => 'Personaggio Leggermente Impacciato',
        $peso < 0.60 * $forza => 'Personaggio Impacciato',
        $peso < 0.80 * $forza => 'Personaggio Pesantemente Impacciato',
        $peso < 1.00 * $forza => 'Personaggio Bloccato nei Movimenti',
        default               => 'Personaggio Impossibilitato a Muoversi',
    };
}

/*======================================================================
 *              INFO DETTAGLIO OGGETTO (scheda completa)
 *====================================================================*/
function getInformation(int $equippgId): array
{
    $row = db()->query(
        "SELECT
             equippg.id           AS detail_id,
             equippg.id,
             equippg.idpg AS owner,
             equip.id             AS uid,
             equip.immagine, equip.shortDesc,
             equip.materiaprima,
             tipo.nome            AS tipo,
             tipo.equipaggiabile,
             tipo.padre,
             equip.taglia,
             equip.peso,
             tipo.tipoG,
             equip.mani,
             equip.sacro,
             equip.restore,
             equip.idcreatore,
             equip.lavorazione,
             equip.`desc`         AS descrizione,
             equippg.slot,
             equippg.nome         AS nome,
             equip.durata,
             equippg.consumo
         FROM equippg
         JOIN equip ON equip.id     = equippg.uid
         JOIN tipo  ON tipo.id      = equip.tipo
        WHERE equippg.id = ?
        LIMIT 1",
        [$equippgId]
    );
    return $row[0] ?? [];
}


/*======================================================================
 *                    EQUIP / UNEQUIP (slot singolo)
 *====================================================================*/
function equipObject(int $equippgId, string $slot, string $side = ''): void
{
    EquipAtomico(static function() use($equippgId,$slot,$side): void {
        $db=db();
        $item=getInformation($equippgId);
        if (!$item || empty($item['equipaggiabile']) || (int)$item['padre']>0 || $item['slot']!=='') return;
        $owner=(int)$item['owner'];
        $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$owner]);
        $item=getInformation($equippgId);
        if(!$item||((int)$item['durata']>0&&(int)$item['consumo']>=(int)$item['durata']))return;
        $kind=strtolower($item['tipoG']); $requested=strtolower($slot);
        $weapons=['arma','armasx','armadx']; $rings=['anello','anellosx','anellodx'];
        if (in_array($kind,$weapons,true)) {
            if (!in_array($requested,$weapons,true)) return;
            $free=checkSlotWeapon($owner,(int)$item['mani']);
            if (!$free) return;
            $target='arma'.($free===3 ? 'sx' : 'dx');
        } elseif (in_array($kind,$rings,true)) {
            if (!in_array($requested,$rings,true)) return;
            $free=checkSlot('anello',$owner);
            if (!$free) return;
            $target='anello'.($free===3 ? 'sx' : 'dx');
        } else {
            if ($requested!==$kind || !checkSlot($kind,$owner)) return;
            $target=$kind;
        }
        $db->execute("UPDATE equippg SET slot=?,idpadre=0 WHERE id=? AND idpg=? AND slot=''",[$target,$equippgId,$owner]);
    });
}

function unequipObject(int $equippgId): void
{
    db()->execute("UPDATE equippg SET slot='',idpadre=0 WHERE id=?",[$equippgId]);
}

/*======================================================================
 *                     SLOT CHECK “SEMPLICE”
 *====================================================================*/
function checkSlot(string $slot, int $pgid): int
{
    $slot = strtolower($slot);

    /* — Anelli (sx/dx) — */
    if (in_array($slot,['anello','anellosx','anellodx'],true)) {
        $busy = db()->query(
            "SELECT slot FROM equippg
              WHERE idpg = ? AND slot LIKE 'anello%'", [$pgid]
        );
        $sxFree = true;
        $dxFree = true;
        foreach ($busy as $r) {
            if ($r['slot'] === 'anellosx') $sxFree = false;
            if ($r['slot'] === 'anellodx') $dxFree = false;
        }
        return match (true) {
            $sxFree && $dxFree => 2,
            $dxFree            => 2,
            $sxFree            => 3,
            default            => 0,
        };
    }

    /* — Scudo — */
    if ($slot === 'scudo') {
        $busyArms = db()->query(
            "SELECT equip.mani
               FROM equippg
               JOIN equip ON equip.id = equippg.uid
              WHERE idpg = ? AND slot IN ('armasx','armadx')",
            [$pgid]
        );
        if (count($busyArms) > 1) return 0;
        if ($busyArms && (int)$busyArms[0]['mani'] === 2) return 0;
        $hasShield = db()->query(
            "SELECT 1 FROM equippg WHERE idpg = ? AND slot = 'scudo' LIMIT 1",
            [$pgid]
        );
        return $hasShield ? 0 : 1;
    }

    /* — Slot normale — */
    $busy = db()->query(
        "SELECT 1 FROM equippg WHERE idpg = ? AND slot = ? LIMIT 1",
        [$pgid, $slot]
    );
    return $busy ? 0 : 1;
}

/*======================================================================
 *         SLOT PER ARMI (1-mano / 2-mani + interazione scudo)
 *====================================================================*/
function checkSlotWeapon(int $pgid, int $mani): int
{
    /* arma a 1 mano → serve almeno uno slot braccia libero */
    if ($mani === 1) {
        $busy = db()->query(
            "SELECT slot, equip.mani
               FROM equippg
               JOIN equip ON equip.id = equippg.uid
              WHERE idpg = ? AND slot IN ('armasx','armadx')",
            [$pgid]
        );
        $sx = $dx = false;
        foreach ($busy as $r) {
            if ($r['slot'] === 'armasx') $sx = true;
            if ($r['slot'] === 'armadx') $dx = true;
            if ((int)$r['mani'] === 2) return 0; // già un'arma 2-mani
        }
        $hasShield = db()->query(
            "SELECT 1 FROM equippg WHERE idpg = ? AND slot = 'scudo' LIMIT 1", [$pgid]
        );
        if ($hasShield && ($sx || $dx)) return 0;

        if (!$sx && !$dx) return 2;   // entrambi liberi → default destro
        if (!$dx)          return 2;  // destro libero
        if (!$sx)          return 3;  // sinistro libero
        return 0;
    }

    /* arma a 2 mani → entrambi slot liberi e niente scudo */
    $busyArms = db()->query(
        "SELECT 1 FROM equippg
          WHERE idpg = ? AND slot IN ('armasx','armadx')",
        [$pgid]
    );
    if ($busyArms) return 0;
    $hasShield = db()->query(
        "SELECT 1 FROM equippg WHERE idpg = ? AND slot = 'scudo' LIMIT 1", [$pgid]
    );
    return $hasShield ? 0 : 2;
}

/*======================================================================
 *                 ZAINO / ARMADIO / VETRINA
 *====================================================================*/
function getEquipInZaino(int $pgid): array
{
    return db()->query(
        "SELECT equippg.id,
                tipo.colore, tipo.tipoG AS equipment_group,
                equip.shortDesc,
                COALESCE(NULLIF(equip.immagine,''),tipo.immagine) AS immagine,
                equip.materiaprima
           FROM equippg
           JOIN equip ON equip.id = equippg.uid
           JOIN tipo  ON tipo.id  = equip.tipo
          WHERE equippg.idpg     = ?
            AND equippg.slot     = ''
            AND equippg.idpadre  = 0
          ORDER BY equippg.id",
        [$pgid]
    );
}




function getEquipInArmadio(int $pgid): array
{
    return db()->query(
        "SELECT armadio.id,
                tipo.colore,
                equip.shortDesc,
                tipo.immagine
           FROM armadio
           JOIN equip ON equip.id = armadio.uid
           JOIN tipo  ON tipo.id  = equip.tipo
          WHERE armadio.idpg     = ?
            AND armadio.slot     = ''
            AND armadio.idpadre  = 0
          ORDER BY armadio.id",
        [$pgid]
    );
}

function getEquipInVetrina(int $pgid): array
{
    return db()->query(
        "SELECT armadio.id,
                tipo.colore,
                equip.shortDesc,
                tipo.immagine
           FROM armadio
           JOIN equip ON equip.id = armadio.uid
           JOIN tipo  ON tipo.id  = equip.tipo
          WHERE armadio.idpg     = ?
            AND armadio.slot     = ''
            AND armadio.idpadre  = 0
            AND armadio.vetrina  = 1
          ORDER BY armadio.id",
        [$pgid]
    );
}

/* ———————————————————————————————————————————————————————————————
 *  MOVE / DELETE / USE (log + missive)
 *——————————————————————————————————————————————————————————————*/
function useObject(int $equippgId, int $pgId, int $mandante): int
{
    return EquipAtomico(static function() use($equippgId,$pgId,$mandante): int {
        $db=db();
        $user=$db->query('SELECT puntif FROM utenti WHERE id=? FOR UPDATE',[$pgId])[0] ?? null;
        $info=$db->query('SELECT e.restore,e.id AS uid FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$equippgId,$pgId])[0] ?? null;
        if (!$user || !$info || (int)$info['restore']<=0) return 0;
        if ((int)$user['puntif']>=maxpuntif) return -1;
        if (!Consuma($pgId,$equippgId,1)) return 0;
        $new=min(maxpuntif,(int)$user['puntif']+(int)$info['restore']);
        $db->execute('UPDATE utenti SET puntif=? WHERE id=?',[$new,$pgId]);
        $db->execute("INSERT INTO logs_equip(data,esecutore,origine,obj,azione) VALUES(?,?,?,?,'USATO')",[time(),$mandante,$pgId,$info['uid']]);
        return 1;
    });
}

function moveObject(int $equippgId, int $src, int $dest, int $mandante): bool
{
    if ($src===$dest) return false;
    return EquipAtomico(static function() use($equippgId,$src,$dest,$mandante): bool {
        $db=db();
        $row=$db->query('SELECT uid FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[$equippgId,$src])[0] ?? null;
        if (!$row || !$db->query('SELECT 1 FROM utenti WHERE id=?',[$dest])) return false;
        $db->execute("UPDATE equippg SET idpadre=0,slot='' WHERE idpadre=? AND idpg=?",[$equippgId,$src]);
        $db->execute("UPDATE equippg SET idpg=?,slot='',idpadre=0 WHERE id=? AND idpg=?",[$dest,$equippgId,$src]);
        $db->execute("INSERT INTO logs_equip(data,esecutore,origine,destinatario,obj,azione) VALUES(?,?,?,?,?,'SPOSTA')",[time(),$mandante,$src,$dest,$row['uid']]);
        InviaMissiva('Notifica Trasferimento Oggetto',GetPGFromID($src).' ti ha inviato un oggetto.',$dest);
        return true;
    });
}

function deleteObject(int $equippgId, int $pgid, int $mandante): bool
{
    return EquipAtomico(static function() use($equippgId,$pgid,$mandante): bool {
        $db=db();
        $row=$db->query('SELECT uid FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[$equippgId,$pgid])[0] ?? null;
        if (!$row) return false;
        $db->execute("UPDATE equippg SET idpadre=0,slot='' WHERE idpadre=? AND idpg=?",[$equippgId,$pgid]);
        $db->execute('DELETE FROM equippg WHERE id=? AND idpg=?',[$equippgId,$pgid]);
        $db->execute("INSERT INTO logs_equip(data,esecutore,origine,obj,azione) VALUES(?,?,?,?,'CANCELLA')",[time(),$mandante,$pgid,$row['uid']]);
        return true;
    });
}

/*======================================================================
 *          SLOTTED EQUIP (matrice icone/slot per la scheda)
 *====================================================================*/
function getSlottedEquip(int $pgid): array
{
    /* placeholder icone */
    $equip = [
        'guanti'   => ['img'=>'/assets/placeholder.svg',   'desc'=>'Guanti'],
        'elmo'     => ['img'=>'/assets/placeholder.svg',     'desc'=>'Elmo'],
        'mantello' => ['img'=>'/assets/placeholder.svg', 'desc'=>'Mantello'],
        'veste'    => ['img'=>'/assets/placeholder.svg',    'desc'=>'Veste'],

        'armasx'   => ['img'=>'/assets/placeholder.svg',  'desc'=>'Arma Braccio Sinistro'],
        'armatura' => ['img'=>'/assets/placeholder.svg', 'desc'=>'Armatura'],
        'armadx'   => ['img'=>'/assets/placeholder.svg',  'desc'=>'Arma Braccio Destro'],
        'bracciali'=> ['img'=>'/assets/placeholder.svg','desc'=>'Bracciali'],

        'faretra'  => ['img'=>'/assets/placeholder.svg',  'desc'=>'Faretra'],
        'stivali'  => ['img'=>'/assets/placeholder.svg',  'desc'=>'Stivali / Schinieri'],
        'scudo'    => ['img'=>'/assets/placeholder.svg',    'desc'=>'Scudo'],
        'musica'   => ['img'=>'/assets/placeholder.svg',    'desc'=>'Strumenti Musicali'],

        'anellosx' => ['img'=>'/assets/placeholder.svg', 'desc'=>'Anello Mano Sinistra'],
        'collana'  => ['img'=>'/assets/placeholder.svg','desc'=>'Medaglione / Collana'],
        'anellodx' => ['img'=>'/assets/placeholder.svg', 'desc'=>'Anello Mano Destra'],
        'attrezzi' => ['img'=>'/assets/placeholder.svg',    'desc'=>'Attrezzi'],
    ];

    $rows = db()->query(
        "SELECT equippg.id,
                equippg.slot,
                COALESCE(NULLIF(equip.immagine,''),tipo.immagine) AS immagine,
                equip.shortDesc
           FROM equippg
           JOIN equip ON equip.id = equippg.uid
           JOIN tipo  ON tipo.id  = equip.tipo
          WHERE equippg.idpg     = ?
            AND equippg.slot    <> ''
            AND equippg.idpadre = 0",
        [$pgid]
    );

    foreach ($rows as $r) {
        $slot = $r['slot'];
        if (!isset($equip[$slot])) continue;
        $equip[$slot]['img']  = alphano_equipment_image((string)$r['immagine'],$slot);
        $equip[$slot]['desc'] = stripslashes($r['shortDesc']);
        $equip[$slot]['id']   = $r['id'];
    }
    return $equip;
}

/*======================================================================
 *             SLOT “PADRE” (equipaggiamento su animali ecc.)
 *====================================================================*/
function checkSlotPadre(string $slot, int $padreTipo, int $pgid): array|false
{
    $rows=db()->query("SELECT p.*,COALESCE(NULLIF(p.nome,''),e.shortDesc) AS nome FROM equippg p JOIN equip e ON e.id=p.uid WHERE e.tipo=? AND p.idpg=? AND p.idpadre=0 AND (e.durata<=0 OR p.consumo<e.durata) AND NOT EXISTS(SELECT 1 FROM equippg child WHERE child.idpadre=p.id AND child.slot=?)",[$padreTipo,$pgid,strtolower($slot)]);
    return $rows ?: false;
}

function equipObjectPadre(int $equippgId, string $slot, int $padreId): void
{
    EquipAtomico(static function() use($equippgId,$slot,$padreId): void {
        $db=db(); $item=getInformation($equippgId);
        if (!$item || empty($item['equipaggiabile']) || (int)$item['padre']<=0 || $equippgId===$padreId || strtolower($slot)!==strtolower($item['tipoG'])) return;
        $owner=(int)$item['owner'];
        $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$owner]);
        $item=getInformation($equippgId);
        if(!$item||((int)$item['durata']>0&&(int)$item['consumo']>=(int)$item['durata']))return;
        $parents=checkSlotPadre($slot,(int)$item['padre'],$owner) ?: [];
        if (!in_array($padreId,array_map('intval',array_column($parents,'id')),true)) return;
        $db->execute('UPDATE equippg SET slot=?,idpadre=? WHERE id=? AND idpg=?',[strtolower($slot),$padreId,$equippgId,$owner]);
    });
}

/*======================================================================
 *                         ALTRE UTILITY
 *====================================================================*/
function GetMateriePrime(array $obj): array|false
{
    $filtered = array_intersect_key($obj, array_flip(MATERIE_PRIME));
    ksort($filtered);
    return $filtered ?: false;
}

function setNome(int $equippgId, string $nome, int $pgid): void
{
    db()->execute(
        'UPDATE equippg SET nome = ? WHERE id = ? AND idpg = ?',
        [$nome, $equippgId, $pgid]
    );
}

function getTipo(int $id): string
{
    $row = db()->query('SELECT nome FROM tipo WHERE id = ? LIMIT 1', [$id]);
    return $row ? (string)$row[0]['nome'] : '';
}

