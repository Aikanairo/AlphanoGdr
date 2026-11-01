/* Keep matching nodes, keyboard focus and the visible scroll anchor during background updates. */
function key(node,index){return node.nodeType===1?(node.dataset.liveKey||node.id||'@'+index):'@'+index;}
function morph(old,fresh){
 if(old.nodeType!==fresh.nodeType||old.nodeName!==fresh.nodeName){old.replaceWith(fresh);return;}
 if(old.nodeType===3){if(old.nodeValue!==fresh.nodeValue)old.nodeValue=fresh.nodeValue;return;}
 if(old.nodeType!==1)return;
 for(const a of Array.from(old.attributes))if(!fresh.hasAttribute(a.name))old.removeAttribute(a.name);
 for(const a of fresh.attributes)if(old.getAttribute(a.name)!==a.value)old.setAttribute(a.name,a.value);
 children(old,fresh);
}
function children(parent,fresh){
 const old=Array.from(parent.childNodes),pool=new Map(old.map((node,i)=>[key(node,i),node]));
 Array.from(fresh.childNodes).forEach((node,i)=>{
  const existing=pool.get(key(node,i));if(existing){pool.delete(key(node,i));if(parent.childNodes[i]!==existing)parent.insertBefore(existing,parent.childNodes[i]||null);morph(existing,node);}
  else parent.insertBefore(node,parent.childNodes[i]||null);
 });
 for(const node of pool.values())node.remove();
}
const snapshots=new WeakMap();
export function patchLiveList(container,html){
 if(!container||snapshots.get(container)===html)return;
 snapshots.set(container,html);
 const template=document.createElement('template');template.innerHTML=html;
 const active=document.activeElement;let scroll=container;
 while(scroll&&scroll!==document.body&&!/(auto|scroll)/.test(getComputedStyle(scroll).overflowY))scroll=scroll.parentElement;
 const top=scroll?.scrollTop||0,left=scroll?.scrollLeft||0;
 const visible=top?Array.from(container.children).find(el=>el.getBoundingClientRect().bottom>(scroll?.getBoundingClientRect().top||0)):null;
 const anchor=visible?.dataset.liveKey,anchorTop=visible?.getBoundingClientRect().top;
 children(container,template.content);
 if(scroll){scroll.scrollTop=top;scroll.scrollLeft=left;const same=anchor&&Array.from(container.children).find(el=>el.dataset.liveKey===anchor);if(same)scroll.scrollTop+=same.getBoundingClientRect().top-anchorTop;}
 if(active?.isConnected&&active!==document.activeElement)active.focus({preventScroll:true});
}
