"""End-to-end board CRUD and forum checks in the isolated smoke environment."""
import json,base64,uuid

def exercise(env,admin,player,guest,check):
 sql=env['sql'];endpoint='/api/admin/bacheche.php';token=admin.csrf('/admin/bacheche.php')
 def manage(**values):return admin.request(endpoint,dict(_csrf=token,**values))
 def forum(client,**values):return client.request('/api/bacheche/',dict(_csrf=client.csrf('/main.php'),**values))
 data=json.loads(manage()[1]);check('board editor always has bundled images',data['success'] and len(data['images'])>=4 and all((env['app']/i['path'].lstrip('/')).is_file() for i in data['images']))
 check('ordinary player cannot manage boards',player.request(endpoint)[0]==403)
 check('creating boards requires CSRF',admin.request(endpoint,dict(azione='aggiungi',location='Generale',nome='Forbidden',descrizione='Test'))[0]==403)
 form=dict(azione='aggiungi',location='Generale',nome='Smoke board',descrizione='<p>Una bacheca per i test.</p>',header_image='')
 check('board creates with empty image selection',manage(**form)[0]==200)
 board=int(sql("SELECT id FROM luoghi WHERE nome='Smoke board'"));check('new board has explicit public permissions',sql(f"SELECT `all` FROM diritti WHERE bacheca='{board}'")=='1')
 check('empty board image uses a real default',sql(f'SELECT header_image FROM luoghi WHERE id={board}')=='/assets/default-board.svg')
 count=sql('SELECT COUNT(*) FROM luoghi');check('invalid board image leaves no partial record',manage(**{**form,'nome':'No orphan','header_image':'/private/settings.json'})[0]==422 and sql('SELECT COUNT(*) FROM luoghi')==count)
 check('empty rich text description is rejected',manage(**{**form,'descrizione':'<p><br></p>'})[0]==400)
 check('board details sanitize active markup',manage(**{**form,'azione':'modifica','id':board,'descrizione':'<p>Descrizione aggiornata.</p><script>alert(1)</script>'})[0]==200 and '<script' not in sql(f'SELECT descrizione FROM luoghi WHERE id={board}'))
 code,text,_=forum(player,action='postThread',board=board,subject='Test thread',message='<p>Primo messaggio.</p>');check('player creates a real board thread',code==200);thread=json.loads(text)['id']
 code,text,_=forum(player,action='postReply',board=board,thread=thread,message='<p>Risposta.</p>');check('player replies to a real board thread',code==200)
 check('board threads and posts can be read',forum(player,action='getThreads',board=board)[0]==200 and len(json.loads(forum(player,action='getPosts',board=board,thread=thread)[1])['posts'])==1)
 check('message editing records history',forum(player,action='modify',id=thread,subject='Updated',message='<p>Aggiornato.</p>')[0]==200 and sql(f'SELECT COUNT(*) FROM storicobacheche WHERE oldid={thread}')=='1')
 check('admin can close threads',forum(admin,action='close',id=thread)[0]==200)
 check('closed thread rejects replies',forum(player,action='postReply',board=board,thread=thread,message='Non consentito')[0]==409)
 check('admin can reopen threads',forum(admin,action='open',id=thread)[0]==200)
 check('board permissions are enforced for players',manage(**dict(azione='permessi',id=board,needadmin=1),**{'all':0})[0]==200 and forum(player,action='getThreads',board=board)[0]==403 and forum(admin,action='getThreads',board=board)[0]==200)
 check('public access can be restored',manage(**dict(azione='permessi',id=board),**{'all':1})[0]==200 and forum(player,action='getThreads',board=board)[0]==200)
 check('clearing a board removes history as well as posts',manage(azione='svuota',id=board)[0]==200 and sql(f'SELECT COUNT(*) FROM bacheche WHERE bacheca={board}')=='0' and sql(f'SELECT COUNT(*) FROM storicobacheche WHERE oldid={thread}')=='0')
 check('owned board deletion removes rights',manage(**dict(azione='cancella',id=board),**{'all':1})[0]==200 and sql(f"SELECT COUNT(*) FROM diritti WHERE bacheca='{board}'")=='0')
 check('standalone progression renders',admin.request('/profili/progressione.php?Id=1')[0]==200)
 check('session location picker renders',admin.request('/sessione/sessione.php')[0]==200)

 check('board games admin API has initial settings',admin.request('/api/board-games.php?action=admin')[0]==200 and json.loads(admin.request('/api/board-games.php?action=admin')[1])['revision']>=1)
 sql('DELETE FROM board_game_settings')
 check('missing board game settings recover safely',admin.request('/api/board-games.php?action=admin')[0]==200 and sql('SELECT COUNT(*) FROM board_game_settings')=='1')

 check('recording viewer opens without compression extension',admin.request('/vari/visualizzagiocata.php')[0]==200)

 check('work editor starts with a valid settings version',admin.request('/api/chat-work-admin.php')[0]==200 and json.loads(admin.request('/api/chat-work-admin.php')[1])['version']>=1)

 room=sql('SELECT chat_key FROM mappe WHERE chat=1 LIMIT 1');player.request('/chat/index.php?chat='+room);pt=player.csrf('/main.php')
 check('personal recording starts',player.request('/chat/recordings.php?chat='+room,dict(_csrf=pt,action='start'))[0]==200)
 check('recording captures a chat message',player.request('/chat/messages.php?chat='+room,dict(_csrf=pt,msg='// Un messaggio da registrare.',requestId=str(uuid.uuid4())))[0]==200)
 code,recording,_=player.request('/chat/recordings.php?chat='+room,dict(_csrf=pt,action='save'))
 check('downloaded recording uses portable XML',code==200 and recording.startswith('<?xml') and '<FIELDSET' in recording)
 code,body,_=player.request('/vari/visualizzagiocata.php',dict(_csrf=pt,nome='Smoke recording',xmldata=base64.b64encode(recording.encode()).decode()))
 check('downloaded recording imports into academy',code==200 and 'Giocata inviata' in body)
