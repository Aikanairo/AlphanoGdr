<?php
declare(strict_types=1);

/** Immediate bodily reactions, without inference, new equipment or resolved movement.
 * The game still owns fear, flight, combat and memories; this module only words them.
 */
function ai_reaction_style_variants(string $mode):array {
    return match($mode){
        'flee'=>[
            ' arretra di scatto, {sopraffatto} dal terrore; cerca con gli occhi un riparo, ma la voce non esce.',
            ' rompe il proprio gesto a metà. Con un respiro strozzato tenta di indietreggiare, le mani tese per tenere lontano il pericolo.',
            ' trasale e ritrae il busto; i piedi cercano una via di fuga da ciò che sta vedendo, mentre le labbra tremano senza parole.',
            ' sussulta, porta una mano alla bocca e cerca di farsi indietro. Il bisogno di trovare riparo prevale su qualunque frase.',
            ' ha un tremito violento alle spalle. Cerca di arretrare senza voltare completamente le spalle al mutamento, con il fiato corto.',
            ' solleva istintivamente gli avambracci e tenta di arretrare. Un suono soffocato spezza il silenzio, senza diventare una parola.',
            ' perde il filo di ogni discorso e cerca spazio dietro di sé. Il terrore rende esitante perfino il tentativo di fuga.',
            ' stringe le mani tremanti contro il petto, poi prova a ritirarsi. Gli occhi cercano un punto dove ripararsi, senza riuscire a staccarsi a lungo dal mutamento.',
            ' sussulta e tende un piede all’indietro. Cerca un riparo, con il respiro così rapido da impedire una frase intera.',
            ' si ritrae con un gesto brusco; le ginocchia tremano mentre tenta di arretrare lontano dal mutamento e cerca protezione.',
            ' trattiene un grido fra i denti. Si guarda intorno alla ricerca di riparo e tenta di arretrare, senza riuscire a mascherare il panico.',
            ' apre la bocca, ma ne esce soltanto un respiro spezzato. Cerca di indietreggiare con piccoli tentativi incerti, gli occhi spalancati.',
            ' porta le mani avanti, quasi a chiedere distanza, e tenta di fuggire. La paura {gli} toglie ogni prontezza nel parlare.',
            ' interrompe ogni movimento verso chi si sta trasformando e cerca invece una via di fuga. Il tremore arriva fino alle dita.',
            ' ha un sussulto e ritira il mento. Con il fiato bloccato in gola, prova ad arretrare verso un riparo senza osare distogliere lo sguardo.',
            ' cerca invano una parola ferma; la voce si incrina e lascia posto a un tentativo di fuga, mentre le mani tremano apertamente.'
        ],
        'freeze'=>[
            ' si pietrifica davanti al mutamento. Le mani tremano e il fiato si spezza prima che riesca a trovare una parola.',
            ' rimane {inchiodato} sul posto, gli occhi spalancati per lo spavento. Il petto si muove in respiri brevi, ma le labbra non formano suoni.',
            ' si irrigidisce dalla nuca alle dita. Un tremito {gli} attraversa le braccia e spegne sul nascere il tentativo di parlare.',
            ' trattiene il respiro fino a doverlo riprendere con uno scatto. Resta immobile, con la paura visibile nelle mani che non riesce a fermare.',
            ' apre la bocca senza voce; il gesto successivo resta sospeso. Soltanto un tremore delle spalle rompe quella paralisi.',
            ' stringe le dita nel palmo e fissa il mutamento, {sconvolto}. Le gambe tremano e non seguono l’impulso di sottrarsi alla vista.',
            ' si blocca con il mento appena sollevato. Deglutisce a vuoto, mentre un respiro sottile e irregolare rivela lo spavento.',
            ' solleva una mano, poi la lascia a mezz’aria. Rimane {pietrificato} dal terrore, incapace di completare il gesto o una frase.',
            ' ha un sussulto, seguito da un’immobilità improvvisa. Gli occhi restano fissi e la voce manca quando prova a richiamarla.',
            ' irrigidisce la mascella; un tremito insistente arriva fino alle dita. Resta {bloccato} davanti al mutamento, con il fiato mozzo.',
            ' ritrae il capo ma non riesce a fare altro. La paura {gli} ferma le parole in gola e lascia il corpo immobile, quasi sospeso.',
            ' abbassa appena il baricentro e resta immobile. Il respiro si spezza in piccoli scatti, senza che riesca a riprendere il discorso.',
            ' fissa ciò che accade con le palpebre tese e le labbra dischiuse. Un brivido scuote il corpo tremante, ma non scioglie la paralisi.',
            ' porta una mano al petto e si arresta. Cerca aria fra le labbra; le parole non arrivano e il tremore rimane ben visibile.',
            ' tronca ogni gesto e rimane {impietrito}. Le dita tremano, si aprono e si richiudono senza presa, mentre il fiato manca.',
            ' inclina il busto all’indietro, poi si blocca. Gli occhi seguono il mutamento mentre la voce si riduce a un filo spezzato dalla paura.'
        ],
        'confront'=>[
            ' si mette in guardia davanti al mutamento. L’addestramento contiene lo spavento, senza cancellarlo, mentre prepara una difesa e mantiene le distanze.',
            ' interrompe il gesto e orienta il corpo di lato. Riprende fiato con fatica, poi raccoglie l’attenzione nella propria difesa senza avanzare.',
            ' assesta il peso sulle gambe e tiene lo sguardo sul mutamento. La tensione irrigidisce la mascella, ma la preparazione {gli} permette di restare in guardia.',
            ' si irrigidisce per un istante, poi raccoglie la postura in una difesa misurata. Non cerca lo scontro: conserva distanza e controlla il respiro.',
            ' lascia uscire l’aria lentamente, senza togliere gli occhi da ciò che cambia. L’allarme resta visibile mentre si dispone a difendersi.',
            ' abbassa appena il mento e rende più stabile l’appoggio. Un tremito passa nelle mani, prima che l’addestramento le riporti sotto controllo.',
            ' tronca ogni distrazione e raccoglie le braccia vicino al corpo. Si prepara alla difesa, con la prudenza di chi riconosce un pericolo concreto.',
            ' misura con lo sguardo la distanza, poi prende una postura difensiva. Il fiato è teso, ma non cede all’impulso di precipitarsi avanti.',
            ' ha un breve sussulto che non nasconde. Riordina subito l’appoggio dei piedi e tiene una guardia prudente, senza trasformarla in un attacco.',
            ' serra le labbra e si dispone di taglio. Lo spavento passa nello sguardo; la preparazione {gli} consente di non perdere la difesa.',
            ' raccoglie il peso e smette di parlare. Il respiro rimane vigile mentre prepara la propria difesa, lasciando intatto lo spazio che li separa.',
            ' tende le spalle, quindi ne scioglie appena la rigidità per non perdere mobilità. Rimane in guardia, chiaramente in allarme.',
            ' segue il mutamento senza avvicinarsi. Il volto si tende, ma l’addestramento sostiene una difesa attenta e nessun gesto offensivo.',
            ' deglutisce una volta e riporta le mani in una posizione raccolta. Tiene le distanze, {pronto} a difendersi senza decretare lo scontro.',
            ' si arresta e cerca un equilibrio più saldo. Non sembra immune allo spavento: lo contiene quanto basta per mantenere una guardia controllata.',
            ' interrompe il respiro per un battito, poi lo regola a fatica. Prepara una difesa prudente e osserva, senza dare per scontata la propria sicurezza.'
        ],
        default=>[
            ' interrompe il proprio gesto e segue con attenzione il mutamento, lasciando agli altri il tempo di reagire.',
            ' orienta il volto verso ciò che cambia e raccoglie le mani vicino al corpo, senza avvicinarsi.',
            ' lascia cadere la frase che stava per pronunciare; gli occhi seguono il mutamento prima di ogni altro gesto.',
            ' si arresta e inclina appena il capo. Mantiene la propria distanza mentre osserva ciò che accade.',
            ' raccoglie l’attenzione sul nuovo aspetto, con le labbra chiuse e il corpo fermo.',
            ' smette di guardare altrove. Segue il mutamento senza parlare per conto di nessuno e senza ridurre la distanza.',
            ' sospende il proprio movimento, poi riporta lo sguardo su chi sta cambiando, con attenzione evidente.',
            ' serra appena le labbra e osserva il passaggio, senza precipitare una risposta o un gesto verso gli altri.',
            ' alza il volto e interrompe ogni distrazione. La sua attenzione resta sul mutamento, senza anticiparne le conseguenze.',
            ' riunisce le dita e prende un respiro misurato, seguendo ciò che cambia da dove si trova.',
            ' sposta soltanto lo sguardo, poi ferma il resto del corpo. Osserva il mutamento senza intervenire sui presenti.',
            ' si fa {attento} al nuovo aspetto e tace per un momento, conservando lo spazio che {lo} separa dagli altri.',
            ' lascia le mani ferme e segue con gli occhi il mutamento, prima di scegliere una parola.',
            ' si interrompe, il viso rivolto a ciò che accade. Non tenta di colmare subito quel silenzio.',
            ' richiama tutta l’attenzione sul mutamento e mantiene una postura raccolta, senza agire al posto altrui.',
            ' abbandona per un momento il proprio discorso. Rimane dove si trova e osserva con cautela il nuovo aspetto.'
        ]
    };
}

/** Event rotation + immutable witness ordinal: no collisions for the first 16 witnesses.
 * No process-local randomness: a retry/parallel publisher produces the same choice.
 */
function ai_reaction_style_variant(array $event,int $npc,array $witnesses,int $count=16):int {
    $ids=array_values(array_unique(array_map('intval',$witnesses)));sort($ids,SORT_NUMERIC);
    $ordinal=array_search($npc,$ids,true);
    if($ordinal===false)$ordinal=max(0,$npc);
    $seed=implode(':',[(string)($event['chat_key']??''),(int)($event['source_id']??0),(int)($event['id']??0)]);
    return ((int)hexdec(substr(hash('sha256','reaction-style-v1:'.$seed),0,7))+(int)$ordinal)%max(1,$count);
}

/** Pure composition: profile data changes expression, never the authoritative mode. */
function ai_reaction_style_compose(array $npc,array $profile,array $reaction,array $event,array $context,array $witnesses):array {
    $mode=(string)($reaction['mode']??'observe');$variants=ai_reaction_style_variants($mode);
    $index=ai_reaction_style_variant($event,(int)$npc['id'],$witnesses,count($variants));
    $female=($profile['sex']??'Maschio')==='Femmina';
    $words=['sopraffatto','inchiodato','sconvolto','pietrificato','bloccato','impietrito','scosso','pronto','attento'];$replace=[];
    foreach($words as $word)$replace['{'.$word.'}']=$female?substr($word,0,-1).'a':$word;
    $replace['{gli}']=$female?'le':'gli';
    $replace['{lo}']=$female?'la':'lo';
    $text=(string)$npc['name'].strtr($variants[$index],$replace);
    $traits=$context['traits']??[];$stats=$context['stats']??[];$relation=$context['relation']??[];
    $panic=in_array($mode,['flee','freeze'],true);$modifiers=[];
    // Insight identifies only what is visible: never a hidden rank, power or intention.
    if((int)($stats['perception']??10)>=20){
        $detail=($event['form']??'native')==='human'?'il ritorno dei lineamenti umani':(in_array((int)($event['native_race_id']??0),[2,5],true)?'gli occhi alterati e i canini affilati':'gli artigli e i lineamenti lupini');
        $modifiers[]='Lo sguardo coglie subito '.$detail.($panic?', senza che riconoscerli attenui il terrore.':', e ne segue con precisione il cambiamento.');
    }elseif((int)($stats['perception']??10)<=8)$modifiers[]='Sbattendo le palpebre, cerca di mettere a fuoco i particolari di ciò che vede.';
    $occupation=mb_strtolower((string)($profile['occupation']??''));
    $guard=preg_match('/\b(?:guardia|guardiano|guardiana|sentinella|soldato|soldatessa|capitano|capitana|militare)\b/u',$occupation)===1;
    if($panic&&$guard)$modifiers[]='Prova a ricomporsi come richiederebbe il suo servizio, ma il tremore tradisce lo sforzo.';
    elseif($panic&&(int)($traits['pride']??50)>=70)$modifiers[]='Serra le labbra nel tentativo di nascondere lo spavento, senza riuscire a fermare il tremore.';
    elseif($panic&&(int)($traits['self_control']??50)<=30)$modifiers[]='Il respiro sfugge a ogni tentativo di regolarlo.';
    elseif($panic&&(int)($traits['sociability']??50)>=70)$modifiers[]='Cerca una voce, ma la consueta prontezza nel parlare non arriva.';
    // Hidden emotions remain private; existing affection is not automatic fear immunity.
    if(empty($context['hidden_emotions'])){
        if((int)($relation['hostility']??0)>=40)$modifiers[]='Le labbra si stringono ancora: nel suo sguardo la diffidenza già maturata non si scioglie.';
        elseif((int)($relation['trust']??0)>=30||(int)($relation['affection']??0)>=30||((int)($relation['love']??0)>=30&&in_array($relation['state']??'',['innamoramento','relazione','riconciliazione'],true)))
            $modifiers[]=$panic?'Cerca nello sguardo familiare un segno di rassicurazione; il legame già costruito non basta ancora a fermare la paura.':'Cerca nello sguardo familiare un segno di continuità, senza scambiarlo per una garanzia di sicurezza.';
    }
    if($mode==='observe'&&!empty($reaction['aftermath']['active']))$modifiers[]='Il respiro resta incerto e il tremore non si è ancora placato; conserva la distanza raggiunta nello spavento.';
    // Two meaningful facets keep the preface brief even on highly customized sheets.
    if(count($modifiers)>2)$modifiers=[$modifiers[0],$modifiers[count($modifiers)-1]];
    return ['text'=>$text.($modifiers?' '.implode(' ',$modifiers):''),'variant'=>'reaction-style-v1:'.$mode.':'.$index];
}

/** Read existing sheets and witnessed event membership; no new schema or write. */
function ai_reaction_style_preface(array $npc,array $profile,array $reaction,array $event):array {
    $db=ai_db();$nid=(int)$npc['id'];$actor=(int)($event['actor_id']??0);
    static $cachedEvent='', $witnesses=[];
    $key=(string)($event['chat_key']??$npc['chat_key']??'').':'.(int)($event['source_id']??0);
    if($cachedEvent!==$key){
        $witnesses=array_map('intval',array_column($db->query('SELECT w.npc_id FROM ai_scene_witnesses w JOIN ai_scene_events e ON e.id=w.event_id WHERE e.source_id=? AND BINARY e.chat_key=BINARY ? ORDER BY w.npc_id',[(int)($event['source_id']??0),(string)($event['chat_key']??$npc['chat_key']??'')]),'npc_id'));
        $cachedEvent=$key;
    }
    $game=ai_npc_game($nid);
    // Reuse RPG formulas without the full sheet's ability/equipment/history queries.
    $race=(int)$profile['race_id'];if($race===ALPHANO_RACE_LEGACY_SPIRIT)$race=ALPHANO_RACE_SPIRIT;
    $stats=rpg_stat_values(['race_id'=>$race],rpg_progress('npc',$nid));
    $relation=$actor>0?ai_relation($nid,$actor):[];$rules=ai_social_rules($nid);
    return ai_reaction_style_compose($npc,$profile,$reaction,$event,['traits'=>$game['traits'],'stats'=>$stats,'relation'=>$relation,'hidden_emotions'=>!empty($rules['hidden_emotions'])],$witnesses);
}

/** The immediate preface already owns the first physical shock, not a draft to copy. */
function ai_reaction_style_continuation(string $preface,string $mode):string {
    return "\nCONTINUA DAL GESTO GIÀ COMPIUTO: ".$preface."\nQuesto testo è già comparso nella chat. Non riscriverlo, non parafrasarne la sequenza di gesti e non ricominciare con lo stesso primo spavento. Scrivi al massimo 25–50 parole nuove: una battuta personale o un tentativo di voce e un solo dettaglio successivo. "
        .match($mode){
            'flee'=>'Il PNG sta cercando riparo: può troncare una supplica o cercare di chiedere distanza, senza avere già raggiunto un luogo nuovo e senza riprendere domande di presentazione.',
            'freeze'=>'Il PNG è ancora bloccato: una frase, se riesce a dirla, esce a fatica. Non convertirlo in una fuga o in una conversazione disinvolta.',
            'confront'=>'Il PNG mantiene la difesa: può formulare un limite o una cautela conforme al proprio ruolo, senza iniziare un attacco e senza inventare dotazione.',
            default=>'Il PNG conserva ciò che ha già osservato e il proprio atteggiamento: scegli una breve osservazione legata al suo carattere e agli incontri veri, senza ricostruire il mutamento.'
        }." La scelta delle parole dipende dal suo profilo e dalla relazione registrata; non attribuire una stessa esclamazione a tutti i testimoni. Nessun gesto, battuta, intenzione o sentimento nuovo del PG.";
}
