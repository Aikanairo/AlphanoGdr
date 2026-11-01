<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/manuals.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libtext.inc.php';
$manualUser=alphano_manual_user(alphano_require_login());
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
if (!is_file(__DIR__ . '/../vendor/autoload.php')) alphano_fail('Generazione PDF non disponibile: installa le dipendenze Composer.',503);
require_once __DIR__ . '/../vendor/autoload.php';

$db=getDB();
$visible=alphano_manual_data($manualUser,true);$groups=$visible['groups'];$rules=$visible['rules'];
$chapter=$_GET['chapter']??'';
if(!is_string($chapter))alphano_fail('Capitolo non disponibile.',404);
if($chapter!==''){
    try{$rule=alphano_manual_find($chapter,0,$manualUser);$rules=[alphano_manual_payload($rule,$manualUser)];}
    catch(DomainException $e){alphano_fail('Capitolo non disponibile.',404);}
    $groups=array_values(array_filter($groups,static fn($g)=>(int)$g['id']===(int)$rule['sezione']));
}
// Hash the whole manual: deletion and changes in the same second invalidate the cache too.
$key=hash('sha256',json_encode([$groups,$rules,'dompdf-manuals-v4',hash_file('sha256',__DIR__.'/../css/theme/print.css')],JSON_INVALID_UTF8_SUBSTITUTE));
$storage=(string)alphano_config('storage_root');
$directory=dirname(__DIR__).'/private/pdf';
if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cache PDF non disponibile');
$path=$directory.'/manuale-'.$key.'.pdf';
$lock=fopen($directory.'/manuale.lock','c');
if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Generazione PDF occupata');
try {
    if (!is_file($path)) {
        $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="it"><meta charset="utf-8"><style>'.file_get_contents(__DIR__.'/../css/theme/print.css').'</style><body><h1>Manuale di Alphano</h1><h2 id="indice">Indice</h2>';
        $sections=[];
        foreach ($groups as $group) {
            $html.='<h3>'.$escape($group['nome']).'</h3><ul>';
            foreach ($rules as $rule) if ((int)$rule['sezione']===(int)$group['id']) {
                $html.='<li><a href="#regola-'.$rule['id'].'">'.$escape($rule['oggetto']).'</a></li>';
                $sections[]=$rule;
            }
            $html.='</ul>';
        }
        foreach ($sections as $rule) {
            $content=$rule['spiegazione'];
            // Only local administrator-supplied media can enter the PDF.
            $content=preg_replace_callback('~<img\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27][^>]*>~i',static function($match) use ($escape) {
                $src=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
                if(str_ends_with(strtolower($src),'.webp')&&!function_exists('imagecreatefromwebp'))return '';
                $source=realpath(dirname(__DIR__).'/'.ltrim($src,'/'));
                if(!$source)$source=realpath(htmlroot.'/'.ltrim($src,'/'));
                if (str_starts_with($src,'/') && $source && (str_starts_with($source,dirname(__DIR__).'/uploads/')||str_starts_with($source,dirname(__DIR__).'/uploads/equipment/')||str_starts_with($source,dirname(__DIR__).'/profili/pg/')) && is_file($source)) {
                    return str_replace($match[1],$escape('file://'.$source),$match[0]);
                }
                return '';
            },$content);
            $html.='<section class="chapter"><h2 id="regola-'.$rule['id'].'">'.$escape($rule['oggetto']).'</h2>'.$content.'<p><a href="#indice">Torna all’indice</a></p></section>';
        }
        $html.='</body></html>';
        // DejaVu supports the text repertoire; decorative supplementary emoji do not have PDF glyphs.
        $html=preg_replace('/[\x{1F000}-\x{1FAFF}\x{2700}-\x{27BF}\x{FE0E}\x{FE0F}\x{200D}]/u','',$html);
        $html=preg_replace_callback('/&#(?:x([0-9a-f]+)|([0-9]+));/i',static fn($m)=>((isset($m[1]) && $m[1]!=='' ? hexdec($m[1]) : (int)$m[2])>0xFFFF) ? '' : $m[0],$html);
        $options=new \Dompdf\Options();
        $options->set('defaultFont','DejaVu Sans');
        $options->set('isRemoteEnabled',false);
        $options->set('isPhpEnabled',false);
        $options->set('isJavascriptEnabled',false);
        $options->set('chroot',[dirname(__DIR__),htmlroot]);
        $options->set('tempDir',$directory);
        $options->set('fontCache',$directory);
        $pdf=new \Dompdf\Dompdf($options);
        $pdf->setPaper('A4'); $pdf->loadHtml($html,'UTF-8'); $pdf->render();
        $pdf->getCanvas()->page_text(270,815,'{PAGE_NUM} / {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('DejaVu Sans','normal'),8,[0.4,0.3,0.2]);
        $pdf->getCanvas()->get_cpdf()->addInfo('Title','Manuale di Alphano');
        $temporary=tempnam($directory,'manuale-');
        if (file_put_contents($temporary,$pdf->output())===false || !rename($temporary,$path)) throw new RuntimeException('Salvataggio PDF non riuscito');
        foreach (glob($directory.'/manuale-*.pdf') ?: [] as $old) if ($old!==$path && filemtime($old)<time()-86400) unlink($old);
    }
} finally { flock($lock,LOCK_UN); fclose($lock); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="manuale-alphano.pdf"');
header('Cache-Control: private, no-cache');
header('Content-Length: '.filesize($path));
readfile($path);
