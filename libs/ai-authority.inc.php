<?php
declare(strict_types=1);

/** Read current in-game appointments. Technical staff flags are never narrative ranks. */
function ai_player_identity(int $player):array {
    $p=ai_db()->query("SELECT u.id,u.nome,u.sesso,u.razza,u.sindaco,u.bloccato,
        a.id AS office_id,a.nome AS office,a.descrizione AS office_description,a.city AS city_id,c.city,a.livello AS city_level,a.ramo AS city_branch_id,
        (SELECT MAX(x.livello) FROM araldiche x WHERE x.city=a.city) AS city_top_level,a.prima AS city_entry,
        EXISTS(SELECT 1 FROM araldiche x WHERE x.city=a.city AND x.livello<a.livello) AS city_has_lower,
        o.id AS organization_id,o.nome AS organization,o.city AS organization_city_id,o.approvato AS organization_approved,co.id AS organization_office_id,co.nome AS organization_office,
        co.ramo AS organization_branch_id,co.livello AS organization_level,co.prima AS organization_entry,
        CASE WHEN co.id IS NOT NULL AND u.adminorganizzazione=1 THEN 1 ELSE 0 END AS organization_manager,
        p.id AS corporation_id,p.nome AS corporation,p.city AS corporation_city_id,p.approvato AS corporation_approved,cp.id AS corporation_office_id,cp.nome AS corporation_office,
        cp.ramo AS corporation_branch_id,cp.livello AS corporation_level,cp.prima AS corporation_entry,
        CASE WHEN cp.id IS NOT NULL AND u.admincorporazione=1 THEN 1 ELSE 0 END AS corporation_manager,
        z.nome AS race,e.nome AS ethnicity
        FROM utenti u LEFT JOIN araldiche a ON a.id=u.araldica LEFT JOIN city c ON c.id=a.city
        LEFT JOIN organizzazioni o ON o.id=u.organizzazione
        LEFT JOIN caricheorganizzazioni co ON co.id=u.caricaorganizzazione AND co.organizzazione=o.id AND co.ramo=u.ramoorganizzazione
        LEFT JOIN corporazioni p ON p.id=u.corporazione
        LEFT JOIN carichecorporazioni cp ON cp.id=u.caricacorporazione AND cp.corporazione=p.id AND cp.ramo=u.ramocorporazione
        LEFT JOIN razze z ON z.id=u.razza LEFT JOIN etnie e ON e.id=u.etnia WHERE u.id=?",[$player])[0]??[];
    if(!$p)return [];
    $p['city_leader']=!empty($p['city_id'])&&(!empty($p['sindaco'])||
        (empty($p['city_entry'])&&!empty($p['city_has_lower'])&&(int)$p['city_level']===(int)$p['city_top_level']));
    return $p;
}

function ai_authority_key(string $text):string {
    $text=strtr(mb_strtolower($text),['ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','ă'=>'a','â'=>'a','î'=>'i','à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??'');
}

/** Legacy maps store their territory in the registered key/position, not a city FK.
 * Resolve only an unambiguous registered city; never inspect a player's prose.
 */
function ai_authority_place(string $chat):array {
    $map=ai_db()->query('SELECT chat_key,position FROM mappe WHERE chat_key=? AND chat=1',[$chat])[0]??[];
    $matches=[];
    if($map)foreach(ai_db()->query('SELECT id,city FROM city ORDER BY id') as $city){
        $name=ai_authority_key($city['city']);$key=ai_authority_key($map['chat_key']);$position=ai_authority_key($map['position']);
        if($name!==''&&(str_starts_with($key,$name.' ')||$key===$name||str_contains(' '.$position.' ',' '.$name.' ')))$matches[]=$city;
    }
    return count($matches)===1?['city_id'=>(int)$matches[0]['id'],'city'=>$matches[0]['city']]:['city_id'=>0,'city'=>''];
}

/** Explicit NPC administration wins over its location. Visitors retain their own allegiance. */
function ai_authority_npc_scope(array $npc):array {
    $profile=ai_npc_profile($npc);$g=ai_npc_game((int)$npc['id']);$social=ai_social_rules((int)$npc['id']);
    $place=ai_authority_place((string)($npc['chat_key']??''));
    $city=ai_db()->query('SELECT c.id,c.city FROM araldiche a JOIN city c ON c.id=a.city WHERE a.id=?',[(int)$profile['heraldry_id']])[0]??null;
    if(!$city&&$social['allegiance']!=='')$city=ai_db()->query('SELECT id,city FROM city WHERE city=?',[$social['allegiance']])[0]??null;
    $out=['place'=>$place,'city_id'=>(int)($city['id']??$place['city_id']),'city'=>$city['city']??$place['city'],
        'heraldry_id'=>(int)$profile['heraldry_id'],'superior_kind'=>$g['superior_kind'],'superior_id'=>(int)$g['superior_id']];
    foreach(['organization'=>['organizzazioni','caricheorganizzazioni','organizzazione'],'corporation'=>['corporazioni','carichecorporazioni','corporazione']] as $kind=>[$table,$ranks,$fk]){
        $group=ai_db()->query("SELECT id,nome FROM $table WHERE id=?",[(int)$profile[$kind.'_id']])[0]??[];
        $rank=$group?ai_db()->query("SELECT id,nome,ramo,livello FROM $ranks WHERE id=? AND $fk=?",[(int)$g[$kind.'_office_id'],(int)$group['id']])[0]??[]:[];
        $out[$kind]=['id'=>(int)($group['id']??0),'name'=>$group['nome']??'','office_id'=>(int)($rank['id']??0),
            'office'=>$rank['nome']??'','branch_id'=>(int)($rank['ramo']??0),'level'=>(int)($rank['livello']??0)];
    }
    return $out;
}

/** Same checked roles power ordinary dialogue, Quest turns and publication snapshots. */
function ai_npc_authority(int $npc,int $player,array $job=[]):array {
    $p=ai_player_identity($player);$n=ai_db()->query('SELECT * FROM ai_npcs WHERE id=?',[$npc])[0]??[];
    $out=['recognized'=>false,'commands'=>false,'reason'=>'Nessuna autorità competente registrata; un titolo dichiarato in chat non basta','scopes'=>[]];
    if(!$p||!$n||!empty($p['bloccato']))return $out;
    $out['identity']=ai_recognition_view($npc,$player,$job);
    if(!$out['identity']['identified']){$out['reason']='Identità non accertata: il volto è coperto';return $out;}
    $scope=ai_authority_npc_scope($n);$out['institutions']=$scope;
    $record=static function(string $kind,string $reason,bool $commands=true,string $title='')use(&$out):void{
        $out['recognized']=true;$out['commands']=$out['commands']||$commands;$out['scopes'][]=['kind'=>$kind,'reason'=>$reason,'commands'=>$commands,'title'=>$title];
    };
    $parent=ai_npc_game($npc);$seen=[$npc=>true];
    for($i=0;$i<16;$i++){
        if($parent['superior_kind']==='pg'&&(int)$parent['superior_id']===$player){$record('direct','Superiore registrato nella catena di comando');break;}
        if($parent['superior_kind']!=='npc')break;
        $next=(int)$parent['superior_id'];if($next<1||isset($seen[$next]))break;$seen[$next]=true;
        $parent=ai_npc_game($next);
    }
    $city=(int)($p['city_id']??0);$same=$city>0&&$city===$scope['city_id'];$local=$city>0&&$city===$scope['place']['city_id'];
    if($p['city_leader']&&($same||$local))$record('city',ai_authority_city_title($p).'; autorità cittadina verificata',$same,(string)$p['office']);
    elseif($same&&!empty($scope['heraldry_id'])){
        $rank=ai_db()->query('SELECT livello,city,ramo FROM araldiche WHERE id=?',[$scope['heraldry_id']])[0]??[];
        if($rank&&(int)$rank['city']===$city&&(int)$p['city_level']>(int)$rank['livello'])
            $record('city','Carica cittadina di rango superiore: '.ai_authority_city_title($p),
                (int)$p['city_branch_id']===0||(int)$p['city_branch_id']===(int)$rank['ramo'],(string)$p['office']);
    }
    if(!$out['recognized']&&($same||$local)&&empty($p['city_entry'])&&!empty($p['city_has_lower']))$record('city','Incarico pubblico riconosciuto: '.ai_authority_city_title($p),false,(string)$p['office']);
    foreach(['organization','corporation'] as $kind){
        $own=$scope[$kind];$group=(int)($p[$kind.'_id']??0);if(!$group||empty($p[$kind.'_office_id']))continue;
        $manager=!empty($p[$kind.'_manager']);$title=(string)$p[$kind.'_office'];
        if(!$own['id']||$own['id']!==$group){
            // A public local institution deserves recognition, without command
            // over residents or the staff of another organization/corporation.
            $homeValue=(string)($p[$kind.'_city_id']??'');$home=ctype_digit($homeValue)?(int)$homeValue:0;
            if($manager&&!empty($p[$kind.'_approved'])&&$home>0&&in_array($home,[$scope['city_id'],$scope['place']['city_id']],true))
                $record($kind,'Rappresentante pubblico di '.$p[$kind].' · '.$title,false,$title);
            continue;
        }
        if($manager){$record($kind,'Responsabile delegato di '.$p[$kind].' · '.$title,true,$title);continue;}
        if(empty($p[$kind.'_office_id']))continue;
        if(!$own['office_id'])continue;
        // Levels ascend in seniority for cities, descend for group ranks.
        // Different branches remain peers unless a verified general office or delegation applies.
        $branch=(int)$p[$kind.'_branch_id'];$sameBranch=$branch===$own['branch_id'];
        if(($sameBranch&&(int)$p[$kind.'_level']<$own['level'])||($branch===0&&$own['branch_id']!==0&&empty($p[$kind.'_entry'])))
            $record($kind,'Superiore nella gerarchia registrata di '.$p[$kind].' · '.$p[$kind.'_office'],true,$title);
    }
    if($out['recognized']){$out['reason']=implode('; ',array_column($out['scopes'],'reason'));$out['attitude']=ai_authority_disposition($n,ai_social_rules($npc),ai_npc_game($npc),ai_relation($npc,$player));}
    return $out;
}

/** Knowing a mandate never rewrites the NPC's convictions, relationships or fear. */
function ai_authority_disposition(array $npc,array $rules,array $game,array $relation):array {
    $configured=$rules['authority_attitude']??'contextual';if(!in_array($configured,['contextual','loyal','skeptical','defiant'],true))$configured='contextual';$mode=$configured==='loyal'?'respectful':$configured;
    $reason='Incarico legittimo nel proprio ambito; normale rispetto istituzionale.';
    if($mode==='contextual'){
        $profile=is_array($npc['profile']??null)?$npc['profile']:(json_decode($npc['profile']??'{}',true)?:[]);
        $text=implode(' ',[$npc['personality']??'',$npc['character_text']??'',$profile['psychology']??'',$rules['honor_code']??'']);$defiant=false;$skeptical=false;
        foreach(preg_split('/[.!?;]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[] as $clause){
            if(preg_match('/\b(?:non\s+(?:è|sono|si\s+(?:considera|definisce))|detesta|odia|combatte|denuncia|arresta|cattura|persegue|condanna|disprezza)\b.{0,50}\b(?:anarchic\p{L}*|anarchist\p{L}*|eversiv\p{L}*|ribell\p{L}*)/iu',$clause))continue;
            if(preg_match('/\b(?:anarchic\p{L}*|anarchist\p{L}*|eversiv\p{L}*)\b|\b(?:rifiuta|disprezza|detesta|odia|non\s+riconosce)\b.{0,30}\b(?:autorità|gerarchie|potere\s+costituito)|\bribelle\s+(?:all[’\x{27}]autorità|al\s+potere)/iu',$clause))$defiant=true;
            if(preg_match('/(?:diffida|diffidente|scettic\p{L}*|non\s+si\s+fida).{0,30}(?:autorità|gerarchie|governanti)/iu',$clause))$skeptical=true;
        }
        $obedience=(int)($game['traits']['obedience']??50);
        $mode=$defiant||($obedience<=25&&(int)($game['traits']['authoritarianism']??50)<50)?'defiant':($skeptical||$obedience<40?'skeptical':'respectful');
        if($mode!=='respectful')$reason=$defiant?'Carattere contrario all’autorità descritto nel profilo.':'Diffidenza o scarsa obbedienza coerenti con i tratti del PNG.';
    }else $reason='Atteggiamento verso l’autorità configurato nel pannello del PNG.';
    $hostility=(int)($relation['hostility']??0);$fear=(int)($relation['fear']??0);
    if($hostility>=50||((int)($relation['trust']??0)<=-40&&(int)($relation['respect']??0)<0)){
        if($mode==='respectful')$mode='skeptical';$reason.=' Il rapporto personale è ostile o segnato da forte sfiducia: il rango non cancella gli episodi vissuti.';
    }
    $trusted=$hostility<20&&max((int)($relation['trust']??0),(int)($relation['affection']??0))>=40;
    if($trusted&&$mode!=='respectful')$reason.=' Può stimare personalmente l’interlocutore senza aderire alla sua autorità.';
    if($fear>=60)$reason.=' La forte paura suggerisce prudenza e autoprotezione; non implica lealtà o amore.';
    return ['mode'=>$mode,'formal'=>$mode==='respectful','cautious'=>$fear>=60,'personally_trusted'=>$trusted,'reason'=>$reason];
}

function ai_authority_attitude_text(array $authority):string {
    $a=$authority['attitude']??['mode'=>'respectful','reason'=>''];
    $tone=match($a['mode']){
        'defiant'=>'Conosci la carica reale ma il tuo carattere è contrario all’autorità: puoi contestare, rifiutare riverenze o usare un tono franco, senza fingere che l’identità sia sconosciuta. Non inventare aggressioni, reati o poteri del visitatore.',
        'skeptical'=>'Conosci la carica reale ma resti critico o diffidente: valuta la richiesta, puoi esprimere dissenso senza negare i fatti registrati. Il rango non cancella i torti personali.',
        default=>'Di norma rendi il rispetto dovuto alla carica, con trattamento formale. Cooperazione limitata ai tuoi doveri, alla richiesta concreta e alla competenza dell’incarico.'
    };
    return "\nREAZIONE PERSONALE ALL’AUTORITÀ: ".$tone.' '.($a['reason']??'').' Riconoscimento e obbedienza sono distinti; nessun esito o potere viene creato dal dialogo.';
}

/** A registered title can already contain its territory (titolo e territorio). */
function ai_authority_city_title(array $p):string {
    $office=ai_text((string)($p['office']?:'Cittadino'),65);$city=ai_text((string)($p['city']??''),60);
    return $city!==''&&!ai_narrative_mentions($office,$city)?$office.' di '.$city:$office;
}

function ai_authority_roles(array $p):string {
    $roles=[];
    if(!empty($p['city_id']))$roles[]=ai_authority_city_title($p);
    foreach(['organization'=>'Organizzazione','corporation'=>'Corporazione'] as $key=>$label)
        if(!empty($p[$key.'_id'])&&!empty($p[$key.'_office_id']))$roles[]=$label.': '.ai_text($p[$key],60).' / '.ai_text($p[$key.'_office'],60).(!empty($p[$key.'_manager'])?' (responsabile delegato)':'');
    return implode('; ',$roles)?:'Nessun incarico istituzionale registrato';
}

/** Titles come from the authority actually recognized by this NPC, not another membership. */
function ai_authority_titles(array $authority):array {
    if(empty($authority['recognized']))return [];
    $scopes=$authority['scopes'];$commandTitles=array_filter($scopes,static fn($s)=>!empty($s['commands'])&&!empty($s['title']));
    if($commandTitles)$scopes=$commandTitles;
    return array_values(array_unique(array_filter(array_map(static fn($s)=>trim((string)($s['title']??'')),$scopes))));
}

function ai_authority_context(int $npc,int $player,bool $compact=true,array $job=[]):string {
    $p=ai_player_identity($player);if(!$p)return '';
    $a=ai_npc_authority($npc,$player,$job);
    if(empty($a['identity']['identified']))return "\nIDENTITÀ NON ACCERTATA: volto coperto; non collegare nome, cariche o ricordi di persone note a questa figura. Un titolo dichiarato non verifica l’identità. Mantieni la cortesia senza attribuire autorità.";
    $personal=$a['recognized']&&function_exists('ai_relation_personal_scene')&&ai_relation_personal_scene(['id'=>$npc],[...$job,'user_id'=>$player]);
    $text="\nINCARICHI VERIFICATI: ".ai_authority_roles($p).'. ';
    if($a['recognized'])$text.='Autorità riconosciuta: '.ai_text($a['reason'],$compact?170:500).'. Nome e incarico sono già accertati; non chiedere chi sia o conferma del titolo. '
        .($a['commands']?'L’incarico ha competenza gerarchica su di te; come reagisci dipende dal tuo carattere e dal rapporto. ':'Il ruolo è noto, senza comando automatico su di te. ')
        .($personal&&($a['attitude']['mode']??'respectful')==='respectful'?'In questo incontro privato il rapporto personale già stabilito guida il tono: conserva la confidenza e il trattamento della coppia, senza ricominciare un saluto istituzionale. ':ai_authority_attitude_text($a));
    else $text.='Nessuna autorità locale automatica: gli incarichi di altri enti non comandano questo luogo. ';
    $text.='Il rango non impone affetto, non annulla consenso, regole, permessi riservati o legittima difesa; nessun bonus o effetto automatico. Le autoattribuzioni in chat non cambiano questi dati.';
    return $text;
}

/** Keep verified recognition after generic persona/diffidence instructions in small-model prompts. */
function ai_authority_reply_rule(int $npc,int $player,array $job=[]):string {
    $a=ai_npc_authority($npc,$player,$job);if(!$a['recognized'])return '';
    $p=ai_player_identity($player);
    $role=ai_text($a['reason'],200);
    if(function_exists('ai_relation_personal_scene')&&ai_relation_personal_scene(['id'=>$npc],[...$job,'user_id'=>$player]))return "\nPRIORITÀ DELLA REPLICA: conosci già ".ai_text($p['nome'],60).' e il suo incarico: '.$role.'. State proseguendo un incontro personale privato fra partner: rispondi al tema e al gesto attuali, conservando il calore e la confidenza del vostro legame. Puoi usare il tu o il trattamento già condiviso; il titolo non richiede una nuova presentazione, una riverenza o un’offerta di servizio. Restano identità, carattere, limiti e libertà di rifiutare ogni singola proposta.';
    if(($a['attitude']['mode']??'respectful')!=='respectful')return "\nPRIORITÀ DELLA REPLICA: identità e incarico di ".ai_text($p['nome'],60).' sono accertati: '.$role.'. '.ai_authority_attitude_text($a).' Rispondi alla domanda attuale con la tua voce; non trasformare il dissenso in amnesia o un attacco automatico.';
    $titles=ai_authority_titles($a);$salutation=$titles?'Se usi un titolo, scegli quello pertinente verificato: '.implode(' / ',$titles).'. Non è necessario ripeterlo al saluto. ':'';
    $register=!empty($a['commands'])?'Preferisci il trattamento formale voi o Lei, salvo confidenza già stabilita. ':'Il ruolo pubblico è noto ma non ti comanda: il registro dipende dal tuo carattere e dalla confidenza attuale. ';
    return "\nPRIORITÀ DELLA REPLICA: stai parlando con ".ai_text($p['nome'],60).', '.$role.'. Lo hai già riconosciuto con certezza. '
        .$salutation.$register.'Rispondi prima alla domanda concreta, senza ripetere un’offerta generica di servizio o chiedere conferma della carica, identità o perché sia lì. '
        .'La diffidenza generica verso estranei non si applica a questa identità accertata. Restano i limiti dei tuoi doveri e la difesa da aggressioni reali.';
}
