<?php
/** @var array $electionSummary Public, read-only summary prepared by the controller. */
$phase=$electionSummary['phase'];
$phaseLabel=['candidacy'=>'Candidature aperte','voting'=>'Votazioni in corso','runoff'=>'Ballottaggio in corso','waiting'=>'Nessuna elezione in corso','unavailable'=>'Registro in preparazione'][$phase]??'Registro in preparazione';
$actionLabel=['candidacy'=>'Candidature e programmi','voting'=>'Vai alle votazioni','runoff'=>'Vai al ballottaggio'][$phase]??'Elezioni e candidature';
?>
<section class="city-election-preview<?=$electionSummary['active']?' is-active':''?>" data-city-election="<?=(int)$electionSummary['city_id']?>" data-election-state="<?=$h(json_encode($electionSummary,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>" aria-label="Situazione elettorale">
 <h3><span class="city-election-dot" aria-hidden="true"></span><span data-city-election-phase><?=$h($phaseLabel)?></span></h3>
 <p data-city-election-candidates<?=$electionSummary['active']?'':' hidden'?>><?=(int)$electionSummary['candidates']?> <?=$electionSummary['candidates']===1?'candidatura':'candidature'?></p>
 <p data-city-election-date<?=$electionSummary['deadline']?'':' hidden'?>>Scadenza: <time datetime="<?=$electionSummary['deadline']?gmdate('c',$electionSummary['deadline']):''?>"><?=$h($electionSummary['deadline_label'])?></time></p>
 <strong data-city-election-countdown></strong>
 <a class="action-btn" data-icon="check" data-city-election-link href="<?=$h($electionSummary['url'])?>"><span data-election-action-label><?=$h($actionLabel)?></span></a>
</section>
