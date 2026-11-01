<?php

	require_once("../libs/kernel.inc.php");
	require_once("../libs/libpg.inc.php");
	require_once("../libs/libgrafica.inc.php");
	alphano_require_login();



	$db=ConnectDB();
	$query="SELECT id,nome FROM utenti WHERE fatepower='1'";
	$res=$db->query($query);
	$master=$masters=array();
	while($dati=LegacyFetchRow($res)) {
		$masters[]=$dati["id"];
		$master[$dati["id"]]=["nome"=>$dati["nome"],"ricompense"=>0];
	}

	$query="SELECT esecutore,COUNT(*) AS tot FROM logs_adminpg WHERE logs_adminpg.azione LIKE 'ricompensa%' AND esecutore IN ('".implode("','",$masters)."') GROUP BY esecutore";
	$res=$db->query($query);
	while($dati=LegacyFetchRow($res)) $master[$dati["esecutore"]]["ricompense"]=$dati["tot"];

	$query="SELECT id,masterin FROM sessioni WHERE masterin=masterout AND masterin IN ('".implode("','",$masters)."') AND run=0";
	$res=$db->query($query);
	$sessioni=array();
	while($dati=LegacyFetchRow($res)) $sessioni[$dati["masterin"]][]=$dati["id"];

	$query="SELECT sessione,parere FROM parerisessioni";
	$res=$db->query($query);
	$parerisessioni=array();
	while($dati=LegacyFetchRow($res)) $parerisessioni[$dati["sessione"]][]=$dati["parere"];
?>
<HTML>
	<HEAD>
		<TITLE>Statistiche Master <?=TitoloSito?></TITLE>

		<SCRIPT language="JavaScript" src="/js/global.js"></SCRIPT>
		<SCRIPT language="JavaScript">
			var orderby = new String();
			var ordersort = true;
			var ricompense = "&Egrave; il numero di ricompense date ai pg durante le sessioni.<BR>Comprende sia ricompense monetarie che oggetti.<BR>Premi sul numero per visualizzare la lista delle ricompense date da quel master.";
			var sessioni = "&Egrave; il numero di sessioni svolte unicamente dal Master in questione nella sua carriera.<BR>In questo numero non sono comprese sessioni fatte da pi&ugrave; Master in contemporanea o sessioni svolte prima dell'inserimento del sistema Feedback.";
			var media = "&Egrave; la media dei feedback ricevuti dal Master in tutte le sue sessioni.<BR>Questo tipo di classifica premia i master con un andamento costante mentre penalizza chi ha fatto anche solo una sessione con Feedback negativo. In compenso mette alla pari i Master pi&ugrave; attivi ed i master che hanno fatto poche sessioni.<BR>Il valore massimo &egrave; 1 e il valore minimo &egrave; -1.";
			var somma = "&Egrave; la somma dei voti ricevuti dal Master, senza tenere conto ne del numero di partecipanti ne del numero di sessioni svolte.<BR>Questo tipo di classificazione premia i master che hanno svolto molte sessioni e/o sessioni con molti partecipanti, ma non tiene conto del numero di sessioni svolte, quindi i Master con molte sessioni svolte avranno un'altissima probabilit&agrave; di trovarsi ai vertici di questa classificazione.";
			var numero = "&Egrave; la somma dei Feedback ricevuti.<BR>Questo tipo di classificazione non tiene conto del numero di sessioni svolte, quindi un master può riuscire a salire ai vertici di questa classificazione anche se alcune sue sessioni hanno avuto un feedback negativo.";
			var master = Array();
<?PHP
	$i=0;
	foreach($master AS $id => $dati) {
?>
			master[<?=$i?>] = new Object();
			master[<?=$i?>]["id"] = new Number("<?=$id?>");
			master[<?=$i?>]["nome"] = new String("<?=addslashes(htmlentities($dati["nome"]))?>");
			master[<?=$i?>]["ricompense"] = new Number("<?=$dati["ricompense"]?>");
<?PHP
		$numpar=0;
		$media=0;
		$somma=0;
		$numero=0;
		foreach(($sessioni[$id] ?? []) as $value) {
			$num=0;
			$numpar+=count($parerisessioni[$value] ?? []);
			foreach(($parerisessioni[$value] ?? []) as $valore) $num+=$valore;
			$somma+=$num;
			if(count($parerisessioni[$value] ?? [])) $numero+=$num/count($parerisessioni[$value] ?? []);
			else $numero+=0;
		}
		if($numpar) $media=$somma/$numpar;
		else $media=0;
?>
			master[<?=$i?>]["media"] = new Number("<?=round($media,2)?>");
			master[<?=$i?>]["somma"] = new Number("<?=$somma?>");
			master[<?=$i?>]["numero"] = new Number("<?=round($numero,2)?>");
			master[<?=$i?>]["sessioni"] = new Number("<?=count($sessioni[$id] ?? [])?>");
<?PHP
		$i++;
	}
?>
			function Carica(order) {
				var out = new String("<TABLE class=\"column-width-100\"><TR><TD class=\"Valore align-center\"><A href=\"javascript:Carica('nome')\">Nome</A>");
				if(order == "nome") {
					out += "<span aria-hidden=\"true\">";
					if(order == orderby && ordersort) {
						out += " ↓";
						master.sort(Ordina);
						ordersort = false;
						master.reverse();
					}
					else {
						if(order == orderby) ordersort = true;
						orderby = order;
						out += " ↑";
						master.sort(Ordina);
					}
					out += "</span>";
				}
				out += "</TD><TD class=\"Valore align-center\"><A href=\"javascript:Carica('sessioni')\">Sessioni</A><A href=\"#\" onmouseover=\"javascript:MostraDescrizione('hint',sessioni)\" onmouseout=\"NascondiDescrizione('hint')\"><IMG src=\"/assets/placeholder.svg\" border=\"0\"></A>";
				if(order == "sessioni") {
					out += "<span aria-hidden=\"true\">";
					if(order == orderby && ordersort) {
						out += " ↓";
						master.sort(Ordina);
						ordersort = false;
						master.reverse();
					}
					else {
						if(order == orderby) ordersort = true;
						orderby = order;
						out += " ↑";
						master.sort(Ordina);
					}
					out += "</span>";
				}
				out += "</TD><TD class=\"Valore align-center\"><A href=\"javascript:Carica('ricompense')\">Ricompense</A><A href=\"#\" onmouseover=\"javascript:MostraDescrizione('hint',ricompense)\" onmouseout=\"NascondiDescrizione('hint')\"><IMG src=\"/assets/placeholder.svg\" border=\"0\"></A>";
				if(order == "ricompense") {
					out += "<span aria-hidden=\"true\">";
					if(order == orderby && ordersort) {
						out += " ↓";
						master.sort(Ordina);
						ordersort = false;
						master.reverse();
					}
					else {
						if(order == orderby) ordersort = true;
						orderby = order;
						out += " ↑";
						master.sort(Ordina);
					}
					out += "</span>";
				}
				out += "</TD><TD class=\"Valore align-center\"><A href=\"javascript:Carica('media')\">Media Voti</A><A href=\"#\" onmouseover=\"javascript:MostraDescrizione('hint',media)\" onmouseout=\"NascondiDescrizione('hint')\"><IMG src=\"/assets/placeholder.svg\" border=\"0\"></A>";
				if(order == "media") {
					out += "<span aria-hidden=\"true\">";
					if(order == orderby && ordersort) {
						out += " ↓";
						master.sort(Ordina);
						ordersort = false;
						master.reverse();
					}
					else {
						if(order == orderby) ordersort = true;
						orderby = order;
						out += " ↑";
						master.sort(Ordina);
					}
					out += "</span>";
				}
				out += "</TD><TD class=\"Valore align-center\"><A href=\"javascript:Carica('somma')\">Somma Voti</A><A href=\"#\" onmouseover=\"javascript:MostraDescrizione('hint',somma)\" onmouseout=\"NascondiDescrizione('hint')\"><IMG src=\"/assets/placeholder.svg\" border=\"0\"></A>";
				if(order == "somma") {
					out += "<span aria-hidden=\"true\">";
					if(order == orderby && ordersort) {
						out += " ↓";
						master.sort(Ordina);
						ordersort = false;
						master.reverse();
					}
					else {
						if(order == orderby) ordersort = true;
						orderby = order;
						out += " ↑";
						master.sort(Ordina);
					}
					out += "</span>";
				}
				out += "</TD><TD class=\"Valore align-center\"><A href=\"javascript:Carica('numero')\">Somma Valori</A><A href=\"#\" onmouseover=\"javascript:MostraDescrizione('hint',numero)\" onmouseout=\"NascondiDescrizione('hint')\"><IMG src=\"/assets/placeholder.svg\" border=\"0\"></A>";
				if(order == "numero") {
					out += "<span aria-hidden=\"true\">";
					if(order == orderby && ordersort) {
						out += " ↓";
						master.sort(Ordina);
						ordersort = false;
						master.reverse();
					}
					else {
						if(order == orderby) ordersort = true;
						orderby = order;
						out += " ↑";
						master.sort(Ordina);
					}
					out += "</span>";
				}
				out += "</TD></TR>";
				for(var i = 0; i < master.length; i++) {
					out += "<TR><TD class=\"Valore align-center\"><A href=\"javascript:openProfileId('" + master[i]["id"] + "')\">" + master[i]["nome"] + "</A></TD><TD class=\"Valore align-right\">" + master[i]["sessioni"] + "</TD><TD class=\"Valore align-right\"><A href=\"ricompense.php?master=" + master[i]["id"] + "\">" + master[i]["ricompense"] + "</A></TD><TD class=\"Valore align-right\">" + master[i]["media"] + "</TD><TD class=\"Valore align-right\">" + master[i]["somma"] + "<TD class=\"Valore align-right\">" + master[i]["numero"] + "</TD></TR><TR><TD class=\"column-width-100\" height=\"1\" colspan=\"6\"></TD></TR>";
				}
				document.getElementById("lista").innerHTML = out + "</TABLE>";
			}
			function Ordina(a,b) {
				if(orderby=="nome") {
					if(a[orderby].toString().toUpperCase() < b[orderby].toString().toUpperCase()) return -1;
					if(a[orderby].toString().toUpperCase() > b[orderby].toString().toUpperCase()) return 1;
				}
				else {
					if(a[orderby] < b[orderby]) return -1;
					if(a[orderby] > b[orderby]) return 1;
				}
				return 0;
			}
		</SCRIPT>
	</HEAD>
	<BODY onload="javascript:Carica('nome')">
		<DIV id="lista"></DIV>
		<CENTER><INPUT type="button" value="Torna Indietro" onclick="javascript:window.location.href = '/sessione/viewlog.php'"></CENTER>
	</BODY>
</HTML>