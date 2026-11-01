/* Presentation-only renderer. Values and permission checks belong to the caller. */
export function renderCharacteristicConstellation(target,{characteristics,emblem,emblemLabel,onTrain,canTrain=false,sex='male'}){
 const node=(tag,cls,text)=>{const n=document.createElement(tag);n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
 target.classList.add('characteristic-constellation');target.dataset.sex=sex==='female'?'female':'male';target.replaceChildren();target.dataset.statRender='live';
 const heart=node('div','constellation-heart'),img=node('img','constellation-seal');img.src=emblem;img.alt=emblemLabel||'Emblema della razza';img.width=160;img.height=160;heart.append(img,node('span','constellation-ornament','✧'));target.append(heart);
 for(const [index,stat] of characteristics.entries()){
  const row=node('article','constellation-attribute '+(index%2?'is-right':'is-left'));row.dataset.rpgStatKey=stat.key;row.title=stat.description+' · Base '+stat.base+' · Iniziali '+stat.initial+' · Allenati '+stat.trained;
  const sigil=node('span','constellation-sigil');sigil.setAttribute('aria-hidden','true');
  if(/^\/images\/rpg\/[a-z0-9_-]+\.(svg|png|webp)$/.test(stat.icon||'')){const image=node('img','');image.src=stat.icon;image.alt='';sigil.append(image);}else sigil.append(window.alphanoUI.icon(stat.icon||'stat-'+stat.key));
  const text=node('div','constellation-text'),label=node('h3','constellation-label',stat.name),value=node('strong','constellation-value',stat.value),meter=node('meter','constellation-track');meter.min=0;meter.max=stat.max;meter.value=stat.value;meter.setAttribute('aria-label',stat.name+': '+stat.value+' su '+stat.max);text.append(label,value,meter);row.append(sigil,text);
  if(canTrain&&(stat.training_room??(stat.max-stat.value))>0){const button=node('button','constellation-train');button.type='button';button.setAttribute('aria-label','Allena '+stat.name);button.title='Allena '+stat.name;button.append(window.alphanoUI.icon('plus'));button.addEventListener('click',()=>onTrain?.(stat));row.append(button);}
  target.append(row);
 }
}
