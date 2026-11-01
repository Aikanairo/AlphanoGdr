<?php
declare(strict_types=1);

/** PHP 8.0-compatible bootstrap; never loads Composer or connects to the database. */
function alphano_runtime_install_url(?string $script = null): string
{
    $base = rtrim(str_replace('\\', '/', dirname($script ?? ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    if (basename($base) === 'install') $base = rtrim(dirname($base), '/');
    if (!preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D', $base)) $base = '';
    return $base.'/install/';
}
function alphano_altervista_php84_config(string $original): string
{
    $original = preg_replace('~\n?# BEGIN AlphanoGdr PHP\r?\n.*?# END AlphanoGdr PHP\r?\n?~s', '', $original);
    return rtrim($original, "\r\n")."\n\n# BEGIN AlphanoGdr PHP\nAddHandler av-php84 .php\n# END AlphanoGdr PHP\n";
}
function alphano_runtime_select_php84(string $root): void
{
    if (is_file($root.'/config/local.php') || is_file($root.'/private/installed.lock')) throw new DomainException('Il sito è già configurato: seleziona PHP dal pannello del provider.');
    if (!is_dir($root.'/private') || !is_writable($root.'/private')) throw new DomainException('La cartella private/ deve essere scrivibile. In alternativa seleziona PHP 8.4 nel pannello AlterVista.');
    $guard = fopen($root.'/private/php-runtime.lock', 'c');
    if (!$guard || !flock($guard, LOCK_EX | LOCK_NB)) throw new DomainException('Selezione PHP già in corso. Riprova fra poco.');
    $temp = null;
    try {
        $path = $root.'/.htaccess';
        if (is_link($path) || (is_file($path) && (!is_readable($path) || filesize($path) > 1048576))) throw new DomainException('Non posso aggiornare il file .htaccess. Seleziona PHP 8.4 dal pannello AlterVista.');
        $original = is_file($path) ? file_get_contents($path) : '';
        if ($original === false) throw new DomainException('Lettura del file .htaccess non riuscita.');
        $backup = $root.'/private/php-runtime-original.htaccess';
        if (!is_file($backup)) {
            $saved = fopen($backup, 'x');
            if (!$saved) throw new DomainException('Impossibile salvare la copia di sicurezza del file .htaccess.');
            chmod($backup, 0600);
            $ok = fwrite($saved, $original) === strlen($original);fclose($saved);
            if (!$ok) {unlink($backup);throw new DomainException('Copia di sicurezza non completata. Nessuna impostazione è stata modificata.');}
        }
        $temp = tempnam($root, '.alphano-php-');
        if ($temp === false) throw new DomainException('La radice del sito non è scrivibile. Seleziona PHP 8.4 dal pannello AlterVista.');
        $updated = alphano_altervista_php84_config($original);
        if (file_put_contents($temp, $updated, LOCK_EX) !== strlen($updated)) throw new DomainException('Scrittura della selezione PHP non riuscita.');
        chmod($temp, is_file($path) ? fileperms($path) & 0777 : 0644);
        if ((is_file($path) ? file_get_contents($path) : '') !== $original) throw new DomainException('Il file .htaccess è cambiato nel frattempo. Riprova.');
        if (!rename($temp, $path)) throw new DomainException('Impossibile attivare PHP 8.4. Selezionalo dal pannello AlterVista.');
        $temp = null;
    } finally {
        if (is_string($temp) && is_file($temp)) unlink($temp);
        flock($guard, LOCK_UN);fclose($guard);
    }
}
function alphano_runtime_setup(string $root): void
{
    require_once __DIR__.'/status-page.inc.php';
    require_once __DIR__.'/http-context.inc.php';
    $fresh = !is_file($root.'/config/local.php') && !is_file($root.'/private/installed.lock');
    $altervista = preg_match('/^[a-z0-9-]+\.altervista\.org(?::443)?$/iD', (string)($_SERVER['HTTP_HOST'] ?? '')) === 1;
    $install = alphano_runtime_install_url();
    $message = 'Il server usa PHP '.PHP_VERSION.'. Per eseguire il gioco e le sue dipendenze serve PHP 8.4: accettare il rischio non rende compatibile PHP 8.0. Puoi proseguire selezionando la versione corretta.';
    $form = null;
    if ($fresh && $altervista) {
        http_response_code(200);
        $error = ''; 
        session_name('alphano_php_setup_'.substr(hash('sha256', $root), 0, 12));
        session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>alphano_https_context()]);
        $_SESSION['runtime_csrf'] ??= bin2hex(random_bytes(32));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!is_string($_POST['_csrf'] ?? null) || !hash_equals($_SESSION['runtime_csrf'], $_POST['_csrf'])) {
                http_response_code(403);$error = 'Sessione scaduta. Ricarica la pagina e riprova: nessuna impostazione è stata modificata.';
            } elseif (($_POST['accept_runtime_change'] ?? '') !== '1') {
                http_response_code(422);$error = 'Per continuare, conferma la selezione di PHP 8.4 per questo sito.';
            } else {
                try {
                    alphano_runtime_select_php84($root);
                    session_write_close();
                    header('Location: '.$install.'?runtime=updated', true, 303);exit;
                } catch (DomainException $e) {http_response_code(422);$error = $e->getMessage();}
            }
        }
        $attempted = isset($_GET['runtime']) && $_GET['runtime'] === 'updated';
        if ($attempted) $message = 'La selezione PHP 8.4 è stata salvata, ma il server sta ancora usando PHP '.PHP_VERSION.'. Apri il pannello AlterVista → Impostazioni PHP, seleziona PHP 8.4 e poi riprova. Non è stato creato né modificato alcun database.';
        else {
            $message = 'Il server sta usando PHP '.PHP_VERSION.'. Possiamo chiedere ad AlterVista di usare PHP 8.4 per questo sito, conservando le altre regole del file .htaccess e salvandone una copia. Poi si aprirà l’installazione. Se la versione selezionata richiede patch di sicurezza, potrai accettarne esplicitamente il rischio e continuare.';
            if ($error !== '') $message .= ' '.$error;
            $form = ['action'=>$install,'csrf'=>$_SESSION['runtime_csrf'],'checkbox'=>'accept_runtime_change','consent'=>'Ho compreso: selezionare PHP 8.4 può cambiare il comportamento di eventuali altri script in questo spazio. Voglio continuare.','button'=>'Attiva PHP 8.4 e continua'];
        }
    }
    alphano_status_page('Prepariamo l’installazione', $message, $install, 'Ho selezionato PHP 8.4: riprova', $form);
    exit;
}
