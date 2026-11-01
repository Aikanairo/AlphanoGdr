<section class="governance" id="governance">
 <p>Gestisci incarichi e poteri esistenti del personaggio. Le modifiche vengono validate insieme e registrate con autore, motivo e valori precedenti.</p>
 <form id="governance-search" class="ui-row-actions"><label>Personaggio <input name="name" list="governance-players" autocomplete="off" required></label><datalist id="governance-players"></datalist><button type="submit" data-icon="search">Carica personaggio</button></form>
 <p id="governance-status" role="status" aria-live="polite"></p>
 <details id="governance-issues"><summary>Controllo coerenza degli incarichi</summary><ul></ul></details>
 <form id="governance-edit" hidden>
  <h2 id="governance-person"></h2>
  <nav id="governance-shortcuts" class="ui-row-actions" aria-label="Amministrazioni collegate"></nav>
  <div class="governance-grid">
   <fieldset><legend>Corporazione</legend><label>Gruppo <select name="corporazione"></select></label><label>Carica <select name="caricacorporazione"></select></label><button type="button" data-grant="corporazione">Delega gestione corporazione</button><p>Concede amministrazione, catalogo e scambi della corporazione selezionata. Scegli la carica dal menu.</p></fieldset>
   <fieldset><legend>Organizzazione</legend><label>Gruppo <select name="organizzazione"></select></label><label>Carica <select name="caricaorganizzazione"></select></label><button type="button" data-grant="organizzazione">Delega gestione organizzazione</button><p>Concede l’amministrazione dell’organizzazione selezionata, con la carica scelta.</p></fieldset>
   <fieldset><legend>Cittadinanza e governo</legend><label>Città <select name="city"></select></label><label>Carica cittadina <select name="araldica"></select></label><button type="button" id="governance-mayor">Nomina sindaco / amministratore</button><p>La nomina assegna la carica apicale e sostituisce il sindaco attuale, che torna alla carica iniziale della stessa città.</p></fieldset>
  </div>
  <fieldset><legend>Poteri e stato account</legend><div id="governance-flags" class="governance-flags"></div></fieldset>
  <label>Motivo della modifica <textarea name="reason" required maxlength="500" rows="3" placeholder="Descrivi l’incarico o la delega da registrare"></textarea></label>
  <div class="ui-row-actions"><button type="submit" data-icon="save">Verifica e salva incarichi</button><button type="button" id="governance-reload">Ricarica dati salvati</button></div>
 </form>
 <section><h3>Registro degli incarichi</h3><p>Ultime 50 modifiche. Il registro completo resta disponibile in Gestione → Log, tabella logs_adminpg, azione incarichi.</p><div id="governance-history"></div></section>
</section>
