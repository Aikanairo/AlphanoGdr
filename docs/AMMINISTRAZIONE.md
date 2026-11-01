# Disposizione comune delle pagine amministrative

Le pagine della gestione, gli strumenti Master, la regia delle sessioni e le amministrazioni di città, organizzazioni e corporazioni usano una struttura condivisa:

- colonna sinistra con ricerca, sezioni della pagina corrente e gruppi di strumenti;
- contenuto senza limite di larghezza del vecchio contenitore esterno;
- collegamenti operativi distinti come pulsanti;
- sullo schermo stretto, pulsante **Sezioni** per aprire e richiudere il menu.

Le voci visibili dipendono dai permessi del personaggio e dai moduli attivati. Le autorizzazioni restano nei controller: il menu non concede nuovi permessi. I campi dei moduli, le azioni POST e i controlli CSRF restano quelli originali.

Le schede funzionali della pagina vengono presentate anche nella colonna sinistra tramite controlli collegati agli originali. I controlli originali rimangono nel proprio componente, insieme ai gestori di eventi e ai moduli: non vengono spostati fuori dal contesto che ne gestisce il funzionamento. La selezione è sincronizzata. I pannelli interni a un modulo o a un dialogo conservano la propria navigazione contestuale.

Il catalogo e il filtro per ruolo sono in `libs/management.inc.php`; layout e presentazione in `assets/management.css`; adattamento dei documenti e comandi locali in `assets/management.js`. Le pagine HTML di errore e le risposte JSON/PDF non ricevono l’involucro amministrativo.

La favicon originale fornita dall’autore è `favicon.png`, richiamata da `libs/branding.inc.php` anche nell’installatore e nelle pagine di errore. Il file non viene deformato: il browser lo presenta nelle dimensioni della propria scheda. Il parametro di revisione cambia al cambiare dell’immagine, per aggiornare la cache. Provenienza e impronta sono nel manifesto `config/brand-art.json`.

La gestione non aggiunge una barra superiore con logo o collegamenti rapidi. Sullo schermo stretto rimane soltanto il comando «Sezioni» per aprire la navigazione.
