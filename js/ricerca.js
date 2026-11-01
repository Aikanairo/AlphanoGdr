(() => {
  // leggiamo i dati inseriti da PHP
  let dati    = window.ricerca    || [];
  let posizioni = window.posizioni || [];

  let orderby   = 'nome';
  let ordersort = true; // true = asc

  // funzione di ordinamento
  function Ordina(a, b) {
    if (a[orderby] < b[orderby]) return -1;
    if (a[orderby] > b[orderby]) return  1;
    return 0;
  }

  // restituisce HTML di header di colonna
  function th(label, field) {
    let arrow = '';
    if (field === orderby) {
      arrow = ordersort?' ↓':' ↑';
    }
    return `<td class="Valore u-30b7e95cf" align="center">
              <a href="javascript:Carica('${field}')">${label}</a>${arrow}
            </td>`;
  }

  // Carica i risultati con eventuale nuovo ordinamento
  window.Carica = function(field) {
    if (field && field !== orderby) {
      orderby   = field;
      ordersort = true;
    } else if (field === orderby) {
      ordersort = !ordersort;
    }

    // legge i filtri dal form
    const f = document.getElementById('ricercaForm');
    const filters = {};
    new FormData(f).forEach((v,k)=>{ if(v) filters[k]=v; });

    // applica filtri JS
    let subset = dati.filter(r => {
      for (let k in filters) {
        const val = filters[k].toLowerCase();
        if (String(r[k]||'').toLowerCase().indexOf(val)===-1) {
          return false;
        }
      }
      return true;
    });

    // ordina
    subset.sort(Ordina);
    if (!ordersort) subset.reverse();

    // header
    let out = `<tr>${th('Nome','nome')}${th('Razza','razza')}`
            + `${th('Allineamento','allineamento')}${th('Cariche','cariche')}`
            + `${th('Posizione','posizione')}</tr>`;

    // risultati
    if (subset.length) {
      out = `<tr><td colspan="5" class="Descrizione">${subset.length} trovati:</td></tr>` + out;
      subset.forEach(r => {
        out += `<tr>
          <td class="Valore" align="center">
            <a href="javascript:openProfileId('${r.idpg}')">${r.nome}</a>
          </td>
          <td class="Valore">
            <img src="/assets/placeholder.svg" width="16" height="16"
                 alt="${r.razza}" title="${r.razza}"> ${r.razza}
          </td>
          <td class="Valore" align="center">${r.allineamento}</td>
          <td class="Valore" align="center">
            <table width="100%"><tr>
              <td width="33%" align="center">${
                r.imgorg
                ? `<img src="/uploads/badges/organizzazioni/${r.imgorg}.gif" width="25" height="25"
                      title="${r.org}" alt="${r.org}">`
                : ''
              }</td>
              <td width="34%" align="center">${
                r.imgcorp
                ? `<img src="/uploads/badges/corporazioni/${r.imgcorp}.gif" width="25" height="25"
                      title="${r.corp}" alt="${r.corp}">`
                : ''
              }</td>
              <td width="33%" align="center">
                <img src="/uploads/badges/cariche/${r.imgaraldica}.gif" width="25" height="25"
                     title="${r.araldica}" alt="${r.araldica}">
              </td>
            </tr></table>
          </td>
          <td class="Valore" align="center">${r.posizione}</td>
        </tr>
        <tr><td colspan="5" height="1" class="u-30b7e95cf"></td></tr>`;
      });
    } else {
      out += `<tr><td colspan="5" class="Descrizione">Nessun risultato</td></tr>`;
    }

    document.getElementById('risultato').innerHTML = `<table width="100%">${out}</table>`;
  };
})();
