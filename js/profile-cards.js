/* The profile control uses the same server transformation endpoint as the game sidebar. */
const button=document.querySelector('[data-profile-transform]');
if(button)button.addEventListener('click',async event=>{
 event.preventDefault();event.stopPropagation();if(button.disabled)return;
 const state=JSON.parse(button.dataset.transformState);
 if(!state.canChoose){await window.alphanoDialog.notice({title:'La metamorfosi',message:state.description,buttonLabel:'Ho capito'});return;}
 button.disabled=true;
 try{
  const token=document.querySelector('meta[name="csrf-token"]')?.content||await window.alphanoRuntime.csrfToken();
  const response=await fetch('/api/transformation.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({form:state.nextForm||(state.form==='human'?'native':'human')}),signal:AbortSignal.timeout(15000)});
  const data=await response.json();if(!response.ok)throw Error(data.error||'Trasformazione non riuscita.');
  document.dispatchEvent(new CustomEvent('alphano:lycan-change',{detail:data.transformation}));
  try{if(parent!==window)parent.document.dispatchEvent(new parent.CustomEvent('alphano:lycan-change',{detail:data.transformation}));}catch{}
  location.reload();
 }catch(error){await window.alphanoDialog.notice({title:'La metamorfosi',message:error.message,buttonLabel:'Ho capito'});button.disabled=false;}
});
