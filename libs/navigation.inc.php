<?php
declare(strict_types=1);
function alphano_navigation(array $items): array
{
    require_once __DIR__.'/appearance.inc.php';$appearance=alphano_appearance();
    $groups=require dirname(__DIR__).'/config/navigation.php';
    $art=require dirname(__DIR__).'/config/navigation-icons.php';
    $labels=['Razze'=>'Elenco razze','Organizzazioni'=>'Elenco organizzazioni','Corporazioni'=>'Elenco corporazioni',
        'Bacheca Org'=>'Forum dell’organizzazione','Bacheca Corp'=>'Forum della corporazione',
        'Admin Org'=>'Amministrazione organizzazione','Admin Corp'=>'Amministrazione corporazione',
        'Admin Città'=>'Amministrazione città','Admin Araldiche'=>'Araldiche e cariche','Admin Bacheche'=>'Gestione bacheche cittadine',
        'Accademia'=>'Proposte e approvazioni','Discus. Acc.'=>'Forum dell’Accademia','Corporazione'=>'Scambi della corporazione',
        'Admin Ferite'=>'Ferite e anime','Admin Chat'=>'Gestione chat','Admin Mappe'=>'Mappe e luoghi',
        'Admin Utenti'=>'Incarichi e utenti','Admin Stato Soc.'=>'Stato sociale','Strumenti OFF'=>'Strumenti personali'];
    $result=[];
    foreach($items as $item){
        if(in_array($item['label'],['Account','Bacheca'],true)||str_starts_with($item['label'],'Posta'))continue;
        $group='gioco';
        foreach($groups as $key=>$definition)if(in_array($item['label'],$definition['items'],true)){$group=$key;break;}
        $item['appearance_key']=alphano_appearance_item_key($item['label'],$group);
        $item['group']=$group;$item['icon']=$groups[$group]['icon'];$item['image']=$art[$item['label']]??$art['Cronaca e notifiche'];
        $item['_order']=array_search($item['label'],$groups[$group]['items'],true);
        if($group==='citta'&&$item['label']==='Città')continue; // L'elenco pubblico viene aggiunto una sola volta qui sotto.
        if($group==='citta')$item['_order']+=10; // Dopo elenco e città di appartenenza, conserva le amministrazioni autorizzate.
        $item['label']=$labels[$item['label']]??$item['label'];
        $result[]=$item;
    }
    $result[]=['url'=>'/citta/','label'=>'Elenco città','group'=>'citta','icon'=>'city','image'=>$art['Elenco città'],'w'=>1000,'h'=>750,'_order'=>0];
    foreach(ConnectDB()->query('SELECT c.id,c.city FROM city c JOIN araldiche a ON a.city=c.id JOIN utenti u ON u.araldica=a.id WHERE u.id=? ORDER BY c.city', [(int)(alphano_user()['id'] ?? 0)]) as $city)
        $result[]=['url'=>'/citta/?id='.(int)$city['id'].'&sezione=araldiche','label'=>$city['city'],'group'=>'citta','icon'=>'city','image'=>'','appearance_key'=>'city-'.$city['id'],'w'=>1000,'h'=>750,'_order'=>1];
    foreach(ConnectDB()->query('SELECT id,nome FROM razze ORDER BY id') as $race)$result[]=['url'=>'/razze/?id='.(int)$race['id'],'label'=>$race['nome'],'group'=>'razze','icon'=>'users','image'=>'','appearance_key'=>'race-'.$race['id'],'w'=>1000,'h'=>750,'_order'=>(int)$race['id']];
    $result[]=['url'=>'/notifiche/','label'=>'Cronaca e notifiche','group'=>'gioco','icon'=>$groups['gioco']['icon'],'image'=>$art['Cronaca e notifiche'],'w'=>1000,'h'=>750,'_order'=>0.5];
    foreach(['organizzazioni','corporazioni','citta','accademia','master','gestione'] as $noticeGroup)if(array_filter($result,static fn($item)=>$item['group']===$noticeGroup))$result[]=['url'=>'/notifiche/?group='.$noticeGroup,'label'=>'Cronaca e notifiche','group'=>$noticeGroup,'icon'=>$groups[$noticeGroup]['icon'],'image'=>$art['Cronaca e notifiche'],'w'=>1000,'h'=>750,'_order'=>200];
    $groupOrder=array_flip(array_keys($groups));
    usort($result,static fn($a,$b)=>[$groupOrder[$a['group']],$a['_order']]<=>[$groupOrder[$b['group']],$b['_order']]);
    foreach($result as &$item){$key=$item['appearance_key']??alphano_appearance_item_key($item['label'],$item['group']);$item['image']=($appearance['items'][$key]['image']??'')?:($item['image']?:'/assets/default-nav-'.($item['icon']??'scroll').'.svg');unset($item['_order']);}
    unset($item);
    foreach($groups as $key=>&$definition){$definition['image']=($appearance['groups'][$key]['image']??'')?:$definition['image'];unset($definition['items']);}
    unset($definition);
    return ['items'=>$result,'groups'=>$groups];
}
