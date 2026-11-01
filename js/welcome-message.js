/* Benvenuto condiviso: lettura sul server e una sola presentazione per revisione. */
const presented=new Set();
let latest=null,running=null;

async function showLatest(){
  while(latest&&latest.enabled&&latest.pending&&!presented.has(latest.revision)){
    if(!window.alphanoDialog?.notice)return;
    const message=latest;
    presented.add(message.revision);
    try{
      const options={
        title:message.title,
        eyebrow:'Alphano · Benvenuto',
        message:message.body,
        buttonLabel:message.contact_url?(message.contact_label||'Scrivi alla gestione'):'Continua nel gioco',
      };
      if(message.contact_url){options.actionUrl=message.contact_url;options.cancelLabel='Continua nel gioco';}
      // La coda comune impedisce sovrapposizioni con cronaca e ruota della sorte.
      await window.alphanoDialog.notice(options);
      // Anche chiudere con ×, Esc o «Continua» significa aver visto il messaggio.
      const response=await fetch('/api/welcome.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':message.csrfToken},
        body:JSON.stringify({action:'read',revision:message.revision}),
      });
      if(!response.ok)throw new Error('Lettura del benvenuto non salvata.');
    }catch(error){
      // Non simulare una lettura riuscita: il server la riproporrà al caricamento successivo.
      console.error('Benvenuto di Alphano:',error);
    }
    // Se è arrivata una revisione nuova mentre il dialogo era aperto, mostra solo l’ultima.
  }
}

export function presentWelcome(message,csrfToken){
  if(!message||typeof message!=='object')return Promise.resolve();
  latest={...message,revision:String(message.revision??''),csrfToken:String(csrfToken??'')};
  if(!latest.revision||!latest.enabled||!latest.pending)return running||Promise.resolve();
  if(!running)running=showLatest().finally(()=>{running=null;});
  return running;
}
