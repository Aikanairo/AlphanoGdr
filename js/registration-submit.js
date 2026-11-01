/* A failed CSRF check happens before account creation; only that failure is retried. */
export async function submitRegistration(form,runtime=window.alphanoRuntime,fetcher=window.fetch.bind(window)){
 for(let attempt=0;attempt<2;attempt++){
  const token=await runtime.csrfToken({refresh:true});
  let field=form.querySelector('input[name="_csrf"]');if(!field){field=document.createElement('input');field.type='hidden';field.name='_csrf';form.append(field);}field.value=token;
  const response=await fetcher(form.action,{method:'POST',credentials:'same-origin',body:new FormData(form),headers:{Accept:'application/json','X-CSRF-Token':token}});
  const result=await response.json();
  if(response.status===403&&result.code==='csrf_expired'&&attempt===0)continue;
  return {response,result};
 }
}
