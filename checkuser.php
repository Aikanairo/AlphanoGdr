<?php
declare(strict_types=1);


// 1) Dipendenze
require __DIR__ . '/libs/kernel.inc.php';   // ConnectDB(), ClosePage(), TitoloSito, maxpuntif
require __DIR__ . '/libs/libpg.inc.php';    // doLogin(), GetIDPG(), SetPosizione()

use Alphano\Database\PDOMySQLDriver;

// 2) Blocca accessi non POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Accesso diretto non consentito');
}

// I browser inviano Origin nei POST: rifiuta accessi avviati da altri siti.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$expectedOrigin = alphano_canonical_origin((string)alphano_config('base_url'));
if (($origin !== '' && strcasecmp($origin, $expectedOrigin) !== 0) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    alphano_fail('Accesso da un altro sito non consentito.',403);
}

// 3) Preleva input dal form header
if (!is_string($_POST['username'] ?? '') || !is_string($_POST['password'] ?? '')) alphano_fail('Credenziali non valide.', 400);
$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');

// 4) Tenta il login
if (!doLogin($username, $password)) {
    // Post/Redirect/Get: a failed login remains readable without parent scripts.
    // Never retain the submitted password or distinguish unknown characters.
    $_SESSION['alphano_login_error']=['username'=>mb_substr($username,0,20)];
    header('Cache-Control: no-store');
    header('Location: '.(($_POST['credit_recovery']??'')==='1'?'/credits/restore.php?login=failed':'/?login=failed#accesso'),true,303);
    exit;
}

if(($_POST['credit_recovery']??'')==='1'){header('Location: /credits/restore.php',true,303);exit;}

// 5) Utente loggato: prendi l’ID
$idpg = GetIDPG(true);
// Un nuovo benvenuto per ogni accesso riuscito, mai per un semplice refresh.
$_SESSION['fortune_login_id'] = bin2hex(random_bytes(16));
unset($_SESSION['fortune_welcome_seen']);

// 6) Recupera dati essenziali e calcola PF
/** @var PDOMySQLDriver $db */
$db  = ConnectDB();
$now = time();
$row = $db->query(
    'SELECT razza, infetto, ancestrale, puntif, ferite, ultimoingresso
       FROM utenti WHERE id = ? LIMIT 1',
    [$idpg]
)[0];

$days   = (int) floor(($now - (int)$row['ultimoingresso']) / 86400);
$puntif = (int)$row['puntif'];
if ($days > 0 && $puntif < maxpuntif) {
    if ((int)$row['infetto'] || (int)$row['ancestrale']) {
        $puntif += $days * (int)(maxpuntif / 20);
    } else {
        switch ((int)$row['razza']) {
            case 1:
                $puntif += $days * (int)(maxpuntif / 40);
                break;
            case 2:
                $puntif += $days * (int)(maxpuntif / 20);
                break;
            case 4: case 7: case 8:
                $puntif = maxpuntif;
                break;
            case 5:
                $puntif += $days * (int)(maxpuntif / (1000/75));
                break;
        }
    }
    $puntif = min($puntif, maxpuntif);
    if ($puntif >= maxpuntif) {
        $row['ferite'] = '';
    }
}

// 7) Aggiorna DB con nuova posizione e PF
$db->execute(
    'UPDATE utenti
        SET posizione      = ?,
            chat           = ?,
            lastmove       = ?,
            puntif         = ?,
            ferite         = ?,
            vacanza        = 0,
            ultimoingresso = ?
      WHERE id = ?',
    [
      'online',
      '',
      $now,
      $puntif,
      (string)$row['ferite'],
      $now,
      $idpg
    ]
);

// 8) Imposta posizione server
SetPosizione('online', $idpg, '');

// 9) Apri il gioco nella finestra corrente, anche dal form in iframe.
$siteTitle = TitoloSito;
echo <<<HTML
<!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><title>Login riuscito – {$siteTitle}</title>

  <script>
    function ApriAlphano() {
      window.top.location.href = '/main.php';
    }
    window.onload = ApriAlphano;
  </script>
</head>
<body>
  <div id="avviso">
    <h1>Impossibile aprire la finestra principale</h1>
    <p>Abilita i pop-up per questo dominio e riprova.</p>
    <button onclick="parent.Apri('hero')">Torna alla Home</button>
  </div>
</body>
</html>
HTML;

ClosePage();
