/* Servizi condivisi per form e chiamate HTTP dello stesso sito. */
(() => {
  'use strict';
  if (window.alphanoRuntime) return;
  const nativeFetch = window.fetch.bind(window);
  let token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let pendingToken=null;
  async function csrfToken({refresh=false}={}){
    if(!refresh&&token)return token;
    if(pendingToken)return pendingToken;
    pendingToken=nativeFetch('/api/session.php',{credentials:'same-origin',cache:'no-store'})
      .then(r=>r.ok?r.json():Promise.reject(new Error('Sessione non disponibile')))
      .then(data=>{if(typeof data.csrfToken!=='string'||!data.csrfToken)throw Error('Sessione non disponibile');token=data.csrfToken;const meta=document.querySelector('meta[name="csrf-token"]');if(meta)meta.content=token;protectForms();return token;})
      .finally(()=>{pendingToken=null;});
    return pendingToken;
  }
  const ready=csrfToken();
  ready.catch(()=>{});
  const unsafe = method => !['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase());
  const local = url => new URL(url, location.href).origin === location.origin;

  window.alphanoRuntime = { csrfToken };
  window.alphanoOpenView = (url, title = 'Alphano') => {
    const target = new URL(url, location.href);
    if (target.origin !== location.origin) return window.open(target.href, '_blank', 'noopener');
    url = target.pathname + target.search + target.hash;
    let context = window;
    try {
      while (context !== context.parent && !context.alphanoWorkspace) context = context.parent;
      if (context.alphanoWorkspace) return context.alphanoWorkspace.open(url, title);
    } catch (_) { /* Il contenuto esterno non può accedere alla cornice. */ }
    window.location.href = '/main.php?view=' + encodeURIComponent(url);
  };
  window.openProfileId = id => window.alphanoOpenView('/profili/index.php?Id=' + encodeURIComponent(id), 'Profilo');
  window.openProfile = name => window.alphanoOpenView('/profili/index.php?Nome=' + encodeURIComponent(name), 'Profilo');
  window.fetch = async (input, init = {}) => {
    const request = input instanceof Request ? input : null;
    const url = request ? request.url : String(input);
    // Public pages start several GETs at once: share the established session cookie.
    if(local(url))await csrfToken();
    if (local(url) && unsafe(init.method || request?.method || 'GET')) {
      const headers = new Headers(init.headers || request?.headers);
      if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', await csrfToken());
      init = { ...init, headers };
    }
    return nativeFetch(input, init);
  };

  function protectForm(form) {
    if (form.method.toLowerCase() !== 'post' || !local(form.action || location.href) || !token) return;
    let field = form.querySelector('input[name="_csrf"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden'; field.name = '_csrf'; form.appendChild(field);
    }
    field.value = token;
  }
  function protectForms() { document.querySelectorAll('form').forEach(protectForm); }
  ready.then(protectForms).catch(() => {});
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post' || !local(form.action)) return;
    if (token) protectForm(form);
    else {
      event.preventDefault();
      csrfToken().then(() => { protectForm(form); form.requestSubmit(event.submitter || undefined); })
        .catch(() => window.alphanoDialog?.notice({title:'Connessione interrotta',message:'La sessione non è disponibile. Riprova tra poco.'}));
    }
  }, true);
  new MutationObserver(protectForms).observe(document, { childList: true, subtree: true });

  const open = XMLHttpRequest.prototype.open;
  const send = XMLHttpRequest.prototype.send;
  const setHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
    if (name.toLowerCase() === 'x-csrf-token') this.alphanoTokenSet = true;
    return setHeader.call(this, name, value);
  };
  XMLHttpRequest.prototype.open = function (method, url, ...args) {
    this.alphanoTokenSet = false;
    this.alphanoUnsafe = local(url) && unsafe(method);
    return open.call(this, method, url, ...args);
  };
  XMLHttpRequest.prototype.send = function (body) {
    if (this.alphanoUnsafe && token && !this.alphanoTokenSet) this.setRequestHeader('X-CSRF-Token', token);
    return send.call(this, body);
  };
})();
