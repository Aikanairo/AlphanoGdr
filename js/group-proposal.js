document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('group-proposal-form');if(!form)return;
 const textarea=document.getElementById('group-statute'),editor=document.getElementById('group-statute-editor'),error=document.getElementById('group-proposal-error'),output=document.getElementById('group-points');
 let quill=null;
 if(window.Quill){editor.hidden=false;quill=new Quill(editor,{theme:'snow',placeholder:'Scrivi lo statuto…',modules:{toolbar:[['bold','italic','underline'],[{header:[2,3,false]}],[{list:'ordered'},{list:'bullet'}],['link','blockquote'],['clean']]}});quill.setContents(quill.clipboard.convert({html:textarea.value}));quill.root.setAttribute('aria-label','Testo dello statuto');textarea.hidden=true;textarea.required=false;}
 const count=()=>{if(!output)return true;for(const select of form.querySelectorAll('[data-production]')){const detail=select.parentElement.querySelector('[data-quality-description]');if(detail)detail.textContent=select.value!=='0'?'Durata massima: '+select.selectedOptions[0].dataset.duration:'';}const used=[...form.querySelectorAll('[data-production]')].reduce((sum,select)=>sum+Number(select.selectedOptions[0]?.dataset.cost||0),0),left=Number(output.dataset.max)-used;output.value=String(left);output.classList.toggle('over-budget',left<0);return left>=0;};
 form.addEventListener('change',count);count();
 form.addEventListener('submit',event=>{error.textContent='';if(quill)textarea.value=quill.root.innerHTML;if((quill?!quill.getText().trim():!textarea.value.trim())||!count()){event.preventDefault();error.textContent='Compila lo statuto e rispetta il budget delle produzioni.';if(quill)quill.focus();}});
 form.addEventListener('reset',()=>{queueMicrotask(()=>{if(quill)quill.setContents(quill.clipboard.convert({html:textarea.defaultValue}));error.textContent='';count();});});
});
