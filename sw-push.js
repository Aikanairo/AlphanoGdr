/* No fetch handler: this worker never caches private game pages or API responses. */
'use strict';
self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('push',event=>event.waitUntil((async()=>{
 let data={};try{data=event.data?.json()||{};}catch(_){}
 if(data.type!=='mail'||!/^mail:[1-9][0-9]{0,18}$/.test(data.event||''))return;
 const clients=await self.clients.matchAll({type:'window',includeUncontrolled:true});
 const foreground=clients.find(client=>client.visibilityState==='visible'&&client.focused);
 // Always present a visible notification; foreground sound comes from the game chime.
 await self.registration.showNotification('Alphano · Nuova missiva',{
  body:'Hai ricevuto un messaggio. Apri la posta per leggerlo.',
  icon:'/assets/placeholder.svg',tag:'alphano-mail',renotify:false,
  silent:!!foreground||data.silent===true,data:{event:data.event,url:'/main.php?view=%2Fposta%2F'}
 });
 if(foreground)foreground.postMessage({type:'alphano:push-mail',event:data.event});
})()));
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 event.waitUntil((async()=>{
  const target=new URL('/main.php?view=%2Fposta%2F',self.location.origin).href;
  const clients=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  const existing=clients.find(client=>new URL(client.url).origin===self.location.origin&&new URL(client.url).pathname==='/main.php');
  if(existing){await existing.navigate(target);return existing.focus();}
  return self.clients.openWindow(target);
 })());
});
