<?PHP
	function DatiPost($id)
	{
        $id=(int)$id;
		global $db;
		if(!isset($db)) $db=ConnectDB();

		$query="SELECT COUNT(*) AS num, MAX(id) AS maxid FROM discussioni WHERE replyid=$id";
		$res=$db->query($query);
		$dati=LegacyFetchRow($res);
		$ret["numrisp"]=$dati["num"];
		if($dati["maxid"])
		{
			$query=sprintf("SELECT utenti.id, utenti.nome, discussioni.data FROM discussioni,utenti WHERE discussioni.id='%s' AND utenti.id=discussioni.idpg",$dati["maxid"]);
			$res=$db->query($query);
			$dati=LegacyFetchRow($res);
			$ret["id"]=$dati["id"];
			$ret["nome"]=$dati["nome"];
			$ret["data"]=$dati["data"];
		}
		return $ret;
	}

	function IsNewThread($id,$time)
	{
        $id=(int)$id; $time=(int)$time;
		global $db;
		if(!isset($db)) $db=ConnectDB();

		$query="SELECT MAX(data) AS maxdata FROM discussioni WHERE id='$id' OR replyid='$id'";
		$res=$db->query($query);
		$dati=LegacyFetchRow($res);
		if($dati["maxdata"]>$time) return TRUE;
		else return FALSE;
	}
?>