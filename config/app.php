<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$local=is_file(__DIR__.'/local.php')?require __DIR__.'/local.php':[];
$site=is_file($root.'/private/settings.json')?json_decode(file_get_contents($root.'/private/settings.json'),true,32,JSON_THROW_ON_ERROR):[];
$defaults=[
 'db_host'=>'127.0.0.1','db_port'=>3306,'db_socket'=>'','db_db'=>'','db_user'=>'','db_pass'=>'',
 'base_url'=>'http://localhost','storage_root'=>$root,'debug'=>false,'public_demo'=>false,
 'game_name'=>'AlphanoGdr','game_description'=>'Un mondo da inventare insieme.','owner_name'=>'Gestione del gioco','support_email'=>'admin@example.invalid',
 'theme'=>'blue','sidebar_position'=>'right','header_position'=>'top','currency_name'=>'Corone',
 'registration_enabled'=>false,'abilities_enabled'=>true,'initial_race'=>0,'initial_points'=>20,'initial_innates'=>0,
 'ethnicities_enabled'=>false,'organizations_enabled'=>false,'corporations_enabled'=>false,
 'lineages_enabled'=>false,'timezone'=>'Europe/Rome','ai_enabled'=>false,'ai_url'=>'','ai_api_key'=>'','ai_model'=>'','ai_timeout'=>60,'ai_context'=>3072,'ai_backup_dir'=>'','ai_local_control_file'=>'','hf_token'=>'',
 'smtp_host'=>'','smtp_port'=>587,'smtp_user'=>'','smtp_pass'=>'','smtp_tls'=>true,'smtp_security'=>'starttls',
 'mail_transport'=>'log','mail_from'=>'admin@example.invalid','mail_dir'=>$root.'/private/mail','push_vapid_file'=>'',
 'welcome_subject'=>'Benvenuto in {game}','welcome_body'=>"Ciao {name},\nla registrazione a {game} è completata. Puoi accedere con la password che hai scelto.\n{url}",
 'privacy_text'=>'Inserire qui l’informativa del proprio gioco e i contatti del titolare.','terms_text'=>'Rispetta le altre persone e le regole della comunità. I contenuti di gioco appartengono alla finzione.',
];
$values=array_replace($defaults,$local,$site??[]);
foreach($defaults as $key=>$value){$env=getenv('ALPHANO_'.strtoupper($key==='db_db'?'db_name':$key));if($env!==false)$values[$key]=$env;}
foreach(['debug','public_demo','smtp_tls','lineages_enabled','ai_enabled','abilities_enabled','registration_enabled','ethnicities_enabled','organizations_enabled','corporations_enabled'] as $key)$values[$key]=filter_var($values[$key],FILTER_VALIDATE_BOOL);
foreach(['smtp_port','db_port','initial_race','initial_points','initial_innates','ai_timeout','ai_context'] as $key)$values[$key]=(int)$values[$key];
// Public demo palettes are request-local and never overwrite the game settings.
if($values['public_demo']&&is_string($_GET['palette']??null)&&in_array($_GET['palette'],['blue','black','brown','red','purple'],true))$values['theme']=$_GET['palette'];
return $values;
