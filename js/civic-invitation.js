/* One shared, acknowledged first-login invitation; never submits a candidature. */
const shown=new Set();
export async function presentCivicInvitation(invitation,csrfToken){
 if(!invitation||!window.alphanoDialog?.notice)return;
 const key=String(invitation.city_id);if(shown.has(key))return;shown.add(key);
 try{
  await window.alphanoDialog.notice({title:invitation.title,eyebrow:'Alphano · Vita pubblica',message:invitation.message,detail:'La prima candidatura apre 48 ore di candidature. Con più candidati seguono cinque giorni di voto; con un solo candidato la proclamazione avviene allo scadere delle 48 ore.',buttonLabel:'Candidature e programmi',cancelLabel:'Deciderò più tardi',actionUrl:invitation.url});
  const response=await fetch('/api/citta/elezioni.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({action:'acknowledge',city:Number(invitation.city_id)})});
  if(!response.ok)throw Error('Invito non registrato.');
 }catch(error){console.error('Invito civico:',error);}
}
