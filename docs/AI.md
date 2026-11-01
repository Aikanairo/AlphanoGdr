# Integrazione IA facoltativa

L’installatore imposta `ai_enabled=false`. Non scarica modelli, non chiama servizi esterni e non crea processi di inferenza. Chat, schede, email, economia e sessioni con Master umani funzionano senza IA. Anche i giochi da tavolo sono risolti in PHP.

Le funzioni di inferenza e accodamento controllano l’interruttore sul server. La semplice presenza di un URL o di una chiave non abilita l’integrazione. Il catalogo PNG e le code iniziali sono vuoti. Disabilitare la funzione sospende il consumo dei lavori; non cancella storici o configurazioni.

## Collegamento successivo

Un amministratore può configurare URL, nome del modello e chiave in Gestione → Impostazioni del gioco, poi attivare l’interruttore. Servono cURL e un servizio HTTP compatibile con **llama.cpp server**: il motore usa il contratto di completamento e, per alcune funzioni, salute, proprietà e tokenizzazione. L’URL può essere remoto: non è necessario installare un modello sull’hosting.

Questa versione non contiene un adattatore universale per OpenAI, Anthropic o qualsiasi API `/v1/chat/completions`. Un servizio con un contratto diverso richiede un adattatore in `libs/ai.inc.php`. Nome del modello e contesto non installano o cambiano da soli il modello sul servizio remoto.

Configura scene e PNG con i tuoi dati dagli strumenti amministrativi, quindi esegui un worker limitato:

```sh
php bin/ai-worker.php
```

La singola esecuzione ha una finestra limitata e può essere pianificata con cron se l’hosting consente PHP CLI e tempi sufficienti. Un normale shared hosting può limitare timeout e processi: in quel caso mantieni l’IA disabilitata o usa un worker separato configurato per questa sola installazione. Non viene fornito né attivato un controller per installare modelli locali.

## Dati e regole

L’endpoint riceve testo della scena e contesto pertinente. Configura le informative della tua comunità prima di attivare un fornitore esterno. Le chiavi restano nella configurazione privata. Il motore verifica autorizzazioni e regole di gioco sul server; il testo prodotto dal modello non è una query SQL o un comando eseguibile.

I meccanismi avanzati di stirpi mantengono adattatori tecnici nel codice, ma sono disabilitati nel mondo minimo (`lineages_enabled=false`): prima di riattivarli occorre definire un catalogo di razze compatibile. Non sono inclusi cast, personalità, segreti, missioni o contenuti del gioco originario.
