<?php
declare(strict_types=1);
require_once __DIR__.'/managed-config.inc.php';
function alphano_policy_fields():array{return [
 'controller'=>['Titolare del trattamento','Associazione Gioco Esempio — denominazione completa'],
 'address'=>['Indirizzo e contatti del titolare','Via Esempio 10, 00100 Roma, Italia'],
 'email'=>['Email per richieste privacy','privacy@example.invalid'],
 'dpo'=>['Responsabile protezione dati (se nominato)','Non nominato; indicare i contatti solo se applicabile.'],
 'purposes'=>['Dati trattati e finalità','Account: nome del personaggio, email e password protetta da hash. Dati di gioco: messaggi, profilo e inventario. Dati tecnici: log di sicurezza. Finalità: erogazione del gioco, comunicazioni di servizio e prevenzione degli abusi.'],
 'basis'=>['Basi giuridiche e conferimento','Esecuzione del servizio per account e gioco; legittimo interesse alla sicurezza, da valutare e descrivere. Consenso facoltativo per il caricamento di contenuti esterni. Spiegare quali dati sono necessari e cosa succede se non vengono forniti.'],
 'retention'=>['Conservazione e cancellazione','Indicare i tempi effettivi per account, giocate, messaggi, log e backup, la procedura di cancellazione e gli eventuali limiti. Esempio da verificare: log di sicurezza per 30 giorni e backup per 30 giorni. Il motore non applica automaticamente questi termini.'],
 'recipients'=>['Destinatari e fornitori','Indicare hosting, servizio email, eventuali servizi IA e altri fornitori realmente utilizzati, il loro ruolo e le relative informative.'],
 'transfers'=>['Trasferimenti fuori SEE','Indicare se avvengono trasferimenti, verso quali paesi e le garanzie applicabili; verificare anche hosting, email, IA e contenuti esterni.'],
 'rights'=>['Diritti e come esercitarli','Scrivere al contatto privacy per accesso, rettifica, cancellazione, limitazione, portabilità e opposizione nei casi previsti. Il consenso può essere revocato. È possibile proporre reclamo al Garante per la protezione dei dati personali.'],
 'cookies_extra'=>['Ulteriori cookie e strumenti del proprio hosting','Elencare nome, fornitore, finalità, categoria e durata dei cookie aggiuntivi realmente presenti. Nessuno nel motore base: verificare gli eventuali strumenti aggiunti dal provider o dal gestore.'],
 'additional'=>['Ulteriori informazioni','Descrivere età minima, eventuali decisioni automatizzate, contatti e particolarità effettive del proprio gioco.']
];}
function alphano_policy_sections():array{
 $v=alphano_managed_read('privacy-policy');$out=[];
 foreach(alphano_policy_fields() as $key=>[$title])if(trim((string)($v[$key]??''))!=='')$out[]=[$title,$v[$key]];
 if(!$out)$out[]=['Informativa da completare','Il gestore deve completare i dati della propria installazione. Contatto del gioco: '.alphano_config('owner_name').' · '.alphano_config('support_email').'.'];
 return $out;
}
function alphano_cookie_sections():array{
 $v=alphano_managed_read('privacy-policy');return [
 ['Sessione di accesso — necessario','Cookie alphano_* (nome specifico dell’installazione): mantiene sessione, autenticazione e protezione CSRF. Durata: sessione del browser. HttpOnly e SameSite=Lax; Secure quando il sito usa HTTPS.'],
 ['Preferenze — necessario','Cookie alphano_privacy: conserva la scelta sui contenuti multimediali esterni per 180 giorni. Il server conserva una ricevuta con identificatore casuale, versione, scelta e scadenza, senza IP o nome nella ricevuta. Le ricevute scadute vengono eliminate al successivo salvataggio delle preferenze. Le impostazioni di gioco possono essere salvate nell’account e nel browser.'],
 ['Contenuti esterni — facoltativi','Nel pacchetto base immagini e contenuti multimediali esterni sono bloccati finché non vengono abilitati dalle preferenze. Se abilitati, il fornitore esterno riceve i dati tecnici della richiesta e può usare strumenti propri: consultare la sua informativa. La scelta può essere revocata dalle Preferenze di gioco e riservatezza.'],
 ['Ulteriori strumenti e fornitori',trim((string)($v['cookies_extra']??''))?:'Il motore base non integra analytics o pubblicità. Il gestore deve verificare e descrivere gli eventuali strumenti aggiunti dal provider o da personalizzazioni. Non installare tracciamenti facoltativi prima del consenso: questa pagina non ne implementa il blocco automaticamente.'],
 ['Contatti',($v['controller']??'') . ' · '.(($v['email']??'')?:alphano_config('support_email'))]
 ];
}
