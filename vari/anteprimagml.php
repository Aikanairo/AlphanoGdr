<?PHP
	require("../libs/kernel.inc.php");
	require("../libs/libgrafica.inc.php");
	require("../libs/libpg.inc.php");
	require("../libs/libtext.inc.php");
	alphano_require_login();
    if ($_SERVER["REQUEST_METHOD"] === "POST") alphano_require_csrf();
	$statuto=(string)($_POST["testo"] ?? "");
?>
<HTML>
	<HEAD>
		<TITLE>Anteprima <?=GetTitolo($statuto)?> <?=TitoloSito?></TITLE>

	</HEAD>
	<BODY>
		<DIV class="Valore"><?=ProcessText($statuto)?></DIV>
	</BODY>
</HTML>