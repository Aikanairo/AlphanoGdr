<?php
declare(strict_types=1);
require_once __DIR__ . '/libs/kernel.inc.php';
require_once __DIR__ . '/libs/sendmail.inc.php';
$error = '';
$verified = false;
$user = '';
$mail = '';
$resetToken = $_POST['reset_token'] ?? $_GET['token'] ?? '';
if (!is_string($resetToken)) alphano_fail('Collegamento non valido.', 400);
$reset = null;
$db = ConnectDB();
if ($resetToken !== '') {
    if (preg_match('/^[a-f0-9]{64}$/D', $resetToken)) $reset = $db->query('SELECT * FROM password_resets WHERE token_hash=? AND expires_at>?', [hash('sha256',$resetToken),time()])[0] ?? null;
    if (!$reset) $error = 'Il collegamento è scaduto o è già stato utilizzato. Richiedine uno nuovo.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    alphano_require_csrf();
    if ($resetToken !== '' && $reset) {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (!is_string($password) || !is_string($confirm) || strlen($password)<12 || strlen($password)>72 || $password!==$confirm) {
            $error = 'Inserisci due password uguali, da 12 a 72 caratteri.';
        } else {
            try {
                $db->beginTransaction();
                $row = $db->query('SELECT * FROM password_resets WHERE token_hash=? AND expires_at>? FOR UPDATE', [hash('sha256',$resetToken),time()])[0] ?? null;
                if (!$row) throw new DomainException('Il collegamento è già stato utilizzato.');
                $db->execute('UPDATE utenti SET password=? WHERE id=?', [password_hash($password,PASSWORD_DEFAULT),(int)$row['pg']]);
                $db->execute('DELETE FROM password_resets WHERE pg=?', [(int)$row['pg']]);
                $db->commit();
                $verified = true;
                $successMessage = 'Password aggiornata. Puoi accedere dalla pagina iniziale.';
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                $error = $exception instanceof DomainException ? $exception->getMessage() : 'Impossibile aggiornare la password. Riprova.';
                if (!$exception instanceof DomainException) error_log('[recupero password] ' . $exception->getMessage());
            }
        }
    } elseif ($resetToken === '') {
        if (!is_string($_POST['user'] ?? '') || !is_string($_POST['mail'] ?? '')) alphano_fail('Dati non validi.',400);
        $user = trim($_POST['user'] ?? '');
        $mail = trim($_POST['mail'] ?? '');
        if ($user === '' || !filter_var($mail,FILTER_VALIDATE_EMAIL)) $error='Inserisci il nome del PG e una email validi.';
        else {
            $row = $db->query('SELECT id,nome,email FROM utenti WHERE nome=? AND email=? LIMIT 1',[$user,$mail])[0] ?? null;
            if ($row) {
                try {
                    $db->beginTransaction();
                    $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[(int)$row['id']]);
                    $recent = $db->query('SELECT created_at FROM password_resets WHERE pg=?',[(int)$row['id']])[0] ?? null;
                    if (!$recent || (int)$recent['created_at'] < time()-300) {
                        $token = bin2hex(random_bytes(32));
                        $db->execute('REPLACE INTO password_resets(pg,token_hash,created_at,expires_at) VALUES (?,?,?,?)',[(int)$row['id'],hash('sha256',$token),time(),time()+3600]);
                        if (!sendPasswordReset($row['email'],$row['nome'],$token)) throw new RuntimeException('Email non inviata');
                    }
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('[recupero password] ' . $exception->getMessage());
                    $error='Il servizio email non è disponibile. Riprova più tardi.';
                }
            }
            if (!$error) {
                $verified=true;
                $successMessage='Se i dati corrispondono a un personaggio, riceverai un collegamento per scegliere la nuova password. Controlla anche la posta indesiderata.';
            }
        }
    }
}

$recovery = [
    'title' => 'Recupero password',
    'description' => 'Recupera l’accesso al tuo personaggio.',
    'error' => $error, 'message' => $verified ? $successMessage : '',
    'mode' => $verified ? 'complete' : ($reset ? 'password' : 'request'),
    'user' => $user, 'email' => $mail, 'token' => $resetToken,
];
require __DIR__ . '/templates/account/recovery.php';
ClosePage();
