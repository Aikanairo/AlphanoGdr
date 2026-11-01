# Email

Gestione → Impostazioni del gioco configura il mittente, l’indirizzo di assistenza, trasporto, host, porta, sicurezza, utente e password SMTP. I segreti non sono mostrati nei form: lasciare il campo vuoto conserva quello salvato; l’opzione di rimozione lo cancella.

- `log`: nessuna connessione SMTP; messaggi MIME `.eml` in `private/mail/`, con permessi 0600.
- `smtp`: consegna al server scelto. STARTTLS è normalmente usato sulla porta 587; TLS implicito sulla 465. I certificati TLS vengono verificati. La modalità senza TLS è disponibile per un relay di prova locale.
- `mail`: usa la funzione PHP del provider, senza credenziali SMTP. AlterVista la documenta nella [guida ufficiale](https://en.help.altervista.org/w/Send_email_with_PHP). Usa un mittente autorizzato e controlla consegna e spam: un esito positivo indica accettazione dal sistema, non recapito garantito.
- `disabled`: nessun invio; il sito mostra l’esito della richiesta senza spedire email.

Il pulsante di prova invia soltanto all’indirizzo di assistenza configurato. Non parte alcuna email durante l’installazione. Per un sito pubblico, imposta il mittente autorizzato dal provider e configura sul tuo dominio SPF, DKIM e DMARC secondo le istruzioni del servizio email. Il motore non modifica DNS.

Il messaggio di benvenuto supporta `{name}`, `{game}` e `{url}`. Le email includono nome del gioco e contatti della gestione; il template è `templates/email/notification.php`. I testi sono codificati e le intestazioni rifiutano caratteri di nuova riga. Le notifiche facoltative rispettano le preferenze dell’account e non allegano la conversazione privata.

Il recupero password genera un token casuale monouso, memorizza nel database soltanto il suo hash e lo fa scadere dopo un’ora. Il link usa `base_url`, da impostare sul dominio HTTPS reale. La password non appare mai nel messaggio di benvenuto o recupero.

Le notifiche accodate richiedono `php bin/maintenance.php`, da eseguire periodicamente se desiderate. Proteggi e ruota i file di prova; non caricarli nella repository o nel pacchetto di distribuzione.
