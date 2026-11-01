/* ========================================================================== *
   panzoom.js ─ v1.1 • 2025
   Panning tenendo premuto il mouse/dito + zoom (+/−, wheel, pinch).
   Include il proprio CSS, disattiva il drag nativo e funziona su img[data-panzoom].
 * ========================================================================== */
(() => {
  /* === PARAMETRI ======================================================== */
  const ZOOM_IN_STEP  = 1.25;   // fattore ingrandimento (pulsante “+” e wheel↑)
  const ZOOM_OUT_STEP = 0.80;   // fattore riduzione     (pulsante “−” e wheel↓)
  const MAX_ZOOM = 6;
  const MIN_ZOOM = 0.3;

  /* === CSS AUTO-INSERT ================================================== */


  /* === INIT ============================================================= */
  const ready = () => document.querySelectorAll('img[data-panzoom]').forEach(initImg);
  document.readyState === 'loading' ? window.addEventListener('DOMContentLoaded', ready) : ready();

  /* === FUNZIONE SETUP PER OGNI IMMAGINE ================================= */
  function initImg(img){
    if (img.dataset.panReady) return;
    img.dataset.panReady = '1';

    /* --- Wrapper overflow:hidden --------------------------------------- */
    const wrap = document.createElement('div');
    wrap.className = 'pan-wrapper grab';
    img.parentNode.insertBefore(wrap, img);
    wrap.appendChild(img);

    /* blocca drag HTML5 */
    img.setAttribute('draggable','false');
    ['dragstart','selectstart'].forEach(evt=>{
      img.addEventListener(evt, e=>e.preventDefault());
      wrap.addEventListener(evt,e=>e.preventDefault());
    });

    /* --- Pulsanti + / − ------------------------------------------------- */
    const ctrl = document.createElement('div');
    ctrl.className = 'pan-ctrl';
    ctrl.innerHTML = `<button aria-label="Zoom in">+</button>
                      <button aria-label="Zoom out">−</button>`;
    wrap.appendChild(ctrl);
    const btnPlus  = ctrl.children[0];
    const btnMinus = ctrl.children[1];

    /* --- Stato trasformazione ------------------------------------------ */
    let scale = 1, posX = 0, posY = 0;     // trasformazione attuale
    let startX = 0, startY = 0;            // per drag
    let dragging = false;

    /* --- Utility -------------------------------------------------------- */
    const apply = () => { img.style.transform = `translate(${posX}px,${posY}px) scale(${scale})`; };
    const fit = () => {
      scale = Math.max(wrap.clientWidth / img.naturalWidth,
                       wrap.clientHeight/ img.naturalHeight);
      posX  = (wrap.clientWidth  - img.naturalWidth  * scale) / 2;
      posY  = (wrap.clientHeight - img.naturalHeight * scale) / 2;
      apply();
    };
    const constrain = () => {
      const w = img.naturalWidth  * scale;
      const h = img.naturalHeight * scale;
      const minX = Math.min(0, wrap.clientWidth  - w);
      const minY = Math.min(0, wrap.clientHeight - h);
      posX = Math.max(minX, Math.min(posX, 0));
      posY = Math.max(minY, Math.min(posY, 0));
      apply();
    };

    /* --- PANNING -------------------------------------------------------- */
    const down = e => {
      if (e.button !== undefined && e.button !== 0) return; // solo sinistro
      dragging = true;
      wrap.classList.replace('grab','grabbed');
      const P = e.touches ? e.touches[0] : e;
      startX = P.clientX - posX;
      startY = P.clientY - posY;
      e.preventDefault();
    };
    const move = e => {
      if (!dragging) return;
      const P = e.touches ? e.touches[0] : e;
      posX = P.clientX - startX;
      posY = P.clientY - startY;
      constrain();
      e.preventDefault();
    };
    const up   = () => { dragging = false; wrap.classList.replace('grabbed','grab'); };

    wrap.addEventListener('mousedown', down);
    wrap.addEventListener('touchstart', down, {passive:false});
    window.addEventListener('mousemove', move, {passive:false});
    window.addEventListener('touchmove', move, {passive:false});
    window.addEventListener('mouseup',   up);
    window.addEventListener('touchend',  up);

    /* --- ZOOM ----------------------------------------------------------- */
    const zoom = (factor, cx = wrap.clientWidth/2, cy = wrap.clientHeight/2) => {
      const newScale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, scale * factor));
      if (newScale === scale) return;
      const rect = wrap.getBoundingClientRect();
      const dx = (cx - rect.left - posX) / scale;
      const dy = (cy - rect.top  - posY) / scale;
      scale = newScale;
      posX = cx - rect.left - dx * scale;
      posY = cy - rect.top  - dy * scale;
      constrain();
    };

    btnPlus.onclick  = () => zoom(ZOOM_IN_STEP);
    btnMinus.onclick = () => zoom(ZOOM_OUT_STEP);

    wrap.addEventListener('wheel', e=>{
      e.preventDefault();
      zoom(e.deltaY < 0 ? ZOOM_IN_STEP : ZOOM_OUT_STEP, e.clientX, e.clientY);
    }, {passive:false});

    wrap.addEventListener('gesturechange', e=>zoom(e.scale, e.clientX, e.clientY));

    /* --- Avvio + resize ------------------------------------------------- */
    img.complete ? fit() : img.addEventListener('load', fit);
    window.addEventListener('resize', fit);
  }
})();
