// bug.js – logica specifica sezione bug

document.addEventListener('DOMContentLoaded', () => {
  // conferma azioni inline (admin)
  document.querySelectorAll('form.inline').forEach(form => {
    form.addEventListener('submit', e => {
      if (!confirm('Confermi questa operazione?')) {
        e.preventDefault();
      }
    });
  });
});
