function CreateGMLHelper(obj)
{
	document.writeln("<A title=\"Titolo\" href=\"javascript:GMLT(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Titolo\"></A>");
	document.writeln("<A title=\"Paragrafo\" href=\"javascript:GMLP(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Paragrafo\"></A>");
	document.writeln("<A title=\"Grassetto\" href=\"javascript:GMLB(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Grassetto\"></A>");
	document.writeln("<A title=\"Corsivo\" href=\"javascript:GMLC(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Corsivo\"></A>");
	document.writeln("<A title=\"Centrato\" href=\"javascript:GMLCE(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Centrato\"></A>");
	document.writeln("<A title=\"Colore del Testo\" href=\"javascript:GMLTC(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Colore del Testo\"></A>");
	document.writeln("<A title=\"Immagine\" href=\"javascript:GMLI(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Immagine\"></A>");
	document.writeln("<A title=\"Collegamento\" href=\"javascript:GMLLNK(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Collegamento\"></A>");
	document.writeln("<A title=\"Colore di Sfondo\" href=\"javascript:GMLBGC(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Colore di Sfondo\"></A>");
	document.writeln("<A title=\"Immagine di Sfondo\" href=\"javascript:GMLBGI(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Immagine di Sfondo\"></A>");
	document.writeln("<A title=\"Tabella\" href=\"javascript:GMLTBL(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Tabella\"></A>");
	document.writeln("<A title=\"Riga di Tabella\" href=\"javascript:GMLRG(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Riga di Tabella\"></A>");
	document.writeln("<A title=\"Colonna di Tabella\" href=\"javascript:GMLCL(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Colonna di Tabella\"></A>");
	document.writeln("<A title=\"Citazione\" href=\"javascript:GMLQ(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Citazione\"></A>");
	document.writeln("<A title=\"Scegli Colore\" href=\"javascript:ApriScegliColore()\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Scegli Colore\"></A>");
	document.writeln("<A title=\"Anteprima\" href=\"javascript:ApriAnteprima(" + obj + ")\"><IMG src=\"/assets/placeholder.svg\" border=\"0\" alt=\"Anteprima\"></A>");
}

function ApriScegliColore()
{
	window.open("/vari/colori.html","GColorChooser","toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=no,width=300,height=100");
}

function ApriAnteprima(obj)
{
	window.open("","GAnteprimaGML","toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=no,width=800,height=600");
	var objform = document.createElement("FORM");
	objform.id = "frmAnteprimaGML";
	objform.method = "POST";
	objform.action = "/vari/anteprimagml.php";
	objform.target = "GAnteprimaGML";
	document.body.appendChild(objform);
	//textarea e non input altrimenti alcuni browser non sono contenti e non so perche'
	var objtext = document.createElement("TEXTAREA");
	objtext.name = "testo";
	objtext.value = obj.value;
	objform.appendChild(objtext);
	objform.submit();
	document.body.removeChild(objform);
}

function GMLT(obj)
{
	var tmp = prompt("Inserisci il Titolo","");
	if(tmp.length) Inserisci(obj,"[t]" + tmp + "[/t]");
}

function GMLP(obj)
{
	Inserisci(obj,"[p][/p]");
}

function GMLB(obj)
{
	Inserisci(obj,"[b][/b]");
}

function GMLC(obj)
{
	Inserisci(obj,"[c][/c]");
}

function GMLCE(obj)
{
	Inserisci(obj,"[ce][/ce]");
}

function GMLTC(obj)
{
	var tmp = prompt("Inserisci il colore del testo","");
	if(tmp.length) Inserisci(obj,"[tc " + tmp + "][/tc]");
}

function GMLI(obj)
{
	var tmp = prompt("Inserisci l'URL dell'immagine","");
	if(tmp.length)
	{
		var tmp2 = prompt("Inserisci altezza e larghezza dell'immagine divisi da una x (lascia vuoto se non le vuoi specificare)","");
		if(tmp2.length) Inserisci(obj,"[i " + tmp2 + "]" + tmp + "[/i]");
		else Inserisci(obj,"[i]" + tmp + "[/i]");
	}
}

function GMLLNK(obj)
{
	var tmp = prompt("Inserisci l'URL della pagina da collegare","");
	if(tmp.length)
	{
		var tmp2 = prompt("Inserisci il testo del collegamento (lascia vuoto se vuoi usare l'URL)","");
		if(tmp2.length) Inserisci(obj,"[lnk " + tmp + "]" + tmp2 + "[/lnk]");
		else Inserisci(obj,"[lnk]" + tmp + "[/lnk]");
	}
}

function GMLBGC(obj)
{
	var tmp = prompt("Inserisci il colore di sfondo","");
	if(tmp.length) Inserisci(obj,"[bgc " + tmp + "][/bgc]");
}

function GMLBGI(obj)
{
	var tmp = prompt("Inserisci l'URL dell'immagine di sfondo","");
	if(tmp.length) Inserisci(obj,"[bgi " + tmp + "][/bgi]");
}

function GMLTBL(obj)
{
	var tmp = prompt("Inserisci altezza e larghezza della tabella divisi da una x (lascia vuoto se non le vuoi specificare)","");
	if(tmp.length) Inserisci(obj,"[tbl " + tmp + "][/tbl]");
	else Inserisci(obj,"[tbl][/tbl]");
}

function GMLRG(obj)
{
	Inserisci(obj,"[rg][/rg]");
}

function GMLCL(obj)
{
	var tmp = prompt("Inserisci il numero di colonne da occupare in larghezza (lascia vuoto se non lo vuoi specificare)","");
	if(tmp.length) Inserisci(obj,"[cl " + tmp + "][/cl]");
	else Inserisci(obj,"[cl][/cl]");
}

function GMLQ(obj)
{
	var tmp = prompt("Inserisci il nome del Personaggio che vuoi citare","");
	if(tmp.length) Inserisci(obj,"[q " + tmp + "][/q]");
}

function Inserisci(obj,txt)
{
	tmp = obj.scrollTop;
	if(document.selection)
	{
		obj.focus();
		sel = document.selection.createRange();
		sel.text = txt;
	}
	else if(obj.selectionStart || obj.selectionStart == "0") obj.value = obj.value.substring(0, obj.selectionStart) + txt + obj.value.substring(obj.selectionEnd, obj.value.length);
	obj.focus();
	obj.scrollTop = tmp;
}