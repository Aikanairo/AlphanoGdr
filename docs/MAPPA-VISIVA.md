# Mappa a card o interattiva

Da **Gestione → Mappa visiva** scegli la modalità predefinita. Le card restano disponibili come alternativa e come elenco accessibile; il passaggio di modalità non cancella città, chat o giocate.

1. Apri «Mappa generale» e carica uno sfondo PNG, JPEG o WebP (massimo 6 MB e 6000 × 6000 pixel).
2. Seleziona una destinazione esistente. Se una città esiste soltanto nell’amministrazione cittadina, usa «Aggiungi una città esistente»: crea il suo collegamento cartografico senza duplicare il governo.
3. Scegli «Immagine cerchiata e nome» o «Solo nome». Premi «Posiziona», trascina il segnaposto oppure imposta X e Y tra 0 e 100. Le frecce spostano il segnaposto selezionato; Maiusc aumenta il passo.
4. Salva. Per modificare l’immagine cerchiata di città o luogo, apri **Gestione del mondo** e modifica la relativa scheda.
5. Scegli una città nel selettore «Mappa da modificare»: carica la sua sottomappa e posiziona le chat collegate. Puoi assegnare uno sfondo diverso a ogni destinazione.

Sfondo e segnaposti condividono lo stesso sistema di coordinate, relativo all’immagine originale. Le coordinate sono percentuali, non pixel dello schermo; zoom, panning, rotazione del dispositivo e larghezza del contenitore non cambiano la posizione salvata. Cambiare il disegno dello sfondo può naturalmente richiedere di riposizionare i luoghi.

La vista interattiva occupa tutto il contenitore centrale, senza intestazione esterna, fascia inferiore o elenco sotto l’immagine. I soli strumenti sono sovrapposti alla mappa. La vista iniziale usa un ingrandimento **cover**, riempiendo lo spazio: parte dei bordi può essere fuori vista. Trascina per esplorare, usa +/−, «Riempi» o il selettore dei luoghi. Lo zoom minimo nella vista di gioco mantiene sempre coperto l’intero riquadro, anche dopo un ridimensionamento o trascinamento. «Intera», che può lasciare margini per mostrare tutti i bordi del disegno, è disponibile soltanto nell’editor. È supportato il pinch su schermi touch. La rotella controlla lo zoom quando la mappa ha il focus, dopo un clic. Il pulsante schermo intero funziona dove consentito dal browser. In un iframe il browser può impedirlo; la mappa rimane utilizzabile nel contenitore.

Le destinazioni non posizionate restano disponibili nel selettore flottante «Trova un luogo»: selezionandole si apre la relativa città o chat. Quelle disabilitate e le chat non pubbliche non vengono esposte ai giocatori. Se manca uno sfondo, si usano le card. Il controllo di accesso della chat viene sempre eseguito dal suo controller, anche arrivando dalla mappa.

La configurazione è in `private/world-map.json`, con scritture atomiche e controllo dei salvataggi concorrenti. Le immagini caricate sono in `uploads/world/`; nessun JavaScript o SVG caricato viene eseguito come sfondo. Includere entrambi nei backup privati. Gli aggiornamenti del motore li conservano; il pacchetto di esempio include soltanto i due sfondi espressamente autorizzati dal titolare per la distribuzione, elencati in `config/map-art.json`. Non include il resto degli upload del sito.

## Esempio pronto all’installazione

Lasciando selezionato **Includi i dati di esempio**, la modalità visiva è già attiva. La mappa generale contiene soltanto City Alphano; aprendo la città compare la sua sottomappa con Piazza, Locanda, Giardino e Biblioteca già posizionate. Gli sfondi sono `assets/maps/world.png` e `assets/maps/city.png`, inclusi anche nel download GitHub. I collegamenti usano gli ID creati durante quella installazione. Non viene creata Alphaland. Senza pacchetto di esempio, la configurazione resta vuota e il gestore può scegliere card o mappa visiva.

L’aggiornamento dei file su un sito già installato non applica nuovamente questi esempi e conserva mappe, immagini caricate e posizioni esistenti.

### Come personalizzare la demo

1. In **Gestione → Mappa visiva**, apri **Mappa generale** per sostituire lo sfondo del mondo o spostare il segnaposto City Alphano.
2. Seleziona **City Alphano** nello stesso pannello: qui modifichi la seconda immagine e le posizioni di Piazza, Locanda, Giardino e Biblioteca. Le due mappe sono indipendenti.
3. Per cambiare nome, descrizione e immagine delle chat, apri **Gestione del mondo**. Mantieni la destinazione City Alphano per farle comparire sulla sottomappa.
4. Salva e apri l’anteprima. Coordinate e immagini rimangono modificabili; la vista a card resta disponibile. Lo sfondo iniziale e quello cittadino si trovano anche in `assets/maps/`; nomi e coordinate della demo sono in `config/demo-data.json`.

I dati di esempio si applicano soltanto alle nuove installazioni che lasciano selezionata l’opzione corrispondente. Un aggiornamento conserva i dati e la cartografia del gioco esistente.
