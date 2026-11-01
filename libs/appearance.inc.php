<?php
declare(strict_types=1);
require_once __DIR__.'/engine-settings.inc.php';
require_once __DIR__.'/default-art.inc.php';

/** Presentation settings contain no credentials and never change module permissions. */
function alphano_appearance(): array {
    static $settings;
    if ($settings !== null) return $settings;
    $file=dirname(__DIR__).'/private/appearance.json';
    $settings=is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];
    return $settings;
}
function alphano_appearance_revision(): string {
    $file=dirname(__DIR__).'/private/appearance.json';
    return is_file($file)?hash_file('sha256',$file):hash('sha256','');
}
function alphano_appearance_page_key(string $url): string {
    $parts=parse_url($url);$path=$parts['path']??'/';
    $path=match($path){'/api/mappa.php'=>'/mappa.php','/profili/modifyprofile.php'=>'/profili/modifyuser.php',default=>$path};
    $path=preg_replace('~/index\.php$~','/',$path);
    $key=trim(preg_replace('~[^a-z0-9]+~i','-',preg_replace('~\.php$~','',$path)),'-')?:'homepage';
    parse_str($parts['query']??'',$q);
    if($key==='legal'&&in_array($q['type']??'', ['privacy','cookies','terms','ai','credits'],true))$key.='-'.$q['type'];
    if($key==='sessione-viewlog'&&in_array($q['azione']??'', ['pareri','aprilog'],true))$key.='-'.$q['azione'];
    if($key==='governo'&&in_array($q['scope']??'', ['citta','organizzazioni','corporazioni'],true))$key.='-'.$q['scope'];
    if($key==='discussioni'&&is_string($q['tipo']??null))$key.='-'.preg_replace('~\d+~','',$q['tipo']);
    if($key==='notifiche'&&is_string($q['group']??null)&&preg_match('/^[a-z]+$/D',$q['group']))$key.='-'.$q['group'];
    foreach(['chat'=>['chat'],'mappa'=>['area'],'razze'=>['id'],'citta'=>['id']] as $module=>$fields)if($key===$module)foreach($fields as $field)if(isset($q[$field])&&is_string($q[$field])&&preg_match('~^[a-zA-Z0-9_-]{1,80}$~D',$q[$field]))$key.='-'.$q[$field];
    return $key;
}
function alphano_appearance_item_key(string $label,string $group): string {
    return $group.'-'.substr(hash('sha256',$label),0,16);
}
function alphano_appearance_image(mixed $url): string {
    if(!is_string($url)||strlen($url)>300)throw new DomainException('Immagine non valida.');
    $url=trim($url);if($url==='')return '';
    if($url==='/assets/alphanogdr-logo.png')return $url;
    if(alphano_is_default_art($url)&&is_file(dirname(__DIR__).$url))return $url;
    if(!preg_match('~^/(?:uploads/[a-zA-Z0-9_/-]+\.(?:png|jpe?g|webp)|assets/[a-zA-Z0-9_-]+\.svg)$~D',$url)||str_contains($url,'..')||!is_file(dirname(__DIR__).$url))throw new DomainException('Carica un’immagine oppure indica il percorso di un’immagine già presente in uploads.');
    return $url;
}
function alphano_appearance_upload(array $file): string {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('Caricamento non riuscito.');
    if(($file['size']??0)>5*1024*1024)throw new DomainException('Ogni immagine può occupare al massimo 5 MB.');
    $info=@getimagesize($file['tmp_name']);$types=[IMAGETYPE_PNG=>'png',IMAGETYPE_JPEG=>'jpg',IMAGETYPE_WEBP=>'webp'];
    if(!$info||!isset($types[$info[2]])||$info[0]>6000||$info[1]>6000||$info[0]*$info[1]>16000000)throw new DomainException('Usa PNG, JPEG o WebP, massimo 6000 pixel per lato e 16 milioni di pixel.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if($mime!==image_type_to_mime_type($info[2]))throw new DomainException('Il contenuto non corrisponde al formato dell’immagine.');
    $dir=dirname(__DIR__).'/uploads/appearance';if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Cartella immagini non scrivibile.');
    $path='/uploads/appearance/'.bin2hex(random_bytes(16)).'.'.$types[$info[2]];
    // Fixed bitmap extensions, nosniff and the uploads execution ban also protect hosts without GD.
    if(!move_uploaded_file($file['tmp_name'],dirname(__DIR__).$path))throw new RuntimeException('Impossibile salvare l’immagine.');
    chmod(dirname(__DIR__).$path,0644);return $path;
}
function alphano_appearance_public_manuals(): array {
    require_once __DIR__.'/manuals.inc.php';
    return array_values(array_filter(ConnectDB()->query('SELECT id,oggetto,summary,manual_key,audience FROM regole ORDER BY sezione,pos,id'),static fn($r)=>alphano_manual_can_read($r,alphano_manual_guest())));
}
function alphano_appearance_catalog(): array {
    $catalog=json_decode(file_get_contents(dirname(__DIR__).'/config/appearance-pages.json'),true,32,JSON_THROW_ON_ERROR);
    $out=[];foreach($catalog as [$group,$label,$url,$menu]){
        $key=alphano_appearance_page_key($url);
        $out[$key]=['key'=>$key,'group'=>$group,'label'=>$label,'url'=>$url,'menu'=>$menu,'item_key'=>alphano_appearance_item_key($menu?:$label,$group)];
    }
    foreach(ConnectDB()->query('SELECT id,nome FROM razze ORDER BY id') as $r){$key='razze-'.$r['id'];$out[$key]=['key'=>$key,'group'=>'razze','label'=>$r['nome'],'url'=>'/razze/?id='.$r['id'],'menu'=>$r['nome'],'item_key'=>'race-'.$r['id']];}
    foreach(ConnectDB()->query('SELECT id,city FROM city ORDER BY id') as $r){$key='citta-'.$r['id'];$out[$key]=['key'=>$key,'group'=>'citta','label'=>$r['city'],'url'=>'/citta/?id='.$r['id'],'menu'=>$r['city'],'item_key'=>'city-'.$r['id']];}
    foreach(ConnectDB()->query('SELECT id,title FROM world_areas ORDER BY id') as $r){$url='/mappa.php?area='.$r['id'];$key=alphano_appearance_page_key($url);$out[$key]=['key'=>$key,'group'=>'citta','label'=>'Mappa · '.$r['title'],'url'=>$url,'menu'=>'','item_key'=>''];}
    foreach(ConnectDB()->query('SELECT chat_key,title FROM mappe ORDER BY id') as $r){$url='/chat/index.php?chat='.$r['chat_key'];$key=alphano_appearance_page_key($url);$out[$key]=['key'=>$key,'group'=>'chat','label'=>'Chat · '.$r['title'],'url'=>$url,'menu'=>'','item_key'=>''];}
    return $out;
}
function alphano_appearance_menu_catalog(): array {
    $out=[];$raw=json_decode(file_get_contents(dirname(__DIR__).'/config/appearance-pages.json'),true,32,JSON_THROW_ON_ERROR);
    foreach($raw as [$group,$label,$url,$menu])if($menu!==''){$key=alphano_appearance_item_key($menu,$group);$out[$key]=['key'=>$key,'group'=>$group,'label'=>$label,'url'=>$url];}
    foreach(alphano_appearance_catalog() as $page)if(str_starts_with($page['item_key'],'race-')||str_starts_with($page['item_key'],'city-'))$out[$page['item_key']]=['key'=>$page['item_key']]+$page;
    return $out;
}
function alphano_appearance_page(?string $url=null): array {
    $url=$url??($_SERVER['REQUEST_URI']??'/');$key=alphano_appearance_page_key($url);
    $base=alphano_appearance_page_key(parse_url($url,PHP_URL_PATH)?:'/');$a=alphano_appearance();
    $page=array_replace(['background'=>'','header'=>alphano_default_header($url)],array_filter($a['defaults']??[]),array_filter($a['pages'][$base]??[]),array_filter($a['pages'][$key]??[]));
    if($key==='homepage'&&!empty($a['home']['background']))$page['background']=$a['home']['background'];
    return $page;
}
function alphano_appearance_save(array $input,array $files): void {
    $dir=dirname(__DIR__).'/private';$lock=fopen($dir.'/appearance.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Impossibile acquisire la configurazione.');
    $newFiles=[];
    try{
        if(!hash_equals(alphano_appearance_revision(),(string)($input['revision']??'')))throw new DomainException('Le impostazioni sono cambiate in un’altra pagina. Ricarica prima di salvare.');
        $file=$dir.'/appearance.json';$a=is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];
        $section=$input['section']??'';$target=$input['target']??'';$catalog=alphano_appearance_catalog();$groups=require dirname(__DIR__).'/config/navigation.php';
        if(!is_string($target))throw new DomainException('Voce non valida.');
        $valid=match($section){'defaults','home'=>$target==='','groups'=>isset($groups[$target]),'items'=>isset(alphano_appearance_menu_catalog()[$target]),'pages'=>isset($catalog[$target]),default=>false};
        if(!$valid)throw new DomainException('Seleziona una voce del catalogo.');
        $fields=match($section){'home'=>['background','banner','logo','scene_library'],'groups','items'=>['image'],default=>['background','header']};
        $values=[];
        foreach($fields as $field){
            $preset=$input['preset_'.$field]??'';
            if(!is_string($preset)||($preset!==''&&!alphano_is_default_art($preset)))throw new DomainException('Immagine predefinita non valida.');
            if(isset($files[$field])&&($files[$field]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$values[$field]=alphano_appearance_upload($files[$field]);$newFiles[]=$values[$field];}
            else $values[$field]=!empty($input['remove_'.$field])?'':alphano_appearance_image($preset!==''?$preset:($input[$field]??''));
        }
        if($section==='home'){
            $headline=$input['headline']??'';$intro=$input['intro']??'';
            if(!is_string($headline)||!is_string($intro)||mb_strlen($headline)>160||mb_strlen($intro)>2000)throw new DomainException('Titolo o presentazione troppo lunghi.');
            $values+=['headline'=>trim($headline),'intro'=>trim($intro)];
            $ids=$input['manuals']??[];if(!is_array($ids)||count($ids)>20)throw new DomainException('Puoi fissare fino a 20 manuali.');
            $public=array_column(alphano_appearance_public_manuals(),'id');$values['manuals']=[];
            foreach($ids as $id){if(!is_scalar($id)||!ctype_digit((string)$id)||!in_array((int)$id,array_map('intval',$public),true))throw new DomainException('Puoi fissare soltanto manuali pubblici.');$values['manuals'][]=(int)$id;}
            $values['manuals']=array_values(array_unique($values['manuals']));
        }
        if(in_array($section,['defaults','home'],true))$a[$section]=$values;else $a[$section][$target]=$values;
        $temp=tempnam($dir,'appearance-');try{chmod($temp,0600);if(file_put_contents($temp,json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false||!rename($temp,$file))throw new RuntimeException('Salvataggio non riuscito.');}finally{if(is_file($temp))unlink($temp);}
    }catch(Throwable $e){foreach($newFiles as $path)if(is_file(dirname(__DIR__).$path))unlink(dirname(__DIR__).$path);throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** Shared brand for the public portal and every management page. */
function alphano_brand_logo():string{
 return (alphano_appearance()['home']['logo']??'')?:'/assets/alphanogdr-logo.png';
}
function alphano_management_brand(string $html):string{
 // The administrative workspace intentionally has no logo/quick-links top bar.
 return $html;
}
