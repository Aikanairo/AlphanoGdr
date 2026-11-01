<?php
/**
 * admin.php
 * Frontend per Amministrazione Organizzazione
 */
$activeTab = 'iscritti';
require_once __DIR__ . '/admin.inc.php'; // include backend







$db      = ConnectDB();

?><!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">


  <title>Amministrazione Org: <?=htmlspecialchars($nomeorg, ENT_QUOTES)?> – <?=htmlspecialchars(TitoloSito, ENT_QUOTES)?></title>

  <script>
    const orgRoles=<?=json_encode($resCariche, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
    const orgAlphalandches=<?=json_encode($db->query('SELECT id,nome,livello FROM ramiorganizzazioni WHERE organizzazione=?',[$org]), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
    function Carica(id) {
      const role=orgRoles.find(row=>Number(row.id)===Number(id));
      if (!role) return;
      const form=document.getElementById('org-role-form');
      form.elements.namedItem('action').value='modifycarica';
      form.elements.namedItem('carica').value=role.id;
      form.elements.namedItem('nome').value=role.nome;
      form.elements.namedItem('prima').checked=Number(role.prima)===1;
      form.elements.namedItem('ramo').value=role.ramo;
      form.elements.namedItem('livello').value=role.livello;
      form.elements.namedItem('descrizione').value=role.descrizione || '';
      form.querySelector('button[type="submit"]').textContent='Salva Carica';
      showTab('iscritti'); window.alphanoUI?.reveal(form); form.scrollIntoView({block:'center',behavior:'smooth'});
    }
    function resetRoleForm() {
      const form=document.getElementById('org-role-form'); form.reset();
      form.elements.namedItem('action').value='addcarica';
      form.elements.namedItem('carica').value='0';
      form.querySelector('button[type="submit"]').textContent='Aggiungi Carica';
    }
    function selectOrgAlphalandch(id) {
      const branch=orgAlphalandches.find(row=>Number(row.id)===Number(id));
      if (!branch) return;
      const form=document.getElementById('org-branch-form');
      form.elements.namedItem('nome').value=branch.nome;
      form.elements.namedItem('livello').value=branch.livello;
    }
    function showTab(id) {
      document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-content').forEach(c => {c.classList.remove('active');c.hidden=c.id!=='tab-'+id;});
      document.getElementById('tab-'+id+'-btn').classList.add('active');
      document.getElementById('tab-'+id).classList.add('active');
    }
  window.addEventListener('DOMContentLoaded', () => {
    const initial=new URLSearchParams(location.search).get('panel')||location.hash.replace(/^#tab-/,'');showTab(['iscritti','finanze','prestiti','flag','elezioni','pvt','abilita'].includes(initial)?initial:'<?= $activeTab ?>');
  });
  </script>
</head>
<body>


  <header class="ui-page-heading" data-page-heading data-header-image="/assets/placeholder.svg"><span data-icon="users"></span><div><span class="ui-eyebrow">Alphano · Governo della comunità</span><h1><?=htmlspecialchars($nomeorg, ENT_QUOTES)?></h1><p>Membri, cariche, responsabilità e risorse dell’organizzazione.</p></div><nav class="ui-module-toolbar"><a class="action-btn" href="/governo/?scope=organizzazioni&amp;id=<?=$org?>" data-icon="coins">Bilancio e stipendi</a><a class="action-btn" href="/gruppi/identita.php?scope=organizzazioni&amp;id=<?=$org?>" data-icon="shield">Stendardo e testata</a><a class="action-btn" href="/notifiche/?group=organizzazioni" data-icon="scroll">Cronaca</a></nav>
  <?php if ($msg): ?>
    <div class="u-e0c8870df">
      <?=htmlspecialchars($msg, ENT_QUOTES)?>
    </div>
  <?php endif; ?>

  <!-- Nav tabs -->
  <div class="tabs ui-module-toolbar">
    <button id="tab-iscritti-btn" onclick="showTab('iscritti')">Iscritti</button>
    <button id="tab-finanze-btn" onclick="showTab('finanze')">Finanze</button>
    <button id="tab-prestiti-btn" onclick="showTab('prestiti')">Prestiti a PG</button>
    <button id="tab-flag-btn" onclick="showTab('flag')">Privilegi</button>
    <button id="tab-elezioni-btn" onclick="showTab('elezioni')">Elezioni</button>
    <button id="tab-abilita-btn" onclick="showTab('abilita')">Abilità</button>
    <button id="tab-pvt-btn" onclick="showTab('pvt')">Interna</button>
  </div>

  </header>
  <!-- Iscritti -->
  <div id="tab-iscritti" class="tab-content">
    <h2>Richieste di ammissione</h2>
    <table>
      <tr><th>PG</th><th>Data</th><th>Accetta</th><th>Respingi</th></tr>
      <?php $rich = GetRichiesteOrg($org); ?>
      <?php if ($rich): foreach ($rich as $r): ?>
      <tr>
        <td><a href="#" onclick="openProfileId('<?=$r['idpg']?>')"><?=htmlspecialchars(GetPGFromID($r['idpg']), ENT_QUOTES)?></a></td>
        <td><?=FormatTimestamp($r['data'])?></td>
        <td>
          <form method="post" class="inline"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <input type="hidden" name="action" value="admitpg">
            <input type="hidden" name="pg" value="<?=$r['idpg']?>">
            <input type="hidden" name="admit" value="1">
            <button class="panel-button">Accetta</button>
          </form>
        </td>
        <td>
          <form method="post" class="inline"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <input type="hidden" name="action" value="admitpg">
            <input type="hidden" name="pg" value="<?=$r['idpg']?>">
            <button class="panel-button">Respingi</button>
          </form>
        </td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="4" class="Valore u-72a68ac34">Nessuna richiesta pervenuta</td></tr>
      <?php endif; ?>
    </table>


    <h2>Assegna / Cambia Carica a PG</h2>
      <table>
        <tr><th>PG</th><th>Carica</th><th>Azione</th></tr>
        <?php
          // Prendo tutti i PG e tutte le cariche
          $pgList    = $pginorg;                         // [id => nome]
          $carList   = GetListaCaricheOrg($org);         // array di ['id','nome','ramo',...]
          $ramiList  = $rami;                            // [ramoId => ramoNome]

          // Raggruppo le cariche per ramo
          $byRamo = [];
          foreach ($carList as $c) {
            $byRamo[$c['ramo']][] = $c;
          }
        ?>
        <?php if ($pgList && $carList): ?>
        <tr>
              <td>
                <form id="org-assignment" method="post" class="inline"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
                  <input type="hidden" name="action" value="changecarica">
                  <select name="pg" required>
                    <option value="">– seleziona PG –</option>
                    <?php foreach ($pgList as $pid => $pn): ?>
                      <option value="<?=$pid?>"><?=htmlspecialchars($pn, ENT_QUOTES)?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td>
                  <select name="carica" form="org-assignment" required>
                    <option value="">– seleziona carica –</option>
                    <?php foreach ($ramiList as $ramoId => $ramoNome): ?>
                      <?php if (!empty($byRamo[$ramoId])): ?>
                      <optgroup label="<?=htmlspecialchars($ramoNome, ENT_QUOTES)?>">
                        <?php foreach ($byRamo[$ramoId] as $c): ?>
                          <option value="<?=$c['id']?>">
                            <?=htmlspecialchars($c['nome'], ENT_QUOTES)?>
                          </option>
                        <?php endforeach; ?>
                      </optgroup>
                      <?php endif; ?>
                    <?php endforeach; ?>
                    <optgroup label="—">
                      <option value="0">Espelli (rimuovi carica)</option>
                    </optgroup>
                  </select>
              </td>
              <td>
                  <button class="panel-button" form="org-assignment">Modifica Carica</button>
              </td>
        </tr>
        <?php else: ?>
        <tr>
          <td colspan="3" class="Valore u-72a68ac34">
            Nessun PG o nessuna carica disponibile
          </td>
        </tr>
        <?php endif; ?>
      </table>

        <h3>Elenco Membri</h3>
        <table>
          <tr>
            <th>Icona | Carica Organizzazione</th>
            <th>Player</th>
          </tr>
          <?php foreach ($membersWithRole as $m): ?>
          <tr>
            <td class="u-c3400183f">
              <?=GetCaricaOrg($org,(int)$m['cid'],false)?>
              <?= htmlspecialchars($m['carica'], ENT_QUOTES) ?>
            </td>
            <td>
              <a href="#" onclick="openProfileId('<?= $m['pid'] ?>')">
                <?= htmlspecialchars($m['player'], ENT_QUOTES) ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>



    <h2>Gerarchia Organizzazione</h2>
    <?php foreach ($cariche as $lvl => $ramiLivello): ?>
      <div class="ui-table-scroll" tabindex="0" role="region" aria-label="Gerarchia dell’organizzazione">
      <table class="ui-hierarchy-table">
        <tr>
          <?php foreach ($ramiLivello as $ramoId => $ramoDat): ?>
            <td>
              <strong><?=htmlspecialchars($ramoDat['nome'], ENT_QUOTES)?></strong>
              <table>
                <?php foreach ($ramoDat['cariche'] as $cid => $cinf): ?>
                <tr>
                  <td class="u-72a68ac34">
                    <a href="#" onclick="Carica('<?=$cid?>')">
                      <?=GetCaricaOrg($org,(int)$cid,false)?>
                      <?=htmlspecialchars($cinf['nomecarica'], ENT_QUOTES)?>
                      <?php if ($cinf['prima']): ?><img src="/assets/placeholder.svg" alt="Prima"><?php endif;?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </table>
            </td>
          <?php endforeach; ?>
        </tr>
      </table>
      </div>
    <?php endforeach; ?>

    <h2>Gestione Rami</h2>
    <!-- Aggiungi ramo -->
    <form method="post" class="u-f267e3ca6"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="addramo">
      <input type="text" name="nome" placeholder="Nome ramo" required>
      <input type="number" name="livello" value="0" min="0" required>
      <button class="panel-button">Aggiungi Ramo</button>
    </form>
    <form id="org-branch-form" method="post"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="modifyramo">
      <select name="ramo" onchange="selectOrgAlphalandch(this.value)" required>
        <option value="">– seleziona ramo da modificare –</option>
        <?php foreach ($rami as $rid=>$rn): ?><option value="<?=(int)$rid?>"><?=htmlspecialchars($rn,ENT_QUOTES)?></option><?php endforeach; ?>
      </select>
      <input name="nome" placeholder="Nome ramo" required>
      <input name="livello" type="number" min="0" value="0" required>
      <button>Modifica Ramo</button>
    </form>
    <!-- Elimina ramo -->
    <form method="post"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="dropramo">
      <select name="ramo" required>
        <option value="">– seleziona ramo da eliminare –</option>
        <?php foreach ($rami as $rid => $rn): ?>
          <option value="<?=$rid?>"><?=htmlspecialchars($rn, ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
      <button class="panel-button">Elimina Ramo</button>
    </form>

    <h2>Gestione Cariche</h2>
    <!-- Aggiungi nuova carica -->
    <form id="org-role-form" method="post" enctype="multipart/form-data" class="u-f267e3ca6"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="addcarica">
      <input type="hidden" name="carica" value="0">
      <input type="file" name="immagine">
      <input type="text" name="nome" placeholder="Nome carica" required>
      <label><input type="checkbox" name="prima"> Prima</label>
      <input type="number" name="livello" value="0" min="0" required>
      <select name="ramo" required>
        <?php foreach ($rami as $rid => $rn): ?>
          <option value="<?=$rid?>"><?=htmlspecialchars($rn, ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
      <textarea name="descrizione" rows="2" placeholder="Descrizione"></textarea>
      <button type="submit" class="panel-button">Aggiungi Carica</button>
      <button type="button" onclick="resetRoleForm()">Nuova carica</button>
    </form>
    <form method="post"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="dropcarica">
      <select name="carica" required>
        <option value="">– seleziona carica da eliminare –</option>
        <?php foreach ($resCariche as $role): ?><option value="<?=(int)$role['id']?>"><?=htmlspecialchars($role['nome'],ENT_QUOTES)?></option><?php endforeach; ?>
      </select>
      <button>Elimina Carica</button>
    </form>
  </div>

  <!-- Finanze -->
  <div id="tab-finanze" class="tab-content">
    <h2>Budget attuale</h2>
    <p class="Valore"><?= $budget ?> <?=alphano_currency_html()?></p>

    <h2>Stato richieste di prestito ricevute</h2>
    <table>
      <tr>
        <th>Data</th><th>Presa in carico da</th><th>Corp.</th>
        <th>Interloc.</th><th>Q.tà</th><th>%</th><th>Stato</th><th>Rimanente</th>
      </tr>
      <?php if ($prestitiOrg): foreach ($prestitiOrg as $d):
        $rimanente = (int)$d['stato']===5
          ? CalcolaInteressi((int)$d['quantita'],(int)$d['interessi'],(int)$d['data']) - (int)$d['restituiti']
          : ((int)$d['stato']<5 ? (int)$d['quantita'] : 0);
      ?>
      <tr>
        <td><?=FormatTimestamp($d['data'])?></td>
        <td>
          <?php if ($d['pgorg']): ?>
            <a href="#" onclick="openProfileId('<?=$d['pgorg']?>')"><?=htmlspecialchars(GetPGFromID($d['pgorg']),ENT_QUOTES)?></a>
          <?php elseif ($d['corp']!=$corp): ?>
            <form method="post" class="inline"><input type="hidden" name="org" value="<?= (int)$org ?>">
              <input type="hidden" name="action" value="prendiincarico">
              <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
              <input type="hidden" name="prestito" value="<?=$d['id']?>">
              <button class="panel-button">Prendi in carico</button>
            </form>
          <?php else: ?>
            Nessuno
          <?php endif; ?>
        </td>
        <td><?=htmlspecialchars(GetCorporazione($d['corp']),ENT_QUOTES)?></td>
        <td><a href="#" onclick="openProfileId('<?=$d['pgcorp']?>')"><?=htmlspecialchars(GetPGFromID($d['pgcorp']),ENT_QUOTES)?></a></td>
        <td><?=$d['quantita']?> <?=alphano_currency_html()?></td>
        <td><?=$d['interessi']?> %</td>
        <td><?=ProcessStato($d['stato'])?></td>
        <td><?=$rimanente?> <?=alphano_currency_html()?></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="8" class="u-72a68ac34">Nessun prestito in atto</td></tr>
      <?php endif; ?>
    </table>

    <h2>Amministrazione richieste prese in carico</h2>
    <table>
      <tr>
        <th>Data</th><th>Corp.</th><th>Interloc.</th>
        <th>Q.tà</th><th>%</th><th>Stato</th><th>Rimanente</th><th>Azioni</th>
      </tr>
      <?php if ($prestitiPGOrg): foreach ($prestitiPGOrg as $d):
        $rimanente = (int)$d['stato']===5
          ? CalcolaInteressi((int)$d['quantita'],(int)$d['interessi'],(int)$d['data']) - (int)$d['restituiti']
          : ((int)$d['stato']<5 ? (int)$d['quantita'] : 0);
      ?>
        <?php if (in_array((int)$d['stato'], [1,2,4], true)): ?>
        <tr>
          <td><?=FormatTimestamp($d['data'])?>
          <form id="org-loan-<?=(int)$d['id']?>" method="post"><input type="hidden" name="org" value="<?= (int)$org ?>">
            <input type="hidden" name="action" value="adminprestito">
            <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
            <input type="hidden" name="prestito" value="<?=$d['id']?>">
            <input type="hidden" name="accetta" value="">
            <input type="hidden" name="cancella" value="">
          </form>
          </td>
            <td><?=htmlspecialchars(GetCorporazione($d['corp']),ENT_QUOTES)?></td>
            <td><a href="#" onclick="openProfileId('<?=$d['pgcorp']?>')"><?=htmlspecialchars(GetPGFromID($d['pgcorp']),ENT_QUOTES)?></a></td>
            <td><input type="number" min="1" max="2147483647" step="1" required name="quantita" form="org-loan-<?=(int)$d['id']?>" value="<?=$d['quantita']?>" class="u-a053cda31"> <?=alphano_currency_html()?></td>
            <td><input type="number" min="0" max="100" step="1" required name="interessi" form="org-loan-<?=(int)$d['id']?>" value="<?=$d['interessi']?>" class="u-1a96f4c6d"> %</td>
            <td><?=ProcessStato($d['stato'])?></td>
            <td><?=$rimanente?> <?=alphano_currency_html()?></td>
            <td>
              <?php if ($budget >= $d['quantita']): ?>
                <button type="button" form="org-loan-<?=(int)$d['id']?>" onclick="this.form.accetta.value=1; this.form.submit()">Accetta</button><br>
              <?php endif; ?>
              <button type="submit" form="org-loan-<?=(int)$d['id']?>">Modifica</button><br>
              <button type="button" form="org-loan-<?=(int)$d['id']?>" onclick="this.form.cancella.value=1; this.form.submit()">Respingi</button>
            </td>
        </tr>
        <?php else: ?>
        <tr>
          <td><?=FormatTimestamp($d['data'])?></td>
          <td><?=htmlspecialchars(GetCorporazione($d['corp']),ENT_QUOTES)?></td>
          <td><a href="#" onclick="openProfileId('<?=$d['pgcorp']?>')"><?=htmlspecialchars(GetPGFromID($d['pgcorp']),ENT_QUOTES)?></a></td>
          <td><?=$d['quantita']?> <?=alphano_currency_html()?></td>
          <td><?=$d['interessi']?> %</td>
          <td><?=ProcessStato($d['stato'])?></td>
          <td><?=$rimanente?> <?=alphano_currency_html()?></td>
          <td></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; else: ?>
      <tr><td colspan="8" class="u-72a68ac34">Nessuna richiesta presa in carico</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- Prestiti a PG -->
  <div id="tab-prestiti" class="tab-content">
    <h2>Concedi Nuovo Prestito a PG</h2>
    <form method="post" class="u-f267e3ca6"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="action" value="prestito">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <select name="pg" required>
        <option value="">– seleziona PG –</option>
        <?php foreach ($pginorg as $pid => $pn): ?>
          <option value="<?=$pid?>"><?=htmlspecialchars($pn, ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" name="qta" min="1" max="<?=min($budget,2147483647)?>" step="1" required> <?=alphano_currency_html()?>
      <button class="panel-button">Invia</button>
    </form>
    <h2>Lista Prestiti Attivi</h2>
    <table>
      <tr><th>Data</th><th>Destinatario</th><th>Debito residuo</th></tr>
      <?php foreach ($prestitiPG as $d): ?>
      <tr>
        <td><?=FormatTimestamp((int)$d['data'])?></td>
        <td><a href="#" onclick="openProfileId('<?=(int)$d['id']?>')"><?=htmlspecialchars($d['nome'],ENT_QUOTES)?></a></td>
        <td><?=(int)$d['qta']?> <?=alphano_currency_html()?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$prestitiPG): ?><tr><td colspan="3">Nessun prestito a personaggi attivo</td></tr><?php endif; ?>
    </table>
  </div>

  <!-- Privilegi -->
  <div id="tab-flag" class="tab-content">
    <h2>Visualizza Flag</h2>
    <form method="post" class="u-f267e3ca6"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="visualizzaflag">
      <select name="nome" required>
        <option value="">– seleziona PG –</option>
        <?php foreach ($pginorg as $pid => $pn): ?>
          <option value="<?=$pid?>"
            <?=isset($_POST['nome']) && (int)$_POST['nome']=== $pid ? 'selected':''?>>
            <?=htmlspecialchars($pn,ENT_QUOTES)?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="panel-button">Visualizza</button>
    </form>

    <?php if (!empty($datiflag)): ?>
      <h2>Modifica Privilegi per <?=htmlspecialchars($pginorg[$datiflag['id']] ?? '',ENT_QUOTES)?></h2>
      <form method="post"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
        <input type="hidden" name="action" value="setflag">
        <input type="hidden" name="nome"   value="<?=htmlspecialchars($_POST['nome'],ENT_QUOTES)?>">
        <label>
          <input type="checkbox" name="adminorg" <?=!empty($datiflag['adminorganizzazione'])?'checked':''?>>
          Amministratore
        </label><br>
        <?php if ($resurgo): ?>
          <label><input type="checkbox" name="resurgo" <?=!empty($datiflag['adminmorti'])?'checked':''?>> Resurgo</label><br>
        <?php endif; ?>
        <?php if ($cerimonia): ?>
          <label><input type="checkbox" name="cerimonia" <?=!empty($datiflag['adminstatosociale'])?'checked':''?>> Cerimoniere</label><br>
        <?php endif; ?>
        <?php if ($ferite): ?>
          <label><input type="checkbox" name="ferite" <?=!empty($datiflag['adminferite'])?'checked':''?>> Guaritore</label><br>
        <?php endif; ?>
        <button class="panel-button">Salva</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Elezioni -->
  <div id="tab-elezioni" class="tab-content">
    <h2>Proponi Candidato</h2>
    <?php if ($electiontime): foreach ($pgcity as $cid => $mems): ?>
      <form method="post" class="u-f267e3ca6"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
        <input type="hidden" name="action" value="proponi">
        <input type="hidden" name="city"   value="<?=$cid?>">
        <select name="pg" required>
          <option value="">– seleziona PG –</option>
          <?php foreach ($mems as $pid => $pinfo): ?>
            <option value="<?=$pid?>"><?=htmlspecialchars($pinfo['nome'],ENT_QUOTES)?></option>
          <?php endforeach; ?>
        </select>
        <textarea name="programma" rows="2" placeholder="Programma" required></textarea>
        <span class="Valore"><?=$listacity[$cid]['costoelezioni']?> <?=alphano_currency_html()?></span>
        <button class="panel-button">Proponi</button>
      </form>
      <hr class="u-f63e01396">
    <?php endforeach; else: ?>
      <p class="Descrizione">
        <?php if ($votetime): ?>
          Fase di voto attiva: non è più possibile proporre.
        <?php else: ?>
          Elezioni chiuse.
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <h2>Candidature Esistenti</h2>
    <?php if ($candidati): ?>
      <table>
        <tr><th>Città</th><th>PG</th><th>Programma</th><th>Costo</th></tr>
        <?php foreach ($candidati as $cityId => $cand): ?>
        <tr>
          <td><?=htmlspecialchars($listacity[$cityId]['city'],ENT_QUOTES)?></td>
          <td><a href="#" onclick="openProfileId('<?=$cand['id']?>')"><?=htmlspecialchars($cand['nome'],ENT_QUOTES)?></a></td>
          <td><div class="Valore u-97c21ea8e"><?= ProcessText($cand['programma']) ?></div></td>
          <td class="Valore"><?=htmlspecialchars($listacity[$cityId]['costoelezioni'],ENT_QUOTES)?> <?=alphano_currency_html()?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <!-- Interna (PVT) -->
  <div id="tab-pvt" class="tab-content">
    <h2>Amministrazione Interna</h2>
    <form method="post"><input type="hidden" name="org" value="<?= (int)$org ?>">
      <input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>">
      <input type="hidden" name="action" value="modifypvt">
      <table>
        <tr>
          <td>Città:</td>
          <td>
            <select name="city" required>
              <?php foreach ($listacity as $cid => $cdata): ?>
                <option value="<?=$cid?>" <?= $cid===$cityorg ? 'selected':''?>>
                  <?=htmlspecialchars($cdata['city'],ENT_QUOTES)?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <td>Password stanza:</td>
          <td><input type="text" name="pvt" value="<?=htmlspecialchars($pvt,ENT_QUOTES)?>" required></td>
        </tr>
        <tr>
          <td>Immagine Top:</td>
          <td><input type="text" name="sfondotop" value="<?=htmlspecialchars($sfondotop,ENT_QUOTES)?>"></td>
        </tr>
        <tr>
          <td>Immagine Sfondo:</td>
          <td><input type="text" name="sfondo" value="<?=htmlspecialchars($sfondo,ENT_QUOTES)?>"></td>
        </tr>
        <tr>
          <td>Mappa stanza:</td>
          <td><input type="text" name="mappa" value="<?=htmlspecialchars($mappa,ENT_QUOTES)?>"></td>
        </tr>
        <tr>
          <td>Descrizione stanza:</td>
          <td><textarea name="descrizione" rows="4"><?=htmlspecialchars($descrizione,ENT_QUOTES)?></textarea></td>
        </tr>
        <tr>
          <td>Sito organizzazione:</td>
          <td><input type="text" name="sito" value="<?=htmlspecialchars($sito,ENT_QUOTES)?>"></td>
        </tr>
        <tr>
          <td colspan="2" class="u-72a68ac34">
            <button class="panel-button">Modifica</button>
          </td>
        </tr>
      </table>
    </form>
  </div>

<div id="tab-abilita" class="tab-content"><?php $orgAbilityMode='organization';$orgAbilityId=$org;require __DIR__.'/../templates/academy/organization-abilities.php'; ?></div>
</body>
</html>
