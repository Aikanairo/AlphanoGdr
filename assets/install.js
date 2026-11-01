const form=document.querySelector('form');
function preview(){if(!form)return;const p=document.querySelector('[data-layout-preview]');document.body.dataset.palette=form.elements.theme.value;p.dataset.sidebar=form.elements.sidebar_position.value;p.dataset.header=form.elements.header_position.value;}
form?.addEventListener('change',preview);preview();
