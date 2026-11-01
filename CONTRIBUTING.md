# Contribuire ad AlphanoGdr

Apri una issue per descrivere un problema riproducibile o proporre una funzione. Per una correzione, crea un fork e una pull request con problema, modifica e controlli eseguiti. Usa solo dati sintetici, immagini redistribuibili e credenziali fittizie.

Conserva le licenze e le attribuzioni dei componenti inclusi. Non aggiungere configurazioni reali, `config/local.php`, dump SQL, conversazioni, profili dei giocatori o file di `private/` e `uploads/`.

I controlli e la costruzione del pacchetto sono descritti in [docs/TESTING.md](docs/TESTING.md). Il workflow GitHub verifica PHP 8.4.0, PHP 8.4 e PHP 8.5, lo schema MySQL e l’installazione del pacchetto completo. Le modifiche al database richiedono attenzione agli aggiornamenti delle installazioni esistenti.

Non usare la demo pubblica per verificare vulnerabilità o conservare dati personali. Lavora su una copia isolata, senza collegarla al database di un gioco in attività.
