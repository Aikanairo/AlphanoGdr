<?PHP
	require_once("../libs/kernel.inc.php");
	require_once("../libs/libpg.inc.php");
	require_once("../libs/libequip.inc.php");
	alphano_require_login();
	$db=ConnectDB();

	$_GET["master"]=isset($_GET["master"]) ? (int)$_GET["master"] : null;
    $_GET["sessione"]=isset($_GET["sessione"]) ? (int)$_GET["sessione"] : null;
    switch(TRUE) {
		case isset($_GET["master"]):
			$condition="logs_adminpg.esecutore='{$_GET["master"]}' AND logs_adminpg.data BETWEEN sessioni.datain AND sessioni.dataout";
			break;
		case isset($_GET["sessione"]):
			$query="SELECT id, datain, dataout FROM sessioni WHERE id='{$_GET["sessione"]}'";
			$res=$db->query($query);
			$dati=LegacyFetchRow($res);
            if (!$dati) { http_response_code(404); exit('Sessione non trovata'); }
			$condition="sessioni.id='{$dati["id"]}' AND logs_adminpg.data BETWEEN {$dati["datain"]} AND {$dati["dataout"]}";
			break;
		default:
			die("Nessun tipo di indagine selezionato");
	}

	$query="SELECT sessioni.id,sessioni.nome,logs_adminpg.obj,logs_adminpg.azione,logs_adminpg.qta,logs_adminpg.esecutore AS master,master.nome AS nomemaster,destinatario.nome AS nomedestinatario,destinatario.id AS destinatario FROM `logs_adminpg`,sessioni,utenti AS destinatario,utenti AS master WHERE logs_adminpg.azione LIKE 'ricompensa%' AND destinatario.id=logs_adminpg.destinatario AND master.id=logs_adminpg.esecutore AND logs_adminpg.chat=sessioni.chat AND $condition";
	$res=$db->query($query);
	$sessioni=array();
	while($dati=LegacyFetchRow($res)) {
		$sessioni[$dati["id"]]["nome"]=$dati["nome"];
		$sessioni[$dati["id"]]["master"][$dati["master"]]["nome"]=$dati["nomemaster"];
		$sessioni[$dati["id"]]["master"][$dati["master"]]["ricompense"][$dati["destinatario"]]["nome"]=$dati["nomedestinatario"];
		if($dati["azione"]=="ricompensa(oggetto)") $sessioni[$dati["id"]]["master"][$dati["master"]]["ricompense"][$dati["destinatario"]]["obj"][$dati["obj"]] = ($sessioni[$dati["id"]]["master"][$dati["master"]]["ricompense"][$dati["destinatario"]]["obj"][$dati["obj"]] ?? 0) + 1;
		else $sessioni[$dati["id"]]["master"][$dati["master"]]["ricompense"][$dati["destinatario"]]["soldi"] = ($sessioni[$dati["id"]]["master"][$dati["master"]]["ricompense"][$dati["destinatario"]]["soldi"] ?? 0) + (int)$dati["qta"];
	}
?>
<HTML>
	<HEAD>
		<TITLE>Statistiche Ricompense <?=TitoloSito?></TITLE>

		<SCRIPT language="JavaScript" src="/js/global.js"></SCRIPT>
	</HEAD>
	<BODY>
		<DIV class="Valore">
			<SPAN class="Descrizione">ATTENZIONE:</SPAN> <b>Le ricompense sono associate alla sessione tramite luogo e intervallo di apertura.</b><br>
<?PHP
	if(count($sessioni)) {
?>
			<UL>
<?PHP
		foreach($sessioni as $key => $dati1) {
?>
				<LI>
					Sessione: <?=htmlentities($dati1["nome"])?><br>
<?PHP
			$query="SELECT * FROM sessioni WHERE id='$key'";
			$res=$db->query($query);
			$dati=LegacyFetchRow($res);
?>
					Aperta da: <A href="javascript:openProfileId('<?=$dati["masterin"]?>')"><?=htmlentities(GetPGFromID($dati["masterin"]))?></A> in data <?=FormatTimestamp($dati["datain"])?><br>
					Chiusa da: <A href="javascript:openProfileId('<?=$dati["masterout"]?>')"><?=htmlentities(GetPGFromID($dati["masterout"]))?></A> in data <?=FormatTimestamp($dati["dataout"])?><br>
<?PHP
			$query="SELECT id,nome FROM utenti WHERE id IN ('".implode("','",explode(";",substr($dati["partecipanti"],1,-1)))."') ORDER BY nome";
			$res=$db->query($query);
			$partecipanti=array();
			while($dati=LegacyFetchRow($res)) $partecipanti[]="<A href=\"javascript:openProfileId('{$dati["id"]}')\">".htmlentities($dati["nome"])."</A>";
?>
					Partecipanti: <?=implode(", ",$partecipanti)?><br>
					Ricompense:<br>
					<UL>
<?PHP
			foreach($dati1["master"] as $id2 => $dati2) {
?>
						<LI>
							<A href="javascript:openProfileId('<?=$id2?>')"><?=$dati2["nome"]?></A><br>
							<UL>
<?PHP
				foreach($dati2["ricompense"] as $id3 => $dati3) {
?>
								<LI>
									<A href="javascript:openProfileId('<?=$id3?>')"><?=htmlentities($dati3["nome"])?></A>
<?PHP
					if(($dati3["soldi"] ?? 0)) {
?>
									(<?=($dati3["soldi"] ?? 0)?> <?=alphano_currency_html()?>)
<?PHP
					}
					foreach(($dati3["obj"] ?? []) as $key => $qta) {
?>
									(<?=$qta?> <?=htmlentities(GetEquipDesc($key))?>)
<?PHP
					}
?>
								</LI>
<?PHP
				}
?>
							</UL>
						</LI>
<?PHP
			}
?>
					</UL>
				</LI>
<?PHP
		}
?>
			</UL>
<?PHP
	}
	else {
?>
			<DIV class="align-center">
<?PHP
		switch(TRUE) {
			case isset($_GET["master"]):
?>
				Nessuna ricompensa trovata per il master selezionato.
<?PHP
				break;
			case isset($_GET["sessione"]):
?>
				Nessuna ricompensa trovata per la sessione selezionata.
<?PHP
				break;
		}
?>
			</DIV>
<?PHP
	}
?>
		</DIV>
		<DIV class="align-center">
<?PHP
	switch(TRUE) {
		case isset($_GET["master"]):
?>
			<INPUT type="button" value="Torna alle statistiche Master" onclick="javascript:window.location.href = 'master.php'">
<?PHP
			break;
		case isset($_GET["sessione"]):
?>
			<INPUT type="button" value="Torna alla lista Sessioni" onclick="javascript:window.location.href = '../sessione/viewlog.php'">
<?PHP
			break;
	}
?>
		</DIV>
	</BODY>
</HTML>
