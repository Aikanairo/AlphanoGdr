<?php
declare(strict_types=1);

function ai_npc_controls_revision(int $npc):string {
    return hash('sha256',ai_quest_json([ai_social_rules($npc),ai_npc_game($npc)]));
}
/** All validated social/game settings have a typed control, including existing defaults. */
function ai_npc_controls_schema():array {
    $fields=[];
    $add=static function($scope,$key,$label,$group,$type='text',$extra=[])use(&$fields){$fields[]=['scope'=>$scope,'key'=>$key,'label'=>$label,'group'=>$group,'type'=>$type,...$extra];};
    foreach(['voice_example'=>['Battuta di riferimento',450],'duty'=>['Dovere e ruolo',400],'goals'=>['Obiettivi',600],'honor_code'=>['Codice morale',500],'boundaries'=>['Limiti personali e comportamento',500],'narrative_rules'=>['Direttive narrative personalizzate',1600],'scene_context'=>['Contesto attuale del PNG',1200]] as $key=>[$label,$max])$add('rules',$key,$label,'Voce, contesto e regole','textarea',['max'=>$max]);
    $add('rules','allegiance','Comunità servita','Ruolo e responsabilità','select',['options'=>[['value'=>'','label'=>'Nessuna'],...array_map(static fn($r)=>['value'=>$r['city'],'label'=>$r['city']],ai_db()->query('SELECT city FROM city ORDER BY city'))]]);
    foreach(['authority_attitude'=>['Atteggiamento verso l’autorità',['contextual'=>'Dal carattere e dal rapporto','loyal'=>'Leale','skeptical'=>'Critico e diffidente','defiant'=>'Eversivo']],'orientation'=>['Orientamento affettivo',['hetero'=>'Sesso opposto','homo'=>'Stesso sesso','none'=>'Nessun interesse romantico','all'=>'Indipendente dal sesso','men'=>'Uomini','women'=>'Donne']],'availability'=>['Disponibilità affettiva',['available'=>'Disponibile','unavailable'=>'Non disponibile','vow'=>'Vincolo personale o religioso']]] as $key=>[$label,$options])$add('rules',$key,$label,'Relazioni e reazioni','select',['options'=>array_map(static fn($k,$v)=>['value'=>$k,'label'=>$v],array_keys($options),array_values($options))]);
    foreach(['recognize_vampires'=>'Riconosce la natura pubblica del personaggio','hidden_emotions'=>'Riserva le interpretazioni emotive','economy_enabled'=>'Scambi monetari','allow_loans'=>'Può proporre prestiti','accept_gifts'=>'Accetta doni'] as $key=>$label)$add('rules',$key,$label,in_array($key,['recognize_vampires','hidden_emotions'],true)?'Relazioni e reazioni':'Economia','checkbox');
    foreach(['max_amount'=>['Massimo per operazione',1000000],'daily_limit'=>[alphano_currency_text('Limite quotidiano di Corone'),10000000]] as $key=>[$label,$max])$add('rules',$key,$label,'Economia','number',['min'=>0,'max'=>$max]);
    foreach(AI_SOCIAL_POLICY as $key=>$policy)$add('rules',$key,$policy['label'],'Evoluzione dei rapporti','number',['min'=>$policy['min'],'max'=>$policy['max']]);
    foreach(AI_NPC_TRAITS as $key=>$label)$add('game','traits.'.$key,$label,'Tratti della personalità','range',['min'=>0,'max'=>100]);
    $add('game','stance','Condotta in combattimento','Capacità e condotta','select',['options'=>array_map(static fn($s)=>['value'=>$s,'label'=>ucfirst($s)],AI_NPC_STANCES)]);
    foreach(['faction'=>'Fazione o corpo','rank'=>'Grado o funzione'] as $key=>$label)$add('game',$key,$label,'Ruolo e responsabilità','text',['max'=>100]);
    foreach(['duties'=>['Doveri operativi',1000],'conditions'=>['Condizioni e vincoli attuali',1500]] as $key=>[$label,$max])$add('game',$key,$label,'Capacità e condotta','textarea',['max'=>$max]);
    foreach(['autonomous'=>'Osserva gli eventi e sostiene gli alleati','sentient'=>'Forma lycan senziente, se prevista dalla stirpe'] as $key=>$label)$add('game',$key,$label,'Capacità e condotta','checkbox');
    $add('game','superior_kind','Tipo di superiore','Ruolo e responsabilità','select',['options'=>[['value'=>'none','label'=>'Nessuno'],['value'=>'pg','label'=>'Personaggio giocante'],['value'=>'npc','label'=>'PNG']]]);
    $add('game','superior_id','ID del superiore · verificato al salvataggio','Ruolo e responsabilità','number',['min'=>0,'max'=>2147483647]);
    foreach(['organization_office_id'=>['Carica organizzativa','caricheorganizzazioni'],'corporation_office_id'=>['Carica corporativa','carichecorporazioni']] as $key=>[$label,$table])$add('game',$key,$label,'Ruolo e responsabilità','select',['options'=>[['value'=>0,'label'=>'Nessuna'],...array_map(static fn($r)=>['value'=>(int)$r['id'],'label'=>'#'.$r['id'].' · '.$r['nome']],ai_db()->query('SELECT id,nome FROM '.$table.' ORDER BY id'))]]);
    foreach(['allies'=>'Fazioni alleate · una per riga','enemies'=>'Fazioni nemiche · una per riga'] as $key=>$label)$add('game',$key,$label,'Ruolo e responsabilità','lines',['max'=>2020]);
    foreach(['enabled'=>'Addestramento contro stirpi speciali','important_role'=>'Ruolo narrativo importante confermato'] as $key=>$label)$add('game','super.'.$key,$label,'Preparazione contro stirpi speciali','checkbox');
    foreach(['training'=>['Addestramento specialistico',100],'ordinary_victory_cap'=>['Limite vittoria contro stirpi speciali (%)',AI_NPC_SUPER_RULES['ordinary_cap']],'elite_victory_cap'=>['Limite contro Originali e Ancestrali (%)',AI_NPC_SUPER_RULES['elite_cap']]] as $key=>[$label,$max])$add('game','super.'.$key,$label,'Preparazione contro stirpi speciali','number',['min'=>0,'max'=>$max]);
    $add('game','super.target_race_ids','Stirpi affrontabili','Preparazione contro stirpi speciali','multiple',['options'=>array_map(static fn($r)=>['value'=>$r['id'],'label'=>$r['name']],ai_npc_super_races())]);
    return $fields;
}
