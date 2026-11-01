<?php
declare(strict_types=1);

// Carica il tuo SMTPMailer (mettilo accanto a questo file)
require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/SMTPMailer.php';
require_once __DIR__.'/email-theme.inc.php';

function alphano_mailer(): SMTPMailer
{
    return new SMTPMailer(
        host: alphano_config('smtp_host'), port: alphano_config('smtp_port'),
        tls: alphano_config('smtp_tls'), user: alphano_config('smtp_user'), pass: alphano_config('smtp_pass')
    );
}


/**
 * Invia la mail di benvenuto con password al nuovo utente
 *
 * @param string $email    Email destinatario
 * @param string $nome     Nome del personaggio
 * @param string $frase    Frase epica (non usata nel corpo)
 * @param string $password La password in chiaro
 * @return bool
 */
/**
 * Invia la mail di recupero password
 *
 * @param string $email
 * @param string $nome
 * @param string $frase
 * @param string $password
 * @return bool
 */
function sendMail(string $email,string $nome,string $frase,string $password):bool {
 try{$replace=['{name}'=>$nome,'{game}'=>(string)alphano_config('game_name'),'{url}'=>(string)alphano_config('base_url')];$subject=strtr((string)alphano_config('welcome_subject'),$replace);$body=strtr((string)alphano_config('welcome_body'),$replace);return alphano_mailer()->send((string)alphano_config('mail_from'),$email,$subject,$body,alphano_email_render($subject,$body,'welcome'));}catch(Throwable $e){error_log('[welcome email] '.$e->getMessage());return false;}
}

/**
 * Invia la mail quando viene rigenerata una nuova password
 *
 * @param string $email
 * @param string $nome
 * @param string $frase
 * @param string $password
 * @return bool
 */
function sendNewPass(string $email, string $nome, string $frase, string $password): bool
{
    // Legacy callers now receive a reset link; never send a reusable password.
    $db=ConnectDB();$user=$db->query('SELECT id FROM utenti WHERE email=? AND nome=?',[$email,$nome])[0]??null;
    if(!$user)return false;$token=bin2hex(random_bytes(32));
    $db->execute('REPLACE INTO password_resets(pg,token_hash,created_at,expires_at) VALUES(?,?,?,?)',[(int)$user['id'],hash('sha256',$token),time(),time()+3600]);
    return sendPasswordReset($email,$nome,$token);
}

/**
 * Notifica un giocatore che il suo personaggio è stato cancellato
 *
 * @param array $value Assoc array con chiavi: email, nome, ultimoingresso, id
 * @return void
 */
function sendDelAdvice(array $value): void
{
    $email = $value['email'] ?? '';
    $nome  = $value['nome']  ?? '';
    $id    = (int) ($value['id'] ?? 0);
    $last  = (int) ($value['ultimoingresso'] ?? 0);
    $path='/main.php';

    $mailer = alphano_mailer();

    $from    = alphano_config('mail_from');
    $subject = alphano_config('game_name').': Il tuo personaggio è stato cancellato';
    $body    = "{$nome} è stato cancellato per inattività.";

    if ($last > 0 && $id > 0) {
        $expiry = date('d/m/Y', time() + 7 * 24 * 3600);
        $token = bin2hex(random_bytes(32));
        ConnectDB()->execute('UPDATE recuperopg SET token_hash = ? WHERE id = ?', [hash('sha256', $token), $id]);
        $path="/recuperopg.php?identity={$id}@{$token}";$link=alphano_email_url($path);

        $body .= "\n\nSono passati 60 giorni dalla tua ultima visita e il tuo personaggio è stato rimosso.";
        $body .= "\nSe vuoi ripristinarlo, segui questo link entro il {$expiry}:\n{$link}";
        $body .= "\n\nNota: eventuali privilegi amministrativi o cariche sono stati revocati.";
    }

    try { $mailer->send($from, $email, $subject, $body,alphano_email_render($subject,$body,'alerts',$path)); }
    catch (Throwable $e) { error_log('[mail] ' . $e->getMessage()); }
}

/**
 * Invia il link di attivazione bug
 *
 * @param string $email
 * @param string $codice
 * @return bool
 */
function sendBugActivation(string $email, string $codice): bool
{
    $mailer = alphano_mailer();

    $from    = alphano_config('mail_from');
    $subject = 'Attivazione segnalazione bug per '.alphano_config('game_name');
    $link = rtrim(alphano_config('base_url'), '/') . '/bug/?id=' . rawurlencode($codice);
    $gameName=(string)alphano_config('game_name');
    $body    = <<<EOT
Grazie per aver segnalato un bug su {$gameName}!

Per attivare la tua segnalazione, apri questo link:
{$link}

—
La gestione del gioco
EOT;

    try { return $mailer->send($from, $email, $subject, $body,alphano_email_render($subject,$body,'alerts','/bug/?id='.rawurlencode($codice))); }
    catch (Throwable $e) { error_log('[mail] ' . $e->getMessage()); return false; }
}

/** Il link permette al titolare della casella di scegliere la nuova password. */
function sendPasswordReset(string $email, string $name, string $token): bool
{
    $link = rtrim(alphano_config('base_url'), '/') . '/recuperopasswd.php?token=' . rawurlencode($token);
    $body = "Ciao {$name},\n\nper scegliere una nuova password apri questo collegamento entro un'ora:\n{$link}\n\nSe non hai chiesto il recupero, puoi ignorare questa email.\nLa gestione del gioco";
    try { return alphano_mailer()->send(alphano_config('mail_from'), $email, 'Recupero password '.alphano_config('game_name'), $body,alphano_email_render('Recupero password '.alphano_config('game_name'),$body,'security','/recuperopasswd.php?token='.rawurlencode($token))); }
    catch (Throwable $exception) { error_log('[mail] ' . $exception->getMessage()); return false; }
}
