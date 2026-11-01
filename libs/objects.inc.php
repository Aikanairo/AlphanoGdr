<?php
// Compatibilità dei vecchi oggetti del gioco, con persistenza PDO unica.
require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libpg.inc.php';
class AlphanoBase {
    var $className;
    var $table;
    var $key = 'id';
    var $dati = [];
    function __construct($id=null) { if ($id !== null) $this->load($id); }
    protected function identifier($name) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('Identificatore non valido');
        return '`' . $name . '`';
    }
    protected function condition($where) {
        if (is_array($where) && $where) {
            $parts=[]; foreach ($where as $key=>$value) $parts[]=$this->identifier($key).' = ?';
            return [implode(' AND ',$parts), array_values($where)];
        }
        // Sintassi storica dei chiamanti interni: confronti semplici, convertiti in parametri.
        if (is_string($where) && preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(\d+)$/D', trim($where), $m)) return [$this->identifier($m[1]).' = ?',[(int)$m[2]]];
        throw new InvalidArgumentException('Usare criteri di ricerca espliciti');
    }
    function load($id,$key=null) { $rows=ConnectDB()->query('SELECT * FROM '.$this->identifier($this->table).' WHERE '.$this->identifier($key??$this->key).' = ? LIMIT 1',[$id]);$this->setArray($rows[0]??[]);return (bool)$rows; }
    function loadList($ids) { $out=[];foreach ((array)$ids as $id) { $item=new $this->className($id);if($item->dati)$out[]=$item; }return $out; }
    function getKeyList() { return array_column(ConnectDB()->query('SELECT '.$this->identifier($this->key).' FROM '.$this->identifier($this->table)),$this->key); }
    function search($where) { [$sql,$params]=$this->condition($where);$out=[];foreach(ConnectDB()->query('SELECT * FROM '.$this->identifier($this->table).' WHERE '.$sql,$params) as $row){$item=new $this->className();$item->setArray($row);$out[]=$item;}return $out; }
    function create($data=null) { $data=$data??$this->dati;if(!$data)return false;$keys=array_map([$this,'identifier'],array_keys($data));ConnectDB()->execute('INSERT INTO '.$this->identifier($this->table).' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));$data[$this->key]=$data[$this->key]??(int)ConnectDB()->lastInsertId();$this->setArray($data);return true; }
    function delete($id=null) { return ConnectDB()->execute('DELETE FROM '.$this->identifier($this->table).' WHERE '.$this->identifier($this->key).' = ?',[$id??$this->getKey()])>0; }
    function deleteQuery($where) { [$sql,$params]=$this->condition($where);return ConnectDB()->execute('DELETE FROM '.$this->identifier($this->table).' WHERE '.$sql,$params); }
    function update($id=null) { return $this->updateQuery($this->dati,[$this->key=>$id??$this->getKey()]); }
    function updateQuery($values,$where=null) { if(!$values)return 0;[$sql,$params]=$this->condition($where);$set=[];foreach($values as $key=>$value)$set[]=$this->identifier($key).' = ?';return ConnectDB()->execute('UPDATE '.$this->identifier($this->table).' SET '.implode(',',$set).' WHERE '.$sql,[...array_values($values),...$params]); }
    function get($key) { return $this->dati[$key]??null; }
    function getKey() { return $this->get($this->key); }
    function getArray() { return $this->dati; }
    function set($key,$value) { $this->identifier($key);$this->dati[$key]=$value; }
    function setArray($values) { $this->dati=is_array($values)?$values:[]; }
    function getQuery() { $keys=array_map([$this,'identifier'],array_keys($this->dati));$values=array_map(fn($v)=>$v===null?'NULL':ConnectDB()->quote((string)$v),array_values($this->dati));return 'INSERT INTO '.$this->identifier($this->table).' ('.implode(',',$keys).') VALUES ('.implode(',',$values).')'; }
}
	class Accademia extends AlphanoBase {
		var $className="Accademia";
		var $table="accademia";

		function Accademia($id=null) {
			parent::__construct($id);
		}
	}

	class Araldiche extends AlphanoBase {
		var $className="Araldiche";
		var $table="araldiche";

		function Araldiche($id=null) {
			parent::__construct($id);
		}
	}

	class Armadio extends AlphanoBase {
		var $className="Armadio";
		var $table="armadio";

		function Armadio($id=null) {
			parent::__construct($id);
		}
	}

	class Assemblea extends AlphanoBase {
		var $className="Assemblea";
		var $table="assemblea";

		function Assemblea($id=null) {
			parent::__construct($id);
		}
	}

	class Avvisi extends AlphanoBase {
		var $className="Avvisi";
		var $table="avvisi";

		function Avvisi($id=null) {
			parent::__construct($id);
		}
	}

	class Bacheche extends AlphanoBase {
		var $className="Bacheche";
		var $table="bacheche";

		function Bacheche($id=null) {
			parent::__construct($id);
		}

        function ThreadsInfo($id=null) {
            $id = $id ?: $this->get('bacheca');
            $rows = ConnectDB()->query('SELECT replyid, MAX(data) AS lastreply FROM bacheche WHERE replyid>0 AND bacheca=? GROUP BY replyid', [(int)$id]);
            return array_column($rows, 'lastreply', 'replyid');
        }

	}

	class Bug extends AlphanoBase {
		var $className="Bug";
		var $table="bug";

		function Bug($id=null) {
			parent::__construct($id);
		}
	}

	class Candidati extends AlphanoBase {
		var $className="Candidati";
		var $table="candidati";

		function Candidati($id=null) {
			parent::__construct($id);
		}
	}

	class CaricheCorporazioni extends AlphanoBase {
		var $className="CaricheCorporazioni";
		var $table="carichecorporazioni";

		function CaricheCorporazioni($id=null) {
			parent::__construct($id);
		}
	}

	class CaricheOrganizzazioni extends AlphanoBase {
		var $className="CaricheOrganizzazioni";
		var $table="caricheorganizzazioni";

		function CaricheOrganizzazioni($id=null) {
			parent::__construct($id);
		}
	}

	class Chat extends AlphanoBase {
		var $className="Chat";
		var $table="chat";

		function Chat($id=null) {
			parent::__construct($id);
		}
	}

	class City extends AlphanoBase {
		var $className="City";
		var $table="city";

		function City($id=null) {
			parent::__construct($id);
		}
	}

	class Corporazioni extends AlphanoBase {
		var $className="Corporazioni";
		var $table="corporazioni";

		function Corporazioni($id=null) {
			parent::__construct($id);
		}
	}

	class Descrizioni extends AlphanoBase {
		var $className="Descrizioni";
		var $table="descrizioni";

		function Descrizioni($id=null) {
			parent::__construct($id);
		}
	}

	class Dimore extends AlphanoBase {
		var $className="Dimore";
		var $table="dimore";

		function Dimore($id=null) {
			parent::__construct($id);
		}
	}

	class Diritti extends AlphanoBase {
		var $className="Diritti";
		var $table="diritti";

		function Diritti($id=null) {
			parent::__construct($id);
		}
	}

	class Discussioni extends AlphanoBase {
		var $className="Discussioni";
		var $table="discussioni";

		function Discussioni($id=null) {
			parent::__construct($id);
		}
	}

	class Elezioni extends AlphanoBase {
		var $className="Elezioni";
		var $table="elezioni";

		function Elezioni($id=null) {
			parent::__construct($id);
		}
	}

	class Equip extends AlphanoBase {
		var $className="Equip";
		var $table="equip";

		function Equip($id=null) {
			parent::__construct($id);
		}

		function delete($id=null) {
			if(parent::delete($id)) {
				$filename=htmlroot."/uploads/equipment/".$this->getKey();
				if(file_exists($filename)) unlink($filename);
				$filename=htmlroot."/uploads/equipment-pending/".$this->getKey();
				if(file_exists($filename)) unlink($filename);
				return TRUE;
			}
			return FALSE;
		}
	}

	class EquipCorp extends AlphanoBase {
		var $className="EquipCorp";
		var $table="equipcorp";

		function EquipCorp($id=null) {
			parent::__construct($id);
		}
	}

	class EquipPG extends AlphanoBase {
		var $className="EquipPG";
		var $table="equippg";

		function EquipPG($id=null) {
			parent::__construct($id);
		}
	}

	class Etnie extends AlphanoBase {
		var $className="Etnie";
		var $table="etnie";

		function Etnie($id=null) {
			parent::__construct($id);
		}
	}

	class Giocate extends AlphanoBase {
		var $className="Giocate";
		var $table="giocate";

		function Giocate($id=null) {
			parent::__construct($id);
		}
	}

	class Indirizzi extends AlphanoBase {
		var $className="Indirizzi";
		var $table="indirizzi";

		function Indirizzi($id=null) {
			parent::__construct($id);
		}
	}

	class Lavori extends AlphanoBase {
		var $className="Lavori";
		var $table="lavori";

		function Lavori($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_AdminPG extends AlphanoBase {
		var $className="Logs_AdminPG";
		var $table="logs_adminpg";

		function Logs_AdminPG($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_DeleteMail extends AlphanoBase {
		var $className="Logs_DeleteMail";
		var $table="logs_deletemail";

		function Logs_DeleteMail($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_DeleteUser extends AlphanoBase {
		var $className="Logs_DeleteUser";
		var $table="logs_deleteuser";

		function Logs_DeleteUser($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Dimore extends AlphanoBase {
		var $className="Logs_Dimore";
		var $table="logs_dimore";

		function Logs_Dimore($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Equip extends AlphanoBase {
		var $className="Logs_Equip";
		var $table="logs_equip";

		function Logs_Equip($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Flag extends AlphanoBase {
		var $className="Logs_Flag";
		var $table="logs_flag";

		function Logs_Flag($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Ingressi extends AlphanoBase {
		var $className="Logs_Ingressi";
		var $table="logs_ingressi";

		function Logs_Ingressi($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Meteo extends AlphanoBase {
		var $className="Logs_Meteo";
		var $table="logs_meteo";

		function Logs_Meteo($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Profili extends AlphanoBase {
		var $className="Logs_Profili";
		var $table="logs_profili";

		function Logs_Profili($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Registrazione extends AlphanoBase {
		var $className="Logs_Registrazione";
		var $table="logs_registrazione";

		function Logs_Registrazione($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Statistica extends AlphanoBase {
		var $className="Logs_Statistica";
		var $table="logs_statistica";

		function Logs_Statistica($id=null) {
			parent::__construct($id);
		}
	}

	class Logs_Visite extends AlphanoBase {
		var $className="Logs_Visite";
		var $table="logs_visite";

		function Logs_Visite($id=null) {
			parent::__construct($id);
		}
	}

	class Luoghi extends AlphanoBase {
		var $className="Luoghi";
		var $table="luoghi";

		function Luoghi($id=null) {
			parent::__construct($id);
		}
	}

	class Missive extends AlphanoBase {
		var $className="Missive";
		var $table="mail";

		function Missive($id=null) {
			parent::__construct($id);
		}
	}

	class Meteo extends AlphanoBase {
		var $className="Meteo";
		var $table="meteo";

		function Meteo($id=null) {
			parent::__construct($id);
		}
	}

	class NewEquip extends AlphanoBase {
		var $className="NewEquip";
		var $table="newequip";

		function NewEquip($id=null) {
			parent::__construct($id);
		}
	}

	class NomiVietati extends AlphanoBase {
		var $className="NomiVietati";
		var $table="nomivietati";

		function NomiVietati($id=null) {
			parent::__construct($id);
		}
	}

	class Organizzazioni extends AlphanoBase {
		var $className="Organizzazioni";
		var $table="organizzazioni";

		function Organizzazioni($id=null) {
			parent::__construct($id);
		}
	}

	class PareriSessioni extends AlphanoBase {
		var $className="PareriSessioni";
		var $table="parerisessioni";

		function PareriSessioni($id=null) {
			parent::__construct($id);
		}
	}

	class Prestiti extends AlphanoBase {
		var $className="Prestiti";
		var $table="prestiti";

		function Prestiti($id=null) {
			parent::__construct($id);
		}
	}

	class PrestitiPG extends AlphanoBase {
		var $className="PrestitiPG";
		var $table="prestitipg";

		function PrestitiPG($id=null) {
			parent::__construct($id);
		}
	}

	class ProduzioniCorporazioni extends AlphanoBase {
		var $className="ProduzioniCorporazioni";
		var $table="produzionicorporazioni";

		function ProduzioniCorporazioni($id=null) {
			parent::__construct($id);
		}
	}

	class RamiAraldiche extends AlphanoBase {
		var $className="RamiAraldiche";
		var $table="ramiaraldiche";

		function RamiAraldiche($id=null) {
			parent::__construct($id);
		}
	}

	class RamiCorporazioni extends AlphanoBase {
		var $className="RamiCorporazioni";
		var $table="ramicorporazioni";

		function RamiCorporazioni($id=null) {
			parent::__construct($id);
		}
	}

	class RamiOrganizzazioni extends AlphanoBase {
		var $className="RamiOrganizzazioni";
		var $table="ramiorganizzazioni";

		function RamiOrganizzazioni($id=null) {
			parent::__construct($id);
		}
	}

	class Razze extends AlphanoBase {
		var $className="Razze";
		var $table="razze";

		function Razze($id=null) {
			parent::__construct($id);
		}
	}

	class RecuperoPG extends AlphanoBase {
		var $className="RecuperoPG";
		var $table="recuperopg";

		function RecuperoPG($id=null) {
			parent::__construct($id);
		}
	}

	class Registrazioni extends AlphanoBase {
		var $className="Registrazioni";
		var $table="registrazioni";

		function Registrazioni($id=null) {
			parent::__construct($id);
		}
	}

	class RegistrazioniChat extends AlphanoBase {
		var $className="RegistrazioniChat";
		var $table="registrazionichat";

		function RegistrazioniChat($id=null) {
			parent::__construct($id);
		}
	}

	class Regole extends AlphanoBase {
		var $className="Regole";
		var $table="regole";

		function Regole($id=null) {
			parent::__construct($id);
		}
	}

	class Richieste extends AlphanoBase {
		var $className="Richieste";
		var $table="richieste";

		function Richieste($id=null) {
			parent::__construct($id);
		}
	}

	class RiSondaggi extends AlphanoBase {
		var $className="RiSondaggi";
		var $table="risondaggi";

		function RiSondaggi($id=null) {
			parent::__construct($id);
		}
	}

	class Sessioni extends AlphanoBase {
		var $className="Sessioni";
		var $table="sessioni";

		function Sessioni($id=null) {
			parent::__construct($id);
		}
	}

	class SezioniRegole extends AlphanoBase {
		var $className="SezioniRegole";
		var $table="sezioniregole";

		function SezioniRegole($id=null) {
			parent::__construct($id);
		}
	}

	class Sondaggi extends AlphanoBase {
		var $className="Sondaggi";
		var $table="sondaggi";

		function Sondaggi($id=null) {
			parent::__construct($id);
		}
	}

	class StoricoBacheche extends AlphanoBase {
		var $className="StoricoBacheche";
		var $table="storicobacheche";

		function StoricoBacheche($id=null) {
			parent::__construct($id);
		}
	}

	class SubTipi extends AlphanoBase {
		var $className="SubTipi";
		var $table="subtipi";

		function SubTipi($id=null) {
			parent::__construct($id);
		}
	}

	class Tipo extends AlphanoBase {
		var $className="Tipo";
		var $table="tipo";

		function Tipo($id=null) {
			parent::__construct($id);
		}
	}

	class Trasferimenti extends AlphanoBase {
		var $className="Trasferimenti";
		var $table="trasferimenti";

		function Trasferimenti($id=null) {
			parent::__construct($id);
		}
	}

	class Umori extends AlphanoBase {
		var $className="Umori";
		var $table="umori";

		function Umori($id=null) {
			parent::__construct($id);
		}
	}

	class Utenti extends AlphanoBase {
		var $className="Utenti";
		var $table="utenti";

		function Utenti($id=null) {
			parent::__construct($id);
		}

		function EnsureAdmin() {
			if($this->get("admin")) {
				if(GetAdminKey()!=admin_passwd) {
					header("location: /slogin.php");
				}
			}
		}

		function SendDelAdvice() {
			require_once __DIR__ . '/sendmail.inc.php'; sendDelAdvice($this->dati);
		}

		function getEquip() {
			return (new EquipPG())->search(['idpg' => $this->get('id')]);
		}

		function getArmadio() {
			return (new Armadio())->search(['idpg' => $this->get('id')]);
		}

		function TogliAraldica() {
			$this->set("araldica",1);//imposto a Viandante
			$this->set("sindaco",0);
			$this->update();
		}

		function TogliOrganizzazione() {
			$this->set("organizzazione",0);
			$this->set("caricaorganizzazione",0);
			$this->set("ramoorganizzazione",0);
			$this->set("adminorganizzazione",0);
			$this->set("adminstatosociale",0);
			$this->set("adminferite",0);
			$this->set("adminmorti",0);
			$this->update();
		}

		function TogliCorporazione() {
			$this->set("corporazione",0);
			$this->set("caricacorporazione",0);
			$this->set("ramocorporazione",0);
			$this->set("admincorporazione",0);
			$this->set("admincatalogo",0);
			$this->set("admintrasfcorp",0);
			$this->update();
		}
	}

	//classe per istanziare tutte le classi derivate da AlphanoBase
	class AlphanoProxy {
		private $classes = array();
		/*l'array delle classi e' attualmente inutile visto che l'istanziamento e' on demand
		private $classNames = array(
		"Accademia",
		"Araldiche",
		"Armadio",
		"Assemblea",
		"Avvisi",
		"Bacheche",
		"Bug",
		"Candidati",
		"CaricheCorporazioni",
		"CaricheOrganizzazioni",
		"Chat",
		"City",
		"Corporazioni",
		"Descrizioni",
		"Dimore",
		"Diritti",
		"Discussioni",
		"Elezioni",
		"Equip",
		"EquipCorp",
		"EquipPG",
		"Etnie",
		"Giocate",
		"Indirizzi",
		"Lavori",
		"Logs_AdminPG",
		"Logs_DeleteMail",
		"Logs_DeleteUser",
		"Logs_Dimore",
		"Logs_Equip",
		"Logs_Flag",
		"Logs_Ingressi",
		"Logs_Meteo",
		"Logs_Profili",
		"Logs_Registrazione",
		"Logs_Statistica",
		"Logs_Visite",
		"Luoghi",
		"Missive",
		"Meteo",
		"NewEquip",
		"NomiVietati",
		"Organizzazioni",
		"PareriSessioni",
		"Prestiti",
		"PrestitiPG",
		"ProduzioniCorporazioni",
		"RamiAraldiche",
		"RamiCorporazioni",
		"RamiOrganizzazioni",
		"Razze",
		"RecuperoPG",
		"Registrazioni",
		"RegistrazioniChat",
		"Regole",
		"Richieste",
		"RiSondaggi",
		"Sessioni",
		"SezioniRegole",
		"Sondaggi",
		"StoricoBacheche",
		"SubTipi",
		"Tipo",
		"Trasferimenti",
		"Umori",
		"Utenti",
		);
		*/

		public function AlphanoProxy() {
			$this->__construct();
		}

		public function __construct() {
			//non fa nulla poiche' l'istanziamento e' on demand
		}

		public function _call($class, $function, $params=null) {
			if (!is_subclass_of($class, AlphanoBase::class)) throw new InvalidArgumentException('Classe non ammessa');
            if(!isset($this->classes[$class])) {
				$this->classes[$class]= new $class();//istanziamento on demand
			}
			if(!is_array($params)) {
				$params=array(0 => $params);
			}
			return call_user_func_array(array($this->classes[$class], $function), $params);
		}

		public static function call($class, $function, $params=null) {
			if(!isset($GLOBALS["_AlphanoProxy"])) {
				$GLOBALS["_AlphanoProxy"]= new AlphanoProxy();
			}
			return $GLOBALS["_AlphanoProxy"]->_call($class, $function, $params);
		}
	}
?>