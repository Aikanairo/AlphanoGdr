<?php
declare(strict_types=1);

/** Administrative configuration only. Availability and consent are decided by the game. */
function ai_adult_admin_settings(int $npc): array {
    $defaults=['sexuality_enabled'=>0,'companion_enabled'=>0,'workplace_key'=>'','exclusive_player_id'=>0];
    if($npc<1)return $defaults;
    $settings=array_replace($defaults,ai_db()->query('SELECT sexuality_enabled,companion_enabled,workplace_key,exclusive_player_id FROM ai_npc_adult WHERE npc_id=?',[$npc])[0]??[]);
    $settings['workplace_key']=(string)($settings['workplace_key']??'');return $settings;
}
function ai_adult_admin_workplaces(): array {
    $rooms=ai_db()->query('SELECT chat_key,title,position,companion_enabled FROM mappe WHERE chat=1 AND rental_enabled=1 ORDER BY position,title');
    $cities=ai_db()->query('SELECT city FROM city ORDER BY city');
    $normalize=static fn(string $v)=>strtolower(preg_replace('/[^a-z0-9]+/i','',iconv('UTF-8','ASCII//TRANSLIT',$v)?:$v));
    foreach($rooms as &$room){$room['city']='Altri luoghi';foreach($cities as $city){$key=$normalize($city['city']);if($key!==''&&(str_starts_with($normalize($room['chat_key']),$key)||str_contains($normalize($room['position']),$key))){$room['city']=$city['city'];break;}}}unset($room);
    usort($rooms,static fn($a,$b)=>strnatcasecmp($a['city'].' '.$a['title'],$b['city'].' '.$b['title']));return $rooms;
}
function ai_adult_admin_validate(array $input,array $profile,string $chat): array {
    if(array_diff(array_keys($input),['sexuality_enabled','companion_enabled','workplace_key','exclusive_player_id']))throw new DomainException('Impostazione adulta del PNG non riconosciuta.',422);
    $v=array_replace(ai_adult_admin_settings(0),$input);
    foreach(['sexuality_enabled','companion_enabled'] as $field)$v[$field]=ai_integer($v,$field,0,1);
    $v['exclusive_player_id']=ai_integer($v,'exclusive_player_id',0,PHP_INT_MAX);
    $v['workplace_key']=ai_field($v,'workplace_key',100);
    if(($v['sexuality_enabled']||$v['companion_enabled'])&&(int)($profile['age']??0)<18)throw new DomainException('Indica un’età di almeno 18 anni per abilitare le scene adulte del PNG.',422);
    if($v['companion_enabled']&&!$v['sexuality_enabled'])throw new DomainException('Per il servizio di accompagnatrice abilita la sessualità adulta del PNG.',422);
    if($v['workplace_key']!==''){
        if(!ai_db()->query('SELECT id FROM mappe WHERE chat_key=? AND chat=1 AND rental_enabled=1',[$v['workplace_key']]))throw new DomainException('Scegli una locanda o un rifugio con affitto camere abilitato.',422);
        if($v['workplace_key']!==$chat)throw new DomainException('Il luogo di lavoro deve coincidere con il luogo di gioco selezionato. Crea o gestisci il PNG nella locanda scelta.',422);
    }
    if($v['companion_enabled']&&$v['workplace_key']==='')throw new DomainException('Scegli il luogo di lavoro dell’accompagnatrice.',422);
    if($v['exclusive_player_id']&&!ai_db()->query('SELECT u.id FROM utenti u JOIN ai_player_preferences p ON p.player_id=u.id WHERE u.id=? AND p.character_age>=18',[$v['exclusive_player_id']]))throw new DomainException('Il vincolo di fedeltà deve riferirsi a un personaggio giocante con età adulta dichiarata.',422);
    return $v;
}
function ai_adult_admin_save(int $npc,array $values): void {
    $db=ai_db();if(!$db->inTransaction())throw new LogicException('La configurazione adulta richiede la transazione del PNG.');
    $db->execute('INSERT INTO ai_npc_adult(npc_id,sexuality_enabled,companion_enabled,workplace_key,exclusive_player_id,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sexuality_enabled=VALUES(sexuality_enabled),companion_enabled=VALUES(companion_enabled),workplace_key=VALUES(workplace_key),exclusive_player_id=VALUES(exclusive_player_id),updated_at=VALUES(updated_at)',[$npc,$values['sexuality_enabled'],$values['companion_enabled'],$values['workplace_key']!==''?$values['workplace_key']:null,$values['exclusive_player_id'],time()]);
}
