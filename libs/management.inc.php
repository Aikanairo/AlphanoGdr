<?php
declare(strict_types=1);
/** Presentation-only catalog. Every destination keeps its own authorization checks. */
function alphano_management_scope(string $path):?string{
 if(str_starts_with($path,'/admin/'))return in_array(basename($path),['master-engine.php','master-ia.php','png-relazioni.php','ferite.php','malus.php','blocco.php','giocata.php','chats.php'],true)?'master':'gestione';
 if($path==='/sessione/sessione.php')return 'master';
 if(in_array($path,['/organizzazioni/admin.php','/corporazioni/admin.php','/corporazioni/oggetti-admin.php','/governo/index.php'],true))return 'governo';
 if($path==='/regole/index.php'&&(alphano_can('admin')||alphano_can('adminaccademia')))return 'regole';
 if($path==='/discussioni/index.php'&&in_array($_GET['tipo']??'',['M','A'],true)&&(alphano_can('admin')||alphano_can('fatepower')||alphano_can('adminaccademia')))return 'master';
 return null;
}
function alphano_management_catalog(array $user):array{
 $admin=!empty($user['admin']);$allowed=static function(array $flags)use($admin,$user):bool{return $admin||(bool)array_filter($flags,static fn($f)=>!empty($user[$f]));};
 $definitions=[
  'gestione'=>['Gestione del gioco','settings',[
   ['Riepilogo configurazione','/admin/setup.php','Passaggi e stato del gioco',[]],['Impostazioni generali','/admin/settings.php','Nome, accesso, email e servizi',[]],['Template e homepage','/admin/appearance.php','Colori, immagini e disposizione',[]],['Cookie e privacy','/admin/privacy.php','Titolare, informative ed esempi',[]],['Città e chat','/admin/world.php','Destinazioni, immagini e luoghi',[]],['Mappa visiva','/admin/world-map.php','Sfondi, città e segnaposti',[]],['Utenti e permessi','/admin/utenti.php','Account, incarichi e stato sociale',['adminstatosociale']],['Assistenza utenti','/admin/assistenza.php','Accessi di supporto e registro',[]],['Messaggio di benvenuto','/admin/welcome.php','Accoglienza e comunicazioni',[]],['Meteo e luna','/admin/weather-assets.php','Icone e presentazione del cielo',[]],['Giochi da tavolo','/admin/giochi.php','Scacchi, dama e disponibilità',[]]]],
  'regole'=>['Regole e contenuti','book',[
   ['Razze, abilità ed esperienza','/admin/rpg.php','Cataloghi, statistiche e progressione',[]],['Lavori e materie prime','/admin/lavori.php','Risorse, raccolta e produzione',['adminaccademia']],['Accademia','/admin/accademia.php','Valutazioni e approvazioni',['adminaccademia']],['Manuali','/regole/','Capitoli, accessi e regolamento',['adminaccademia']],['Registro delle operazioni','/admin/log.php','Consultazione dei log',['adminaccademia']]]],
  'master'=>['Strumenti Master','scroll',[
   ['Regia sessione','/sessione/sessione.php','Luoghi, partecipanti e conduzione',['fatepower']],['Vulnerabilità e condizioni','/admin/malus.php','Stato dei personaggi in gioco',['fatepower']],['Salute e ferite','/admin/ferite.php','Ferite, morti e interventi',['fatepower','adminferite','adminmorti']],['Gestione chat','/admin/chats.php','Accessi e disponibilità dei luoghi',['fatepower','adminaccademia']],['Moderazione','/admin/blocco.php','Blocchi e motivazioni',['fatepower']],['Tracce quest','/discussioni/?tipo=M','Materiale riservato alle quest',['fatepower']]]],
  'ia'=>['Intelligenza artificiale','sparkles',[
   ['Luoghi e personaggi','/admin/master-ia.php','Ambientazione e identità dei PNG',[]],['Amministrazione IA','/admin/master-engine.php','Regole, fonti e verifiche',[]],['Relazioni dei PNG','/admin/png-relazioni.php','Ricordi, rapporti e contesto',['fatepower']]]],
  'governo'=>['Città e gruppi','city',[
   ['Amministrazione città','/admin/city.php','Cittadini, richieste e decisioni',['sindaco']],['Cariche e araldiche','/admin/cariche.php','Ruoli, rami e incarichi',['sindaco']],['Bacheche cittadine','/admin/bacheche.php','Canali, permessi e discussioni',['sindaco']]]]
 ];
 $out=[];
 foreach($definitions as $key=>[$title,$icon,$items]){
  if($key==='ia'&&!alphano_config('ai_enabled'))continue;$entries=[];
  foreach($items as [$label,$url,$description,$flags])if($allowed($flags))$entries[]=['label'=>$label,'url'=>$url,'description'=>$description];
  if($key==='governo')foreach(['organizations_enabled'=>['organizzazioni','organizzazione','adminorganizzazione','Organizzazioni'],'corporations_enabled'=>['corporazioni','corporazione','admincorporazione','Corporazioni']] as $setting=>[$scope,$idKey,$flag,$label])if(alphano_config($setting)&&$allowed([$flag]))$entries[]=['label'=>$label,'url'=>'/governo/?scope='.$scope.(!$admin&&isset($user[$idKey])?'&id='.(int)$user[$idKey]:''),'description'=>'Tesoreria e amministrazione'];
  if($entries)$out[$key]=['title'=>$title,'icon'=>$icon,'items'=>$entries];
 }
 return $out;
}
function alphano_management_document(string $html):string{
 $path=parse_url($_SERVER['SCRIPT_NAME']??'',PHP_URL_PATH)??'';$scope=alphano_management_scope($path);
 if($scope===null||http_response_code()>=400||str_contains($html,'data-management-shell'))return $html;
 $user=alphano_user();if(!$user)return $html;$groups=alphano_management_catalog($user);if(!$groups)return $html;
 $current=static function(string $url)use($path):bool{$parts=parse_url($url);$target=$parts['path'];$normalize=static fn($p)=>rtrim(preg_replace('~/index\.php$~','/',$p),'/');if($normalize($target)!==$normalize($path))return false;parse_str($parts['query']??'',$query);foreach($query as $key=>$v)if(($_GET[$key]??null)!==$v)return false;return true;};
 $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$rail='<aside class="management-rail" id="management-navigation" aria-label="Navigazione amministrativa"><div class="management-rail-heading"><small>AREA DI LAVORO</small><strong>Strumenti e sezioni</strong></div><label class="management-search">Cerca uno strumento<input type="search" placeholder="Nome o funzione…" data-management-search autocomplete="off"></label><section class="management-local" data-management-local hidden><h2>In questa pagina</h2><div data-management-local-items></div></section><nav aria-label="Pannelli di gestione">';
 foreach($groups as $key=>$group){$open=$key===$scope||($scope==='master'&&$key==='ia'&&str_contains($path,'master-'));$rail.='<details class="management-group" data-default-open="'.($open?'1':'0').'"'.($open?' open':'').'><summary><img src="/assets/default-nav-'.$h($group['icon']).'.svg" alt="" width="26" height="26"><span>'.$h($group['title']).'</span></summary><div>';
 foreach($group['items'] as $item)$rail.='<a class="management-destination" href="'.$h($item['url']).'"'.($current($item['url'])?' aria-current="page"':'').'><strong>'.$h($item['label']).'</strong><small>'.$h($item['description']).'</small></a>';
 $rail.='</div></details>';}
 $rail.='</nav><p class="management-search-empty" data-management-empty hidden>Nessuna sezione corrisponde alla ricerca.</p><a class="management-return" href="/main.php">← Torna al gioco</a></aside>';
 $html=preg_replace('/<body\b/i','<body data-management-shell="'.$h($scope).'"',$html,1);
 $html=preg_replace('/(<body\b[^>]*>)/i','$1<a class="management-skip" href="#management-content">Vai al contenuto</a>'.$rail,$html,1);
 $script='<script src="/assets/management.js?v='.filemtime(dirname(__DIR__).'/assets/management.js').'" defer></script>';
 return preg_replace('~</head>~i',$script.'</head>',$html,1);
}
