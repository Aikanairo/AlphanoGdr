import {patchLiveList} from './live-list.js';
/* La lettura è salvata sul server; nessun avviso viene ricreato dal polling. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const mail=document.getElementById('mail-open'),badge=document.getElementById('mail-unread');
    const list=document.getElementById('quest-notifications'),count=document.getElementById('quest-count');
    const activityList=document.getElementById('activity-notifications'),activityCount=document.getElementById('activity-count');
    if(!mail||!badge||!list)return;
    let state={},pending=[],activities=[],currentCounts={},activityTotal=0,loading=false,issued=0,rendered=0;
    const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function refreshBadges(){
      document.dispatchEvent(new CustomEvent('alphano:notifications',{detail:state}));
      document.querySelectorAll('iframe').forEach(frame=>{try{frame.contentDocument?.dispatchEvent(new CustomEvent('alphano:notifications',{detail:state}));}catch{}});
    }
    let previousMail=null,latestMail=0;const seenQuest=new Set(),seenActivity=new Set();
    let modalRunning=false;const modalQueue=new Map();
    async function announce(){
      if(modalRunning||!modalQueue.size||!window.alphanoDialog)return;
      modalRunning=true;const batch=[...modalQueue.values()];modalQueue.clear();
      try{
        if(window.alphanoBoardNotices){
          const games=batch.filter(n=>n.event_type==='board-game'),latest=new Map();games.forEach(n=>{const id=n.data?.game_id||n.key,old=latest.get(id);if(!old||Number(n.data?.presentation?.version||n.created_at||0)>Number(old.data?.presentation?.version||old.created_at||0))latest.set(id,n);});
          for(const n of latest.values()){const decision=await window.alphanoBoardNotices.fromNotice(n,Number(document.getElementById('app-script')?.dataset.idpg||0));if(decision==='ack')await request({keys:[n.key]});}
          for(let i=batch.length-1;i>=0;i--)if(batch[i].event_type==='board-game')batch.splice(i,1);
          if(!batch.length)return;
        }
        const single=batch.length===1?batch[0]:null,trade=Boolean(single?.data?.order_id);
        const decision=await window.alphanoDialog.notice({
          variant:'activity',iconImage:trade?'/assets/placeholder.svg':undefined,title:trade?single.title:(single?'Nuova notifica':'Nuove notifiche'),eyebrow:'Alphano · Cronaca',
          message:trade?single.detail:batch.length+(single?' aggiornamento da leggere.':' aggiornamenti da leggere.'),
          items:trade?undefined:batch.slice(0,12).map(n=>({label:n.title,meta:n.detail,image:n.data?.order_id||n.commerce?'/assets/placeholder.svg':undefined,symbol:n.event_type==='board-game'?'♜':n.key==='mail'?'✉':'✦',actionUrl:n.url||'/notifiche/?notice='+encodeURIComponent(n.key),actionLabel:n.event_type==='board-game'?'Apri il tavolo':'Leggi'})),
          itemsTitle:'Ultimi aggiornamenti',contentUrl:trade?'/commercio/?id='+Number(single.data.order_id):undefined,
          actionUrl:trade?undefined:'/notifiche/',buttonLabel:trade?'Chiudi trattativa':'Apri la cronaca',cancelLabel:'Più tardi'
        });
        // Opening the chronicle is not the same as reading every receipt in the batch.
        const opened=decision.startsWith('item:')?batch[Number(decision.slice(5))]:(decision==='ack'&&trade?single:null);
        if(opened?.key?.startsWith('quest:'))await request({ids:[Number(opened.key.slice(6))]});
        else if(opened?.key&&opened.key!=='mail')await request({keys:[opened.key]});
      }catch(error){console.error('Lettura della notifica non salvata',error);}
      finally{modalRunning=false;if(modalQueue.size)announce();}
    }
    function render(data){
      const first=previousMail===null,oldLatestMail=latestMail;
      const newItems=first?(data.activity||[]).filter(a=>a.event_type==='board-game'&&a.data?.presentation?.status==='pending'&&a.data.presentation.expires_at*1000>Date.now()): (data.activity||[]).filter(a=>!seenActivity.has(a.key)&&Number(a.actor_id)!==Number(document.getElementById('app-script')?.dataset.idpg||-1));
      const newQuests=first?[]:(data.quests||[]).filter(q=>!seenQuest.has(q.id)).map(q=>({key:'quest:'+q.id,title:'Nuova quest: '+q.title,detail:q.location_label}));
      const newNotice=!first&&((Number(data.mail_latest_unread)||0)>latestMail||(data.quests||[]).some(q=>!seenQuest.has(q.id))||(data.activity||[]).some(a=>!seenActivity.has(a.key)));
      previousMail=Number(data.mail)||0;latestMail=Math.max(latestMail,Number(data.mail_latest_unread)||0);(data.quests||[]).forEach(q=>seenQuest.add(q.id));(data.activity||[]).forEach(a=>seenActivity.add(a.key));
      if(newNotice){const event=Number(data.mail_latest_unread)>oldLatestMail?'mail:'+Number(data.mail_latest_unread):(newQuests[0]?.key||newItems[0]?.key||'notice:'+latestMail);window.alphanoExperience?.ready().then(()=>window.alphanoExperience.notifyOnce(event)).catch(()=>{});}
      state=data;
      [...newItems,...newQuests].forEach(n=>modalQueue.set(n.key,n));if(!first&&Number(data.mail_latest_unread)>oldLatestMail&&!newItems.length)modalQueue.set('mail',{title:'Nuova missiva',detail:'Hai ricevuto un nuovo messaggio nella posta.',url:'/posta/'});announce();
      const n=Number(data.mail)||0;badge.hidden=n===0;badge.textContent=String(n);
      mail.setAttribute('aria-label',n?`Posta: ${n} messaggi non letti`:'Posta');
      pending=data.quests||[];count.hidden=pending.length===0;count.textContent=String(pending.length);
      list.hidden=pending.length===0;
      patchLiveList(list,pending.map(q=>`<li data-live-key="quest:${q.id}"><button class="quest-notice notice-unread" type="button" data-quest-id="${q.id}"><span class="unread-flag">Da leggere</span><strong>Nuova quest: ${esc(q.title)}</strong><span>${esc(q.location_label)}</span><small>Apri e segna come letta</small></button></li>`).join(''));
      activities=data.activity||[];currentCounts=data.counts||{};activityTotal=Number(data.activity_total)||0;
      if(activityCount){activityCount.hidden=activityTotal===0;activityCount.textContent=String(activityTotal);}
      if(activityList){
        activityList.hidden=activities.length===0;
        patchLiveList(activityList,activities.slice(0,8).map(item=>`<li data-live-key="notice:${esc(item.key)}"><button class="quest-notice activity-notice notice-unread ${item.commerce?'trade-row-'+esc(item.commerce.key):''}" type="button" data-notice-key="${esc(item.key)}"><span class="unread-flag">Da leggere</span><strong>${esc(item.title)}</strong><span>${esc(item.detail.slice(0,180))}</span><small>${item.commerce?`<span class="trade-status trade-${esc(item.commerce.key)}"><strong>${esc(item.commerce.label)}</strong> · ${esc(item.commerce.next)}</span>`:'Leggi la notifica'}</small></button></li>`).join(''));
      }
      refreshBadges();
    }
    async function request(payload){
      const sequence=++issued;let options={cache:'no-store'};
      if(payload){
        const session=await fetch('/api/session.php',{cache:'no-store'});
        if(!session.ok)throw new Error('Sessione scaduta');
        const token=await session.json();
        options={method:'POST',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':token.csrfToken},body:JSON.stringify(payload)};
      }
      const response=await fetch('/api/notifications.php',options);
      if(!response.ok)throw new Error('Avvisi non disponibili');
      const data=await response.json();
      if(sequence>=rendered){rendered=sequence;render(data);}
      return data;
    }
    async function refresh(){
      if(loading||document.hidden)return;
      loading=true;try{await request();}catch(error){console.error(error);}finally{loading=false;}
    }
    list.addEventListener('click',async event=>{
      const button=event.target.closest('[data-quest-id]');if(!button)return;
      const q=pending.find(q=>q.id===Number(button.dataset.questId));if(!q)return;
      button.disabled=true;
      try{await request({ids:[q.id]});window.alphanoWorkspace?.open(q.url,'Quest: '+q.title);}
      catch{button.disabled=false;button.querySelector('small').textContent='Lettura non salvata. Riprova.';}
    });
    activityList?.addEventListener('click',async event=>{
      const button=event.target.closest('[data-notice-key]');if(!button)return;
      const item=activities.find(item=>item.key===button.dataset.noticeKey);if(!item)return;
      window.alphanoWorkspace?.open('/notifiche/?notice='+encodeURIComponent(item.key),'Cronaca e notifiche');
    });
    window.alphanoNotifications={refresh,refreshBadges,getState:()=>state,readThread:target=>request({target}),readAll:async()=>{
      const ids=pending.map(q=>q.id);
      for(let i=0;i<ids.length;i+=500)await request({ids:ids.slice(i,i+500)});
      // The API delivers bounded pages, with exact counters for the entire inbox.
      // Drain successive pages without silently forgetting items after the first 200.
      while(activities.length){
        const keys=activities.map(item=>item.key);
        const response=await request({keys});
        if(!response.activity?.length)break;
        if(response.activity.every(item=>keys.includes(item.key)))throw new Error('Lettura non salvata. Riprova.');
      }
    }};
    document.querySelector('[data-open-inbox]')?.addEventListener('click',event=>{event.preventDefault();window.alphanoWorkspace?.open('/notifiche/','Cronaca e notifiche');});
    window.alphanoExperience?.ready().catch(()=>{});
    refresh();setInterval(refresh,30000);
    document.addEventListener('visibilitychange',refresh);
    document.addEventListener('alphano:menu-ready',refreshBadges);
  });
})();
