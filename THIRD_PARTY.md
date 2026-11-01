# Dipendenze e attribuzioni

La licenza AGPL-3.0 del progetto non sostituisce le licenze delle dipendenze né revoca i diritti già concessi sulle versioni precedenti.

| Componente | Licenza / provenienza |
| --- | --- |
| Immagini predefinite `assets/default-*.svg` | Illustrazioni originali distribuite come AGPL-3.0-or-later; catalogo e dettagli in `config/default-art.json` e `docs/DEFAULT-ART.md`. |
| Illustrazioni `assets/headers/*.webp` | Immagini originali generate con lo strumento integrato OpenAI image_gen per questa distribuzione; MIT. Prompt e impronte in `config/header-art.json`, istruzioni in `docs/TEMPLATE.md`. |
| Onspli Chess | MIT, copyright Ondřej Šplíchal; licenza in `third_party/onspli-chess/LICENSE`. Il renderer conserva una correzione del motore per la presa en passant. |
| Quill | BSD-3-Clause; testo in `js/vendor/quill/LICENSE`. |
| Winwheel.js | MIT; copyright e testo conservati nell’intestazione di `js/vendor/Winwheel.js`, provenienza in `Winwheel.NOTICE.txt`. |
| Dompdf, librerie PDF | Installate da Composer, con licenze e attribuzioni upstream dentro `vendor/`. |
| Minishlink WebPush e Guzzle | Installate da Composer, con licenze e attribuzioni upstream dentro `vendor/`. |

Il progetto usa template PHP nativi. I vecchi compilatori Smarty, i renderer PDF precedenti e i loro asset non sono distribuiti; le chiamate PDF passano per un piccolo adattatore su Dompdf.

I vettori preesistenti in `assets/` conservano la licenza AGPL indicata nel loro manifest; le nuove componenti mantengono la licenza specificata nel rispettivo manifest. Su richiesta dell’autore vengono incluse esclusivamente 16 immagini degli slot equipaggiamento e 23 illustrazioni delle materie prime già usate sul suo sito, senza lore o dati di giocatori: inventario e impronte in `config/equipment-art.json`. Questi file sono offerti sotto AGPL-3.0-only, con credito predefinito a https://gothik.it/ senza ulteriori restrizioni di backlink. Le altre immagini conservano la licenza indicata nei rispettivi manifest.

Il contatore delle caselle vuote in Onspli Chess è stato adattato a interi per PHP 8.4, preservando la notazione FEN.

Il logo `assets/alphanogdr-logo.png` è stato fornito dall’autore per il progetto AlphanoGdr ed è distribuito sotto AGPL-3.0-only. Provenienza e impronta sono registrate in `config/brand-art.json`; non proviene dagli asset del gioco dimostrativo esterno.

La favicon `favicon.png` è stata fornita dall’autore per AlphanoGdr ed è distribuita sotto AGPL-3.0-only. Il file PNG originale è conservato senza modifiche; la sua impronta è in `config/brand-art.json`.

Gli sfondi dimostrativi `assets/maps/world.png` e `assets/maps/city.png` sono copie delle immagini caricate dal titolare su alfanew e da lui autorizzate per questa distribuzione, sotto AGPL-3.0-only. `config/map-art.json` ne registra ruoli e hash. Nessun altro upload del sito è incluso.

Gli screenshot di presentazione in `assets/showcase/` sono stati acquisiti dalla demo pubblica autorizzata su alfanew con account dimostrativi. Le otto immagini, elencate in `config/showcase-art.json`, mostrano cinque palette, mappa generale, sottomappa e gestione. Sono distribuite sotto AGPL-3.0-only, ferme le licenze dei componenti originali raffigurati.
