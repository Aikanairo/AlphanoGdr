# Sicurezza

AlphanoGdr V1.0 richiede PHP 8.4 o successivo. Mantieni PHP e le dipendenze aggiornati e segui [i requisiti di hosting](docs/HOSTING.md). I test automatici aiutano a individuare regressioni; non costituiscono una certificazione di sicurezza o un benchmark di capacità.

Non pubblicare in una issue credenziali, dati dei giocatori, dump, contenuti di configurazioni private o dettagli che consentano di attaccare un sito attivo. Usa il canale riservato per vulnerabilità offerto da GitHub, quando disponibile, oppure contatta il titolare tramite i recapiti che ha scelto di rendere pubblici. Le issue pubbliche sono adatte alle segnalazioni prive di dati sensibili.

Le credenziali test/test e admin/admin si riferiscono esclusivamente alla demo pubblica indicata nel README. La gestione della demo è completa e condivisa; chiunque può modificarla. Non riutilizzare queste password per un proprio gioco. Il pacchetto standard non crea questi account e richiede una password amministratore robusta.

La modalità `public_demo` mostra l’avviso e le anteprime dei colori; non limita i permessi, non isola i dati e non ripristina automaticamente il sito. Per una demo con amministrazione completa usa un hosting e un database dedicati, mantieni backup esterni e non conservare credenziali SMTP o dati personali.
