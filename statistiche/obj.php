<?php

	require_once("../libs/kernel.inc.php");
	require_once("../libs/libpg.inc.php");
	require_once("../libs/libgrafica.inc.php");
	alphano_require_login();

	$db=ConnectDB();
	$timer=GeneratePastTimestamp(30);
	$dati=["num"=>0];
    $query="SELECT NULL AS nomeobj,0 AS num,0 AS avgprz";
    $_POST["obj"]=(int)($_POST["obj"] ?? 0);
    switch($_POST["verso"] ?? "")
	{
		case "1":
			$query="SELECT nomeobj FROM trasferimenti WHERE obj='{$_POST["obj"]}' AND verso='1'";
			$res=$db->query($query);
			$nomi=array();
			$dati=LegacyFetchRow($res);
			$query=sprintf("SELECT nomeobj, COUNT(*) AS num, AVG(prezzo) AS avgprz FROM trasferimenti WHERE nomeobj='%s' AND stato='5' AND data>'%s' GROUP BY nomeobj",addslashes($dati["nomeobj"]),$timer);
			break;
		case "2":
			$query="SELECT nomeobj, COUNT(*) AS num, AVG(prezzo) AS avgprz FROM trasferimenti WHERE obj='{$_POST["obj"]}' AND (stato='7' OR stato='5') AND verso='2' AND data>'$timer' GROUP BY obj";
			break;
	}
	$res=$db->query($query);
	$dati=LegacyFetchRow($res);
?>
<html>
	<head>
		<TITLE>Statistiche Oggetto <?=TitoloSito?></TITLE>

	</head>
	<body>
<?PHP
	if($dati["num"])
	{
?>
		<table class="column-width-100">
			<TR>
				<TD class="Descrizione align-center">Il prezzo indicato NON &egrave; vincolante</TD>
			</TR>
		</table>
		<table class="column-width-100">
			<TR>
				<TD class="Valore align-center">Oggetto</TD>
				<TD class="Valore align-center"><?=$dati["nomeobj"]?></TD>
			</TR>
			<TR>
				<TD class="Valore align-center">Numero Transazioni</TD>
				<TD class="Valore align-center"><?=$dati["num"]?></TD>
			</TR>
			<TR>
				<TD class="Valore align-center">Prezzo Medio</TD>
				<TD class="Valore align-center"><?=round($dati["avgprz"])?> <?=alphano_currency_html()?></TD>
			</TR>
		</table>
<?PHP
	}
	else
	{
?>
		<table class="column-width-100">
			<TR>
				<TD class="Descrizione align-center">Nessun oggetto di questo tipo mai venduto</TD>
			</TR>
		</table>
<?PHP
	}
?>
	</body>
</html>
<?PHP
	ClosePage();
?>