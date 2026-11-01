/* main.js – Versione 2025 – modern-browser */

//////////////////////////////////////////////////////////////////////////
// VARIABILI GLOBALI
//////////////////////////////////////////////////////////////////////////
const nomeobj  = "mainmenu";
var   vocimenu = [];

//////////////////////////////////////////////////////////////////////////
// 1) LOGOUT
//////////////////////////////////////////////////////////////////////////
function CloseLand() {
  const w=300,h=300,l=(screen.width-w)/2,t=(screen.height-h)/2;
  window.open("/logout.php","logout",
    `toolbar=no,location=no,status=no,menubar=no,scrollbars=no,resizable=no,width=${w},height=${h},top=${t},left=${l}`
  );
  window.close();
}

//////////////////////////////////////////////////////////////////////////
// 2) MENU
//////////////////////////////////////////////////////////////////////////
function GeneraMenu(){
  let out='<ul>';
  out+=`<li><a href="#" onclick="Nascondi('${nomeobj}');return false;">×</a></li>`;
  for(const voce of vocimenu){
    const [url,name,w,h,label]=voce.split(',');
    out+=`<li><a href="#" onclick="OpenPopUp('${url}','${name}',${w},${h});return false;">${label}</a></li>`;
  }
  out+='</ul>';
  return out;
}

function MostraMenu(){
  let div=document.getElementById(nomeobj);
  if(!div){
    div=document.createElement('div');
    div.id=nomeobj;
    document.body.appendChild(div);
  }
  div.innerHTML=GeneraMenu();
  const trig=document.getElementById('spaziomenu'),
        r=trig.getBoundingClientRect(),
        sy=window.scrollY||document.documentElement.scrollTop,
        sx=window.scrollX||document.documentElement.scrollLeft;
  Object.assign(div.style,{
    position:'absolute',
    top:`${r.bottom+sy}px`,
    left:`${r.left+sx}px`,
    width:`${r.width}px`,
    zIndex:'9999',
    visibility:'visible'
  });
  setTimeout(()=>{
    document.addEventListener('mousedown',e=>{ if(!div.contains(e.target)) Nascondi(nomeobj); },{once:true});
    document.addEventListener('keyup',e=>{ if(e.key==='Escape') Nascondi(nomeobj); },{once:true});
  },0);
}

function Nascondi(id){
  const d=document.getElementById(id);
  if(d){ d.innerHTML=''; d.style.visibility='hidden'; }
}

function CambiaMenu(){
  const d=document.getElementById(nomeobj);
  d && d.style.visibility==='visible' ? Nascondi(nomeobj) : MostraMenu();
}

//////////////////////////////////////////////////////////////////////////
// 3) NEWS
//////////////////////////////////////////////////////////////////////////
function MostraNews(htmlTxt){
  let div=document.getElementById('news');
  if(!div){
    div=document.createElement('div');
    div.id='news';
    document.body.appendChild(div);
  }
  div.className='Valore';
  div.style.width=`${document.body.clientWidth/2}px`;
  div.innerHTML=htmlTxt;
  div.style.left=`${(document.body.clientWidth-div.offsetWidth)/2}px`;
  div.style.visibility='visible';
}
function ResettaNews(){
  if(confirm('Vuoi resettare il controllo nuovi eventi?')){
    Nascondi('news');
    window.frames.gnews.location.href='/news.php?azione=resetta';
  }
}

//////////////////////////////////////////////////////////////////////////
// 4) POPUP
//////////////////////////////////////////////////////////////////////////
function OpenPopUp(dest,name,w,h){
  Nascondi(nomeobj);
  window.open(dest,name,
    `toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=${w},height=${h}`
  );
}

//////////////////////////////////////////////////////////////////////////
// 5) SUONI
//////////////////////////////////////////////////////////////////////////
function GetPlugins(){
  const o={};
  for(const p of navigator.plugins)
    for(const m of p) o[m.type]=true;
  return o;
}
function PlaySound(mailNonLette){
  try{
    let wrap=document.getElementById('suono');
    if(!wrap){ wrap=document.createElement('div'); wrap.id='suono'; document.body.appendChild(wrap); }
    wrap.innerHTML='';
    if(!mailNonLette) return;
    const plugin=GetPlugins(),fmt=[
      {type:'application/ogg',ext:'ogg'},
      {type:'audio/mpeg', ext:'mp3'},
      {type:'audio/x-wav',ext:'wav'}
    ];
    for(const f of fmt){
      if(plugin[f.type]){
        wrap.innerHTML=
          `<audio autoplay preload="auto">`+
            `<source src="/posta/newmail.${f.ext}" type="${f.type}">`+
          `</audio>`;
        break;
      }
    }
  }catch{}
}

//////////////////////////////////////////////////////////////////////////
// 6) UPDATE DA control.php + MODAL LUNA
//////////////////////////////////////////////////////////////////////////
function UpdateMTop(
  sorgecala,cielo,descrCielo,
  tempoCode,descrTempo,
  ventoCode,descrVento,
  temperatura,
  lunaPhase,daysToFull,
  postaNonLette, weatherIcons={}
){
  // alba/tramonto
  document.getElementById('sorgecala').textContent=sorgecala;
  // icone
  const [imgCielo]=document.getElementsByName('cielo'),
        [imgTempo]=document.getElementsByName('tempo'),
        [imgVento]=document.getElementsByName('vento');
  if(imgCielo){ imgCielo.src=weatherIcons.cielo?.src||'/assets/weather-cloud.svg'; imgCielo.title=weatherIcons.cielo?.description||descrCielo; }
  if(imgTempo){ imgTempo.src=weatherIcons.tempo?.src||'/assets/weather-cloud.svg'; imgTempo.title=weatherIcons.tempo?.description||descrTempo; }
  if(imgVento){ imgVento.src=weatherIcons.vento?.src||'/assets/weather-cloud.svg'; imgVento.title=weatherIcons.vento?.description||descrVento; }
  document.getElementById('temperatura').innerHTML=`${temperatura}&deg;C`;

  // luna + modal
  const [imgLuna]=document.getElementsByName('luna');
  if(imgLuna){
    imgLuna.src=weatherIcons.luna?.src||'/assets/weather-cloud.svg';
    imgLuna.title=lunaPhase;
    imgLuna.style.cursor='pointer';
    imgLuna.onclick=()=>{
      if(lunaPhase.toLowerCase()==='fullmoon' || lunaPhase.toLowerCase()==='luna piena'){
        ApriModalLuna(daysToFull);
      }
    };
  }
  document.getElementById('descrluna').textContent=lunaPhase;

  // numeri giorni
  const dec=document.getElementsByName('decine')[0],
        uni=document.getElementsByName('unita')[0];
  if(dec&&uni){
    dec.src='/assets/weather-cloud.svg';
    uni.src='/assets/weather-cloud.svg';
    dec.title=uni.title=`${daysToFull} giorni alla prossima Luna Piena`;
  }

  // menu
  const menuBox=document.getElementById('spaziomenu');
  if(postaNonLette){
    menuBox.innerHTML=
      `<a href="#" onclick="parent.CambiaMenu();return false;">`+
        `<img src="/posta/newmail.gif" class="u-659827ad9">Menu`+
      `</a>`;
  } else {
    menuBox.innerHTML=
      `<a href="#" onclick="parent.CambiaMenu();return false;">Menu</a>`;
  }
  document.querySelectorAll('nav.row2 a').forEach(a=>{
  });

  PlaySound(postaNonLette);
}

// crea e mostra la modal della Luna Piena
function ApriModalLuna(daysToFull){
  let modal=document.getElementById('modal-luna');
  if(!modal){
    modal=document.createElement('div');
    modal.id='modal-luna';
    modal.innerHTML=`
      <div class="modal-content">
        <span class="modal-close" onclick="ChiudiModalLuna()">&times;</span>
        <h2>Dettagli Luna Piena</h2>
        <p>Mancano <strong>${daysToFull}</strong> giorni alla prossima Luna Piena.</p>
      </div>`;
    document.body.appendChild(modal);
  }
  modal.style.display='block';
}
function ChiudiModalLuna(){
  const m=document.getElementById('modal-luna');
  if(m) m.style.display='none';
}

//////////////////////////////////////////////////////////////////////////
// 7) AUTO-RELOAD MAPPA
//////////////////////////////////////////////////////////////////////////
function SetupMapAutoReload(intervalMs=15000){
  const container=document.getElementById('map');
  if(!container) return;
  const reload=()=>fetch('/mappa.php').then(r=>r.text()).then(html=>container.innerHTML=html);
  reload();
  setInterval(reload,intervalMs);
}
