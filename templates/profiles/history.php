<?php
/** Only controller-authorized, already formatted fields enter the narrative cards. */
$historyCard=static function(array $row,bool $wide=false)use($h):void {
 $story=$row['label']==='Storia';
?>
<article class="rpg-history-card<?=$story?' rpg-history-biography':($wide?' rpg-history-wide':'')?>"<?=$story?' data-profile-biography':''?>>
 <header><img class="rpg-chapter-symbol" src="<?=$h(alphano_profile_chapter_symbol($row['label']))?>" alt="" width="56" height="56"><div><small class="rpg-eyebrow">Il diario del personaggio</small><h2><?=$h($row['label'])?></h2></div></header>
 <div class="rpg-history-content"><?=$row['html']?></div>
</article>
<?php };
$storyRows=array_values(array_filter($s['rows'],static fn(array $row):bool=>$row['label']==='Storia'));
$otherRows=array_values(array_filter($s['rows'],static fn(array $row):bool=>$row['label']!=='Storia'));
?>
<div class="rpg-history-cards">
 <?php foreach($storyRows?:[['label'=>'Storia','html'=>'<p>La storia di questo personaggio non è ancora stata rivelata.</p>']] as $row)$historyCard($row); ?>
 <?php foreach($otherRows as $row)$historyCard($row,count($otherRows)===1); ?>
 <?php foreach($s['children']??[] as $child): ?>
  <?php if($child['id']==='relazioni'): ?><section class="rpg-history-related" id="<?=$h($child['id'])?>" aria-label="<?=$h($child['title'])?>"><?php foreach($child['rows'] as $row)$historyCard($row); ?></section>
  <?php else: ?><section class="rpg-history-card rpg-history-record"><details class="rpg-profile-disclosure" id="<?=$h($child['id'])?>"><summary><img class="rpg-chapter-symbol" src="<?=$h(alphano_profile_chapter_symbol($child['title']))?>" alt="" width="48" height="48"><?=$h($child['title'])?></summary><?php if(($child['template']??'')==='npc-relationship'): require __DIR__.'/npc-relationship.php'; else: $fields($child['rows']); endif; ?></details></section><?php endif; ?>
 <?php endforeach; ?>
</div>
