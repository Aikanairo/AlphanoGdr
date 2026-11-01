# AlphanoGdr V1.0 · AGPL e crediti

Copyright © 2026 **Alfano Gaetano (Aikanairo)**. Questa distribuzione è software libero sotto **GNU Affero General Public License, versione 3 soltanto (`AGPL-3.0-only`)**. Il testo integrale e non modificato è in [LICENSE](LICENSE). Non si aggiungono restrizioni alla licenza. GitHub può riconoscere il testo standard; tale riconoscimento non è una certificazione legale.

Il pacchetto originale mostra l'autore, [Gothik.it](https://gothik.it/) e il credito «Grafica ispirata da Gothik.it» nell'equipaggiamento. Il collegamento è un credito predefinito del progetto, non un obbligo promozionale aggiunto all'AGPL. Rimangono gli obblighi di conservazione degli avvisi d'autore e di licenza previsti dall'AGPL, senza imporre una specifica posizione o impaginazione oltre quanto previsto dalla licenza stessa.

## Controllo distribuito e libertà di modifica

Il codice originale include un controllo locale: sulle pagine previste verifica nome e link nel contenuto HTML, anziché l'uguaglianza dei file. Una rimozione rilevata o un credito nascosto genera un avviso e sospende le altre pagine e API. Le pagine interessate restano visitabili per una nuova verifica. Cambiare il sorgente invalida la vecchia osservazione. Sono riconosciuti casi comuni di CSS, non ogni possibile rendering: il controllo non è un browser, non invia telemetria e non è inviolabile.

**Puoi modificare, disattivare o rimuovere questo controllo e i collegamenti secondo i diritti concessi dall'AGPL.** Il blocco è un comportamento della versione distribuita, non una sanzione legale. La modifica o rimozione del controllo non è di per sé una violazione della licenza. Non sono imposti file immutabili, funzioni obbligatorie né protezioni contro la modifica.

Un amministratore può usare `/credits/restore.php` per aggiungere un footer centrale quando manca il credito. Non vengono sovrascritti file PHP, HTML o CSS, database, credenziali o upload. Lo stato precedente è conservato privatamente in `private/credits-recovery-*`. Una pagina che presenta già il proprio credito valido non riceve duplicati. I due frammenti statici di ripristino in `config/credit-originals.json` sono autenticati solo per la sicurezza del ripristino; un frammento corrotto non blocca una pagina valida.

## Sorgente delle versioni offerte online

Se modifichi il programma e lo rendi utilizzabile tramite rete, la sezione 13 richiede di offrire in modo evidente a tutti gli utenti remoti l'accesso gratuito al **Sorgente Corrispondente della tua versione**, con un mezzo standard di download. Comprende modifiche e script necessari a costruire e installare il programma, escludendo dati dei giocatori, password, segreti e configurazioni private. Il link «Codice sorgente» in `index.php` deve quindi puntare al sorgente effettivamente offerto: la repository originale privata non basta per gli utenti che non vi hanno accesso. Rendila accessibile al rilascio oppure offri un archivio pubblico del sorgente corrispondente. Conserva un'offerta evidente anche se ridisegni la home e segnala le modifiche con le date secondo l'AGPL.

L'installatore presenta la licenza e registra privatamente identificatore, impronta del testo, data e nome del gestore. La conferma non limita i diritti AGPL; non registra IP o password. Le concessioni precedenti restano valide. Dipendenze e risorse conservano le licenze specificate in [THIRD_PARTY.md](THIRD_PARTY.md).
