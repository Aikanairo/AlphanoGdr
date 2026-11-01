/* Profile editing behavior only; permissions and persistence remain in the PHP controller. */
(() => {
  'use strict';
  const form = document.getElementById('form');
  if (!form?.classList.contains('character-editor')) return;
  const navigation=[...form.querySelectorAll('[data-editor-target]')],panels=[...form.querySelectorAll('[data-editor-panel]')];
  const activate=key=>{panels.forEach(p=>p.hidden=p.dataset.editorPanel!==key);navigation.forEach(b=>{const active=b.dataset.editorTarget===key;b.setAttribute('aria-current',active?'page':'false');b.setAttribute('aria-controls','editor-'+b.dataset.editorTarget);});};
  if(navigation.length){navigation.forEach(b=>b.addEventListener('click',()=>activate(b.dataset.editorTarget)));activate('appearance');form.addEventListener('invalid',event=>{const panel=event.target.closest('[data-editor-panel]');if(panel)activate(panel.dataset.editorPanel);},true);}

  const banner=form.elements.namedItem('urlbanner'),preview=form.querySelector('[data-banner-preview]');
  if(banner&&preview){const refresh=()=>{preview.src=banner.value.trim()||preview.dataset.defaultSrc;};banner.addEventListener('change',refresh);preview.addEventListener('error',()=>{if(preview.getAttribute('src')!==preview.dataset.defaultSrc)preview.src=preview.dataset.defaultSrc;});form.querySelector('[data-banner-reset]').addEventListener('click',()=>{banner.value='';const upload=form.elements.namedItem('filebanner');if(upload)upload.value='';refresh();});}
  form.querySelectorAll('[data-volume-control]').forEach(input=>input.addEventListener('input',()=>input.closest('label').querySelector('output').textContent=input.value+'%'));
  form.querySelector('[data-test-notification]')?.addEventListener('click',()=>window.alphanoExperience?.test({notification_sound:true,notification_volume:Number(form.elements.notification_volume.value),sound_theme:form.elements.sound_theme.value}));
  const password = form.elements.namedItem('txtpass');
  const quality = document.getElementById('passqual');
  password?.addEventListener('input', () => {
    quality.textContent = password.value && typeof window.checkQuality === 'function' ? 'Qualità password: ' + window.checkQuality(password.value) : '';
  });
  form.querySelector('[data-generate-password]')?.addEventListener('click', () => {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%+-';
    const random = new Uint32Array(20);
    crypto.getRandomValues(random);
    password.value = Array.from(random, number => alphabet[number % alphabet.length]).join('');
    password.dispatchEvent(new Event('input'));
    const suggestion = document.getElementById('password-suggestion');
    suggestion.textContent = 'Password suggerita: ' + password.value;
    suggestion.hidden = false;
  });
  form.addEventListener('submit', event => {
    const action = event.submitter?.value || '';
    if (action === 'loadfile' && !form.elements.namedItem('fileprofilo').files.length) {
      event.preventDefault();
      const details = form.querySelector('.profile-import');
      details.open = true;
      form.elements.namedItem('fileprofilo').focus();
      document.getElementById('profile-save-status').textContent = 'Scegli il file JSON da importare.';
    }
  });
})();
