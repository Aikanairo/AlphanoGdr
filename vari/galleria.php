<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/kernel.inc.php';
require_once __DIR__.'/../libs/libpg.inc.php';
alphano_require_login();
$root=realpath(__DIR__.'/galleria');
$requested=(string)($_POST['dir'] ?? $_GET['dir'] ?? './galleria');
if (str_starts_with($requested,'./galleria')) $requested=substr($requested,10);
$directory=$root ? realpath($root.'/'.ltrim($requested,'/')) : false;
if (!$directory || ($directory!==$root && !str_starts_with($directory,$root.DIRECTORY_SEPARATOR))) $directory=$root;
$entries=[];
if ($directory && is_readable($directory)) {
    if ($directory!==$root) $entries[]=['name'=>'..','directory'=>true,'path'=>dirname($directory)];
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot() || $entry->isLink()) continue;
        $entries[]=['name'=>$entry->getFilename(),'directory'=>$entry->isDir(),'path'=>$entry->getPathname()];
    }
    usort($entries,static fn($a,$b)=>($b['directory']<=>$a['directory']) ?: strnatcasecmp($a['name'],$b['name']));
}
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Galleria Immagini – <?=$escape(TitoloSito)?></title>
<body><h1>Galleria immagini</h1>
<?php if (!$entries): ?><p>La galleria non contiene immagini.</p><?php endif; ?>
<div class="u-aabb9be3a">
<?php foreach ($entries as $entry): $relative=substr($entry['path'],strlen($root)); $url='/vari/galleria'.implode('/',array_map('rawurlencode',explode('/',$relative))); ?>
<?php if ($entry['directory']): ?>
<form method="post"><input type="hidden" name="dir" value="<?=$escape($relative)?>"><button type="submit">📁 <?=$escape($entry['name'])?></button></form>
<?php else: $info=@getimagesize($entry['path']); if (!$info) continue; ?>
<a href="<?=$escape($url)?>" target="_blank" rel="noopener"><img src="<?=$escape($url)?>" alt="<?=$escape($entry['name'])?>" class="u-34e197701"><br><?=$escape($entry['name'])?></a>
<?php endif; endforeach; ?></div></body></html>
