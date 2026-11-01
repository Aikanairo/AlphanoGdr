<?php
declare(strict_types=1);
/** One palette, layout and asset catalogue for every outgoing email. No tracking pixels. */
function alphano_email_categories():array {
 return ['mail'=>['Missive personali','Nuovi messaggi ricevuti dagli altri giocatori. Il testo della conversazione resta nel gioco.','mail'], 'trade'=>['Commercio e inventario',alphano_currency_text('Proposte, ordini, consegne e trasferimenti di Corone, oggetti e materiali.'),'trade'], 'alerts'=>['Avvisi personali','Comunicazioni del sistema e aggiornamenti indirizzati al tuo personaggio.','alerts'], 'quests'=>['Quest e Master','Aperture di quest e nuovi esiti o interventi del Master che ti riguardano.','alerts'], 'groups'=>['Organizzazioni e Accademia','Richieste, proposte e decisioni accessibili in base alle tue cariche attuali.','alerts'], 'boards'=>['Bacheche e discussioni','Nuovi interventi nelle bacheche e discussioni che puoi consultare.','mail'], 'games'=>['Scacchi e Dama','Sfide, cambi di condizioni e risultati. Gli inviti scadono comunque dopo dieci minuti.','alerts'], 'civic'=>['Cittadinanza ed elezioni','Richieste civiche, candidature, votazioni e risultati della tua città.','alerts']];
}
function alphano_email_url(string $path='/main.php'):string {
 $base=rtrim((string)alphano_config('base_url'),'/');
 if(!preg_match('#^https?://[^/]+#',$base))$base='https://example.invalid';
 if(!str_starts_with($path,'/')||str_starts_with($path,'//')||str_contains($path,"\r")||str_contains($path,"\n"))$path='/main.php';
 return $base.$path;
}
function alphano_email_render(string $subject,string $body,string $category='alerts',string $path='/main.php',bool $optional=false):string {
 $art=in_array($category,['mail','trade','alerts','welcome','security'],true)?$category:(alphano_email_categories()[$category][2]??'alerts');
 $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 ob_start();require dirname(__DIR__).'/templates/email/notification.php';return ob_get_clean();
}
