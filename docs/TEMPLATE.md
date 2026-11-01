# Template, homepage e immagini

Un amministratore completo apre **Gestione → Template e homepage**. Le impostazioni rimangono modificabili dopo l’installazione; sono accessibili anche dalle impostazioni generali e dalla fase Template della configurazione guidata.

- **Colori e disposizione**: blu, nero, marrone, rosso e viola; colonna del personaggio a destra o sinistra e navigazione sopra o sotto il contenuto. I sottomenu inferiori si aprono verso l’alto, anche quando il menu principale scorre orizzontalmente.
- **Homepage e manuali**: titolo e presentazione facoltativi, logo, banner e sfondo, oltre all’illustrazione della biblioteca. La home comprende un panorama iniziale, accesso in evidenza e creazione del personaggio espandibile. Senza testi personalizzati vengono usati nome e descrizione generali del gioco. Seleziona fino a 20 capitoli pubblici da fissare prima dell’accesso. Un capitolo successivamente reso riservato scompare automaticamente. L’anteprima della homepage è disponibile al gestore anche mentre è autenticato.
- **Immagini dei menu**: un’immagine distinta per ciascun gruppo principale. Senza immagine viene usata un’icona vettoriale.
- **Immagini dei sottomenu**: ogni voce ha una propria configurazione, comprese quelle dei moduli facoltativi e le voci riservate. Le immagini non modificano i permessi di accesso. Le razze e le città create dal gestore vengono aggiunte al catalogo e conservano la propria configurazione quando cambiano nome.
- **Sfondi di tutte le pagine**: catalogo ricercabile di pagine pubbliche, menu, sottomenu e amministrazioni; include automaticamente le destinazioni della mappa e le singole chat. Per ogni pagina si possono impostare sfondo e immagine dell’intestazione. Ogni pagina ha già una testata illustrata adatta alla sezione, visibile in anteprima nel catalogo. I campi vuoti ereditano le immagini comuni oppure il disegno del pacchetto; le singole città, razze e chat ereditano prima la configurazione della rispettiva sezione.

Carica PNG, JPEG o WebP: massimo 5 MB per file, 6000 pixel per lato e 16 megapixel. Il limite complessivo della richiesta dipende da `post_max_size` del provider: su hosting con limiti ridotti carica un’immagine alla volta. Per le icone sono consigliate immagini quadrate di almeno 128 pixel; per banner e fondali immagini panoramiche. È possibile riutilizzare un percorso locale sotto `/uploads/`. SVG e file eseguibili caricati dagli utenti sono rifiutati.

Il template applica una velatura ai fondali per mantenere leggibili i testi. L’immagine scelta per la pagina non sostituisce le immagini dei luoghi o dei personaggi: queste si gestiscono nei rispettivi moduli. La palette resta comune a tutte le pagine; le immagini caricate mantengono i propri colori.

## Salvataggio e aggiornamenti

Le preferenze grafiche sono conservate in `private/appearance.json`; i caricamenti sono in `uploads/appearance/`. Entrambi sono esclusi dai pacchetti e dalla pubblicazione del sorgente. Le impostazioni generali, credenziali del database e SMTP restano nei propri file privati. Due editor aperti sulla stessa configurazione non possono sovrascriversi senza rileggere la versione aggiornata.

Le pagine aperte nello stesso browser ricevono il nuovo tema senza ricaricare la chat e senza eliminare il testo in scrittura. Gli altri dispositivi applicano la configurazione alla successiva apertura o ricarica della pagina.

`assets/theme.php` riunisce i fogli elencati in `css/theme/theme.css`, poi `assets/engine.css`, `assets/appearance.css` e `assets/portal.css`. Il collegamento contiene un hash del contenuto di **tutti** i moduli CSS, evitando che la cache del provider mantenga vecchi colori. Non servono compilazione, Node.js o processi persistenti sul server. Per aggiungere una nuova pagina al catalogo modifica `config/appearance-pages.json`: gruppo, nome visibile, URL e nome originale della voce di menu (vuoto se è solo una pagina interna).

## Chat e MySQL 8

I blocchi nominativi condivisi da chat, Master e archivi usano `alphano_session_guard()`. La funzione comune `alphano_lock_name()` include il database nel namespace e genera nomi sotto il limite di 64 caratteri di MySQL 8. Non usare un prefisso seguito da un SHA-256 completo: la lunghezza complessiva può impedire tutti gli invii.

La chat mostra gli errori di connessione e riprova automaticamente la lettura. Un invio fallito conserva il testo e l’identificativo della richiesta, evitando duplicati al tentativo successivo. Gli errori interni hanno un codice di assistenza e vengono registrati in `private/chat-errors.log`, mai restituiti integralmente al browser.

## Illustrazioni già incluse

Il pacchetto comprende undici illustrazioni panoramiche originali in WebP, circa 2,6 MB complessivi: città, biblioteca, locanda e bacheche, missive, sala delle gilde, bottega, stanza del personaggio, cronache, giochi, osservatorio e foresta. Le stesse immagini coordinate sono assegnate alle pagine secondo il contenuto: per esempio, biblioteca per manuali e accademia, bottega per equipaggiamento e corporazioni, foresta per le chat. Città, razze e chat create dopo l’installazione ereditano automaticamente il disegno della sezione.

Da **Sfondi di tutte le pagine**, cerca la pagina e premi **Personalizza**. L’anteprima mostra anche il valore ereditato. Scegli un’altra **Immagine del pacchetto**, carica un file oppure riusa un percorso locale. **Rimuovi la personalizzazione** ripristina l’eredità. Ordine: immagine della singola destinazione → sezione → immagine comune del gestore → illustrazione inclusa. Per il panorama della home il banner impostato in **Homepage e manuali** ha precedenza sulla testata della pagina.

I file sono in `assets/headers/`, con licenza MIT; `config/header-art.json` conserva catalogo, dimensioni, impronte SHA-256, prompt di generazione e assegnazioni. `config/homepage-art.json` identifica il panorama iniziale. Nessun servizio IA, processo grafico o collegamento esterno è necessario sul server per mostrarli. Il controllo di rilascio ammette soltanto questi bitmap con impronte verificate, continuando a escludere caricamenti e grafica privata.

## Crediti e progetto

La homepage presenta il progetto GitHub e Gothik.it come esempio di gioco personalizzato. Il footer predefinito riporta «Motore AlphanoGdr V1.0 · Programmato da Alfano Gaetano · Gothik.it», con collegamenti all’autore e al gioco. Il pacchetto AGPL include un controllo locale e il ripristino descritti in ATTRIBUTION.md. Puoi riscrivere il template, modificare o rimuovere il controllo e i link secondo la AGPL. Conserva gli avvisi di copyright e licenza richiesti dalla AGPL; nessun backlink promozionale aggiuntivo è imposto.

Il collegamento «Codice sorgente» predefinito punta alla repository originale. Se offri online una versione modificata, devi offrire il relativo Sorgente Corrispondente agli utenti secondo la sezione 13 AGPL: aggiorna questo link con un download accessibile della tua versione; gli obblighi delle singole componenti sono descritti in THIRD_PARTY.md. Non caricare dati dei giocatori o credenziali.

La sezione con le tre schede introduttive è rimossa; le illustrazioni restano disponibili per le intestazioni e nella libreria del pacchetto.

## Pagine completamente personalizzate

Puoi cambiare ogni pagina, compresi i frammenti dei crediti, il renderer e i fogli di stile. Non vengono richiesti file originali o una chiamata PHP specifica. Il nuovo HTML deve conservare un credito leggibile con Alfano Gaetano e il collegamento a Gothik.it; la scheda equipaggiamento conserva anche il credito della grafica. Il ripristino aggiunge soltanto un footer quando manca quello personalizzato e non sovrascrive il tuo layout. Vedi ATTRIBUTION.md per verifica dell’HTML e limiti relativi a CSS complessi o JavaScript.

Il logo fornito per AlphanoGdr è incluso in `assets/alphanogdr-logo.png` (manifesto `config/brand-art.json`). La parte principale della homepage lo richiama tramite `alphano_brand_logo()`: dimensioni e proporzioni sono coordinate dai CSS, senza deformare il file. Un logo caricato in **Template e homepage → Homepage e manuali** sostituisce quello predefinito nella parte principale della homepage. La barra superiore della homepage usa soltanto la A di `favicon.png`, piccola e proporzionata; la parte principale mostra il logo completo al posto del nome testuale; un titolo personalizzato continua a poter essere impostato.

Le pagine di amministrazione usano una navigazione laterale comune e tutto lo spazio disponibile. La stessa palette alimenta campi, schede e pulsanti della colonna sinistra; su mobile il menu si apre con **Sezioni**. Vedi [Amministrazione](AMMINISTRAZIONE.md). La favicon fornita dall’autore è richiamata centralmente anche nell’installatore e nelle pagine di errore.
