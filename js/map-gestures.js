/* Drag/pinch share one state machine. A child's lost capture is not a cancelled gesture. */
(function(root){
 'use strict';
 class Contacts {
  constructor(camera,change){this.camera=camera;this.change=change;this.points=new Map();this.gesture=null;}
  pair(){const p=[...this.points.values()];return{mid:{x:(p[0].x+p[1].x)/2,y:(p[0].y+p[1].y)/2},distance:Math.hypot(p[0].x-p[1].x,p[0].y-p[1].y)};}
  down(id,point,link=null){if(this.points.has(id))return;this.points.set(id,point);if(this.points.size===1)this.gesture={start:point,last:point,link,dragging:false};else this.gesture={pinch:true,...this.pair()};}
  move(id,point){if(!this.points.has(id)||!this.gesture)return false;this.points.set(id,point);const g=this.gesture;
   if(this.points.size>=2){const next=this.pair();this.camera.zoom(this.camera.scale*next.distance/Math.max(1,g.distance),g.mid.x,g.mid.y);this.camera.pan(next.mid.x-g.mid.x,next.mid.y-g.mid.y);this.gesture={pinch:true,...next};this.change();return true;}
   if(g.pinch)return false;if(!g.dragging&&Math.hypot(point.x-g.start.x,point.y-g.start.y)<8)return false;g.dragging=true;this.camera.pan(point.x-g.last.x,point.y-g.last.y);g.last=point;this.change();return true;
  }
  up(id,cancelled=false){if(!this.points.has(id))return null;const g=this.gesture;this.points.delete(id);let tap=null;
   if(this.points.size===1){const point=[...this.points.values()][0];this.gesture={start:point,last:point,dragging:true};}
   else if(!this.points.size){if(!cancelled&&g&&!g.dragging&&!g.pinch)tap=g.link;this.gesture=null;}
   else this.gesture={pinch:true,...this.pair()};return tap;
  }
  reset(){this.points.clear();this.gesture=null;}
 }
 function attach(surface,camera,change,activate,options={}){
  const doc=surface.ownerDocument,win=doc.defaultView,contacts=new Contacts(camera,change),subscriptions=[];
  const pointerEvents=options.pointerEvents??('PointerEvent' in win);let suppressUntil=0;
  const now=()=>win.performance.now(),local=e=>{const r=surface.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top};};
  const link=e=>e.target?.closest?.('a.map-link')||null;
  const listen=(node,type,handler,settings)=>{node.addEventListener(type,handler,settings);subscriptions.push(()=>node.removeEventListener(type,handler,settings));};
  const reset=()=>{contacts.reset();surface.classList.remove('is-dragging');};
  const prevent=e=>{if(e.cancelable)e.preventDefault();};
  const moved=(id,event)=>{if(!contacts.points.has(id))return false;if(!contacts.move(id,local(event)))return false;prevent(event);suppressUntil=now()+700;surface.classList.add('is-dragging');return true;};
  listen(surface,'dragstart',prevent);
  listen(surface,'click',e=>{if(e.detail===0)return;if(now()<suppressUntil){prevent(e);e.stopImmediatePropagation();}},true);
  if(pointerEvents){
   listen(surface,'pointerdown',e=>{if(e.pointerType==='mouse'&&e.button!==0)return;if(!contacts.points.size)suppressUntil=0;contacts.down(e.pointerId,local(e),link(e));});
   listen(win,'pointermove',e=>{if(!moved(e.pointerId,e))return;
    // Capture may be absent on older embedded browsers or synthetic test input.
    try{if(!surface.hasPointerCapture?.(e.pointerId))surface.setPointerCapture?.(e.pointerId);}catch(_){/* Window listeners still complete the drag. */}
   },{passive:false});
   const end=e=>{contacts.up(e.pointerId,e.type==='pointercancel');if(!contacts.points.size)surface.classList.remove('is-dragging');};
   listen(win,'pointerup',end);listen(win,'pointercancel',end);
   listen(surface,'lostpointercapture',e=>{
    // Touch implicitly captures the hit child (<image>, <text> or <polygon>).
    // Its bubbling loss during transfer to the root must NOT remove the contact.
    if(e.target!==surface||surface.hasPointerCapture?.(e.pointerId))return;
    contacts.up(e.pointerId,true);if(!contacts.points.size)surface.classList.remove('is-dragging');
   });
  }else{
   // Legacy Safari/WebViews without Pointer Events: listeners must be non-passive.
   listen(surface,'touchstart',e=>{prevent(e);if(!contacts.points.size)suppressUntil=0;for(const t of e.changedTouches)contacts.down(t.identifier,local(t),link(e));},{passive:false});
   listen(surface,'touchmove',e=>{prevent(e);for(const t of e.changedTouches)moved(t.identifier,t);},{passive:false});
   listen(surface,'touchend',e=>{prevent(e);for(const t of e.changedTouches){const target=contacts.up(t.identifier);if(target&&target===doc.elementFromPoint(t.clientX,t.clientY)?.closest?.('a.map-link'))activate(target);}suppressUntil=now()+700;if(!contacts.points.size)surface.classList.remove('is-dragging');},{passive:false});
   listen(surface,'touchcancel',e=>{prevent(e);suppressUntil=now()+700;reset();},{passive:false});
   listen(surface,'mousedown',e=>{if(e.button===0){if(!contacts.points.size)suppressUntil=0;contacts.down('mouse',local(e),link(e));}});
   listen(win,'mousemove',e=>moved('mouse',e),{passive:false});listen(win,'mouseup',()=>{contacts.up('mouse');surface.classList.remove('is-dragging');});
  }
  listen(win,'blur',reset);listen(win,'pagehide',reset);listen(doc,'visibilitychange',()=>{if(doc.hidden)reset();});
  return{contacts,destroy(){reset();subscriptions.forEach(remove=>remove());}};
 }
 const api={Contacts,attach};if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.AlphanoMapGestures=api;
})(typeof window!=='undefined'?window:this);
