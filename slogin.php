<?php
declare(strict_types=1);

require_once __DIR__ . '/libs/kernel.inc.php';
require_once __DIR__ . '/libs/libgrafica.inc.php';
require_once __DIR__ . '/libs/libpg.inc.php';
require_once __DIR__ . '/libs/libtext.inc.php';


use Alphano\Database\PDOMySQLDriver;

// -------------------------------------------------
// 1) Inizializzazione e verifica privilegi
// -------------------------------------------------
$idpg = GetIDPG(false);
if ($idpg === null || !IsAdmin($idpg)) {
    // Accesso negato
    http_response_code(403);
    exit('Ritenta, sarai più fortunato…');
}

// -------------------------------------------------
// 2) Gestione del POST con la password
// -------------------------------------------------
$passwd = trim($_POST['passwd'] ?? '');

if ($passwd !== '') {
    // Genera il valore criptato del cookie
    $token = CriptaNome($passwd);

    // Imposta il cookie valido per tutto il dominio
    setcookie(
        'utanto',
        $token,
        [
            'expires'  => time() + 3600,  // 1 ora di validità
            'path'     => '/',            // valido in tutto il sito
            'httponly' => true,           // non accessibile da JS
            'samesite' => 'Lax',          // protezione CSRF minima
            // 'secure' => true,          // attivare se servito via HTTPS
        ]
    );

    // Chiude la finestra popup via JavaScript
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>OK</title>
</head>
<body>
  <script>window.close();</script>
</body>
</html>
HTML;

    ClosePage();
    exit;
}

// -------------------------------------------------
// 3) Rendering del form di inserimento password
// -------------------------------------------------

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Password di amministrazione <?=htmlspecialchars(TitoloSito, ENT_QUOTES)?></title>

</head>
<body>
  <table class="align-center" class="Valore column-width-100">
    <tr>
      <td class="align-center">
        <img src="/assets/placeholder.svg" alt="<?=htmlspecialchars(TitoloSito,ENT_QUOTES)?>">
      </td>
    </tr>
    <tr>
      <td class="align-center">
        <form method="POST">
          <span class="Descrizione">
            Attenzione, sezione protetta da password di amministrazione
            <br><br>
          </span>
          Password:&nbsp;
          <input
            type="password"
            name="passwd"
            size="20"
            autocomplete="off"
            required
          ><br><br>
          <input type="submit" value="Continua">&nbsp;
          <input type="reset" value="Reset">
        </form>
      </td>
    </tr>
  </table>
</body>
</html>
<?php
ClosePage();
