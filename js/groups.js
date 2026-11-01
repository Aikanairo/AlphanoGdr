document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('form[data-group-join]').forEach(form=>form.addEventListener('submit',event=>{
    if(!confirm(`Vuoi richiedere l’ammissione a ${form.dataset.groupJoin}? Potrai inviare una nuova richiesta dopo l’esito di questa.`))event.preventDefault();
  }));
});
