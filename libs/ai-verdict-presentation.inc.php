<?php
declare(strict_types=1);
/** The UI consumes only committed effect receipts. It never calculates game outcomes. */
function ai_scene_effect_icon(string $key):string {
    return match($key){'wound'=>'/assets/placeholder.svg','dice'=>'/assets/placeholder.svg','health'=>alphano_profile_symbol('health'),'money'=>alphano_profile_symbol('crowns'),'item','ammunition','reload'=>alphano_profile_symbol('equipment'),'death'=>alphano_profile_symbol('victims'),'xp'=>alphano_profile_symbol('abilities'),default=>alphano_profile_symbol('master-notes')};
}
function ai_verdict_public_html(array $v):string {
    $esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $rows=ai_verdict_receipts((int)$v['id']);$situation=ai_tactical_situation((int)$v['session_id']);$events=[];
    $html='<section class="quest-verdict" data-verdict-id="'.(int)$v['id'].'"><header class="quest-verdict-heading"><img src="'.$esc(alphano_profile_symbol('master-notes')).'" alt="" width="55" height="55"><div><small>Master · Esito del giro '.(int)$v['round_number'].'</small><h3>Il destino della scena</h3></div></header><div class="quest-verdict-narrative">';
    foreach(preg_split('/\n\s*\n/u',trim($v['narrative']),-1,PREG_SPLIT_NO_EMPTY) as $p)$html.='<p>'.nl2br($esc($p)).'</p>';
    $html.='</div>';
    $technical=rpg_decode($v['technical']??'{}');foreach($technical['results']??[] as $result)if(isset($result['activation'])){$a=$result['activation'];$html.='<p class="human-activation'.($a['success']?'':' is-failed').'"><strong>'.$esc($result['actor']).' · '.$esc($a['name']).'</strong> — Attivazione: '.$a['roll'].' / 100 · soglia '.$a['chance'].'% · '.($a['success']?'riuscita':'fallita').'</p>';}
    if($rows){$html.='<section class="quest-consequences"><h4>Conseguenze registrate</h4><div class="quest-consequence-grid">';
        foreach($rows as $r){$html.='<article class="quest-consequence"><strong>'.$esc($r['name']).'</strong><div class="quest-consequence-changes">';
            foreach($r['changes']??[] as $c){$html.='<div class="quest-change" data-effect-kind="'.$esc($c['key']).'" data-effect-sign="'.(($c['delta']??0)<0?'negative':'positive').'"><img src="'.$esc($c['icon']).'" alt="" width="45" height="45"><div><small>'.$esc($c['label']).'</small><b>'.$esc($c['value']).'</b></div></div>';}
            $html.='</div></article>';if(!empty($r['changes']))$events[]=['name'=>$r['name'],'kind'=>$r['kind'],'id'=>$r['id'],'changes'=>$r['changes']];
        }$html.='</div></section>';
    }
    if($situation){$html.='<section class="quest-verdict-situation"><h4>Situazione al termine del giro</h4><ul>';foreach($situation as $line)$html.='<li>'.$esc($line).'</li>';$html.='</ul></section>';}
    return $html.'<span hidden data-scene-effects="'.$esc(ai_quest_json($events)).'"></span></section>';
}
function ai_dice_public_html(string $name,int $roll,int $receipt):string {
    $esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    return '<div class="MasterKey"><section class="quest-dice" data-dice-result="'.$roll.'" data-dice-receipt="'.$receipt.'"><img src="'.$esc(ai_scene_effect_icon('dice')).'" alt="Dado a venti facce" width="67" height="67"><div><small>Dadi della scena · d20</small><p><strong>'.$esc($name).'</strong> ha ottenuto <b class="quest-dice-result">'.$roll.'</b><span> / 20</span></p></div></section></div>';
}

function ai_reward_public_html(string $name,int $xp,int $money,int $player):string {
    $changes=[];foreach(['xp'=>[$xp,'Esperienza'],'money'=>[$money,alphano_currency_text('Corone')]] as $key=>[$amount,$label])if($amount>0)$changes[]=['key'=>$key,'label'=>$label,'value'=>'+'.$amount,'delta'=>$amount,'icon'=>ai_scene_effect_icon($key)];
    $esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<div class="MasterKey"><section class="quest-consequences"><h4>Ricompense registrate · '.$esc($name).'</h4><div class="quest-consequence-changes">';
    foreach($changes as $c)$html.='<div class="quest-change"><img src="'.$esc($c['icon']).'" alt="" width="45" height="45"><div><small>'.$esc($c['label']).'</small><b>'.$esc($c['value']).'</b></div></div>';
    return $html.'<p>La cronaca della quest è annotata nel profilo.</p><span hidden data-scene-effects="'.$esc(ai_quest_json([['name'=>$name,'kind'=>'pg','id'=>$player,'changes'=>$changes]])).'"></span></section></div>';
}
