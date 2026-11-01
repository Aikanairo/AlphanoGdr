<?php
declare(strict_types=1);

use Alphano\Database\PDOMySQLDriver;

require_once __DIR__ . '/../libs/kernel.inc.php';      // ConnectDB(), initSession()
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';       // GetIDPG(), GetPGID(), EnsureAdmin(), ProcessRazza(), ProcessAl()
require_once __DIR__ . '/../libs/libequip.inc.php';    // getInformation(), checkSlot(), ecc.

initSession();
// permetti anche spettatori: secondo argomento true
$idpg = GetIDPG(false, true);

$id     = filter_input(INPUT_POST, 'id',     FILTER_VALIDATE_INT)
       ?? filter_input(INPUT_GET,  'id',     FILTER_VALIDATE_INT);
$pgName = filter_input(INPUT_POST, 'pgName', FILTER_UNSAFE_RAW)
       ?? filter_input(INPUT_GET,  'pgName', FILTER_UNSAFE_RAW);

if (!$id || !$pgName) {
    header('Location: ./istruzioni.php');
    exit;
}

// ricavo l’ID del PG di destinazione
$pgid = GetPGID((string)$pgName);
if (!$pgid) {
    http_response_code(400);
    exit('Personaggio inesistente');
}

$db = db();  // PDOMySQLDriver singleton

// Permessi
$user = $db->query(
    'SELECT admin, fatepower, bloccato, corporazione AS corp
       FROM utenti
      WHERE id = ?',
    [$idpg]
)[0] ?? [];

$admin     = (bool)$user['admin'];
$fatepower = (bool)$user['fatepower'];
$lock      = (bool)$user['bloccato'];
$corp      = $user['corp'];
if ($admin) {
    EnsureAdmin();
}

$isSame = $idpg === $pgid;

// Dati oggetto
$oggetto = getInformation($id);
if (!$oggetto || (int)$oggetto['owner']!==$pgid) {
    http_response_code(404);
    exit('Oggetto non trovato');
}

// Colore header

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Oggetto #<?=htmlspecialchars((string)$id)?> – <?=$pgName?></title>

  <script>
    function confirmAction(msg, form){
      if(confirm(msg)){
        form.submit();
      }
    }
    function parentSubmit(form){
      form.target = '_parent';
      form.submit();
    }
  </script>
</head>
<body>
  <header>Dettagli Oggetto</header>
  <main>
    <!-- Informazioni -->
    <section class="panel">
      <h2>Informazioni</h2>
      <div class="media">
        <img src="<?=$oggetto['immagine']?>" alt="Immagine oggetto">
        <div class="desc"><?=nl2br(htmlspecialchars($oggetto['descrizione'], ENT_QUOTES))?></div>
      </div>
      <ul class="props">
        <li><strong>Tipo:</strong> <?=htmlspecialchars($oggetto['tipo'], ENT_QUOTES)?></li>
        <li><strong>Taglia:</strong> <?=htmlspecialchars(getSize((int)$oggetto['taglia']), ENT_QUOTES)?></li>
        <?php if($oggetto['nome']): ?>
          <li><strong>Nome:</strong> <?=htmlspecialchars($oggetto['nome'], ENT_QUOTES)?></li>
        <?php endif;?>
        <?php if($oggetto['peso']): ?>
          <li><strong>Peso:</strong> <?=round((float)$oggetto['peso'],2)?> kg</li>
        <?php endif;?>
        <?php if($oggetto['mani']>0 && in_array(strtolower($oggetto['tipoG']),['arma','armasx','armadx'],true)): ?>
          <li><strong>Mani richieste:</strong> <?=$oggetto['mani']?></li>
        <?php endif;?>
        <?php if(!empty($oggetto['sacro'])): ?>
          <li><strong>Proprietà:</strong> Oggetto sacro</li>
        <?php endif;?>
        <?php if($oggetto['restore']>0): ?>
          <li><strong>Ripristina PF:</strong> <?=$oggetto['restore']?></li>
        <?php endif;?>
        <?php if($oggetto['idcreatore']):
          $cre = htmlspecialchars(GetPGFromID($oggetto['idcreatore']), ENT_QUOTES);
        ?>
          <li><strong>Creatore:</strong>
            <a href="#" onclick="openProfileId('<?=$oggetto['idcreatore']?>')"><?=$cre?></a>
          </li>
        <?php endif;?>
        <?php if($oggetto['lavorazione']): ?>
          <li><strong>Lavorazione:</strong> <?=htmlspecialchars($oggetto['lavorazione'], ENT_QUOTES)?></li>
        <?php endif;?>
        <li><strong>Qualità:</strong> <?=htmlspecialchars(getQuality((int)$oggetto['durata']), ENT_QUOTES)?></li>
        <li><strong>Consumo/Durata:</strong> <?=$oggetto['consumo']?> / <?=$oggetto['durata']?></li>
      </ul>
    </section>

    <!-- Azioni -->
    <section class="panel">
      <h2>Azioni</h2>
      <div class="actions">
        <?php if ($oggetto['slot']==='' && ($isSame||$admin||$fatepower)):
          // equipaggiabile?
          $canEquip = false;
          if (in_array(strtolower($oggetto['tipoG']),['arma','armasx','armadx'],true)) {
            $canEquip = checkSlotWeapon($pgid, (int)$oggetto['mani']);
          } elseif ($oggetto['equipaggiabile']) {
            if ($oggetto['padre']) {
              $canEquip = checkSlotPadre($oggetto['tipoG'], $oggetto['padre'], $pgid);
            } else {
              $canEquip = checkSlot($oggetto['tipoG'], $pgid);
            }
          }
          if ($canEquip && ! $oggetto['padre']): ?>
            <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="parentSubmit(this)">
              <input type="hidden" name="action"  value="equipaggia">
              <input type="hidden" name="id"      value="<?=$oggetto['id']?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="slot"    value="<?=strtolower($oggetto['tipoG'])?>">
              <?php if ($canEquip>1): ?>
                <input type="hidden" name="side" value="<?php echo $canEquip===2?'dx':'sx' ?>">
              <?php endif;?>
              <input type="hidden" name="pgName" value="<?=$pgName?>">
              <button>Equipaggia</button>
            </form>
          <?php elseif ($canEquip && $oggetto['padre']): ?>
            <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="parentSubmit(this)">
              <input type="hidden" name="action"  value="equipaggiapadre">
              <input type="hidden" name="id"      value="<?=$oggetto['id']?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="slot"    value="<?=strtolower($oggetto['tipoG'])?>">
              <input type="hidden" name="pgName" value="<?=$pgName?>">
              <label>
                A quale genitore?
                <select name="padre">
                  <option value="">– seleziona –</option>
                  <?php foreach($canEquip as $r): ?>
                    <option value="<?=$r['id']?>"><?=htmlspecialchars($r['nome'], ENT_QUOTES)?></option>
                  <?php endforeach;?>
                </select>
              </label>
              <button>Equipaggia</button>
            </form>
          <?php endif;
        endif; ?>

        <?php if ($oggetto['slot']!=='' && ($isSame||$admin||$fatepower)): ?>
          <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="parentSubmit(this)">
            <input type="hidden" name="action" value="disequipaggia">
            <input type="hidden" name="id"     value="<?=$oggetto['id']?>">
             <input type="hidden" name="pgid"   value="<?=$pgid?>">
            <input type="hidden" name="pgName" value="<?=$pgName?>">
            <button>Disequipaggia</button>
          </form>
        <?php endif; ?>

        <?php if ($oggetto['restore']>0 && $isSame): ?>
          <form method="POST" action="./equip.php?pgid=<?=$pgid?>"
                onsubmit="event.preventDefault(); confirmAction(
                  'Vuoi veramente usare questo oggetto?', this)">
            <input type="hidden" name="action"  value="usa">
            <input type="hidden" name="id"      value="<?=$oggetto['id']?>">
            <input type="hidden" name="pgName"  value="<?=$pgName?>">
            <button>Usa</button>
          </form>
        <?php endif; ?>

        <?php if (($isSame||$admin||$fatepower) && ! $lock): ?>
          <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="event.preventDefault(); confirmAction('Vuoi dare questo oggetto a…?', this)">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="id"     value="<?=$oggetto['id']?>">
            <input type="hidden" name="pgName" value="<?=$pgName?>">
            <input type="text" name="to" placeholder="Nome PG destinatario">
            <button>Dai a...</button>
          </form>
          <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="event.preventDefault(); confirmAction('Vuoi cancellare questo oggetto?', this)">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id"     value="<?=$oggetto['id']?>">
            <input type="hidden" name="pgName" value="<?=$pgName?>">
            <button class="danger">Cancella</button>
          </form>
        <?php elseif ($lock): ?>
          <p>Sei interdettο: non puoi spostare né cancellare oggetti.</p>
        <?php endif; ?>

        <?php if ($isSame && !$oggetto['nome'] && $oggetto['tipoG']==='Animale'): ?>
          <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="event.preventDefault(); confirmAction('Vuoi assegnare un nome?', this)">
            <input type="hidden" name="action" value="setnome">
            <input type="hidden" name="id"     value="<?=$oggetto['id']?>">
            <input type="hidden" name="pgName" value="<?=$pgName?>">
            <input type="text" name="nome" placeholder="Nome animale">
            <button>Assegna nome</button>
          </form>
        <?php endif; ?>

        <?php if ($corp && !$isSame && strlen($oggetto['materiaprima'])): ?>
          <form method="POST" action="./equip.php?pgid=<?=$pgid?>" onsubmit="event.preventDefault(); confirmAction('Proponi acquisto?', this)">
            <input type="hidden" name="action" value="acquista">
            <input type="hidden" name="id"     value="<?=$oggetto['id']?>">
            <input type="hidden" name="pgName" value="<?=$pgName?>">
            <button>Proponi acquisto</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
