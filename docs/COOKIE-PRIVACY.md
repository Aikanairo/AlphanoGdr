# Cookie e privacy nella gestione

**Gestione → Cookie e privacy** raccoglie i dati del titolare, contatti, eventuale DPO, dati e finalità, basi giuridiche, conservazione, destinatari, trasferimenti, diritti e strumenti aggiuntivi. Ogni campo ha un esempio inseribile con un pulsante. Gli esempi sono bozze da verificare e adattare all’uso reale: nessun fornitore, durata o base giuridica viene certificato dal programma.

Con «Salva e pubblica» si aggiornano `/legal/?type=privacy` e `/legal/?type=cookies`. Sono raggiungibili anche dalla homepage e dalle preferenze. Il testo iniziale privacy delle impostazioni generali rimane una breve introduzione; i dati dettagliati vengono dalla nuova sezione. Le modifiche aggiornano la revisione dell’informativa. I testi sono trattati come testo, con escaping HTML.

Il pacchetto base usa:

- un cookie di sessione `alphano_*`, con HttpOnly, SameSite=Lax e Secure in HTTPS;
- `alphano_privacy`, che memorizza per 180 giorni la preferenza sui contenuti esterni;
- ricevute di preferenza con identificatore casuale e scadenza, senza nome o IP nella ricevuta; le ricevute scadute vengono rimosse al successivo salvataggio;
- impostazioni tecniche di lettura e gioco nel browser e nell’account.

Il motore non integra analytics o pubblicità. Immagini e contenuti multimediali esterni sono bloccati per impostazione predefinita dalla politica CSP e vengono consentiti con la scelta esplicita dell’utente. Gli strumenti eventualmente aggiunti da hosting o personalizzazioni richiedono un censimento e, quando necessario, un sistema di consenso e blocco preventivo dedicato: elencarli nell’informativa non ne impedisce tecnicamente l’esecuzione.

I cookie strettamente tecnici richiedono informativa; quelli non tecnici richiedono, salvo le eccezioni applicabili, consenso preventivo. Riferimenti: [FAQ cookie del Garante](https://www.garanteprivacy.it/faq/cookie) e [linee guida 10 giugno 2021](https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9677876). Verificare l’informativa della propria installazione anche rispetto all’articolo 13 GDPR.

I valori sono in `private/privacy-policy.json`, con permessi privati e scrittura atomica. Un salvataggio da una finestra obsoleta viene rifiutato. L’editor non modifica le regole di cancellazione del database: indicare durate coerenti con le procedure effettivamente adottate. Non inserire password o chiavi API nelle informative pubbliche.
