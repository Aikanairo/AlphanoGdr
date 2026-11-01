<?php
declare(strict_types=1);



require_once __DIR__ . '/../libs/kernel.inc.php';      // ConnectDB(), initSession()…
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';       // GetIDPG(), ClosePage(), …


// (opzionale) se vuoi restrizioni d’accesso:
// initSession();
// if (! GetIDPG(false, true)) { header('Location:/login.php'); exit; }


?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>EMS :: Equip Management System</title>

</head>
<body>

  <header>EMS :: Equip Management System</header>

  <main>
    <div class="intro-image">
      <img src="./ems.jpg" alt="EMS Logo">
    </div>
    <div class="intro-text">
      <p>Benvenuti nell’<strong>Equip Management System</strong> (EMS) di Alphano.</p>
      <p>
        Questo sistema di gestione permette di stabilire quali oggetti si ha addosso
        e quali si porta con sé. Per vedere le azioni associate a un particolare
        oggetto, è sufficiente fare clic sullo slot che lo contiene: in questa
        area compariranno tutte le informazioni sull’oggetto selezionato.
      </p>
      <p><strong>NOTA:</strong><br>
        Gli slot degli oggetti sono evidenziati con un bordo colorato che ne
        indica il tipo.</p>
      <p>
        L’EMS è ispirato — soprattutto per l’impostazione grafica — al sistema
        di gestione degli equipaggiamenti de <em>“Il Tempio del Male Elementale”</em>
        di Atari.
      </p>
    </div>
  </main>

</body>
</html>
<?php
// se vuoi chiudere la sessione o fare altre pulizie:
// ClosePage();
?>
