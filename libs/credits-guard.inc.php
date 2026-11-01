<?php
declare(strict_types=1);
// This hash authenticates repair fragments only. It never locks authored source files.
const ALPHANO_CREDIT_BUNDLE_HASH = '23cedc0846f8f9a2a84399fbc822ae6eec6708d2b600c0eac68061d1fca35603';
function alphano_credits_bundle(): array
{
    $file=dirname(__DIR__).'/config/credit-originals.json';
    if(!is_file($file)||!hash_equals(ALPHANO_CREDIT_BUNDLE_HASH,hash_file('sha256',$file)))throw new DomainException('La copia di ripristino dei crediti è mancante o alterata: ricarica il pacchetto originale.');
    return json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR)['fragments'];
}
function alphano_credit_page(): ?string
{
    return match(parse_url($_SERVER['SCRIPT_NAME']??'',PHP_URL_PATH)){
        '/', '/index.php'=>'home','/equip/equip.php'=>'equipment','/legal/index.php'=>'legal',default=>null
    };
}
function alphano_credit_state(string $name): array
{
    $file=dirname(__DIR__).'/private/credits-'.$name.'.json';
    $value=is_file($file)?json_decode((string)file_get_contents($file),true):[];
    return is_array($value)?$value:[];
}
function alphano_credit_write(string $name,array $data): void
{
    $dir=dirname(__DIR__).'/private';$temp=tempnam($dir,'credits-');
    try{chmod($temp,0600);if(file_put_contents($temp,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($temp,$dir.'/credits-'.$name.'.json'))throw new RuntimeException('Impossibile salvare lo stato dei crediti.');}
    finally{if(is_file($temp))unlink($temp);}
}
function alphano_credits_issues(): array
{
    $root=dirname(__DIR__);$issues=[];
    foreach(alphano_credit_state('health') as $page=>$entry){
        if(!in_array($page,['home','equipment','legal'],true)||!is_array($entry['dependencies']??null))continue;
        // A source edit invalidates an old observation, instead of being a violation.
        foreach($entry['dependencies'] as $path=>$hash){
            if(str_contains($path,'..')||str_starts_with($path,'/')||(is_file($root.'/'.$path)?hash_file('sha256',$root.'/'.$path):null)!==$hash)continue 2;
        }
        $issues[]=$page;
    }
    return $issues;
}
function alphano_credit_block_html(): string
{
    require_once __DIR__.'/status-page.inc.php';
    return alphano_status_document('Accesso sospeso: ripristina i crediti','Il controllo incluso nel pacchetto non rileva il credito predefinito nella pagina generata. Correggi il credito o usa il ripristino, che conserva le personalizzazioni. Puoi anche modificare o rimuovere questo controllo secondo la AGPL: il blocco tecnico non significa che tu abbia violato la licenza.','/credits/restore.php','Ripristina i crediti');
}
function alphano_credits_guard(): void
{
    $path=parse_url($_SERVER['SCRIPT_NAME']??'',PHP_URL_PATH);
    // Credit-bearing pages must remain reachable to revalidate a corrected layout.
    if(alphano_credit_page()!==null||in_array($path,['/credits/restore.php','/checkuser.php'],true)||!alphano_credits_issues())return;
    if(PHP_SAPI==='cli')throw new DomainException('Credito non visibile: visitare la pagina corretta o usare il ripristino.');
    http_response_code(503);header('Cache-Control: no-store');
    if(str_starts_with($_SERVER['REQUEST_URI']??'','/api/')||str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')||str_contains($_SERVER['CONTENT_TYPE']??'','application/json')){
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'Credito predefinito non rilevato dal controllo locale. Controllo e link sono modificabili o rimovibili secondo la AGPL; questo non è un accertamento di violazione.','code'=>'credits_integrity','recovery_url'=>'/credits/restore.php'],JSON_UNESCAPED_UNICODE);exit;
    }
    echo alphano_credit_block_html();exit;
}
/** Basic authored CSS only: unknown selectors/media rules do not cause a block. */
function alphano_credit_css(DOMDocument $dom,array &$dependencies): array
{
    $root=dirname(__DIR__);$sources=[];$rules=[];$order=0;
    foreach((new DOMXPath($dom))->query('//style|//link') as $link){
        if(strtolower($link->tagName)==='style'){$sources[]=$link->textContent;continue;}
        if(strtolower($link->getAttribute('rel'))!=='stylesheet')continue;
        $url=parse_url(html_entity_decode($link->getAttribute('href'),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if(!$url||isset($url['host'])&&strcasecmp($url['host'],parse_url('https://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)??''))continue;
        $path=$url['path']??'';$files=$path==='/assets/theme.php'&&function_exists('alphano_theme_files')?alphano_theme_files():[$root.$path];
        foreach($files as $file){$real=realpath($file);if(!$real||!str_starts_with($real,$root.'/')||pathinfo($real,PATHINFO_EXTENSION)!=='css'||filesize($real)>524288)continue;
            $dependencies[substr($real,strlen($root)+1)]=hash_file('sha256',$real);$sources[]=file_get_contents($real);
        }
    }
    foreach($sources as $css){
        $css=preg_replace('~/\*.*?\*/~s','',$css);$offset=0;
        while(($open=strpos($css,'{',$offset))!==false){
            $selector=trim(substr($css,$offset,$open-$offset));$depth=1;$end=$open+1;
            for(;$end<strlen($css)&&$depth;$end++){if($css[$end]==='{')$depth++;elseif($css[$end]==='}')$depth--;}
            $body=substr($css,$open+1,$end-$open-2);$offset=$end;
            if($selector===''||str_starts_with($selector,'@')||str_contains($body,'{'))continue;
            $declarations=alphano_credit_declarations($body);if(!$declarations)continue;
            foreach(explode(',',$selector) as $part){$part=trim($part);if(!preg_match('/^[a-zA-Z0-9_.#*\s>\-]+$/D',$part))continue;
                $specificity=100*substr_count($part,'#')+10*substr_count($part,'.')+preg_match_all('/(?:^|\s|>)[a-zA-Z]/',$part);
                $rules[]=[$part,$declarations,$specificity,++$order];
            }
        }
    }
    return $rules;
}
function alphano_credit_declarations(string $css): array
{
    $out=[];
    foreach(explode(';',$css) as $part){$pair=explode(':',$part,2);if(count($pair)!==2)continue;[$name,$value]=array_map('trim',$pair);$name=strtolower($name);
        if(!in_array($name,['display','visibility','opacity','font-size','font','color','content-visibility'],true))continue;
        $important=(bool)preg_match('/!\s*important\s*$/i',$value);$value=strtolower(trim(preg_replace('/!\s*important\s*$/i','',$value)));
        if($name==='font'){$name='font-size';$value=preg_match('/(?:^|\s)([0-9.]+(?:px|pt|em|rem|%)?)(?:\/[^\s]+)?(?:\s|$)/',$value,$size)?$size[1]:'initial';}
        if(!empty($out[$name][1])&&!$important)continue;$out[$name]=[$value,$important];
    }
    return $out;
}
function alphano_credit_selector(DOMElement $node,string $selector): bool
{
    $parts=preg_split('/\s*(>)\s*|\s+/',trim($selector),-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    $matches=static function(DOMElement $element,string $part):bool{
        if(!preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*|\*)?((?:[.#][a-zA-Z0-9_-]+)*)$/D',$part,$m))return false;
        if(($m[1]??'')!==''&&$m[1]!=='*'&&strcasecmp($element->tagName,$m[1]))return false;
        preg_match_all('/([.#])([a-zA-Z0-9_-]+)/',$m[2]??'',$bits,PREG_SET_ORDER);
        foreach($bits as $bit)if($bit[1]==='#'?$element->getAttribute('id')!==$bit[2]:!in_array($bit[2],preg_split('/\s+/',trim($element->getAttribute('class'))),true))return false;
        return true;
    };
    if(!$parts||!$matches($node,array_pop($parts)))return false;
    while($parts){$direct=end($parts)==='>';if($direct)array_pop($parts);if(!$parts)return false;$part=array_pop($parts);$node=$node->parentNode;
        if($direct){if(!$node instanceof DOMElement||!$matches($node,$part))return false;}
        else{while($node instanceof DOMElement&&!$matches($node,$part))$node=$node->parentNode;if(!$node instanceof DOMElement)return false;}
    }
    return true;
}
function alphano_credit_visible(DOMElement $node,array $rules,array &$cache): bool
{
    $path=$node->getNodePath();if(isset($cache[$path]))return $cache[$path];$ancestors=[];
    for($p=$node;$p instanceof DOMElement;$p=$p->parentNode)$ancestors[]=$p;
    $inherited=[];
    foreach(array_reverse($ancestors) as $p){
        if(in_array(strtolower($p->tagName),['head','script','style','template','noscript','svg'],true)||$p->hasAttribute('hidden'))return $cache[$path]=false;
        $properties=$cache['_styles'][$p->getNodePath()]??null;
        if($properties===null){
        $properties=[];$rank=[];
        foreach($rules as [$selector,$declarations,$specificity,$order])if(alphano_credit_selector($p,$selector))foreach($declarations as $key=>[$value,$important]){
            $candidate=[$important?1:0,$specificity,$order];if(!isset($rank[$key])||$candidate>=$rank[$key]){$properties[$key]=$value;$rank[$key]=$candidate;}
        }
        foreach(alphano_credit_declarations($p->getAttribute('style')) as $key=>[$value,$important]){
            $candidate=[$important?1:0,1000,PHP_INT_MAX];if(!isset($rank[$key])||$candidate>=$rank[$key])$properties[$key]=$value;
        }
        $cache['_styles'][$p->getNodePath()]=$properties;
        }
        if(($properties['display']??'')==='none'||($properties['content-visibility']??'')==='hidden'||isset($properties['opacity'])&&is_numeric($properties['opacity'])&&(float)$properties['opacity']<=0)return $cache[$path]=false;
        foreach(['visibility','font-size','color'] as $key)if(isset($properties[$key])&&!in_array($properties[$key],['inherit','unset'],true))$inherited[$key]=$properties[$key];
    }
    return $cache[$path]=!in_array($inherited['visibility']??'',['hidden','collapse'],true)&&!preg_match('/^0(?:\.0+)?(?:px|pt|em|rem|%)?$/',$inherited['font-size']??'')&&($inherited['color']??'')!=='transparent'&&!preg_match('/^#[a-f0-9]{6}00$/',$inherited['color']??'');
}
function alphano_credit_html_valid(string $html,string $page,array &$dependencies=[]): bool
{
    $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $rules=alphano_credit_css($dom,$dependencies);$cache=[];
    $read=static function(DOMNode $node)use(&$read,&$cache,$rules):string{
        if($node instanceof DOMText)return $node->parentNode instanceof DOMElement&&alphano_credit_visible($node->parentNode,$rules,$cache)?$node->textContent:'';
        $pieces=[];foreach($node->childNodes as $child)$pieces[]=$read($child);return implode(' ',$pieces);
    };
    $text=mb_strtolower(trim(preg_replace('/\s+/u',' ',$read($dom))));
    if(!str_contains($text,'alfano gaetano')||($page==='equipment'&&!str_contains($text,'grafica ispirata da')))return false;
    foreach($dom->getElementsByTagName('a') as $a){
        $url=parse_url(trim($a->getAttribute('href')));$label=mb_strtolower(preg_replace('/\s+/u','',$read($a)));
        if($url&&in_array(strtolower($url['scheme']??''),['http','https'],true)&&in_array(strtolower($url['host']??''),['gothik.it','www.gothik.it'],true)&&in_array($url['path']??'',['','/'],true)&&empty($url['user'])&&empty($url['pass'])&&str_contains($label,'gothik.it')&&alphano_credit_visible($a,$rules,$cache))return true;
    }
    return false;
}
function alphano_credit_append(string $html,string $fragment): string
{
    $position=strripos($html,'</body>');return $position===false?$html.$fragment:substr_replace($html,$fragment,$position,0);
}
function alphano_credits_filter_html(string $html): string
{
    $page=alphano_credit_page();if($page===null||http_response_code()>=300)return $html;
    $dependencies=[];$valid=alphano_credit_html_valid($html,$page,$dependencies);
    if(!$valid&&!empty(alphano_credit_state('repairs')[$page])){
        try{$fragments=alphano_credits_bundle();$html=alphano_credit_append($html,$fragments[$page==='equipment'?'equipment':'home']);$valid=alphano_credit_html_valid($html,$page,$dependencies);}catch(Throwable){/* A damaged repair copy never invalidates an otherwise valid page. */}
    }
    $state=alphano_credit_state('health');
    if($valid){if(isset($state[$page])){unset($state[$page]);alphano_credit_write('health',$state);}return $html;}
    $root=dirname(__DIR__);
    foreach(get_included_files() as $file)if(str_starts_with($file,$root.'/')&&!str_starts_with($file,$root.'/vendor/')&&!str_starts_with($file,$root.'/private/'))$dependencies[substr($file,strlen($root)+1)]=hash_file('sha256',$file);
    foreach(['config/credits.php','libs/credits.inc.php','templates/credits/home.php','templates/credits/equipment.php'] as $file)$dependencies[$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):null;
    $state[$page]=['at'=>gmdate('c'),'dependencies'=>$dependencies];alphano_credit_write('health',$state);
    http_response_code(503);header('Cache-Control: no-store');return alphano_credit_block_html();
}
/** Enable a central response footer; never replace any customized source page. */
function alphano_credits_restore(): array
{
    alphano_credits_bundle();$issues=alphano_credits_issues();if(!$issues)return [];
    $root=dirname(__DIR__);$lock=fopen($root.'/private/credits-restore.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new DomainException('Ripristino già in corso.');
    try{
        $backup=$root.'/private/credits-recovery-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));if(!mkdir($backup,0700,true))throw new DomainException('Impossibile salvare lo stato precedente.');
        foreach(['health','repairs'] as $name){$path=$root.'/private/credits-'.$name.'.json';if(is_file($path)){if(!copy($path,$backup.'/credits-'.$name.'.json'))throw new DomainException('Copia privata non riuscita.');chmod($backup.'/credits-'.$name.'.json',0600);}}
        $repairs=alphano_credit_state('repairs');$state=alphano_credit_state('health');foreach($issues as $page){$repairs[$page]=true;unset($state[$page]);}
        alphano_credit_write('repairs',$repairs);alphano_credit_write('health',$state);return $issues;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
