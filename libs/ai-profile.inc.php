<?php
declare(strict_types=1);
require_once __DIR__.'/libpg.inc.php';
require_once __DIR__.'/chat-format.inc.php';

function ai_profile_defaults(): array {
    return ['sex'=>'Maschio','race_id'=>1,'ethnicity_id'=>0,'age'=>0,'alignment'=>'NN',
        'portrait'=>'','motto'=>'','appearance'=>'','biography'=>'','psychology'=>'',
        'occupation'=>'','social_status'=>'','relationships'=>'','affections'=>'','animals'=>'','notes'=>'',
        'scene_position'=>'','scene_activity'=>'','scene_purpose'=>'',
        'hit_points'=>1000,'money'=>0,'wounds'=>'','corporation_id'=>0,'organization_id'=>0,'heraldry_id'=>0,'details'=>[]];
}
function ai_npc_profile(array $npc): array {
    $profile=is_array($npc['profile']??null)?$npc['profile']:json_decode($npc['profile']??'{}',true);
    return array_replace(ai_profile_defaults(),array_intersect_key(is_array($profile)?$profile:[],ai_profile_defaults()));
}
function ai_validate_profile(array $input, array $previous): array {
    $p=array_replace($previous,$input);
    if (array_diff(array_keys($p),array_keys(ai_profile_defaults()))) throw new DomainException('Campo del profilo non consentito.',422);
    if (!in_array($p['sex'],['Maschio','Femmina'],true)) throw new DomainException('Sesso non valido.',422);
    if (!is_string($p['alignment']) || !preg_match('/^[LNC][BNM]$/D',$p['alignment'])) throw new DomainException('Allineamento non valido.',422);
    foreach (['age'=>2000,'race_id'=>100,'ethnicity_id'=>10000,'hit_points'=>1000000,'money'=>1000000000000,'corporation_id'=>PHP_INT_MAX,'organization_id'=>PHP_INT_MAX,'heraldry_id'=>PHP_INT_MAX] as $field=>$max) $p[$field]=ai_integer($p,$field,0,$max);
    foreach (['race_id'=>'razze','ethnicity_id'=>'etnie','corporation_id'=>'corporazioni','organization_id'=>'organizzazioni','heraldry_id'=>'araldiche'] as $key=>$table) {
        if (($key==='race_id' || $p[$key]) && !ai_db()->query('SELECT id FROM '.$table.' WHERE id=?',[$p[$key]])) throw new DomainException('Valore del profilo non disponibile: '.$key,422);
    }
    foreach (['portrait'=>512,'motto'=>500,'appearance'=>3000,'biography'=>4000,'psychology'=>3000,'occupation'=>160,'social_status'=>160,'relationships'=>2000,'affections'=>2000,'animals'=>2000,'notes'=>3000,'wounds'=>500,'scene_position'=>100,'scene_activity'=>240,'scene_purpose'=>240] as $key=>$max) $p[$key]=ai_field($p,$key,$max);
    if ($p['portrait']!=='' && (!preg_match('~^(?:https?://[^/\s]+/|/(?!/))~i',$p['portrait']) || preg_match('/[\x00-\x20<>"\x27]/',$p['portrait']))) throw new DomainException('Indirizzo del ritratto non valido.',422);
    if (!is_array($p['details']) || !array_is_list($p['details']) || count($p['details'])>12) throw new DomainException('Massimo 12 informazioni aggiuntive.',422);
    $p['details']=array_map(static function($detail) {
        if (!is_array($detail)) throw new DomainException('Informazione aggiuntiva non valida.',422);
        return ['label'=>ai_field($detail,'label',80,true),'value'=>ai_field($detail,'value',2000,true)];
    },$p['details']);
    return $p;
}
function ai_profile_link(array $npc, bool $icon=true): string {
    $p=ai_npc_profile($npc);$url='/profili/png.php?Id='.(int)$npc['id'];
    return '<a class="npc-profile-link '.alphano_chat_gender($p['sex']).'" href="'.$url.'" data-npc-profile="'.(int)$npc['id'].'">'.($icon?processRazza((int)$p['race_id'],$p['sex'],(int)$p['ethnicity_id']).' ':'').htmlspecialchars($npc['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</a>';
}
function ai_public_npc(array $npc): array {
    $p=ai_npc_profile($npc);
    return ['id'=>(int)$npc['id'],'name'=>$npc['name'],'profile_url'=>'/profili/png.php?Id='.(int)$npc['id'],
        'sex'=>$p['sex'],'portrait'=>$p['portrait']?:'/assets/placeholder.svg','appearance'=>$p['appearance'],'occupation'=>$p['occupation']];
}
function ai_pending_public(string $chat): array {
    return ai_db()->query("SELECT j.id,n.name,j.npc_id,j.status,j.user_id FROM ai_jobs j JOIN ai_npcs n ON n.id=j.npc_id JOIN ai_rooms r ON r.chat_key=j.chat_key WHERE j.chat_key=? AND j.kind IN('play','observe','npc-turn','reaction') AND j.status IN('queued','running') AND j.created_at>? AND r.enabled=1 AND n.active=1 ORDER BY j.id LIMIT 24",[$chat,time()-1800]);
}
function ai_portrait_directory(): string {
    return (alphano_config('storage_root')?:dirname(__DIR__).'/config/storage').'/npc-portraits';
}
function ai_upload_portrait(array $file): string {
    if (($file['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'') || ($file['size']??0)>3*1024*1024) throw new DomainException('Scegli un’immagine PNG, JPEG o WebP entro 3 MB.',422);
    $info=@getimagesize($file['tmp_name']);
    if (!$info || $info[0]*$info[1]>4194304 || !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_WEBP],true)) throw new DomainException('Formato o dimensioni non consentiti (massimo 4 megapixel).',422);
    $image=@imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$image) throw new DomainException('Immagine non valida.',422);
    $ratio=min(1,800/max($info[0],$info[1]));$width=max(1,(int)($info[0]*$ratio));$height=max(1,(int)($info[1]*$ratio));
    $clean=imagecreatetruecolor($width,$height);imagealphablending($clean,false);imagesavealpha($clean,true);
    imagecopyresampled($clean,$image,0,0,0,0,$width,$height,$info[0],$info[1]);imagedestroy($image);
    $dir=ai_portrait_directory();
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cartella dei ritratti non disponibile.');
    $name=bin2hex(random_bytes(16)).'.png';
    try { if (!imagepng($clean,$dir.'/'.$name)) throw new RuntimeException('Salvataggio del ritratto non riuscito.');chmod($dir.'/'.$name,0600); }
    finally { imagedestroy($clean); }
    return '/api/ai.php?action=portrait&file='.$name;
}

function ai_message_html(array $job, ?string $name=null): string {
    $data=json_decode($job['result'],true,16,JSON_THROW_ON_ERROR);
    $name=$name??($data['nome']??'Narratore');
    $npc=$data['character']??(ai_db()->query('SELECT id,name,profile FROM ai_npcs WHERE id=?',[(int)($job['npc_id']??0)])[0]??null);
    $profile=$npc?ai_npc_profile($npc):ai_profile_defaults();
    $header=$npc?ai_profile_link($npc):htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $time=(int)($data['sent_at']??$job['finished_at']??0)?:time();
    $position=$data['situation']??[];$tag=isset($position['place'],$position['position'])?implode(' · ',array_unique([ai_text((string)$position['place'],80),ai_text((string)$position['position'],100)])):'';
    $placement=$tag!==''?'<strong class="chat-action '.alphano_chat_gender($profile['sex']).'" data-npc-position>['.htmlspecialchars($tag,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'] </strong>':'';
    return '<div class="ai-narration scene-note" data-message-type="action" data-ai-job="'.(int)$job['id'].'"><div class="chat-character-header"><time datetime="'.date(DATE_ATOM,$time).'">'.date('H:i',$time).'</time> '.$header.'</div><div class="chat-character-text">'.$placement.alphano_chat_text($data['testo'],$profile['sex'],true).(!empty($data['ability'])?rpg_combat_badge($data['ability']):'').'</div></div>';
}
