# Hosting PHP e MySQL

## Requisiti

PHP **8.4 o successivo**. Le patch **8.4.27+** e **8.5.12+** sono consigliate: l’installatore distingue compatibilità e aggiornamento di sicurezza e permette di continuare sulle versioni compatibili mostrando un avviso. MySQL **8.0+**, oppure MariaDB **10.6+**, InnoDB e utf8mb4. Sono richiesti PDO MySQL, mbstring, fileinfo, OpenSSL, cURL, DOM/XML, iconv e sessioni; GD è consigliata per le immagini. Non servono trigger, eventi MySQL, privilegi amministrativi, shell, SSH o servizi IA. I test locali usano anche MariaDB 10.4 per la compatibilità dello schema.

## Percorso rapido: pacchetto pronto, FTP e browser

Per una nuova installazione usa **`AlphanoGdr-hosting.zip`**, che contiene già le dipendenze in `vendor/`. Anche **Code → Download ZIP** di GitHub include ora `vendor/`: dopo l’estrazione entra nella cartella `AlphanoGdr-main` e caricane il contenuto nella radice del dominio. Soltanto il pacchetto separato `AlphanoGdr-source.zip`, destinato agli sviluppatori, esclude le dipendenze. I pacchetti completi si recuperano dagli artefatti di un’esecuzione riuscita di [Verify and package](https://github.com/Aikanairo/AlphanoGdr/actions/workflows/verify.yml), oppure dall’eventuale pacchetto allegato a una release. Se l’artefatto GitHub contiene un secondo ZIP, estrai anche quello.

1. Attiva presso il provider PHP compatibile e un database vuoto dedicato, annotandone i dati.
2. Scompatta il pacchetto sul computer. Tramite FTP carica **il contenuto estratto**, comprese cartelle, `vendor/` e file nascosti `.htaccess`, nella radice del dominio o sottodominio. Usa il trasferimento binario per trasferire fedelmente codice, dipendenze e frammenti del ripristino. FTP trasferisce lo ZIP ma non lo estrae sul server.
3. Verifica che `index.php`, `install/`, `config/`, `private/`, `uploads/` e `vendor/autoload.php` siano nella stessa radice. Non creare una cartella contenitore aggiuntiva o installare in `/gioco/`.
4. Apri il dominio oppure **`/index.php`**. Se manca `config/local.php`, si apre automaticamente **`/install/`**, prima di usare il database di gioco.
5. Inserisci connessione, identità del sito e amministratore completo. L’installatore crea le tabelle, salva i file di configurazione e blocca l’installazione iniziale. **Non importare manualmente `database/schema.sql`.**
6. Prosegui dal gestore: crea o salta chat, razze, etnie, città, organizzazioni, corporazioni e impostazioni. Solo il primo passaggio è obbligatorio; per aprire le iscrizioni servono una razza ordinaria iniziale attiva e l’abilitazione delle registrazioni.

Non servono Composer, terminale, SSH, Node.js o Python sul server. Composer serve solo a chi prepara il pacchetto completo dai sorgenti su un computer di sviluppo. Il primo caricamento non installa un modello IA e non avvia servizi persistenti.

Le cartelle `config/`, `private/` e `uploads/` devono essere scrivibili dal processo PHP. Il modulo elenca i requisiti mancanti, incluso un eventuale `vendor/autoload.php` non caricato. I permessi iniziali abituali sono 755 per le cartelle e 644 per i file, ma vanno adattati al proprietario e al provider; non usare 777 come soluzione generale.

## AlterVista

1. Attiva il database dal pannello. Per un account `nomeaccount`, i dati normalmente sono host `localhost`, utente `nomeaccount`, database `my_nomeaccount`; la password MySQL può essere lasciata vuota. Sono i dati del singolo spazio, non quelli di altri server.
2. Nelle **Impostazioni PHP** del pannello, scegli PHP **8.4**. L’installatore controlla il numero di patch effettivo: se è inferiore a 8.4.27, mostra un avviso integrato e permette di continuare. Chiedi comunque al provider di aggiornare PHP o confermare l’applicazione delle patch di sicurezza. Le versioni inferiori a PHP 8.4 rimangono incompatibili. Il pannello MySQL 8 e la selezione PHP sono impostazioni distinte.
3. Per FTP usa l’utente dell’account, la stessa password del pannello, porta **21**, modalità passiva e **TLS esplicito**. Gli hostname documentati sono `nomeaccount.altervista.org` e `ftp.nomeaccount.altervista.org`. Usa quello il cui certificato risulta valido nel client; non disabilitare la verifica TLS.
4. Prima di sostituire un sito esistente, salva e verifica tutti i file (anche nascosti) e un’esportazione SQL completa. Conserva i backup fuori dalla directory pubblicabile.
5. Scompatta il pacchetto hosting completo sul computer e carica via FTP tutti i file estratti nella radice, comprese dipendenze e file nascosti. Mantieni la direttiva PHP generata da AlterVista nel `.htaccess` della radice, insieme alle protezioni del motore. La selezione PHP 8.4 attualmente genera `AddHandler av-php84 .php`; se copi un nuovo `.htaccess`, preserva questa riga oppure ripeti la selezione dal pannello. Puoi aggiungere la direttiva al file prima del trasferimento oppure riselezionare PHP 8.4 nel pannello dopo aver caricato il `.htaccess`. Non copiare questa direttiva su altri provider.
6. Apri il dominio o `/index.php`: si apre automaticamente `/install/`. Compila la prima pagina: non serve alcuna chiave. Il database deve essere vuoto. Non importare SQL a mano né il database di un altro gioco. Per l’amministratore scegli le credenziali del gioco: sono separate da quelle FTP del provider.
7. Dal pannello del gestore configura o salta i passaggi. Le registrazioni rimangono chiuse finché scegli una razza iniziale ordinaria attiva e le apri esplicitamente.

L’hosting potrebbe limitare connessioni esterne, SMTP, timeout e cron. Il pannello del provider indica le possibilità dell’account. Non impostare SMTP a caso: usa le credenziali del tuo servizio email e verifica con l’invio di prova. Benvenuto e recupero password funzionano senza cron, se il trasporto è disponibile.

Fonti ufficiali: [FAQ hosting AlterVista](https://help.altervista.org/it/FAQ_Altervista_Hosting), [parametri FTP](https://help.altervista.org/en/FTP). Le pagine descrivono hostname, login, modalità passiva e credenziali MySQL. Le direttive correnti sono nella [guida `.htaccess` di AlterVista](https://help.altervista.org/it/Configurazione_personalizzata_(.htaccess)). La password MySQL facoltativa non va confusa con la password obbligatoria del pannello/FTP.

## PHP2Shell e patch

Le correzioni delle CVE-2026-109618 e CVE-2026-109619 sono in PHP 8.4.27 e 8.5.12, oltre alle patch dei rami precedenti che questo progetto non supporta. L’aggiornamento del runtime e dei worker spetta al provider. Il codice PHP dell’applicazione non può correggere un difetto che si verifica prima della sua esecuzione.

Il progetto non modifica `session.serialize_handler` durante le richieste. Il 23 ottobre 2026 PHP ha inoltre segnalato CVE-2026-115560, relativa a una combinazione insolita di handler modificato a runtime e upload progress. Se non serve, chiedi al provider di disabilitare `session.upload_progress.enabled` **nella configurazione amministrativa prima del parsing delle richieste**; `ini_set()` nell’applicazione o `.user.ini` non costituiscono una protezione generale per le vulnerabilità di inizializzazione. Non aggiungere direttive PHP non supportate dal tuo hosting: possono causare errori 500.

Fonti: [avvisi ufficiali PHP di ottobre 2026](https://www.php.net/archive/2026.php), [corruzione OOM](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w), [stato globale e mitigazioni](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). Controlla gli aggiornamenti successivi del tuo ramo PHP.

## Directory private e permessi

`config/local.php` contiene la connessione; `private/settings.json` le impostazioni e gli eventuali segreti SMTP/IA. Questi file non devono entrare in Git o negli archivi pubblici. Il webserver deve negare l’accesso a `private/`, `config/`, `database/`, `libs/`, `vendor/`, `templates/`, `tests/`, `bin/` e ai file di configurazione/log. Le regole Apache incluse lo fanno; su Nginx configura gli stessi divieti prima della pubblicazione. Gli upload non devono poter eseguire PHP, HTML o SVG caricati dagli utenti.

L’installazione scrive la configurazione in modo atomico, usa CSRF e rifiuta database occupati. Dopo il primo passaggio `/install/` risponde 404; la configurazione successiva richiede l’amministratore completo. Solo il gestore può riaprire le registrazioni.

## Manutenzione facoltativa

Se il provider offre cron, puoi eseguire ogni pochi minuti:

```sh
php /percorso/del/gioco/bin/maintenance.php
```

Il comando usa solo la configurazione dell’installazione in cui si trova. Non esporlo come endpoint web. La regia umana non richiede worker IA. Senza cron alcune notifiche programmate attendono le richieste periodiche dei giocatori; non sono garantite mentre il sito è inattivo.

## Aggiornamenti e ripristino

Salva database, configurazione privata e upload prima degli aggiornamenti. La prima distribuzione installa un database nuovo; non migra altri giochi. Per ripristinare usa un database dedicato vuoto e il backup corrispondente dei file/configurazioni. Non includere backup, credenziali o dati del vecchio hosting nel pacchetto distribuibile.

## HTTPS dietro proxy

I cookie e il controllo Origin del login usano l’URL canonico scelto in installazione. Imposta `https://` quando il certificato è attivo. Prima della configurazione il modulo riconosce anche l’header HTTPS di AlterVista sul dominio del provider. Su altri proxy configura l’URL del gioco esplicitamente.

Per obbligare HTTPS su AlterVista aggiungi alle regole della radice, sostituendo il dominio:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]
RewriteRule ^ https://nomeaccount.altervista.org%{REQUEST_URI} [R=302,L]
```

La regola va usata dopo aver attivato HTTPS; non applicarla a un server locale HTTP. [Documentazione Apache sui proxy TLS](https://httpd.apache.org/docs/2.4/rewrite/remapping.html) e [conferma del supporto HTTPS nativo AlterVista](https://forum.en.altervista.org/services/5358-https-does-not-work-third-level-domains.html).


## Cosa mostra `index.php`

| Stato della copia | Risultato |
| --- | --- |
| Pacchetto completo, PHP supportato, configurazione assente | Reindirizzamento automatico al modulo `/install/` |
| PHP non supportato | Messaggio con la versione minima richiesta; aggiorna la selezione presso il provider |
| Dipendenze, estensioni o permessi mancanti | L’installatore elenca i requisiti da correggere e non procede con le tabelle |
| Installazione completata | Homepage del gioco, oppure ingresso al gioco per un utente già autenticato |
| `/install/` aperto dopo l’installazione | Risposta “Installazione chiusa”; la configurazione successiva si usa dal gestore |

Il controllo usa la configurazione della singola copia. Non avvia una reinstallazione solo perché il database non risponde: in quel caso si devono correggere connessione o servizio, preservando i dati. Se è rimasto il blocco di un’installazione precedente, non rimuoverlo per provare il modulo sul database esistente. Per una seconda installazione prepara un altro spazio e un altro database vuoto.

## Prima apertura su AlterVista con PHP 8.0

Se la radice viene eseguita con PHP 8.0, il motore non può avviare le sue dipendenze. La schermata iniziale propone **Attiva PHP 8.4 e continua**: dopo una conferma esplicita, una richiesta POST protetta seleziona l’interprete `av-php84` nel `.htaccess` di questa installazione. Conserva le altre direttive e salva il contenuto originale in `private/php-runtime-original.htaccess`. Non scarica un interprete, non cambia altri account e non usa il database. È disponibile solo per installazioni nuove sul dominio AlterVista e richiede che la radice e `private/` siano scrivibili.

Se il provider non applica la selezione, viene mostrato come scegliere PHP 8.4 dal suo pannello. Su altri hosting la selezione va fatta nel pannello del provider. Non esiste un pulsante che renda compatibili le dipendenze PHP 8.4 con PHP 8.0.

Una volta attivo PHP 8.4, se mancano le patch consigliate il modulo richiede di spuntare **accetto il rischio di continuare**. La verifica avviene anche sul server prima della connessione al database. Dopo il consenso l’installazione può proseguire; non vengono applicate patch a PHP.

## Conferma e uscita dall’installazione

Quando il primo modulo viene salvato correttamente, una risposta HTTP 303 apre `/install/complete.php`. Il riepilogo mostra il gioco, l’account del gestore e lo stato della struttura, senza password o dati di connessione. Dopo 15 secondi porta alla homepage; puoi andarci subito, restare sul riepilogo o aprire la configurazione del gioco. Aggiornare il riepilogo non ripete la creazione delle tabelle. L’installatore rimane chiuso: un amministratore che torna su `/install/` viene accompagnato al riepilogo; gli altri visitatori tornano alla homepage e non possono riaprire la procedura.

## Pacchetto di esempio e licenza

Il primo modulo richiede il lettura della GNU AGPL-3.0, senza condizioni aggiuntive di backlink, e descrive il controllo locale e il ripristino. L’opzione dati di esempio è inizialmente selezionata: crea City Alphano, quattro chat, Umano, Italiano, le cariche Cittadino/Sindaco, 23 risorse e raccolte dimostrative. Il gestore iniziale è il sindaco; non vengono creati altri account o inventari di giocatori. Deselezionando l’opzione, la struttura resta vuota. Gli aggiornamenti di installazioni esistenti non importano automaticamente questi dati né modificano il database. Le immagini dei soli slot e materiali autorizzati sono nel manifesto `config/equipment-art.json`.

## Ripristino dei crediti

Puoi riscrivere qualsiasi pagina mantenendo il credito visibile. Le differenze di codice, markup e CSS rispetto agli originali non causano un blocco. Il controllo verifica autore e link nell’HTML generato; vedi [ATTRIBUTION.md](../ATTRIBUTION.md) per i casi riconosciuti e i limiti della verifica degli stili. Le segnalazioni di credito mancante sono in `private/credits-health.json`; una modifica del sorgente permette di ripetere la verifica. Visita nuovamente la homepage o l’equipaggiamento dopo la correzione.

Il pulsante in `/credits/restore.php` richiede gestore, CSRF e conferma. Attiva un footer centrale nelle pagine interessate senza sostituire PHP, HTML, CSS o layout. Salva lo stato precedente privatamente in `private/credits-recovery-*` e non tocca il database. Se la pagina contiene già un credito valido non aggiunge duplicati. I frammenti del ripristino sono statici: se la loro copia è corrotta, ricaricala dal pacchetto; il problema non blocca pagine con crediti validi. Mantieni private le cartelle `private/` e i backup.

Il controllo locale dei crediti resta incluso con il suo blocco tecnico, ma è modificabile o rimovibile secondo la AGPL. Per versioni modificate offerte online, rendi evidente il download gratuito del Sorgente Corrispondente della tua versione (sezione 13): aggiorna il link «Codice sorgente» in `index.php`. Non basta un link a una repository privata inaccessibile agli utenti. Escludi dati personali e segreti. Vedi [ATTRIBUTION.md](../ATTRIBUTION.md).

Il pacchetto di esempio è preselezionato: include City Alphano e quattro chat con mappa visiva generale e cittadina già pronta, senza Alphaland. I due sfondi sono distribuiti in `assets/maps/`, quindi basta caricare tutti i file via FTP. Deselezionando l’esempio non vengono create città o configurazioni cartografiche. Gli aggiornamenti conservano la mappa privata del sito esistente.

### Ambiente dimostrativo pubblico

Gli accessi pubblici della demo alfanew non vengono creati dal pacchetto. Un’installazione nuova richiede le proprie credenziali robuste. L’opzione locale `public_demo` mostra un avviso di ambiente condiviso e consente anteprime delle cinque palette tramite `?palette=blue` (oppure black, brown, red, purple); non modifica il tema salvato e non crea utenti. L’account admin della demo ha gestione completa: non usare questo ambiente per dati o credenziali reali.
