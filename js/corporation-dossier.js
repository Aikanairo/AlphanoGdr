/* The public dossier keeps the same banner while switching between its sections. */
document.addEventListener('DOMContentLoaded', () => {
  const panels = ['cariche', 'statuto'].map(id => document.getElementById(id));
  if (panels.some(panel => !panel)) return; // The statute editor keeps its normal form navigation.
  const links = [...document.querySelectorAll('[data-corporation-section]')];
  function refresh() {
    const section = location.hash === '#statuto' ? 'statuto' : 'cariche';
    panels.forEach(panel => { panel.hidden = panel.id !== section; });
    links.forEach(link => {
      const active = link.dataset.corporationSection === section;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  }
  links.forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    history.pushState(null, '', '#' + link.dataset.corporationSection);
    refresh();
  }));
  window.addEventListener('hashchange', refresh);
  window.addEventListener('popstate', refresh);
  refresh();
  if (location.hash === '#statuto' || location.hash === '#cariche') window.scrollTo(0, 0);
});
