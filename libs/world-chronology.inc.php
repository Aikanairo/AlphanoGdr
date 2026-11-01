<?php
declare(strict_types=1);

/** Narrative dates never replace real timestamps used for accounts, combat or deadlines. */
function alphano_world_calendar(?int $at=null):array {
    static $config;$config??=json_decode(file_get_contents(__DIR__.'/../config/world-chronology.json'),true,32,JSON_THROW_ON_ERROR);
    $zone=new DateTimeZone($config['timezone']);
    $real=(new DateTimeImmutable('@'.($at??time())))->setTimezone($zone)->setTime(0,0);
    $anchor=new DateTimeImmutable($config['real_anchor'],$zone);
    $days=(int)$anchor->diff($real)->format('%r%a');
    $story=(new DateTimeImmutable($config['story_anchor'],$zone))->modify(sprintf('%+d days',$days));
    $months=[1=>'gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'];
    return ['date'=>$story->format('Y-m-d'),'year'=>(int)$story->format('Y'),'label'=>(int)$story->format('j').' '.$months[(int)$story->format('n')].' '.$story->format('Y'),'anchor'=>$config['story_anchor'],'version'=>$config['version'],'moon_convention'=>$config['moon_convention'],'public_context'=>$config['public_context'],'technology_context'=>$config['technology_context']];
}
function alphano_world_prompt_context(?int $at=null):string {
    $c=alphano_world_calendar($at);
    return "\nCALENDARIO ON: ".$c['label'].'. '.$c['public_context'].' '.$c['technology_context'].'. Usa soltanto i fatti verificati della scena e non divulgare informazioni riservate.';
}
