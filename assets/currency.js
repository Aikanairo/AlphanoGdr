/* Labels are configured by the manager; protocol keys and amounts never change. */
(() => {
  const name = document.querySelector('meta[name="alphano-currency"]')?.content || 'Corone';
  const escaped = name.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  window.AlphanoCurrency = Object.freeze({
    name,
    text: text => String(text).replace(/\bcorone\b/gi, () => name),
    html: text => String(text).replace(/\bcorone\b/gi, () => escaped)
  });
})();
