<?php $sh=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$filterFields=[
 'online'=>['Presenza',['Si'=>'Online','No'=>'Offline']],
 'razza'=>['Razza',$mapRaz], 'sesso'=>['Sesso',['Maschio'=>'Maschio','Femmina'=>'Femmina']],
 'city'=>['Città',$mapCity], 'luogo'=>['Posizione',array_combine($posizioni,$posizioni)?:[]],
 'corporazione'=>['Corporazione',array_column($listaCorp,'nome','id')], 'organizzazione'=>['Organizzazione',array_column($listaOrg,'nome','id')],
 'etnia'=>['Etnia',$mapEtn],
 'al'=>['Allineamento',['LB'=>'Legale Buono','NB'=>'Neutrale Buono','CB'=>'Caotico Buono','LN'=>'Legale Neutrale','NN'=>'Neutrale','CN'=>'Caotico Neutrale','LM'=>'Legale Malvagio','NM'=>'Neutrale Malvagio','CM'=>'Caotico Malvagio']],
 'categoria'=>['Incarico',array_combine(array_keys($categoriaMap),array_keys($categoriaMap))]
]; ?>
<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ricerca dei Personaggi <?=TitoloSito()?></title>
<script src="/js/global.js"></script>
<script type="application/json" id="search-data"><?=json_encode($ricerca,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?></script><script src="/js/search.js?v=<?=filemtime(dirname(__DIR__,2).'/js/search.js')?>" defer></script></head>
<body onload="Carica()">
<form class="search-filters" method="get" action="/ricerca/" aria-label="Filtri ricerca personaggi">
 <label class="search-filter">Nome<input type="search" name="nome" value="<?=$sh($nome!=='NULL'?$nome:'')?>" placeholder="Nome o iniziale" autocomplete="off"></label>
 <?php foreach($filterFields as $field=>[$label,$choices]): ?><label class="search-filter"><?=$sh($label)?><select name="<?=$field?>"><option value="">Tutti</option><?php foreach($choices as $value=>$label): ?><option value="<?=$sh($value)?>"<?=(string)$$field===(string)$value?' selected':''?>><?=$sh($label)?></option><?php endforeach; ?></select></label><?php endforeach; ?>
 <label class="search-filter">Carica cittadina<select name="araldica"><option value="">Tutte</option><?php foreach($listaaraldiche as $cityId=>$list): ?><optgroup label="<?=$sh($mapCity[$cityId]??'Nessuna città')?>"><?php foreach($list as $role): ?><option value="<?=(int)$role['id']?>"<?=(string)$araldica===(string)$role['id']?' selected':''?>><?=$sh($role['nome'])?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
 <div class="search-actions"><button type="submit" data-icon="search">Cerca personaggi</button><a class="action-btn" href="/ricerca/" data-icon="back">Azzera filtri</a></div>
</form>
<nav class="search-alphabet" aria-label="Iniziale del nome"><?php foreach(range('a','z') as $letter):$letterQuery=$_GET;$letterQuery['nome']=$letter; ?><a href="/ricerca/?<?=$sh(http_build_query($letterQuery))?>"<?=strtolower($nome)===$letter?' aria-current="true"':''?>><?=strtoupper($letter)?></a><?php endforeach; ?><a href="/ricerca/">Tutti</a></nav>
<section id="risultato" class="search-results" aria-label="Risultati della ricerca" aria-live="polite"></section>
</body></html><?php ClosePage(); ?>
