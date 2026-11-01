<dialog id="rpg-chat-dialog" class="modal-window rpg-chat-dialog" aria-labelledby="rpg-chat-title">
  <header class="modal-header"><h2 id="rpg-chat-title"><span data-icon="skill" aria-hidden="true"></span> Le tue capacità</h2><button type="button" id="rpg-chat-close" data-icon="x" aria-label="Chiudi abilità"></button></header>
  <div class="modal-body">
    <p>Combina le capacità della tua stirpe con l’azione che vuoi tentare. Il Master risolve gli effetti al termine del giro.</p>
    <div id="rpg-chat-stats" class="rpg-chat-stats" aria-label="Caratteristiche attuali"></div>
    <label for="rpg-chat-ability">Abilità per il prossimo invio</label>
    <select id="rpg-chat-ability"><option value="0">Nessuna · azione o dialogo libero</option></select>
    <p id="rpg-chat-description" class="rpg-chat-description" aria-live="polite"></p>
    <p id="rpg-chat-feedback" role="status"></p>
    <div class="rpg-chat-footer"><a class="action-btn" href="/profili/?Id=<?=(int)$idpg?>#progressione" data-icon="user">Caratteristiche e progressione</a><button type="button" id="rpg-chat-choose" data-icon="check">Pronto</button></div>
  </div>
</dialog>

<script src="/js/item-engine.js" defer></script>
