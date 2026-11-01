# Struttura e personalizzazione

| Percorso | Responsabilità |
| --- | --- |
| `install/` | Requisiti, autorizzazione iniziale, prima pagina, schema vuoto e account del gestore |
| `database/schema.sql` | Solo struttura del database e vincoli |
| `config/app.php` | Valori predefiniti e override di ambiente |
| `config/local.php` | Connessione privata, creata dall’installatore, esclusa da Git |
| `private/settings.json` | Personalizzazioni del gioco e segreti facoltativi, esclusi da Git |
| `libs/` | Servizi del motore, autorizzazioni, regole e driver PDO |
| `api/` | Endpoint autenticati e operazioni con CSRF |
| `chat/` | Chat HTTP, ricevute e flusso dei messaggi |
| `templates/` | Viste native PHP e componenti condivisi |
| `css/theme/` | Stile del motore e dei moduli |
| `assets/engine.css` | Palette, disposizione e interfacce di installazione e gestione |
| `assets/placeholder.svg` | Vettore dimostrativo originale |
| `admin/setup.php` | Configurazione a fasi, checklist e cataloghi del gestore |
| `libs/setup.inc.php` | Salvataggio dei passaggi e dei cataloghi |
| `admin/settings.php` | Personalizzazione del sito, email e IA |
| `admin/world.php` | Destinazioni e chat con immagini |
| `uploads/world/` | Immagini caricate dallo staff, escluse da Git |
| `bin/` | Comandi CLI facoltativi, senza installazione di servizi |
| `tests/` | Installazione da zero, protezioni e collaudi del motore |

I nomi delle funzioni interne usano il prefisso `alphano_`; i nomi storici delle tabelle sono mantenuti per preservare i moduli del motore. Le vecchie pagine di amministrazione delle mappe rimandano al gestore unico del mondo.

## Tema

`data-palette`, `data-sidebar` e `data-header` vengono applicati a tutti i documenti dal livello di presentazione. Ogni iframe legge la stessa configurazione. Le variabili CSS in `assets/engine.css` definiscono le cinque palette. I template del contenuto rimangono in `templates/` e sono modificabili in PHP; non serve compilare JavaScript.

Su desktop ci sono due colonne. La colonna del personaggio può stare da entrambi i lati, e la navigazione prima o dopo il contenuto centrale. La navigazione sotto apre i menu verso l’alto. Su schermi piccoli si applica la disposizione mobile, per conservare accessibilità e spazio per la chat.

## Mondo

`world_areas` contiene città e territori navigabili; `city_id` collega facoltativamente una destinazione al governo cittadino. `mappe.area_id` associa ogni chat alla sua destinazione. `enabled` e `public_enabled` controllano disponibilità e accesso diretto. `position` e `sort_order` stabiliscono l’ordine delle schede.

Le immagini del nuovo gestore vengono validate per MIME, formato, peso e dimensioni. Non sono accettati SVG caricati dagli utenti o URL remoti come immagini delle schede. La grafica di esempio può essere sostituita con file propri di cui il gestore dispone dei diritti.

## Contenuti di gioco

Il seed iniziale si trova in `install/installer.php`. Non è un dump di dati esistenti. Abilità, razze, caratteristiche e costi si modificano nella gestione RPG; gruppi, cariche, statuti e cataloghi nelle relative amministrazioni; i manuali dal loro editor. I cataloghi narrativi di `config/` sono vuoti e non includono materiale di ambientazione.

`abilities_enabled` sospende l’uso del sistema delle abilità, conservando il catalogo per lo staff. Le statistiche rimangono disponibili. I cataloghi avanzati partono vuoti e vanno popolati dal gestore; il motore non applica seed del gioco originario.
