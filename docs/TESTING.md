# Verifiche e ambiente isolato

Il sito pubblico non richiede Python, Node, Docker o shell. Questi strumenti servono soltanto per sviluppo e collaudo.

## Test automatici

`python3 tests/smoke.py` crea una directory temporanea, copia il codice e avvia **un nuovo processo MariaDB senza rete**, usando un socket temporaneo e una porta PHP scelta automaticamente. Non cerca configurazioni di altri siti, non importa dati di gioco e non si connette al MySQL principale del computer. Tutti i processi e i dati di prova vengono rimossi al termine.

Servono PHP con le estensioni richieste, MariaDB Server e i relativi client. I percorsi sono configurabili:

```sh
PHP_BIN=/path/php \
MYSQL_BIN=/path/mariadb \
MYSQLD_BIN=/path/mariadbd \
MYSQL_INSTALL_DB=/path/mariadb-install-db \
python3 tests/smoke.py
```

Il test verifica installazione senza chiave, CSRF e controllo Origin del browser, rifiuto di un database occupato, blocco dell’installatore iniziale, cataloghi vuoti, procedura riprendibile, razze inattive e trasformazioni configurabili, protezione delle directory, sessioni separate, registrazione, password hashate, email di benvenuto, azioni chat e retry, missive e retry, recupero password monouso, moduli principali, venti combinazioni di tema e layout, creazione e disattivazione di schede e chat, assenza di lavori IA e consegna SMTP a un ricevitore **locale fittizio**. Nessuna email viene inviata su Internet.

`--serve` mantiene aperto il collaudo e stampa l’URL della copia temporanea. L’account `DemoAdmin` usa esclusivamente la password di test definita nello script; non esiste in un’installazione reale. Premi Invio per chiudere l’ambiente e cancellarlo.

```sh
php tests/http-context.php
php tests/runtime-requirements.php
php tests/board-engines.php
python3 tests/release_audit.py
```

Il controllo del runtime verifica le soglie di sicurezza dei diversi rami PHP. Quello dei giochi controlla scacchi e dama in puro PHP, inclusi scacco matto, presa obbligatoria e mosse illegali. Il secondo controlla l’insieme esatto dei file esportabili, escludendo cronologia Git, configurazioni locali, mail, upload, dump, grafica e riferimenti all’ambientazione originaria.

La workflow GitHub verifica sintassi PHP e JavaScript, esegue i test locali isolati, controlla lo schema contro un servizio MySQL 8 dedicato e prepara un artefatto per hosting con le dipendenze Composer. La presenza della workflow non significa che ogni esecuzione remota sia già stata completata: consulta lo stato in Actions.

## Pacchetto da caricare

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
python3 tests/release_audit.py
python3 bin/package.py --include-vendor
```

L’archivio `dist/AlphanoGdr-hosting.zip` contiene i file da caricare nella radice del dominio, incluse dipendenze e protezioni `.htaccess`. La procedura esclude configurazioni locali, dati privati, upload e cronologia Git. Viene stampata l’impronta SHA-256 del pacchetto.


## Bacheche, pagine e registrazioni

La suite verifica anche creazione e modifica delle bacheche senza un archivio immagini esterno, permessi pubblici e riservati, discussioni, risposte, storico, chiusura e riapertura, pulizia e cancellazione dei relativi permessi. Controlla le caratteristiche del personaggio e il selettore dei luoghi della regia come pagine autonome, oltre all’inizializzazione delle impostazioni dei giochi da tavolo.

```sh
php -d zend.assertions=1 -d assert.exception=1 tests/recording-format.php
```

Le nuove registrazioni `.alphano` sono XML portabile, importabile senza estensioni di compressione. Gli archivi storici BZip2 sono leggibili quando PHP dispone dell’estensione `bz2`; se manca, il formato precedente è rifiutato con un messaggio esplicativo. Gli amministratori possono convertire questi archivi su un ambiente con BZip2 e reimportarli nel formato XML.

Per una verifica su hosting, salvare prima file e database; usare utenti, città, bacheche e chat temporanei riconoscibili, provare i flussi dalle normali pagine/API e rimuovere infine solo quelle risorse. Distinguere nel rapporto caricamento HTML, operazioni effettivamente provate, accessi vietati e moduli non configurati. SMTP e inferenza IA richiedono servizi configurati e non vanno dichiarati collaudati se disabilitati.

Per aggiornare un’installazione precedente al nuovo editor delle bacheche, eseguire una sola volta `database/migrations/20261031_forum_location.sql`: amplia il nome della città senza modificare le bacheche esistenti.

Gli aggiornamenti di una copia già installata devono preservare le direttive specifiche dell’hosting in `.htaccess` (su AlterVista: `AddHandler av-php84 .php`), oltre a `config/local.php`, `private/`, upload e log. Dopo il caricamento verificare la versione PHP effettiva, non soltanto la selezione nel pannello.


## Installazione dal pacchetto effettivamente distribuito

```sh
python3 bin/package.py --include-vendor
python3 tests/package_install.py dist/AlphanoGdr-hosting.zip
```

Il secondo comando estrae lo ZIP in una directory temporanea, verifica file essenziali e assenza della configurazione privata, poi avvia la suite isolata **sui soli file estratti**. Non esegue Composer sul sito estratto. Il test controlla che `/` e `/index.php` aprano automaticamente `/install/` quando la copia è nuova, prima di creare tabelle; verifica anche che un caricamento senza `vendor/` venga rifiutato senza lasciare un’installazione parziale. Questa è la stessa procedura usata dalla CI prima di allegare il pacchetto hosting.

## Homepage e intestazioni illustrate

La suite controlla che ogni pagina del catalogo abbia una testata locale esistente, l’assegnazione per contenuto, la priorità delle personalizzazioni, il ripristino dopo la rimozione e le immagini modificabili della home. Verifica inoltre accesso e registrazione reali e che un manuale riservato non compaia pubblicamente. Il controllo di rilascio verifica hash, formato e dimensione degli undici WebP ammessi; non autorizza altri bitmap di provenienza privata. Il controllo visivo desktop/mobile resta necessario per gerarchia, contrasto, ritaglio e apertura del modulo di registrazione.

Il controllo runtime distingue PHP compatibile (8.4+) dalle patch di sicurezza consigliate. La matrice CI include PHP 8.4.0 per verificare che l’avviso non impedisca l’installazione, oltre ai rami 8.4 e 8.5 aggiornati. I test dei runtime meno aggiornati devono restare isolati, con server HTTP vincolato a 127.0.0.1.

I test coprono entrambe le scelte del pacchetto dati (vuoto o di esempio), la registrazione privata del consenso alla licenza, i crediti centralizzati, il blocco dichiarato, il login di recupero, il rifiuto di ripristini anonimi/CSRF non validi e il ripristino del solo credito tramite footer centrale, senza cambiare i sorgenti delle pagine. I tentativi di alterazione avvengono esclusivamente nella copia temporanea.

Le prove dei crediti includono una homepage completamente riscritta, un renderer nuovo senza i vecchi frammenti, markup e CSS diversi, override `!important`, ereditarietà, fogli di stile locali e link nascosti. Le modifiche valide devono lasciare il sito accessibile; il ripristino deve conservare byte per byte tutte le pagine personalizzate e non aggiungere un secondo credito quando ne esiste già uno valido.
