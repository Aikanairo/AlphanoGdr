/* One local audio engine for the entire game shell, shared with its same-origin frames. */
(() => {
 'use strict';if(window.alphanoExperience)return;
 try{if(parent!==window&&parent.alphanoExperience){window.alphanoExperience=parent.alphanoExperience;document.addEventListener('pointerdown',()=>window.alphanoExperience.unlock(),{once:true,capture:true});document.addEventListener('keydown',()=>window.alphanoExperience.unlock(),{once:true,capture:true});document.dispatchEvent(new CustomEvent('alphano:experience-ready'));return;}}catch(_){}
 const defaults={map_effects:true,map_lighting:true,ambient_sound:false,notification_sound:true,ambient_volume:80,notification_volume:80,sound_theme:'castle',relationship_effects:'private',chess_skin:'classic'};
 let settings={...defaults},environment=null,loading=null,context=null,ambient=null,scene=null,unlocked=false,lastSound=0,saveChain=Promise.resolve();
 const subscribers=new Set();
 const emit=()=>{for(const fn of subscribers)try{fn({settings:{...settings},environment});}catch(_){};document.dispatchEvent(new CustomEvent('alphano:experience',{detail:{settings,environment}}));};
 async function read(){const r=await fetch('/api/experience.php',{cache:'no-store'});if(!r.ok)throw Error('Preferenze non disponibili.');const data=await r.json();settings={...defaults,...data.settings};environment=data.environment;emit();updateAmbient();return {settings,environment,confirmed:data.confirmed,form:data.form};}
 const ready=()=>loading||(loading=read().catch(error=>{loading=null;throw error;}));
 async function save(patch){
  const task=async()=>{await ready();const r=await fetch('/api/experience.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({settings:patch})});const data=await r.json();if(!r.ok)throw Error(data.error||'Preferenze non salvate.');settings={...defaults,...data.settings};emit();updateAmbient();return settings;};
  const result=saveChain.then(task,task);saveChain=result.catch(()=>{});return result;
 }
 function audio(){if(!context){const Audio=window.AudioContext||window.webkitAudioContext;if(!Audio)return null;context=new Audio();}return context;}
 async function unlock(){const ctx=audio();if(!ctx)return;try{await ctx.resume();unlocked=ctx.state==='running';updateAmbient();}catch(_){};}
 document.addEventListener('pointerdown',unlock,{once:true,capture:true});document.addEventListener('keydown',unlock,{once:true,capture:true});
 function stopAmbient(){if(!ambient)return;try{ambient.source.stop();ambient.source.disconnect();ambient.filter.disconnect();ambient.gain.disconnect();}catch(_){}ambient=null;}
 function updateAmbient(){
  stopAmbient();if(!unlocked||!scene||!settings.ambient_sound||!settings.ambient_volume||document.hidden||!scene.visible())return;
  const ctx=audio();if(!ctx||ctx.state!=='running')return;
  const weather=scene.environment||environment||{};if(weather.precipitation==='none'&&weather.wind<2)return;
  const length=ctx.sampleRate*3,buffer=ctx.createBuffer(1,length,ctx.sampleRate),data=buffer.getChannelData(0);let seed=73129,previous=0;
  for(let i=0;i<length;i++){seed=(seed*16807)%2147483647;const white=seed/1073741824-1;previous=(previous+.025*white)/1.025;data[i]=weather.precipitation==='rain'?white*.5:previous*7;}
  const source=ctx.createBufferSource(),filter=ctx.createBiquadFilter(),gain=ctx.createGain();source.buffer=buffer;source.loop=true;filter.type='lowpass';filter.frequency.value=weather.precipitation==='rain'?1400:350;gain.gain.value=(settings.ambient_volume/100)*.11;source.connect(filter).connect(gain).connect(ctx.destination);source.start();ambient={source,filter,gain};
 }
 function notification(override){
  const p=override?{...settings,...override}:settings;if(!unlocked||!p.notification_sound||!p.notification_volume)return false;
  const now=Date.now();if(!override&&now-lastSound<2500)return false;const ctx=audio();if(!ctx||ctx.state!=='running')return false;lastSound=now;
  const notes=p.sound_theme==='castle'?[196,294,392]:p.sound_theme==='chime'?[659,988,1318]:p.sound_theme==='soft'?[392,523]:[523,784,1046];
  notes.forEach((frequency,i)=>{const o=ctx.createOscillator(),g=ctx.createGain(),start=ctx.currentTime+i*.105;o.type='sine';o.frequency.value=frequency;g.gain.setValueAtTime(.0001,start);g.gain.exponentialRampToValueAtTime((p.notification_volume/100)*.11/(i+1),start+.015);g.gain.exponentialRampToValueAtTime(.0001,start+.9);o.connect(g).connect(ctx.destination);o.start(start);o.stop(start+1);o.addEventListener('ended',()=>{o.disconnect();g.disconnect();},{once:true});});return true;
 }
 // Same event from polling and Web Push sounds once across tabs. No content is stored.
 const sounded=new Set();
 async function notifyOnce(event){
  if(document.hidden||!unlocked||!settings.notification_sound||!settings.notification_volume||typeof event!=='string'||event.length>190)return false;
  const key='alphano:notice-sounds',scope=document.getElementById?.('app-script')?.dataset.idpg||'game',identity=scope+':'+event;
  async function play(){
   if(sounded.has(identity))return false;
   let history={};try{history=JSON.parse(localStorage.getItem(key)||'{}');if(!history||typeof history!=='object'||Array.isArray(history))history={};if(Date.now()-Number(history[identity]||0)<86400000)return false;}catch(_){}
   if(!notification())return false;sounded.add(identity);history[identity]=Date.now();
   try{const recent=Object.entries(history).filter(([,stamp])=>Date.now()-Number(stamp)<86400000).sort((a,b)=>Number(b[1])-Number(a[1])).slice(0,128);localStorage.setItem(key,JSON.stringify(Object.fromEntries(recent)));}catch(_){}return true;
  }
  if(window.navigator?.locks)try{return await window.navigator.locks.request('alphano:notification-audio',{ifAvailable:true},lock=>lock?play():false);}catch(_){}
  // Older engines: short verified lease lets one tab win before playing.
  const lease='alphano:notice-sound-lease',token=String(Math.random())+Date.now();
  try{localStorage.setItem(lease,token);await new Promise(resolve=>setTimeout(resolve,40));if(localStorage.getItem(lease)!==token)return false;}catch(_){}
  return play();
 }
 window.alphanoExperience={notifyOnce,ready,save,unlock,refresh:read,subscribe(fn){subscribers.add(fn);if(environment)fn({settings,environment});return()=>subscribers.delete(fn);},setScene(value){scene=value;updateAmbient();},clearScene(token){if(scene?.token===token){scene=null;stopAmbient();}},notification,async test(patch){await ready().catch(()=>{});await unlock();return notification(patch);},getState:()=>({settings:{...settings},environment,unlocked})};
 document.dispatchEvent(new CustomEvent('alphano:experience-ready'));
 document.addEventListener('visibilitychange',()=>{if(document.hidden){stopAmbient();context?.suspend();}else if(unlocked){context?.resume().then(updateAmbient).catch(()=>{});}});
 window.addEventListener('pagehide',stopAmbient);
 setInterval(()=>{if(environment&&!document.hidden)read().catch(()=>{});},60000);
})();
