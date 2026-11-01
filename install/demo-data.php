<?php
declare(strict_types=1);
/** Clean, opt-in bootstrap data. No players, histories or external database dumps. */
function alphano_install_demo_data(PDO $pdo, int $admin, array &$settings): array
{
    $seed=json_decode(file_get_contents(dirname(__DIR__).'/config/demo-data.json'),true,32,JSON_THROW_ON_ERROR);
    $insert=static fn(string $table,array $values):int=>alphano_seed_insert($pdo,$table,$values);
    $now=time();$stats=$seed['race']['stats'];
    $race=$insert('razze',['id'=>100,'nome'=>$seed['race']['name'],'forza'=>$stats['strength']]);
    $insert('rpg_races',['race_id'=>$race,'base_stats'=>json_encode($stats,JSON_THROW_ON_ERROR),'description'=>'Razza ordinaria iniziale, personalizzabile dal gestore.','source'=>'Pacchetto di esempio AlphanoGdr','special'=>0,'enabled'=>1,'human_default'=>1,'updated_at'=>$now]);
    $ethnicity=$insert('etnie',['nome'=>$seed['ethnicity']['name'],'descrizione'=>$seed['ethnicity']['description']]);
    $city=$insert('city',['city'=>$seed['city'],'budget'=>0,'statuto'=>'Città di esempio: personalizza lo statuto dal pannello del gestore.','newstatuto'=>'','elezioni'=>'0','elections_enabled'=>0]);
    $branch=$insert('ramiaraldiche',['city'=>$city,'nome'=>'Cittadinanza','livello'=>1]);
    $insert('araldiche',['nome'=>'Cittadino','prima'=>'1','city'=>$city,'ramo'=>$branch,'descrizione'=>'Prima carica della città, assegnata ai cittadini.','livello'=>1]);
    $mayor=$insert('araldiche',['nome'=>'Sindaco','prima'=>'0','city'=>$city,'ramo'=>$branch,'descrizione'=>'Amministratore della città.','livello'=>10]);
    $area=$insert('world_areas',['city_id'=>$city,'slug'=>'city_alphanogdr','title'=>$seed['city'],'description'=>'Una città pronta da personalizzare.','image'=>$seed['map']['city_image'],'enabled'=>1,'position'=>0]);
    $markers=[];
    foreach($seed['chats'] as $position=>$chat){
        $room=$insert('mappe',['chat_key'=>$chat['key'],'chat'=>1,'title'=>$chat['name'],'position'=>'city_alphanogdr','descrizione'=>$chat['description'],'scene_image'=>$chat['image'],'scene_thumbnail'=>$chat['image'],'scene_icon'=>'city','public_enabled'=>1,'area_id'=>$area,'sort_order'=>$position]);
        $markers[(string)$room]=$chat['marker'];
    }
    $pdo->prepare("UPDATE utenti SET razza=?,etnia=?,araldica=?,sindaco='1' WHERE id=?")->execute([$race,$ethnicity,$mayor,$admin]);
    $type=$insert('tipo',['nome'=>'Materia prima','tipoG'=>'risorsa','equipaggiabile'=>'0','colore'=>'#79b8ee','immagine'=>'/assets/default-nav-coins.svg']);
    foreach($seed['materials'] as $material){
        $resource=$insert('equip',['shortDesc'=>ucfirst($material),'desc'=>'Materia prima del catalogo dimostrativo. Utilizzabile per raccolta, commercio e creazione di oggetti.','datacreazione'=>$now,'datamodifica'=>$now,'tipo'=>$type,'approvato'=>'1','visibile'=>'1','peso'=>1,'materiaprima'=>$material,'immagine'=>'/assets/materials/'.$material.'.webp','corp'=>0,'durata'=>100]);
        $job=$insert('lavori',['chat'=>$seed['chats'][0]['key'],'azione'=>'Raccolta dimostrativa: '.$material,'prob'=>60,'res'=>$resource,'frase'=>'Completa una lavorazione dimostrativa e raccoglie una materia prima.']);
        $insert('chat_work_options',['job_id'=>$job,'enabled'=>1,'quantity'=>1,'stat_key'=>'perception']);
    }
    $settings['initial_race']=$race;$settings['ethnicities_enabled']=true;$settings['demo_data_installed']=true;
    // Bind markers to fresh IDs, never to IDs from another game.
    $map=['mode'=>'visual','maps'=>['world'=>['image'=>$seed['map']['world_image'],'markers'=>[(string)$area=>$seed['map']['city_marker']]],'area-'.$area=>['image'=>$seed['map']['city_image'],'markers'=>$markers]]];
    // Registration is deliberately opened by the manager after reviewing the setup.
    $pdo->prepare("UPDATE engine_setup_steps SET status='completed',updated_at=? WHERE step_key IN ('chats','races','ethnicities','cities')")->execute([$now]);
    return $map;
}
