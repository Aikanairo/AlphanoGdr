<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/kernel.inc.php';
require_once __DIR__.'/../libs/libadmin.inc.php';
require_once __DIR__.'/../libs/libpg.inc.php';
require_once __DIR__.'/../libs/libgrafica.inc.php';






alphano_require_login();
if (!alphano_can('adminstatosociale')) { http_response_code(403); exit('Accesso negato'); }
// Identificazione utente e tema
$idpg    = GetIDPG(true);





// Recupera nome del PG
$db       = ConnectDB();
$row      = $db->query("SELECT nome FROM utenti WHERE id = :id", ['id' => $idpg])[0] ?? [];
$username = $row['nome'] ?? "Utente #{$idpg}";
$canManageUsers = alphano_can('admin');
$userActions = [
    'incarichi'=>'Incarichi, cittadinanza e poteri',
    'resetpos'=>'Metti offline tutti', 'resetcount'=>'Azzera visite profili',
    'profile'=>'Modifica Profilo', 'cambiapass'=>'Cambia Password', 'cancella'=>'Cancella Utente',
    'cancellaposta'=>'Cancella Posta', 'cambianome'=>'Cambia Nome',
     'doppipg'=>'Trova Doppi PG', 'statosociale'=>'Cambia Stato Sociale',
    'mostrastatosociale'=>'Mostra Stato Sociale', 'emailreg'=>'Cambia Email Reg.',
    'mostraemailreg'=>'Mostra Email Reg.', 'ottimizza'=>'Ottimizza Tabelle',
    'manutenzione'=>'Manutenzione utenti e archivi', 'dump'=>'Dump Database',
];
if (!$canManageUsers) $userActions = array_intersect_key($userActions, array_flip(['statosociale','mostrastatosociale']));
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Admin Utenti — <?= TitoloSito ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Carica il CSS “game-responsive.css” -->


  <!-- Palette dinamica -->


  <!-- Definisco l’URL dell’API per il JS esterno -->
  <script>window.apiUrl = '/api/admin/utenti.php'; window.csrfToken = <?=json_encode(alphano_csrf_token())?>; window.canManageUsers = <?=json_encode($canManageUsers)?>;</script>
  <script src="/js/admin/utenti.js" defer></script>
  <?php if($canManageUsers): ?><script src="/js/admin/incarichi.js?v=<?=filemtime(__DIR__.'/../js/admin/incarichi.js')?>" defer></script><?php endif; ?>
</head>
<body>

  <!-- HEADER -->
  <div class="page-header">
    Admin Utenti — <?= htmlspecialchars($username, ENT_QUOTES) ?>
  </div>

  <!-- WRAP MASTER/DETAIL -->
  <div class="wrap">

    <!-- NAV A SINISTRA -->
    <aside class="nav-panel">
      <h2>Menu</h2>
      <ul id="menu-list">
        <?php foreach ($userActions as $action=>$label): ?>
        <li data-action="<?=$action?>" role="button" tabindex="0"><?=htmlspecialchars($label,ENT_QUOTES)?></li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <!-- MAIN A DESTRA -->
    <div class="main-panel">
      <div class="panel-header" id="panel-title">Seleziona un’azione</div>
      <div class="panel-container">

        <?php
        function panel(string $act, string $html) {
          echo "<div id=\"panel-{$act}\" class=\"panel\">{$html}</div>";
        }
        if($canManageUsers){ob_start();require __DIR__.'/../templates/admin/incarichi.php';panel('incarichi',ob_get_clean());}
        panel('resetpos',   '<button class="action-btn" data-action="resetpos">Esegui</button>');
        panel('resetcount', '<button class="action-btn" data-action="resetcount">Esegui</button>');
        panel('profile', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="profile-nome"></p>
            <p><label>Sesso:</label>
              <select id="profile-sesso"><option></option><option>Maschio</option><option>Femmina</option></select>
            </p>
            <p><label>Etnia:</label><select id="profile-etnia"></select></p>
            <p><label>Allineamento:</label>
              <select id="profile-allineamento">
                <option></option><option value="LB">LB</option><option value="NB">NB</option>
                <option value="CB">CB</option><option value="LN">LN</option>
                <option value="NN">NN</option><option value="CN">CN</option>
                <option value="LM">LM</option><option value="NM">NM</option>
                <option value="CM">CM</option>
              </select>
            </p>
            <p><label>Razza:</label><select id="profile-razza"></select></p>
            <div id="profile-lineage" class="admin-lineage-choice" hidden>
              <p id="profile-lineage-message" role="status" aria-live="polite"></p>
              <label for="profile-creator">Chi lo sta generando?</label>
              <select id="profile-creator" aria-describedby="profile-lineage-message"></select>
            </div>
          </div>
          <button class="action-btn" data-action="profile">Salva</button>
        ');
        panel('cambiapass', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="pass-nome"></p>
            <p><label>Password:</label><input type="password" id="pass-new"></p>
          </div>
          <button class="action-btn" data-action="cambiapass">Cambia</button>
        ');
        panel('cancella', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="del-nome"></p>
            <p>
              <label><input type="checkbox" id="del-profilo" checked>Profilo</label><br>
              <label><input type="checkbox" id="del-equip" checked>Equip</label><br>
              <label><input type="checkbox" id="del-posta" checked>Posta</label><br>
              <label><input type="checkbox" id="del-bacheche">Bacheche</label>
            </p>
          </div>
          <button class="action-btn" data-action="cancella">Elimina</button>
        ');
        panel('cancellaposta', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="mail-nome"></p>
            <p><label>Invio:<select id="mail-invio">
              <option value="ric">Ricevuta</option>
              <option value="inv">Inviata</option>
              <option value="both">Entrambe</option>
            </select></label></p>
            <p><label>Baule:<select id="mail-baule">
              <option value="no">No</option>
              <option value="si">Si</option>
              <option value="both">Entrambe</option>
            </select></label></p>
            <p><label>Letta:<select id="mail-letta">
              <option value="si">Si</option>
              <option value="no">No</option>
              <option value="both">Entrambe</option>
            </select></label></p>
          </div>
          <button class="action-btn" data-action="cancellaposta">Pulisci</button>
        ');
        panel('cambianome', '
          <div class="session-details">
            <p><label>Vecchio Nome:</label><input type="text" id="cn-old"></p>
            <p><label>Nuovo Nome:</label><input type="text" id="cn-new"></p>
          </div>
          <button class="action-btn" data-action="cambianome">Rinomina</button>
        ');
        panel('doppipg', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="dp-nome"></p>
            <p><label><input type="checkbox" id="dp-trasf" checked>Trasferimenti</label></p>
            <p><label><input type="checkbox" id="dp-ric"    checked>Ricompense</label></p>
            <p><label><input type="checkbox" id="dp-concat">Concatena</label></p>
          </div>
          <button class="action-btn" data-action="doppipg">Cerca</button>
        ');
        panel('statosociale', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="ss-nome"></p>
            <p><label>Stato:</label><input type="text" id="ss-val"></p>
          </div>
          <button class="action-btn" data-action="statosociale">Aggiorna</button>
        ');
        panel('mostrastatosociale', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="mss-nome"></p>
          </div>
          <button class="action-btn" data-action="mostrastatosociale">Mostra</button>
        ');
        panel('emailreg', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="er-nome"></p>
            <p><label>Email:</label><input type="email" id="er-val"></p>
          </div>
          <button class="action-btn" data-action="emailreg">Aggiorna</button>
        ');
        panel('mostraemailreg', '
          <div class="session-details">
            <p><label>Nome:</label><input type="text" id="mer-nome"></p>
          </div>
          <button class="action-btn" data-action="mostraemailreg">Mostra</button>
        ');
        panel('ottimizza', '<button class="action-btn" data-action="ottimizza">Ottimizza Tabelle</button>');
        panel('manutenzione', '<p>Applica le scadenze per inattività, conserva i backup recuperabili e pulisce gli archivi scaduti.</p><button class="action-btn" data-action="manutenzione">Esegui manutenzione</button>');
        panel('dump',      '<button class="action-btn" data-action="dump">Dump Database</button>');
        ?>
      </div>
    </div>

  </div><!-- .wrap -->

</body>
</html>
